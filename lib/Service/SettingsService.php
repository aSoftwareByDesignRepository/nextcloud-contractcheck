<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Service;

use OCA\ContractCheck\Db\SettingsStore;
use OCA\ContractCheck\Exception\ConflictException;
use OCA\ContractCheck\Exception\ValidationException;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\Security\ISecureRandom;

/**
 * Versioned app settings (cc_settings singleton) + per-user ICS feed tokens
 * in appconfig.
 *
 * Settings payload (JSON):
 *  - allowedGroups    list<string> — access door; empty = all users
 *  - managerGroups    list<string> — contract managers (default ['admin'])
 *  - costViewerGroups list<string> — extra cost visibility
 *  - defaultLeadDays  list<int>    — default reminder leads for new contracts
 *  - orgName          string       — ICS calendar display name
 */
class SettingsService
{
	private const APP = 'contractcheck';
	private const FEED_TOKEN_PREFIX = 'feed_token_';
	private const FEED_TOKEN_LEN = 32;

	/** @var array{version:int,payload:array<string,mixed>}|null */
	private ?array $cache = null;

	public function __construct(
		private readonly SettingsStore $store,
		private readonly IConfig $config,
		private readonly ISecureRandom $secureRandom,
		private readonly IGroupManager $groupManager,
		private readonly Clock $clock,
	) {
	}

	/** @return array{version:int,settings:array<string,mixed>} */
	public function get(): array
	{
		$row = $this->load();

		return ['version' => $row['version'], 'settings' => $row['payload']];
	}

	/**
	 * Guarded settings write — $expectedVersion must match the stored
	 * version or the save fails closed (fail-closed CAS, family rule §3).
	 *
	 * @param array<string, mixed> $settings
	 */
	public function save(array $settings, int $expectedVersion): void
	{
		$this->validate($settings);
		$current = $this->load();
		if ($current['version'] !== $expectedVersion) {
			throw new ConflictException('Settings changed in the meantime. Reload and try again.');
		}
		$payload = json_encode($this->normalize($settings), JSON_UNESCAPED_UNICODE);
		if (!$this->store->save($payload === false ? '{}' : $payload, $expectedVersion, $this->clock->now())) {
			throw new ConflictException('Settings changed in the meantime. Reload and try again.');
		}
		$this->cache = null;
	}

	/**
	 * Planned yearly budget per cost center (int cents) — NH-05 light
	 * budgeting. Empty map when unconfigured.
	 * @return array<string, int>
	 */
	public function getCostCenterBudgets(): array
	{
		$b = $this->load()['payload']['costCenterBudgets'] ?? [];
		return is_array($b) ? $b : [];
	}

	/** @return list<string> */
	public function getAllowedGroups(): array
	{
		return $this->strList('allowedGroups');
	}

	/** @return list<string> */
	public function getManagerGroups(): array
	{
		$v = $this->strList('managerGroups');

		return $v === [] ? ['admin'] : $v;
	}

	/** @return list<string> */
	public function getCostViewerGroups(): array
	{
		return $this->strList('costViewerGroups');
	}

	/** @return list<int> */
	public function getDefaultLeadDays(): array
	{
		$v = $this->load()['payload']['defaultLeadDays'] ?? null;
		if (!is_array($v) || $v === []) {
			return [60, 30];
		}
		$out = [];
		foreach ($v as $d) {
			if (is_int($d) && $d >= 0 && $d <= 3650) {
				$out[] = $d;
			}
		}

		return $out === [] ? [60, 30] : array_values(array_unique($out));
	}

	public function getOrgName(): string
	{
		$v = $this->load()['payload']['orgName'] ?? '';

		return is_string($v) && $v !== '' ? $v : 'ContractCheck';
	}

	// ---- ICS feed tokens (appconfig, per user, revocable) ----

	public function mintFeedToken(string $uid): string
	{
		$token = $this->secureRandom->generate(self::FEED_TOKEN_LEN, ISecureRandom::CHAR_ALPHANUMERIC);
		// Store the hash only — a DB/config leak must not mint feed access.
		$this->config->setAppValue(self::APP, self::FEED_TOKEN_PREFIX . $uid, hash('sha256', $token));

		return $token;
	}

	public function revokeFeedToken(string $uid): void
	{
		$this->config->deleteAppValue(self::APP, self::FEED_TOKEN_PREFIX . $uid);
	}

	public function hasFeedToken(string $uid): bool
	{
		return $this->config->getAppValue(self::APP, self::FEED_TOKEN_PREFIX . $uid, '') !== '';
	}

	/**
	 * Resolve a presented token to its owning uid.
	 * Iterates the small feed_token_* keyspace (one entry per user with a
	 * token) — intentionally no lookup index, tokens are secrets not ids.
	 */
	public function uidForFeedToken(string $token): ?string
	{
		if ($token === '' || strlen($token) > 128) {
			return null;
		}
		$hash = hash('sha256', $token);
		foreach ($this->config->getAppKeys(self::APP) as $key) {
			if (!str_starts_with($key, self::FEED_TOKEN_PREFIX)) {
				continue;
			}
			$stored = $this->config->getAppValue(self::APP, $key, '');
			if ($stored !== '' && hash_equals($stored, $hash)) {
				return substr($key, strlen(self::FEED_TOKEN_PREFIX));
			}
		}

		return null;
	}

	// ---- internals ----

	/** @return array{version:int,payload:array<string,mixed>} */
	private function load(): array
	{
		if ($this->cache !== null) {
			return $this->cache;
		}
		$row = $this->store->load();
		$payload = [];
		if ($row !== null) {
			$decoded = json_decode($row['payload'], true);
			if (is_array($decoded)) {
				$payload = $decoded;
			}
		}
		$version = $row['version'] ?? 0;
		$payload = $this->normalize($payload);
		$this->cache = ['version' => $version, 'payload' => $payload];

		return $this->cache;
	}

	/** @return list<string> */
	private function strList(string $key): array
	{
		$v = $this->load()['payload'][$key] ?? [];
		if (!is_array($v)) {
			return [];
		}

		return array_values(array_filter($v, static fn ($x) => is_string($x) && $x !== ''));
	}

	private const KNOWN_KEYS = [
		'allowedGroups', 'managerGroups', 'costViewerGroups',
		'defaultLeadDays', 'orgName', 'costCenterBudgets',
	];

	/**
	 * @param array<string, mixed> $in
	 */
	private function validate(array $in): void
	{
		$fields = [];
		foreach ($in as $k => $_) {
			if (!in_array($k, self::KNOWN_KEYS, true)) {
				$fields[is_string($k) ? $k : '?'] = 'unknown setting';
			}
		}
		foreach (['allowedGroups', 'managerGroups', 'costViewerGroups'] as $key) {
			if (isset($in[$key]) && !is_array($in[$key])) {
				$fields[$key] = 'must be a list of group ids';
			} elseif (isset($in[$key])) {
				foreach ($in[$key] as $g) {
					if (!is_string($g) || $g === '' || strlen($g) > 64) {
						$fields[$key] = 'must be a list of group ids';
						break;
					}
					if (!$this->groupManager->groupExists($g)) {
						$fields[$key] = 'unknown group: ' . $g;
						break;
					}
				}
			}
		}
		// Lockout guard: a non-empty managerGroups where every listed group
		// has zero members leaves nobody able to manage the app. An empty
		// list is fine — it falls back to the admin default.
		if (isset($in['managerGroups']) && is_array($in['managerGroups'])
			&& $in['managerGroups'] !== [] && !isset($fields['managerGroups'])) {
			$hasMembers = false;
			foreach ($in['managerGroups'] as $g) {
				$group = is_string($g) ? $this->groupManager->get($g) : null;
				if ($group !== null && $group->getUsers() !== []) {
					$hasMembers = true;
					break;
				}
			}
			if (!$hasMembers) {
				$fields['managerGroups'] = 'at least one listed group must have members';
			}
		}
		if (isset($in['defaultLeadDays'])) {
			if (!is_array($in['defaultLeadDays'])) {
				$fields['defaultLeadDays'] = 'must be a list of day counts';
			} elseif (count($in['defaultLeadDays']) > ContractValidator::MAX_LEAD_DAYS) {
				$fields['defaultLeadDays'] = 'at most ' . ContractValidator::MAX_LEAD_DAYS . ' lead days';
			} else {
				foreach ($in['defaultLeadDays'] as $d) {
					if (!is_int($d) || $d < 0 || $d > 3650) {
						$fields['defaultLeadDays'] = 'days must be integers between 0 and 3650';
						break;
					}
				}
			}
		}
		if (isset($in['orgName']) && (!is_string($in['orgName']) || strlen($in['orgName']) > 120)) {
			$fields['orgName'] = 'must be a short name';
		}
		if (isset($in['costCenterBudgets'])) {
			if (!is_array($in['costCenterBudgets']) || count($in['costCenterBudgets']) > 200) {
				$fields['costCenterBudgets'] = 'must be a map of cost center to yearly amount (cents)';
			} else {
				foreach ($in['costCenterBudgets'] as $k => $v) {
					if (!is_string($k) || $k === '' || strlen($k) > 64 || !is_int($v) || $v < 0 || $v > 1_000_000_000_000) {
						$fields['costCenterBudgets'] = 'must be a map of cost center to yearly amount (cents)';
						break;
					}
				}
			}
		}
		if ($fields !== []) {
			throw new ValidationException('SETTINGS_INVALID', 'Invalid settings', $fields);
		}
	}

	/**
	 * @param array<string, mixed> $in
	 * @return array<string, mixed>
	 */
	private function normalize(array $in): array
	{
		$out = $this->cache !== null ? $this->cache['payload'] : [
			'allowedGroups' => [],
			'managerGroups' => ['admin'],
			'costViewerGroups' => [],
			'defaultLeadDays' => [60, 30],
			'orgName' => '',
			'costCenterBudgets' => [],
		];
		foreach (['allowedGroups', 'managerGroups', 'costViewerGroups'] as $key) {
			if (isset($in[$key]) && is_array($in[$key])) {
				$out[$key] = array_values(array_unique(array_filter($in[$key], 'is_string')));
			}
		}
		if (isset($in['defaultLeadDays']) && is_array($in['defaultLeadDays'])) {
			$days = array_values(array_unique(array_filter($in['defaultLeadDays'], 'is_int')));
			sort($days, SORT_NUMERIC);
			$out['defaultLeadDays'] = $days;
		}
		if (isset($in['orgName'])) {
			$out['orgName'] = is_string($in['orgName']) ? trim($in['orgName']) : '';
		}
		if (isset($in['costCenterBudgets']) && is_array($in['costCenterBudgets'])) {
			$clean = [];
			foreach ($in['costCenterBudgets'] as $k => $v) {
				if (is_string($k) && $k !== '' && is_int($v)) {
					$clean[$k] = $v;
				}
			}
			ksort($clean);
			$out['costCenterBudgets'] = $clean;
		}

		return $out;
	}
}
