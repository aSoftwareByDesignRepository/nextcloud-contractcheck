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
 * @method string getLabel()
 * @method void setLabel(string $v)
 * @method int getAmountCents()
 * @method void setAmountCents(int $v)
 * @method string getCurrency()
 * @method void setCurrency(string $v)
 * @method string getInterval()
 * @method void setInterval(string $v)
 */
class Cost extends ContractCheckEntity
{
	protected $contractId = 0;
	protected $label = '';
	protected $amountCents = 0;
	protected $currency = 'EUR';
	protected $interval = 'yearly';
	protected $validFrom;
	protected $validTo;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('contractId', 'integer');
		$this->addType('amountCents', 'integer');
		$this->addType('validFrom', 'date_immutable');
		$this->addType('validTo', 'date_immutable');
	}

	public function getValidFromString(): ?string { return $this->dateStr('validFrom'); }
	public function getValidToString(): ?string { return $this->dateStr('validTo'); }

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return [
			'id' => $this->getId(),
			'contractId' => $this->getContractId(),
			'label' => $this->getLabel(),
			'amountCents' => $this->getAmountCents(),
			'currency' => $this->getCurrency(),
			'interval' => $this->getInterval(),
			'validFrom' => $this->getValidFromString(),
			'validTo' => $this->getValidToString(),
		];
	}
}
