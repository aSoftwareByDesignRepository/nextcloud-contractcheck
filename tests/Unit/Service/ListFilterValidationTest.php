<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Service;

use OCA\ContractCheck\Exception\ValidationException;
use OCA\ContractCheck\Service\ContractValidator;
use PHPUnit\Framework\TestCase;

final class ListFilterValidationTest extends TestCase
{
	public function testEmptyFiltersAreNull(): void
	{
		self::assertSame(
			['type' => null, 'status' => null, 'partnerId' => null, 'costCenter' => null, 'deadline' => null],
			ContractValidator::filters([])
		);
	}

	public function testValidFiltersNormalize(): void
	{
		$f = ContractValidator::filters([
			'type' => 'rent',
			'partnerId' => '42',
			'costCenter' => 'IT',
			'deadline' => 'missed',
		]);
		self::assertSame('rent', $f['type']);
		self::assertSame(42, $f['partnerId']);
		self::assertSame('IT', $f['costCenter']);
		self::assertSame('missed', $f['deadline']);
	}

	public static function invalidFilters(): iterable
	{
		yield 'unknown type' => [['type' => 'submarine'], 'type'];
		yield 'partner decimal' => [['partnerId' => '2.9'], 'partnerId'];
		yield 'partner exponent' => [['partnerId' => '2e3'], 'partnerId'];
		yield 'partner whitespace' => [['partnerId' => ' 5'], 'partnerId'];
		yield 'partner negative' => [['partnerId' => '-5'], 'partnerId'];
		yield 'costCenter too long' => [['costCenter' => str_repeat('x', 65)], 'costCenter'];
		yield 'unknown deadline window' => [['deadline' => 'whenever'], 'deadline'];
		yield 'status uppercase not normalized' => [['status' => 'Active'], 'status'];
		yield 'deadline numeric injection' => [['deadline' => '30'], 'deadline'];
		yield 'unknown status' => [['status' => 'bogus'], 'status'];
	}

	/**
	 * @dataProvider invalidFilters
	 */
	public function testInvalidFiltersFailClosed(array $in, string $field): void
	{
		try {
			ContractValidator::filters($in);
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertSame('FILTERS_INVALID', $e->getErrorCode());
			self::assertArrayHasKey($field, $e->getFields());
		}
	}

	public function testAllDeadlineWindowsAccepted(): void
	{
		foreach (ContractValidator::DEADLINE_FILTERS as $w) {
			self::assertSame($w, ContractValidator::filters(['deadline' => $w])['deadline']);
		}
	}

	public function testAllStatusesAccepted(): void
	{
		foreach (ContractValidator::STATUSES as $st) {
			self::assertSame($st, ContractValidator::filters(['status' => $st])['status']);
		}
	}
}
