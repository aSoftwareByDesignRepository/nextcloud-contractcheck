<?php
/**
 * Settings sub-page: Transfer configuration (test → production).
 * Two cards — Export (passphrase → .nctransfer download) and Import
 * (mode → file → passphrase → mandatory dry-run → confirm) — plus the
 * "Last transfer" receipt line. The report dialog is a native <dialog>.
 *
 * Synced from apps/_shared/config-transfer — do not edit app-side.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
$isSystemAdmin = !empty($_['isSystemAdmin']);
$lastReceipt = is_array($_['transferLastReceipt'] ?? null) ? $_['transferLastReceipt'] : null;
?>
<section class="cc-card cc-section" aria-labelledby="cc-xfer-export-h" id="cc-transfer-export-card">
	<header class="cc-section__header">
		<div>
			<h2 id="cc-xfer-export-h"><?php p($l->t('Export configuration')); ?></h2>
			<p class="cc-section__sub"><?php p($l->t('Download all settings, catalogues and business data as one encrypted file — then import it on your production instance.')); ?></p>
		</div>
	</header>
	<div class="cc-callout cc-callout--info" role="note">
		<p><strong><?php p($l->t('Secrets are never included.')); ?></strong>
		<?php p($l->t('Passwords, tokens and salts stay on this instance — the file is encrypted and still does not carry them. It does contain user and group names: handle it like personal data.')); ?></p>
	</div>
	<form id="cc-transfer-export-form" class="cc-form" novalidate>
		<div class="cc-form-row">
			<label for="cc-transfer-passphrase"><?php p($l->t('Encryption passphrase')); ?></label>
			<div class="cc-input-group">
				<input type="password" id="cc-transfer-passphrase" name="passphrase" autocomplete="new-password" required minlength="12" aria-describedby="cc-transfer-passphrase-hint cc-transfer-passphrase-strength">
				<button type="button" class="cc-btn cc-btn--ghost" id="cc-transfer-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
				<button type="button" class="cc-btn cc-btn--secondary" id="cc-transfer-passphrase-generate"><?php p($l->t('Generate')); ?></button>
			</div>
			<p class="cc-form-row__hint" id="cc-transfer-passphrase-hint"><?php p($l->t('At least 12 characters. Without this passphrase the file cannot be restored — store it safely.')); ?></p>
			<p class="cc-form-row__hint cc-transfer-strength" id="cc-transfer-passphrase-strength" role="status" aria-live="polite"></p>
		</div>
		<div class="cc-actions">
			<button type="submit" class="cc-btn cc-btn--primary" id="cc-transfer-export-btn">
				<?php p($l->t('Download encrypted transfer file')); ?>
			</button>
		</div>
	</form>
</section>

<section class="cc-card cc-section" aria-labelledby="cc-xfer-import-h" id="cc-transfer-import-card">
	<header class="cc-section__header">
		<div>
			<h2 id="cc-xfer-import-h"><?php p($l->t('Import configuration')); ?></h2>
			<p class="cc-section__sub"><?php p($l->t('Pick a transfer file, enter its passphrase, review the preview — then confirm. Nothing is written before you confirm.')); ?></p>
		</div>
	</header>
	<?php if ($lastReceipt !== null): ?>
	<p class="cc-transfer-last" id="cc-transfer-last">
		<?php p($l->t('Last transfer')); ?>:
		<strong><?php p((string)($lastReceipt['at'] ?? '—')); ?></strong>
		· <?php
			$transferMode = (string)($lastReceipt['mode'] ?? '');
			if ($transferMode === 'merge') {
				p($l->t('Merge'));
			} elseif ($transferMode === 'replace') {
				p($l->t('Replace'));
			} else {
				p($transferMode !== '' ? $transferMode : '—');
			}
		?>
		· <?php p(($lastReceipt['result'] ?? '') === 'applied' ? $l->t('applied') : $l->t('failed')); ?>
		<?php if (($lastReceipt['snapshotId'] ?? null) !== null): ?>
			· <?php p($l->t('undo via snapshot')); ?> <code><?php p((string)$lastReceipt['snapshotId']); ?></code>
		<?php endif; ?>
	</p>
	<?php endif; ?>
	<form id="cc-transfer-import-form" class="cc-form" novalidate>
		<fieldset class="cc-form-row cc-fieldset">
			<legend><?php p($l->t('Mode')); ?></legend>
			<label class="cc-radio">
				<input type="radio" name="mode" value="merge" checked>
				<span><strong><?php p($l->t('Merge')); ?></strong> — <?php p($l->t('add and update; nothing is deleted')); ?></span>
			</label>
			<label class="cc-radio cc-radio--danger">
				<input type="radio" name="mode" value="replace" <?php if (!$isSystemAdmin): ?>disabled<?php endif; ?>>
				<span><strong><?php p($l->t('Replace')); ?></strong> — <?php p($l->t('make identical to the file; prod-only rows are deleted')); ?></span>
			</label>
			<p class="cc-form-row__hint" id="cc-transfer-replace-hint">
				<?php if ($isSystemAdmin): ?>
					<?php p($l->t('Replace needs maintenance mode ON (occ maintenance:mode --on) — you will type REPLACE in the preview to confirm.')); ?>
				<?php else: ?>
					<?php p($l->t('Replace is available to Nextcloud system administrators only.')); ?>
				<?php endif; ?>
			</p>
		</fieldset>
		<div class="cc-form-row">
			<label for="cc-transfer-file"><?php p($l->t('Transfer file')); ?></label>
			<input type="file" id="cc-transfer-file" name="file" accept=".nctransfer,application/json" class="cc-file-input">
		</div>
		<div class="cc-form-row">
			<label for="cc-transfer-import-passphrase"><?php p($l->t('File passphrase')); ?></label>
			<div class="cc-input-group">
				<input type="password" id="cc-transfer-import-passphrase" name="import_passphrase" autocomplete="off">
				<button type="button" class="cc-btn cc-btn--ghost" id="cc-transfer-import-passphrase-toggle" aria-pressed="false"><?php p($l->t('Show')); ?></button>
			</div>
		</div>
		<div id="cc-transfer-import-status" class="cc-form-row__hint" role="status" aria-live="polite"></div>
	</form>
</section>

<dialog id="cc-transfer-report" class="cc-dialog cc-transfer-report" aria-labelledby="cc-transfer-report-h">
	<div class="cc-dialog__body">
		<h3 id="cc-transfer-report-h"><?php p($l->t('Import preview')); ?></h3>
		<p id="cc-transfer-report-verdict" class="cc-transfer-report__verdict"></p>
		<p id="cc-transfer-report-file" class="cc-form-row__hint"></p>
		<div id="cc-transfer-report-groups"></div>
	</div>
	<footer class="cc-dialog__footer">
		<div id="cc-transfer-replace-confirm" class="cc-form-row" hidden>
			<label for="cc-transfer-replace-token"><?php p($l->t('Type REPLACE to confirm')); ?></label>
			<input type="text" id="cc-transfer-replace-token" autocomplete="off" spellcheck="false" placeholder="REPLACE">
		</div>
		<div class="cc-actions">
			<button type="button" class="cc-btn cc-btn--secondary" id="cc-transfer-cancel" value="cancel"><?php p($l->t('Cancel')); ?></button>
			<button type="button" class="cc-btn cc-btn--primary" id="cc-transfer-confirm"><?php p($l->t('Apply import')); ?></button>
		</div>
	</footer>
</dialog>

<dialog id="cc-transfer-progress" class="cc-dialog" aria-labelledby="cc-transfer-progress-h" aria-live="polite">
	<div class="cc-dialog__body">
		<h3 id="cc-transfer-progress-h"><?php p($l->t('Applying import')); ?></h3>
		<progress id="cc-transfer-progress-bar" max="100" value="0"></progress>
		<p id="cc-transfer-progress-text"><?php p($l->t('Starting…')); ?></p>
		<p class="cc-form-row__hint"><?php p($l->t('You can close this — the result is saved and shown under “Last transfer”.')); ?></p>
	</div>
</dialog>

<div id="cc-transfer-result-live" class="cc-visually-hidden" role="status" aria-live="polite"></div>
