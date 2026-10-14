<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCA\ContractCheck\Exception\ValidationException;
use Psr\Log\LoggerInterface;

/**
 * CSV contract import — same validation as the create API (rows go through
 * ContractValidator + ContractService::create, never a shortcut path).
 * Per-row error collection: a bad row reports line + field, good rows
 * still import. Row cap prevents memory bombs.
 */
class CsvImportService
{
	private const MAX_ROWS = 2000;

	/** Canonical column → accepted header aliases (en + de). */
	private const COLUMN_MAP = [
		'title' => ['title', 'titel', 'name'],
		'refNo' => ['ref', 'ref_no', 'refno', 'referenz', 'vertragsnummer'],
		'type' => ['type', 'typ'],
		'partnerName' => ['partner', 'vertragspartner', 'vendor', 'anbieter'],
		'scope' => ['scope', 'sichtbarkeit'],
		'signedAt' => ['signed', 'signed_at', 'unterzeichnet'],
		'startDate' => ['start', 'start_date', 'beginn'],
		'endDate' => ['end', 'end_date', 'ende'],
		'termKind' => ['term', 'term_kind', 'laufzeitart'],
		'renewalValue' => ['renewal', 'renewal_value', 'verlaengerung'],
		'renewalUnit' => ['renewal_unit', 'verlaengerung_einheit'],
		'noticeValue' => ['notice', 'notice_value', 'kuendigungsfrist'],
		'noticeUnit' => ['notice_unit', 'frist_einheit'],
		'noticeAnchor' => ['anchor', 'notice_anchor', 'bezugspunkt'],
		'costCenter' => ['cost_center', 'kostenstelle'],
		'notes' => ['notes', 'notizen'],
	];

	public function __construct(
		private readonly ContractService $contracts,
		private readonly PartyService $parties,
		private readonly LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{imported:int, errors:list<array{line:int,field:string,message:string}>}
	 */
	public function import(string $csv, string $actorUid): array
	{
		$rows = $this->parse($csv);
		$errors = [];
		$warnings = [];
		$imported = 0;
		foreach ($rows as $lineNo => $row) {
			try {
				$data = $this->rowToContractData($row, $actorUid);
				$contract = $this->contracts->create($data, $actorUid);
				++$imported;
				// Over-length fields are clamped by the validator — surface the
				// truncation instead of silently shortening stored data.
				foreach (['title' => 255, 'refNo' => 64, 'costCenter' => 64, 'notes' => 10000] as $f => $max) {
					if (mb_strlen(trim($row[$f] ?? '')) > $max) {
						$warnings[] = ['line' => $lineNo, 'field' => $f, 'message' => 'value truncated to ' . $max . ' characters'];
					}
				}
			} catch (ValidationException $e) {
				foreach ($e->getFields() as $field => $message) {
					$errors[] = ['line' => $lineNo, 'field' => $field, 'message' => $message];
				}
				if ($e->getFields() === []) {
					$errors[] = ['line' => $lineNo, 'field' => '', 'message' => $e->getMessage()];
				}
			} catch (\Throwable $e) {
				// Never echo raw exception text — a DB failure could leak
				// schema/SQL fragments to the client. Log details instead.
				$this->logger->error('ContractCheck import row failed: ' . $e->getMessage());
				$errors[] = ['line' => $lineNo, 'field' => '', 'message' => 'row could not be imported'];
			}
		}

		return ['imported' => $imported, 'errors' => $errors, 'warnings' => $warnings];
	}

	/**
	 * Parse the CSV (auto-detects `;` / `,` / tab + optional UTF-8 BOM).
	 *
	 * @return array<int, array<string, string>> line-number → assoc row
	 */
	public function parse(string $csv): array
	{
		$csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
		$fh = fopen('php://temp', 'r+');
		if ($fh === false) {
			throw new ValidationException('IMPORT_INVALID', 'Cannot read upload');
		}
		fwrite($fh, $csv);
		rewind($fh);
		$header = fgetcsv($fh, 0, ';', escape: '');
		$delimiter = ';';
		if ($header === false || count($header) < 2) {
			rewind($fh);
			$header = fgetcsv($fh, 0, ',', escape: '');
			$delimiter = ',';
			if ($header === false || count($header) < 2) {
				rewind($fh);
				$header = fgetcsv($fh, 0, "\t", escape: '');
				$delimiter = "\t";
			}
		}
		if ($header === false || count($header) < 2) {
			fclose($fh);
			throw new ValidationException('IMPORT_INVALID', 'CSV header not recognized');
		}
		$columns = [];
		foreach ($header as $i => $h) {
			$key = self::columnFor(strtolower(trim((string)$h)));
			if ($key !== null) {
				$columns[$i] = $key;
			}
		}
		if (!in_array('title', $columns, true)) {
			fclose($fh);
			throw new ValidationException('IMPORT_INVALID', 'CSV must contain a title column', ['file' => 'missing title column']);
		}

		$out = [];
		$lineNo = 1;
		while (($row = fgetcsv($fh, 0, $delimiter, escape: '')) !== false) {
			++$lineNo;
			if ($lineNo - 1 > self::MAX_ROWS) {
				fclose($fh);
				throw new ValidationException('IMPORT_INVALID', 'Too many rows (max ' . self::MAX_ROWS . ')');
			}
			$assoc = [];
			$empty = true;
			foreach ($columns as $i => $key) {
				$val = isset($row[$i]) ? trim((string)$row[$i]) : '';
				if ($val !== '') {
					$empty = false;
				}
				$assoc[$key] = $val;
			}
			if (!$empty) {
				$out[$lineNo] = $assoc;
			}
		}
		fclose($fh);

		return $out;
	}

	/**
	 * @param array<string, string> $row
	 * @return array<string, mixed>
	 */
	private function rowToContractData(array $row, string $actorUid): array
	{
		$data = [
			'title' => $row['title'] ?? '',
			'refNo' => $row['refNo'] ?? '',
			'type' => $this->enumOr($row['type'] ?? '', \OCA\ContractCheck\Service\ContractValidator::TYPES, 'other'),
			'scope' => $this->enumOr($row['scope'] ?? '', \OCA\ContractCheck\Service\ContractValidator::SCOPES, 'org'),
			'signedAt' => $this->dateOrNull($row['signedAt'] ?? ''),
			'startDate' => $this->dateOrNull($row['startDate'] ?? ''),
			'endDate' => $this->dateOrNull($row['endDate'] ?? ''),
			'termKind' => $this->enumOr($row['termKind'] ?? '', \OCA\ContractCheck\Service\ContractValidator::TERM_KINDS, 'fixed'),
			'noticeAnchor' => $this->enumOr($row['noticeAnchor'] ?? '', \OCA\ContractCheck\Service\ContractValidator::ANCHORS, 'contract_end'),
			'costCenter' => $row['costCenter'] ?? '',
			'notes' => $row['notes'] ?? '',
		];
		if (($row['partnerName'] ?? '') !== '') {
			$data['partnerId'] = $this->partyIdForName($row['partnerName'], $actorUid);
		}
		if (($row['renewalValue'] ?? '') !== '') {
			$data['renewalValue'] = is_numeric($row['renewalValue']) ? (int)$row['renewalValue'] : $row['renewalValue'];
			$data['renewalUnit'] = $this->enumOr($row['renewalUnit'] ?? '', \OCA\ContractCheck\Service\ContractValidator::RENEWAL_UNITS, 'months');
		}
		if (($row['noticeValue'] ?? '') !== '') {
			$data['noticeValue'] = is_numeric($row['noticeValue']) ? (int)$row['noticeValue'] : $row['noticeValue'];
			$data['noticeUnit'] = $this->enumOr($row['noticeUnit'] ?? '', \OCA\ContractCheck\Service\ContractValidator::NOTICE_UNITS, 'months');
		}

		return $data;
	}

	private function partyIdForName(string $name, string $actorUid): int
	{
		foreach ($this->parties->listForUser('', $name, false) as $party) {
			if (mb_strtolower($party->getName()) === mb_strtolower($name)) {
				return $party->getId();
			}
		}
		// Auto-create missing counterparties so an import is complete.
		$party = $this->parties->create(['name' => $name], $actorUid);

		return $party->getId();
	}

	private function dateOrNull(string $v): ?string
	{
		$v = trim($v);
		if ($v === '') {
			return null;
		}
		// Accept d.m.Y (German exports) alongside ISO.
		if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $v, $m)) {
			$v = sprintf('%04d-%02d-%02d', (int)$m[3], (int)$m[2], (int)$m[1]);
		}

		// Invalid dates pass through raw so the validator reports the field
		// instead of silently dropping e.g. a mistyped signed date.
		return $v;
	}

	/** @param list<string> $allowed */
	private function enumOr(string $v, array $allowed, string $default): string
	{
		$v = strtolower(trim($v));
		if ($v === '') {
			return $default;
		}

		return in_array($v, $allowed, true) ? $v : $v; // invalid → validator errors, never a silent default
	}

	private static function columnFor(string $header): ?string
	{
		foreach (self::COLUMN_MAP as $key => $aliases) {
			if (in_array($header, $aliases, true)) {
				return $key;
			}
		}

		return null;
	}
}
