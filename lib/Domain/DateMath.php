<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Domain;

/**
 * Pure calendar math on strict 'Y-m-d' strings.
 *
 * All deadline arithmetic MUST go through this class — never through Unix
 * timestamps (DST transitions would shift local dates) and never through
 * relative format strings (PHP's "-3 months" is day-preserving only, which
 * is wrong for month-end-anchored notice periods).
 */
final class DateMath
{
	private function __construct()
	{
	}

	/**
	 * Strict 'Y-m-d' validation incl. real-calendar check
	 * ('2027-02-30' is rejected, '2027-2-3' is rejected).
	 */
	public static function isValid(string $date): bool
	{
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
			return false;
		}
		$dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);

		return $dt !== false && $dt->format('Y-m-d') === $date;
	}

	public static function parse(string $date): \DateTimeImmutable
	{
		$dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
		if ($dt === false || $dt->format('Y-m-d') !== $date) {
			throw new \InvalidArgumentException('Invalid date: ' . $date);
		}

		return $dt;
	}

	/** @return int negative when $a < $b, 0 when equal, positive when $a > $b */
	public static function compare(string $a, string $b): int
	{
		return $a <=> $b; // 'Y-m-d' sorts chronologically
	}

	public static function addDays(string $date, int $days): string
	{
		return self::parse($date)
			->modify(($days >= 0 ? '+' : '') . $days . ' days')
			->format('Y-m-d');
	}

	public static function isLastDayOfMonth(string $date): bool
	{
		$dt = self::parse($date);

		return $dt->format('d') === $dt->format('t');
	}

	public static function lastDayOfMonth(string $date): string
	{
		$dt = self::parse($date);

		return $dt->format('Y-m') . '-' . $dt->format('t');
	}

	/**
	 * Day-preserving month addition with end-of-month clamping:
	 * 31.01 + 1 month → 28./29.02 (never overflows into March).
	 */
	public static function addMonthsClamped(string $date, int $months): string
	{
		if ($months === 0) {
			return self::parse($date)->format('Y-m-d');
		}
		$dt = self::parse($date);
		$day = (int)$dt->format('d');
		// First of the target month, then clamp the day to its length.
		$target = $dt->modify('first day of this month')
			->modify(($months >= 0 ? '+' : '') . $months . ' months');
		$lastDay = (int)$target->format('t');

		return $target->format('Y-m') . '-' . str_pad((string)min($day, $lastDay), 2, '0', STR_PAD_LEFT);
	}

	public static function addYearsClamped(string $date, int $years): string
	{
		return self::addMonthsClamped($date, $years * 12);
	}

	/**
	 * Notice subtraction on month granularity.
	 *
	 * Rule R1 (legal month-end semantics): when $date is the LAST day of a
	 * month (e.g. quarter/year/month-end anchors, or a contract end that
	 * happens to fall on a month end), the cancel-by date is the last day of
	 * the month $months before it — 30.04 - 3 months → 31.01, NOT 30.01.
	 * Otherwise plain day-preserving clamped subtraction applies.
	 */
	public static function subMonths(string $date, int $months): string
	{
		if (self::isLastDayOfMonth($date)) {
			return self::lastDayOfMonth(self::addMonthsClamped($date, -$months));
		}

		return self::addMonthsClamped($date, -$months);
	}

	/** Signed day difference $to - $from in calendar days. */
	public static function diffDays(string $from, string $to): int
	{
		$days = (int)self::parse($from)->diff(self::parse($to))->format('%r%a');

		return $days;
	}
}
