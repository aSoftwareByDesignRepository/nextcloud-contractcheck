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

/** @template-extends QBMapper<ContractEvent> */
class ContractEventMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'cc_events', ContractEvent::class);
	}

	/** Latest event for one contract — chain head (NULL when no events yet). */
	public function findHead(int $contractId): ?ContractEvent
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('contract_id', $qb->createNamedParameter($contractId, IQueryBuilder::PARAM_INT)))
			->orderBy('seq', 'DESC')
			->setMaxResults(1);
		$rows = $this->findEntities($qb);

		return $rows[0] ?? null;
	}

	/** @return list<ContractEvent> */
	public function findByContract(int $contractId, int $limit = 200, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('contract_id', $qb->createNamedParameter($contractId, IQueryBuilder::PARAM_INT)))
			->orderBy('seq', 'DESC')
			->setMaxResults(max(1, min($limit, 500)))
			->setFirstResult(max(0, $offset));

		return $this->findEntities($qb);
	}

	/**
	 * Org-wide audit stream filtered by contract visibility — used by the
	 * audit page. $contractIds = visible ids; empty → nothing.
	 *
	 * @param list<int> $contractIds
	 * @return list<ContractEvent>
	 */
	public function findForContracts(array $contractIds, ?string $event, ?string $from, ?string $to, int $limit = 200): array
	{
		if ($contractIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('contract_id', $qb->createNamedParameter($contractIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->orderBy('seq', 'DESC')
			->setMaxResults(max(1, min($limit, 500)));
		if ($event !== null && $event !== '') {
			$qb->andWhere($qb->expr()->eq('event', $qb->createNamedParameter($event)));
		}
		if ($from !== null && $from !== '') {
			$qb->andWhere($qb->expr()->gte('created_at', $qb->createNamedParameter($from . ' 00:00:00')));
		}
		if ($to !== null && $to !== '') {
			$qb->andWhere($qb->expr()->lte('created_at', $qb->createNamedParameter($to . ' 23:59:59')));
		}

		return $this->findEntities($qb);
	}
}
