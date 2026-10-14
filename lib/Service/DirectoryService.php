<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserManager;

/**
 * User/group directory for the searchable pickers. Existence checks run
 * through userExists/groupExists so stale uids (ghost users) can never be
 * attached — membership alone is not sufficient (family rule §2).
 */
class DirectoryService
{
	public function __construct(
		private readonly IUserManager $userManager,
		private readonly IGroupManager $groupManager,
	) {
	}

	/**
	 * @return list<array{uid:string, displayName:string}>
	 */
	public function searchUsers(string $term, int $limit = 20): array
	{
		$out = [];
		foreach ($this->userManager->searchDisplayName($term, $limit) as $user) {
			if ($user instanceof IUser && $user->isEnabled()) {
				$out[] = ['uid' => $user->getUID(), 'displayName' => $user->getDisplayName()];
			}
		}
		// Include exact uid matches display-name search misses.
		if ($term !== '' && $this->userManager->userExists($term)) {
			$user = $this->userManager->get($term);
			if ($user !== null && $user->isEnabled()) {
				$hit = ['uid' => $user->getUID(), 'displayName' => $user->getDisplayName()];
				if (!in_array($hit, $out, true)) {
					array_unshift($out, $hit);
				}
			}
		}

		return array_slice($out, 0, $limit);
	}

	/**
	 * @return list<array{gid:string, displayName:string}>
	 */
	public function searchGroups(string $term, int $limit = 20): array
	{
		$out = [];
		foreach ($this->groupManager->search($term, $limit) as $group) {
			$out[] = ['gid' => $group->getGID(), 'displayName' => $group->getDisplayName()];
		}

		return $out;
	}
}
