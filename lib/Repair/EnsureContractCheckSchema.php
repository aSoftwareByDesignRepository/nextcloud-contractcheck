<?php

declare(strict_types=1);

namespace OCA\ContractCheck\Repair;

use OCA\ContractCheck\Db\ContractCheckTableCatalog;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Idempotent schema presence check after install / post-migration.
 */
final class EnsureContractCheckSchema implements IRepairStep
{
	public const APP_ID = 'contractcheck';

	public function __construct(
		private readonly IDBConnection $connection,
		private readonly IConfig $config,
	) {
	}

	public function getName(): string
	{
		return 'Ensure contractcheck core tables exist';
	}

	public function run(IOutput $output): void
	{
		$this->config->deleteAppValue(self::APP_ID, UninstallDropTables::REPAIR_PASS_KEY);
		$missing = [];
		foreach (ContractCheckTableCatalog::requiredTables() as $table) {
			if (!$this->connection->tableExists($table)) {
				$missing[] = $table;
			}
		}
		if ($missing !== []) {
			$output->warning('contractcheck missing tables: ' . implode(', ', $missing) . ' — run migrations');
		} else {
			$output->info('contractcheck schema OK');
		}
	}
}
