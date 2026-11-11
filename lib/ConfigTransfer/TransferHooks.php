<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * ContractCheck wiring for the shared transfer engine.
 *
 * All real config lives in the `cc_settings` singleton JSON doc, so EVERY
 * transferred key is store-backed class 'mirrored' and the whole batch is
 * written through SettingsService::save() — the app's own validator +
 * normaliser + CAS version guard (MH-12 / NN-08), never a raw doc rewrite.
 */

namespace OCA\ContractCheck\ConfigTransfer;

use OCA\ContractCheck\Exception\ForbiddenException;
use OCA\ContractCheck\Exception\ValidationException;
use OCA\ContractCheck\Service\AccessService;
use OCA\ContractCheck\Service\SettingsService;
use OCA\ContractCheck\Service\UpgradeBackupService;
use Psr\Log\LoggerInterface;

final class TransferHooks implements ConfigTransferHooks
{
	/** Manifest key => decode-kind for the settings-doc fragment. */
	private const JSON_LIST_KEYS = ['allowedGroups', 'managerGroups', 'costViewerGroups', 'defaultLeadDays'];
	private const JSON_MAP_KEYS = ['costCenterBudgets'];
	private const STRING_KEYS = ['orgName'];

	public function __construct(
		private readonly SettingsService $settings,
		private readonly AccessService $access,
		private readonly UpgradeBackupService $backups,
		private readonly LoggerInterface $logger,
	) {
	}

	public function requireTransferAdmin(string $uid): void
	{
		// The app's door-admin IS manager role — the transfer API collapses
		// this denial into the uniform NOT_FOUND payload either way.
		if (!$this->access->isManager($uid)) {
			throw new ForbiddenException('Not a ContractCheck manager');
		}
	}

	public function validateValue(string $key, string $value): ?string
	{
		try {
			$this->settings->validateSettingsInput($this->decodeFragment($key, $value));
			return null;
		} catch (ValidationException $e) {
			$code = $e->getErrorCode();
			return $code !== '' ? $code : 'SETTINGS_INVALID';
		} catch (\JsonException) {
			return 'CC_INVALID_JSON';
		} catch (\Throwable) {
			return 'SETTINGS_INVALID';
		}
	}

	public function normalizeValue(string $key, string $value): string
	{
		try {
			$normalized = $this->settings->normalizeSettingsInput($this->decodeFragment($key, $value));
			return $this->encodeField($key, $normalized[$key] ?? null);
		} catch (\Throwable) {
			return $value;
		}
	}

	public function validateMirrored(array $keyValues): ?string
	{
		// The batch is the unit — one settings doc, all-or-nothing. Every
		// key already passed validateValue; re-run the batch so cross-key
		// drift between plan and apply still fails loudly, never silently.
		try {
			$this->settings->validateSettingsInput($this->decodeAll($keyValues));
			return null;
		} catch (ValidationException $e) {
			$code = $e->getErrorCode();
			return $code !== '' ? $code : 'SETTINGS_INVALID';
		} catch (\JsonException) {
			return 'CC_INVALID_JSON';
		} catch (\Throwable) {
			return 'SETTINGS_INVALID';
		}
	}

	public function writeMirrored(array $keyValues): void
	{
		if ($keyValues === []) {
			return;
		}
		// ONE atomic overlay: the decoded fragment merges onto the current
		// doc through the app's own save() — validate() + normalize() + the
		// guarded UPDATE on `version` all apply natively. A stale-version
		// conflict (concurrent admin save) throws ConflictException and the
		// engine's apply transaction rolls the WHOLE import back — never a
		// half-written settings doc. No blind retry: a conflict means a
		// concurrent save won and the transaction is already dead.
		$input = $this->decodeAll($keyValues);
		$current = $this->settings->get();
		$this->settings->save($input, $current['version']);
	}

	public function securityRelevantKeys(): array
	{
		return ['allowedGroups', 'managerGroups', 'costViewerGroups'];
	}

	public function createSafetySnapshot(string $reason): ?string
	{
		try {
			$result = $this->backups->createSnapshot($reason);
			return (string)($result['id'] ?? '');
		} catch (\Throwable) {
			if ($this->backups->hasDataToBackup()) {
				throw new \RuntimeException('TRANSFER_SNAPSHOT_FAILED');
			}
			return null;
		}
	}

	public function settingsSectionForKey(string $key): ?string
	{
		return match ($key) {
			'allowedGroups', 'managerGroups', 'costViewerGroups' => 'access',
			'defaultLeadDays', 'orgName', 'costCenterBudgets' => 'deadlines',
			default => null,
		};
	}

	public function auditEvent(string $action, string $actor, array $details): void
	{
		// The app's AuditService is per-contract hash-chained — transfer
		// events have no contract row, so they go to the app log instead
		// (never silent, spec MH-08).
		$this->logger->info('contractcheck: config transfer ' . $action, [
			'app' => 'contractcheck',
			'actor' => $actor === '' ? 'cli' : $actor,
			'details' => $details,
		]);
	}

	// ── per-key decoding — the wire form mirrors what the engine reads:
	//    scalar fields raw, list/map fields as JSON strings ──

	/**
	 * @param array<string,string> $keyValues
	 * @return array<string,mixed>
	 */
	private function decodeAll(array $keyValues): array
	{
		$input = [];
		foreach ($keyValues as $key => $value) {
			$input += $this->decodeFragment((string)$key, (string)$value);
		}
		return $input;
	}

	/**
	 * @return array<string,mixed> one settings-doc field
	 */
	private function decodeFragment(string $key, string $value): array
	{
		if (in_array($key, self::JSON_LIST_KEYS, true)) {
			$decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
			if (!is_array($decoded) || !array_is_list($decoded)) {
				throw new ValidationException('SETTINGS_INVALID', 'Invalid list', [$key => 'must be a list']);
			}
			return [$key => $decoded];
		}
		if (in_array($key, self::JSON_MAP_KEYS, true)) {
			$decoded = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
			if (!is_array($decoded)) {
				throw new ValidationException('SETTINGS_INVALID', 'Invalid map', [$key => 'must be a map']);
			}
			return [$key => $decoded];
		}
		if (in_array($key, self::STRING_KEYS, true)) {
			return [$key => $value];
		}
		throw new ValidationException('CC_UNKNOWN_KEY', 'Unknown transfer key', [$key => 'unknown setting']);
	}

	/**
	 * Re-encode a normalized doc field to its wire form — the exact shape
	 * the engine exports from the settings doc (scalars raw, else JSON).
	 */
	private function encodeField(string $key, mixed $value): string
	{
		if (in_array($key, self::STRING_KEYS, true)) {
			return is_string($value) ? $value : '';
		}
		return (string)json_encode($value, JSON_UNESCAPED_UNICODE);
	}
}
