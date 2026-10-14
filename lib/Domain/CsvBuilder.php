<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Domain;

/**
 * CSV writer with mandatory formula-injection hardening.
 *
 * Every cell whose first character could make a spreadsheet app evaluate it
 * as a formula (=, +, -, @, TAB, CR, VT) is prefixed with a literal
 * apostrophe — quoting alone is not protection (family rule §7).
 */
final class CsvBuilder
{
	private const DANGEROUS_PREFIXES = ['=', '+', '-', '@', "\t", "\r", "\x0B"];

	private function __construct()
	{
	}

	public static function sanitizeCell(string $value): string
	{
		if ($value === '') {
			return '';
		}
		$first = $value[0];
		if (in_array($first, self::DANGEROUS_PREFIXES, true)) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * @param list<string> $headers
	 * @param list<list<string>> $rows
	 */
	public static function build(array $headers, array $rows, string $delimiter = ';'): string
	{
		$out = [];
		$out[] = self::line($headers, $delimiter);
		foreach ($rows as $row) {
			$out[] = self::line($row, $delimiter);
		}
		// UTF-8 BOM so Excel opens Umlaute correctly.
		return "\xEF\xBB\xBF" . implode("\r\n", $out) . "\r\n";
	}

	/** @param list<string> $cells */
	private static function line(array $cells, string $delimiter): string
	{
		$escaped = array_map(static function (string $cell) use ($delimiter): string {
			$cell = self::sanitizeCell($cell);
			if (str_contains($cell, $delimiter) || str_contains($cell, '"')
				|| str_contains($cell, "\n") || str_contains($cell, "\r")) {
				return '"' . str_replace('"', '""', $cell) . '"';
			}

			return $cell;
		}, $cells);

		return implode($delimiter, $escaped);
	}
}
