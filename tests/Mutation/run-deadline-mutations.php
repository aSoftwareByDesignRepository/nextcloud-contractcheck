<?php

declare(strict_types=1);

/**
 * Deadline-engine mutation runner — the spec's worked-example corpus must
 * kill every mutant of the date/anchor/renewal math.
 *
 * Usage: php tests/Mutation/run-deadline-mutations.php
 */

$root = dirname(__DIR__, 2);
$phpunit = $root . '/vendor/bin/phpunit';
if (!is_file($phpunit)) {
	fwrite(STDERR, "phpunit not found at {$phpunit}\n");
	exit(1);
}

$mutants = [
	[
		'name' => 'quarter progression skips +3 → +4 months',
		'file' => 'lib/Domain/DeadlineEngine.php',
		'find' => "\$month = (int)substr(\$current, 5, 2) + 3;",
		'repl' => "\$month = (int)substr(\$current, 5, 2) + 4;",
		'tests' => 'DeadlineEngineTest',
	],
	[
		'name' => 'quarter-end September wrong day (31st)',
		'file' => 'lib/Domain/DeadlineEngine.php',
		'find' => '3 => 31, 6 => 30, 9 => 30, default => 31,',
		'repl' => '3 => 31, 6 => 30, 9 => 31, default => 31,',
		'tests' => 'DeadlineEngineTest',
	],
	[
		'name' => 'year-end anchor jumps 2 years',
		'file' => 'lib/Domain/DeadlineEngine.php',
		'find' => "self::ANCHOR_YEAR_END => sprintf('%04d-12-31', (int)substr(\$current, 0, 4) + 1),",
		'repl' => "self::ANCHOR_YEAR_END => sprintf('%04d-12-31', (int)substr(\$current, 0, 4) + 2),",
		'tests' => 'DeadlineEngineTest',
	],
	[
		'name' => 'days-notice subtracted backwards (+ instead of -)',
		'file' => 'lib/Domain/DeadlineEngine.php',
		'find' => ': DateMath::addDays($effectiveDate, -$noticeValue);',
		'repl' => ': DateMath::addDays($effectiveDate, $noticeValue);',
		'tests' => 'DeadlineEngineTest',
	],
	[
		'name' => 'missed-window boundary day treated as missed (> instead of >=)',
		'file' => 'lib/Domain/DeadlineEngine.php',
		'find' => 'DateMath::compare($cancelBy, $today) >= 0',
		'repl' => 'DateMath::compare($cancelBy, $today) > 0',
		'tests' => 'DeadlineEngineTest',
	],
	[
		'name' => 'R1 month-end semantics dropped (30.04-3m → 30.01 not 31.01)',
		'file' => 'lib/Domain/DateMath.php',
		'find' => 'if (self::isLastDayOfMonth($date)) {',
		'repl' => 'if (!self::isLastDayOfMonth($date)) {',
		'tests' => 'DateMathTest|DeadlineEngineTest',
	],
	[
		'name' => 'month clamp removed (31.01 + 1m overflows to 03.03)',
		'file' => 'lib/Domain/DateMath.php',
		'find' => 'str_pad((string)min($day, $lastDay), 2, \'0\', STR_PAD_LEFT)',
		'repl' => 'str_pad((string)$day, 2, \'0\', STR_PAD_LEFT)',
		'tests' => 'DateMathTest|DeadlineEngineTest',
	],
	[
		'name' => 'addDays sign flipped',
		'file' => 'lib/Domain/DateMath.php',
		'find' => "->modify((\$days >= 0 ? '+' : '') . \$days . ' days')",
		'repl' => '->modify((string)(-$days) . \' days\')',
		'tests' => 'DateMathTest|DeadlineEngineTest',
	],
	[
		'name' => 'compare() equality lost',
		'file' => 'lib/Domain/DateMath.php',
		'find' => 'return $a <=> $b;',
		'repl' => 'return $a < $b ? -1 : 1;',
		'tests' => 'DateMathTest|DeadlineEngineTest',
	],
];

$killed = 0;
$survived = [];

foreach ($mutants as $m) {
	if (!empty($m['skip'])) {
		continue;
	}
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
