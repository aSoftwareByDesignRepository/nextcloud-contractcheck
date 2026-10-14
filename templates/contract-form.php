<?php
/** @var array $_ @var \OCP\IL10N $l */
?>
<section class="cc-contract-form" aria-labelledby="cc-form-heading">
	<h2 id="cc-form-heading" class="cc-sr-only"><?php p($l->t('Contract details')); ?></h2>

	<form id="cc-contract-form" novalidate>
		<input type="hidden" name="contractId" id="cc-f-id" value="<?php p((string)($_['contractId'] ?? '')); ?>" />
		<input type="hidden" name="revision" id="cc-f-revision" value="" />

		<fieldset class="cc-fieldset">
			<legend><?php p($l->t('Basics')); ?></legend>
			<div class="cc-field">
				<label class="cc-label" for="cc-f-title"><?php p($l->t('Title')); ?> <span aria-hidden="true">*</span></label>
				<input class="cc-input" type="text" id="cc-f-title" name="title" required maxlength="255"
					placeholder="<?php p($l->t('e.g. Office rent, printer lease')); ?>" />
				<p class="cc-field__hint"><?php p($l->t('A short name everyone recognizes.')); ?></p>
			</div>
			<div class="cc-field-row">
				<div class="cc-field">
					<label class="cc-label" for="cc-f-refno"><?php p($l->t('Reference no.')); ?></label>
					<input class="cc-input" type="text" id="cc-f-refno" name="refNo" maxlength="64" />
				</div>
				<div class="cc-field">
					<label class="cc-label" for="cc-f-type"><?php p($l->t('Type')); ?></label>
					<select class="cc-select" id="cc-f-type" name="type">
						<option value="rent"><?php p($l->t('Rent')); ?></option>
						<option value="leasing"><?php p($l->t('Leasing')); ?></option>
						<option value="service" selected><?php p($l->t('Service')); ?></option>
						<option value="insurance"><?php p($l->t('Insurance')); ?></option>
						<option value="maintenance"><?php p($l->t('Maintenance')); ?></option>
						<option value="grant"><?php p($l->t('Grant')); ?></option>
						<option value="employment_related"><?php p($l->t('Employment-related')); ?></option>
						<option value="other"><?php p($l->t('Other')); ?></option>
					</select>
				</div>
			</div>
			<div class="cc-field-row">
				<div class="cc-field">
					<label class="cc-label" for="cc-f-partner"><?php p($l->t('Partner')); ?></label>
					<select class="cc-select" id="cc-f-partner" name="partnerId">
						<option value=""><?php p($l->t('— none —')); ?></option>
					</select>
					<p class="cc-field__hint"><?php p($l->t('Managed under “Parties”.')); ?></p>
				</div>
				<div class="cc-field">
					<label class="cc-label" for="cc-f-owner"><?php p($l->t('Owner')); ?></label>
					<div class="cc-picker" id="cc-owner-picker" data-picker="user">
						<div class="cc-picker__chips" id="cc-owner-chips" aria-live="polite"></div>
						<input class="cc-input cc-picker__input" type="text" id="cc-f-owner" name="ownerSearch"
							role="combobox" aria-expanded="false" aria-autocomplete="list"
							aria-controls="cc-owner-results"
							placeholder="<?php p($l->t('Search people…')); ?>" autocomplete="off" />
						<input type="hidden" id="cc-f-owner-uid" name="ownerUid" />
						<ul class="cc-picker__results" id="cc-owner-results" role="listbox" hidden></ul>
					</div>
					<p class="cc-field__hint"><?php p($l->t('Gets deadline reminders. Empty → managers are reminded.')); ?></p>
				</div>
			</div>
			<div class="cc-field">
				<label class="cc-label" for="cc-f-scope"><?php p($l->t('Visibility')); ?></label>
				<select class="cc-select" id="cc-f-scope" name="scope">
					<option value="org" selected><?php p($l->t('Whole organization')); ?></option>
					<option value="dept"><?php p($l->t('Owner’s group(s)')); ?></option>
					<option value="restricted"><?php p($l->t('Only owner & managers')); ?></option>
				</select>
				<p class="cc-field__hint"><?php p($l->t('Who may see this contract. Documents keep their own file permissions.')); ?></p>
			</div>
		</fieldset>

		<fieldset class="cc-fieldset">
			<legend><?php p($l->t('Term & dates')); ?></legend>
			<div class="cc-field-row">
				<div class="cc-field">
					<label class="cc-label" for="cc-f-termkind"><?php p($l->t('Term')); ?></label>
					<select class="cc-select" id="cc-f-termkind" name="termKind">
						<option value="fixed" selected><?php p($l->t('Fixed term')); ?></option>
						<option value="auto_renew"><?php p($l->t('Auto-renewing')); ?></option>
						<option value="indefinite"><?php p($l->t('Open-ended')); ?></option>
					</select>
				</div>
				<div class="cc-field">
					<label class="cc-label" for="cc-f-signed"><?php p($l->t('Signed on')); ?></label>
					<input class="cc-input" type="date" id="cc-f-signed" name="signedAt" />
				</div>
			</div>
			<div class="cc-field-row">
				<div class="cc-field">
					<label class="cc-label" for="cc-f-start"><?php p($l->t('Starts')); ?></label>
					<input class="cc-input" type="date" id="cc-f-start" name="startDate" />
				</div>
				<div class="cc-field" id="cc-f-end-wrap">
					<label class="cc-label" for="cc-f-end"><?php p($l->t('Ends')); ?> <span aria-hidden="true">*</span></label>
					<input class="cc-input" type="date" id="cc-f-end" name="endDate" />
				</div>
			</div>
			<div class="cc-field-row" id="cc-f-renewal-row" hidden>
				<div class="cc-field">
					<label class="cc-label" for="cc-f-renewal-value"><?php p($l->t('Renews every')); ?></label>
					<input class="cc-input" type="number" id="cc-f-renewal-value" name="renewalValue" min="1" max="600" step="1" inputmode="numeric" />
				</div>
				<div class="cc-field">
					<label class="cc-label" for="cc-f-renewal-unit"><?php p($l->t('Unit')); ?></label>
					<select class="cc-select" id="cc-f-renewal-unit" name="renewalUnit">
						<option value="months" selected><?php p($l->t('Months')); ?></option>
						<option value="years"><?php p($l->t('Years')); ?></option>
					</select>
				</div>
			</div>
		</fieldset>

		<fieldset class="cc-fieldset">
			<legend><?php p($l->t('Cancellation')); ?></legend>
			<div class="cc-field-row">
				<div class="cc-field">
					<label class="cc-label" for="cc-f-notice-value"><?php p($l->t('Notice period')); ?></label>
					<input class="cc-input" type="number" id="cc-f-notice-value" name="noticeValue" min="0" max="36500" step="1" inputmode="numeric" placeholder="3" />
				</div>
				<div class="cc-field">
					<label class="cc-label" for="cc-f-notice-unit"><?php p($l->t('Unit')); ?></label>
					<select class="cc-select" id="cc-f-notice-unit" name="noticeUnit">
						<option value="months" selected><?php p($l->t('Months')); ?></option>
						<option value="days"><?php p($l->t('Days')); ?></option>
					</select>
				</div>
				<div class="cc-field">
					<label class="cc-label" for="cc-f-anchor"><?php p($l->t('Effective at')); ?></label>
					<select class="cc-select" id="cc-f-anchor" name="noticeAnchor">
						<option value="contract_end" selected><?php p($l->t('Contract end')); ?></option>
						<option value="month_end"><?php p($l->t('End of month')); ?></option>
						<option value="quarter_end"><?php p($l->t('End of quarter')); ?></option>
						<option value="year_end"><?php p($l->t('End of year')); ?></option>
						<option value="anytime"><?php p($l->t('Any time')); ?></option>
					</select>
				</div>
			</div>
			<p class="cc-field__hint" id="cc-anchor-hint"><?php p($l->t('“Effective at” is the date your cancellation takes effect — e.g. 3 months’ notice to month end.')); ?></p>
		</fieldset>

		<fieldset class="cc-fieldset">
			<legend><?php p($l->t('Reminders')); ?></legend>
			<p class="cc-field__hint"><?php p($l->t('Get notified this many days before the cancel-by date (choose any):')); ?></p>
			<div class="cc-check-row" id="cc-lead-days" role="group" aria-label="<?php p($l->t('Reminder lead days')); ?>">
				<label class="cc-check"><input type="checkbox" name="leadDay" value="90" /> <span>90</span></label>
				<label class="cc-check"><input type="checkbox" name="leadDay" value="60" checked /> <span>60</span></label>
				<label class="cc-check"><input type="checkbox" name="leadDay" value="30" checked /> <span>30</span></label>
				<label class="cc-check"><input type="checkbox" name="leadDay" value="14" /> <span>14</span></label>
				<label class="cc-check"><input type="checkbox" name="leadDay" value="7" /> <span>7</span></label>
				<label class="cc-check"><input type="checkbox" name="leadDay" value="1" /> <span>1</span></label>
			</div>
		</fieldset>

		<fieldset class="cc-fieldset">
			<legend><?php p($l->t('More')); ?></legend>
			<div class="cc-field-row">
				<div class="cc-field">
					<label class="cc-label" for="cc-f-costcenter"><?php p($l->t('Cost center')); ?></label>
					<input class="cc-input" type="text" id="cc-f-costcenter" name="costCenter" maxlength="64" />
				</div>
				<div class="cc-field">
					<label class="cc-label" for="cc-f-tags"><?php p($l->t('Tags')); ?></label>
					<input class="cc-input" type="text" id="cc-f-tags" name="tagsText" maxlength="400"
						placeholder="<?php p($l->t('comma-separated')); ?>" />
				</div>
			</div>
			<div class="cc-field">
				<label class="cc-label" for="cc-f-notes"><?php p($l->t('Notes')); ?></label>
				<textarea class="cc-input cc-textarea" id="cc-f-notes" name="notes" rows="4" maxlength="10000"></textarea>
			</div>
		</fieldset>

		<div class="cc-form-actions">
			<button type="submit" class="cc-btn cc-btn--primary" id="cc-form-save">
				<?php p($l->t('Save contract')); ?>
			</button>
			<a class="cc-btn cc-btn--secondary" href="<?php p($_['urls']['home'] ?? '#'); ?>"><?php p($l->t('Cancel')); ?></a>
		</div>
	</form>
</section>
