<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCA\ContractCheck\AppInfo\Application;
use OCA\ContractCheck\Domain\DeadlineResult;
use OCA\ContractCheck\Domain\IcsBuilder;
use OCP\IL10N;
use OCP\IUserManager;
use OCP\L10N\IFactory as IL10NFactory;

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
		private readonly IUserManager $userManager,
		private readonly IL10NFactory $l10nFactory,
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

		// No session on this public route — resolve the feed owner's own
		// language so calendar clients see their configured locale.
		$l10n = $this->feedL10n($uid);

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
				'title' => $l10n->t('Cancel by ') . $contract->getTitle(),
				'cancelBy' => $result->cancelBy,
				'description' => $result->effectiveAt !== null
					? $l10n->t('Effective at') . ': ' . $result->effectiveAt
					: null,
				'leadDays' => \OCA\ContractCheck\Db\Contract::decodeIntList($contract->getLeadDaysJson(), [60, 30]),
			];
		}

		return [
			'uid' => $uid,
			'ics' => IcsBuilder::build(
				$this->settings->getOrgName() . ' — ' . $l10n->t('Contract deadlines'),
				$events,
				$this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z')
			),
		];
	}

	/**
	 * The feed is token-authenticated (public route, no session user), so the
	 * request's ambient locale is meaningless. Render in the feed owner's
	 * configured language; a deleted owner falls back to the generic resolver.
	 */
	private function feedL10n(string $uid): IL10N
	{
		$user = $this->userManager->get($uid);
		$lang = $user !== null
			? $this->l10nFactory->getUserLanguage($user)
			: $this->l10nFactory->findLanguage(Application::APP_ID);

		return $this->l10nFactory->get(Application::APP_ID, $lang);
	}
}
