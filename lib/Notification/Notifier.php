<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Notification;

use OCA\ContractCheck\Service\ContractService;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\L10N\IFactory;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;
use OCP\Notification\UnknownNotificationException;

/**
 * Renders ContractCheck notifications. Subjects carry ids/dates only; the
 * notifier resolves the title at render time for the recipient's locale —
 * and only when the recipient may still see the contract (scope-safe).
 */
class Notifier implements INotifier
{
	public function __construct(
		private readonly IFactory $l10nFactory,
		private readonly IURLGenerator $urlGenerator,
		private readonly ContractService $contracts,
		private readonly IManager $manager,
	) {
	}

	public function getID(): string
	{
		return 'contractcheck';
	}

	public function getName(): string
	{
		return 'ContractCheck';
	}

	public function prepare(INotification $notification, string $languageCode): INotification
	{
		if ($notification->getApp() !== 'contractcheck') {
			throw new UnknownNotificationException();
		}
		if ($notification->getObjectType() !== 'contract') {
			throw new UnknownNotificationException();
		}
		$l = $this->l10nFactory->get('contractcheck', $languageCode);
		$contractId = (int)$notification->getObjectId();
		$subject = $notification->getSubject();

		$uid = $notification->getUser();
		try {
			$contract = $this->contracts->getForUser($contractId, $uid);
		} catch (\Throwable) {
			// Contract gone or no longer visible → drop the notification.
			$n = $this->manager->createNotification();
			$n->setApp('contractcheck')->setUser($uid)
				->setObject('contract', (string)$contractId)
				->setSubject($subject, []);
			$this->manager->markProcessed($n);
			throw new UnknownNotificationException();
		}

		$title = $contract->getTitle();
		$link = $this->urlGenerator->linkToRouteAbsolute('contractcheck.page.contractShow', ['id' => $contractId]);
		$notification->setLink($link);
		$notification->setIcon($this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath('contractcheck', 'app-dark.svg')
		));

		$params = $notification->getSubjectParameters();
		$daysLeft = isset($params['daysLeft']) ? (int)$params['daysLeft'] : null;

		$message = match ($subject) {
			'deadline_due' => $daysLeft === null || $daysLeft <= 0
				? $l->t('Cancellation deadline today: %s', [$title])
				: $l->t('Cancellation deadline in %1$d days: %2$s', [$daysLeft, $title]),
			'contract_expired' => $l->t('Contract expired: %s', [$title]),
			'document_missing' => $l->t('A linked document is missing: %s', [$title]),
			default => throw new UnknownNotificationException(),
		};

		return $notification->setParsedSubject($message);
	}
}
