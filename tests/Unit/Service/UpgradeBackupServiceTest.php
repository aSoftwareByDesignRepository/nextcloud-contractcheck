<?php

declare(strict_types=1);

/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Tests\Unit\Service;

use OCA\ContractCheck\Exception\UpgradeBackupException;
use OCA\ContractCheck\Service\UpgradeBackupService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class UpgradeBackupServiceTest extends TestCase
{
	private IDBConnection&MockObject $db;
	private ILockingProvider&MockObject $locking;
	private UpgradeBackupService $svc;

	protected function setUp(): void
	{
		$this->db = $this->createMock(IDBConnection::class);
		$this->locking = $this->createMock(ILockingProvider::class);
		$this->svc = new UpgradeBackupService(
			$this->db,
			$this->createMock(IConfig::class),
			$this->createMock(IRootFolder::class),
			$this->createMock(\OCP\App\IAppManager::class),
			$this->locking,
			$this->createMock(LoggerInterface::class),
		);
	}

	public function testHasDataToBackupReflectsTablePresence(): void
	{
		$this->db->method('tableExists')->willReturn(false);
		self::assertFalse($this->svc->hasDataToBackup());

		$db = $this->createMock(IDBConnection::class);
		$db->method('tableExists')->willReturnCallback(
			static fn (string $t) => $t === 'cc_contracts'
		);
		$svc = new UpgradeBackupService(
			$db, $this->createMock(IConfig::class), $this->createMock(IRootFolder::class),
			$this->createMock(\OCP\App\IAppManager::class), $this->locking,
			$this->createMock(LoggerInterface::class),
		);
		self::assertTrue($svc->hasDataToBackup());
	}

	public function testLockContentionSurfacesAsBackupException(): void
	{
		// A second backup while one runs must fail fast — not queue, not
		// interleave table writes.
		$this->locking->method('acquireLock')
			->willThrowException(new LockedException('held'));
		$this->db->method('tableExists')->willReturn(true);
		$this->expectException(UpgradeBackupException::class);
		$this->expectExceptionMessage('already in progress');
		$this->svc->createSnapshot('test');
	}

	public function testLockIsReleasedWhenWorkThrows(): void
	{
		$this->db->method('tableExists')->willReturn(false); // early throw path
		$this->locking->expects($this->once())->method('releaseLock');
		try {
			$this->svc->createSnapshot('test');
			self::fail('expected UpgradeBackupException (nothing to back up)');
		} catch (UpgradeBackupException $e) {
			self::assertStringContainsString('nothing to back up', $e->getMessage());
		}
	}

	public function testRestoreRejectsBadSnapshotIdBeforeFilesystem(): void
	{
		// Path-traversal shaped ids die at the pattern check — the root
		// folder must never be consulted.
		$root = $this->createMock(IRootFolder::class);
		$root->expects($this->never())->method('getUserFolder');
		$svc = new UpgradeBackupService(
			$this->db, $this->createMock(IConfig::class), $root,
			$this->createMock(\OCP\App\IAppManager::class), $this->locking,
			$this->createMock(LoggerInterface::class),
		);
		$this->expectException(UpgradeBackupException::class);
		$svc->restoreSnapshot('../../etc/passwd');
	}
}
