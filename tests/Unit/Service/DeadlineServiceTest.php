<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Service;

use OCA\ContractCheck\Db\Contract;
use OCA\ContractCheck\Db\Party;
use OCA\ContractCheck\Db\PartyMapper;
use OCA\ContractCheck\Domain\DeadlineEngine;
use OCA\ContractCheck\Service\AccessService;
use OCA\ContractCheck\Service\ContractService;
use OCA\ContractCheck\Service\CostService;
use OCA\ContractCheck\Service\Clock;
use OCA\ContractCheck\Service\DeadlineService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DeadlineServiceTest extends TestCase
{
	private PartyMapper&MockObject $parties;
	private AccessService&MockObject $access;
	private CostService&MockObject $costs;
	private DeadlineService $svc;

	protected function setUp(): void
	{
		$this->parties = $this->createMock(PartyMapper::class);
		$this->access = $this->createMock(AccessService::class);
		$this->costs = $this->createMock(CostService::class);
		$contracts = $this->createMock(ContractService::class);
		$contracts->method('ruleOf')->willReturnCallback(static fn (Contract $c) => [
			'term_kind' => $c->getTermKind(),
			'start_date' => $c->getStartDateString(),
			'end_date' => $c->getEndDateString(),
			'notice_value' => $c->getNoticeValue(),
			'notice_unit' => $c->getNoticeUnit(),
			'notice_anchor' => $c->getNoticeAnchor(),
			'renewal_value' => $c->getRenewalValue(),
			'renewal_unit' => $c->getRenewalUnit(),
		]);
		$clock = $this->createMock(Clock::class);
		$clock->method('today')->willReturn('2027-06-01');
		$clock->method('now')->willReturn(new \DateTimeImmutable('2027-06-01 12:00:00'));
		$this->svc = new DeadlineService(
			new DeadlineEngine(), $contracts, $this->parties, $this->costs, $this->access, $clock
		);
	}

	private function contract(): Contract
	{
		$c = new Contract();
		$c->setId(7);
		$c->setTitle('Lease');
		$c->setType('rent');
		$c->setScope('org');
		$c->setTermKind('fixed');
		$c->setStartDate('2026-01-01');
		$c->setEndDate('2027-06-30');
		$c->setNoticeValue(3);
		$c->setNoticeUnit('months');
		$c->setNoticeAnchor('contract_end');
		return $c;
	}

	public function testSerializeOmitsCostsForNonCostViewers(): void
	{
		// GA-4: the costs KEY must be absent — not null, absent — for
		// viewers without the cost role.
		$this->access->method('canViewCosts')->willReturn(false);
		$this->access->method('canEditContract')->willReturn(false);
		$row = $this->svc->serialize($this->contract(), 'viewer1');
		self::assertArrayNotHasKey('costs', $row);
		self::assertArrayHasKey('deadline', $row);
		self::assertFalse($row['canEdit']);
	}

	public function testSerializeIncludesCostsForCostViewers(): void
	{
		$this->access->method('canViewCosts')->willReturn(true);
		$this->costs->method('rollupForContract')
			->willReturn(['annual_cents' => ['EUR' => 120000], 'once_cents' => [], 'entry_count' => 1]);
		$row = $this->svc->serialize($this->contract(), 'boss');
		self::assertSame(120000, $row['costs']['annual_cents']['EUR']);
	}

	public function testSerializeSurvivesDeletedParty(): void
	{
		// A contract referencing a since-deleted party must render with a
		// null partnerName, not a 500.
		$this->access->method('canViewCosts')->willReturn(false);
		$this->parties->method('find')->willThrowException(new \RuntimeException('gone'));
		$c = $this->contract();
		$c->setPartnerId(9);
		$row = $this->svc->serialize($c, 'viewer1');
		self::assertNull($row['partnerName']);
	}

	public function testDeadlineWindowMatching(): void
	{
		$openSoon = ['deadline' => ['state' => 'open', 'daysLeft' => 20], 'endDate' => '2027-06-30'];
		$openFar = ['deadline' => ['state' => 'open', 'daysLeft' => 80], 'endDate' => '2027-08-30'];
		$missed = ['deadline' => ['state' => 'missed', 'daysLeft' => null]];
		$none = ['deadline' => ['state' => 'none', 'daysLeft' => null]];

		self::assertTrue($this->svc->matchesDeadlineWindow($openSoon, 'cancel30'));
		self::assertFalse($this->svc->matchesDeadlineWindow($openFar, 'cancel30'));
		self::assertTrue($this->svc->matchesDeadlineWindow($openFar, 'cancel90'));
		self::assertTrue($this->svc->matchesDeadlineWindow($missed, 'missed'));
		self::assertFalse($this->svc->matchesDeadlineWindow($missed, 'cancel30'));
		self::assertTrue($this->svc->matchesDeadlineWindow($none, 'none'));
		self::assertTrue($this->svc->matchesDeadlineWindow($openSoon, 'expiring'));
		self::assertFalse($this->svc->matchesDeadlineWindow(['deadline' => null], 'cancel30'));
		self::assertFalse($this->svc->matchesDeadlineWindow($openSoon, 'bogus'));
	}

	public function testSerializeManyKeepsCostAbsencePerRow(): void
	{
		$this->access->method('canViewCosts')->willReturn(false);
		$this->parties->method('find')->willThrowException(new \RuntimeException('gone'));
		$rows = $this->svc->serializeMany([$this->contract()], 'viewer1');
		self::assertCount(1, $rows);
		self::assertArrayNotHasKey('costs', $rows[0]);
		self::assertArrayHasKey('deadlineInstanceId', $rows[0]);
	}
}
