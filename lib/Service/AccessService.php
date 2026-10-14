<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCA\ContractCheck\Db\Contract;
use OCA\ContractCheck\Exception\NotFoundException;
use OCP\IGroupManager;
use OCP\IUserManager;

/**
 * Central role + scope resolution. Everything permission-related funnels
 * through here — controllers and services never hand-roll ACL checks.
 *
 * Roles (spec §MH-09):
 *  - manager      → members of configured manager groups (default: admin)
 *  - cost-viewer  → managers + members of configured cost-viewer groups
 *  - viewer       → any user passing the app access door
 *
 * Scopes (spec §9):
 *  - org        → all app users
 *  - dept       → owner + users sharing ≥1 group with the owner + managers
 *  - restricted → owner + managers
 *
 * Enforcement happens in SQL at the mapper (visibilityScope) AND again
 * per-entity at the service boundary (canSee) — defence in depth.
 */
class AccessService
{
	public function __construct(
		private readonly SettingsService $settings,
		private readonly IGroupManager $groupManager,
		private readonly IUserManager $userManager,
	) {
	}

	/** App access door — may this user open ContractCheck at all? */
	public function hasAppAccess(?string $uid): bool
	{
		if ($uid === null || $uid === '') {
			return false;
		}
		$allowed = $this->settings->getAllowedGroups();
		if ($allowed === []) {
			return true;
		}
		foreach ($allowed as $gid) {
			if ($this->groupManager->isInGroup($uid, $gid)) {
				return true;
			}
		}

		return false;
	}

	public function isManager(?string $uid): bool
	{
		if ($uid === null || $uid === '') {
			return false;
		}
		foreach ($this->settings->getManagerGroups() as $gid) {
			if ($this->groupManager->isInGroup($uid, $gid)) {
				return true;
			}
		}

		return false;
	}

	/** Cost columns/rollups are gated server-side — never emitted otherwise. */
	public function canViewCosts(?string $uid): bool
	{
		if ($this->isManager($uid)) {
			return true;
		}
		if ($uid === null || $uid === '') {
			return false;
		}
		foreach ($this->settings->getCostViewerGroups() as $gid) {
			if ($this->groupManager->isInGroup($uid, $gid)) {
				return true;
			}
		}

		return false;
	}

	/** Mutations are manager-only. */
	public function canEdit(?string $uid): bool
	{
		return $this->isManager($uid);
	}

	/**
	 * SQL fragments + params that limit a query to contracts the user may
	 * see. Returned as OR-able fragments for ContractMapper::findVisible.
	 *
	 * dept visibility needs "shares a group with the owner" — resolved via a
	 * core group_user subquery (read-only, stable across supported NC).
	 *
	 * @return array{fragments: list<string>, params: array<string, mixed>}
	 */
	public function visibilityScope(string $uid): array
	{
		$fragments = ["scope = 'org'", "owner_uid = :cc_acl_uid"];
		$params = ['cc_acl_uid' => $uid];

		if ($this->isManager($uid)) {
			// Managers see everything — a tautology fragment is simplest.
			return ['fragments' => ["1 = 1"], 'params' => []];
		}

		$gids = $this->groupIds($uid);
		if ($gids !== []) {
			// dept rows whose owner shares any group with the viewer.
			$fragments[] = "(scope = 'dept' AND owner_uid IN ("
				. 'SELECT gu.uid FROM `*PREFIX*group_user` gu WHERE gu.gid IN ('
				. $this->quotedInList($gids, 'cc_acl_gid', $params)
				. ')))';
		}
		// 'restricted' rows: only owner_uid = :cc_acl_uid (already covered).

		return ['fragments' => $fragments, 'params' => $params];
	}

	/**
	 * Entity-level scope check — used for single-record fetches where the
	 * SQL fragment path was bypassed or for belt-and-braces on writes.
	 */
	public function canSee(Contract $contract, string $uid): bool
	{
		if ($this->isManager($uid)) {
			return true;
		}
		if ($contract->getOwnerUid() === $uid) {
			return true;
		}

		return match ($contract->getScope()) {
			'org' => true,
			'dept' => $this->sharesGroupWith($uid, $contract->getOwnerUid()),
			default => false, // restricted + unknown scopes fail closed
		};
	}

	/**
	 * Write-side check: the caller must be a manager AND be allowed to see
	 * the record (a manager always can, but keeping both predicates explicit
	 * makes future role splits safe).
	 */
	public function canEditContract(Contract $contract, string $uid): bool
	{
		return $this->canEdit($uid) && $this->canSee($contract, $uid);
	}

	/**
	 * Fetch + scope-check in one step; missing and out-of-scope records both
	 * surface as NotFoundException → serialized identically (no oracle).
	 */
	public function requireVisible(Contract $contract, string $uid): Contract
	{
		if (!$this->canSee($contract, $uid)) {
			throw new NotFoundException('Contract not found');
		}

		return $contract;
	}

	public function sharesGroupWith(string $uid, ?string $ownerUid): bool
	{
		if ($ownerUid === null || $ownerUid === '' || $uid === $ownerUid) {
			return false;
		}
		$ownerGroups = $this->groupIds($ownerUid);
		if ($ownerGroups === []) {
			return false;
		}
		$myGroups = $this->groupIds($uid);

		return array_intersect($ownerGroups, $myGroups) !== [];
	}

	/**
	 * IGroupManager::getUserGroupIds() takes IUser objects — resolve via the
	 * user manager; deleted users yield no groups (fail closed).
	 *
	 * @return list<string>
	 */
	private function groupIds(string $uid): array
	{
		$user = $this->userManager->get($uid);
		if ($user === null) {
			return [];
		}

		return $this->groupManager->getUserGroupIds($user);
	}

	/**
	 * owner_uid assignment rule (spec §9.5): user must exist AND — unless
	 * the caller is a manager — share scope with the acting user. Managers
	 * may assign any existing user.
	 */
	public function assertAssignableOwner(string $actorUid, ?string $ownerUid): void
	{
		if ($ownerUid === null || $ownerUid === '') {
			return; // owner optional → manager-group fallback covers alerts
		}
		if (!$this->userManager->userExists($ownerUid)) {
			throw new NotFoundException('Owner not found'); // uniform 404, no oracle
		}
		if (!$this->isManager($actorUid) && $ownerUid !== $actorUid
			&& !$this->sharesGroupWith($actorUid, $ownerUid)) {
			throw new NotFoundException('Owner not found');
		}
	}

	/**
	 * UIDs that must be notified for a contract: owner when set & existing,
	 * otherwise the first members of the manager groups (spec US-203 AC-2 —
	 * never nobody).
	 *
	 * @return list<string>
	 */
	public function reminderRecipients(Contract $contract): array
	{
		$owner = $contract->getOwnerUid();
		if ($owner !== null && $owner !== '' && $this->userManager->userExists($owner)) {
			return [$owner];
		}
		$recipients = [];
		foreach ($this->settings->getManagerGroups() as $gid) {
			foreach ($this->groupManager->displayNamesInGroup($gid, '', -1) as $uid => $displayName) {
				$recipients[(string)$uid] = true;
			}
		}

		return array_keys($recipients);
	}

	/** @param list<string> $gids */
	/**
	 * @param list<string> $gids
	 * @param array<string, mixed> $params
	 */
	private function quotedInList(array $gids, string $paramPrefix, array &$params): string
	{
		$placeholders = [];
		foreach ($gids as $i => $gid) {
			$key = $paramPrefix . '_' . $i;
			$params[$key] = $gid;
			$placeholders[] = ':' . $key;
		}

		return implode(',', $placeholders);
	}
}
