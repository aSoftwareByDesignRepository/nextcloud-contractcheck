<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\BackgroundJob;

use OCA\ContractCheck\BackgroundJob\DeadlineScanJob;
use OCA\ContractCheck\Service\DocumentService;
use OCA\ContractCheck\Service\ReminderService;
use OCA\ContractCheck\Service\RenewalService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class DeadlineScanJobTest extends TestCase
{
	private RenewalService&MockObject $renewals;
	private ReminderService&MockObject $reminders;
	private DocumentService&MockObject $documents;
	private LoggerInterface&MockObject $logger;
	private ILockingProvider&MockObject $locking;
	private DeadlineScanJob $job;

	protected function setUp(): void
	{
		$this->renewals = $this->createMock(RenewalService::class);
		$this->reminders = $this->createMock(ReminderService::class);
		$this->documents = $this->createMock(DocumentService::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->job = new DeadlineScanJob(
			$this->createMock(ITimeFactory::class),
			$this->renewals, $this->reminders, $this->documents, $this->logger, $this->locking
		);
	}

	private function runJob(): void
	{
		$m = new \ReflectionMethod($this->job, 'run');
		$m->invoke($this->job, null);
	}

	public function testAllThreePassesRun(): void
	{
		$this->renewals->expects($this->once())->method('run')
			->willReturn(['rolled' => 1, 'expired' => 2]);
		$this->reminders->expects($this->once())->method('dispatchDueReminders')->willReturn(3);
		$this->documents->expects($this->once())->method('rescanMissing')->willReturn([9]);
		$this->logger->expects($this->once())->method('info');
		$this->runJob();
	}

	public function testRenewalFailureDoesNotStarveOtherPasses(): void
	{
		$this->renewals->method('run')->willThrowException(new \RuntimeException('db down'));
		$this->reminders->expects($this->once())->method('dispatchDueReminders')->willReturn(0);
		$this->documents->expects($this->once())->method('rescanMissing')->willReturn([]);
		$this->logger->expects($this->once())->method('error');
		$this->logger->expects($this->never())->method('info');
		$this->runJob(); // must not throw
	}

	public function testReminderFailureStillScansDocuments(): void
	{
		$this->renewals->method('run')->willReturn(['rolled' => 0, 'expired' => 0]);
		$this->reminders->method('dispatchDueReminders')->willThrowException(new \RuntimeException('x'));
		$this->documents->expects($this->once())->method('rescanMissing')->willReturn([]);
		$this->runJob();
	}

	public function testDocumentFailureStillReportsEarlierResults(): void
	{
		$this->renewals->method('run')->willReturn(['rolled' => 5, 'expired' => 0]);
		$this->reminders->method('dispatchDueReminders')->willReturn(2);
		$this->documents->method('rescanMissing')->willThrowException(new \RuntimeException('x'));
		$this->logger->expects($this->once())->method('info')
			->with(self::stringContains('5 rolled'));
		$this->runJob();
	}

	public function testQuietRunLogsNothing(): void
	{
		$this->renewals->method('run')->willReturn(['rolled' => 0, 'expired' => 0]);
		$this->reminders->method('dispatchDueReminders')->willReturn(0);
		$this->documents->method('rescanMissing')->willReturn([]);
		$this->logger->expects($this->never())->method('info');
		$this->logger->expects($this->never())->method('error');
		$this->runJob();
	}

	public function testSkipsAllPassesWhileRestoreHoldsLock(): void
	{
		// A restore holds the exclusive backup lock — the scan must skip,
		// not write rows into tables being truncated+reinserted.
		$this->locking->method('acquireLock')
			->willThrowException(new LockedException('restore running'));
		$this->renewals->expects($this->never())->method('run');
		$this->reminders->expects($this->never())->method('dispatchDueReminders');
		$this->documents->expects($this->never())->method('rescanMissing');
		$this->runJob(); // must not throw
	}

	public function testReleasesSharedLockAfterRun(): void
	{
		$this->locking->expects($this->once())->method('acquireLock')
			->with($this->anything(), ILockingProvider::LOCK_SHARED, $this->anything());
		$this->locking->expects($this->once())->method('releaseLock')
			->with($this->anything(), ILockingProvider::LOCK_SHARED);
		$this->renewals->method('run')->willReturn(['rolled' => 0, 'expired' => 0]);
		$this->reminders->method('dispatchDueReminders')->willReturn(0);
		$this->documents->method('rescanMissing')->willReturn([]);
		$this->runJob();
	}

	public function testReleasesLockEvenWhenPassThrows(): void
	{
		$this->renewals->method('run')->willThrowException(new \RuntimeException('x'));
		$this->reminders->method('dispatchDueReminders')->willReturn(0);
		$this->documents->method('rescanMissing')->willReturn([]);
		$this->locking->expects($this->once())->method('releaseLock');
		$this->runJob();
	}
}
