<?php
/**
 * In-page settings chip bar — complements sidebar when #app-navigation collapses (<1024).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 * @var \OCA\ContractCheck\Service\SettingsSectionCatalog $catalog
 */
$catalog = $_['sectionCatalog'];
$current = (string)($_['settingsSection'] ?? '');
$urls = $_['urls'] ?? [];
?>
<nav class="cc-settings-nav" id="cc-settings-pages" aria-label="<?php p($l->t('Settings sections')); ?>">
	<?php foreach (\OCA\ContractCheck\Service\SettingsSectionCatalog::SECTIONS as $slug):
		$href = (string)($urls[$slug] ?? '#');
		$active = $current === $slug;
		?>
		<a class="cc-settings-nav__link<?php if ($active): ?> is-active<?php endif; ?>"
			href="<?php p($href); ?>"
			<?php if ($active): ?>aria-current="page"<?php endif; ?>>
			<?php p($catalog->navLabel($l, $slug)); ?>
		</a>
	<?php endforeach; ?>
</nav>
