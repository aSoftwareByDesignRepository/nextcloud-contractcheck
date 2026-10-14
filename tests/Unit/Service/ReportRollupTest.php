<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Service;

use OCA\ContractCheck\Db\Contract;
use OCA\ContractCheck\Db\Cost;
use OCA\ContractCheck\Db\CostMapper;
use OCA\ContractCheck\Db\PartyMapper;
use OCA\ContractCheck\Domain\DeadlineEngine;
use OCA\ContractCheck\Exception\ValidationException;
use OCA\ContractCheck\Service\AccessService;
use OCA\ContractCheck\Service\AuditService;
use OCA\ContractCheck\Service\Clock;
use OCA\ContractCheck\Service\ContractService;
use OCA\ContractCheck\Service\CostService;
use OCA\ContractCheck\Service\DeadlineService;
use OCA\ContractCheck\Service\SettingsService;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ReportRollupTest extends TestCase
{
	private CostMapper&MockObject $costMapper;
	private CostService $costs;
	private DeadlineService $deadlines;

	protected function setUp(): void
	{
		$this->costMapper = $this->createMock(CostMapper::class);
		$this->costs = new CostService($this->costMapper, $this->createMock(AuditService::class));

		$clock = $this->createMock(Clock::class);
		$clock->method('today')->willReturn('2026-10-12');
		$clock->method('now')->willReturn(new \DateTimeImmutable('2026-10-12 12:00:00'));
		$this->deadlines = new DeadlineService(
			new DeadlineEngine(),
			$this->createMock(ContractService::class),
			$this->createMock(PartyMapper::class),
			$this->costs,
			$this->createMock(AccessService::class),
			$clock,
		);
	}

	private function contract(int $id, string $type, ?string $costCenter): Contract
	{
		$c = new Contract();
		$c->setId($id);
		$c->setType($type);
		$c->setCostCenter($costCenter);
		return $c;
	}

	private function cost(int $contractId, int $cents, string $interval, ?string $from = null, ?string $to = null): Cost
	{
		$e = new Cost();
		$e->setContractId($contractId);
		$e->setAmountCents($cents);
		$e->setCurrency('EUR');
		$e->setInterval($interval);
		$e->setValidFrom($from);
		$e->setValidTo($to);
		return $e;
	}

	public function testGroupByTypeSumsPerCurrency(): void
	{
		$contracts = [
			$this->contract(1, 'rent', 'IT'),
			$this->contract(2, 'rent', 'IT'),
			$this->contract(3, 'insurance', null),
		];
		$this->costMapper->method('findByContracts')->willReturn([
			$this->cost(1, 10000, 'monthly'),
			$this->cost(2, 20000, 'monthly'),
			$this->cost(3, 5000, 'once'),
		]);

		$rows = $this->costs->rollupGrouped($contracts, 'type', 2026);
		$byKey = array_column($rows, null, 'key');

		self::assertSame(360000, $byKey['rent']['annual_cents']['EUR']); // (10k+20k)*12
		self::assertSame(2, $byKey['rent']['contracts']);
		self::assertSame(5000, $byKey['insurance']['once_cents']['EUR']);
		self::assertArrayNotHasKey('EUR', $byKey['insurance']['annual_cents'] ?? []);
	}

	public function testGroupByCostCenterCollectsUnassigned(): void
	{
		$contracts = [$this->contract(1, 'rent', 'IT'), $this->contract(2, 'rent', null)];
		$this->costMapper->method('findByContracts')->willReturn([
			$this->cost(1, 1000, 'yearly'),
			$this->cost(2, 2000, 'yearly'),
		]);

		$rows = array_column($this->costs->rollupGrouped($contracts, 'costCenter', 2026), null, 'key');
		self::assertSame(1000, $rows['IT']['annual_cents']['EUR']);
		self::assertSame(2000, $rows['']['annual_cents']['EUR']); // unassigned bucket
	}

	public function testContractGroupingSkipsEmptyAndExcludesExpiredWindow(): void
	{
		$contracts = [$this->contract(1, 'rent', 'IT'), $this->contract(2, 'rent', 'IT')];
		$this->costMapper->method('findByContracts')->willReturn([
			$this->cost(1, 1000, 'monthly'),
			// entry 2's validity ended before the rollup year → excluded
			$this->cost(2, 9000, 'monthly', null, '2025-12-31'),
		]);

		$rows = $this->costs->rollupGrouped($contracts, 'contract', 2026);
		// contract 2's only entry expired before 2026 → entry_count 0 → row skipped
		self::assertCount(1, $rows);
		self::assertSame(12000, $rows[0]['annual_cents']['EUR']);
	}

	public function testInvalidGroupByRejected(): void
	{
		$this->expectException(ValidationException::class);
		$this->costs->rollupGrouped([], 'evil', 2026);
	}

	public function testDeadlineWindowMatcher(): void
	{
		$open = ['deadline' => ['state' => 'open', 'daysLeft' => 20, 'cancelBy' => '2026-11-01', 'effectiveAt' => null, 'missedCancelBy' => null, 'missedEffectiveAt' => null], 'endDate' => '2027-03-01'];
		self::assertTrue($this->deadlines->matchesDeadlineWindow($open, 'cancel30'));
		self::assertTrue($this->deadlines->matchesDeadlineWindow($open, 'cancel90'));
		self::assertFalse($this->deadlines->matchesDeadlineWindow($open, 'missed'));
		self::assertFalse($this->deadlines->matchesDeadlineWindow($open, 'none'));
		self::assertFalse($this->deadlines->matchesDeadlineWindow($open, 'expiring')); // ends 2027-03-01 > 90d

		$missed = ['deadline' => ['state' => 'missed', 'daysLeft' => null, 'cancelBy' => null, 'effectiveAt' => '2026-12-31', 'missedCancelBy' => '2026-10-01', 'missedEffectiveAt' => '2026-12-31'], 'endDate' => '2026-12-31'];
		self::assertTrue($this->deadlines->matchesDeadlineWindow($missed, 'missed'));
		self::assertTrue($this->deadlines->matchesDeadlineWindow($missed, 'expiring')); // ends 2026-12-31 = 80d
		self::assertFalse($this->deadlines->matchesDeadlineWindow($missed, 'cancel30'));

		$none = ['deadline' => ['state' => 'none', 'daysLeft' => null, 'cancelBy' => null, 'effectiveAt' => null, 'missedCancelBy' => null, 'missedEffectiveAt' => null], 'endDate' => null];
		self::assertTrue($this->deadlines->matchesDeadlineWindow($none, 'none'));
		self::assertFalse($this->deadlines->matchesDeadlineWindow($none, 'expiring'));
		self::assertFalse($this->deadlines->matchesDeadlineWindow($none, 'cancel90'));
	}
}
