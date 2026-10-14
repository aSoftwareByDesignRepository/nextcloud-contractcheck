<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCA\ContractCheck\Db\Contract;
use OCA\ContractCheck\Db\ContractMapper;
use OCA\ContractCheck\Db\ReminderSent;
use OCA\ContractCheck\Db\ReminderSentMapper;
use OCA\ContractCheck\Domain\DeadlineEngine;
use OCA\ContractCheck\Domain\DeadlineResult;
use Psr\Log\LoggerInterface;

/**
 * Deadline reminder dispatch (job-side).
 *
 * Dedup key: (contract_id, deadline_instance_id, lead_day) where the
 * instance id hashes contract id + effective date — amending a contract
 * re-arms reminders, re-running the job never re-sends (spec US-203).
 *
 * Skipped: 'anytime' anchors (always kündbar — no real deadline) and
 * non-actionable results.
 */
class ReminderService
{
	/**
	 * A claimed-but-unconfirmed dedup row older than this is treated as a
	 * crashed delivery and reclaimed by the next pass. Well below the
	 * daily job interval; far above any live notify() duration, so a
	 * still-running claimer is never stolen mid-delivery.
	 */
	public const CLAIM_STALE_SECONDS = 600;

	public function __construct(
		private readonly ContractMapper $contracts,
		private readonly ReminderSentMapper $sent,
		private readonly ContractService $contractService,
		private readonly AccessService $access,
		private readonly NotificationService $notifications,
	private readonly AuditService $audit,
		private readonly DeadlineEngine $engine,
		private readonly Clock $clock,
		private readonly LoggerInterface $logger,
	) {
	}

	/** @return int number of notifications dispatched */
	public function dispatchDueReminders(): int
	{
		$today = $this->clock->today();
		$dispatched = 0;
		foreach ($this->contracts->findActiveForJob() as $contract) {
			if ($contract->getStatus() !== 'active') {
				continue;
			}
			try {
				$dispatched += $this->dispatchForContract($contract, $today);
			} catch (\Throwable $e) {
				// One broken contract must not starve every contract behind
				// it for a whole day.
				$this->logger->error(
					'ContractCheck: reminder dispatch failed for contract ' . $contract->getId()
					. ': ' . $e->getMessage(),
					['exception' => $e],
				);
			}
		}

		return $dispatched;
	}

	public function dispatchForContract(Contract $contract, string $today): int
	{
		$result = $this->engine->compute($this->contractService->ruleOf($contract), $today);
		if (!$result->isActionable()
			|| $contract->getNoticeAnchor() === DeadlineEngine::ANCHOR_ANYTIME) {
			return 0;
		}
		$leadDays = Contract::decodeIntList($contract->getLeadDaysJson(), [60, 30]);
		$daysLeft = $result->daysLeft;
		if ($daysLeft === null) {
			return 0;
		}
		$instanceId = $result->instanceId($contract->getId());
		$recipients = $this->access->reminderRecipients($contract);
		if ($recipients === []) {
			$this->logger->info('ContractCheck: no reminder recipients for contract ' . $contract->getId());

			return 0;
		}

		$dispatched = 0;
		foreach ($leadDays as $leadDay) {
			// Send when inside the lead window (daysLeft <= lead) — once.
			if ($daysLeft > $leadDay) {
				continue;
			}
			$row = new ReminderSent();
			$row->setContractId($contract->getId());
			$row->setDeadlineInstanceId($instanceId);
			$row->setLeadDay($leadDay);
			$row->setSentAt($this->clock->now());
			$staleBefore = $this->clock->now()->sub(new \DateInterval('PT' . self::CLAIM_STALE_SECONDS . 'S'));
			if (!$this->sent->claim($row, $staleBefore)) {
				continue; // confirmed, or a fresh claim owned by a live run
			}
			try {
				foreach ($recipients as $uid) {
					$this->notifications->deadlineReminder($contract->getId(), $uid, [
						'daysLeft' => $daysLeft,
						'kind' => 'cancel_by',
					]);
					++$dispatched;
				}
			} catch (\Throwable $e) {
				// Claim-then-deliver means a transient notification failure
				// would permanently swallow the reminder — release the claim
				// so the next run retries (at-least-once over at-most-once).
				try {
					$this->sent->markUnsent($contract->getId(), $instanceId, $leadDay);
				} catch (\Throwable $u) {
					$this->logger->error('ContractCheck: failed to release reminder claim: ' . $u->getMessage());
				}
				throw $e;
			}
			try {
				$this->sent->confirmSent($contract->getId(), $instanceId, $leadDay, $this->clock->now());
			} catch (\Throwable $e) {
				// Confirmation failed: the row stays pending and will be
				// reclaimed when stale → a duplicate notification may go
				// out, which is the correct at-least-once degradation.
				$this->logger->error('ContractCheck: reminder claim confirm failed: ' . $e->getMessage());
			}
			try {
				$this->audit->record($contract->getId(), 'reminder_sent', null, [
					'leadDay' => $leadDay,
					'daysLeft' => $daysLeft,
				]);
			} catch (\Throwable $e) {
				// Audit failure must not undo a delivered notification.
				$this->logger->warning('ContractCheck: reminder audit failed: ' . $e->getMessage());
			}
		}

		return $dispatched;
	}
}
