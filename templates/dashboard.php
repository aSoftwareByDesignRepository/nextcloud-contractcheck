<?php
/** @var array $_ @var \OCP\IL10N $l */
?>
<section class="cc-dashboard" aria-labelledby="cc-dash-heading">
	<h2 id="cc-dash-heading" class="cc-sr-only"><?php p($l->t('Deadline overview')); ?></h2>

	<div class="cc-stat-row" id="cc-dash-counts" role="list" aria-label="<?php p($l->t('Summary')); ?>">
		<a class="cc-stat" role="listitem" href="<?php p($_['urls']['contracts'] ?? '#'); ?>?status=active">
			<span class="cc-stat__value" id="cc-stat-total">–</span>
			<span class="cc-stat__label"><?php p($l->t('Active contracts')); ?></span>
		</a>
		<a class="cc-stat cc-stat--warn" role="listitem" href="<?php p($_['urls']['contracts'] ?? '#'); ?>?status=active&deadline=cancel30">
			<span class="cc-stat__value" id="cc-stat-urgent">–</span>
			<span class="cc-stat__label"><?php p($l->t('Deadlines ≤ 30 days')); ?></span>
		</a>
		<a class="cc-stat cc-stat--danger" role="listitem" href="<?php p($_['urls']['contracts'] ?? '#'); ?>?deadline=missed">
			<span class="cc-stat__value" id="cc-stat-missed">–</span>
			<span class="cc-stat__label"><?php p($l->t('Missed windows')); ?></span>
		</a>
		<a class="cc-stat cc-stat--warn" role="listitem" href="<?php p($_['urls']['contracts'] ?? '#'); ?>?status=active">
			<span class="cc-stat__value" id="cc-stat-docs">–</span>
			<span class="cc-stat__label"><?php p($l->t('Missing documents')); ?></span>
		</a>
		<a class="cc-stat" role="listitem" id="cc-stat-costs-wrap" hidden
			href="<?php p($_['urls']['reports'] ?? '#'); ?>">
			<span class="cc-stat__value" id="cc-stat-costs">–</span>
			<span class="cc-stat__label"><?php p($l->t('Contracted per year')); ?></span>
		</a>
	</div>

	<div class="cc-card-grid">
		<section class="cc-panel" aria-labelledby="cc-dash-urgent-h">
			<h3 id="cc-dash-urgent-h" class="cc-panel__title">
				<?php print_unescaped(\OCA\ContractCheck\Service\IconCatalog::render('alert-triangle')); ?>
				<?php p($l->t('Urgent — cancel by soon')); ?>
			</h3>
			<ul id="cc-dash-urgent" class="cc-deadline-list" role="list"></ul>
			<p id="cc-dash-urgent-empty" class="cc-empty" hidden><?php p($l->t('Nothing due in the next 30 days.')); ?></p>
		</section>

		<section class="cc-panel" aria-labelledby="cc-dash-upcoming-h">
			<h3 id="cc-dash-upcoming-h" class="cc-panel__title">
				<?php print_unescaped(\OCA\ContractCheck\Service\IconCatalog::render('calendar-clock')); ?>
				<?php p($l->t('Coming up — next 90 days')); ?>
			</h3>
			<ul id="cc-dash-upcoming" class="cc-deadline-list" role="list"></ul>
			<p id="cc-dash-upcoming-empty" class="cc-empty" hidden><?php p($l->t('Nothing due in the next 90 days.')); ?></p>
		</section>

		<section class="cc-panel" aria-labelledby="cc-dash-missed-h">
			<h3 id="cc-dash-missed-h" class="cc-panel__title">
				<?php print_unescaped(\OCA\ContractCheck\Service\IconCatalog::render('repeat')); ?>
				<?php p($l->t('Missed windows')); ?>
			</h3>
			<ul id="cc-dash-missed" class="cc-deadline-list" role="list"></ul>
			<p id="cc-dash-missed-empty" class="cc-empty" hidden><?php p($l->t('No missed deadlines.')); ?></p>
		</section>

		<section class="cc-panel" aria-labelledby="cc-dash-expiring-h">
			<h3 id="cc-dash-expiring-h" class="cc-panel__title">
				<?php print_unescaped(\OCA\ContractCheck\Service\IconCatalog::render('calendar-clock')); ?>
				<?php p($l->t('Expiring soon')); ?>
			</h3>
			<ul id="cc-dash-expiring" class="cc-deadline-list" role="list"></ul>
			<p id="cc-dash-expiring-empty" class="cc-empty" hidden><?php p($l->t('Nothing expires in the next 90 days.')); ?></p>
		</section>

		<section class="cc-panel" aria-labelledby="cc-dash-docs-h">
			<h3 id="cc-dash-docs-h" class="cc-panel__title">
				<?php print_unescaped(\OCA\ContractCheck\Service\IconCatalog::render('file-warning')); ?>
				<?php p($l->t('Missing documents')); ?>
			</h3>
			<ul id="cc-dash-docs" class="cc-deadline-list" role="list"></ul>
			<p id="cc-dash-docs-empty" class="cc-empty" hidden><?php p($l->t('All linked files are in place.')); ?></p>
		</section>
	</div>

	<section id="cc-dash-first-run" class="cc-callout cc-callout--info" hidden>
		<h3 class="cc-callout__title"><?php p($l->t('Add your first contract')); ?></h3>
		<p><?php p($l->t('Enter the end date and the notice period — ContractCheck tells you when to cancel.')); ?></p>
		<p class="cc-btn-row">
			<a class="cc-btn cc-btn--primary" href="<?php p($_['urls']['newContract'] ?? '#'); ?>"><?php p($l->t('New contract')); ?></a>
			<a class="cc-btn cc-btn--secondary" href="<?php p($_['urls']['import'] ?? '#'); ?>"><?php p($l->t('Import existing list (CSV)')); ?></a>
		</p>
	</section>
</section>
