<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Service;

use OCA\ContractCheck\Db\Contract;
use OCA\ContractCheck\Db\ContractMapper;
use OCA\ContractCheck\Domain\DeadlineEngine;
use OCA\ContractCheck\Service\AccessService;
use OCA\ContractCheck\Service\AuditService;
use OCA\ContractCheck\Service\Clock;
use OCA\ContractCheck\Service\NotificationService;
use OCA\ContractCheck\Service\RenewalService;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class RenewalServiceTest extends TestCase
{
	private ContractMapper&MockObject $contracts;
	private AuditService&MockObject $audit;
	private AccessService&MockObject $access;
	private NotificationService&MockObject $notifications;
	private \OCP\IDBConnection&MockObject $db;
	private RenewalService $svc;

	protected function setUp(): void
	{
		$this->contracts = $this->createMock(ContractMapper::class);
		$this->audit = $this->createMock(AuditService::class);
		$this->access = $this->createMock(AccessService::class);
		$this->notifications = $this->createMock(NotificationService::class);
		$clock = $this->createMock(Clock::class);
		$clock->method('today')->willReturn('2027-06-01');
		$clock->method('now')->willReturn(new \DateTimeImmutable('2027-06-01 00:00:00'));
		$this->db = $this->createMock(\OCP\IDBConnection::class);
		$this->svc = new RenewalService(
			$this->contracts, $this->audit, $this->access,
			$this->notifications, new DeadlineEngine(), $clock, $this->db
		);
	}

	private function contract(string $term, ?string $end, int $rev = 2): Contract
	{
		$c = new Contract();
		$c->setId(9);
		$c->setStatus('active');
		$c->setTermKind($term);
		$c->setRevision($rev);
		if ($end !== null) {
			$c->setEndDate(\DateTimeImmutable::createFromFormat('Y-m-d', $end));
		}
		$c->setRenewalValue(12);
		$c->setRenewalUnit('months');
		return $c;
	}

	public function testFixedPastEndExpires(): void
	{
		$this->contracts->method('findActiveForJob')->willReturn([$this->contract('fixed', '2027-05-31')]);
		$this->contracts->expects($this->once())->method('guardedUpdate')
			->with(9, self::callback(fn ($set) => $set['status'] === 'expired' && $set['revision'] === 3), self::anything())
			->willReturn(1);
		$this->access->method('reminderRecipients')->willReturn(['boss']);
		$this->notifications->expects($this->once())->method('expired')->with(9, 'boss');
		$this->audit->expects($this->once())->method('record')->with(9, 'expired', null);
		self::assertSame(['rolled' => 0, 'expired' => 1], $this->svc->run());
	}

	public function testLostGuardDoesNotAuditOrNotify(): void
	{
		// Second parallel run: row already moved → guard fails → no side effects.
		$this->contracts->method('findActiveForJob')->willReturn([$this->contract('fixed', '2027-05-31')]);
		$this->contracts->method('guardedUpdate')->willReturn(0);
		$this->notifications->expects($this->never())->method('expired');
		$this->audit->expects($this->never())->method('record');
		self::assertSame(['rolled' => 0, 'expired' => 0], $this->svc->run());
	}

	public function testAutoRenewRollsForward(): void
	{
		$this->contracts->method('findActiveForJob')->willReturn([$this->contract('auto_renew', '2027-05-31')]);
		$this->contracts->expects($this->once())->method('guardedUpdate')
			->with(9, self::callback(fn ($set) => $set['end_date'] === '2028-05-31'), self::anything())
			->willReturn(1);
		$this->audit->expects($this->once())->method('record')
			->with(9, 'renewed', null, ['from' => '2027-05-31', 'to' => '2028-05-31']);
		self::assertSame(['rolled' => 1, 'expired' => 0], $this->svc->run());
	}

	public function testFutureEndUntouched(): void
	{
		$this->contracts->method('findActiveForJob')->willReturn([
			$this->contract('auto_renew', '2028-01-01'),
			$this->contract('fixed', '2028-01-01'),
		]);
		$this->contracts->expects($this->never())->method('guardedUpdate');
		self::assertSame(['rolled' => 0, 'expired' => 0], $this->svc->run());
	}

	public function testIndefiniteNeverTouched(): void
	{
		$c = $this->contract('indefinite', null);
		$this->contracts->method('findActiveForJob')->willReturn([$c]);
		$this->contracts->expects($this->never())->method('guardedUpdate');
		self::assertSame(['rolled' => 0, 'expired' => 0], $this->svc->run());
	}

	public function testAuditFailureRollsBackGuardedUpdate(): void
	{
		// SF-03: update + audit are one transaction — a crash between them
		// must not leave a silent renewal/expiry with no audit row.
		$c = $this->contract('fixed', '2027-05-01');
		$this->contracts->method('findActiveForJob')->willReturn([$c]);
		$this->contracts->method('guardedUpdate')->willReturn(1);
		$this->audit->method('record')->willThrowException(new \RuntimeException('audit db down'));
		$this->db->expects($this->once())->method('rollBack');
		$this->db->expects($this->never())->method('commit');
		$this->notifications->expects($this->never())->method('expired');
		$this->expectException(\RuntimeException::class);
		$this->svc->run();
	}
}
