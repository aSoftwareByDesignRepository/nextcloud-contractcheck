<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Domain;

use OCA\ContractCheck\Domain\IcsBuilder;
use PHPUnit\Framework\TestCase;

final class IcsBuilderTest extends TestCase
{
	public function testRfc5545Structure(): void
	{
		$ics = IcsBuilder::build('My Org', [
			['uid' => 'cc-7@x', 'title' => 'Contract A', 'cancelBy' => '2027-09-30',
				'effectiveAt' => '2027-12-31', 'leadDays' => [30, 7]]
		], '20260601T000000Z');
		self::assertStringStartsWith("BEGIN:VCALENDAR\r\n", $ics);
		self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
		self::assertStringContainsString("VERSION:2.0\r\n", $ics);
		self::assertStringContainsString('X-WR-CALNAME:My Org', $ics);
		self::assertStringContainsString('UID:cc-7@x', $ics);
		self::assertStringContainsString('DTSTART;VALUE=DATE:20270930', $ics);
		// All-day DTEND exclusive → +1 day
		self::assertStringContainsString('DTEND;VALUE=DATE:20271001', $ics);
		self::assertSame(2, substr_count($ics, 'BEGIN:VALARM'));
		self::assertStringContainsString('TRIGGER:-P30D', $ics);
		self::assertStringContainsString('TRIGGER:-P7D', $ics);
	}

	public function testDtendRollsOverMonth(): void
	{
		$ics = IcsBuilder::build('O', [
			['uid' => 'u', 'title' => 't', 'cancelBy' => '2027-12-31', 'effectiveAt' => null, 'leadDays' => []]
		], '20260601T000000Z');
		self::assertStringContainsString('DTEND;VALUE=DATE:20280101', $ics);
	}

	public function testEscapesText(): void
	{
		$ics = IcsBuilder::build('Org; Inc, "HQ"', [
			['uid' => 'u', 'title' => "Rent; Main St\nSuite 5", 'cancelBy' => '2027-01-01',
				'effectiveAt' => null, 'leadDays' => []]
		], '20260601T000000Z');
		self::assertStringContainsString('X-WR-CALNAME:Org\; Inc\, "HQ"', $ics);
		self::assertStringContainsString('SUMMARY:Rent\; Main St\nSuite 5', $ics);
	}

	public function testFoldsLongLinesAt75Octets(): void
	{
		$ics = IcsBuilder::build('O', [
			['uid' => 'u', 'title' => str_repeat('VeryLongTitle', 8), 'cancelBy' => '2027-01-01',
				'effectiveAt' => null, 'leadDays' => []]
		], '20260601T000000Z');
		foreach (explode("\r\n", rtrim($ics)) as $line) {
			self::assertLessThanOrEqual(75, strlen($line), "line too long: {$line}");
		}
		// folded continuation lines start with a space
		self::assertMatchesRegularExpression("/\r\n /", $ics);
	}

	public function testZeroLeadDaySkipped(): void
	{
		$ics = IcsBuilder::build('O', [
			['uid' => 'u', 'title' => 't', 'cancelBy' => '2027-01-01', 'effectiveAt' => null, 'leadDays' => [0, 14]]
		], '20260601T000000Z');
		self::assertSame(1, substr_count($ics, 'BEGIN:VALARM'));
	}
}
