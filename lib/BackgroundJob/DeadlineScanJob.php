<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\BackgroundJob;

use OCA\ContractCheck\Service\DocumentService;
use OCA\ContractCheck\Service\ReminderService;
use OCA\ContractCheck\Service\RenewalService;
use OCA\ContractCheck\Service\UpgradeBackupService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use Psr\Log\LoggerInterface;

/**
 * Daily deadline scan — one job, three idempotent passes:
 *   1. renewal roll-forward + fixed-term expiry (guarded conditional UPDATEs)
 *   2. reminder dispatch (dedup table is the idempotency key)
 *   3. missing-document rescan (missing_since only set once)
 *
 * All three passes are safe under concurrent execution: each is either a
 * conditional UPDATE, a PK-deduped insert, or a set-once marker.
 */
class DeadlineScanJob extends TimedJob
{
	private const INTERVAL_SECONDS = 86400;

	public function __construct(
		ITimeFactory $time,
		private readonly RenewalService $renewals,
		private readonly ReminderService $reminders,
		private readonly DocumentService $documents,
		private readonly LoggerInterface $logger,
		private readonly ILockingProvider $locking,
	) {
		parent::__construct($time);
		$this->setInterval(self::INTERVAL_SECONDS);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	/** @param mixed $argument */
	protected function run($argument): void
	{
		// Shared lock on the backup key: an admin running
		// `contractcheck:upgrade-backup restore` holds it exclusively — the
		// scan skips rather than writing into tables mid-restore (writes
		// between truncate and insert would be silently rolled back).
		try {
			$this->locking->acquireLock(
				UpgradeBackupService::LOCK_KEY,
				ILockingProvider::LOCK_SHARED,
				'ContractCheck deadline scan',
			);
		} catch (LockedException) {
			$this->logger->warning('ContractCheck: skipping deadline scan — upgrade backup/restore in progress.');
			return;
		}

		try {
			$this->runPasses();
		} finally {
			$this->locking->releaseLock(UpgradeBackupService::LOCK_KEY, ILockingProvider::LOCK_SHARED);
		}
	}

	private function runPasses(): void
	{
		// Each pass isolated: a failed pass must not starve the other two
		// for a whole day (nor take Nextcloud's job queue down).
		$rolled = 0;
		$expired = 0;
		$reminded = 0;
		$missing = [];

		try {
			$result = $this->renewals->run();
			$rolled = $result['rolled'];
			$expired = $result['expired'];
		} catch (\Throwable $e) {
			$this->logger->error('ContractCheck renewal pass failed: ' . $e->getMessage(), ['exception' => $e]);
		}
		try {
			$reminded = $this->reminders->dispatchDueReminders();
		} catch (\Throwable $e) {
			$this->logger->error('ContractCheck reminder pass failed: ' . $e->getMessage(), ['exception' => $e]);
		}
		try {
			$missing = $this->documents->rescanMissing();
		} catch (\Throwable $e) {
			$this->logger->error('ContractCheck document pass failed: ' . $e->getMessage(), ['exception' => $e]);
		}

		if ($rolled > 0 || $expired > 0 || $reminded > 0 || $missing !== []) {
			$this->logger->info(sprintf(
				'ContractCheck scan: %d rolled, %d expired, %d reminders, %d contracts with missing docs',
				$rolled, $expired, $reminded, count($missing)
			));
		}
	}
}
