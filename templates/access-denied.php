<?php
/**
 * Access denied — design-system minimal shell (§3.17): skip, live regions, alert, one CTA.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$homeUrl = (string)($_['homeUrl'] ?? '/');
$clientHints = $_['clientHints'] ?? ['locale' => 'en-US', 'htmlLang' => 'en-US', 'timezone' => 'UTC'];
$htmlLang = (string)($clientHints['htmlLang'] ?? 'en-US');
?>
<div id="app-content" class="cc-app cc-app--denied cc-shell--minimal"
	lang="<?php p($htmlLang); ?>">
	<a class="cc-skip-link" href="#cc-main-content"><?php p($l->t('Skip to main content')); ?></a>
	<div id="cc-live-region" class="cc-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="cc-alert-region" class="cc-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="app-content-wrapper" class="cc-shell">
		<!-- div, not header: unscoped <header> = second banner landmark. -->
		<div class="cc-page-header" aria-labelledby="cc-page-title">
			<div class="cc-page-header__main">
				<div class="cc-page-header__icon" aria-hidden="true">
					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="cc-page-header__icon-svg" focusable="false"><circle cx="12" cy="12" r="10"/><path d="M15 9 9 15M9 9l6 6"/></svg>
				</div>
				<div class="cc-page-header__text">
					<h1 id="cc-page-title" class="cc-page-title"><?php p($l->t('Not authorized')); ?></h1>
				</div>
			</div>
		</div>
		<main id="cc-main-content" class="cc-main" tabindex="-1" aria-labelledby="cc-page-title">
			<section class="cc-callout cc-callout--warning" role="alert">
				<p><?php p($l->t('Only Nextcloud admins and ContractCheck app admins can use this app.')); ?></p>
				<p><a class="cc-btn cc-btn--primary" href="<?php p($homeUrl); ?>"><?php p($l->t('Back to Nextcloud')); ?></a></p>
			</section>
		</main>
	</div>
</div>
