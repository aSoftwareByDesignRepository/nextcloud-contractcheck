(function () {
	'use strict';
	if (!window.CC) return;
	var t = CC.t;

	var body = document.getElementById('cc-parties-body');
	var empty = document.getElementById('cc-parties-empty');
	var emptyDefault = empty.textContent;
	var searchEl = document.getElementById('cc-party-search');
	var archivedEl = document.getElementById('cc-party-archived');
	var dialog = document.getElementById('cc-party-dialog');
	var form = document.getElementById('cc-party-form');
	var newBtn = document.getElementById('cc-party-new');
	var guard = CC.busyGuard(form.querySelector('button[type="submit"]'));
	var colLbl = CC.colLabels(document.getElementById('cc-parties-table'));
	var aborter = null;

	if (CC.role.manager) newBtn.hidden = false;

	function row(p) {
		var tr = CC.el('tr', {});
		tr.appendChild(CC.el('td', { 'data-label': colLbl[0] }, [CC.el('strong', { text: p.name })]));
		tr.appendChild(CC.el('td', { 'data-label': colLbl[1], text: p.contactName || '—' }));
		tr.appendChild(CC.el('td', { 'data-label': colLbl[2], text: p.contactEmail || '—' }));
		var st = CC.el('td', { 'data-label': colLbl[3] });
		st.appendChild(CC.el('span', {
			class: 'cc-badge ' + (p.archived ? 'cc-badge--neutral' : 'cc-badge--success'),
			text: p.archived ? t('archived') : t('active')
		}));
		tr.appendChild(st);
		var act = CC.el('td', { 'data-label': colLbl[4], class: 'cc-table__actions' });
		if (CC.role.manager) {
			var edit = CC.el('button', { type: 'button', class: 'cc-btn cc-btn--secondary cc-btn--small', text: t('Edit') });
			edit.addEventListener('click', function () { openDialog(p); });
			act.appendChild(edit);
			var arch = CC.el('button', {
				type: 'button', class: 'cc-btn cc-btn--secondary cc-btn--small',
				text: p.archived ? t('Restore') : t('Archive')
			});
			arch.addEventListener('click', function () {
				CC.api('/api/parties/' + p.id + '/' + (p.archived ? 'unarchive' : 'archive'), { method: 'POST' })
					.then(load).catch(CC.handleError);
			});
			act.appendChild(arch);
		}
		tr.appendChild(act);
		return tr;
	}

	function load() {
		if (aborter) aborter.abort();
		aborter = new AbortController();
		var params = new URLSearchParams();
		if (searchEl.value.trim()) params.set('search', searchEl.value.trim());
		if (archivedEl.checked) params.set('includeArchived', '1');
		CC.api('/api/parties?' + params.toString(), { signal: aborter.signal })
			.then(function (list) {
				body.innerHTML = '';
				list.forEach(function (p) { body.appendChild(row(p)); });
				if (list.length === 0) {
					empty.textContent = searchEl.value.trim() === ''
						? emptyDefault
						: t('No parties match these filters.');
				}
				empty.hidden = list.length > 0;
			}).catch(function (e) {
				if (e && e.name === 'AbortError') return;
				CC.handleError(e);
			});
	}

	function openDialog(p) {
		form.reset();
		if (window.CheckFieldErrors) CheckFieldErrors.clearFieldMarks(form);
		document.getElementById('cc-p-id').value = p ? p.id : '';
		document.getElementById('cc-p-name').value = p ? p.name : '';
		document.getElementById('cc-p-contact').value = (p && p.contactName) || '';
		document.getElementById('cc-p-email').value = (p && p.contactEmail) || '';
		document.getElementById('cc-p-phone').value = (p && p.contactPhone) || '';
		document.getElementById('cc-p-address').value = (p && p.address) || '';
		document.getElementById('cc-p-notes').value = (p && p.notes) || '';
		document.getElementById('cc-party-dialog-title').textContent =
			p ? t('Edit party') : t('New party');
		dialog.showModal();
	}

	document.getElementById('cc-p-cancel').addEventListener('click', function () {
		dialog.close();
	});
	// Manual Escape path: NC's notifications app preventDefault()s the global
	// Escape keydown, which can suppress the native dialog `cancel` event.
	dialog.addEventListener('keydown', function (ev) {
		if (ev.key === 'Escape' && dialog.open) {
			ev.preventDefault();
			ev.stopPropagation();
			dialog.close();
		}
	});
	newBtn.addEventListener('click', function () { openDialog(null); });

	form.addEventListener('submit', function (ev) {
		ev.preventDefault();
		if (!guard.start()) return;
		var id = document.getElementById('cc-p-id').value;
		var body = {
			name: document.getElementById('cc-p-name').value,
			contactName: document.getElementById('cc-p-contact').value,
			contactEmail: document.getElementById('cc-p-email').value,
			contactPhone: document.getElementById('cc-p-phone').value,
			address: document.getElementById('cc-p-address').value,
			notes: document.getElementById('cc-p-notes').value
		};
		var req = id
			? CC.api('/api/parties/' + id, { method: 'PUT', json: body })
			: CC.api('/api/parties', { method: 'POST', json: body });
		req.then(function () {
			dialog.close();
			CC.toast('success', t('Saved.'));
			load();
		}).catch(function (e) {
			ev.preventDefault();
			CC.handleError(e, form);
		}).finally(function () { guard.end(); });
	});

	searchEl.addEventListener('input', CC.debounce(load, 250));
	archivedEl.addEventListener('change', load);
	load();
})();
