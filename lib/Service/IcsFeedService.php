<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCA\ContractCheck\Domain\DeadlineResult;
use OCA\ContractCheck\Domain\IcsBuilder;

/**
 * Token-authenticated read-only ICS feed of upcoming cancel-by dates —
 * one VEVENT per actionable deadline, VALARMs from the contract's own
 * lead days. Scope rules apply identically to the feed: a restricted
 * contract only shows in feeds of users allowed to see it.
 */
class IcsFeedService
{
	public function __construct(
		private readonly SettingsService $settings,
		private readonly ContractService $contracts,
		private readonly DeadlineService $deadlines,
		private readonly AccessService $access,
		private readonly Clock $clock,
	) {
	}

	/**
	 * @return array{uid:string, ics:string}|null null → invalid token (404, not 401 detail)
	 */
	public function feedForToken(string $token): ?array
	{
		$uid = $this->settings->uidForFeedToken($token);
		if ($uid === null || !$this->access->hasAppAccess($uid)) {
			return null;
		}

		$events = [];
		foreach ($this->contracts->listForUser($uid, null, 'active', [], 500, 0) as $contract) {
			$result = $this->deadlines->compute($contract);
			if (!$result->isActionable() || $result->cancelBy === null) {
				continue;
			}
			// Title is intentionally minimal: title + date only (NN-06 — no
			// partner, no costs, no notes in the calendar feed).
			$events[] = [
				'uid' => 'contractcheck-' . $contract->getId() . '-' . $result->instanceId($contract->getId()) . '@contractcheck',
				'title' => 'Cancel by: ' . $contract->getTitle(),
				'cancelBy' => $result->cancelBy,
				'effectiveAt' => $result->effectiveAt,
				'leadDays' => \OCA\ContractCheck\Db\Contract::decodeIntList($contract->getLeadDaysJson(), [60, 30]),
			];
		}

		return [
			'uid' => $uid,
			'ics' => IcsBuilder::build(
				$this->settings->getOrgName() . ' — contract deadlines',
				$events,
				$this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z')
			),
		];
	}
}
