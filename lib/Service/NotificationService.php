<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCP\Notification\IManager as INotificationManager;
use Psr\Log\LoggerInterface;

/**
 * Nextcloud-notification delivery. Payload discipline (spec NN-06):
 * subject params carry ONLY object id + deadline date + kind — never
 * partner names, never cost data. The Notifier renders title text.
 */
class NotificationService
{
	public const SUBJECT_DEADLINE = 'deadline_due';
	public const SUBJECT_EXPIRED = 'contract_expired';
	public const SUBJECT_DOC_MISSING = 'document_missing';

	public function __construct(
		private readonly INotificationManager $notifications,
		private readonly LoggerInterface $logger,
	private readonly Clock $clock,
	) {
	}

	/**
	 * @param array{daysLeft:int, kind:string} $params
	 */
	public function deadlineReminder(int $contractId, string $recipientUid, array $params): void
	{
		$this->send($contractId, $recipientUid, self::SUBJECT_DEADLINE, $params);
	}

	public function expired(int $contractId, string $recipientUid): void
	{
		$this->send($contractId, $recipientUid, self::SUBJECT_EXPIRED, ['kind' => 'expired']);
	}

	public function documentMissing(int $contractId, string $recipientUid): void
	{
		$this->send($contractId, $recipientUid, self::SUBJECT_DOC_MISSING, ['kind' => 'doc']);
	}

	/** @param array<string, int|string> $params */
	private function send(int $contractId, string $recipientUid, string $subject, array $params): void
	{
		try {
			$n = $this->notifications->createNotification();
			$n->setApp('contractcheck')
				->setUser($recipientUid)
				->setDateTime(\DateTime::createFromImmutable($this->clock->now()))
				->setObject('contract', (string)$contractId)
				->setSubject($subject, $params);
			$this->notifications->notify($n);
		} catch (\Throwable $e) {
			// Delivery must never break the job — a missed notification is
			// recoverable, a crashed scan is not.
			$this->logger->warning('ContractCheck notification failed: ' . $e->getMessage());
		}
	}
}
