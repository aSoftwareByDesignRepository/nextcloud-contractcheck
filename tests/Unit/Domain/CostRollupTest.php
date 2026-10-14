<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Domain;

use OCA\ContractCheck\Domain\CostRollup;
use PHPUnit\Framework\TestCase;

/**
 * Spec §rollup: monthly×12, quarterly×4, yearly×1, once excluded from the
 * annualized total (reported separately), validity windows filter per year.
 */
final class CostRollupTest extends TestCase
{
	private static function entry(int $cents, string $interval, string $cur = 'EUR', ?string $from = null, ?string $to = null): array
	{
		return [
			'amount_cents' => $cents,
			'currency' => $cur,
			'interval' => $interval,
			'valid_from' => $from,
			'valid_to' => $to,
		];
	}

	public function testIntervalFactors(): void
	{
		$r = CostRollup::rollupYear([
			self::entry(10000, 'monthly'),   // 100.00 × 12
			self::entry(30000, 'quarterly'), // 300.00 × 4
			self::entry(50000, 'yearly'),    // 500.00 × 1
		], 2027);

		self::assertSame(10000 * 12 + 30000 * 4 + 50000, $r['annual_cents']['EUR']);
	}

	public function testOnceExcludedFromAnnualized(): void
	{
		$r = CostRollup::rollupYear([
			self::entry(9900, 'once'),
			self::entry(10000, 'yearly'),
		], 2027);

		self::assertSame(10000, $r['annual_cents']['EUR']);
		self::assertSame(9900, $r['once_cents']['EUR']);
	}

	public function testValidityWindowsFilterPerYear(): void
	{
		$r = CostRollup::rollupYear([
			self::entry(10000, 'yearly', 'EUR', '2026-01-01', '2026-12-31'), // ended
			self::entry(20000, 'yearly', 'EUR', '2027-06-01', null),          // starts mid-year
			self::entry(30000, 'yearly', 'EUR', null, '2025-12-31'),          // long over
		], 2027);

		self::assertSame(20000, $r['annual_cents']['EUR']);
	}

	public function testEntryStartingNextYearExcluded(): void
	{
		$r = CostRollup::rollupYear([
			self::entry(10000, 'monthly', 'EUR', '2028-01-01', null),
		], 2027);
		self::assertSame([], $r['annual_cents']);
	}

	public function testMultiCurrencySeparates(): void
	{
		$r = CostRollup::rollupYear([
			self::entry(10000, 'yearly', 'EUR'),
			self::entry(20000, 'yearly', 'USD'),
		], 2027);

		self::assertSame(10000, $r['annual_cents']['EUR']);
		self::assertSame(20000, $r['annual_cents']['USD']);
	}

	public function testCoversYearEdges(): void
	{
		self::assertTrue(CostRollup::coversYear(null, null, 2027));
		self::assertTrue(CostRollup::coversYear('2027-12-31', null, 2027));
		self::assertFalse(CostRollup::coversYear('2028-01-01', null, 2027));
		self::assertTrue(CostRollup::coversYear(null, '2027-01-01', 2027));
		self::assertFalse(CostRollup::coversYear(null, '2026-12-31', 2027));
	}

	public function testIntegerMathNoFloat(): void
	{
		$r = CostRollup::rollupYear([self::entry(333, 'monthly')], 2027);
		self::assertSame(3996, $r['annual_cents']['EUR']); // 333×12, int — not 3996.0000001
	}
}
