<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Db;

use OCA\ContractCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<Party> */
class PartyMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'cc_parties', Party::class);
	}

	public function find(int $id): Party
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity($qb);
		} catch (\Throwable) {
			throw new NotFoundException('Party not found');
		}
	}

	/** @return list<Party> */
	public function search(?string $search, bool $includeArchived, int $limit = 500, int $offset = 0): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->orderBy('name', 'ASC')
			->setMaxResults(max(1, min($limit, 1000)))
			->setFirstResult(max(0, $offset));
		if (!$includeArchived) {
			$qb->where($qb->expr()->eq('archived', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)));
		}
		if ($search !== null && $search !== '') {
			$like = '%' . $this->db->escapeLikeParameter($search) . '%';
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->iLike('name', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('contact_name', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('contact_email', $qb->createNamedParameter($like)),
			));
		}

		return $this->findEntities($qb);
	}

	public function exists(int $id): bool
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return (int)$qb->executeQuery()->fetchOne() > 0;
	}
}
