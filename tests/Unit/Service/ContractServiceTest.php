<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Service;

use OCA\ContractCheck\Db\Contract;
use OCA\ContractCheck\Db\ContractMapper;
use OCA\ContractCheck\Db\ContractRevisionMapper;
use OCA\ContractCheck\Db\PartyMapper;
use OCA\ContractCheck\Domain\DeadlineEngine;
use OCA\ContractCheck\Exception\ConflictException;
use OCA\ContractCheck\Exception\NotFoundException;
use OCA\ContractCheck\Exception\StateException;
use OCA\ContractCheck\Service\AccessService;
use OCA\ContractCheck\Service\AuditService;
use OCA\ContractCheck\Service\Clock;
use OCA\ContractCheck\Service\ContractService;
use OCA\ContractCheck\Service\SettingsService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ContractServiceTest extends TestCase
{
	private ContractMapper&MockObject $contracts;
	private PartyMapper&MockObject $parties;
	private AccessService&MockObject $access;
	private AuditService&MockObject $audit;
	private SettingsService&MockObject $settings;
	private IDBConnection&MockObject $db;
	private ContractService $svc;

	protected function setUp(): void
	{
		$this->contracts = $this->createMock(ContractMapper::class);
		$this->parties = $this->createMock(PartyMapper::class);
		$this->access = $this->createMock(AccessService::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->settings = $this->createMock(SettingsService::class);
		$this->db = $this->createMock(IDBConnection::class);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(new \DateTimeImmutable('2027-06-01 12:00:00'));
		$clock->method('today')->willReturn('2027-06-01');
		$this->settings->method('getDefaultLeadDays')->willReturn([60, 30]);
		$this->svc = new ContractService(
			$this->contracts, $this->parties, $this->createMock(ContractRevisionMapper::class),
			$this->access, $this->audit, $this->settings, $clock, $this->db
		);
	}

	private function contract(int $rev = 3, string $status = 'active'): Contract
	{
		$c = new Contract();
		$c->setId(42);
		$c->setRevision($rev);
		$c->setStatus($status);
		$c->setScope('org');
		$c->setTermKind('fixed');
		$c->setTitle('Rent');
		$c->setType('rent');
		return $c;
	}

	public function testMissingRevisionIsConflictNeverMerge(): void
	{
		$this->contracts->expects($this->never())->method('find');
		$this->expectException(ConflictException::class);
		$this->svc->update(42, ['title' => 'x'], null, 'alice');
	}

	public function testNonDigitRevisionIsConflict(): void
	{
		$this->expectException(ConflictException::class);
		$this->svc->update(42, ['title' => 'x'], 'abc', 'alice');
	}

	public function testStaleRevision409s(): void
	{
		$this->contracts->method('find')->willReturn($this->contract(rev: 5));
		$this->access->method('canEditContract')->willReturn(true);
		$this->expectException(ConflictException::class);
		$this->svc->update(42, ['title' => 'x'], 3, 'alice');
	}

	public function testOutOfScopeUpdateIsUniform404(): void
	{
		$this->contracts->method('find')->willReturn($this->contract());
		$this->access->method('requireVisible')->willThrowException(new NotFoundException('Contract not found'));
		$this->expectException(NotFoundException::class);
		$this->svc->update(42, ['title' => 'x'], 3, 'alice');
	}

	public function testCasRaceLosesGuardedUpdate(): void
	{
		$this->contracts->method('find')->willReturn($this->contract(rev: 3));
		$this->access->method('canEditContract')->willReturn(true);
		$this->contracts->expects($this->once())
			->method('updateWithRevision')
			->willReturn(0); // lost the CAS race — row moved under us
		$this->db->expects($this->once())->method('rollBack');
		$this->expectException(ConflictException::class);
		$this->svc->update(42, ['title' => 'New', 'termKind' => 'fixed', 'endDate' => '2028-01-01',
			'noticeValue' => 3, 'noticeUnit' => 'months', 'noticeAnchor' => 'contract_end',
			'type' => 'rent', 'scope' => 'org'], 3, 'alice');
	}

	public function testTransitionRejectsUnknownAction(): void
	{
		$this->contracts->method('find')->willReturn($this->contract());
		$this->access->method('canEditContract')->willReturn(true);
		$this->expectException(\OCA\ContractCheck\Exception\ValidationException::class);
		$this->svc->transition(42, 'detonate', [], 3, 'alice');
	}

	public function testTransitionTableEnforced(): void
	{
		$this->contracts->method('find')->willReturn($this->contract(status: 'draft'));
		$this->access->method('canEditContract')->willReturn(true);
		// 'terminate' only from 'active' — draft must fail.
		$this->expectException(StateException::class);
		$this->svc->transition(42, 'terminate', [], 3, 'alice');
	}

	public function testArchivedIsTerminalForWrites(): void
	{
		$this->contracts->method('find')->willReturn($this->contract(status: 'archived'));
		$this->access->method('canEditContract')->willReturn(true);
		$this->expectException(StateException::class);
		$this->svc->transition(42, 'terminate', [], 3, 'alice');
	}

	public function testReopenOnlyFromArchived(): void
	{
		$this->contracts->method('find')->willReturn($this->contract(status: 'active'));
		$this->access->method('canEditContract')->willReturn(true);
		$this->expectException(StateException::class);
		$this->svc->transition(42, 'reopen', [], 3, 'alice');
	}

	public function testActivateHappyPath(): void
	{
		$this->contracts->method('find')->willReturn($this->contract(status: 'draft'));
		$this->access->method('canEditContract')->willReturn(true);
		$this->contracts->method('updateWithRevision')->willReturn(1);
		$this->audit->expects($this->once())->method('record')
			->with(42, 'activated', 'alice');
		$out = $this->svc->transition(42, 'activate', [], 3, 'alice');
		self::assertSame('active', $out->getStatus());
		self::assertSame(4, $out->getRevision());
	}

	public function testCreateRejectsArchivedParty(): void
	{
		// An archived party must never be newly assigned — pickers hide them,
		// so an archived id arriving here is stale or crafted input.
		$this->access->method('canEdit')->willReturn(true);
		$party = new \OCA\ContractCheck\Db\Party();
		$party->setId(7);
		$party->setArchived(1);
		$this->parties->method('exists')->willReturn(true);
		$this->parties->method('find')->willReturn($party);
		$this->contracts->expects($this->never())->method('insert');
		try {
			$this->svc->create(['title' => 'x', 'partnerId' => 7, 'type' => 'rent', 'scope' => 'org',
				'termKind' => 'fixed', 'noticeValue' => 3, 'noticeUnit' => 'months',
				'noticeAnchor' => 'contract_end', 'startDate' => '2027-01-01', 'endDate' => '2028-12-31'], 'alice');
			self::fail('expected ValidationException');
		} catch (\OCA\ContractCheck\Exception\ValidationException $e) {
			self::assertArrayHasKey('partnerId', $e->getFields());
		}
	}

	public function testUpdateRejectsSwitchToArchivedPartyButKeepsExisting(): void
	{
		$this->access->method('canEditContract')->willReturn(true);
		$archived = new \OCA\ContractCheck\Db\Party();
		$archived->setId(7);
		$archived->setArchived(1);
		$this->parties->method('exists')->willReturn(true);
		$this->parties->method('find')->willReturn($archived);

		// Switching to the archived party → reject.
		$existing = $this->contract();
		$existing->setPartnerId(3);
		$this->contracts->method('find')->willReturn($existing);
		try {
			$this->svc->update(42, ['title' => 'x', 'partnerId' => 7, 'type' => 'rent', 'scope' => 'org',
				'termKind' => 'fixed', 'noticeValue' => 3, 'noticeUnit' => 'months',
				'noticeAnchor' => 'contract_end', 'startDate' => '2027-01-01', 'endDate' => '2028-12-31'], 3, 'alice');
			self::fail('expected ValidationException for switch to archived party');
		} catch (\OCA\ContractCheck\Exception\ValidationException $e) {
			self::assertArrayHasKey('partnerId', $e->getFields());
		}

		// Same archived party already assigned → update of other fields allowed.
		$existing2 = $this->contract();
		$existing2->setPartnerId(7);
		$contracts2 = $this->createMock(ContractMapper::class);
		$contracts2->method('find')->willReturn($existing2);
		$contracts2->method('updateWithRevision')->willReturn(1);
		$svc2 = new ContractService(
			$contracts2, $this->parties, $this->createMock(ContractRevisionMapper::class),
			$this->access, $this->audit, $this->settings,
			$this->createMock(Clock::class), $this->db
		);
		$out = $svc2->update(42, ['title' => 'renamed', 'partnerId' => 7, 'type' => 'rent', 'scope' => 'org',
				'termKind' => 'fixed', 'noticeValue' => 3, 'noticeUnit' => 'months',
				'noticeAnchor' => 'contract_end', 'startDate' => '2027-01-01', 'endDate' => '2028-12-31'], 3, 'alice');
		self::assertSame('renamed', $out->getTitle());
	}

	public function testCreateRequiresManager(): void
	{
		$this->access->method('canEdit')->willReturn(false);
		$this->expectException(\OCA\ContractCheck\Exception\ForbiddenException::class);
		$this->svc->create(['title' => 'x'], 'alice');
	}
}
