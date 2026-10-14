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

/** @template-extends QBMapper<Contract> */
class ContractMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'cc_contracts', Contract::class);
	}

	public function find(int $id): Contract
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity($qb);
		} catch (\Throwable) {
			throw new NotFoundException('Contract not found');
		}
	}

	/**
	 * List contracts visible under a scope filter produced by AccessService
	 * (SQL fragments + params — see AccessService::scopeSql).
	 *
	 * @param list<string> $scopeFragments OR-able WHERE fragments, e.g. ["scope = 'org'", "owner_uid = :cc_uid"]
	 * @param array<string, mixed> $params
	 * @param array{type:?string, partnerId:?int, costCenter:?string, deadline:?string} $filters
	 * @return list<Contract>
	 */
	public function findVisible(array $scopeFragments, array $params, ?string $search, ?string $status, array $filters, int $limit, int $offset): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->setMaxResults(max(1, min($limit, 500)))
			->setFirstResult(max(0, $offset))
			->orderBy('title', 'ASC');

		if ($scopeFragments !== []) {
			$qb->where($qb->expr()->orX(...$scopeFragments));
		}
		foreach ($params as $k => $v) {
			$qb->setParameter($k, $v);
		}
		if ($status !== null && $status !== '') {
			$qb->andWhere($qb->expr()->eq('status', $qb->createNamedParameter($status)));
		}
		if ($filters['type'] !== null) {
			$qb->andWhere($qb->expr()->eq('type', $qb->createNamedParameter($filters['type'])));
		}
		if ($filters['partnerId'] !== null) {
			$qb->andWhere($qb->expr()->eq('partner_id', $qb->createNamedParameter($filters['partnerId'], IQueryBuilder::PARAM_INT)));
		}
		if ($filters['costCenter'] !== null) {
			$qb->andWhere($qb->expr()->eq('cost_center', $qb->createNamedParameter($filters['costCenter'])));
		}
		if ($search !== null && $search !== '') {
			$like = '%' . $this->db->escapeLikeParameter($search) . '%';
			$qb->andWhere($qb->expr()->orX(
				$qb->expr()->iLike('title', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('ref_no', $qb->createNamedParameter($like)),
				$qb->expr()->iLike('cost_center', $qb->createNamedParameter($like)),
			));
		}

		return $this->findEntities($qb);
	}

	/**
	 * Distinct non-empty cost centers across the caller-visible set —
	 * feeds the filter dropdown, so it must obey the same scope.
	 *
	 * @param list<string> $scopeFragments
	 * @param array<string, mixed> $params
	 * @return list<string>
	 */
	public function distinctCostCenters(array $scopeFragments, array $params): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->selectDistinct('cost_center')->from($this->getTableName())
			->where($qb->expr()->isNotNull('cost_center'))
			->andWhere($qb->expr()->neq('cost_center', $qb->createNamedParameter('')))
			->orderBy('cost_center', 'ASC');
		if ($scopeFragments !== []) {
			$qb->andWhere($qb->expr()->orX(...$scopeFragments));
		}
		foreach ($params as $k => $v) {
			$qb->setParameter($k, $v);
		}
		$out = [];
		$res = $qb->executeQuery();
		while (($v = $res->fetchOne()) !== false) {
			$out[] = (string)$v;
		}
		$res->closeCursor();
		return $out;
	}

	/** @return list<Contract> */
	public function findActiveForJob(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->in('status', $qb->createNamedParameter(['active', 'draft'], IQueryBuilder::PARAM_STR_ARRAY)));

		return $this->findEntities($qb);
	}

	/**
	 * CAS update — succeeds only when the stored revision still equals
	 * $expectedRevision. Returns affected row count (0 → conflict).
	 */
	public function updateWithRevision(Contract $contract, int $expectedRevision): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('revision', $qb->createNamedParameter($expectedRevision + 1, IQueryBuilder::PARAM_INT))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($contract->getId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('revision', $qb->createNamedParameter($expectedRevision, IQueryBuilder::PARAM_INT)));

		$fields = [
			'ref_no' => $contract->getRefNo(),
			'title' => $contract->getTitle(),
			'type' => $contract->getType(),
			'partner_id' => $contract->getPartnerId(),
			'owner_uid' => $contract->getOwnerUid(),
			'scope' => $contract->getScope(),
			'status' => $contract->getStatus(),
			'signed_at' => $contract->getSignedAt(),
			'start_date' => $contract->getStartDate(),
			'end_date' => $contract->getEndDate(),
			'term_kind' => $contract->getTermKind(),
			'renewal_value' => $contract->getRenewalValue(),
			'renewal_unit' => $contract->getRenewalUnit(),
			'notice_value' => $contract->getNoticeValue(),
			'notice_unit' => $contract->getNoticeUnit(),
			'notice_anchor' => $contract->getNoticeAnchor(),
			'lead_days_json' => $contract->getLeadDaysJson(),
			'reminder_channel' => $contract->getReminderChannel(),
			'cost_center' => $contract->getCostCenter(),
			'tags_json' => $contract->getTagsJson(),
			'notes' => $contract->getNotes(),
			'terminated_at' => $contract->getTerminatedAt(),
			'updated_at' => $contract->getUpdatedAt(),
		];
		foreach ($fields as $col => $val) {
			if ($val instanceof \DateTimeInterface) {
				$val = in_array($col, ['signed_at', 'start_date', 'end_date', 'terminated_at'], true)
					? $val->format('Y-m-d')
					: $val->format('Y-m-d H:i:s');
			}
			$qb->set($col, $qb->createNamedParameter($val));
		}

		return $qb->executeStatement();
	}

	/**
	 * Atomic conditional transitions for background jobs: apply only while a
	 * predicate still holds (e.g. end_date in the past, status unchanged).
	 * Two parallel job runs can never double-apply.
	 *
	 * @param array<string, mixed> $set
	 * @param list<array{0:string,1:mixed}> $predicates column => value equalities that must still hold
	 */
	public function guardedUpdate(int $id, array $set, array $predicates): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
		foreach ($set as $col => $val) {
			$qb->set($col, $qb->createNamedParameter($val));
		}
		foreach ($predicates as [$col, $val]) {
			$qb->andWhere($qb->expr()->eq($col, $qb->createNamedParameter($val)));
		}

		return $qb->executeStatement();
	}
}
