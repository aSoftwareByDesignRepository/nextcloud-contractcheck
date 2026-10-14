<?php
/** @var array $_ @var \OCP\IL10N $l */
?>
<section class="cc-get-app" aria-labelledby="cc-getapp-heading">
	<h2 id="cc-getapp-heading" class="cc-sr-only"><?php p($l->t('Companion app')); ?></h2>

	<div class="cc-card-grid">
		<section class="cc-panel" aria-labelledby="cc-app-info-h">
			<h3 id="cc-app-info-h" class="cc-panel__title">
				<?php print_unescaped(\OCA\ContractCheck\Service\IconCatalog::render('smartphone')); ?>
				<?php p($l->t('ContractCheck for Android')); ?>
			</h3>
			<p><?php p($l->t('Deadlines in your pocket: upcoming cancel-by dates, missed windows and missing documents — read-only and offline-capable.')); ?></p>
			<p class="cc-field__hint"><?php p($l->t('Availability: the Android companion is distributed by your administrator (managed APK). It is not yet in public app stores — this section is honest about what exists today.')); ?></p>
			<h4><?php p($l->t('Connect')); ?></h4>
			<ol class="cc-steps">
				<li><?php p($l->t('Open the app → “Connect to Nextcloud”.')); ?></li>
				<li><?php p($l->t('Enter your server address and log in with an app password.')); ?></li>
				<li><?php p($l->t('Your deadlines sync automatically.')); ?></li>
			</ol>
		</section>

		<section class="cc-panel" aria-labelledby="cc-app-status-h">
			<h3 id="cc-app-status-h" class="cc-panel__title"><?php p($l->t('Connection status')); ?></h3>
			<dl class="cc-dl" id="cc-app-status">
				<div class="cc-dl__row"><dt><?php p($l->t('Companion API')); ?></dt><dd id="cc-app-api">…</dd></div>
				<div class="cc-dl__row"><dt><?php p($l->t('Your role')); ?></dt><dd id="cc-app-role">…</dd></div>
				<div class="cc-dl__row"><dt><?php p($l->t('Minimum app version')); ?></dt><dd id="cc-app-min">…</dd></div>
			</dl>
		</section>
	</div>
</section>
