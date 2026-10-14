<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Db;

use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Single-row versioned settings document on `cc_settings` (id = 1).
 * The row itself is created lazily; optimistic versioning happens through
 * a guarded UPDATE so concurrent saves cannot clobber each other.
 */
class SettingsStore
{
	private const TABLE = 'cc_settings';

	public function __construct(private readonly IDBConnection $db)
	{
	}

	/** @return array{version:int,payload:string}|null */
	public function load(): ?array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('version', 'payload')->from(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)));
		$row = $qb->executeQuery()->fetch();

		if ($row === false) {
			return null;
		}

		return ['version' => (int)$row['version'], 'payload' => (string)$row['payload']];
	}

	/**
	 * Guarded write: only applies while the stored version still equals
	 * $expectedVersion; creates the row on first save.
	 *
	 * @return bool false → concurrent write, caller must reload & retry
	 */
	public function save(string $payload, int $expectedVersion, \DateTimeInterface $now): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('payload', $qb->createNamedParameter($payload))
			->set('version', $qb->createNamedParameter($expectedVersion + 1, IQueryBuilder::PARAM_INT))
			->set('updated_at', $qb->createNamedParameter($now->format('Y-m-d H:i:s')))
			->where($qb->expr()->eq('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('version', $qb->createNamedParameter($expectedVersion, IQueryBuilder::PARAM_INT)));

		if ($qb->executeStatement() === 1) {
			return true;
		}

		if ($expectedVersion !== 0 || $this->load() !== null) {
			return false;
		}

		// First save — create the singleton row.
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)
			->setValue('id', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->setValue('version', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->setValue('payload', $qb->createNamedParameter($payload))
			->setValue('updated_at', $qb->createNamedParameter($now->format('Y-m-d H:i:s')));
		try {
			$qb->executeStatement();

			return true;
		} catch (\Throwable) {
			return false; // concurrent first-writer won
		}
	}
}
