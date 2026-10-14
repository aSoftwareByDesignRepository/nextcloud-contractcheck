<?php
/**
 * ContractCheck sidebar — Check-family chrome (family parity).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */

use OCA\ContractCheck\Service\IconCatalog;

$navItems = $_['nav'] ?? [];
$settingsItems = $_['settingsNav'] ?? [];
$settingsSection = (string)($_['settingsSection'] ?? '');
$roleLabel = (string)($_['roleLabel'] ?? $l->t('Viewer'));
$appFeedbackCssPrefix = 'cc';
$appFeedbackLanguageCode = method_exists($l, 'getLanguageCode') ? (string)$l->getLanguageCode() : 'en';

$mainItems = [];
foreach ($navItems as $item) {
	if (($item['id'] ?? '') === 'settings') {
		continue; // settings group rendered from settingsNav
	}
	$mainItems[] = $item;
}
?>
<a class="skip-link cc-skip-link--nav" href="#app-navigation"><?php p($l->t('Skip to navigation')); ?></a>
<nav id="app-navigation" class="cc-nav" role="navigation" aria-label="<?php p($l->t('ContractCheck')); ?>">
	<div class="cc-brand">
		<span class="cc-brand__icon" aria-hidden="true">
			<?php print_unescaped(IconCatalog::render('file-text', 'cc-brand__icon-svg')); ?>
		</span>
		<div class="cc-brand__text">
			<h2 class="cc-brand__title"><?php p($l->t('ContractCheck')); ?></h2>
			<p class="cc-brand__subtitle"><?php p($l->t('Contracts & deadlines')); ?></p>
			<span class="cc-badge cc-badge--neutral"><?php p($roleLabel); ?></span>
		</div>
	</div>
	<div class="cc-nav__body">
		<section class="cc-nav__group" aria-labelledby="cc-nav-register">
			<h3 class="cc-nav__group-label" id="cc-nav-register"><?php p($l->t('Register')); ?></h3>
			<ul class="cc-nav__list">
				<?php foreach ($mainItems as $item): ?>
					<li class="cc-nav__item<?php if (!empty($item['active'])): ?> is-active<?php endif; ?>">
						<a class="cc-nav__link<?php if (!empty($item['active'])): ?> is-active<?php endif; ?>"
							href="<?php p($item['href']); ?>"
							<?php if (!empty($item['active'])): ?>aria-current="page"<?php endif; ?>>
							<span class="cc-nav__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render($item['icon'])); ?></span>
							<span class="cc-nav__label">
								<span class="cc-nav__name"><?php p($item['label']); ?></span>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
		<section class="cc-nav__group" aria-labelledby="cc-nav-settings">
			<h3 class="cc-nav__group-label" id="cc-nav-settings"><?php p($l->t('Settings')); ?></h3>
			<ul class="cc-nav__list">
				<?php foreach ($settingsItems as $item): ?>
					<li class="cc-nav__item<?php if (!empty($item['active'])): ?> is-active<?php endif; ?>">
						<a class="cc-nav__link<?php if (!empty($item['active'])): ?> is-active<?php endif; ?>"
							href="<?php p($item['href']); ?>"
							<?php if (!empty($item['active'])): ?>aria-current="page"<?php endif; ?>>
							<span class="cc-nav__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render($item['icon'])); ?></span>
							<span class="cc-nav__label">
								<span class="cc-nav__name"><?php p($item['label']); ?></span>
								<span class="cc-nav__hint"><?php p((string)($item['hint'] ?? '')); ?></span>
							</span>
						</a>
					</li>
				<?php endforeach; ?>
			</ul>
		</section>
	</div>
	<?php include __DIR__ . '/../parts/feedback-nav-footer.php'; ?>
</nav>
