<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCA\ContractCheck\Db\Cost;
use OCA\ContractCheck\Db\CostMapper;
use OCA\ContractCheck\Domain\CostRollup;
use OCA\ContractCheck\Domain\DateMath;
use OCA\ContractCheck\Exception\NotFoundException;
use OCA\ContractCheck\Exception\ValidationException;

/**
 * Cost entries — always scoped through the parent contract (never reached
 * by bare id from the wire) and only ever rendered for cost-viewers.
 */
class CostService
{
	public function __construct(
		private readonly CostMapper $costs,
		private readonly AuditService $audit,
	) {
	}

	/** @return list<Cost> */
	public function listForContract(int $contractId): array
	{
		return $this->costs->findByContract($contractId);
	}

	/**
	 * Batched rollup for list views — one query for all contracts.
	 *
	 * @param list<int> $contractIds
	 * @return array<int, array{annual_cents: array<string,int>, once_cents: array<string,int>, entry_count:int}>
	 */
	public function rollupsForContracts(array $contractIds, int $year): array
	{
		$grouped = [];
		foreach ($this->costs->findByContracts($contractIds) as $cost) {
			$grouped[$cost->getContractId()][] = [
				'amount_cents' => $cost->getAmountCents(),
				'currency' => $cost->getCurrency(),
				'interval' => $cost->getInterval(),
				'valid_from' => $cost->getValidFromString(),
				'valid_to' => $cost->getValidToString(),
			];
		}
		$out = [];
		foreach ($contractIds as $id) {
			$out[$id] = CostRollup::rollupYear($grouped[$id] ?? [], $year);
		}

		return $out;
	}

	/**
	 * Grouped rollup for reports (spec US-206: per contract / category /
	 * cost center). Caller supplies the already scope-filtered contract
	 * list — grouping never widens visibility.
	 *
	 * @param list<\OCA\ContractCheck\Db\Contract> $contracts
	 * @return list<array{key:string, contracts:int, annual_cents:array<string,int>, once_cents:array<string,int>}>
	 *         Rows without any cost entries still appear when they carry a
	 *         group key (budgeting needs the bucket), sorted by key.
	 */
	public function rollupGrouped(array $contracts, string $groupBy, int $year): array
	{
		if (!in_array($groupBy, ['contract', 'type', 'costCenter'], true)) {
			throw ValidationException::field('GROUPBY_INVALID', 'groupBy', 'unknown grouping');
		}
		$ids = array_map(static fn ($c) => $c->getId(), $contracts);
		$rollups = $this->rollupsForContracts($ids, $year);
		$groups = [];
		foreach ($contracts as $c) {
			$r = $rollups[$c->getId()] ?? ['annual_cents' => [], 'once_cents' => [], 'entry_count' => 0];
			if ($groupBy === 'contract') {
				$key = (string)$c->getId();
			} elseif ($groupBy === 'type') {
				$key = $c->getType();
			} else {
				$cc = $c->getCostCenter();
				$key = ($cc === null || $cc === '') ? '' : $cc;
			}
			if ($groupBy === 'contract' && $r['entry_count'] === 0) {
				continue; // per-contract view lists only contracts with costs
			}
			$g = $groups[$key] ?? ['key' => $key, 'contracts' => 0, 'annual_cents' => [], 'once_cents' => []];
			$g['contracts']++;
			foreach ($r['annual_cents'] as $cur => $v) {
				$g['annual_cents'][$cur] = ($g['annual_cents'][$cur] ?? 0) + $v;
			}
			foreach ($r['once_cents'] as $cur => $v) {
				$g['once_cents'][$cur] = ($g['once_cents'][$cur] ?? 0) + $v;
			}
			$groups[$key] = $g;
		}
		$out = array_values($groups);
		usort($out, static fn ($a, $b) => strcasecmp($a['key'], $b['key']));

		return $out;
	}

	/** @return array{annual_cents: array<string,int>, once_cents: array<string,int>, entry_count:int} */
	public function rollupForContract(int $contractId, int $year): array
	{
		return $this->rollupsForContracts([$contractId], $year)[$contractId];
	}

	/** @param array<string, mixed> $data */
	public function add(int $contractId, array $data, string $actorUid): Cost
	{
		$v = self::validate($data);
		$cost = new Cost();
		$cost->setContractId($contractId);
		$this->apply($cost, $v);
		$cost = $this->costs->insert($cost);
		$this->audit->record($contractId, 'cost_added', $actorUid, ['costId' => $cost->getId()]);

		return $cost;
	}

	/** @param array<string, mixed> $data */
	public function update(int $contractId, int $costId, array $data, string $actorUid): Cost
	{
		$cost = $this->costs->find($costId);
		if ($cost->getContractId() !== $contractId) {
			throw new NotFoundException('Cost not found'); // foreign-contract id → uniform 404
		}
		$v = self::validate($data);
		$this->apply($cost, $v);
		$cost = $this->costs->update($cost);
		$this->audit->record($contractId, 'cost_updated', $actorUid, ['costId' => $costId]);

		return $cost;
	}

	public function delete(int $contractId, int $costId, string $actorUid): void
	{
		if ($this->costs->deleteForContract($costId, $contractId) !== 1) {
			throw new NotFoundException('Cost not found');
		}
		$this->audit->record($contractId, 'cost_removed', $actorUid, ['costId' => $costId]);
	}

	/**
	 * @param array<string, mixed> $in
	 * @return array{label:string,amountCents:int,currency:string,interval:string,validFrom:?string,validTo:?string}
	 */
	public static function validate(array $in): array
	{
		$fields = [];
		$label = is_string($in['label'] ?? null) ? mb_substr(trim($in['label']), 0, 255) : '';
		if ($label === '') {
			$fields['label'] = 'required';
		}

		// Strict integer cents — no floats, no numeric strings with decimals.
		$amount = $in['amountCents'] ?? null;
		$amountCents = null;
		if (is_int($amount) && $amount >= 0) {
			$amountCents = $amount;
		} elseif (is_string($amount) && preg_match('/^\d+$/', $amount) === 1) {
			$amountCents = (int)$amount;
		}
		if ($amountCents === null) {
			$fields['amountCents'] = 'integer cents ≥ 0 required';
		}

		$currency = is_string($in['currency'] ?? null) ? strtoupper(trim($in['currency'])) : '';
		if (!preg_match('/^[A-Z]{3}$/', $currency)) {
			$fields['currency'] = 'ISO 4217 (e.g. EUR)';
		}

		$interval = $in['interval'] ?? 'yearly';
		if (!is_string($interval) || !CostRollup::isValidInterval($interval)) {
			$fields['interval'] = 'invalid';
			$interval = 'yearly';
		}

		$from = self::ndate($in['validFrom'] ?? null, 'validFrom', $fields);
		$to = self::ndate($in['validTo'] ?? null, 'validTo', $fields);
		if ($from !== null && $to !== null && DateMath::compare($to, $from) < 0) {
			$fields['validTo'] = 'before valid from';
		}

		if ($fields !== []) {
			throw new ValidationException('COST_INVALID', 'Please check the highlighted fields.', $fields);
		}
		assert(is_int($amountCents));

		return [
			'label' => $label,
			'amountCents' => $amountCents,
			'currency' => $currency,
			'interval' => $interval,
			'validFrom' => $from,
			'validTo' => $to,
		];
	}

	/** @param array<string, string> $fields */
	private static function ndate(mixed $v, string $field, array &$fields): ?string
	{
		if ($v === null || $v === '') {
			return null;
		}
		if (is_string($v) && DateMath::isValid($v)) {
			return $v;
		}
		$fields[$field] = 'invalid date (YYYY-MM-DD)';

		return null;
	}

	/** @param array{label:string,amountCents:int,currency:string,interval:string,validFrom:?string,validTo:?string} $v */
	private function apply(Cost $c, array $v): void
	{
		$c->setLabel($v['label']);
		$c->setAmountCents($v['amountCents']);
		$c->setCurrency($v['currency']);
		$c->setInterval($v['interval']);
		$c->setValidFrom($v['validFrom'] === null ? null : DateMath::parse($v['validFrom']));
		$c->setValidTo($v['validTo'] === null ? null : DateMath::parse($v['validTo']));
	}
}
