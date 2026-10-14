<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Domain;

/**
 * Annualized cost rollup (spec §9.3).
 *
 * annual = Σ amount_cents × factor(interval), entries filtered to those
 * covering the rollup year [Y-01-01, Y-12-31]. 'once' is excluded from the
 * annual figure (reported separately as one-time total). All money stays
 * in integer cents — no floats anywhere.
 */
final class CostRollup
{
	private function __construct()
	{
	}

	/** @var array<string, int> */
	public const INTERVAL_FACTORS = [
		'once' => 0,
		'monthly' => 12,
		'quarterly' => 4,
		'yearly' => 1,
	];

	public const INTERVALS = ['once', 'monthly', 'quarterly', 'yearly'];

	public static function isValidInterval(string $interval): bool
	{
		return array_key_exists($interval, self::INTERVAL_FACTORS);
	}

	/**
	 * Does the entry's validity window intersect rollup year $year?
	 * No bounds → always covered.
	 */
	public static function coversYear(?string $validFrom, ?string $validTo, int $year): bool
	{
		$yearStart = sprintf('%04d-01-01', $year);
		$yearEnd = sprintf('%04d-12-31', $year);
		if ($validFrom !== null && $validFrom !== '' && DateMath::compare($validFrom, $yearEnd) > 0) {
			return false;
		}
		if ($validTo !== null && $validTo !== '' && DateMath::compare($validTo, $yearStart) < 0) {
			return false;
		}

		return true;
	}

	/**
	 * @param list<array{amount_cents:int, currency:string, interval:string, valid_from:?string, valid_to:?string}> $entries
	 * @return array{annual_cents: array<string,int>, once_cents: array<string,int>, entry_count:int}
	 *         Per-currency sums — currencies are never mixed silently.
	 */
	public static function rollupYear(array $entries, int $year): array
	{
		$annual = [];
		$once = [];
		$count = 0;
		foreach ($entries as $e) {
			if (!self::coversYear($e['valid_from'] ?? null, $e['valid_to'] ?? null, $year)) {
				continue;
			}
			$factor = self::INTERVAL_FACTORS[$e['interval']] ?? 0;
			$currency = strtoupper((string)($e['currency'] ?? 'EUR'));
			if ($factor === 0) {
				// once: reported raw, never multiplied into the annualized sum
				$once[$currency] = ($once[$currency] ?? 0) + $e['amount_cents'];
			} else {
				$annual[$currency] = ($annual[$currency] ?? 0) + $e['amount_cents'] * $factor;
			}
			++$count;
		}

		return ['annual_cents' => $annual, 'once_cents' => $once, 'entry_count' => $count];
	}
}
