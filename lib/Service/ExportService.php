<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCA\ContractCheck\Domain\CsvBuilder;

/**
 * CSV + PDF exports of the contract register.
 *
 * Column scoping (spec US-206 AC-3 / GA-4): cost columns are decided at
 * query/serialization — a non-cost-viewer export literally never contains
 * the column, byte-checked by tests.
 */
class ExportService
{
	public function __construct(
		private readonly ContractService $contracts,
		private readonly DeadlineService $deadlines,
		private readonly AccessService $access,
		private readonly Clock $clock,
	) {
	}

	public function buildCsv(string $uid): string
	{
		$rows = $this->exportRows($uid);
		$withCosts = $this->access->canViewCosts($uid);
		$headers = ['Ref', 'Title', 'Type', 'Partner', 'Owner', 'Scope', 'Status',
			'Start', 'End', 'Term', 'Cancel by', 'Effective', 'Cost center', 'Tags'];
		if ($withCosts) {
			$headers[] = 'Annual costs';
			$headers[] = 'Currency';
		}
		$csvRows = [];
		foreach ($rows as $r) {
			$d = $r['deadline'];
			$line = [
				(string)($r['refNo'] ?? ''),
				$r['title'],
				$r['type'],
				(string)($r['partnerName'] ?? ''),
				(string)($r['ownerUid'] ?? ''),
				$r['scope'],
				$r['status'],
				(string)($r['startDate'] ?? ''),
				(string)($r['endDate'] ?? ''),
				$r['termKind'],
				(string)($d['cancelBy'] ?? ''),
				(string)($d['effectiveAt'] ?? ''),
				(string)($r['costCenter'] ?? ''),
				implode(', ', $r['tags'] ?? []),
			];
			if ($withCosts) {
				[$amount, $currency] = $this->formatAnnual($r['costs'] ?? null);
				$line[] = $amount;
				$line[] = $currency;
			}
			$csvRows[] = $line;
		}

		return CsvBuilder::build($headers, $csvRows);
	}

	public function buildPdf(string $uid): string
	{
		$rows = $this->exportRows($uid);
		$withCosts = $this->access->canViewCosts($uid);
		$columns = ['Ref', 'Title', 'Partner', 'Status', 'End', 'Cancel by'];
		$widths = [0.10, 0.30, 0.20, 0.10, 0.13, 0.17];
		if ($withCosts) {
			$columns[] = 'Annual';
			$widths = [0.09, 0.25, 0.17, 0.09, 0.12, 0.15, 0.13];
		}
		$pdfRows = [];
		foreach ($rows as $r) {
			$d = $r['deadline'];
			$line = [
				(string)($r['refNo'] ?? ''),
				$r['title'],
				(string)($r['partnerName'] ?? ''),
				$r['status'],
				(string)($r['endDate'] ?? ''),
				(string)($d['cancelBy'] ?? ''),
			];
			if ($withCosts) {
				[$amount, $currency] = $this->formatAnnual($r['costs'] ?? null);
				$line[] = trim($amount . ' ' . $currency);
			}
			$pdfRows[] = $line;
		}

		return SimplePdfBuilder::buildStatement([
			'brand' => 'ContractCheck',
			'title' => 'Contract register',
			'columns' => $columns,
			'colWidths' => $widths,
			'rows' => $pdfRows,
			'totals' => [],
			'note' => 'Generated ' . $this->clock->now()->format('Y-m-d H:i'),
		]);
	}

	/**
	 * Rows are produced by the same scoped serializer the API uses —
	 * export can never see more than the API (spec: scope at query).
	 *
	 * @return list<array<string, mixed>>
	 */
	private function exportRows(string $uid): array
	{
		$all = $this->contracts->listForUser($uid, null, null, [], 500, 0);

		return $this->deadlines->serializeMany($all, $uid);
	}

	/**
	 * @param array{annual_cents: array<string,int>}|null $costs
	 * @return array{0:string,1:string}
	 */
	private function formatAnnual(?array $costs): array
	{
		if ($costs === null || ($costs['annual_cents'] ?? []) === []) {
			return ['', ''];
		}
		$parts = [];
		$currency = '';
		foreach ($costs['annual_cents'] as $cur => $cents) {
			$parts[] = number_format($cents / 100, 2, '.', '');
			$currency = $cur;
		}

		return [implode(' / ', $parts), $currency];
	}
}
