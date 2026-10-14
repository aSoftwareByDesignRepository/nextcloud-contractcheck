<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Domain;

use OCA\ContractCheck\Domain\CsvBuilder;
use PHPUnit\Framework\TestCase;

final class CsvBuilderTest extends TestCase
{
	public static function dangerousCells(): iterable
	{
		yield 'formula' => ['=SUM(A1:A2)'];
		yield 'plus' => ['+43 1234'];
		yield 'minus' => ['-5'];
		yield 'at' => ['@cmd'];
		yield 'tab' => ["\t=x"];
		yield 'cr' => ["\r=x"];
		yield 'vt' => ["\x0B=x"];
	}

	/** @dataProvider dangerousCells */
	public function testFormulaInjectionPrefixed(string $cell): void
	{
		$out = CsvBuilder::sanitizeCell($cell);
		self::assertStringStartsWith("'", $out, 'dangerous cell must be quote-prefixed');
	}

	public function testSafeCellsUnchanged(): void
	{
		self::assertSame('Office rent', CsvBuilder::sanitizeCell('Office rent'));
		self::assertSame('0', CsvBuilder::sanitizeCell('0'));
		self::assertSame('— none —', CsvBuilder::sanitizeCell('— none —'));
		self::assertSame('a-b', CsvBuilder::sanitizeCell('a-b')); // minus not at pos 0
	}

	public function testBuildEscapesDelimiterAndQuotes(): void
	{
		$csv = CsvBuilder::build(['a', 'b'], [['x;y', 'he said "hi"']]);
		self::assertStringContainsString('"x;y"', $csv);
		self::assertStringContainsString('"he said ""hi"""', $csv);
	}

	public function testBuildAppliesSanitizeToAllCells(): void
	{
		$csv = CsvBuilder::build(['t'], [['=1+1']]);
		self::assertStringContainsString("'=1+1", $csv);
	}

	public function testBomPresentForExcel(): void
	{
		$csv = CsvBuilder::build(['a'], [['b']]);
		self::assertStringStartsWith("\xEF\xBB\xBF", $csv);
	}
}
