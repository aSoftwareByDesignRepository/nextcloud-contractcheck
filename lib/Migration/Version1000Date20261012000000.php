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
 * Initial ContractCheck schema (cc_* — all table/index names ≤ 27 chars
 * for Oracle/MySQL/PostgreSQL portability, no FKs: family convention).
 *
 * Calendar dates are stored as real DATE columns; wall-clock moments as
 * DATETIME. The deadline engine never touches these directly — it works
 * on 'Y-m-d' strings produced by the entities.
 */
class Version1000Date20261012000000 extends SimpleMigrationStep
{
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper
	{
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();

		if (!$schema->hasTable('cc_contracts')) {
			$t = $schema->createTable('cc_contracts');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('ref_no', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('title', 'string', ['notnull' => true, 'length' => 255]);
			$t->addColumn('type', 'string', ['notnull' => true, 'length' => 32]);
			$t->addColumn('partner_id', 'bigint', ['notnull' => false]);
			$t->addColumn('owner_uid', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('scope', 'string', ['notnull' => true, 'length' => 16, 'default' => 'org']);
			$t->addColumn('status', 'string', ['notnull' => true, 'length' => 16, 'default' => 'draft']);
			$t->addColumn('signed_at', 'date', ['notnull' => false]);
			$t->addColumn('start_date', 'date', ['notnull' => false]);
			$t->addColumn('end_date', 'date', ['notnull' => false]);
			$t->addColumn('term_kind', 'string', ['notnull' => true, 'length' => 16, 'default' => 'fixed']);
			$t->addColumn('renewal_value', 'integer', ['notnull' => false]);
			$t->addColumn('renewal_unit', 'string', ['notnull' => false, 'length' => 8]);
			$t->addColumn('notice_value', 'integer', ['notnull' => false]);
			$t->addColumn('notice_unit', 'string', ['notnull' => false, 'length' => 8]);
			$t->addColumn('notice_anchor', 'string', ['notnull' => false, 'length' => 16]);
			$t->addColumn('lead_days_json', 'text', ['notnull' => false]);
			$t->addColumn('reminder_channel', 'string', ['notnull' => true, 'length' => 16, 'default' => 'notify']);
			$t->addColumn('cost_center', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('tags_json', 'text', ['notnull' => false]);
			$t->addColumn('notes', 'text', ['notnull' => false]);
			$t->addColumn('terminated_at', 'date', ['notnull' => false]);
			$t->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cc_contracts_pk');
			$t->addIndex(['status', 'scope'], 'cc_con_status_scope_idx');
			$t->addIndex(['owner_uid'], 'cc_con_owner_idx');
			$t->addIndex(['partner_id'], 'cc_con_partner_idx');
			$t->addIndex(['end_date'], 'cc_con_end_idx');
			$t->addIndex(['ref_no'], 'cc_con_ref_idx');
		}

		if (!$schema->hasTable('cc_parties')) {
			$t = $schema->createTable('cc_parties');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('name', 'string', ['notnull' => true, 'length' => 255]);
			$t->addColumn('contact_name', 'string', ['notnull' => false, 'length' => 255]);
			$t->addColumn('contact_email', 'string', ['notnull' => false, 'length' => 255]);
			$t->addColumn('contact_phone', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('contact_uid', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('address', 'text', ['notnull' => false]);
			$t->addColumn('notes', 'text', ['notnull' => false]);
			$t->addColumn('archived', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cc_parties_pk');
			$t->addIndex(['archived', 'name'], 'cc_par_arch_name_idx');
		}

		if (!$schema->hasTable('cc_costs')) {
			$t = $schema->createTable('cc_costs');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('contract_id', 'bigint', ['notnull' => true]);
			$t->addColumn('label', 'string', ['notnull' => true, 'length' => 255]);
			$t->addColumn('amount_cents', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('currency', 'string', ['notnull' => true, 'length' => 3, 'default' => 'EUR']);
			$t->addColumn('interval', 'string', ['notnull' => true, 'length' => 16, 'default' => 'yearly']);
			$t->addColumn('valid_from', 'date', ['notnull' => false]);
			$t->addColumn('valid_to', 'date', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'cc_costs_pk');
			$t->addIndex(['contract_id'], 'cc_cost_contract_idx');
		}

		if (!$schema->hasTable('cc_documents')) {
			$t = $schema->createTable('cc_documents');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('contract_id', 'bigint', ['notnull' => true]);
			$t->addColumn('file_id', 'bigint', ['notnull' => true]);
			$t->addColumn('kind', 'string', ['notnull' => true, 'length' => 24, 'default' => 'contract']);
			$t->addColumn('added_by', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('added_at', 'datetime', ['notnull' => true]);
			$t->addColumn('missing_since', 'datetime', ['notnull' => false]);
			$t->setPrimaryKey(['id'], 'cc_documents_pk');
			$t->addIndex(['contract_id'], 'cc_doc_contract_idx');
			$t->addIndex(['file_id'], 'cc_doc_file_idx');
			$t->addUniqueIndex(['contract_id', 'file_id'], 'cc_doc_con_file_uq');
		}

		if (!$schema->hasTable('cc_events')) {
			$t = $schema->createTable('cc_events');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('seq', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('prev_hash', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('contract_id', 'bigint', ['notnull' => true]);
			$t->addColumn('event', 'string', ['notnull' => true, 'length' => 32]);
			$t->addColumn('actor_uid', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('payload_json', 'text', ['notnull' => false]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cc_events_pk');
			$t->addUniqueIndex(['contract_id', 'seq'], 'cc_evt_con_seq_uq');
			$t->addIndex(['event', 'created_at'], 'cc_evt_type_time_idx');
			$t->addIndex(['created_at'], 'cc_evt_time_idx');
		}

		if (!$schema->hasTable('cc_reminders')) {
			$t = $schema->createTable('cc_reminders');
			$t->addColumn('contract_id', 'bigint', ['notnull' => true]);
			$t->addColumn('deadline_instance_id', 'string', ['notnull' => true, 'length' => 64]);
			$t->addColumn('lead_day', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('sent_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['contract_id', 'deadline_instance_id', 'lead_day'], 'cc_rem_pk');
		}

		if (!$schema->hasTable('cc_revisions')) {
			$t = $schema->createTable('cc_revisions');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('contract_id', 'bigint', ['notnull' => true]);
			$t->addColumn('revision', 'bigint', ['notnull' => true, 'default' => 0]);
			$t->addColumn('snapshot_json', 'text', ['notnull' => true]);
			$t->addColumn('actor_uid', 'string', ['notnull' => false, 'length' => 64]);
			$t->addColumn('created_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cc_revisions_pk');
			$t->addUniqueIndex(['contract_id', 'revision'], 'cc_rev_con_rev_uq');
			$t->addIndex(['contract_id', 'id'], 'cc_rev_con_id_idx');
		}

		if (!$schema->hasTable('cc_settings')) {
			$t = $schema->createTable('cc_settings');
			$t->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
			$t->addColumn('version', 'integer', ['notnull' => true, 'default' => 0]);
			$t->addColumn('payload', 'text', ['notnull' => true]);
			$t->addColumn('updated_at', 'datetime', ['notnull' => true]);
			$t->setPrimaryKey(['id'], 'cc_settings_pk');
		}

		return $schema;
	}
}
