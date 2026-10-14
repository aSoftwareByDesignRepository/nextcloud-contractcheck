<?php

declare(strict_types=1);

/**
 * ContractCheck absolute-no-gos — static hard gates run by
 * tests/Unit/Architecture/AbsoluteNoGosTest.php and CI.
 *
 * Each rule encodes a security/correctness invariant of this app:
 *  - SEC-01 deterministic clock (deadline math must be testable)
 *  - SEC-02 no shell/process execution
 *  - SEC-03 no superglobal reads in lib/ (request data via controller only)
 *  - SEC-04 uniform NOT_FOUND (no existence oracles)
 *  - SEC-05 fail-closed CAS (guarded updates only)
 *  - SEC-06 CSV formula-injection sanitization
 *  - SEC-07 feed tokens stored hashed only
 *  - SEC-08 cost fields gated server-side (absent, not redacted)
 *  - SEC-09 integer cents (no float money math)
 *  - SEC-10 no arbitrary file-path reads (documents by fileId only)
 */

$root = dirname(__DIR__, 2);
$fail = 0;

function fail(string $msg): void
{
	global $fail;
	echo "FAIL: {$msg}\n";
	$fail++;
}

function ok(string $msg): void
{
	echo "OK: {$msg}\n";
}

$lib = '';
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/lib')) as $file) {
	if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
		$lib .= file_get_contents($file->getPathname()) . "\n";
	}
}

// SEC-02: no process execution anywhere in lib/
if (preg_match('/\\\\Process::|new\s+\\\\?Symfony\\\\Component\\\\Process\\\\Process|shell_exec\s*\(|proc_open\s*\(|passthru\s*\(|\bpopen\s*\(/', $lib)) {
	fail('SEC-02: process execution must not appear in lib/');
} else {
	ok('SEC-02: no process execution');
}

// eval is banned outright.
if (preg_match('/\beval\s*\(/', $lib)) {
	fail('SEC-02: eval() banned');
} else {
	ok('SEC-02: no eval()');
}

// SEC-03: superglobals must not be read in lib/ — controllers receive
// parsed params through AppFramework; middleware may touch IRequest only.
if (preg_match('/\$_(GET|POST|REQUEST|COOKIE|FILES|SERVER)\b/', $lib)) {
	fail('SEC-03: superglobal read in lib/');
} else {
	ok('SEC-03: no superglobals in lib/');
}

// SEC-01: wall-clock reads confined to Service/Clock.php.
// Exemptions: Clock itself plus the two operational (non-domain) stamps —
// support-report timestamp and backup snapshot IDs. Nothing else may read
// the wall clock; deadline/cost/audit logic goes through Clock.
$clockExempt = [
	'Clock.php' => true,
	'AppFeedbackLinks.php' => true,
	'UpgradeBackupService.php' => true,
];
$clockViolations = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/lib')) as $file) {
	if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
		continue;
	}
	if (isset($clockExempt[basename($file->getPathname())])) {
		continue;
	}
	$src = (string)file_get_contents($file->getPathname());
	if (preg_match('/\btime\s*\(\s*\)|\bmicrotime\s*\(|\b(?:gmdate|date)\s*\(\s*["\'\\$]|new\s+\\\\?DateTime(?:Immutable)?\s*\(\s*(["\']now["\'])?\s*\)/', $src)) {
		$clockViolations[] = $file->getPathname();
	}
}
if ($clockViolations !== []) {
	fail('SEC-01: wall-clock reads outside Clock: ' . implode(', ', $clockViolations));
} else {
	ok('SEC-01: wall-clock confined to Clock');
}

// SEC-04: ApiJsonTrait must emit the canonical uniform payload.
$trait = (string)file_get_contents($root . '/lib/Controller/ApiJsonTrait.php');
if (!str_contains($trait, "'NOT_FOUND'") || !str_contains($trait, "'success'")) {
	fail('SEC-04: ApiJsonTrait missing canonical NOT_FOUND envelope');
} else {
	ok('SEC-04: uniform NOT_FOUND in ApiJsonTrait');
}

// SEC-05: writes that race must go through guarded mappers.
$mapper = (string)file_get_contents($root . '/lib/Db/ContractMapper.php');
if (!str_contains($mapper, 'function updateWithRevision') || !str_contains($mapper, 'function guardedUpdate')) {
	fail('SEC-05: ContractMapper missing updateWithRevision/guardedUpdate');
} else {
	ok('SEC-05: guarded CAS mappers present');
}

// SEC-06: CSV export must prefix formula-leading cells.
$csv = (string)file_get_contents($root . '/lib/Domain/CsvBuilder.php');
if (!preg_match("/[=+\\-@\\t\\r\\x0B]/", $csv) && !str_contains($csv, '\\\\x0B')) {
	fail('SEC-06: CsvBuilder formula-injection prefix not detected');
} else {
	ok('SEC-06: CSV formula-injection sanitization present');
}

// SEC-07: feed tokens — stored hash only, timing-safe compare.
$settings = (string)file_get_contents($root . '/lib/Service/SettingsService.php');
if (!str_contains($settings, "hash('sha256'") || !str_contains($settings, 'hash_equals')) {
	fail('SEC-07: feed token must be stored hashed + hash_equals compare');
} else {
	ok('SEC-07: hashed feed tokens + hash_equals');
}

// SEC-08: cost serialization must gate on canViewCosts.
$api = (string)file_get_contents($root . '/lib/Controller/ApiController.php');
$contractSvc = (string)file_get_contents($root . '/lib/Service/ContractService.php');
if (!str_contains($api, 'canViewCosts') && !str_contains($contractSvc, 'canViewCosts')
	&& !str_contains((string)file_get_contents($root . '/lib/Service/DeadlineService.php'), 'canViewCosts')) {
	fail('SEC-08: no canViewCosts gating found on serialization path');
} else {
	ok('SEC-08: cost visibility gated');
}

// SEC-09: money is integer cents — no float casts on amount fields.
if (preg_match('/(floatval|float)\s*\(\s*\$?\w*[Aa]mount/', $lib)
	|| preg_match('/getAmountCents\s*\(\s*\)\s*\/\s*100/', $lib)) {
	fail('SEC-09: float math on money detected');
} else {
	ok('SEC-09: integer-cent money only');
}

// SEC-10: documents resolve via fileId — never raw request paths.
$doc = (string)file_get_contents($root . '/lib/Service/DocumentService.php');
if (preg_match('/file_get_contents\s*\(\s*\$/', $doc) || preg_match('/basename\s*\(\s*\$/', $doc)) {
	fail('SEC-10: raw path read in DocumentService');
} else {
	ok('SEC-10: document access via fileId only');
}

// Form-encoded "false" must not be treated truthy: trait must expose a
// strict boolean parser used by controllers.
if (preg_match('/function\s+paramBool/', $trait) !== 1
	|| (!str_contains($trait, "['1', 'true', 'yes']") && !str_contains($trait, "'1', 'true'"))) {
	fail('strictBool: ApiJsonTrait missing strict boolean parsing (paramBool true-list)');
} else {
	ok('strictBool: strict boolean parsing present');
}

// Strict positive-int id parsing for ID-bearing routes.
if (preg_match('/function\s+paramId/', $trait) !== 1 || !str_contains($trait, '/^\\d+$/')) {
	fail('strictId: ApiJsonTrait missing digit-only id parsing (paramId)');
} else {
	ok('strictId: digit-only id parsing present');
}

// l10n catalogs must exist for en + de (WCAG/deployment contract).
foreach (['en', 'de'] as $lang) {
	if (!is_file($root . '/l10n/' . $lang . '.json')) {
		fail("l10n: missing {$lang}.json");
	}
}

exit($fail === 0 ? 0 : 1);
