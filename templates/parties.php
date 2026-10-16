<?php
/** @var array $_ @var \OCP\IL10N $l */
?>
<section class="cc-parties" aria-labelledby="cc-parties-heading">
	<h2 id="cc-parties-heading" class="cc-sr-only"><?php p($l->t('Party directory')); ?></h2>

	<div class="cc-toolbar" role="search">
		<div class="cc-field cc-toolbar__search">
			<label class="cc-label" for="cc-party-search"><?php p($l->t('Search')); ?></label>
			<input type="search" id="cc-party-search" class="cc-input" placeholder="<?php p($l->t('Name, contact, email…')); ?>" autocomplete="off" />
		</div>
		<label class="cc-check cc-toolbar__archived">
			<input type="checkbox" id="cc-party-archived" name="includeArchived" value="0" />
			<span><?php p($l->t('Show archived')); ?></span>
		</label>
		<div class="cc-toolbar__actions">
			<button type="button" id="cc-party-new" class="cc-btn cc-btn--primary" hidden>
				<?php print_unescaped(\OCA\ContractCheck\Service\IconCatalog::render('plus')); ?>
				<?php p($l->t('New party')); ?>
			</button>
		</div>
	</div>

	<div class="cc-card-grid">
		<div class="cc-table-wrap cc-panel--wide">
			<table class="cc-table cc-table--responsive" id="cc-parties-table">
				<thead><tr>
					<th scope="col"><?php p($l->t('Name')); ?></th>
					<th scope="col"><?php p($l->t('Contact')); ?></th>
					<th scope="col"><?php p($l->t('Email')); ?></th>
					<th scope="col"><?php p($l->t('Status')); ?></th>
					<th scope="col"><span class="cc-sr-only"><?php p($l->t('Actions')); ?></span></th>
				</tr></thead>
				<tbody id="cc-parties-body"></tbody>
			</table>
			<p id="cc-parties-empty" class="cc-empty" hidden><?php p($l->t('No parties yet.')); ?></p>
		</div>
	</div>

	<dialog id="cc-party-dialog" class="cc-dialog" aria-labelledby="cc-party-dialog-title">
		<form id="cc-party-form" method="dialog" novalidate>
			<h3 id="cc-party-dialog-title" class="cc-dialog__title"><?php p($l->t('Party')); ?></h3>
			<input type="hidden" name="partyId" id="cc-p-id" />
			<div class="cc-field">
				<label class="cc-label" for="cc-p-name"><?php p($l->t('Name')); ?> <span aria-hidden="true">*</span></label>
				<input class="cc-input" type="text" id="cc-p-name" name="name" required maxlength="255" />
			</div>
			<div class="cc-field-row">
				<div class="cc-field">
					<label class="cc-label" for="cc-p-contact"><?php p($l->t('Contact person')); ?></label>
					<input class="cc-input" type="text" id="cc-p-contact" name="contactName" maxlength="255" />
				</div>
				<div class="cc-field">
					<label class="cc-label" for="cc-p-email"><?php p($l->t('Email')); ?></label>
					<input class="cc-input" type="email" id="cc-p-email" name="contactEmail" maxlength="255" />
				</div>
			</div>
			<div class="cc-field-row">
				<div class="cc-field">
					<label class="cc-label" for="cc-p-phone"><?php p($l->t('Phone')); ?></label>
					<input class="cc-input" type="tel" id="cc-p-phone" name="contactPhone" maxlength="64" />
				</div>
			</div>
			<div class="cc-field">
				<label class="cc-label" for="cc-p-address"><?php p($l->t('Address')); ?></label>
				<textarea class="cc-input cc-textarea" id="cc-p-address" name="address" rows="2" maxlength="4000"></textarea>
			</div>
			<div class="cc-field">
				<label class="cc-label" for="cc-p-notes"><?php p($l->t('Notes')); ?></label>
				<textarea class="cc-input cc-textarea" id="cc-p-notes" name="notes" rows="2" maxlength="10000"></textarea>
			</div>
			<div class="cc-form-actions">
				<button type="submit" class="cc-btn cc-btn--primary"><?php p($l->t('Save')); ?></button>
				<button type="button" class="cc-btn cc-btn--secondary" id="cc-p-cancel"><?php p($l->t('Cancel')); ?></button>
			</div>
		</form>
	</dialog>
</section>
