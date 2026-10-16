<?php
/** @var array $_ @var \OCP\IL10N $l */
$contractId = (string)($_['contractId'] ?? '');
?>
<section class="cc-contract-detail" aria-labelledby="cc-detail-heading" data-contract-id="<?php p($contractId); ?>">
	<h2 id="cc-detail-heading" class="cc-sr-only"><?php p($l->t('Contract details')); ?></h2>

	<div id="cc-detail-loading" class="cc-loading" role="status"><?php p($l->t('Loading…')); ?></div>
	<div id="cc-detail-root" hidden>

		<section class="cc-hero" id="cc-hero" aria-labelledby="cc-hero-title">
			<div class="cc-hero__main">
				<h3 class="cc-hero__title" id="cc-hero-title"></h3>
				<p class="cc-hero__meta" id="cc-hero-meta"></p>
				<div class="cc-hero__badges" id="cc-hero-badges"></div>
			</div>
			<div class="cc-hero__deadline" id="cc-hero-deadline" role="status"></div>
		</section>

		<div class="cc-detail-actions" id="cc-detail-actions" role="group" aria-label="<?php p($l->t('Contract actions')); ?>"></div>

		<div class="cc-card-grid">
			<section class="cc-panel" aria-labelledby="cc-d-info-h">
				<h3 id="cc-d-info-h" class="cc-panel__title"><?php p($l->t('Details')); ?></h3>
				<dl class="cc-dl" id="cc-d-info"></dl>
			</section>

			<section class="cc-panel" id="cc-d-costs-panel" aria-labelledby="cc-d-costs-h" hidden>
				<h3 id="cc-d-costs-h" class="cc-panel__title"><?php p($l->t('Costs')); ?></h3>
				<p class="cc-cost-rollup" id="cc-d-rollup"></p>
				<div class="cc-table-wrap"><table class="cc-table cc-table--compact cc-table--responsive" id="cc-d-costs-table">
					<thead><tr>
						<th scope="col"><?php p($l->t('Label')); ?></th>
						<th scope="col"><?php p($l->t('Amount')); ?></th>
						<th scope="col"><?php p($l->t('Interval')); ?></th>
						<th scope="col"><span class="cc-sr-only"><?php p($l->t('Remove')); ?></span></th>
					</tr></thead>
					<tbody id="cc-d-costs-body"></tbody>
				</table></div>
				<form id="cc-cost-form" class="cc-inline-form" hidden novalidate>
					<input type="text" name="label" class="cc-input" placeholder="<?php p($l->t('Label')); ?>" maxlength="255" required />
					<input type="text" name="amount" class="cc-input cc-input--amount" placeholder="<?php p($l->t('e.g. 49,90')); ?>" required inputmode="decimal" aria-label="<?php p($l->t('Amount')); ?>" />
					<input type="text" name="currency" class="cc-input cc-input--currency" value="EUR" maxlength="3" required aria-label="<?php p($l->t('Currency (ISO 4217)')); ?>" />
					<select name="interval" class="cc-select">
						<option value="monthly"><?php p($l->t('monthly')); ?></option>
						<option value="quarterly"><?php p($l->t('quarterly')); ?></option>
						<option value="yearly" selected><?php p($l->t('yearly')); ?></option>
						<option value="once"><?php p($l->t('once')); ?></option>
					</select>
					<button type="submit" class="cc-btn cc-btn--secondary"><?php p($l->t('Add')); ?></button>
				</form>
			</section>

			<section class="cc-panel" aria-labelledby="cc-d-docs-h">
				<h3 id="cc-d-docs-h" class="cc-panel__title"><?php p($l->t('Documents')); ?></h3>
				<ul class="cc-doc-list" id="cc-d-docs" role="list"></ul>
				<p id="cc-d-docs-empty" class="cc-empty" hidden><?php p($l->t('No files linked yet.')); ?></p>
				<div class="cc-picker" id="cc-doc-picker">
					<input class="cc-input cc-picker__input" type="search" id="cc-doc-search"
						role="combobox" aria-expanded="false" aria-autocomplete="list"
						aria-controls="cc-doc-results"
						placeholder="<?php p($l->t('Search your files…')); ?>" autocomplete="off" />
					<select id="cc-doc-kind" class="cc-select" aria-label="<?php p($l->t('Document kind')); ?>">
						<option value="contract" selected><?php p($l->t('Contract')); ?></option>
						<option value="amendment"><?php p($l->t('Amendment')); ?></option>
						<option value="termination"><?php p($l->t('Termination')); ?></option>
						<option value="invoice"><?php p($l->t('Invoice')); ?></option>
						<option value="other"><?php p($l->t('Other')); ?></option>
					</select>
					<ul class="cc-picker__results" id="cc-doc-results" role="listbox" hidden></ul>
				</div>
			</section>

			<section class="cc-panel" aria-labelledby="cc-d-history-h">
				<h3 id="cc-d-history-h" class="cc-panel__title"><?php p($l->t('History')); ?></h3>
				<ol class="cc-event-list" id="cc-d-events" reversed></ol>
				<p id="cc-d-events-empty" class="cc-empty" hidden><?php p($l->t('Nothing recorded yet.')); ?></p>
			</section>
		</div>
	</div>
</section>
