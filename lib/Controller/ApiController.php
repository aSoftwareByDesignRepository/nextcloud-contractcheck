<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Controller;

use OCA\ContractCheck\Db\ContractEventMapper;
use OCA\ContractCheck\Db\ContractRevisionMapper;
use OCA\ContractCheck\Exception\ForbiddenException;
use OCA\ContractCheck\Exception\NotFoundException;
use OCA\ContractCheck\Service\AccessService;
use OCA\ContractCheck\Service\Clock;
use OCA\ContractCheck\Service\ContractService;
use OCA\ContractCheck\Service\ContractValidator;
use OCA\ContractCheck\Service\CostService;
use OCA\ContractCheck\Service\CsvImportService;
use OCA\ContractCheck\Service\DashboardService;
use OCA\ContractCheck\Service\DeadlineService;
use OCA\ContractCheck\Service\DirectoryService;
use OCA\ContractCheck\Service\DocumentService;
use OCA\ContractCheck\Service\ExportService;
use OCA\ContractCheck\Service\FilesSearchService;
use OCA\ContractCheck\Service\PartyService;
use OCA\ContractCheck\Service\SettingsService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/**
 * Web JSON API — session-authenticated, CSRF-protected (Nextcloud default;
 * mutating routes deliberately keep CSRF on).
 */
class ApiController extends Controller
{
	use ApiJsonTrait;

	public function __construct(
		string $appName,
		IRequest $request,
		private readonly IUserSession $userSession,
		private readonly AccessService $access,
		private readonly ContractService $contracts,
		private readonly DeadlineService $deadlines,
		private readonly PartyService $parties,
		private readonly CostService $costs,
		private readonly DocumentService $documents,
		private readonly DashboardService $dashboard,
		private readonly DirectoryService $directory,
		private readonly FilesSearchService $files,
		private readonly SettingsService $settings,
		private readonly ExportService $export,
		private readonly CsvImportService $import,
		private readonly ContractEventMapper $events,
		private readonly ContractRevisionMapper $revisions,
		private readonly IURLGenerator $urlGenerator,
		private readonly Clock $clock,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	private function uid(): string
	{
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new ForbiddenException('Login required');
		}

		return $user->getUID();
	}

	// ---- dashboard ----

	#[NoAdminRequired]
	public function dashboard(): JSONResponse
	{
		try {
			return $this->ok($this->dashboard->build($this->uid()));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ---- contracts ----

	#[NoAdminRequired]
	public function listContracts(?string $search = null, ?string $status = null, int $limit = 100, int $offset = 0): JSONResponse
	{
		try {
			$uid = $this->uid();
			$filters = ContractValidator::filters($this->request->getParams());
			if ($filters['deadline'] !== null) {
				// Deadline windows are computed fields — filter after
				// serialization, before slicing. Cap matches the mapper's;
				// org scale is tens-to-hundreds of contracts.
				$rows = $this->deadlines->serializeMany(
					$this->contracts->listForUser($uid, $search, $filters['status'], $filters, 500, 0),
					$uid
				);
				$window = $filters['deadline'];
				$rows = array_values(array_filter(
					$rows,
					fn (array $r) => $this->deadlines->matchesDeadlineWindow($r, $window)
				));
				$rows = array_slice($rows, max(0, $offset), max(1, min($limit, 500)));

				return $this->ok($rows);
			}
			$list = $this->contracts->listForUser($uid, $search, $filters['status'], $filters, $limit, $offset);

			return $this->ok($this->deadlines->serializeMany($list, $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Filter metadata for the contracts list — cost centers are data-derived
	 * and scope-filtered, so a viewer never learns foreign cost centers.
	 */
	#[NoAdminRequired]
	public function contractFilters(): JSONResponse
	{
		try {
			return $this->ok([
				'types' => ContractValidator::TYPES,
				'costCenters' => $this->contracts->distinctCostCenters($this->uid()),
				'deadlineWindows' => ContractValidator::DEADLINE_FILTERS,
			]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	/**
	 * Grouped cost rollup for the reports page (spec US-206 + NH-05).
	 * Cost-viewer gated; planned budgets merged into the cost-center view.
	 */
	#[NoAdminRequired]
	public function rollupReport(?string $groupBy = 'contract', ?int $year = null): JSONResponse
	{
		try {
			$uid = $this->uid();
			if (!$this->access->canViewCosts($uid)) {
				return $this->notFound();
			}
			$groupBy = $groupBy ?? 'contract';
			if (!in_array($groupBy, ['contract', 'type', 'costCenter'], true)) {
				return $this->fail('GROUPBY_INVALID', 'Unknown grouping', ['groupBy' => 'unknown grouping']);
			}
			$contracts = $this->contracts->listForUser($uid, null, null, [], 500, 0);
			$rows = $this->costs->rollupGrouped($contracts, $groupBy, $year ?? (int)$this->clock->now()->format('Y'));
			if ($groupBy === 'costCenter') {
				$budgets = $this->settings->getCostCenterBudgets();
				foreach ($rows as &$r) {
					$r['planned_cents'] = $budgets[$r['key']] ?? null;
				}
				unset($r);
				// Budgeted cost centers without any contract still surface.
				$known = array_column($rows, 'key');
				foreach ($budgets as $name => $cents) {
					if (!in_array($name, $known, true)) {
						$rows[] = [
							'key' => $name,
							'contracts' => 0,
							'annual_cents' => [],
							'once_cents' => [],
							'planned_cents' => $cents,
						];
					}
				}
			}

			return $this->ok(['groupBy' => $groupBy, 'year' => $year ?? (int)$this->clock->now()->format('Y'), 'rows' => $rows]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function getContract(int $id): JSONResponse
	{
		try {
			$uid = $this->uid();
			$contract = $this->contracts->getForUser($id, $uid);
			$row = $this->deadlines->serialize($contract, $uid);
			if ($this->access->canViewCosts($uid)) {
				$row['costEntries'] = array_map(
					static fn ($c) => $c->toArray(),
					$this->costs->listForContract($id)
				);
			}
			$row['documents'] = $this->documents->listForContract($id, $uid);

			return $this->ok($row);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createContract(): JSONResponse
	{
		try {
			$contract = $this->contracts->create($this->request->getParams(), $this->uid());

			return $this->ok($contract->toArray(), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateContract(int $id): JSONResponse
	{
		try {
			$params = $this->request->getParams();
			$contract = $this->contracts->update($id, $params, $params['revision'] ?? null, $this->uid());

			return $this->ok($contract->toArray());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function transitionContract(int $id): JSONResponse
	{
		try {
			$params = $this->request->getParams();
			$action = is_string($params['action'] ?? null) ? $params['action'] : '';
			$contract = $this->contracts->transition(
				$id, $action, $params, $params['revision'] ?? null, $this->uid()
			);

			return $this->ok($contract->toArray());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ---- costs ----

	#[NoAdminRequired]
	public function listCosts(int $id): JSONResponse
	{
		try {
			$uid = $this->uid();
			$this->contracts->getForUser($id, $uid);
			if (!$this->access->canViewCosts($uid)) {
				return $this->notFound();
			}

			return $this->ok(array_map(static fn ($c) => $c->toArray(), $this->costs->listForContract($id)));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function addCost(int $id): JSONResponse
	{
		try {
			$uid = $this->uid();
			$contract = $this->contracts->getForUser($id, $uid);
			if (!$this->access->canEditContract($contract, $uid)) {
				return $this->notFound();
			}
			$cost = $this->costs->add($id, $this->request->getParams(), $uid);

			return $this->ok($cost->toArray(), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateCost(int $id, int $costId): JSONResponse
	{
		try {
			$uid = $this->uid();
			$contract = $this->contracts->getForUser($id, $uid);
			if (!$this->access->canEditContract($contract, $uid)) {
				return $this->notFound();
			}
			$cost = $this->costs->update($id, $costId, $this->request->getParams(), $uid);

			return $this->ok($cost->toArray());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function deleteCost(int $id, int $costId): JSONResponse
	{
		try {
			$uid = $this->uid();
			$contract = $this->contracts->getForUser($id, $uid);
			if (!$this->access->canEditContract($contract, $uid)) {
				return $this->notFound();
			}
			$this->costs->delete($id, $costId, $uid);

			return $this->ok(['deleted' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ---- documents ----

	#[NoAdminRequired]
	public function listDocuments(int $id): JSONResponse
	{
		try {
			$uid = $this->uid();
			$this->contracts->getForUser($id, $uid);

			return $this->ok($this->documents->listForContract($id, $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function linkDocument(int $id): JSONResponse
	{
		try {
			$uid = $this->uid();
			$contract = $this->contracts->getForUser($id, $uid);
			if (!$this->access->canEditContract($contract, $uid)) {
				return $this->notFound();
			}
			$params = $this->request->getParams();
			$doc = $this->documents->link(
				$id, $params['fileId'] ?? null,
				is_string($params['kind'] ?? null) ? $params['kind'] : 'contract',
				$uid
			);

			return $this->ok($doc->toArray(), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function unlinkDocument(int $id, int $docId): JSONResponse
	{
		try {
			$uid = $this->uid();
			$contract = $this->contracts->getForUser($id, $uid);
			if (!$this->access->canEditContract($contract, $uid)) {
				return $this->notFound();
			}
			$this->documents->unlink($id, $docId, $uid);

			return $this->ok(['deleted' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ---- history ----

	#[NoAdminRequired]
	public function listEvents(int $id, int $limit = 100, int $offset = 0): JSONResponse
	{
		try {
			$uid = $this->uid();
			$this->contracts->getForUser($id, $uid);

			return $this->ok(array_map(
				static fn ($e) => $e->toArray(),
				$this->events->findByContract($id, $limit, $offset)
			));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function listRevisions(int $id): JSONResponse
	{
		try {
			$uid = $this->uid();
			$this->contracts->getForUser($id, $uid);

			return $this->ok(array_map(
				static fn ($r) => [
					'id' => $r->getId(),
					'revision' => $r->getRevision(),
					'actorUid' => $r->getActorUid(),
					'createdAt' => $r->toArray()['createdAt'],
				],
				$this->revisions->findByContract($id)
			));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ---- parties ----

	#[NoAdminRequired]
	public function listParties(?string $search = null): JSONResponse
	{
		try {
			$uid = $this->uid();
			$includeArchived = $this->paramBool($this->request->getParam('includeArchived'));

			return $this->ok(array_map(
				static fn ($p) => $p->toArray(),
				$this->parties->listForUser($uid, $search, $includeArchived)
			));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function createParty(): JSONResponse
	{
		try {
			$party = $this->parties->create($this->request->getParams(), $this->uid());

			return $this->ok($party->toArray(), Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function updateParty(int $id): JSONResponse
	{
		try {
			$party = $this->parties->update($id, $this->request->getParams(), $this->uid());

			return $this->ok($party->toArray());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function archiveParty(int $id): JSONResponse
	{
		try {
			$party = $this->parties->archive($id, $this->uid());

			return $this->ok($party->toArray());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function unarchiveParty(int $id): JSONResponse
	{
		try {
			$party = $this->parties->unarchive($id, $this->uid());

			return $this->ok($party->toArray());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ---- directory pickers ----

	#[NoAdminRequired]
	public function searchUsers(string $q = ''): JSONResponse
	{
		try {
			if (!$this->access->isManager($this->uid())) {
				throw new ForbiddenException('Manager role required');
			}
			return $this->ok($this->directory->searchUsers($q));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function searchGroups(string $q = ''): JSONResponse
	{
		try {
			if (!$this->access->isManager($this->uid())) {
				throw new ForbiddenException('Manager role required');
			}
			return $this->ok($this->directory->searchGroups($q));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function searchFiles(string $q = ''): JSONResponse
	{
		try {
			if (!$this->access->isManager($this->uid())) {
				throw new ForbiddenException('Manager role required');
			}
			return $this->ok($this->files->search($this->uid(), $q));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ---- settings + feed token ----

	#[NoAdminRequired]
	public function getSettings(): JSONResponse
	{
		try {
			$uid = $this->uid();
			$isManager = $this->access->isManager($uid);
			$current = $this->settings->get();
			$data = [
				'version' => $current['version'],
				'role' => [
					'manager' => $isManager,
					'costViewer' => $this->access->canViewCosts($uid),
				],
				'settings' => $isManager ? $current['settings'] : [
					'defaultLeadDays' => $this->settings->getDefaultLeadDays(),
					'orgName' => $this->settings->getOrgName(),
				],
				'feed' => [
					'hasToken' => $this->settings->hasFeedToken($uid),
					'url' => $this->urlGeneratorFeed(),
				],
			];

			return $this->ok($data);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function saveSettings(): JSONResponse
	{
		try {
			$uid = $this->uid();
			if (!$this->access->isManager($uid)) {
				throw new ForbiddenException('Manager role required');
			}
			$params = $this->request->getParams();
			$version = $this->paramId($params['version'] ?? null);
			if ($version === null) {
				return $this->fail('CONFLICT', 'Missing settings version — reload and retry.', [], Http::STATUS_CONFLICT);
			}
			$this->settings->save(is_array($params['settings'] ?? null) ? $params['settings'] : [], $version);

			return $this->ok($this->settings->get());
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function mintFeedToken(): JSONResponse
	{
		try {
			$uid = $this->uid();
			$token = $this->settings->mintFeedToken($uid);

			return $this->ok([
				'token' => $token,
				'url' => $this->urlGeneratorFeed() . '?token=' . urlencode($token),
			], Http::STATUS_CREATED);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	#[NoAdminRequired]
	public function revokeFeedToken(): JSONResponse
	{
		try {
			$this->settings->revokeFeedToken($this->uid());

			return $this->ok(['revoked' => true]);
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ---- export / import ----

	#[NoAdminRequired]
	public function exportCsv(): Response
	{
		try {
			$csv = $this->export->buildCsv($this->uid());

			return new DataDownloadResponse($csv, 'contractcheck-contracts.csv', 'text/csv; charset=utf-8');
		} catch (\Throwable $e) {
			$this->logger->error('ApiController::exportCsv failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => 'SERVER_ERROR', 'message' => 'Export failed', 'fields' => new \stdClass()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function exportPdf(): Response
	{
		try {
			$pdf = $this->export->buildPdf($this->uid());

			return new DataDownloadResponse($pdf, 'contractcheck-contracts.pdf', 'application/pdf');
		} catch (\Throwable $e) {
			$this->logger->error('ApiController::exportPdf failed: ' . $e->getMessage(), ['exception' => $e]);
			return new JSONResponse(['success' => false, 'error' => 'SERVER_ERROR', 'message' => 'Export failed', 'fields' => new \stdClass()], Http::STATUS_INTERNAL_SERVER_ERROR);
		}
	}

	#[NoAdminRequired]
	public function importCsv(): JSONResponse
	{
		try {
			$uid = $this->uid();
			if (!$this->access->canEdit($uid)) {
				throw new ForbiddenException('Manager role required');
			}
			$upload = $this->request->getUploadedFile('file');
			$content = null;
			if (is_array($upload) && isset($upload['tmp_name']) && is_string($upload['tmp_name'])
				&& is_uploaded_file($upload['tmp_name'])) {
				$content = file_get_contents($upload['tmp_name']);
			}
			if (!is_string($content) || $content === '') {
				$raw = $this->request->getParam('csv');
				$content = is_string($raw) ? $raw : null;
			}
			if ($content === null || $content === '') {
				return $this->fail('IMPORT_INVALID', 'No CSV content provided', ['file' => 'required']);
			}
			if (strlen($content) > 4 * 1024 * 1024) {
				return $this->fail('IMPORT_INVALID', 'File too large (max 4 MB)', ['file' => 'too large']);
			}

			return $this->ok($this->import->import($content, $uid));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	// ---- audit ----

	#[NoAdminRequired]
	public function auditLog(?string $event = null, ?string $from = null, ?string $to = null, int $limit = 200): JSONResponse
	{
		try {
			$uid = $this->uid();
			$visible = $this->contracts->listForUser($uid, null, null, [], 500, 0);
			$ids = array_map(static fn ($c) => $c->getId(), $visible);

			return $this->ok(array_map(
				static fn ($e) => $e->toArray(),
				$this->events->findForContracts($ids, $event, $from, $to, $limit)
			));
		} catch (\Throwable $e) {
			return $this->fromThrowable($e);
		}
	}

	private function urlGeneratorFeed(): string
	{
		return $this->urlGenerator->linkToRouteAbsolute('contractcheck.feed.ics');
	}
}
