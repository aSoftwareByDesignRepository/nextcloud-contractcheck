<?php
/** @var array $_ @var \OCP\IL10N $l */
$section = (string)($_['settingsSection'] ?? 'deadlines');
?>
<section class="cc-settings" aria-labelledby="cc-settings-heading" data-section="<?php p($section); ?>">
	<h2 id="cc-settings-heading" class="cc-sr-only"><?php p($l->t('Settings')); ?></h2>

	<?php if ($section === 'deadlines'): ?>
	<section class="cc-panel" aria-labelledby="cc-set-deadlines-h">
		<h3 id="cc-set-deadlines-h" class="cc-panel__title"><?php p($l->t('Default reminders')); ?></h3>
		<p class="cc-field__hint"><?php p($l->t('New contracts start with these reminder lead days — adjustable per contract.')); ?></p>
		<div class="cc-check-row" id="cc-set-lead-days" role="group" aria-label="<?php p($l->t('Default lead days')); ?>">
			<label class="cc-check"><input type="checkbox" name="leadDay" value="90" /> <span>90</span></label>
			<label class="cc-check"><input type="checkbox" name="leadDay" value="60" /> <span>60</span></label>
			<label class="cc-check"><input type="checkbox" name="leadDay" value="30" /> <span>30</span></label>
			<label class="cc-check"><input type="checkbox" name="leadDay" value="14" /> <span>14</span></label>
			<label class="cc-check"><input type="checkbox" name="leadDay" value="7" /> <span>7</span></label>
			<label class="cc-check"><input type="checkbox" name="leadDay" value="1" /> <span>1</span></label>
		</div>
		<div class="cc-field">
			<label class="cc-label" for="cc-set-orgname"><?php p($l->t('Organization name')); ?></label>
			<input class="cc-input" type="text" id="cc-set-orgname" name="orgName" maxlength="120"
				placeholder="<?php p($l->t('Shown in the calendar feed')); ?>" />
		</div>

	</section>

	<section class="cc-panel" aria-labelledby="cc-set-budgets-h">
		<h3 id="cc-set-budgets-h" class="cc-panel__title"><?php p($l->t('Cost-center budgets')); ?></h3>
		<p class="cc-field__hint"><?php p($l->t('Optional yearly budget per cost center — Reports compares contracted vs. planned.')); ?></p>
		<div class="cc-field">
			<label class="cc-label" for="cc-set-budgets"><?php p($l->t('Budgets')); ?></label>
			<textarea class="cc-input cc-textarea" id="cc-set-budgets" name="costCenterBudgets" rows="4"
				placeholder="<?php p($l->t('One per line: Cost center = amount per year')); ?>"
				aria-describedby="cc-set-budgets-hint"></textarea>
			<p class="cc-field__hint" id="cc-set-budgets-hint"><?php p($l->t('Example: IT = 12000.00')); ?></p>
			<p class="cc-field-error" id="cc-set-budgets-error" hidden role="alert"></p>
		</div>
	</section>
	<div class="cc-form-actions">
		<button type="button" class="cc-btn cc-btn--primary" id="cc-set-save"><?php p($l->t('Save')); ?></button>
	</div>
	<?php endif; ?>

	<?php if ($section === 'access'): ?>
	<section class="cc-panel" aria-labelledby="cc-set-access-h">
		<h3 id="cc-set-access-h" class="cc-panel__title"><?php p($l->t('Access & roles')); ?></h3>
		<div class="cc-field">
			<label class="cc-label" for="cc-set-allowed"><?php p($l->t('Who may use ContractCheck')); ?></label>
			<div class="cc-picker" id="cc-allowed-picker">
				<div class="cc-picker__chips" id="cc-allowed-chips" aria-live="polite"></div>
				<input class="cc-input cc-picker__input" type="text" id="cc-set-allowed" name="allowedSearch"
					role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="cc-allowed-results"
					placeholder="<?php p($l->t('Search groups… empty = everyone')); ?>" autocomplete="off" />
				<ul class="cc-picker__results" id="cc-allowed-results" role="listbox" hidden></ul>
			</div>
			<p class="cc-field__hint"><?php p($l->t('Groups that may open the app. Leave empty for all users.')); ?></p>
		</div>
		<div class="cc-field">
			<label class="cc-label" for="cc-set-managers"><?php p($l->t('Contract managers')); ?></label>
			<div class="cc-picker" id="cc-managers-picker">
				<div class="cc-picker__chips" id="cc-managers-chips" aria-live="polite"></div>
				<input class="cc-input cc-picker__input" type="text" id="cc-set-managers" name="managersSearch"
					role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="cc-managers-results"
					placeholder="<?php p($l->t('Search groups… default: admin')); ?>" autocomplete="off" />
				<ul class="cc-picker__results" id="cc-managers-results" role="listbox" hidden></ul>
			</div>
			<p class="cc-field__hint"><?php p($l->t('Can create, edit and archive contracts; sees all scopes and all costs.')); ?></p>
		</div>
		<div class="cc-field">
			<label class="cc-label" for="cc-set-costviewers"><?php p($l->t('Cost viewers')); ?></label>
			<div class="cc-picker" id="cc-costviewers-picker">
				<div class="cc-picker__chips" id="cc-costviewers-chips" aria-live="polite"></div>
				<input class="cc-input cc-picker__input" type="text" id="cc-set-costviewers" name="costViewersSearch"
					role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="cc-costviewers-results"
					placeholder="<?php p($l->t('Search groups…')); ?>" autocomplete="off" />
				<ul class="cc-picker__results" id="cc-costviewers-results" role="listbox" hidden></ul>
			</div>
			<p class="cc-field__hint"><?php p($l->t('Additionally allowed to see cost data (managers always can).')); ?></p>
		</div>
		<div class="cc-form-actions">
			<button type="button" class="cc-btn cc-btn--primary" id="cc-set-save-access"><?php p($l->t('Save')); ?></button>
		</div>
	</section>
	<?php endif; ?>

	<?php if ($section === 'feed'): ?>
	<section class="cc-panel" aria-labelledby="cc-set-feed-h">
		<h3 id="cc-set-feed-h" class="cc-panel__title"><?php p($l->t('Calendar feed')); ?></h3>
		<p class="cc-field__hint"><?php p($l->t('Subscribe to your visible cancellation deadlines (ICS) in any calendar app. The link is secret — anyone who has it sees your deadlines.')); ?></p>
		<div class="cc-field">
			<label class="cc-label" for="cc-feed-url"><?php p($l->t('Feed URL')); ?></label>
			<div class="cc-copy-row">
				<input class="cc-input" type="text" id="cc-feed-url" readonly value="" aria-describedby="cc-feed-hint" />
				<button type="button" class="cc-btn cc-btn--secondary" id="cc-feed-copy"><?php p($l->t('Copy')); ?></button>
			</div>
			<p class="cc-field__hint" id="cc-feed-hint"><?php p($l->t('Generate a link below first.')); ?></p>
		</div>
		<div class="cc-form-actions">
			<button type="button" class="cc-btn cc-btn--primary" id="cc-feed-mint"><?php p($l->t('Generate link')); ?></button>
			<button type="button" class="cc-btn cc-btn--secondary" id="cc-feed-revoke" hidden><?php p($l->t('Revoke link')); ?></button>
		</div>
	</section>
	<?php endif; ?>

	<?php if ($section === 'support'): ?>
	<section class="cc-panel" aria-labelledby="cc-set-support-h">
		<h3 id="cc-set-support-h" class="cc-panel__title"><?php p($l->t('Support ContractCheck')); ?></h3>
		<p><?php p($l->t('ContractCheck is free and open source. If it saves you a missed deadline, consider supporting development.')); ?></p>
		<div class="cc-btn-row">
			<a class="cc-btn cc-btn--primary" href="https://github.com/sponsors/aSoftwareByDesignRepository" target="_blank" rel="noopener noreferrer">
				<?php print_unescaped(\OCA\ContractCheck\Service\IconCatalog::render('heart-handshake')); ?>
				<?php p($l->t('GitHub Sponsors')); ?>
			</a>
			<a class="cc-btn cc-btn--secondary" href="https://nextcloud.software-by-design.de/" target="_blank" rel="noopener noreferrer">
				<?php p($l->t('Website')); ?>
			</a>
		</div>
		<h4><?php p($l->t('Commissioned features')); ?></h4>
		<p><?php p($l->t('Organizations can commission features or support: %s', ['info@software-by-design.de'])); ?></p>
	</section>
	<?php endif; ?>
</section>
