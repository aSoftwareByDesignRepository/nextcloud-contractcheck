<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Domain;

use OCA\ContractCheck\Domain\DeadlineEngine;
use OCA\ContractCheck\Domain\DeadlineResult;
use PHPUnit\Framework\TestCase;

/**
 * PRODUCT-SPEC §9.4 — the worked-examples table is normative and every row
 * must pass as an executable test (GA-1).
 */
final class DeadlineEngineTest extends TestCase
{
	private DeadlineEngine $engine;

	protected function setUp(): void
	{
		$this->engine = new DeadlineEngine();
	}

	private function rule(array $over): array
	{
		return array_merge([
			'term_kind' => DeadlineEngine::TERM_FIXED,
			'start_date' => null,
			'end_date' => null,
			'renewal_value' => null,
			'renewal_unit' => null,
			'notice_value' => 0,
			'notice_unit' => 'days',
			'notice_anchor' => DeadlineEngine::ANCHOR_CONTRACT_END,
		], $over);
	}

	public static function workedExamples(): iterable
	{
		// [name, rule overrides, today, expectedState, expectedCancelBy, expectedEffective]
		yield 'EX1 fixed 3m open' => [
			['term_kind' => 'fixed', 'end_date' => '2027-12-31', 'notice_value' => 3, 'notice_unit' => 'months'],
			'2027-09-01', DeadlineResult::STATE_OPEN, '2027-09-30', '2027-12-31',
		];
		yield 'EX2 fixed 3m missed' => [
			['term_kind' => 'fixed', 'end_date' => '2027-12-31', 'notice_value' => 3, 'notice_unit' => 'months'],
			'2027-10-15', DeadlineResult::STATE_MISSED, '2027-09-30', '2027-12-31',
		];
		yield 'EX3 auto_renew 12m rolls to next end' => [
			['term_kind' => 'auto_renew', 'end_date' => '2027-12-31',
				'renewal_value' => 12, 'renewal_unit' => 'months',
				'notice_value' => 3, 'notice_unit' => 'months'],
			'2027-10-15', DeadlineResult::STATE_OPEN, '2028-09-30', '2028-12-31',
		];
		yield 'EX4 auto_renew rolls end after expiry' => [
			['term_kind' => 'auto_renew', 'end_date' => '2027-06-30',
				'renewal_value' => 12, 'renewal_unit' => 'months',
				'notice_value' => 3, 'notice_unit' => 'months'],
			'2027-07-15', DeadlineResult::STATE_OPEN, '2028-03-31', '2028-06-30',
		];
		yield 'EX5 indefinite 3m month_end iterates' => [
			['term_kind' => 'indefinite', 'notice_anchor' => 'month_end',
				'notice_value' => 3, 'notice_unit' => 'months', 'start_date' => '2020-01-01'],
			'2027-01-10', DeadlineResult::STATE_OPEN, '2027-01-31', '2027-04-30',
		];
		yield 'EX7 fixed 6m already past at create' => [
			['term_kind' => 'fixed', 'end_date' => '2027-03-31', 'notice_value' => 6, 'notice_unit' => 'months'],
			'2026-10-01', DeadlineResult::STATE_MISSED, '2026-09-30', '2027-03-31',
		];
		yield 'EX8 auto_renew leap end clamps' => [
			['term_kind' => 'auto_renew', 'end_date' => '2028-02-29',
				'renewal_value' => 12, 'renewal_unit' => 'months',
				'notice_value' => 1, 'notice_unit' => 'months'],
			'2028-02-01', DeadlineResult::STATE_OPEN, '2029-01-31', '2029-02-28',
		];
	}

	/** @dataProvider workedExamples */
	public function testWorkedExample(array $over, string $today, string $state, ?string $cancelBy, ?string $effective): void
	{
		$r = $this->engine->compute($this->rule($over), $today);
		self::assertSame($state, $r->state, 'state');
		self::assertSame($cancelBy, $r->cancelBy, 'cancelBy');
		self::assertSame($effective, $r->effectiveAt, 'effectiveAt');
	}

	public function testEx6IndefiniteAnytime(): void
	{
		$r = $this->engine->compute($this->rule([
			'term_kind' => 'indefinite',
			'notice_anchor' => DeadlineEngine::ANCHOR_ANYTIME,
			'notice_value' => 14,
			'notice_unit' => 'days',
		]), '2027-05-20');

		self::assertSame(DeadlineResult::STATE_OPEN, $r->state);
		self::assertSame('2027-05-20', $r->cancelBy);
		self::assertSame('2027-06-03', $r->effectiveAt);
	}

	public function testDayBasedNoticeSubtractsPlainDays(): void
	{
		$r = $this->engine->compute($this->rule([
			'term_kind' => 'fixed', 'end_date' => '2027-12-31',
			'notice_value' => 90, 'notice_unit' => 'days',
		]), '2027-06-01');

		self::assertSame('2027-10-02', $r->cancelBy); // 31.12 - 90d
		self::assertSame('2027-12-31', $r->effectiveAt);
	}

	public function testMonthEndAnchorQuarterEndYearEnd(): void
	{
		// quarter_end, notice 0 → effective is the next quarter end after today
		$r = $this->engine->compute($this->rule([
			'term_kind' => 'indefinite',
			'notice_anchor' => DeadlineEngine::ANCHOR_QUARTER_END,
			'notice_value' => 0,
			'notice_unit' => 'months',
			'start_date' => '2020-01-01',
		]), '2027-02-10');
		self::assertSame(DeadlineResult::STATE_OPEN, $r->state);
		self::assertSame('2027-03-31', $r->effectiveAt);
		self::assertSame('2027-03-31', $r->cancelBy);

		$r = $this->engine->compute($this->rule([
			'term_kind' => 'indefinite',
			'notice_anchor' => DeadlineEngine::ANCHOR_YEAR_END,
			'notice_value' => 0,
			'notice_unit' => 'months',
			'start_date' => '2020-01-01',
		]), '2027-11-01');
		self::assertSame('2027-12-31', $r->effectiveAt);
	}

	public function testQuarterEndSkipsWindowAlreadyClosed(): void
	{
		// 3m notice to quarter end; today 05.01 → cancel_by(31.03)=31.12 past → next Q = 30.06
		$r = $this->engine->compute($this->rule([
			'term_kind' => 'indefinite',
			'notice_anchor' => DeadlineEngine::ANCHOR_QUARTER_END,
			'notice_value' => 3,
			'notice_unit' => 'months',
			'start_date' => '2020-01-01',
		]), '2027-01-05');
		self::assertSame('2027-06-30', $r->effectiveAt);
		self::assertSame('2027-03-31', $r->cancelBy);
	}

	public function testYearEndAnchorIteratesWhenWindowClosed(): void
	{
		// 3m notice to year end; today 01.10.2027 → cancel_by(31.12) = 30.09
		// already past → engine must step the anchor to 31.12.2028.
		$r = $this->engine->compute($this->rule([
			'term_kind' => 'indefinite',
			'notice_anchor' => DeadlineEngine::ANCHOR_YEAR_END,
			'notice_value' => 3,
			'notice_unit' => 'months',
			'start_date' => '2020-01-01',
		]), '2027-10-01');
		self::assertSame(DeadlineResult::STATE_OPEN, $r->state);
		self::assertSame('2028-12-31', $r->effectiveAt);
		self::assertSame('2028-09-30', $r->cancelBy);
	}

	public function testCancelByEqualTodayIsStillOpenNotMissed(): void
	{
		// Boundary semantics: the cancel_by day itself is the LAST day the
		// user can still act — missed starts the day after.
		$r = $this->engine->compute($this->rule([
			'term_kind' => 'fixed',
			'end_date' => '2027-12-31',
			'notice_value' => 3,
			'notice_unit' => 'months',
			'notice_anchor' => 'contract_end',
		]), '2027-09-30');
		self::assertSame(DeadlineResult::STATE_OPEN, $r->state);
		self::assertSame(0, $r->daysLeft);

		$r = $this->engine->compute($this->rule([
			'term_kind' => 'fixed',
			'end_date' => '2027-12-31',
			'notice_value' => 3,
			'notice_unit' => 'months',
			'notice_anchor' => 'contract_end',
		]), '2027-10-01');
		self::assertSame(DeadlineResult::STATE_MISSED, $r->state);
	}

	public function testUnknownTermKindIsDefensiveNone(): void
	{
		$r = $this->engine->compute($this->rule(['term_kind' => 'bogus']), '2027-01-01');
		self::assertSame(DeadlineResult::STATE_NONE, $r->state);
		self::assertNull($r->cancelBy);
	}

	public function testFixedWithoutEndDateIsNone(): void
	{
		$r = $this->engine->compute($this->rule([
			'term_kind' => 'fixed', 'end_date' => null, 'notice_value' => 3, 'notice_unit' => 'months',
		]), '2027-01-01');
		self::assertSame(DeadlineResult::STATE_NONE, $r->state);
	}

	public function testRollEndDateStopsInFuture(): void
	{
		self::assertSame('2028-06-30', $this->engine->rollEndDate('2027-06-30', 12, 'months', '2027-07-15'));
		self::assertNull($this->engine->rollEndDate('2027-06-30', 12, 'months', '2027-01-01'), 'already-future → no roll');
		self::assertNull($this->engine->rollEndDate('bogus', 12, 'months', '2027-01-01'));
		self::assertNull($this->engine->rollEndDate('2027-06-30', 0, 'months', '2027-01-01'));
		self::assertNull($this->engine->rollEndDate('2027-06-30', 12, 'weeks', '2027-01-01'));
	}

	public function testRollEndDateLeapClamps(): void
	{
		// 29.02.2028 + 12m → 28.02.2029 (clamped, matching spec EX8)
		self::assertSame('2029-02-28', $this->engine->rollEndDate('2028-02-29', 12, 'months', '2028-03-01'));
	}

	public function testInstanceIdIsStableForSameEffectiveDate(): void
	{
		$r1 = $this->engine->compute($this->rule([
			'term_kind' => 'fixed', 'end_date' => '2027-12-31', 'notice_value' => 3, 'notice_unit' => 'months',
		]), '2027-01-01');
		$r2 = $this->engine->compute($this->rule([
			'term_kind' => 'fixed', 'end_date' => '2027-12-31', 'notice_value' => 3, 'notice_unit' => 'months',
		]), '2027-06-01');
		self::assertSame($r1->instanceId(7), $r2->instanceId(7), 'same contract+date → same dedup key');
		self::assertNotSame($r1->instanceId(7), $r1->instanceId(8), 'different contract → different key');
	}

	public function testDaysLeftIsExactDayCount(): void
	{
		$r = $this->engine->compute($this->rule([
			'term_kind' => 'fixed', 'end_date' => '2027-12-31', 'notice_value' => 3, 'notice_unit' => 'months',
		]), '2027-09-01');
		self::assertSame(29, $r->daysLeft); // 30.09 - 01.09
	}
}
