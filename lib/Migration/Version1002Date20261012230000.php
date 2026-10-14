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
 * Two-phase reminder dedup.
 *
 * A dedup row claimed-then-delivered had a fatal window: a PHP crash
 * between insert and notification left the slot claimed forever and the
 * reminder was never sent. `pending = 1` marks a claimed-not-confirmed
 * row; the scanner reclaims pending rows older than the stale threshold,
 * so even a SIGKILL degrades to at-least-once delivery instead of a lost
 * reminder. Existing rows default to 0 (= confirmed sent).
 */
class Version1002Date20261012230000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if ($schema->hasTable('cc_reminders')) {
			$t = $schema->getTable('cc_reminders');
			if (!$t->hasColumn('pending')) {
				$t->addColumn('pending', 'smallint', ['notnull' => true, 'default' => 0]);
			}
		}

		// Oracle treats '' as NULL — a notnull column may not carry an
		// empty-string default. prev_hash is always explicitly written
		// ('genesis' or a chain hash); drop the default so an omitted
		// insert fails loudly instead of storing a fake genesis.
		if ($schema->hasTable('cc_events')) {
			$t = $schema->getTable('cc_events');
			if ($t->hasColumn('prev_hash')) {
				$t->changeColumn('prev_hash', ['default' => null, 'notnull' => true]);
			}
		}

		return $schema;
	}
}
