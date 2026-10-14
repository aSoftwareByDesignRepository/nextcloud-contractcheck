<?php
/** @var array $_ @var \OCP\IL10N $l */
?>
<section class="cc-import" aria-labelledby="cc-import-heading">
	<h2 id="cc-import-heading" class="cc-sr-only"><?php p($l->t('CSV import')); ?></h2>

	<div class="cc-card-grid">
		<section class="cc-panel" aria-labelledby="cc-import-h">
			<h3 id="cc-import-h" class="cc-panel__title">
				<?php print_unescaped(\OCA\ContractCheck\Service\IconCatalog::render('upload')); ?>
				<?php p($l->t('Import contracts from CSV')); ?>
			</h3>
			<p class="cc-field__hint">
				<?php p($l->t('One contract per row. Required column: title. Recognized columns: ref, type, partner, start, end, term, renewal, notice, anchor, cost_center, notes. Dates as YYYY-MM-DD or DD.MM.YYYY.')); ?>
			</p>
			<form id="cc-import-form" enctype="multipart/form-data" novalidate>
				<div class="cc-field">
					<label class="cc-label" for="cc-import-file"><?php p($l->t('CSV file')); ?></label>
					<input type="file" id="cc-import-file" name="file" accept=".csv,text/csv" required />
				</div>
				<div class="cc-form-actions">
					<button type="submit" class="cc-btn cc-btn--primary" id="cc-import-submit"><?php p($l->t('Import')); ?></button>
					<button type="button" class="cc-btn cc-btn--secondary" id="cc-import-template"><?php p($l->t('Download CSV template')); ?></button>
				</div>
			</form>
			<div id="cc-import-result" class="cc-import-result" hidden>
				<p id="cc-import-summary" role="status"></p>
				<div class="cc-table-wrap" id="cc-import-errors-wrap" hidden><table class="cc-table cc-table--compact" id="cc-import-errors">
					<thead><tr>
						<th scope="col"><?php p($l->t('Line')); ?></th>
						<th scope="col"><?php p($l->t('Field')); ?></th>
						<th scope="col"><?php p($l->t('Problem')); ?></th>
					</tr></thead>
					<tbody id="cc-import-errors-body"></tbody>
				</table></div>
				<div class="cc-table-wrap" id="cc-import-warnings-wrap" hidden><table class="cc-table cc-table--compact" id="cc-import-warnings">
					<thead><tr>
						<th scope="col"><?php p($l->t('Line')); ?></th>
						<th scope="col"><?php p($l->t('Field')); ?></th>
						<th scope="col"><?php p($l->t('Warnings')); ?></th>
					</tr></thead>
					<tbody id="cc-import-warnings-body"></tbody>
				</table></div>
			</div>
		</section>
	</div>
</section>
