(function () {
	'use strict';
	if (!window.CC) return;
	var t = CC.t;

	var eventSel = document.getElementById('cc-audit-event');
	var fromEl = document.getElementById('cc-audit-from');
	var toEl = document.getElementById('cc-audit-to');
	var body = document.getElementById('cc-audit-body');
	var empty = document.getElementById('cc-audit-empty');
	var aborter = null;

	function load() {
		if (aborter) aborter.abort();
		aborter = new AbortController();
		var params = new URLSearchParams();
		if (eventSel.value) params.set('event', eventSel.value);
		if (fromEl.value) params.set('from', fromEl.value);
		if (toEl.value) params.set('to', toEl.value + ' 23:59:59');
		CC.api('/api/audit?' + params.toString(), { signal: aborter.signal })
			.then(function (list) {
				body.innerHTML = '';
				list.forEach(function (ev) {
					var tr = CC.el('tr', {});
					tr.appendChild(CC.el('td', { text: (ev.createdAt || '').slice(0, 16).replace('T', ' ') }));
					var evTd = CC.el('td', {});
					evTd.appendChild(CC.el('span', { class: 'cc-badge cc-badge--neutral', text: t(ev.event) }));
					tr.appendChild(evTd);
					var cTd = CC.el('td', {});
					if (ev.contractId) {
						cTd.appendChild(CC.el('a', {
							class: 'cc-link',
							href: CC.appBase() + '/contracts/' + ev.contractId,
							text: (ev.contractTitle || ('#' + ev.contractId))
						}));
					} else {
						cTd.textContent = '—';
					}
					tr.appendChild(cTd);
					tr.appendChild(CC.el('td', { text: ev.actorUid || '—' }));
					body.appendChild(tr);
				});
				empty.hidden = list.length > 0;
			}).catch(function (e) {
				if (e && e.name === 'AbortError') return;
				CC.handleError(e);
			});
	}

	eventSel.addEventListener('change', load);
	fromEl.addEventListener('change', load);
	toEl.addEventListener('change', load);
	load();
})();
