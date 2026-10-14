<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCA\ContractCheck\Db\Party;
use OCA\ContractCheck\Db\PartyMapper;
use OCA\ContractCheck\Exception\ForbiddenException;
use OCA\ContractCheck\Exception\NotFoundException;
use OCA\ContractCheck\Exception\ValidationException;
use OCP\IUserManager;

/**
 * Party (counterparty/vendor) directory. Parties are org-shared records —
 * contract scoping applies to contracts, not to the directory itself
 * (a party name is not contract data; contract links stay scoped).
 */
class PartyService
{
	public function __construct(
		private readonly PartyMapper $parties,
		private readonly AccessService $access,
		private readonly IUserManager $userManager,
		private readonly Clock $clock,
	) {
	}

	/** @return list<Party> */
	public function listForUser(string $uid, ?string $search, bool $includeArchived): array
	{
		return $this->parties->search($search, $includeArchived);
	}

	public function get(int $id): Party
	{
		return $this->parties->find($id);
	}

	/** @param array<string, mixed> $data */
	public function create(array $data, string $actorUid): Party
	{
		$this->requireManager($actorUid);
		$v = self::validate($data);
		if ($v['contactUid'] !== null && !$this->userManager->userExists($v['contactUid'])) {
			throw new NotFoundException('Contact user not found');
		}

		$now = $this->clock->now();
		$p = new Party();
		$this->apply($p, $v);
		$p->setArchived(0);
		$p->setCreatedAt($now);
		$p->setUpdatedAt($now);

		return $this->parties->insert($p);
	}

	/** @param array<string, mixed> $data */
	public function update(int $id, array $data, string $actorUid): Party
	{
		$this->requireManager($actorUid);
		$p = $this->parties->find($id);
		$v = self::validate($data);
		if ($v['contactUid'] !== null && !$this->userManager->userExists($v['contactUid'])) {
			throw new NotFoundException('Contact user not found');
		}
		$this->apply($p, $v);
		$p->setUpdatedAt($this->clock->now());

		return $this->parties->update($p);
	}

	public function archive(int $id, string $actorUid): Party
	{
		return $this->setArchived($id, true, $actorUid);
	}

	public function unarchive(int $id, string $actorUid): Party
	{
		return $this->setArchived($id, false, $actorUid);
	}

	private function setArchived(int $id, bool $archived, string $actorUid): Party
	{
		$this->requireManager($actorUid);
		$p = $this->parties->find($id);
		$p->setArchived($archived ? 1 : 0);
		$p->setUpdatedAt($this->clock->now());

		return $this->parties->update($p);
	}

	private function requireManager(string $uid): void
	{
		if (!$this->access->canEdit($uid)) {
			throw new ForbiddenException('Manager role required');
		}
	}

	/**
	 * @param array<string, mixed> $in
	 * @return array<string, mixed>
	 */
	private static function validate(array $in): array
	{
		$fields = [];
		$name = is_string($in['name'] ?? null) ? mb_substr(trim($in['name']), 0, 255) : '';
		if ($name === '') {
			$fields['name'] = 'required';
		}
		$email = self::nstr($in['contactEmail'] ?? null, 255);
		if ($email !== null && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
			$fields['contactEmail'] = 'invalid email';
		}
		$out = [
			'name' => $name,
			'contactName' => self::nstr($in['contactName'] ?? null, 255),
			'contactEmail' => $email,
			'contactPhone' => self::nstr($in['contactPhone'] ?? null, 64),
			'contactUid' => self::nstr($in['contactUid'] ?? null, 64),
			'address' => self::nstr($in['address'] ?? null, 4000),
			'notes' => self::nstr($in['notes'] ?? null, 10000),
		];
		if ($fields !== []) {
			throw new ValidationException('VALIDATION', 'Please check the highlighted fields.', $fields);
		}

		return $out;
	}

	private static function nstr(mixed $v, int $max): ?string
	{
		if (!is_string($v)) {
			return null;
		}
		$v = mb_substr(trim($v), 0, $max);

		return $v === '' ? null : $v;
	}

	/** @param array<string, mixed> $v */
	private function apply(Party $p, array $v): void
	{
		$p->setName($v['name']);
		$p->setContactName($v['contactName']);
		$p->setContactEmail($v['contactEmail']);
		$p->setContactPhone($v['contactPhone']);
		$p->setContactUid($v['contactUid']);
		$p->setAddress($v['address']);
		$p->setNotes($v['notes']);
	}
}
