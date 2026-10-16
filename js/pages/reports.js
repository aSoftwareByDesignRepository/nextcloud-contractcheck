(function () {
	'use strict';
	if (!window.CC) return;
	var t = CC.t;

	var empty = document.getElementById('cc-rollup-empty');
	function rollupRows(tbody, rows, labelFn) {
		var body = document.getElementById(tbody);
		var colLbl = CC.colLabels(body && body.closest('table'));
		body.innerHTML = '';
		rows.forEach(function (r) {
			var tr = CC.el('tr', {});
			tr.appendChild(CC.el('td', { 'data-label': colLbl[0], text: labelFn(r) }));
			tr.appendChild(CC.el('td', { 'data-label': colLbl[1], class: 'cc-num', text: String(r.contracts) }));
			tr.appendChild(CC.el('td', { 'data-label': colLbl[2], class: 'cc-num', text: CC.fmtCentsMap(r.annual_cents) }));
			tr.appendChild(CC.el('td', { 'data-label': colLbl[3], class: 'cc-num', text: CC.fmtCentsMap(r.once_cents) }));
			body.appendChild(tr);
		});
		return body;
	}

	var typeNames = {
		rent: t('Rent'), leasing: t('Leasing'), service: t('Service'), insurance: t('Insurance'),
		maintenance: t('Maintenance'), grant: t('Grant'), employment_related: t('Employment-related'), other: t('Other')
	};

	if (!CC.role.costViewer) {
		empty.textContent = t('Cost data is hidden for your role.');
		empty.hidden = false;
		return;
	}
	CC.api('/api/contracts?limit=500').then(function (list) {
		var body = document.getElementById('cc-rollup-body');
		var colLbl = CC.colLabels(body.closest('table'));
		body.innerHTML = '';
		var rows = list.filter(function (c) {
			return c.costs && c.costs.entry_count > 0;
		});
		rows.forEach(function (c) {
			var tr = CC.el('tr', {});
			var td = CC.el('td', { 'data-label': colLbl[0] });
			td.appendChild(CC.el('a', {
				class: 'cc-link',
				href: CC.appBase() + '/contracts/' + c.id,
				text: c.title
			}));
			tr.appendChild(td);
			tr.appendChild(CC.el('td', { 'data-label': colLbl[1], class: 'cc-num', text: CC.fmtRollup(c.costs) }));
			body.appendChild(tr);
		});
		empty.hidden = rows.length > 0;
	}).catch(CC.handleError);

	CC.api('/api/reports/rollup?groupBy=type').then(function (res) {
		var body = rollupRows('cc-rollup-type-body', res.rows || [], function (r) {
			return typeNames[r.key] || r.key;
		});
		document.getElementById('cc-rollup-type-empty').hidden = body.children.length > 0;
	}).catch(CC.handleError);

	CC.api('/api/reports/rollup?groupBy=costCenter').then(function (res) {
		var body = document.getElementById('cc-rollup-cc-body');
		var colLbl = CC.colLabels(body.closest('table'));
		body.innerHTML = '';
		(res.rows || []).forEach(function (r) {
			var tr = CC.el('tr', {});
			tr.appendChild(CC.el('td', { 'data-label': colLbl[0], text: r.key === '' ? t('(no cost center)') : r.key }));
			tr.appendChild(CC.el('td', { 'data-label': colLbl[1], class: 'cc-num', text: String(r.contracts) }));
			tr.appendChild(CC.el('td', { 'data-label': colLbl[2], class: 'cc-num', text: CC.fmtRollupParts(r) }));
			var planned = r.planned_cents;
			tr.appendChild(CC.el('td', { 'data-label': colLbl[3], class: 'cc-num', text: planned === null || planned === undefined ? '—' : CC.fmtCents(planned, 'EUR') }));
			var diffTd = CC.el('td', { 'data-label': colLbl[4], class: 'cc-num' });
			var curs = r.annual_cents ? Object.keys(r.annual_cents) : [];
			var eurOnly = curs.length === 0 || (curs.length === 1 && curs[0] === 'EUR');
			if (planned !== null && planned !== undefined && eurOnly) {
				var contracted = (r.annual_cents && r.annual_cents.EUR) || 0;
				var diff = planned - contracted;
				var badge = CC.el('span', {
					class: 'cc-badge ' + (diff < 0 ? 'cc-badge--danger' : 'cc-badge--neutral'),
					text: (diff < 0 ? '−' : '') + CC.fmtCents(Math.abs(diff), 'EUR')
				});
				diffTd.appendChild(badge);
			} else {
				diffTd.textContent = '—';
			}
			tr.appendChild(diffTd);
			body.appendChild(tr);
		});
		document.getElementById('cc-rollup-cc-empty').hidden = body.children.length > 0;
	}).catch(CC.handleError);
})();
