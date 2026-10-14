<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Domain;

use OCA\ContractCheck\Domain\DateMath;
use PHPUnit\Framework\TestCase;

final class DateMathTest extends TestCase
{
	public static function validDates(): iterable
	{
		yield 'plain' => ['2027-12-31', true];
		yield 'leap feb29' => ['2028-02-29', true];
		yield 'non-leap feb29' => ['2027-02-29', false];
		yield 'month 13' => ['2027-13-01', false];
		yield 'day 32' => ['2027-01-32', false];
		yield 'feb30' => ['2027-02-30', false];
		yield 'not a date' => ['hello', false];
		yield 'datetime' => ['2027-01-01 10:00', false];
		yield 'd.m.Y' => ['31.12.2027', false];
		yield 'empty' => ['', false];
	}

	/** @dataProvider validDates */
	public function testIsValid(string $d, bool $expected): void
	{
		self::assertSame($expected, DateMath::isValid($d));
	}

	public static function subMonthsCases(): iterable
	{
		yield 'EX4 anchor: 30.04 -3m → 31.01' => ['2027-04-30', 3, '2027-01-31'];
		yield 'mid-month day-preserving' => ['2027-06-15', 3, '2027-03-15'];
		yield 'EX8: 28.02.2029 -1m → 31.01.2029' => ['2029-02-28', 1, '2029-01-31'];
		yield '31.03 -1m → 28.02' => ['2027-03-31', 1, '2027-02-28'];
		yield '31.03 -1m leap → 29.02' => ['2028-03-31', 1, '2028-02-29'];
		yield '31.12 -3m → 30.09' => ['2027-12-31', 3, '2027-09-30'];
		yield '31.01 -1m → 31.12 prev year' => ['2027-01-31', 1, '2026-12-31'];
		yield '30.06 -6m → 31.12' => ['2027-06-30', 6, '2026-12-31'];
		yield 'zero months' => ['2027-04-30', 0, '2027-04-30'];
	}

	/** @dataProvider subMonthsCases */
	public function testSubMonthsMonthEndAware(string $date, int $months, string $expected): void
	{
		self::assertSame($expected, DateMath::subMonths($date, $months));
	}

	public static function addMonthsCases(): iterable
	{
		yield '29.02 +12m → 28.02' => ['2028-02-29', 12, '2029-02-28'];
		yield '31.01 +1m → 28.02' => ['2027-01-31', 1, '2027-02-28'];
		yield '15.03 +2m' => ['2027-03-15', 2, '2027-05-15'];
		yield '30.06 +12m' => ['2027-06-30', 12, '2028-06-30'];
		yield 'year boundary' => ['2027-11-30', 3, '2028-02-29'];
	}

	/** @dataProvider addMonthsCases */
	public function testAddMonthsClamped(string $date, int $months, string $expected): void
	{
		self::assertSame($expected, DateMath::addMonthsClamped($date, $months));
	}

	public function testAddYearsClamped(): void
	{
		self::assertSame('2029-02-28', DateMath::addYearsClamped('2028-02-29', 1));
		self::assertSame('2032-02-29', DateMath::addYearsClamped('2028-02-29', 4));
		self::assertSame('2030-06-30', DateMath::addYearsClamped('2027-06-30', 3));
	}

	public function testDiffDays(): void
	{
		self::assertSame(0, DateMath::diffDays('2027-01-01', '2027-01-01'));
		self::assertSame(29, DateMath::diffDays('2027-09-01', '2027-09-30'));
		self::assertSame(-5, DateMath::diffDays('2027-09-10', '2027-09-05'));
		self::assertSame(365, DateMath::diffDays('2027-01-01', '2028-01-01'));
		self::assertSame(366, DateMath::diffDays('2028-01-01', '2029-01-01'));
	}

	public function testIsLastDayOfMonth(): void
	{
		self::assertTrue(DateMath::isLastDayOfMonth('2027-01-31'));
		self::assertTrue(DateMath::isLastDayOfMonth('2027-02-28'));
		self::assertTrue(DateMath::isLastDayOfMonth('2028-02-29'));
		self::assertFalse(DateMath::isLastDayOfMonth('2027-02-27'));
		self::assertFalse(DateMath::isLastDayOfMonth('2027-04-29'));
	}

	public function testAddDaysCrossesMonthAndYear(): void
	{
		self::assertSame('2027-10-02', DateMath::addDays('2027-12-31', -90));
		self::assertSame('2028-01-01', DateMath::addDays('2027-12-31', 1));
		self::assertSame('2027-06-03', DateMath::addDays('2027-05-20', 14));
	}

	public function testCompare(): void
	{
		self::assertSame(0, DateMath::compare('2027-01-01', '2027-01-01'));
		self::assertLessThan(0, DateMath::compare('2027-01-01', '2027-01-02'));
		self::assertGreaterThan(0, DateMath::compare('2027-02-01', '2027-01-31'));
	}

	public function testParseRejectsInvalid(): void
	{
		$this->expectException(\InvalidArgumentException::class);
		DateMath::parse('2027-02-30');
	}
}
