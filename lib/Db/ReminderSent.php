<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Db;

/**
 * Reminder de-dup row: (contract_id, deadline_instance_id, lead_day).
 *
 * @method int getContractId()
 * @method void setContractId(int $v)
 * @method string getDeadlineInstanceId()
 * @method void setDeadlineInstanceId(string $v)
 * @method int getLeadDay()
 * @method void setLeadDay(int $v)
 * @method int getPending()
 * @method void setPending(int $v)
 */
class ReminderSent extends ContractCheckEntity
{
	protected $contractId = 0;
	protected $deadlineInstanceId = '';
	protected $leadDay = 0;
	protected $sentAt;
	protected $pending = 0;

	public function __construct()
	{
		$this->addType('contractId', 'integer');
		$this->addType('leadDay', 'integer');
		$this->addType('sentAt', 'datetime_immutable');
		$this->addType('pending', 'integer');
	}
}
