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
 * @method int getFileId()
 * @method void setFileId(int $v)
 * @method string getKind()
 * @method void setKind(string $v)
 * @method string getAddedBy()
 * @method void setAddedBy(string $v)
 */
class ContractDocument extends ContractCheckEntity
{
	public const KINDS = ['contract', 'amendment', 'termination', 'invoice', 'other'];

	protected $contractId = 0;
	protected $fileId = 0;
	protected $kind = 'contract';
	protected $addedBy = '';
	protected $addedAt;
	protected $missingSince;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('contractId', 'integer');
		$this->addType('fileId', 'integer');
		$this->addType('addedAt', 'datetime_immutable');
		$this->addType('missingSince', 'datetime_immutable');
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return [
			'id' => $this->getId(),
			'contractId' => $this->getContractId(),
			'fileId' => $this->getFileId(),
			'kind' => $this->getKind(),
			'addedBy' => $this->getAddedBy(),
			'addedAt' => $this->dateTimeStr('addedAt'),
			'missingSince' => $this->dateTimeStr('missingSince'),
		];
	}
}
