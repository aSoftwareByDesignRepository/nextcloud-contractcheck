<?php
/** @var array $_ @var \OCP\IL10N $l */
?>
<section class="cc-contracts" aria-labelledby="cc-contracts-heading">
	<h2 id="cc-contracts-heading" class="cc-sr-only"><?php p($l->t('Contract list')); ?></h2>

	<div class="cc-toolbar" role="search">
		<div class="cc-field cc-toolbar__search">
			<label class="cc-label" for="cc-contract-search"><?php p($l->t('Search')); ?></label>
			<input type="search" id="cc-contract-search" name="search" class="cc-input"
				placeholder="<?php p($l->t('Title, reference, cost center…')); ?>"
				autocomplete="off" />
		</div>
		<div class="cc-field">
			<label class="cc-label" for="cc-contract-status"><?php p($l->t('Status')); ?></label>
			<select id="cc-contract-status" name="status" class="cc-select">
				<option value=""><?php p($l->t('All')); ?></option>
				<option value="draft"><?php p($l->t('Draft')); ?></option>
				<option value="active" selected><?php p($l->t('Active')); ?></option>
				<option value="terminated"><?php p($l->t('Terminated')); ?></option>
				<option value="expired"><?php p($l->t('Expired')); ?></option>
				<option value="archived"><?php p($l->t('Archived')); ?></option>
			</select>
		</div>
		<div class="cc-field">
			<label class="cc-label" for="cc-contract-type"><?php p($l->t('Type')); ?></label>
			<select id="cc-contract-type" name="type" class="cc-select">
				<option value=""><?php p($l->t('All')); ?></option>
				<option value="rent"><?php p($l->t('Rent')); ?></option>
				<option value="leasing"><?php p($l->t('Leasing')); ?></option>
				<option value="service"><?php p($l->t('Service')); ?></option>
				<option value="insurance"><?php p($l->t('Insurance')); ?></option>
				<option value="maintenance"><?php p($l->t('Maintenance')); ?></option>
				<option value="grant"><?php p($l->t('Grant')); ?></option>
				<option value="employment_related"><?php p($l->t('Employment-related')); ?></option>
				<option value="other"><?php p($l->t('Other')); ?></option>
			</select>
		</div>
		<div class="cc-field">
			<label class="cc-label" for="cc-contract-partner"><?php p($l->t('Partner')); ?></label>
			<select id="cc-contract-partner" name="partnerId" class="cc-select">
				<option value=""><?php p($l->t('All')); ?></option>
			</select>
		</div>
		<div class="cc-field">
			<label class="cc-label" for="cc-contract-costcenter"><?php p($l->t('Cost center')); ?></label>
			<select id="cc-contract-costcenter" name="costCenter" class="cc-select">
				<option value=""><?php p($l->t('All')); ?></option>
			</select>
		</div>
		<div class="cc-field">
			<label class="cc-label" for="cc-contract-deadline"><?php p($l->t('Deadline')); ?></label>
			<select id="cc-contract-deadline" name="deadline" class="cc-select">
				<option value=""><?php p($l->t('All')); ?></option>
				<option value="cancel30"><?php p($l->t('Cancellable ≤30 days')); ?></option>
				<option value="cancel90"><?php p($l->t('Cancellable ≤90 days')); ?></option>
				<option value="missed"><?php p($l->t('Deadline missed')); ?></option>
				<option value="expiring"><?php p($l->t('Ends within 90 days')); ?></option>
				<option value="none"><?php p($l->t('No deadline')); ?></option>
			</select>
		</div>
		<div class="cc-toolbar__actions">
			<a id="cc-new-contract" class="cc-btn cc-btn--primary" href="<?php p($_['urls']['newContract'] ?? '#'); ?>" hidden>
				<?php print_unescaped(\OCA\ContractCheck\Service\IconCatalog::render('plus')); ?>
				<?php p($l->t('New contract')); ?>
			</a>
		</div>
	</div>

	<div class="cc-table-wrap">
		<table class="cc-table cc-table--responsive" id="cc-contracts-table">
			<caption class="cc-sr-only"><?php p($l->t('Contracts')); ?></caption>
			<thead>
				<tr>
					<th scope="col"><?php p($l->t('Title')); ?></th>
					<th scope="col"><?php p($l->t('Partner')); ?></th>
					<th scope="col"><?php p($l->t('Status')); ?></th>
					<th scope="col"><?php p($l->t('End')); ?></th>
					<th scope="col"><?php p($l->t('Cancel by')); ?></th>
					<th scope="col" class="cc-col-cost" hidden><?php p($l->t('Per year')); ?></th>
					<th scope="col"><span class="cc-sr-only"><?php p($l->t('Open')); ?></span></th>
				</tr>
			</thead>
			<tbody id="cc-contracts-body"></tbody>
		</table>
		<p id="cc-contracts-empty" class="cc-empty" hidden>
			<?php p($l->t('No contracts yet — add your first one and never miss a deadline again.')); ?>
		</p>
		<p id="cc-contracts-loading" class="cc-loading" role="status"><?php p($l->t('Loading…')); ?></p>
	</div>
</section>
