<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Service;

use OCA\ContractCheck\Db\ContractEvent;
use OCA\ContractCheck\Db\ContractEventMapper;
use OCA\ContractCheck\Service\AuditService;
use OCA\ContractCheck\Service\Clock;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AuditServiceTest extends TestCase
{
	private ContractEventMapper&MockObject $mapper;
	private AuditService $svc;

	protected function setUp(): void
	{
		$this->mapper = $this->createMock(ContractEventMapper::class);
		$clock = $this->createMock(Clock::class);
		$clock->method('now')->willReturn(new \DateTimeImmutable('2027-06-01 12:00:00'));
		$this->svc = new AuditService($this->mapper, $clock);
	}

	public function testGenesisEvent(): void
	{
		$this->mapper->method('findHead')->with(5)->willReturn(null);
		$this->mapper->expects($this->once())->method('insert')
			->with(self::callback(
				fn (ContractEvent $e) => $e->getSeq() === 1
					&& $e->getPrevHash() === 'genesis'
					&& $e->getContractId() === 5
					&& $e->getEvent() === 'created'
			));
		$this->svc->record(5, 'created', 'alice');
	}

	public function testChainsFromHead(): void
	{
		$head = new ContractEvent();
		$head->setSeq(41);
		$head->setPrevHash('abc');
		$head->setContractId(5);
		$head->setEvent('updated');
		$head->setActorUid('alice');
		$head->setPayloadJson(null);
		$head->setCreatedAt(new \DateTimeImmutable('2027-05-01 00:00:00'));
		$expected = $head->contentHash();

		$this->mapper->method('findHead')->willReturn($head);
		$this->mapper->expects($this->once())->method('insert')
			->with(self::callback(
				fn (ContractEvent $e) => $e->getSeq() === 42 && $e->getPrevHash() === $expected
			));
		$this->svc->record(5, 'updated', 'bob');
	}

	public function testSeqRaceRetriesOnceThenSucceeds(): void
	{
		$head = new ContractEvent();
		$head->setSeq(10);
		$head->setPrevHash('x');
		$head->setContractId(5);
		$head->setEvent('e');
		$head->setCreatedAt(new \DateTimeImmutable());
		$this->mapper->method('findHead')->willReturnOnConsecutiveCalls($head, $head, $head);

		$calls = 0;
		$this->mapper->method('insert')->willReturnCallback(
			function (ContractEvent $e) use (&$calls) {
				if (++$calls === 1) {
					throw new \Exception('An exception occurred while executing: Duplicate entry for key unique');
				}
				return $e;
			}
		);
		$this->svc->record(5, 'updated', 'bob'); // must not throw
		self::assertSame(2, $calls);
	}

	public function testNonDuplicateErrorsPropagate(): void
	{
		$this->mapper->method('findHead')->willReturn(null);
		$this->mapper->method('insert')->willThrowException(new \RuntimeException('connection lost'));
		$this->expectException(\RuntimeException::class);
		$this->svc->record(5, 'created', 'alice');
	}

	public function testPersistentDuplicateGivesUpAfter3(): void
	{
		$this->mapper->method('findHead')->willReturn(null);
		$calls = 0;
		$this->mapper->method('insert')->willReturnCallback(
			function () use (&$calls) { ++$calls; throw new \Exception('UNIQUE constraint failed'); }
		);
		try {
			$this->svc->record(5, 'x', null);
			self::fail('expected throw');
		} catch (\Exception $e) {
			self::assertSame(3, $calls);
		}
	}
}
