<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Service;

use OCA\ContractCheck\Db\Contract;
use OCA\ContractCheck\Db\Cost;
use OCA\ContractCheck\Db\CostMapper;
use OCA\ContractCheck\Exception\NotFoundException;
use OCA\ContractCheck\Exception\ValidationException;
use OCA\ContractCheck\Service\AuditService;
use OCA\ContractCheck\Service\CostService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CostServiceTest extends TestCase
{
	private CostMapper&MockObject $costs;
	private AuditService&MockObject $audit;
	private CostService $svc;

	protected function setUp(): void
	{
		$this->costs = $this->createMock(CostMapper::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->svc = new CostService($this->costs, $this->audit);
	}

	private function cost(int $id, int $contractId = 7): Cost
	{
		$c = new Cost();
		$c->setId($id);
		$c->setContractId($contractId);
		$c->setAmountCents(120000);
		$c->setCurrency('EUR');
		$c->setInterval('monthly');
		return $c;
	}

	public function testValidateRejectsNonIntegerCents(): void
	{
		foreach ([-1, 1.5, '2.9', '2e3', 'abc', null, true] as $bad) {
			try {
				CostService::validate(['label' => 'x', 'amountCents' => $bad, 'currency' => 'EUR']);
				self::fail('expected ValidationException for ' . var_export($bad, true));
			} catch (ValidationException $e) {
				self::assertArrayHasKey('amountCents', $e->getFields());
			}
		}
	}

	public function testValidateAcceptsDigitStringCents(): void
	{
		$v = CostService::validate(['label' => 'x', 'amountCents' => '120000', 'currency' => 'eur']);
		self::assertSame(120000, $v['amountCents']);
		self::assertSame('EUR', $v['currency']); // normalized
	}

	public function testValidateRejectsValidToBeforeValidFrom(): void
	{
		try {
			CostService::validate([
				'label' => 'x', 'amountCents' => 100, 'currency' => 'EUR',
				'validFrom' => '2028-01-01', 'validTo' => '2027-01-01',
			]);
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('validTo', $e->getFields());
		}
	}

	public function testValidateRejectsBadCurrencyAndLabel(): void
	{
		try {
			CostService::validate(['label' => '  ', 'amountCents' => 1, 'currency' => 'EURO']);
			self::fail('expected ValidationException');
		} catch (ValidationException $e) {
			self::assertArrayHasKey('label', $e->getFields());
			self::assertArrayHasKey('currency', $e->getFields());
		}
	}

	public function testUpdateForeignContractIsUniformNotFound(): void
	{
		// A cost row reachable by id but belonging to another contract must
		// serialize as 404 — never leak that the row exists elsewhere.
		$this->costs->method('find')->willReturn($this->cost(5, contractId: 99));
		$this->expectException(NotFoundException::class);
		$this->svc->update(7, 5, ['label' => 'x', 'amountCents' => 1, 'currency' => 'EUR'], 'alice');
	}

	public function testDeleteForeignContractIsUniformNotFound(): void
	{
		$this->costs->method('deleteForContract')->willReturn(0);
		$this->audit->expects($this->never())->method('record');
		$this->expectException(NotFoundException::class);
		$this->svc->delete(7, 5, 'alice');
	}

	public function testRollupGroupedRejectsUnknownGrouping(): void
	{
		$this->expectException(ValidationException::class);
		$this->svc->rollupGrouped([], 'evil', 2027);
	}

	public function testRollupGroupedByCostCenterKeepsEmptyBuckets(): void
	{
		$a = new Contract();
		$a->setId(1);
		$a->setType('rent');
		$a->setCostCenter('IT');
		$b = new Contract();
		$b->setId(2);
		$b->setType('rent');
		$b->setCostCenter('');
		$this->costs->method('findByContracts')->willReturn([]);
		$rows = $this->svc->rollupGrouped([$a, $b], 'costCenter', 2027);
		// Budgeting needs the bucket even when a contract has no cost rows.
		self::assertCount(2, $rows);
		$keys = array_map(static fn ($r) => $r['key'], $rows);
		self::assertContains('IT', $keys);
		self::assertContains('', $keys);
	}

	public function testRollupByContractSkipsCostlessContracts(): void
	{
		$a = new Contract();
		$a->setId(1);
		$a->setType('rent');
		$this->costs->method('findByContracts')->willReturn([]);
		self::assertSame([], $this->svc->rollupGrouped([$a], 'contract', 2027));
	}
}
