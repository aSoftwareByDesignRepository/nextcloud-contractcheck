<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * ContractCheck transfer manifest — every appconfig key and every cc_*
 * table classified exactly once
 * (gate: scripts/check-config-transfer-manifest.py).
 *
 * Real config lives in the `cc_settings` singleton JSON document
 * (id=1, column `payload`, CAS-versioned behind SettingsService) — NOT in
 * appconfig. Each transferable top-level field of that document transfers
 * as a virtual store-backed key (`'store' => [json spec]`, manifest key
 * name IS the json_key): the engine reads values from the doc row, writes
 * route through TransferHooks::writeMirrored → SettingsService::save so
 * validate() + normalize() + the CAS version guard apply natively.
 * `cc_settings` itself is declared `internal` so raw rows never move.
 */

namespace OCA\ContractCheck\ConfigTransfer;

final class TransferManifest extends AbstractTransferManifest
{
	private const SCHEMA_VERSION = 1;

	private const SETTINGS_STORE = [
		'table' => 'cc_settings',
		'kind' => 'json',
		'row_id' => 1,
		'json_col' => 'payload',
	];

	public function schemaVersion(): int
	{
		return self::SCHEMA_VERSION;
	}

	/** @return array<string,array> */
	public function keys(): array
	{
		return self::normaliseKeys([
			// ── access door — GID allowlists inside the settings doc ──
			// unresolved 'skip' (default): a ghost gid skips the whole key —
			// the target door stays closed rather than half-open.
			'allowedGroups' => ['class' => 'mirrored', 'security' => true, 'section' => 'access',
				'refs' => ['kind' => 'gid_list_json'],
				'store' => self::SETTINGS_STORE + ['json_key' => 'allowedGroups']],
			// Widening the manager/cost-viewer groups is a privilege grant →
			// highlighted in the security section of the report.
			'managerGroups' => ['class' => 'mirrored', 'security' => true, 'section' => 'access',
				'refs' => ['kind' => 'gid_list_json'],
				'store' => self::SETTINGS_STORE + ['json_key' => 'managerGroups']],
			'costViewerGroups' => ['class' => 'mirrored', 'security' => true, 'section' => 'access',
				'refs' => ['kind' => 'gid_list_json'],
				'store' => self::SETTINGS_STORE + ['json_key' => 'costViewerGroups']],
			// ── deadlines / display defaults ──
			'defaultLeadDays' => ['class' => 'mirrored', 'section' => 'deadlines',
				'store' => self::SETTINGS_STORE + ['json_key' => 'defaultLeadDays']],
			'orgName' => ['class' => 'mirrored', 'section' => 'deadlines',
				'store' => self::SETTINGS_STORE + ['json_key' => 'orgName']],
			'costCenterBudgets' => ['class' => 'mirrored', 'section' => 'deadlines',
				'store' => self::SETTINGS_STORE + ['json_key' => 'costCenterBudgets']],

			// ── internal bookkeeping — never transferred ──
			'upgrade_backup_last_snapshot_id' => 'internal',
			'upgrade_backup_max_snapshots' => 'internal',
			'uninstall_repair_pass' => 'internal',
			'transfer_receipts' => 'internal',
			'transfer_last_import_at' => 'internal',
			'transfer_job_state' => 'internal',
			// Dynamic/prefix key families — prefixes, not keys; members are
			// dynamic and never transferred by definition:
			//   'feed_token_*'   per-user sha256 ICS feed tokens — instance
			//                    secrets; a token minted on source is dead
			//                    (and dangerous) on any target.
		]);
	}

	/** @return array<string,array> */
	public function tables(): array
	{
		return self::normaliseTables([
			// ══ settings doc backing — moves via the virtual keys above ══
			'cc_settings' => ['class' => 'internal'],

			// ══ catalog — contract partners/vendors ══
			// `name` is the identity an operator dedups partners by (the
			// table has no code/number column). contact_uid is a nullable
			// person reference — empty stays NULL, a ghost skips the row.
			'cc_parties' => ['class' => 'catalog', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['name'],
				'uid_ref' => ['contact_uid'], 'uid_ref_nullable' => ['contact_uid']],

			// ══ business — the contract register ══
			// ref_no is nullable AND non-unique, so it cannot anchor
			// identity alone — the honest identity of a register row is the
			// business tuple (partner, ref_no, title, start_date): a
			// contract re-typed on the target still matches and updates,
			// while created_at deliberately stays OUT (test and prod copies
			// of the same contract have different creation timestamps).
			'cc_contracts' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['partner_id', 'ref_no', 'title', 'start_date'],
				'fk' => ['partner_id' => 'cc_parties'],
				'depends_on' => ['cc_parties'],
				'uid_ref' => ['owner_uid', 'created_by'],
				'uid_ref_nullable' => ['owner_uid']],
			// A cost line is identified inside its contract by label +
			// first validity date (nullable → IS NULL match).
			'cc_costs' => ['class' => 'business', 'strategy' => 'upsert-natural-key',
				'natural_key' => ['contract_id', 'label', 'valid_from'],
				'fk' => ['contract_id' => 'cc_contracts'],
				'depends_on' => ['cc_contracts']],

			// ══ NEVER TRANSFER — instance-bound or rebuildable state ══
			// file_id is an NC fileid — instance-bound AND NOT NULL: the
			// row's only substance is a link to a file that does not exist
			// on the target (a remapped id would point at a WRONG file —
			// worse than no row). Users re-link documents on the target;
			// same call as maintenancecheck mn_equip_docs.
			'cc_documents' => ['class' => 'internal'],
			// Reminder dedup ledger (sent/claimed slots) — the deadline
			// engine recomputes instances on the target; carrying rows
			// would suppress or duplicate notifications.
			'cc_reminders' => ['class' => 'internal'],

			// ══ audit trail — logs, never transferred ══
			// Hash-chained per-contract event journal.
			'cc_events' => ['class' => 'log'],
			// Snapshot chain keyed by (contract_id, revision) — re-created
			// on the target by the next edit; chain stays source-bound.
			'cc_revisions' => ['class' => 'log'],
		]);
	}
}
