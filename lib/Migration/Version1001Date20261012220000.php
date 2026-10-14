<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 Alexander Mäule <info@software-by-design.de>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\ContractCheck\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Column-default hardening.
 *
 * OCP\Entity::setter() marks a field as "updated" only when the assigned
 * value differs from the PHP property default. Writes that intentionally
 * set a column to its PHP default (revision 0 on create, lead_day 0, a
 * 0-cent cost) therefore leave the column out of the INSERT entirely —
 * which crashes on strict SQL modes when the column has no DB default.
 * Defaults matching the entity property defaults close that trap for
 * every at-risk scalar column.
 */
class Version1001Date20261012220000 extends SimpleMigrationStep
{
	private const DEFAULTS = [
		'cc_revisions' => [
			'revision' => 0,
		],
		'cc_events' => [
			'seq' => 0,
		],
		'cc_reminders' => [
			'lead_day' => 0,
		],
		'cc_costs' => [
			'amount_cents' => 0,
		],
	];

	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		foreach (self::DEFAULTS as $table => $columns) {
			if (!$schema->hasTable($table)) {
				continue;
			}
			$t = $schema->getTable($table);
			foreach ($columns as $column => $default) {
				if ($t->hasColumn($column)) {
					$t->changeColumn($column, ['default' => $default, 'notnull' => true]);
				}
			}
		}

		return $schema;
	}
}
