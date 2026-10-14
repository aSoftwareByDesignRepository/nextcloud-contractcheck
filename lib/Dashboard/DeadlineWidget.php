<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Dashboard;

use OCA\ContractCheck\AppInfo\Application;
use OCA\ContractCheck\Service\DashboardService;
use OCP\Dashboard\IAPIWidgetV2;
use OCP\Dashboard\IButtonWidget;
use OCP\Dashboard\IIconWidget;
use OCP\Dashboard\IReloadableWidget;
use OCP\Dashboard\Model\WidgetButton;
use OCP\Dashboard\Model\WidgetItem;
use OCP\Dashboard\Model\WidgetItems;
use OCP\IL10N;
use OCP\IURLGenerator;

/**
 * "Upcoming contract deadlines" widget on the Nextcloud dashboard —
 * server-rendered item list, no JS stack needed.
 */
class DeadlineWidget implements IAPIWidgetV2, IButtonWidget, IIconWidget, IReloadableWidget
{
	public function __construct(
		private readonly IL10N $l10n,
		private readonly IURLGenerator $urlGenerator,
		private readonly DashboardService $dashboard,
	) {
	}

	public function getId(): string
	{
		return Application::APP_ID . '-deadlines';
	}

	public function getTitle(): string
	{
		return $this->l10n->t('Contract deadlines');
	}

	public function getOrder(): int
	{
		return 30;
	}

	public function getIconClass(): string
	{
		return 'icon-contractcheck';
	}

	public function getIconUrl(): string
	{
		return $this->urlGenerator->getAbsoluteURL(
			$this->urlGenerator->imagePath(Application::APP_ID, 'app-dark.svg')
		);
	}

	public function getUrl(): ?string
	{
		return $this->urlGenerator->linkToRouteAbsolute('contractcheck.page.dashboard');
	}

	public function load(): void
	{
		// No scripts — pure IAPIWidgetV2 + server data (family widget rule:
		// app l10n JS on the dashboard has historically broken /apps/dashboard).
	}

	public function getItemsV2(string $userId, ?string $since = null, int $limit = 7): WidgetItems
	{
		$data = $this->dashboard->build($userId);
		$items = [];
		foreach (array_merge($data['missed'], $data['urgent'], $data['upcoming']) as $row) {
			if (count($items) >= $limit) {
				break;
			}
			$d = $row['deadline'];
			$missed = $d['state'] === 'missed';
			$subtitle = $missed
				? $this->l10n->t('Deadline missed — next: %s', [(string)($d['effectiveAt'] ?? '')])
				: $this->l10n->t('Cancel by %1$s (%2$d days)', [
					(string)$d['cancelBy'], (int)$d['daysLeft'],
				]);
			$items[] = new WidgetItem(
				$row['title'],
				$subtitle,
				$this->urlGenerator->linkToRouteAbsolute('contractcheck.page.contractShow', ['id' => $row['id']]),
				$this->getIconUrl(),
				(string)$d['cancelBy'] ?: '',
			);
		}

		return new WidgetItems(
			$items,
			$items === [] ? $this->l10n->t('No upcoming deadlines — all good.') : '',
			'',
		);
	}

	public function getWidgetButtons(string $userId): array
	{
		return [
			new WidgetButton(
				WidgetButton::TYPE_MORE,
				$this->urlGenerator->linkToRouteAbsolute('contractcheck.page.contracts'),
				$this->l10n->t('All contracts'),
			),
		];
	}

	public function getReloadInterval(): int
	{
		return 300;
	}
}
