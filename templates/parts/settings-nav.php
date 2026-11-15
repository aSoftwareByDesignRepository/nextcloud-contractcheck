<?php
/**
 * In-page settings chip bar — complements sidebar when #app-navigation collapses (<1024).
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$items = $_['settingsNav'] ?? [];
?>
<?php if ($items !== []): ?>
<nav class="cc-settings-nav" id="cc-settings-pages" aria-label="<?php p($l->t('Settings sections')); ?>">
	<?php foreach ($items as $item):
		$href = (string)($item['href'] ?? '#');
		$active = !empty($item['active']);
		?>
		<a class="cc-settings-nav__link<?php if ($active): ?> is-active<?php endif; ?>"
			href="<?php p($href); ?>"
			<?php if ($active): ?>aria-current="page"<?php endif; ?>>
			<?php p((string)($item['label'] ?? '')); ?>
		</a>
	<?php endforeach; ?>
</nav>
<?php endif; ?>
