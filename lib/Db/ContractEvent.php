<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Db;

/**
 * Audit event — append-only, hash-chained via seq + prev_hash.
 *
 * @method int getSeq()
 * @method void setSeq(int $v)
 * @method string getPrevHash()
 * @method void setPrevHash(string $v)
 * @method int getContractId()
 * @method void setContractId(int $v)
 * @method string getEvent()
 * @method void setEvent(string $v)
 * @method ?string getActorUid()
 * @method void setActorUid(?string $v)
 * @method ?string getPayloadJson()
 * @method void setPayloadJson(?string $v)
 */
class ContractEvent extends ContractCheckEntity
{
	public const CREATED = 'created';
	public const UPDATED = 'updated';
	public const ACTIVATED = 'activated';
	public const TERMINATED = 'terminated';
	public const EXPIRED = 'expired';
	public const RENEWED = 'renewed';
	public const ARCHIVED = 'archived';
	public const REOPENED = 'reopened';
	public const DOC_LINKED = 'doc_linked';
	public const DOC_UNLINKED = 'doc_unlinked';
	public const DOC_MISSING = 'doc_missing';
	public const COST_ADDED = 'cost_added';
	public const COST_UPDATED = 'cost_updated';
	public const COST_REMOVED = 'cost_removed';
	public const REMINDER_SENT = 'reminder_sent';
	public const IMPORTED = 'imported';

	protected $seq = 0;
	protected $prevHash = '';
	protected $contractId = 0;
	protected $event = '';
	protected $actorUid;
	protected $payloadJson;
	protected $createdAt;

	public function __construct()
	{
		$this->addType('id', 'integer');
		$this->addType('seq', 'integer');
		$this->addType('contractId', 'integer');
		$this->addType('createdAt', 'datetime_immutable');
	}

	/**
	 * Canonical content hash of this row — the value the NEXT event stores
	 * as prev_hash. Recomputable, so no separate column is needed.
	 */
	public function contentHash(): string
	{
		return self::hashContents(
			$this->getSeq(),
			$this->getPrevHash(),
			$this->getContractId(),
			$this->getEvent(),
			$this->getActorUid(),
			$this->getPayloadJson(),
			$this->dateTimeStr('createdAt'),
		);
	}

	public static function hashContents(
		int $seq, string $prevHash, int $contractId, string $event,
		?string $actorUid, ?string $payloadJson, ?string $createdAt,
	): string {
		return hash('sha256', implode('|', [
			(string)$seq,
			$prevHash,
			(string)$contractId,
			$event,
			$actorUid ?? '',
			$payloadJson ?? '',
			$createdAt ?? '',
		]));
	}

	/** @return array<string, mixed> */
	public function toArray(): array
	{
		$payload = null;
		if ($this->getPayloadJson() !== null && $this->getPayloadJson() !== '') {
			$decoded = json_decode($this->getPayloadJson(), true);
			$payload = is_array($decoded) ? $decoded : null;
		}

		return [
			'id' => $this->getId(),
			'seq' => $this->getSeq(),
			'prevHash' => $this->getPrevHash(),
			'contractId' => $this->getContractId(),
			'event' => $this->getEvent(),
			'actorUid' => $this->getActorUid(),
			'payload' => $payload,
			'createdAt' => $this->dateTimeStr('createdAt'),
		];
	}
}
