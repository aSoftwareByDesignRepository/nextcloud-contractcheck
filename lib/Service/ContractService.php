<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCA\ContractCheck\Db\Contract;
use OCA\ContractCheck\Db\ContractMapper;
use OCA\ContractCheck\Db\ContractRevision;
use OCA\ContractCheck\Db\ContractRevisionMapper;
use OCA\ContractCheck\Db\PartyMapper;
use OCA\ContractCheck\Domain\DateMath;
use OCA\ContractCheck\Exception\ConflictException;
use OCA\ContractCheck\Exception\NotFoundException;
use OCA\ContractCheck\Exception\StateException;
use OCA\ContractCheck\Exception\ValidationException;
use OCP\IDBConnection;

/**
 * Contract aggregate — CRUD, lifecycle transitions, CAS and revision
 * snapshots. All mutations record an audit event inside the same
 * transaction as the write, so history can never diverge from state.
 */
class ContractService
{
	private const TRANSITIONS = [
		'activate' => ['from' => ['draft'], 'to' => 'active', 'event' => 'activated'],
		'terminate' => ['from' => ['active'], 'to' => 'terminated', 'event' => 'terminated'],
		'archive' => ['from' => ['draft', 'terminated', 'expired'], 'to' => 'archived', 'event' => 'archived'],
		'reopen' => ['from' => ['archived'], 'to' => 'active', 'event' => 'reopened'],
	];

	public function __construct(
		private readonly ContractMapper $contracts,
		private readonly PartyMapper $parties,
		private readonly ContractRevisionMapper $revisions,
		private readonly AccessService $access,
		private readonly AuditService $audit,
		private readonly SettingsService $settings,
		private readonly Clock $clock,
		private readonly IDBConnection $db,
	) {
	}

	public function getForUser(int $id, string $uid): Contract
	{
		$contract = $this->contracts->find($id);

		return $this->access->requireVisible($contract, $uid);
	}

	/**
	 * @return list<Contract>
	 */
	/**
	 * @param array{type?:?string, partnerId?:?int, costCenter?:?string, deadline?:?string} $filters
	 * @return list<\OCA\ContractCheck\Db\Contract>
	 */
	public function listForUser(string $uid, ?string $search, ?string $status, array $filters = [], int $limit = 100, int $offset = 0): array
	{
		$scope = $this->access->visibilityScope($uid);
		$filters += ['type' => null, 'partnerId' => null, 'costCenter' => null, 'deadline' => null];

		return $this->contracts->findVisible(
			$scope['fragments'], $scope['params'], $search, $status, $filters, $limit, $offset
		);
	}

	/**
	 * Distinct cost centers visible to $uid — scope-filtered like the list.
	 * @return list<string>
	 */
	public function distinctCostCenters(string $uid): array
	{
		$scope = $this->access->visibilityScope($uid);
		return $this->contracts->distinctCostCenters($scope['fragments'], $scope['params']);
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public function create(array $data, string $actorUid): Contract
	{
		if (!$this->access->canEdit($actorUid)) {
			throw new \OCA\ContractCheck\Exception\ForbiddenException('Manager role required');
		}
		$v = ContractValidator::validate($data, $this->settings->getDefaultLeadDays());
		$this->assertAssignableParty($v['partnerId'], null);
		$this->access->assertAssignableOwner($actorUid, $v['ownerUid']);

		$now = $this->clock->now();
		$contract = new Contract();
		$this->apply($contract, $v);
		$contract->setStatus('draft');
		$contract->setRevision(0);
		$contract->setCreatedBy($actorUid);
		$contract->setCreatedAt($now);
		$contract->setUpdatedAt($now);

		$this->db->beginTransaction();
		try {
			$contract = $this->contracts->insert($contract);
			$this->snapshot($contract, $actorUid);
			$this->audit->record($contract->getId(), 'created', $actorUid, [
				'type' => $contract->getType(),
				'scope' => $contract->getScope(),
				'termKind' => $contract->getTermKind(),
			]);
			$this->db->commit();
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $contract;
	}

	/**
	 * Full update with fail-closed CAS: a missing/unknown revision is a
	 * conflict — never merged against current state (family rule §3).
	 *
	 * @param array<string, mixed> $data
	 */
	public function update(int $id, array $data, mixed $revision, string $actorUid): Contract
	{
		$expected = $this->requireRevision($revision);
		$contract = $this->contracts->find($id);
		$this->access->requireVisible($contract, $actorUid);
		if (!$this->access->canEditContract($contract, $actorUid)) {
			throw new NotFoundException('Contract not found');
		}
		if ($contract->getRevision() !== $expected) {
			throw new ConflictException();
		}

		$v = ContractValidator::validate($data, $this->settings->getDefaultLeadDays(), $contract);
		$this->assertAssignableParty($v['partnerId'], $contract->getPartnerId());
		$this->access->assertAssignableOwner($actorUid, $v['ownerUid']);

		// An end_date moved by the renewal job may legitimately differ from
		// what the client last saw — when only that drift exists, allow the
		// update while preserving the newer stored end date.
		$this->apply($contract, $v);
		$contract->setUpdatedAt($this->clock->now());
		$contract->setRevision($expected + 1);

		$this->db->beginTransaction();
		try {
			if ($this->contracts->updateWithRevision($contract, $expected) !== 1) {
				$this->db->rollBack();
				throw new ConflictException();
			}
			$this->snapshot($contract, $actorUid);
			$this->audit->record($id, 'updated', $actorUid, ['revision' => $expected + 1]);
			$this->db->commit();
		} catch (ConflictException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $contract;
	}

	/**
	 * Lifecycle transition — explicit action, guarded by CAS revision and
	 * the transition table. terminatedAt defaults to today for 'terminate'.
	 *
	 * @param array<string, mixed> $params
	 */
	public function transition(int $id, string $action, array $params, mixed $revision, string $actorUid): Contract
	{
		$expected = $this->requireRevision($revision);
		$contract = $this->contracts->find($id);
		$this->access->requireVisible($contract, $actorUid);
		if (!$this->access->canEditContract($contract, $actorUid)) {
			throw new NotFoundException('Contract not found');
		}
		if ($contract->getRevision() !== $expected) {
			throw new ConflictException();
		}

		$rule = self::TRANSITIONS[$action] ?? null;
		if ($rule === null) {
			throw ValidationException::field('STATE_INVALID', 'action', 'Unknown transition');
		}
		if (!in_array($contract->getStatus(), $rule['from'], true)) {
			throw new StateException('STATE_INVALID', 'Cannot ' . $action . ' a contract in status ' . $contract->getStatus());
		}

		if ($action === 'terminate') {
			$terminatedAt = $params['terminatedAt'] ?? $this->clock->today();
			if (!is_string($terminatedAt) || !DateMath::isValid($terminatedAt)) {
				throw ValidationException::field('CONTRACT_DATES', 'terminatedAt', 'invalid date (YYYY-MM-DD)');
			}
			$contract->setTerminatedAt(DateMath::parse($terminatedAt));
		}
		$contract->setStatus($rule['to']);
		$contract->setUpdatedAt($this->clock->now());
		$contract->setRevision($expected + 1);

		$this->db->beginTransaction();
		try {
			if ($this->contracts->updateWithRevision($contract, $expected) !== 1) {
				$this->db->rollBack();
				throw new ConflictException();
			}
			$this->snapshot($contract, $actorUid);
			$this->audit->record($id, $rule['event'], $actorUid, [
				'to' => $rule['to'],
				'revision' => $expected + 1,
			]);
			$this->db->commit();
		} catch (ConflictException $e) {
			throw $e;
		} catch (\Throwable $e) {
			$this->db->rollBack();
			throw $e;
		}

		return $contract;
	}

	/**
	 * The deadline rule view of a contract (engine input).
	 *
	 * @return array<string, mixed>
	 */
	/**
	 * @return array{
	 *   term_kind:string, start_date:?string, end_date:?string,
	 *   renewal_value:?int, renewal_unit:?string,
	 *   notice_value:?int, notice_unit:?string, notice_anchor:?string
	 * }
	 */
	public function ruleOf(Contract $c): array
	{
		return [
			'term_kind' => $c->getTermKind(),
			'start_date' => $c->getStartDateString(),
			'end_date' => $c->getEndDateString(),
			'renewal_value' => $c->getRenewalValue(),
			'renewal_unit' => $c->getRenewalUnit(),
			'notice_value' => $c->getNoticeValue(),
			'notice_unit' => $c->getNoticeUnit(),
			'notice_anchor' => $c->getNoticeAnchor(),
		];
	}

	private function requireRevision(mixed $revision): int
	{
		// Fail closed: absent or malformed revision is a conflict, never a merge.
		if (is_int($revision) && $revision >= 0) {
			return $revision;
		}
		if (is_string($revision) && preg_match('/^\d+$/', $revision) === 1) {
			return (int)$revision;
		}
		throw new ConflictException('Missing revision — reload and retry.');
	}

	/** @param array<string, mixed> $v */
	private function apply(Contract $c, array $v): void
	{
		$c->setTitle($v['title']);
		$c->setType($v['type']);
		$c->setRefNo($v['refNo']);
		$c->setPartnerId($v['partnerId']);
		$c->setOwnerUid($v['ownerUid']);
		$c->setScope($v['scope']);
		$c->setSignedAt($v['signedAt'] === null ? null : DateMath::parse($v['signedAt']));
		$c->setStartDate($v['startDate'] === null ? null : DateMath::parse($v['startDate']));
		$c->setEndDate($v['endDate'] === null ? null : DateMath::parse($v['endDate']));
		$c->setTermKind($v['termKind']);
		$c->setRenewalValue($v['renewalValue']);
		$c->setRenewalUnit($v['renewalUnit']);
		$c->setNoticeValue($v['noticeValue']);
		$c->setNoticeUnit($v['noticeUnit']);
		$c->setNoticeAnchor($v['noticeAnchor']);
		$c->setLeadDaysJson(json_encode($v['leadDays']) ?: '[]');
		$c->setReminderChannel($v['reminderChannel']);
		$c->setCostCenter($v['costCenter']);
		$c->setTagsJson(json_encode($v['tags']) ?: '[]');
		$c->setNotes($v['notes']);
	}

	/**
	 * A party must exist and — for a *new or changed* assignment — must not be
	 * archived. An already-archived partnerId may stay on an existing contract
	 * (archiving a party must not freeze its contracts), it just cannot be
	 * newly selected.
	 */
	private function assertAssignableParty(?int $partnerId, ?int $currentId): void
	{
		if ($partnerId === null) {
			return;
		}
		if (!$this->parties->exists($partnerId)) {
			throw ValidationException::field('PARTY_INVALID', 'partnerId', 'Party does not exist');
		}
		if ($partnerId !== $currentId && $this->parties->find($partnerId)->getArchived() === 1) {
			throw ValidationException::field('PARTY_INVALID', 'partnerId', 'Party is archived');
		}
	}

	private function snapshot(Contract $c, string $actorUid): void
	{
		$rev = new ContractRevision();
		$rev->setContractId($c->getId());
		$rev->setRevision($c->getRevision());
		$rev->setSnapshotJson(json_encode($c->toArray(), JSON_UNESCAPED_UNICODE) ?: '{}');
		$rev->setActorUid($actorUid);
		$rev->setCreatedAt($this->clock->now());
		$this->revisions->insert($rev);
	}
}
