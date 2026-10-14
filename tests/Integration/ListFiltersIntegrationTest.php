<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Integration;

use OCA\ContractCheck\Service\AccessService;
use OCA\ContractCheck\Service\ContractService;
use OCA\ContractCheck\Service\CostService;
use OCA\ContractCheck\Service\DeadlineService;
use OCA\ContractCheck\Service\PartyService;
use OCA\ContractCheck\Service\SettingsService;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Test\TestCase;

/**
 * Live-DB coverage for the reporting filters: type / partner / cost-center /
 * deadline-window — and proof that filters never widen the scope visibility.
 */
final class ListFiltersIntegrationTest extends TestCase
{
	private const MGR = 'cc_lf_mgr';
	private const OWNER = 'cc_lf_owner';
	private const OUT = 'cc_lf_out';
	private const MGRS = 'cc_lf_mgrs';
	private const PW = 'CcFilters2026!';

	private ContractService $contracts;
	private AccessService $access;
	private CostService $costs;
	private DeadlineService $deadlines;
	private PartyService $parties;
	private SettingsService $settings;
	private IDBConnection $db;
	private IUserManager $users;
	private IGroupManager $groups;
	private array $prevSettings;

	protected function setUp(): void
	{
		parent::setUp();
		if (!defined('CONTRACTCHECK_NC_BOOTSTRAPPED')) {
			$this->markTestSkipped('Nextcloud is not bootstrapped');
		}
		$s = \OC::$server;
		$this->contracts = $s->get(ContractService::class);
		$this->access = $s->get(AccessService::class);
		$this->costs = $s->get(CostService::class);
		$this->deadlines = $s->get(DeadlineService::class);
		$this->parties = $s->get(PartyService::class);
		$this->settings = $s->get(SettingsService::class);
		$this->db = $s->get(IDBConnection::class);
		$this->users = $s->get(IUserManager::class);
		$this->groups = $s->get(IGroupManager::class);

		foreach ([self::MGR, self::OWNER, self::OUT] as $uid) {
			if (!$this->users->userExists($uid)) {
				$this->users->createUser($uid, self::PW);
			}
		}
		if (!$this->groups->groupExists(self::MGRS)) {
			$this->groups->createGroup(self::MGRS);
		}
		if (!$this->groups->isInGroup(self::MGR, self::MGRS)) {
			$this->groups->get(self::MGRS)?->addUser($this->users->get(self::MGR));
		}

		$this->prevSettings = $this->settings->get();
		$this->settings->save(array_merge($this->prevSettings['settings'], [
			'managerGroups' => [self::MGRS],
			'allowedGroups' => [],
			'costViewerGroups' => [self::MGRS],
			'defaultLeadDays' => [60, 30],
		]), $this->prevSettings['version']);
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			parent::tearDown();
			return;
		}
		$this->db->executeStatement("DELETE FROM `*PREFIX*cc_contracts` WHERE created_by LIKE 'cc_lf%'");
		$this->db->executeStatement("DELETE FROM `*PREFIX*cc_parties` WHERE name LIKE 'cc-lf-%'");
		if (isset($this->prevSettings)) {
			// Settings are global state — a failed restore leaves e.g. a
			// deleted manager group stored and locks every user out. Retry
			// the CAS a few times, then force-write the row directly.
			for ($i = 0; $i < 5; $i++) {
				try {
					$cur = $this->settings->get();
					$this->settings->save($this->prevSettings['settings'], $cur['version']);
					break;
				} catch (\Throwable) {
				}
			}
			try {
				$cur = $this->settings->get();
				if ($cur['settings'] !== $this->prevSettings['settings']) {
					$this->db->executeStatement(
						'UPDATE `*PREFIX*cc_settings` SET `payload` = ?, `version` = `version` + 1 WHERE `id` = 1',
						[json_encode($this->prevSettings['settings'], JSON_UNESCAPED_UNICODE)]
					);
				}
			} catch (\Throwable) {
			}
		}
		foreach ([self::MGR, self::OWNER, self::OUT] as $uid) {
			$this->users->get($uid)?->delete();
		}
		$this->groups->get(self::MGRS)?->delete();
		parent::tearDown();
	}

	private function data(array $over = []): array
	{
		return array_merge([
			'title' => 'cc-lf contract',
			'type' => 'rent',
			'scope' => 'org',
			'ownerUid' => self::OWNER,
			'termKind' => 'fixed',
			'startDate' => '2026-01-01',
			'endDate' => '2027-12-31',
			'noticeValue' => 3,
			'noticeUnit' => 'months',
			'noticeAnchor' => 'contract_end',
			'leadDays' => [60, 30],
			'reminderChannel' => 'notification',
		], $over);
	}

	private function titles(array $list): array
	{
		return array_map(static fn ($c) => $c->getTitle(), $list);
	}

	public function testDbFilters(): void
	{
		$party = $this->parties->create(['name' => 'cc-lf Partner'], self::MGR);

		$this->contracts->create($this->data(['title' => 'cc-lf A rent IT', 'costCenter' => 'IT', 'partnerId' => $party->getId()]), self::MGR);
		$this->contracts->create($this->data(['title' => 'cc-lf B lease HR', 'type' => 'leasing', 'costCenter' => 'HR']), self::MGR);
		$this->contracts->create($this->data(['title' => 'cc-lf C rent archived', 'status' => 'draft']), self::MGR);

		$rent = $this->titles($this->contracts->listForUser(self::MGR, 'cc-lf', null, ['type' => 'rent'], 500, 0));
		self::assertContains('cc-lf A rent IT', $rent);
		self::assertNotContains('cc-lf B lease HR', $rent);

		$byPartner = $this->titles($this->contracts->listForUser(self::MGR, 'cc-lf', null, ['partnerId' => $party->getId()], 500, 0));
		self::assertSame(['cc-lf A rent IT'], $byPartner);

		$byCc = $this->titles($this->contracts->listForUser(self::MGR, 'cc-lf', null, ['costCenter' => 'HR'], 500, 0));
		self::assertSame(['cc-lf B lease HR'], $byCc);

		// Combined filters intersect.
		$both = $this->titles($this->contracts->listForUser(self::MGR, 'cc-lf', null, ['type' => 'rent', 'costCenter' => 'IT'], 500, 0));
		self::assertSame(['cc-lf A rent IT'], $both);

		$centers = $this->contracts->distinctCostCenters(self::MGR);
		self::assertContains('IT', $centers);
		self::assertContains('HR', $centers);
	}

	public function testFiltersNeverWidenScope(): void
	{
		// A restricted contract owned by OUT is invisible to MGR even when
		// its type/costCenter match the filter — scope is applied at SQL.
		$this->contracts->create($this->data([
			'title' => 'cc-lf hidden', 'scope' => 'restricted',
			'ownerUid' => self::OUT, 'costCenter' => 'SECRET-CC',
		]), self::MGR);
		$this->contracts->create($this->data(['title' => 'cc-lf open', 'costCenter' => 'IT']), self::MGR);

		// MGR is a manager — managers see restricted. The real check: OUT (a
		// plain user who is NOT the owner... OUT owns it, so use a 4th axis:
		// a plain viewer with no group overlap sees only org-scope rows.
		$visitor = 'cc_lf_visitor';
		if (!$this->users->userExists($visitor)) {
			$this->users->createUser($visitor, self::PW);
		}
		try {
			$visible = $this->titles($this->contracts->listForUser($visitor, 'cc-lf', null, ['type' => 'rent'], 500, 0));
			self::assertContains('cc-lf open', $visible);
			self::assertNotContains('cc-lf hidden', $visible);

			$centers = $this->contracts->distinctCostCenters($visitor);
			self::assertContains('IT', $centers);
			self::assertNotContains('SECRET-CC', $centers);
		} finally {
			$this->users->get($visitor)?->delete();
		}
	}

	public function testRollupGroupedLive(): void
	{
		$a = $this->contracts->create($this->data(['title' => 'cc-lf ra', 'type' => 'rent', 'costCenter' => 'IT']), self::MGR);
		$b = $this->contracts->create($this->data(['title' => 'cc-lf rb', 'type' => 'rent', 'costCenter' => 'IT']), self::MGR);
		$c = $this->contracts->create($this->data(['title' => 'cc-lf ins', 'type' => 'insurance', 'costCenter' => 'HR']), self::MGR);

		$this->costs->add($a->getId(), ['label' => 'rent', 'amountCents' => 10000, 'currency' => 'EUR', 'interval' => 'monthly'], self::MGR);
		$this->costs->add($b->getId(), ['label' => 'rent2', 'amountCents' => 20000, 'currency' => 'EUR', 'interval' => 'monthly'], self::MGR);
		$this->costs->add($c->getId(), ['label' => 'ins', 'amountCents' => 9900, 'currency' => 'EUR', 'interval' => 'yearly'], self::MGR);

		$all = $this->contracts->listForUser(self::MGR, 'cc-lf', null, [], 500, 0);
		$rows = array_column($this->costs->rollupGrouped($all, 'type', (int)date('Y')), null, 'key');

		self::assertSame(360000, $rows['rent']['annual_cents']['EUR']);
		self::assertSame(2, $rows['rent']['contracts']);
		self::assertSame(9900, $rows['insurance']['annual_cents']['EUR']);

		$byCc = array_column($this->costs->rollupGrouped($all, 'costCenter', (int)date('Y')), null, 'key');
		self::assertSame(360000, $byCc['IT']['annual_cents']['EUR']);
		self::assertSame(9900, $byCc['HR']['annual_cents']['EUR']);
	}

	public function testDeadlineWindowFilter(): void
	{
		// cancel30: fixed contract ending soon with 3m notice → cancel_by passed → missed
		$missed = $this->contracts->create($this->data([
			'title' => 'cc-lf missed', 'endDate' => date('Y-m-d', strtotime('+20 days')),
		]), self::MGR);
		// cancel90: long future end, anytime anchor → cancel_by = today ≤ 90d
		$open = $this->contracts->create($this->data([
			'title' => 'cc-lf open', 'termKind' => 'indefinite', 'endDate' => null,
			'noticeAnchor' => 'anytime', 'noticeValue' => 14, 'noticeUnit' => 'days',
		]), self::MGR);

		$rows = $this->deadlines->serializeMany(
			$this->contracts->listForUser(self::MGR, 'cc-lf', null, [], 500, 0),
			self::MGR
		);
		$byTitle = array_column($rows, null, 'title');

		self::assertTrue($this->deadlines->matchesDeadlineWindow($byTitle['cc-lf missed'], 'missed'));
		self::assertFalse($this->deadlines->matchesDeadlineWindow($byTitle['cc-lf missed'], 'cancel30'));
		self::assertTrue($this->deadlines->matchesDeadlineWindow($byTitle['cc-lf open'], 'cancel30'));
		self::assertFalse($this->deadlines->matchesDeadlineWindow($byTitle['cc-lf open'], 'missed'));
	}
}
