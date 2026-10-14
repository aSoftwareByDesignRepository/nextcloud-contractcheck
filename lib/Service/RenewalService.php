<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCA\ContractCheck\Db\ContractMapper;
use OCA\ContractCheck\Domain\DateMath;
use OCA\ContractCheck\Domain\DeadlineEngine;
use OCP\IDBConnection;

/**
 * Auto-renewal roll-forward + fixed-term expiry (job-side).
 *
 * Concurrency contract (spec §9.6): the roll is a guarded conditional
 * UPDATE — WHERE status='active' AND end_date=<observed>. Two parallel job
 * runs can compute the same new end, but only the first statement matches;
 * the update itself is the lock, no advisory locks needed.
 */
class RenewalService
{
	public function __construct(
		private readonly ContractMapper $contracts,
		private readonly AuditService $audit,
		private readonly AccessService $access,
		private readonly NotificationService $notifications,
		private readonly DeadlineEngine $engine,
		private readonly Clock $clock,
		private readonly IDBConnection $db,
	) {
	}

	/**
	 * Roll auto-renew contracts whose stored end_date lies in the past, and
	 * expire fixed contracts whose end_date has passed.
	 *
	 * @return array{rolled:int, expired:int}
	 */
	public function run(): array
	{
		$today = $this->clock->today();
		$rolled = 0;
		$expired = 0;
		foreach ($this->contracts->findActiveForJob() as $contract) {
			if ($contract->getStatus() !== 'active') {
				continue;
			}
			$end = $contract->getEndDateString();
			if ($contract->getTermKind() === DeadlineEngine::TERM_AUTO_RENEW) {
				if ($end === null || DateMath::compare($end, $today) > 0) {
					continue;
				}
				$newEnd = $this->engine->rollEndDate(
					$end,
					(int)($contract->getRenewalValue() ?? 0),
					(string)($contract->getRenewalUnit() ?? ''),
					$today,
				);
				if ($newEnd === null) {
					continue;
				}
				// Guarded update: only applies if end_date is STILL the value
				// we rolled from — second parallel run loses and does nothing.
				// Update + audit commit atomically — a crash between them must
				// not leave a rolled end_date with no audit row.
				$this->db->beginTransaction();
				try {
					$affected = $this->contracts->guardedUpdate($contract->getId(), [
						'end_date' => $newEnd,
						'revision' => $contract->getRevision() + 1,
						'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
					], [
						['status', 'active'],
						['end_date', $end],
						['revision', $contract->getRevision()],
					]);
					if ($affected === 1) {
						$this->audit->record($contract->getId(), 'renewed', null, [
							'from' => $end,
							'to' => $newEnd,
						]);
					}
					$this->db->commit();
				} catch (\Throwable $e) {
					$this->db->rollBack();
					throw $e;
				}
				if ($affected === 1) {
					++$rolled;
				}
			} elseif ($contract->getTermKind() === DeadlineEngine::TERM_FIXED) {
				if ($end === null || DateMath::compare($end, $today) >= 0) {
					continue;
				}
				$this->db->beginTransaction();
				try {
					$affected = $this->contracts->guardedUpdate($contract->getId(), [
						'status' => 'expired',
						'revision' => $contract->getRevision() + 1,
						'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
					], [
						['status', 'active'],
						['end_date', $end],
						['revision', $contract->getRevision()],
					]);
					if ($affected === 1) {
						$this->audit->record($contract->getId(), 'expired', null, ['endDate' => $end]);
					}
					$this->db->commit();
				} catch (\Throwable $e) {
					$this->db->rollBack();
					throw $e;
				}
				if ($affected === 1) {
					++$expired;
					// Notifications post-commit: notify is external IO, never
					// inside the transaction.
					foreach ($this->access->reminderRecipients($contract) as $uid) {
						$this->notifications->expired($contract->getId(), $uid);
					}
				}
			}
		}

		return ['rolled' => $rolled, 'expired' => $expired];
	}
}
