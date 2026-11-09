<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Db;

use OCA\ContractCheck\Exception\NotFoundException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\Exception as DbException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/** @template-extends QBMapper<ContractDocument> */
class ContractDocumentMapper extends QBMapper
{
	public function __construct(IDBConnection $db)
	{
		parent::__construct($db, 'cc_documents', ContractDocument::class);
	}

	public function find(int $id): ContractDocument
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		try {
			return $this->findEntity($qb);
		} catch (\Throwable) {
			throw new NotFoundException('Document not found');
		}
	}

	/** @return list<ContractDocument> */
	public function findByContract(int $contractId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('contract_id', $qb->createNamedParameter($contractId, IQueryBuilder::PARAM_INT)))
			->orderBy('id', 'ASC');

		return $this->findEntities($qb);
	}

	/** @return list<ContractDocument> */
	public function findByFileId(int $fileId): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createNamedParameter($fileId, IQueryBuilder::PARAM_INT)));

		return $this->findEntities($qb);
	}

	/** @return list<ContractDocument> */
	public function findAll(): array
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName());

		return $this->findEntities($qb);
	}

	/** Insert ignoring the (contract_id, file_id) unique key — idempotent link. */
	public function insertIgnore(ContractDocument $doc): ContractDocument
	{
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from($this->getTableName())
			->where($qb->expr()->eq('contract_id', $qb->createNamedParameter($doc->getContractId(), IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('file_id', $qb->createNamedParameter($doc->getFileId(), IQueryBuilder::PARAM_INT)));
		$existing = $this->findEntities($qb);
		if ($existing !== []) {
			return $existing[0];
		}
		try {
			return $this->insert($doc);
		} catch (\Throwable $e) {
			// Only the unique-key race is idempotent — anything else is a
			// real failure and must surface.
			if (!($e instanceof DbException)
				|| $e->getReason() !== DbException::REASON_UNIQUE_CONSTRAINT_VIOLATION) {
				throw $e;
			}
			$existing = $this->findEntities($qb);
			if ($existing !== []) {
				return $existing[0];
			}
			throw $e;
		}
	}

	public function deleteForContract(int $id, int $contractId): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('contract_id', $qb->createNamedParameter($contractId, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}

	/**
	 * unique-conflict:ok — this UPDATE only SETs missing_since, which is not
	 * a member of cc_doc_con_file_uq, so it cannot violate the unique index.
	 * (deleteForContract is a DELETE; insertIgnore above carries the real
	 * REASON_UNIQUE_CONSTRAINT_VIOLATION catch for the one insert site.)
	 */
	public function setMissingSince(int $id, ?\DateTimeInterface $since): int
	{
		$qb = $this->db->getQueryBuilder();
		$qb->update($this->getTableName())
			->set('missing_since', $qb->createNamedParameter(
				$since === null ? null : $since->format('Y-m-d H:i:s')
			))
			->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

		return $qb->executeStatement();
	}
}
