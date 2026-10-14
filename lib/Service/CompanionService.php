<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCP\IURLGenerator;

/**
 * Companion (mobile) API backend — capability bootstrap plus the
 * read-oriented payload the Android app needs. Writes stay on the web for
 * v1; the capability map advertises exactly what exists so the client
 * degrades honestly (design-system companion-discovery rule).
 */
class CompanionService
{
	public const MIN_APP_VERSION = '1.0.0';

	public function __construct(
		private readonly AccessService $access,
		private readonly ContractService $contracts,
		private readonly DeadlineService $deadlines,
		private readonly PartyService $parties,
		private readonly CostService $costs,
		private readonly DocumentService $documents,
		private readonly DashboardService $dashboard,
		private readonly SettingsService $settings,
		private readonly IURLGenerator $urlGenerator,
	) {
	}

	/** @return array<string, mixed> */
	public function bootstrap(string $uid): array
	{
		return [
			'app' => 'contractcheck',
			'apiVersion' => 1,
			'minAppVersion' => self::MIN_APP_VERSION,
			'role' => [
				'manager' => $this->access->isManager($uid),
				'costViewer' => $this->access->canViewCosts($uid),
			],
			'capabilities' => [
				'contracts.read' => true,
				'contracts.write' => $this->access->canEdit($uid),
				'parties.read' => true,
				'costs.read' => $this->access->canViewCosts($uid),
				'deadlines.read' => true,
				'notifications' => true,
			],
			'orgName' => $this->settings->getOrgName(),
			'urls' => [
				'web' => $this->urlGenerator->linkToRouteAbsolute('contractcheck.page.index'),
			],
		];
	}

	/** @return array<string, mixed> */
	public function dashboard(string $uid): array
	{
		$data = $this->dashboard->build($uid);

		return [
			'counts' => $data['counts'],
			'urgent' => array_map([$this, 'slim'], $data['urgent']),
			'missed' => array_map([$this, 'slim'], $data['missed']),
			'missingDocs' => array_map([$this, 'slim'], $data['missingDocs']),
		];
	}

	/** @return list<array<string, mixed>> */
	/**
	 * @param array{type?:?string, partnerId?:?int, costCenter?:?string, deadline?:?string} $filters
	 * @return list<array<string, mixed>>
	 */
	public function listContracts(string $uid, ?string $search, ?string $status, array $filters = []): array
	{
		$filters += ['type' => null, 'partnerId' => null, 'costCenter' => null, 'deadline' => null];
		// Deadline windows are computed — fetch the (scope-filtered) rows and
		// filter after serialization, mirroring ApiController::listContracts.
		$fetch = $filters['deadline'] !== null ? 500 : 200;
		$list = $this->contracts->listForUser($uid, $search, $status, $filters, $fetch, 0);
		$rows = $this->deadlines->serializeMany($list, $uid);
		if ($filters['deadline'] !== null) {
			$window = $filters['deadline'];
			$rows = array_values(array_filter(
				$rows,
				fn (array $r) => $this->deadlines->matchesDeadlineWindow($r, $window)
			));
			$rows = array_slice($rows, 0, 200);
		}

		return array_map([$this, 'slim'], $rows);
	}

	/** @return array<string, mixed>|null */
	public function getContract(string $uid, int $id): ?array
	{
		try {
			$contract = $this->contracts->getForUser($id, $uid);
		} catch (\Throwable) {
			return null;
		}
		$row = $this->deadlines->serialize($contract, $uid);
		if ($this->access->canViewCosts($uid)) {
			$row['costEntries'] = array_map(
				static fn ($c) => $c->toArray(),
				$this->costs->listForContract($id)
			);
		}
		$row['documents'] = array_map(
			static fn (array $d) => [
				'id' => $d['id'],
				'kind' => $d['kind'],
				'name' => $d['name'],
				'state' => $d['state'],
			],
			$this->documents->listForContract($id, $uid)
		);

		return $row;
	}

	/** @return list<array<string, mixed>> */
	public function listParties(string $uid): array
	{
		return array_map(
			static fn ($p) => ['id' => $p->getId(), 'name' => $p->getName()],
			$this->parties->listForUser($uid, null, false)
		);
	}

	/**
	 * Slim row for list payloads — ids, dates, deadline only.
	 * @param array<string, mixed> $row
	 * @return array<string, mixed>
	 */
	private function slim(array $row): array
	{
		return [
			'id' => $row['id'],
			'title' => $row['title'],
			'type' => $row['type'],
			'status' => $row['status'],
			'scope' => $row['scope'],
			'endDate' => $row['endDate'],
			'deadline' => $row['deadline'],
		];
	}
}
