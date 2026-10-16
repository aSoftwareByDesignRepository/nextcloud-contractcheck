(function () {
	'use strict';
	if (!window.CC) return;
	var t = CC.t;

	var form = document.getElementById('cc-import-form');
	var fileInput = document.getElementById('cc-import-file');
	var submitBtn = document.getElementById('cc-import-submit');
	var guard = CC.busyGuard(submitBtn);
	var result = document.getElementById('cc-import-result');

	// Zero-friction template: a real downloadable CSV with the header the
	// importer expects plus one example row — no server round-trip.
	document.getElementById('cc-import-template').addEventListener('click', function () {
		var csv = 'title,type,partner,start,end,term,renewal,notice,anchor,cost_center,notes\n'
			+ '"' + t('Office rent') + '","service","' + t('Landlord Ltd') + '","2026-01-01","2028-01-01","auto_renew","12 months","3 months","contract_end","HQ","' + t('Example row — delete before import') + '"\n';
		var blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8' });
		var a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = 'contractcheck-import-template.csv';
		document.body.appendChild(a);
		a.click();
		a.remove();
		URL.revokeObjectURL(a.href);
	});

	form.addEventListener('submit', function (ev) {
		ev.preventDefault();
		if (!fileInput.files || !fileInput.files.length) {
			CC.toast('error', t('Choose a CSV file first.'));
			return;
		}
		if (!guard.start()) return;
		var fd = new FormData();
		fd.append('file', fileInput.files[0]);
		CC.api('/api/import/contracts', { method: 'POST', formData: fd })
			.then(function (res) {
				result.hidden = false;
				var summary = document.getElementById('cc-import-summary');
				summary.textContent = res.imported === 1
					? t('Imported 1 contract.')
					: t('Imported {n} contracts.', { n: res.imported });
				var errors = res.errors || [];
				var table = document.getElementById('cc-import-errors-wrap');
				var body = document.getElementById('cc-import-errors-body');
				body.innerHTML = '';
				var errLabels = CC.colLabels(document.getElementById('cc-import-errors'));
				errors.forEach(function (err) {
					var tr = CC.el('tr', {});
					tr.appendChild(CC.el('td', { 'data-label': errLabels[0] || '', text: String(err.line) }));
					tr.appendChild(CC.el('td', { 'data-label': errLabels[1] || '', text: err.field || '—' }));
					tr.appendChild(CC.el('td', { 'data-label': errLabels[2] || '', text: err.message }));
					body.appendChild(tr);
				});
				table.hidden = errors.length === 0;
				var warnings = res.warnings || [];
				var wTable = document.getElementById('cc-import-warnings-wrap');
				var wBody = document.getElementById('cc-import-warnings-body');
				wBody.innerHTML = '';
				var wLabels = CC.colLabels(document.getElementById('cc-import-warnings'));
				warnings.forEach(function (w) {
					var tr = CC.el('tr', {});
					tr.appendChild(CC.el('td', { 'data-label': wLabels[0] || '', text: String(w.line) }));
					tr.appendChild(CC.el('td', { 'data-label': wLabels[1] || '', text: w.field || '—' }));
					tr.appendChild(CC.el('td', { 'data-label': wLabels[2] || '', text: w.message }));
					wBody.appendChild(tr);
				});
				wTable.hidden = warnings.length === 0;
				CC.toast(errors.length ? 'error' : 'success',
					errors.length ? t('Import finished with errors.') : t('Import finished.'));
			}).catch(function (e) {
				CC.handleError(e, form);
			}).finally(function () { guard.end(); });
	});
})();
