<?php

declare(strict_types=1);

/**
 * Critical-path mutation runner for ContractCheck.
 *
 * Applies known-bad source mutations in place, runs targeted PHPUnit, EXPECTS
 * FAILURE, restores the file. Exit non-zero if any mutant survives (i.e. the
 * test suite did NOT catch it) or restore fails.
 *
 * Usage: php tests/Mutation/run-critical-mutations.php
 */

$root = dirname(__DIR__, 2);
$phpunit = $root . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	fwrite(STDERR, "phpunit not found at {$phpunit}\n");
	exit(1);
}

/**
 * Each mutant: file, exact literal to find, replacement, phpunit --filter
 * that MUST fail while the mutant is applied.
 */
$mutants = [
	[
		'name' => 'rollup: once-costs multiplied into annual bucket',
		'file' => 'lib/Domain/CostRollup.php',
		'find' => "\$once[\$currency] = (\$once[\$currency] ?? 0) + \$e['amount_cents'];",
		'repl' => "\$once[\$currency] = (\$once[\$currency] ?? 0) + \$e['amount_cents'] * 0;",
		'tests' => 'CostRollupTest',
	],
	[
		'name' => 'rollup: annual factor dropped',
		'file' => 'lib/Domain/CostRollup.php',
		'find' => "\$annual[\$currency] = (\$annual[\$currency] ?? 0) + \$e['amount_cents'] * \$factor;",
		'repl' => "\$annual[\$currency] = (\$annual[\$currency] ?? 0) + \$e['amount_cents'];",
		'tests' => 'CostRollupTest',
	],
	[
		'name' => 'csv: formula-injection prefix removed',
		'file' => 'lib/Domain/CsvBuilder.php',
		'find' => 'return "\'" . $value;',
		'repl' => 'return $value;',
		'tests' => 'CsvBuilderTest',
	],
	[
		'name' => 'access: unknown scope fails open',
		'file' => 'lib/Service/AccessService.php',
		'find' => 'default => false, // restricted + unknown scopes fail closed',
		'repl' => 'default => true, // restricted + unknown scopes fail closed',
		'tests' => 'AccessServiceTest',
	],
	[
		'name' => 'access: owner shortcut removed',
		'file' => 'lib/Service/AccessService.php',
		'find' => 'if ($contract->getOwnerUid() === $uid) {',
		'repl' => 'if ($contract->getOwnerUid() === $uid && false) {',
		'tests' => 'AccessServiceTest',
	],
	[
		'name' => 'cas: missing revision merges instead of 409',
		'file' => 'lib/Service/ContractService.php',
		'find' => "if (is_string(\$revision) && preg_match('/^\\d+\$/', \$revision) === 1) {",
		'repl' => "if (is_string(\$revision)) {",
		'tests' => 'ContractServiceTest',
	],
	[
		'name' => 'cas: revision drift merged silently',
		'file' => 'lib/Service/ContractService.php',
		'find' => 'if ($contract->getRevision() !== $expected) {',
		'repl' => 'if (false) {',
		'tests' => 'ContractServiceTest',
	],
	[
		'name' => 'api: paramId accepts any string ("2e3", " 5", "-4")',
		'file' => 'lib/Controller/ApiJsonTrait.php',
		'find' => "preg_match('/^\\d+\$/', \$v) === 1",
		'repl' => 'true',
		'tests' => 'ApiJsonTraitTest',
	],
	[
		'name' => 'api: literal "false" treated as truthy',
		'file' => 'lib/Controller/ApiJsonTrait.php',
		'find' => "in_array(strtolower(\$v), ['1', 'true', 'yes'], true);",
		'repl' => "\$v !== '0';",
		'tests' => 'ApiJsonTraitTest',
	],
	[
		'name' => 'audit: genesis prev_hash wrong',
		'file' => 'lib/Service/AuditService.php',
		'find' => "\$entity->setPrevHash(\$head === null ? 'genesis' : \$head->contentHash());",
		'repl' => "\$entity->setPrevHash(\$head === null ? 'zero' : \$head->contentHash());",
		'tests' => 'AuditServiceTest',
	],
	[
		'name' => 'audit: seq race never retried',
		'file' => 'lib/Service/AuditService.php',
		'find' => 'if (++$attempts >= 3 || !self::isDuplicateKey($e)) {',
		'repl' => 'if (true) {',
		'tests' => 'AuditServiceTest',
	],
	[
		'name' => 'reminders: dedup guard removed (double-notify)',
		'file' => 'lib/Service/ReminderService.php',
		'find' => 'if (!$this->sent->claim($row, $staleBefore)) {',
		'repl' => 'if (false) {',
		'tests' => 'ReminderServiceTest',
	],
	[
		'name' => 'reminders: delivery confirm skipped (row stays pending → duplicate resend)',
		'file' => 'lib/Service/ReminderService.php',
		'find' => 'try {
				$this->sent->confirmSent(',
		'repl' => 'try {
				if (false) { $this->sent->confirmSent(',
		'tests' => 'ReminderServiceTest',
	],
	[
		'name' => 'renewal: expired notify sent even when CAS lost',
		'file' => 'lib/Service/RenewalService.php',
		'find' => 'if ($affected === 1) {',
		'repl' => 'if (true) {',
		'tests' => 'RenewalServiceTest',
	],
	[
		'name' => 'settings: feed token compare skipped (any token valid)',
		'file' => 'lib/Service/SettingsService.php',
		'find' => 'hash_equals($stored, $hash)',
		'repl' => 'true',
		'tests' => 'SettingsServiceTest',
	],
	[
		'name' => 'settings: stale version merged',
		'file' => 'lib/Service/SettingsService.php',
		'find' => 'if ($current[\'version\'] !== $expectedVersion) {',
		'repl' => 'if (false) {',
		'tests' => 'SettingsServiceTest',
	],
];

$killed = 0;
$survived = [];

foreach ($mutants as $m) {
	$path = $root . '/' . $m['file'];
	$orig = file_get_contents($path);
	if ($orig === false || !str_contains($orig, $m['find'])) {
		$survived[] = $m['name'] . ' [ANCHOR NOT FOUND]';
		fwrite(STDERR, "MISS  {$m['name']} — anchor not found in {$m['file']}\n");
		continue;
	}
	// Baseline-green guard (lesson mutation_runner_baseline_red_false_kill):
	// a kill-test that is red unmutated turns nonzero exits into fake kills.
	require_once __DIR__ . '/MutationBaseline.php';
	mutationBaselineSelfCheck(__FILE__);
	file_put_contents($path, str_replace($m['find'], $m['repl'], $orig));
	try {
		exec('cd ' . escapeshellarg($root) . ' && php ' . escapeshellarg($phpunit)
			. ' --filter ' . escapeshellarg($m['tests']) . ' 2>&1', $out, $code);
		if ($code === 0) {
			$survived[] = $m['name'];
			echo "SURVIVED {$m['name']}\n";
		} else {
			++$killed;
			echo "KILLED   {$m['name']}\n";
		}
	} finally {
		file_put_contents($path, $orig);
	}
}

echo "----\n{$killed} killed, " . count($survived) . " survived\n";
if ($survived !== []) {
	fwrite(STDERR, "Surviving mutants:\n - " . implode("\n - ", $survived) . "\n");
	exit(1);
}
exit(0);
