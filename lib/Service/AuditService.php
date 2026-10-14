<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCA\ContractCheck\Db\ContractEvent;
use OCA\ContractCheck\Db\ContractEventMapper;

/**
 * Append-only audit writer — per-contract sequence, hash-chained
 * (prev_hash → contentHash of the previous row of the same contract).
 * Payloads are content-free
 * by contract: ids, enums and dates only, never titles/partner/cost data
 * beyond the bare minimum (spec §6 NN-06, §9.1 events payload_json).
 */
class AuditService
{
	public function __construct(
		private readonly ContractEventMapper $mapper,
		private readonly Clock $clock,
	) {
	}

	/**
	 * Append an event to the contract's chain. Per-contract seq + prev_hash
	 * keep the chain independent of unrelated writes; the (contract_id, seq)
	 * unique index makes concurrent appends detect rather than corrupt —
	 * retried once so a lost race still lands.
	 *
	 * Callers running inside an outer transaction get atomicity for free
	 * (DBAL nests); standalone calls are safe either way.
	 *
	 * @param array<string, scalar|null> $payload content-free metadata only
	 */
	public function record(int $contractId, string $event, ?string $actorUid, array $payload = []): void
	{
		$attempts = 0;
		while (true) {
			try {
				$this->append($contractId, $event, $actorUid, $payload);

				return;
			} catch (\Throwable $e) {
				// Only the seq race is retried — anything else surfaces.
				if (++$attempts >= 3 || !self::isDuplicateKey($e)) {
					throw $e;
				}
			}
		}
	}

	/** @param array<string, scalar|null> $payload */
	private function append(int $contractId, string $event, ?string $actorUid, array $payload): void
	{
		$head = $this->mapper->findHead($contractId);

		$entity = new ContractEvent();
		$entity->setSeq(($head === null ? 0 : $head->getSeq()) + 1);
		$entity->setPrevHash($head === null ? 'genesis' : $head->contentHash());
		$entity->setContractId($contractId);
		$entity->setEvent($event);
		$entity->setActorUid($actorUid);
		$entity->setPayloadJson($payload === [] ? null : (json_encode($payload, JSON_UNESCAPED_UNICODE) ?: null));
		$entity->setCreatedAt($this->clock->now());
		$this->mapper->insert($entity);
	}

	private static function isDuplicateKey(\Throwable $e): bool
	{
		$msg = strtolower($e->getMessage() . ' ' . ($e->getPrevious()?->getMessage() ?? ''));

		return str_contains($msg, 'unique') || str_contains($msg, 'duplicate');
	}
}
