<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Db;

/**
 * @method ?string getRefNo()
 * @method void setRefNo(?string $v)
 * @method string getTitle()
 * @method void setTitle(string $v)
 * @method string getType()
 * @method void setType(string $v)
 * @method ?int getPartnerId()
 * @method void setPartnerId(?int $v)
 * @method ?string getOwnerUid()
 * @method void setOwnerUid(?string $v)
 * @method string getScope()
 * @method void setScope(string $v)
 * @method string getStatus()
 * @method void setStatus(string $v)
 * @method ?int getRenewalValue()
 * @method void setRenewalValue(?int $v)
 * @method ?string getRenewalUnit()
 * @method void setRenewalUnit(?string $v)
 * @method ?int getNoticeValue()
 * @method void setNoticeValue(?int $v)
 * @method ?string getNoticeUnit()
 * @method void setNoticeUnit(?string $v)
 * @method ?string getNoticeAnchor()
 * @method void setNoticeAnchor(?string $v)
 * @method ?string getLeadDaysJson()
 * @method void setLeadDaysJson(?string $v)
 * @method string getReminderChannel()
 * @method void setReminderChannel(string $v)
 * @method ?string getCostCenter()
 * @method void setCostCenter(?string $v)
 * @method ?string getTagsJson()
 * @method void setTagsJson(?string $v)
 * @method ?string getNotes()
 * @method void setNotes(?string $v)
 * @method int getRevision()
 * @method void setRevision(int $v)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $v)
 */
class Contract extends ContractCheckEntity
{
	protected $refNo;
	protected $title = '';
	// No 'other' default here: QBMapper::setter() skips columns whose value
	// equals the property default, and the DB column has no default — a
	// create with type 'other' then INSERTs without `type` and dies on a
	// strict-1364. Validation guarantees a real type before insert.
	protected $type;
	protected $partnerId;
	protected $ownerUid;
	protected $scope = 'org';
	protected $status = 'draft';
	protected $signedAt;
	protected $startDate;
	protected $endDate;
	protected $termKind = 'fixed';
	protected $renewalValue;
	protected $renewalUnit;
	protected $noticeValue;
	protected $noticeUnit;
	protected $noticeAnchor;
	protected $leadDaysJson;
	protected $reminderChannel = 'notify';
	protected $costCenter;
	protected $tagsJson;
	protected $notes;
	protected $terminatedAt;
	protected $revision = 0;
	protected $createdBy = '';
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('partnerId', 'integer');
		$this->addType('renewalValue', 'integer');
		$this->addType('noticeValue', 'integer');
		$this->addType('revision', 'integer');
		$this->addType('signedAt', 'date_immutable');
		$this->addType('startDate', 'date_immutable');
		$this->addType('endDate', 'date_immutable');
		$this->addType('terminatedAt', 'date_immutable');
		$this->addType('createdAt', 'datetime_immutable');
		$this->addType('updatedAt', 'datetime_immutable');
	}

	public function getSignedAtString(): ?string { return $this->dateStr('signedAt'); }
	public function getStartDateString(): ?string { return $this->dateStr('startDate'); }
	public function getEndDateString(): ?string { return $this->dateStr('endDate'); }
	public function getTerminatedAtString(): ?string { return $this->dateStr('terminatedAt'); }
	public function getCreatedAtString(): ?string { return $this->dateTimeStr('createdAt'); }
	public function getUpdatedAtString(): ?string { return $this->dateTimeStr('updatedAt'); }

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		return [
			'id' => $this->getId(),
			'refNo' => $this->getRefNo(),
			'title' => $this->getTitle(),
			'type' => $this->getType(),
			'partnerId' => $this->getPartnerId(),
			'ownerUid' => $this->getOwnerUid(),
			'scope' => $this->getScope(),
			'status' => $this->getStatus(),
			'signedAt' => $this->getSignedAtString(),
			'startDate' => $this->getStartDateString(),
			'endDate' => $this->getEndDateString(),
			'termKind' => $this->getTermKind(),
			'renewalValue' => $this->getRenewalValue(),
			'renewalUnit' => $this->getRenewalUnit(),
			'noticeValue' => $this->getNoticeValue(),
			'noticeUnit' => $this->getNoticeUnit(),
			'noticeAnchor' => $this->getNoticeAnchor(),
			'leadDays' => self::decodeIntList($this->getLeadDaysJson(), [60, 30]),
			'reminderChannel' => $this->getReminderChannel(),
			'costCenter' => $this->getCostCenter(),
			'tags' => self::decodeStringList($this->getTagsJson()),
			'notes' => $this->getNotes(),
			'terminatedAt' => $this->getTerminatedAtString(),
			'revision' => $this->getRevision(),
			'createdBy' => $this->getCreatedBy(),
			'createdAt' => $this->getCreatedAtString(),
			'updatedAt' => $this->getUpdatedAtString(),
		];
	}

	public function getTermKind(): string
	{
		return $this->termKind ?? 'fixed';
	}

	/**
	 * @param list<int> $fallback
	 * @return list<int>
	 */
	public static function decodeIntList(?string $json, array $fallback): array
	{
		if ($json === null || $json === '') {
			return $fallback;
		}
		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			return $fallback;
		}
		$out = [];
		foreach ($decoded as $v) {
			if (is_int($v)) {
				$out[] = $v;
			}
		}

		return $out;
	}

	/** @return list<string> */
	public static function decodeStringList(?string $json): array
	{
		if ($json === null || $json === '') {
			return [];
		}
		$decoded = json_decode($json, true);
		if (!is_array($decoded)) {
			return [];
		}

		return array_values(array_filter($decoded, 'is_string'));
	}
}
