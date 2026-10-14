<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Db;

/**
 * @method int getContractId()
 * @method void setContractId(int $v)
 * @method int getRevision()
 * @method void setRevision(int $v)
 * @method string getSnapshotJson()
 * @method void setSnapshotJson(string $v)
 * @method ?string getActorUid()
 * @method void setActorUid(?string $v)
 */
class ContractRevision extends ContractCheckEntity
{
	protected $contractId = 0;
	protected $revision = 0;
	protected $snapshotJson = '{}';
	protected $actorUid;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('contractId', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		$snapshot = json_decode($this->getSnapshotJson(), true);

		return [
			'id' => $this->getId(),
			'contractId' => $this->getContractId(),
			'revision' => $this->getRevision(),
			'snapshot' => is_array($snapshot) ? $snapshot : null,
			'actorUid' => $this->getActorUid(),
			'createdAt' => $this->dateTimeStr('createdAt'),
		];
	}
}
