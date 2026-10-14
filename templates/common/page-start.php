<?php
/**
 * ContractCheck page chrome — design-system / ArbeitszeitCheck shell parity.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\ContractCheck\Service\IconCatalog;

$pageId = (string)($_['pageId'] ?? 'home');
$pageTitle = (string)($_['pageTitle'] ?? $l->t('ContractCheck'));
$pageHelp = (string)($_['pageHelp'] ?? '');
$roleLabel = (string)($_['roleLabel'] ?? $l->t('Member'));
$urls = $_['urls'] ?? [];
$clientHints = $_['clientHints'] ?? ['locale' => 'en-US', 'htmlLang' => 'en-US', 'timezone' => 'UTC'];
$htmlLang = (string)($clientHints['htmlLang'] ?? 'en-US');
$locale = (string)($clientHints['locale'] ?? $htmlLang);
$timezone = (string)($clientHints['timezone'] ?? 'UTC');
$settingsSection = (string)($_['settingsSection'] ?? '');
$urlsJson = htmlspecialchars(json_encode($urls, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8');
$homeUrl = (string)($urls['home'] ?? '#');

$headerIcons = [
	'dashboard' => 'home',
	'contracts' => 'file-text',
	'contract-form' => 'file-text',
	'contract-detail' => 'file-text',
	'parties' => 'users',
	'reports' => 'activity',
	'audit' => 'list',
	'import' => 'upload',
	'settings' => 'settings',
	'get-the-app' => 'smartphone',
	'denied' => 'shield-off',
];
$iconName = $headerIcons[$pageId] ?? 'file-text';
include __DIR__ . '/navigation.php';
?>
<div id="app-content" class="cc-app cc-app--<?php p($pageId); ?>"
	lang="<?php p($htmlLang); ?>"
	data-locale="<?php p($locale); ?>"
	data-cc-page="<?php p($pageId); ?>"
	data-cc-timezone="<?php p($timezone); ?>"
	data-cc-settings-section="<?php p($settingsSection); ?>"
	data-cc-urls="<?php print_unescaped($urlsJson); ?>"
	data-cc-settings-version="<?php p((string)($_['settingsVersion'] ?? '')); ?>"
	data-cc-is-nc-admin="<?php p(!empty($_['isNcAdmin']) ? '1' : '0'); ?>">
	<a class="cc-skip-link" href="#cc-main-content"><?php p($l->t('Skip to main content')); ?></a>
	<div id="cc-live-region" class="cc-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="cc-alert-region" class="cc-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="app-content-wrapper" class="cc-shell">
		<!-- div, not header: an unscoped <header> outside sectioning content
		     registers a second banner landmark (duplicate banner with NC
		     #header — ds_chrome cc-mf-dup-banner). -->
		<div class="cc-page-header" aria-labelledby="cc-page-title">
			<div class="cc-page-header__top">
				<?php require __DIR__ . '/nav-toggle.php'; ?>
				<nav class="cc-breadcrumb" aria-label="<?php p($l->t('Breadcrumb')); ?>">
					<ol class="cc-breadcrumb__list">
						<li class="cc-breadcrumb__item">
							<a class="cc-breadcrumb__link" href="<?php p($homeUrl); ?>"><?php p($l->t('ContractCheck')); ?></a>
						</li>
						<li class="cc-breadcrumb__item cc-breadcrumb__item--current" aria-current="page">
							<span class="cc-breadcrumb__current"><?php p($pageTitle); ?></span>
						</li>
					</ol>
				</nav>
			</div>
			<div class="cc-page-header__main">
				<div class="cc-page-header__icon" aria-hidden="true">
					<?php print_unescaped(IconCatalog::render($iconName, 'cc-page-header__icon-svg')); ?>
				</div>
				<div class="cc-page-header__text">
					<h1 id="cc-page-title" class="cc-page-title"><?php p($pageTitle); ?></h1>
					<?php if ($pageHelp !== ''): ?>
						<p class="cc-page-header__lead"><?php p($pageHelp); ?></p>
					<?php endif; ?>
				</div>
				<div id="cc-page-actions" class="cc-page-header__actions" aria-live="polite"></div>
			</div>
			<?php if (empty($_['hideScopeStrip'])): ?>
			<div class="cc-scope-strip" aria-label="<?php p($l->t('Active session context')); ?>">
				<span class="cc-scope-strip__label"><?php p($l->t('Role')); ?></span>
				<span class="cc-badge cc-badge--neutral cc-scope-strip__badge"><?php p($roleLabel); ?></span>
				<span class="cc-scope-strip__sep" aria-hidden="true">·</span>
				<span class="cc-scope-strip__label"><?php p($l->t('Timezone')); ?></span>
				<span class="cc-scope-strip__value"><?php p($timezone); ?></span>
			</div>
			<?php endif; ?>
		</div>
		<main id="cc-main-content" class="cc-main" tabindex="-1" aria-labelledby="cc-page-title">
