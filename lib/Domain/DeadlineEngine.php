<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Domain;

/**
 * Normative deadline engine (product spec §4).
 *
 * Given a contract's termination rule, computes the NEXT actionable
 * cancellation date and the effective termination date it belongs to.
 * The engine is pure: everything is 'Y-m-d' string math via DateMath,
 * so results are identical on every host, timezone and database.
 *
 * Semantics:
 *  - fixed       → single candidate E = end_date
 *  - auto_renew  → candidates E_k = end_date + k·P (renewal period)
 *  - indefinite  → candidates = anchor dates (month/quarter/year end),
 *                  'anytime' → cancel-by is today, effective today + notice
 *  - cancel_by   → E - notice period; month-unit subtraction uses
 *                  month-end semantics (see DateMath::subMonths, rule R1)
 *  - result      → earliest window with cancel_by >= today; the most recent
 *                  passed window is reported as informational "missed"
 */
final class DeadlineEngine
{
	/**
	 * Defensive iteration bound. With the validation caps
	 * (notice <= 600 months, renewal period <= 600 months) a scan never
	 * comes near this; it exists so a corrupt rule can never hang a job.
	 */
	private const MAX_ITERATIONS = 2400;

	public const ANCHOR_CONTRACT_END = 'contract_end';
	public const ANCHOR_MONTH_END = 'month_end';
	public const ANCHOR_QUARTER_END = 'quarter_end';
	public const ANCHOR_YEAR_END = 'year_end';
	public const ANCHOR_ANYTIME = 'anytime';

	public const TERM_FIXED = 'fixed';
	public const TERM_AUTO_RENEW = 'auto_renew';
	public const TERM_INDEFINITE = 'indefinite';

	/**
	 * @param array{
	 *   term_kind:string, start_date:?string, end_date:?string,
	 *   renewal_value:?int, renewal_unit:?string,
	 *   notice_value:?int, notice_unit:?string, notice_anchor:?string
	 * } $rule
	 */
	public function compute(array $rule, string $today): DeadlineResult
	{
		$noticeValue = $rule['notice_value'] ?? 0;
		$noticeUnit = $rule['notice_unit'] ?? 'days';
		$anchor = $rule['notice_anchor'] ?? self::ANCHOR_CONTRACT_END;
		$startDate = $rule['start_date'] ?? null;
		$endDate = $rule['end_date'] ?? null;

		return match ($rule['term_kind']) {
			self::TERM_FIXED => $this->computeFixed($endDate, $noticeValue, $noticeUnit, $today),
			self::TERM_AUTO_RENEW => $this->computeAutoRenew(
				$endDate, (int)($rule['renewal_value'] ?? 0), (string)($rule['renewal_unit'] ?? ''),
				$noticeValue, $noticeUnit, $today
			),
			self::TERM_INDEFINITE => $this->computeIndefinite(
				$anchor, $startDate, $noticeValue, $noticeUnit, $today
			),
			default => DeadlineResult::none(),
		};
	}

	/**
	 * cancel_by(E) = E - notice period.
	 * days → plain day subtraction; months → month-end-aware (R1).
	 */
	public function cancelByFor(string $effectiveDate, int $noticeValue, string $noticeUnit): string
	{
		if ($noticeValue <= 0) {
			return $effectiveDate;
		}

		return $noticeUnit === 'months'
			? DateMath::subMonths($effectiveDate, $noticeValue)
			: DateMath::addDays($effectiveDate, -$noticeValue);
	}

	/**
	 * Add one renewal period (months or years) to a date — clamped.
	 */
	public function addRenewalPeriod(string $date, int $value, string $unit): string
	{
		return $unit === 'years'
			? DateMath::addYearsClamped($date, $value)
			: DateMath::addMonthsClamped($date, $value);
	}

	/**
	 * Roll an auto-renew contract's stored end date forward until it lies in
	 * the future relative to $today. Iterative (not arithmetic) so clamps
	 * behave exactly like a sequence of single renewals.
	 *
	 * @return ?string the rolled end date — null when the inputs are invalid
	 *         OR no roll was needed ($endDate already lies after $today).
	 */
	public function rollEndDate(string $endDate, int $value, string $unit, string $today): ?string
	{
		if (!DateMath::isValid($endDate) || $value <= 0 || !in_array($unit, ['months', 'years'], true)) {
			return null;
		}
		$end = $endDate;
		$steps = 0;
		while (DateMath::compare($end, $today) <= 0 && $steps < self::MAX_ITERATIONS) {
			$end = $this->addRenewalPeriod($end, $value, $unit);
			++$steps;
		}

		return DateMath::compare($end, $endDate) > 0 ? $end : null;
	}

	private function computeFixed(?string $endDate, int $noticeValue, string $noticeUnit, string $today): DeadlineResult
	{
		if ($endDate === null || !DateMath::isValid($endDate)) {
			return DeadlineResult::none();
		}
		$cancelBy = $this->cancelByFor($endDate, $noticeValue, $noticeUnit);
		$state = DateMath::compare($cancelBy, $today) >= 0
			? DeadlineResult::STATE_OPEN
			: DeadlineResult::STATE_MISSED;

		return new DeadlineResult(
			$state,
			$cancelBy,
			$endDate,
			null,
			null,
			DateMath::diffDays($today, $cancelBy),
		);
	}

	private function computeAutoRenew(
		?string $endDate, int $renewalValue, string $renewalUnit,
		int $noticeValue, string $noticeUnit, string $today,
	): DeadlineResult {
		if ($endDate === null || !DateMath::isValid($endDate) || $renewalValue <= 0
			|| !in_array($renewalUnit, ['months', 'years'], true)) {
			return DeadlineResult::none();
		}

		$effective = $endDate;
		$prevEffective = null;
		$prevCancelBy = null;
		for ($i = 0; $i < self::MAX_ITERATIONS; ++$i) {
			$cancelBy = $this->cancelByFor($effective, $noticeValue, $noticeUnit);
			if (DateMath::compare($cancelBy, $today) >= 0) {
				return new DeadlineResult(
					DeadlineResult::STATE_OPEN,
					$cancelBy,
					$effective,
					$prevCancelBy,
					$prevEffective,
					DateMath::diffDays($today, $cancelBy),
				);
			}
			$prevEffective = $effective;
			$prevCancelBy = $cancelBy;
			$effective = $this->addRenewalPeriod($effective, $renewalValue, $renewalUnit);
		}

		return DeadlineResult::none();
	}

	private function computeIndefinite(
		string $anchor, ?string $startDate, int $noticeValue, string $noticeUnit, string $today,
	): DeadlineResult {
		if ($anchor === self::ANCHOR_ANYTIME) {
			// Always cancelable: cancel-by is today (or the start date when the
			// contract has not begun yet), effective after the notice period.
			$cancelBy = $today;
			$effective = $noticeUnit === 'months'
				? DateMath::addMonthsClamped($today, max(0, $noticeValue))
				: DateMath::addDays($today, max(0, $noticeValue));
			if ($startDate !== null && DateMath::compare($effective, $startDate) < 0) {
				$effective = $startDate;
			}

			return new DeadlineResult(
				DeadlineResult::STATE_OPEN,
				$cancelBy,
				$effective,
				null,
				null,
				0,
			);
		}

		if (!in_array($anchor, [self::ANCHOR_MONTH_END, self::ANCHOR_QUARTER_END, self::ANCHOR_YEAR_END], true)) {
			return DeadlineResult::none();
		}

		// Iterate anchor dates forward until one yields cancel_by >= today.
		// Starting a bit before "today - notice" bounds the scan tightly.
		$floor = $noticeUnit === 'months'
			? DateMath::addMonthsClamped($today, -min($noticeValue, 1200) - 2)
			: DateMath::addDays($today, -$noticeValue - 62);

		$effective = $this->firstAnchorOnOrAfter($anchor, $floor);
		$prevEffective = null;
		$prevCancelBy = null;
		for ($i = 0; $i < self::MAX_ITERATIONS; ++$i) {
			if ($startDate === null || DateMath::compare($effective, $startDate) >= 0) {
				$cancelBy = $this->cancelByFor($effective, $noticeValue, $noticeUnit);
				if (DateMath::compare($cancelBy, $today) >= 0) {
					return new DeadlineResult(
						DeadlineResult::STATE_OPEN,
						$cancelBy,
						$effective,
						$prevCancelBy,
						$prevEffective,
						DateMath::diffDays($today, $cancelBy),
					);
				}
				$prevEffective = $effective;
				$prevCancelBy = $cancelBy;
			}
			$effective = $this->nextAnchor($anchor, $effective);
		}

		return DeadlineResult::none();
	}

	private function firstAnchorOnOrAfter(string $anchor, string $date): string
	{
		$cursor = self::anchorInPeriod($anchor, $date);
		while (DateMath::compare($cursor, $date) < 0) {
			$cursor = $this->nextAnchor($anchor, $cursor);
		}

		return $cursor;
	}

	/** The anchor date inside the same period as $date. */
	private static function anchorInPeriod(string $anchor, string $date): string
	{
		$dt = DateMath::parse($date);
		$year = (int)$dt->format('Y');
		$month = (int)$dt->format('n');

		return match ($anchor) {
			self::ANCHOR_QUARTER_END => self::quarterEnd($year, $month),
			self::ANCHOR_YEAR_END => sprintf('%04d-12-31', $year),
			default => DateMath::lastDayOfMonth($date),
		};
	}

	private static function quarterEnd(int $year, int $month): string
	{
		$endMonth = intdiv($month - 1, 3) * 3 + 3;
		$day = match ($endMonth) {
			3 => 31, 6 => 30, 9 => 30, default => 31,
		};

		return sprintf('%04d-%02d-%02d', $year, $endMonth, $day);
	}

	private function nextAnchor(string $anchor, string $current): string
	{
		return match ($anchor) {
			// Quarter ends are always month ∈ {3,6,9,12}: adding 3 months
			// arithmetically avoids the DateTime overflow pitfall where
			// "31.03 + 3 months" lands on 01.07 and skips Q2 entirely.
			self::ANCHOR_QUARTER_END => (static function (string $current): string {
				$year = (int)substr($current, 0, 4);
				$month = (int)substr($current, 5, 2) + 3;
				if ($month > 12) {
					$month -= 12;
					++$year;
				}

				return self::quarterEnd($year, $month);
			})($current),
			self::ANCHOR_YEAR_END => sprintf('%04d-12-31', (int)substr($current, 0, 4) + 1),
			default => DateMath::lastDayOfMonth(DateMath::addMonthsClamped($current, 1)),
		};
	}
}
