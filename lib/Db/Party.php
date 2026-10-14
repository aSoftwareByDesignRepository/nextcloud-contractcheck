<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Db;

/**
 * @method string getName()
 * @method void setName(string $v)
 * @method ?string getContactName()
 * @method void setContactName(?string $v)
 * @method ?string getContactEmail()
 * @method void setContactEmail(?string $v)
 * @method ?string getContactPhone()
 * @method void setContactPhone(?string $v)
 * @method ?string getContactUid()
 * @method void setContactUid(?string $v)
 * @method ?string getAddress()
 * @method void setAddress(?string $v)
 * @method ?string getNotes()
 * @method void setNotes(?string $v)
 * @method int getArchived()
 * @method void setArchived(int $v)
 */
class Party extends ContractCheckEntity
{
	protected $name = '';
	protected $contactName;
	protected $contactEmail;
	protected $contactPhone;
	protected $contactUid;
	protected $address;
	protected $notes;
	protected $archived = 0;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('archived', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('updatedAt', 'datetime_immutable');
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return [
			'id' => $this->getId(),
			'name' => $this->getName(),
			'contactName' => $this->getContactName(),
			'contactEmail' => $this->getContactEmail(),
			'contactPhone' => $this->getContactPhone(),
			'contactUid' => $this->getContactUid(),
			'address' => $this->getAddress(),
			'notes' => $this->getNotes(),
			'archived' => $this->getArchived() === 1,
			'updatedAt' => $this->dateTimeStr('updatedAt'),
		];
	}
}
