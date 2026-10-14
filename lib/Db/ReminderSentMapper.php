<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Db;

use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<ReminderSent> */
class ReminderSentMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'cc_reminders', ReminderSent::class);
	}

	public function exists(int $contractId, string $instanceId, int $leadDay): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('*'))->from($this->getTableName())
			->where($qb->expr()->eq('contract_id', $qb->createNamedParameter($contractId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deadline_instance_id', $qb->createNamedParameter($instanceId)))
			->andWhere($qb->expr()->eq('lead_day', $qb->createNamedParameter($leadDay, IQueryBuilder::PARAM_INT)));

		return (int)$qb->executeQuery()->fetchOne() > 0;
	}

	/**
	 * Release a claimed dedup slot after a delivery failure so the next job
	 * run retries instead of the reminder being lost forever.
	 */
	public function markUnsent(int $contractId, string $instanceId, int $leadDay): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('contract_id', $qb->createNamedParameter($contractId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deadline_instance_id', $qb->createNamedParameter($instanceId)))
			->andWhere($qb->expr()->eq('lead_day', $qb->createNamedParameter($leadDay, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * Two-phase dedup claim — the composite PK makes concurrent runs safe:
	 * only one writer can insert. When the insert collides, the row is
	 * either already confirmed (deduped) or a pending claim: claims older
	 * than $staleBefore are reclaimed so a crashed delivery retried on the
	 * next pass; a fresh pending claim belongs to a live run and dedups.
	 *
	 * @return bool true when this call holds the claim (caller may notify)
	 */
	public function claim(ReminderSent $row, \DateTimeImmutable $staleBefore): bool
	{
		$row->setPending(1);
		try {
			$this->insert($row);

			return true;
		} catch (\Throwable) {
			// Row exists: confirmed → dedup; stale pending → reclaim below.
		}

		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('pending', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT))
			->set('sent_at', $qb->createNamedParameter($row->getSentAt(), IQueryBuilder::PARAM_DATETIME_IMMUTABLE))
			->where($qb->expr()->eq('contract_id', $qb->createNamedParameter($row->getContractId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deadline_instance_id', $qb->createNamedParameter($row->getDeadlineInstanceId())))
			->andWhere($qb->expr()->eq('lead_day', $qb->createNamedParameter($row->getLeadDay(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('pending', $qb->createNamedParameter(1, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->lt('sent_at', $qb->createNamedParameter($staleBefore, IQueryBuilder::PARAM_DATETIME_IMMUTABLE)));

		return $qb->executeStatement() > 0;
	}

	/**
	 * Confirm a held claim after delivery — flips pending to sent so the
	 * row dedups permanently instead of becoming reclaimable when stale.
	 */
	public function confirmSent(int $contractId, string $instanceId, int $leadDay, \DateTimeImmutable $at): void
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('pending', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT))
			->set('sent_at', $qb->createNamedParameter($at, IQueryBuilder::PARAM_DATETIME_IMMUTABLE))
			->where($qb->expr()->eq('contract_id', $qb->createNamedParameter($contractId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('deadline_instance_id', $qb->createNamedParameter($instanceId)))
			->andWhere($qb->expr()->eq('lead_day', $qb->createNamedParameter($leadDay, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}
}
