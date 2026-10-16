<?php
/** @var array $_ @var \OCP\IL10N $l */
?>
<section class="cc-reports" aria-labelledby="cc-reports-heading">
	<h2 id="cc-reports-heading" class="cc-sr-only"><?php p($l->t('Reports & export')); ?></h2>

	<div class="cc-card-grid">
		<section class="cc-panel" aria-labelledby="cc-export-h">
			<h3 id="cc-export-h" class="cc-panel__title">
				<?php print_unescaped(\OCA\ContractCheck\Service\IconCatalog::render('download')); ?>
				<?php p($l->t('Export the register')); ?>
			</h3>
			<p class="cc-field__hint"><?php p($l->t('Everything you may see — titles, partners, deadlines, status. Cost columns only when your role includes them.')); ?></p>
			<div class="cc-btn-row">
				<a class="cc-btn cc-btn--primary" href="<?php p($_['urls']['exportCsv'] ?? '#'); ?>" download>
					<?php p($l->t('Download CSV')); ?>
				</a>
				<a class="cc-btn cc-btn--secondary" href="<?php p($_['urls']['exportPdf'] ?? '#'); ?>" download>
					<?php p($l->t('Download PDF')); ?>
				</a>
			</div>
		</section>

		<section class="cc-panel" aria-labelledby="cc-rollup-h">
			<h3 id="cc-rollup-h" class="cc-panel__title"><?php p($l->t('Costs per year')); ?></h3>
			<p class="cc-field__hint"><?php p($l->t('Annualized cost per contract — monthly ×12, quarterly ×4, yearly ×1, one-time excluded.')); ?></p>
			<div class="cc-table-wrap"><table class="cc-table cc-table--responsive" id="cc-rollup-table">
				<thead><tr>
					<th scope="col"><?php p($l->t('Contract')); ?></th>
					<th scope="col"><?php p($l->t('Per year')); ?></th>
				</tr></thead>
				<tbody id="cc-rollup-body"></tbody>
			</table></div>
			<p id="cc-rollup-empty" class="cc-empty" hidden><?php p($l->t('No cost data visible for your role.')); ?></p>
		</section>

		<section class="cc-panel cc-panel--wide" aria-labelledby="cc-rollup-type-h">
			<h3 id="cc-rollup-type-h" class="cc-panel__title"><?php p($l->t('Costs per category')); ?></h3>
			<p class="cc-field__hint"><?php p($l->t('Annualized cost grouped by contract type.')); ?></p>
			<div class="cc-table-wrap"><table class="cc-table cc-table--responsive" id="cc-rollup-type-table">
				<thead><tr>
					<th scope="col"><?php p($l->t('Category')); ?></th>
					<th scope="col" class="cc-num"><?php p($l->t('Contracts')); ?></th>
					<th scope="col" class="cc-num"><?php p($l->t('Per year')); ?></th>
					<th scope="col" class="cc-num"><?php p($l->t('One-time')); ?></th>
				</tr></thead>
				<tbody id="cc-rollup-type-body"></tbody>
			</table></div>
			<p id="cc-rollup-type-empty" class="cc-empty" hidden><?php p($l->t('No cost data visible for your role.')); ?></p>
		</section>

		<section class="cc-panel cc-panel--wide" aria-labelledby="cc-rollup-cc-h">
			<h3 id="cc-rollup-cc-h" class="cc-panel__title"><?php p($l->t('Costs per cost center')); ?></h3>
			<p class="cc-field__hint"><?php p($l->t('Contracted vs. planned per year — planned budgets are set under Settings.')); ?></p>
			<div class="cc-table-wrap"><table class="cc-table cc-table--responsive" id="cc-rollup-cc-table">
				<thead><tr>
					<th scope="col"><?php p($l->t('Cost center')); ?></th>
					<th scope="col" class="cc-num"><?php p($l->t('Contracts')); ?></th>
					<th scope="col" class="cc-num"><?php p($l->t('Contracted per year')); ?></th>
					<th scope="col" class="cc-num"><?php p($l->t('Planned per year')); ?></th>
					<th scope="col" class="cc-num"><?php p($l->t('Difference')); ?></th>
				</tr></thead>
				<tbody id="cc-rollup-cc-body"></tbody>
			</table></div>
			<p id="cc-rollup-cc-empty" class="cc-empty" hidden><?php p($l->t('No cost centers in use.')); ?></p>
		</section>
	</div>
</section>
