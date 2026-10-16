<?php
/** @var array $_ @var \OCP\IL10N $l */
?>
<section class="cc-audit" aria-labelledby="cc-audit-heading">
	<h2 id="cc-audit-heading" class="cc-sr-only"><?php p($l->t('Audit history')); ?></h2>

	<div class="cc-toolbar">
		<div class="cc-field">
			<label class="cc-label" for="cc-audit-event"><?php p($l->t('Event')); ?></label>
			<select id="cc-audit-event" class="cc-select">
				<option value=""><?php p($l->t('All')); ?></option>
				<option value="created"><?php p($l->t('Created')); ?></option>
				<option value="updated"><?php p($l->t('Updated')); ?></option>
				<option value="activated"><?php p($l->t('Activated')); ?></option>
				<option value="terminated"><?php p($l->t('Terminated')); ?></option>
				<option value="expired"><?php p($l->t('Expired')); ?></option>
				<option value="renewed"><?php p($l->t('Renewed')); ?></option>
				<option value="doc_missing"><?php p($l->t('Document missing')); ?></option>
				<option value="reminder_sent"><?php p($l->t('Reminder sent')); ?></option>
			</select>
		</div>
		<div class="cc-field">
			<label class="cc-label" for="cc-audit-from"><?php p($l->t('From')); ?></label>
			<input type="date" id="cc-audit-from" class="cc-input" />
		</div>
		<div class="cc-field">
			<label class="cc-label" for="cc-audit-to"><?php p($l->t('To')); ?></label>
			<input type="date" id="cc-audit-to" class="cc-input" />
		</div>
	</div>

	<div class="cc-table-wrap">
		<table class="cc-table cc-table--responsive" id="cc-audit-table">
			<thead><tr>
				<th scope="col"><?php p($l->t('When')); ?></th>
				<th scope="col"><?php p($l->t('Event')); ?></th>
				<th scope="col"><?php p($l->t('Contract')); ?></th>
				<th scope="col"><?php p($l->t('By')); ?></th>
			</tr></thead>
			<tbody id="cc-audit-body"></tbody>
		</table>
		<p id="cc-audit-empty" class="cc-empty" hidden><?php p($l->t('No events recorded yet.')); ?></p>
	</div>
</section>
