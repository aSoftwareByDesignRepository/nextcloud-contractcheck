<?php

declare(strict_types=1);

namespace OCA\ContractCheck\Tests\Integration;

use OCA\ContractCheck\Service\UpgradeBackupService;
use OCP\IDBConnection;
use Test\TestCase;

/**
 * Live restore drill: snapshot → mutate → restore → assert the world comes
 * back exactly as snapshotted, and post-snapshot rows are gone.
 * Runs only against a bootstrapped Nextcloud (container).
 */
final class UpgradeRestoreRoundTripIntegrationTest extends TestCase
{
	private const MARKER = 'CC-ITEST-RESTORE-MARKER-' . __CLASS__;

	private UpgradeBackupService $backup;
	private IDBConnection $db;

	protected function setUp(): void
	{
		parent::setUp();
		if (!defined('CONTRACTCHECK_NC_BOOTSTRAPPED')) {
			$this->markTestSkipped('Nextcloud is not bootstrapped');
		}
		$this->backup = \OC::$server->get(UpgradeBackupService::class);
		$this->db = \OC::$server->get(IDBConnection::class);
	}

	private function markerCount(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('cc_parties')
			->where($qb->expr()->eq('name', $qb->createNamedParameter(self::MARKER)));
		return (int)$qb->executeQuery()->fetchOne();
	}

	private function partyCount(): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from('cc_parties');
		return (int)$qb->executeQuery()->fetchOne();
	}

	public function testSnapshotMutateRestoreRoundTrip(): void
	{
		$before = $this->partyCount();
		$this->deleteMarker();

		$created = $this->backup->createSnapshot('itest-roundtrip', rotate: false);
		$id = $created['id'];

		try {
			// Mutate after the snapshot: insert a marker row the snapshot
			// does not contain, and count must drift by exactly one.
			$qb = $this->db->getQueryBuilder();
			$qb->insert('cc_parties')
				->setValue('name', $qb->createNamedParameter(self::MARKER))
				->setValue('archived', $qb->createNamedParameter(0, \OCP\DB\QueryBuilder\IQueryBuilder::PARAM_INT))
				->setValue('created_at', $qb->createNamedParameter('2027-01-01 00:00:00'))
				->setValue('updated_at', $qb->createNamedParameter('2027-01-01 00:00:00'))
				->executeStatement();
			self::assertSame(1, $this->markerCount());
			self::assertSame($before + 1, $this->partyCount());

			$this->backup->restoreSnapshot($id, createSafetySnapshot: false);

			// Restore must have wiped the post-snapshot row and preserved
			// everything that existed at snapshot time.
			self::assertSame(0, $this->markerCount(), 'post-snapshot row must be rolled back by restore');
			self::assertSame($before, $this->partyCount(), 'snapshot-state rows must survive restore');
		} finally {
			$this->deleteMarker();
			$this->deleteSnapshot($id);
		}
	}

	private function deleteSnapshot(string $id): void
	{
		// Snapshots accumulate in rotation slots — the test cleans up its own.
		$instanceId = \OC::$server->get(\OCP\IConfig::class)->getSystemValueString('instanceid');
		$root = \OC::$server->get(\OCP\Files\IRootFolder::class);
		try {
			$root->get('appdata_' . $instanceId . '/contractcheck/upgrade-backups/' . $id)->delete();
		} catch (\Throwable) {
		}
	}

	private function deleteMarker(): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete('cc_parties')
			->where($qb->expr()->eq('name', $qb->createNamedParameter(self::MARKER)))
			->executeStatement();
	}
}
