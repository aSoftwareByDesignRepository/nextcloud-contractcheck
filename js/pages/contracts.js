(function () {
	'use strict';
	if (!window.CC) return;
	var t = CC.t;

	if (CC.role.manager) {
		document.getElementById('cc-new-contract').hidden = false;
	}
	var costCol = document.querySelector('.cc-col-cost');
	if (CC.role.costViewer && costCol) costCol.hidden = false;

	var searchEl = document.getElementById('cc-contract-search');
	var statusEl = document.getElementById('cc-contract-status');
	var typeEl = document.getElementById('cc-contract-type');
	var partnerEl = document.getElementById('cc-contract-partner');
	var costCenterEl = document.getElementById('cc-contract-costcenter');
	var deadlineEl = document.getElementById('cc-contract-deadline');

	// Partner + cost-center options are data-derived — populate once.
	CC.api('/api/parties').then(function (parties) {
		parties.forEach(function (p) {
			partnerEl.appendChild(CC.el('option', { value: String(p.id), text: p.name }));
		});
		if (pendingPartner && partnerEl.querySelector('option[value="' + pendingPartner + '"]')) {
			partnerEl.value = pendingPartner;
			load();
		}
	}).catch(CC.handleError);
	CC.api('/api/contracts/filters').then(function (meta) {
		(meta.costCenters || []).forEach(function (cc) {
			costCenterEl.appendChild(CC.el('option', { value: cc, text: cc }));
		});
		if (pendingCostCenter && costCenterEl.querySelector('option[value="' + pendingCostCenter + '"]')) {
			costCenterEl.value = pendingCostCenter;
			load();
		}
	}).catch(CC.handleError);
	var body = document.getElementById('cc-contracts-body');
	var empty = document.getElementById('cc-contracts-empty');
	var loading = document.getElementById('cc-contracts-loading');
	var aborter = null;

	function tr(c) {
		var d = c.deadline || {};
		var missed = d.state === 'missed';
		var row = CC.el('tr', { class: 'cc-table__row' });
		var titleTd = CC.el('td', {});
		var link = CC.el('a', { href: CC.appBase() + '/contracts/' + c.id, class: 'cc-link' });
		link.appendChild(CC.el('strong', { text: c.title }));
		if (c.refNo) link.appendChild(CC.el('span', { class: 'cc-muted', text: ' · ' + c.refNo }));
		titleTd.appendChild(link);
		row.appendChild(titleTd);
		row.appendChild(CC.el('td', { text: c.partnerName || '—' }));
		var stTd = CC.el('td', {});
		stTd.appendChild(CC.statusBadge(c.status));
		row.appendChild(stTd);
		row.appendChild(CC.el('td', { text: CC.fmtDate(c.endDate) }));
		var cbTd = CC.el('td', {});
		if (missed) {
			cbTd.appendChild(CC.el('span', { class: 'cc-muted', text: t('missed → ') + CC.fmtDate(d.effectiveAt) + ' ' }));
			var b1 = CC.daysBadge(null, true); if (b1) cbTd.appendChild(b1);
		} else if (d.cancelBy) {
			cbTd.appendChild(CC.el('span', { text: CC.fmtDate(d.cancelBy) + ' ' }));
			var b2 = CC.daysBadge(d.daysLeft, false); if (b2) cbTd.appendChild(b2);
		} else {
			cbTd.textContent = '—';
		}
		row.appendChild(cbTd);
		if (CC.role.costViewer) {
			row.appendChild(CC.el('td', { class: 'cc-num', text: CC.fmtRollup(c.costs) }));
		}
		var openTd = CC.el('td', { class: 'cc-table__actions' });
		openTd.appendChild(CC.el('a', {
			class: 'cc-btn cc-btn--secondary cc-btn--small',
			href: CC.appBase() + '/contracts/' + c.id, text: t('Open')
		}));
		row.appendChild(openTd);
		return row;
	}

	function load() {
		if (aborter) aborter.abort();
		aborter = new AbortController();
		var params = new URLSearchParams();
		if (searchEl.value.trim()) params.set('search', searchEl.value.trim());
		if (statusEl.value) params.set('status', statusEl.value);
		if (typeEl.value) params.set('type', typeEl.value);
		if (partnerEl.value) params.set('partnerId', partnerEl.value);
		if (costCenterEl.value) params.set('costCenter', costCenterEl.value);
		if (deadlineEl.value) params.set('deadline', deadlineEl.value);
		loading.hidden = false;
		CC.api('/api/contracts?' + params.toString(), { signal: aborter.signal })
			.then(function (list) {
				loading.hidden = true;
				body.innerHTML = '';
				list.forEach(function (c) { body.appendChild(tr(c)); });
				empty.hidden = list.length > 0;
			}).catch(function (e) {
				if (e && e.name === 'AbortError') return;
				loading.hidden = true;
				CC.handleError(e);
			});
	}

	// Deep-linkable filters: ?status=…&deadline=…&type=…&search=… etc. —
	// dashboard stat tiles and external links land directly on a filtered view.
	var url = new URLSearchParams(window.location.search);
	var prefill = {
		search: searchEl, status: statusEl, type: typeEl,
		partnerId: partnerEl, costCenter: costCenterEl, deadline: deadlineEl
	};
	Object.keys(prefill).forEach(function (k) {
		var v = url.get(k);
		if (v !== null) {
			var el = prefill[k];
			if (el.tagName === 'SELECT' && !el.querySelector('option[value="' + v + '"]')) return;
			el.value = v;
		}
	});
	// partner/cost-center options load async — apply URL value once they land.
	var pendingPartner = url.get('partnerId');
	var pendingCostCenter = url.get('costCenter');

	searchEl.addEventListener('input', CC.debounce(load, 250));
	[statusEl, typeEl, partnerEl, costCenterEl, deadlineEl].forEach(function (el) {
		el.addEventListener('change', load);
	});
	load();
})();
