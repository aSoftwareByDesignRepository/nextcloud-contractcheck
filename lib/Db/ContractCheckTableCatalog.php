<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Db;

final class ContractCheckTableCatalog
{
	/**
	 * Every table this app has ever created (uninstall DROP IF EXISTS target list).
	 *
	 * @var list<string>
	 */
	public const TABLES = [
		'cc_contracts',
		'cc_costs',
		'cc_documents',
		'cc_events',
		'cc_parties',
		'cc_reminders',
		'cc_revisions',
		'cc_settings',
	];

	/**
	 * Tables intentionally dropped by later migrations (none yet).
	 *
	 * @var list<string>
	 */
	public const DROPPED_LEGACY_TABLES = [];

	/**
	 * Tables that must exist after current migrations.
	 *
	 * @return list<string>
	 */
	public static function requiredTables(): array
	{
		return array_values(array_diff(self::TABLES, self::DROPPED_LEGACY_TABLES));
	}
}
