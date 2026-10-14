<?php
/**
 * ContractCheck app shell — picks the active page partial.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

$pageId = (string)($_['page'] ?? 'dashboard');
$allowed = [
	'dashboard', 'contracts', 'contract-form', 'contract-detail',
	'parties', 'reports', 'audit', 'import', 'settings',
];
if (!in_array($pageId, $allowed, true)) {
	$pageId = 'dashboard';
}
$_['pageId'] = $pageId;
$_['settingsSection'] = (string)($_['settingsSection'] ?? '');

include __DIR__ . '/common/page-start.php';
include __DIR__ . '/' . $pageId . '.php';
include __DIR__ . '/common/page-end.php';
