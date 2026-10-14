<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCA\ContractCheck\Db\Contract;
use OCA\ContractCheck\Domain\DeadlineResult;

/**
 * Deadline dashboard aggregation (spec MH-03):
 *  - urgent cancel-by (< 30 days)
 *  - upcoming cancel-by (< 90 days)
 *  - missed windows (verstrichen)
 *  - expiring contracts (< 90 days to end)
 *  - missing documents
 * Everything scope-filtered; cost data only for cost-viewers.
 */
class DashboardService
{
	public function __construct(
		private readonly ContractService $contracts,
		private readonly DeadlineService $deadlines,
		private readonly DocumentService $documents,
		private readonly Clock $clock,
	) {
	}

	/** @return array<string, mixed> */
	public function build(string $uid): array
	{
		$all = $this->contracts->listForUser($uid, null, 'active', [], 500, 0);
		$serialized = $this->deadlines->serializeMany($all, $uid);

		$urgent = [];
		$upcoming = [];
		$missed = [];
		$expiring = [];
		foreach ($serialized as $row) {
			$d = $row['deadline'];
			if ($d['state'] === DeadlineResult::STATE_MISSED) {
				$missed[] = $row;
				continue;
			}
			if ($d['state'] === DeadlineResult::STATE_OPEN && $d['daysLeft'] !== null) {
				if ($d['daysLeft'] <= 30) {
					$urgent[] = $row;
				} elseif ($d['daysLeft'] <= 90) {
					$upcoming[] = $row;
				}
			}
			// Fixed/auto contracts approaching their end (expiry watch).
			if (($row['termKind'] ?? null) === 'fixed' && ($row['endDate'] ?? null) !== null
				&& $d['effectiveAt'] !== null) {
				$daysToEnd = \OCA\ContractCheck\Domain\DateMath::diffDays(
					$this->clock->today(), $row['endDate']
				);
				if ($daysToEnd >= 0 && $daysToEnd <= 90) {
					$row['daysToEnd'] = $daysToEnd;
					$expiring[] = $row;
				}
			}
		}

		$missingDocIds = array_flip($this->documents->missingContractIds());
		$missingDocs = array_values(array_filter(
			$serialized,
			static fn (array $row) => isset($missingDocIds[$row['id']])
		));

		$counts = [
			'total' => count($serialized),
			'urgent' => count($urgent),
			'missed' => count($missed),
			'missingDocs' => count($missingDocs),
		];

		// Cost summary (spec §2): serializeMany only attaches 'costs' for
		// cost-viewers, so aggregating here can never leak to other roles.
		$costSummary = null;
		$annual = [];
		$once = [];
		$withCosts = 0;
		foreach ($serialized as $row) {
			$r = $row['costs'] ?? null;
			if (!is_array($r)) {
				continue;
			}
			if (($r['entry_count'] ?? 0) > 0) {
				$withCosts++;
			}
			foreach (($r['annual_cents'] ?? []) as $cur => $v) {
				$annual[$cur] = ($annual[$cur] ?? 0) + $v;
			}
			foreach (($r['once_cents'] ?? []) as $cur => $v) {
				$once[$cur] = ($once[$cur] ?? 0) + $v;
			}
		}
		if ($annual !== [] || $once !== [] || $withCosts > 0) {
			$costSummary = [
				'annual_cents' => $annual,
				'once_cents' => $once,
				'contractsWithCosts' => $withCosts,
			];
		}

		return [
			'counts' => $counts,
			'urgent' => $urgent,
			'upcoming' => $upcoming,
			'missed' => $missed,
			'expiring' => $expiring,
			'missingDocs' => $missingDocs,
			'costSummary' => $costSummary,
		];
	}
}
