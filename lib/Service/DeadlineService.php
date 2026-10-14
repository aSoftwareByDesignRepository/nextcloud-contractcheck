<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCA\ContractCheck\Db\Contract;
use OCA\ContractCheck\Db\PartyMapper;
use OCA\ContractCheck\Domain\DeadlineEngine;
use OCA\ContractCheck\Domain\DeadlineResult;

/**
 * Computes and attaches deadline information to contract views.
 * The engine result is derived data — never persisted (NN-02: recompute
 * is truth; the stored end_date only drives the roll).
 */
class DeadlineService
{
	public function __construct(
		private readonly DeadlineEngine $engine,
		private readonly ContractService $contracts,
		private readonly PartyMapper $parties,
		private readonly CostService $costs,
		private readonly AccessService $access,
		private readonly Clock $clock,
	) {
	}

	public function compute(Contract $c): DeadlineResult
	{
		return $this->engine->compute($this->contracts->ruleOf($c), $this->clock->today());
	}

	/**
	 * Post-compute deadline-window filter for the contracts list
	 * (ContractValidator::DEADLINE_FILTERS). Windows are relative to the
	 * injectable clock — never wall-clock.
	 *
	 * @param array<string, mixed> $row serialized contract row (serializeMany output)
	 */
	public function matchesDeadlineWindow(array $row, string $window): bool
	{
		$d = $row['deadline'] ?? null;
		if (!is_array($d)) {
			return false;
		}
		switch ($window) {
			case 'cancel30':
				return $d['state'] === DeadlineResult::STATE_OPEN
					&& $d['daysLeft'] !== null && $d['daysLeft'] <= 30;
			case 'cancel90':
				return $d['state'] === DeadlineResult::STATE_OPEN
					&& $d['daysLeft'] !== null && $d['daysLeft'] <= 90;
			case 'missed':
				return $d['state'] === DeadlineResult::STATE_MISSED;
			case 'none':
				return $d['state'] === DeadlineResult::STATE_NONE;
			case 'expiring':
				$end = $row['endDate'] ?? null;
				if (!is_string($end) || $end === '') {
					return false;
				}
				$days = \OCA\ContractCheck\Domain\DateMath::diffDays($this->clock->today(), $end);
				return $days >= 0 && $days <= 90;
		}
		return false;
	}

	/**
	 * Full API serialization of one contract for a viewer — deadline
	 * attached, party name resolved, costs ONLY when the viewer has the
	 * cost role (byte-level absence otherwise, spec GA-4).
	 *
	 * @return array<string, mixed>
	 */
	public function serialize(Contract $c, string $uid, bool $withRollup = true): array
	{
		$row = $c->toArray();
		$row['deadline'] = $this->compute($c)->toArray();
		$row['deadlineInstanceId'] = $this->compute($c)->instanceId($c->getId());
		$row['canEdit'] = $this->access->canEditContract($c, $uid);

		$partnerId = $c->getPartnerId();
		$row['partnerName'] = null;
		if ($partnerId !== null) {
			try {
				$row['partnerName'] = $this->parties->find($partnerId)->getName();
			} catch (\Throwable) {
				$row['partnerName'] = null; // archived/deleted party → don't 500 the view
			}
		}

		if ($this->access->canViewCosts($uid)) {
			if ($withRollup) {
				$row['costs'] = $this->costs->rollupForContract(
					$c->getId(), (int)$this->clock->now()->format('Y')
				);
			} else {
				$row['costs'] = null;
			}
		}
		// No else branch — the key is absent entirely for non-cost-viewers.

		return $row;
	}

	/**
	 * Batch-serialize with one rollup query for the whole page.
	 *
	 * @param list<Contract> $list
	 * @return list<array<string, mixed>>
	 */
	public function serializeMany(array $list, string $uid): array
	{
		$withCosts = $this->access->canViewCosts($uid);
		$ids = array_map(static fn (Contract $c) => $c->getId(), $list);
		$rollups = $withCosts
			? $this->costs->rollupsForContracts($ids, (int)$this->clock->now()->format('Y'))
			: [];
		$partyNames = $this->partyNamesFor($list);
		$today = $this->clock->today();

		$out = [];
		foreach ($list as $c) {
			$row = $c->toArray();
			$result = $this->engine->compute($this->contracts->ruleOf($c), $today);
			$row['deadline'] = $result->toArray();
			$row['deadlineInstanceId'] = $result->instanceId($c->getId());
			$row['canEdit'] = $this->access->canEditContract($c, $uid);
			$row['partnerName'] = $partyNames[$c->getPartnerId() ?? -1] ?? null;
			if ($withCosts) {
				$row['costs'] = $rollups[$c->getId()] ?? null;
			}
			$out[] = $row;
		}

		return $out;
	}

	/**
	 * @param list<Contract> $list
	 * @return array<int, string>
	 */
	private function partyNamesFor(array $list): array
	{
		$ids = [];
		foreach ($list as $c) {
			if ($c->getPartnerId() !== null) {
				$ids[$c->getPartnerId()] = true;
			}
		}
		$names = [];
		foreach (array_keys($ids) as $id) {
			try {
				$names[$id] = $this->parties->find($id)->getName();
			} catch (\Throwable) {
			}
		}

		return $names;
	}
}
