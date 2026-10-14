<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Integration;

use OCA\ContractCheck\Db\Contract;
use OCA\ContractCheck\Exception\ConflictException;
use OCA\ContractCheck\Exception\NotFoundException;
use OCA\ContractCheck\Service\AccessService;
use OCA\ContractCheck\Service\ContractService;
use OCA\ContractCheck\Service\DocumentService;
use OCA\ContractCheck\Service\ReminderService;
use OCA\ContractCheck\Service\RenewalService;
use OCA\ContractCheck\Service\SettingsService;
use OCA\ContractCheck\Exception\ValidationException;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\IGroupManager;
use OCP\IUserManager;
use Test\TestCase;

/**
 * Live-DB workflow tests: CAS, scope matrix, renewal idempotency, reminder
 * dedup, document missing-file detection — against real users/groups/tables.
 */
final class ContractServiceIntegrationTest extends TestCase
{
	private const MGR = 'cc_itest_mgr';
	private const OWNER = 'cc_itest_owner';
	private const PEER = 'cc_itest_peer';
	private const OUT = 'cc_itest_out';
	private const DEPT = 'cc_itest_dept';
	private const MGRS = 'cc_itest_mgrs';
	private const PW = 'CcIntegration2026!';

	private ContractService $contracts;
	private AccessService $access;
	private ReminderService $reminders;
	private RenewalService $renewals;
	private SettingsService $settings;
	private DocumentService $documents;
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
		$this->reminders = $s->get(ReminderService::class);
		$this->renewals = $s->get(RenewalService::class);
		$this->settings = $s->get(SettingsService::class);
		$this->documents = $s->get(DocumentService::class);
		$this->db = $s->get(IDBConnection::class);
		$this->users = $s->get(IUserManager::class);
		$this->groups = $s->get(IGroupManager::class);

		foreach ([self::MGR, self::OWNER, self::PEER, self::OUT] as $uid) {
			if (!$this->users->userExists($uid)) {
				$this->users->createUser($uid, self::PW);
			}
		}
		foreach ([self::DEPT, self::MGRS] as $gid) {
			if (!$this->groups->groupExists($gid)) {
				$this->groups->createGroup($gid);
			}
		}
		foreach ([self::OWNER, self::PEER] as $uid) {
			if (!$this->groups->isInGroup($uid, self::DEPT)) {
				$this->groups->get(self::DEPT)?->addUser($this->users->get($uid));
			}
		}
		if (!$this->groups->isInGroup(self::MGR, self::MGRS)) {
			$this->groups->get(self::MGRS)?->addUser($this->users->get(self::MGR));
		}

		$this->prevSettings = $this->settings->get();
		$this->settings->save(array_merge($this->prevSettings['settings'], [
			'managerGroups' => [self::MGRS],
			'allowedGroups' => [],
			'costViewerGroups' => [],
			'defaultLeadDays' => [60, 30],
		]), $this->prevSettings['version']);
	}

	protected function tearDown(): void
	{
		if (!isset(\OC::$server)) {
			parent::tearDown();
			return;
		}
		$this->db->executeStatement(
			"DELETE FROM `*PREFIX*cc_contracts` WHERE created_by LIKE 'cc_itest%'"
		);
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
		foreach ([self::MGR, self::OWNER, self::PEER, self::OUT] as $uid) {
			$this->users->get($uid)?->delete();
		}
		foreach ([self::DEPT, self::MGRS] as $gid) {
			$this->groups->get($gid)?->delete();
		}
		parent::tearDown();
	}

	private function baseData(array $over = []): array
	{
		return array_merge([
			'title' => 'cc-itest Rent',
			'type' => 'rent',
			'scope' => 'org',
			'ownerUid' => self::OWNER,
			'termKind' => 'fixed',
			'startDate' => '2027-01-01',
			'endDate' => '2027-12-31',
			'noticeValue' => 3,
			'noticeUnit' => 'months',
			'noticeAnchor' => 'contract_end',
			'leadDays' => [60, 30],
			'reminderChannel' => 'notification',
		], $over);
	}

	public function testCreateAndCasUpdateFlow(): void
	{
		$c = $this->contracts->create($this->baseData(), self::MGR);
		self::assertSame('draft', $c->getStatus());

		// Missing revision → 409, never merge.
		try {
			$this->contracts->update($c->getId(), ['title' => 'x'], null, self::MGR);
			self::fail('expected ConflictException');
		} catch (ConflictException) {
		}

		// Stale revision → 409.
		try {
			$this->contracts->update($c->getId(), array_merge($this->baseData(), ['title' => 'x']), 99, self::MGR);
			self::fail('expected ConflictException');
		} catch (ConflictException) {
		}

		$fresh = $this->contracts->getForUser($c->getId(), self::MGR);
		$updated = $this->contracts->update(
			$c->getId(), array_merge($this->baseData(), ['title' => 'cc-itest Updated']),
			$fresh->getRevision(), self::MGR
		);
		self::assertSame('cc-itest Updated', $updated->getTitle());
		self::assertSame($fresh->getRevision() + 1, $updated->getRevision());

		// Activate via transition CAS.
		$updated = $this->contracts->transition(
			$c->getId(), 'activate', [], $updated->getRevision(), self::MGR
		);
		self::assertSame('active', $updated->getStatus());
	}

	public function testScopeMatrix(): void
	{
		$org = $this->contracts->create($this->baseData(), self::MGR);
		$dept = $this->contracts->create($this->baseData(['scope' => 'dept']), self::MGR);
		$restr = $this->contracts->create($this->baseData(['scope' => 'restricted']), self::MGR);

		foreach ([self::OWNER, self::PEER, self::OUT] as $uid) {
			self::assertTrue($this->access->canSee($org, $uid), "org visible to {$uid}");
		}
		self::assertTrue($this->access->canSee($dept, self::PEER), 'dept visible to same-group peer');
		self::assertFalse($this->access->canSee($dept, self::OUT), 'dept hidden from outsider');
		self::assertFalse($this->access->canSee($restr, self::PEER), 'restricted hidden from peer');
		self::assertFalse($this->access->canSee($restr, self::OUT));
		self::assertTrue($this->access->canSee($restr, self::OWNER));
		self::assertTrue($this->access->canSee($restr, self::MGR), 'manager sees all');

		// SQL-level: outsider's list must not contain dept/restricted rows.
		$outIds = array_map(
			static fn (Contract $c) => $c->getId(),
			$this->contracts->listForUser(self::OUT, 'cc-itest', null, [], 50, 0)
		);
		self::assertContains($org->getId(), $outIds);
		self::assertNotContains($dept->getId(), $outIds);
		self::assertNotContains($restr->getId(), $outIds);

		// getForUser → uniform 404 for out-of-scope.
		try {
			$this->contracts->getForUser($restr->getId(), self::OUT);
			self::fail('expected NotFoundException');
		} catch (NotFoundException) {
		}
	}

	public function testRenewalRollIsIdempotent(): void
	{
		$c = $this->contracts->create($this->baseData([
			'termKind' => 'auto_renew',
			'startDate' => '2019-01-01',
			'endDate' => '2020-06-30', // long past — must roll forward
			'renewalValue' => 12,
			'renewalUnit' => 'months',
		]), self::MGR);
		$this->contracts->transition($c->getId(), 'activate', [], $c->getRevision(), self::MGR);

		$before = $this->contracts->getForUser($c->getId(), self::MGR);
		$this->renewals->run();
		$after1 = $this->contracts->getForUser($c->getId(), self::MGR);
		$end1 = $after1->getEndDateString();
		self::assertNotNull($end1);
		self::assertGreaterThanOrEqual(date('Y-m-d'), $end1, 'end must roll into the future');
		self::assertSame('active', $after1->getStatus());
		$rev1 = $after1->getRevision();

		// Second run: nothing left to roll → row untouched.
		$this->renewals->run();
		$after2 = $this->contracts->getForUser($c->getId(), self::MGR);
		self::assertSame($end1, $after2->getEndDateString(), 'second run must not roll again');
		self::assertSame($rev1, $after2->getRevision());
	}

	public function testFixedExpiryIsIdempotent(): void
	{
		$c = $this->contracts->create($this->baseData([
			'startDate' => '2019-01-01',
			'endDate' => '2020-01-01',
		]), self::MGR);
		$this->contracts->transition($c->getId(), 'activate', [], $c->getRevision(), self::MGR);

		$this->renewals->run();
		$after = $this->contracts->getForUser($c->getId(), self::MGR);
		self::assertSame('expired', $after->getStatus());

		$this->renewals->run(); // no crash, stays expired
		$again = $this->contracts->getForUser($c->getId(), self::MGR);
		self::assertSame('expired', $again->getStatus());
	}

	public function testReminderDedup(): void
	{
		// cancel_by = 30 days from "today" → inside 30d lead.
		$today = date('Y-m-d');
		$end = date('Y-m-d', strtotime($today . ' +30 days +3 months'));
		$c = $this->contracts->create($this->baseData(['endDate' => $end]), self::MGR);
		$this->contracts->transition($c->getId(), 'activate', [], $c->getRevision(), self::MGR);
		$active = $this->contracts->getForUser($c->getId(), self::MGR);

		$first = $this->reminders->dispatchForContract($active, $today);
		$second = $this->reminders->dispatchForContract($active, $today);
		self::assertGreaterThan(0, $first, 'deadline inside lead window must notify');
		self::assertSame(0, $second, 'same deadline instance must not re-notify');
	}

	public function testStaleReminderClaimIsReclaimedAndDelivered(): void
	{
		// SF-02: a PHP fatal between claim and delivery leaves a pending=1
		// row. Once it ages past CLAIM_STALE_SECONDS the next pass must
		// reclaim it and actually notify — never lose the reminder.
		$today = date('Y-m-d');
		$end = date('Y-m-d', strtotime($today . ' +30 days +3 months'));
		$c = $this->contracts->create($this->baseData(['endDate' => $end]), self::MGR);
		$this->contracts->transition($c->getId(), 'activate', [], $c->getRevision(), self::MGR);
		$active = $this->contracts->getForUser($c->getId(), self::MGR);

		// Compute the instance id the same way the service will, then plant
		// a stale pending claim as a crashed run would have left it.
		$engine = new \OCA\ContractCheck\Domain\DeadlineEngine();
		$instanceId = $engine->compute($this->contracts->ruleOf($active), $today)->instanceId($active->getId());
		$stale = (new \DateTimeImmutable())->sub(new \DateInterval('PT7200S'))->format('Y-m-d H:i:s');
		$this->db->executeStatement(
			"INSERT INTO `*PREFIX*cc_reminders` (contract_id, deadline_instance_id, lead_day, sent_at, pending)
			 VALUES (?, ?, ?, ?, ?)",
			[$active->getId(), $instanceId, 30, $stale, 1]
		);

		$dispatched = $this->reminders->dispatchForContract($active, $today);
		self::assertGreaterThan(0, $dispatched, 'stale pending claim must be reclaimed and delivered');

		$row = $this->db->executeQuery(
			"SELECT pending FROM `*PREFIX*cc_reminders`
			 WHERE contract_id = ? AND deadline_instance_id = ? AND lead_day = ?",
			[$active->getId(), $instanceId, 30]
		)->fetch();
		self::assertSame(0, (int)$row['pending'], 'delivered claim must be confirmed, not left pending');

		// A fresh pending claim (a live run holds it) must NOT be stolen.
		$fresh = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
		$this->db->executeStatement(
			"UPDATE `*PREFIX*cc_reminders` SET pending = 1, sent_at = ?
			 WHERE contract_id = ? AND deadline_instance_id = ? AND lead_day = ?",
			[$fresh, $active->getId(), $instanceId, 30]
		);
		self::assertSame(0, $this->reminders->dispatchForContract($active, $today),
			'fresh pending claim belongs to a live run — must dedup, not steal');
	}

	public function testDocumentLinkRejectsForeignAndDetectsMissing(): void
	{
		$c = $this->contracts->create($this->baseData(), self::MGR);
		$this->contracts->transition($c->getId(), 'activate', [], $c->getRevision(), self::MGR);

		// Nonexistent file id → uniform DOC_UNRESOLVED.
		try {
			$this->documents->link($c->getId(), 99999999, 'contract', self::MGR);
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertSame('DOC_UNRESOLVED', $e->getErrorCode());
		}

		// Real file in manager's storage → links; after deletion the rescan marks it.
		$folder = \OC::$server->get(IRootFolder::class)->getUserFolder(self::MGR);
		$file = $folder->newFile('cc-itest-doc.txt', 'test-content');
		$doc = $this->documents->link($c->getId(), $file->getId(), 'contract', self::MGR);
		self::assertNotNull($doc->getId());

		$file->delete();
		$missing = $this->documents->rescanMissing();
		self::assertContains($c->getId(), $missing, 'deleted linked file must flag the contract');
	}
}
