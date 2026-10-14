(function () {
	'use strict';
	if (!window.CC) return;
	var t = CC.t;

	var formPage = document.querySelector('.cc-contract-form');
	var detailPage = document.querySelector('.cc-contract-detail');

	/* ================= FORM ================= */
	if (formPage) {
		var form = document.getElementById('cc-contract-form');
		var saveBtn = document.getElementById('cc-form-save');
		var guard = CC.busyGuard(saveBtn);
		var idInput = document.getElementById('cc-f-id');
		var revInput = document.getElementById('cc-f-revision');
		var contractId = idInput.value ? parseInt(idInput.value, 10) : 0;
		var ownerPicker = null;

		// Partner options
		CC.api('/api/parties').then(function (parties) {
			var sel = document.getElementById('cc-f-partner');
			parties.forEach(function (p) {
				var opt = document.createElement('option');
				opt.value = p.id;
				opt.textContent = p.name;
				sel.appendChild(opt);
			});
			if (window.__ccPendingPartner) {
				sel.value = String(window.__ccPendingPartner);
			}
		}).catch(CC.handleError);

		// Owner picker (single-select user combobox → hidden uid)
		ownerPicker = CC.initPicker({
			container: document.getElementById('cc-owner-picker'),
			input: document.getElementById('cc-f-owner'),
			results: document.getElementById('cc-owner-results'),
			chips: document.getElementById('cc-owner-chips'),
			hidden: document.getElementById('cc-f-owner-uid'),
			multiple: false,
			search: function (term, signal) {
				return CC.api('/api/directory/users?q=' + encodeURIComponent(term), { signal: signal });
			},
			onChange: function () {}
		});

		// Term-kind conditional fields (progressive disclosure, no placeholders)
		var termSel = document.getElementById('cc-f-termkind');
		var renewalRow = document.getElementById('cc-f-renewal-row');
		var endWrap = document.getElementById('cc-f-end-wrap');
		var endInput = document.getElementById('cc-f-end');
		var anchorSel = document.getElementById('cc-f-anchor');

		var remindersFieldset = document.getElementById('cc-lead-days')
			? document.getElementById('cc-lead-days').closest('fieldset') : null;

		function syncTermFields() {
			var kind = termSel.value;
			renewalRow.hidden = kind !== 'auto_renew';
			endWrap.hidden = kind === 'indefinite';
			endInput.required = kind !== 'indefinite';
			if (kind === 'auto_renew' && !anchorSel.value) anchorSel.value = 'contract_end';
			if (kind === 'indefinite' && anchorSel.value === 'contract_end') anchorSel.value = 'anytime';
			syncReminderVisibility();
		}
		function syncReminderVisibility() {
			// anchor=anytime has no deadline — the reminder checkboxes can
			// never fire (the dispatch job skips anytime anchors outright),
			// so hiding them removes a dead control.
			if (remindersFieldset) {
				remindersFieldset.hidden = anchorSel.value === 'anytime';
			}
		}
		termSel.addEventListener('change', syncTermFields);
		anchorSel.addEventListener('change', syncReminderVisibility);
		syncTermFields();

		function payload() {
			var v = function (name) {
				var f = form.querySelector('[name="' + name + '"]');
				return f ? f.value.trim() : '';
			};
			var body = {
				title: v('title'),
				refNo: v('refNo'),
				type: v('type'),
				partnerId: v('partnerId'),
				ownerUid: document.getElementById('cc-f-owner-uid').value.trim(),
				scope: v('scope'),
				termKind: v('termKind'),
				signedAt: v('signedAt'),
				startDate: v('startDate'),
				endDate: v('endDate'),
				renewalValue: v('renewalValue'),
				renewalUnit: v('renewalUnit'),
				noticeValue: v('noticeValue'),
				noticeUnit: v('noticeUnit'),
				noticeAnchor: v('noticeAnchor'),
				leadDays: CC.getLeadDays('cc-lead-days'),
				costCenter: v('costCenter'),
				tags: v('tagsText').split(',').map(function (x) { return x.trim(); })
					.filter(function (x) { return x !== ''; }),
				notes: v('notes')
			};
			if (contractId) body.revision = revInput.value;
			return body;
		}

		function fill(c) {
			var set = function (name, val) {
				var f = form.querySelector('[name="' + name + '"]');
				if (f) f.value = val === null || val === undefined ? '' : String(val);
			};
			set('title', c.title);
			set('refNo', c.refNo);
			set('type', c.type);
			set('scope', c.scope);
			set('termKind', c.termKind);
			set('signedAt', c.signedAt);
			set('startDate', c.startDate);
			set('endDate', c.endDate);
			set('renewalValue', c.renewalValue);
			set('renewalUnit', c.renewalUnit || 'months');
			set('noticeValue', c.noticeValue);
			set('noticeUnit', c.noticeUnit || 'months');
			set('noticeAnchor', c.noticeAnchor || 'contract_end');
			set('costCenter', c.costCenter);
			set('tagsText', (c.tags || []).join(', '));
			set('notes', c.notes);
			CC.setLeadDays('cc-lead-days', c.leadDays || []);
			revInput.value = c.revision;
			if (c.partnerId) {
				var sel = document.getElementById('cc-f-partner');
				if (sel.querySelector('option[value="' + c.partnerId + '"]')) {
					sel.value = String(c.partnerId);
				} else {
					window.__ccPendingPartner = c.partnerId;
				}
			}
			if (c.ownerUid) {
				ownerPicker.setSelected([{ id: c.ownerUid, label: c.ownerUid }]);
				document.getElementById('cc-f-owner-uid').value = c.ownerUid;
			}
			syncTermFields();
		}

		if (contractId) {
			CC.api('/api/contracts/' + contractId).then(fill).catch(function (e) {
				CC.handleError(e);
				saveBtn.disabled = true;
			});
		}

		form.addEventListener('submit', function (ev) {
			ev.preventDefault();
			if (!guard.start()) return;
			if (window.CheckFieldErrors) CheckFieldErrors.clearFieldMarks(form);
			var req = contractId
				? CC.api('/api/contracts/' + contractId, { method: 'PUT', json: payload() })
				: CC.api('/api/contracts', { method: 'POST', json: payload() });
			req.then(function (c) {
				CC.toast('success', t('Saved.'));
				window.location.href = CC.appBase() + '/contracts/' + c.id;
			}).catch(function (e) {
				CC.handleError(e, form);
			}).finally(function () {
				guard.end();
			});
		});
	}

	/* ================= DETAIL ================= */
	if (detailPage) {
		var contractId = parseInt(detailPage.getAttribute('data-contract-id'), 10);
		var contract = null;
		var costGuard = null;

		function dlRow(label, value) {
			var wrap = CC.el('div', { class: 'cc-dl__row' });
			wrap.appendChild(CC.el('dt', { text: label }));
			wrap.appendChild(CC.el('dd', { text: value }));
			return wrap;
		}

		function renderDeadline(d) {
			var box = document.getElementById('cc-hero-deadline');
			box.innerHTML = '';
			if (!d || d.state === 'none') {
				box.appendChild(CC.el('p', {
					class: 'cc-hero__deadline-note',
					text: t('No deadline computable — open-ended contracts are cancellable per their notice period.')
				}));
				return;
			}
			if (d.state === 'missed') {
				box.appendChild(CC.el('p', { class: 'cc-hero__deadline-note cc-text--danger',
					text: t('Cancellation window missed — rolls to ') + CC.fmtDate(d.effectiveAt) }));
			} else {
				var p1 = CC.el('p', { class: 'cc-hero__cancel-by' });
				p1.appendChild(document.createTextNode(t('Cancel by ') + CC.fmtDate(d.cancelBy) + ' '));
				var badge = CC.daysBadge(d.daysLeft, false);
				if (badge) p1.appendChild(badge);
				box.appendChild(p1);
				box.appendChild(CC.el('p', { class: 'cc-hero__deadline-note',
					text: t('takes effect ') + CC.fmtDate(d.effectiveAt) }));
			}
		}

		function transition(c, action, extra) {
			var body = { action: action, revision: c.revision };
			if (extra) {
				Object.keys(extra).forEach(function (k) { body[k] = extra[k]; });
			}
			return CC.api('/api/contracts/' + c.id + '/transition', {
				method: 'POST', json: body
			});
		}

		// Mirror of ContractService::TRANSITIONS — buttons follow the table.
		function renderActions(c) {
			var box = document.getElementById('cc-detail-actions');
			box.innerHTML = '';
			if (!c.canEdit) return;
			var add = function (label, fn, cls, confirmMsg) {
				var b = CC.el('button', { type: 'button', class: 'cc-btn ' + (cls || 'cc-btn--secondary') });
				b.textContent = label;
				b.addEventListener('click', function () {
					if (confirmMsg && !window.confirm(confirmMsg)) return;
					b.disabled = true;
					fn().then(function () {
						b.disabled = false;
						reload();
						reloadEvents();
					}).catch(function (e) {
						b.disabled = false;
						CC.handleError(e);
					});
				});
				box.appendChild(b);
			};
			var edit = CC.el('a', {
				class: 'cc-btn cc-btn--secondary',
				href: CC.appBase() + '/contracts/' + c.id + '/edit',
				text: t('Edit')
			});
			box.appendChild(edit);
			if (c.status === 'draft') {
				add(t('Activate'), function () { return transition(c, 'activate'); }, 'cc-btn--primary');
			}
			if (c.status === 'active') {
				add(t('Terminate'), function () { return transition(c, 'terminate'); },
					'cc-btn--warn', t('Terminate this contract? Today is used as the termination date.'));
			}
			if (c.status === 'draft' || c.status === 'terminated' || c.status === 'expired') {
				add(t('Archive'), function () { return transition(c, 'archive'); },
					'cc-btn--warn', t('Archive this contract? It can be restored later.'));
			}
			if (c.status === 'archived') {
				add(t('Restore'), function () { return transition(c, 'reopen'); });
			}
		}

		function renderCosts(c) {
			var panel = document.getElementById('cc-d-costs-panel');
			if (!CC.role.costViewer) { panel.hidden = true; return; }
			panel.hidden = false;
			document.getElementById('cc-d-rollup').textContent =
				t('Annualized: ') + CC.fmtRollup(c.costs);
			var body = document.getElementById('cc-d-costs-body');
			body.innerHTML = '';
			(c.costEntries || []).forEach(function (entry) {
				var row = CC.el('tr', {});
				row.appendChild(CC.el('td', { text: entry.label }));
				row.appendChild(CC.el('td', { class: 'cc-num', text: CC.fmtCents(entry.amountCents, entry.currency) }));
				row.appendChild(CC.el('td', { text: t(entry.interval) }));
				var td = CC.el('td', {});
				if (c.canEdit) {
					var del = CC.el('button', {
						type: 'button', class: 'cc-btn cc-btn--secondary cc-btn--small',
						text: t('Remove'), 'aria-label': t('Remove cost entry ') + entry.label
					});
					del.addEventListener('click', function () {
						CC.api('/api/contracts/' + c.id + '/costs/' + entry.id, { method: 'DELETE' })
							.then(reload).catch(CC.handleError);
					});
					td.appendChild(del);
				}
				row.appendChild(td);
				body.appendChild(row);
			});
			var costForm = document.getElementById('cc-cost-form');
			costForm.hidden = !c.canEdit;
		}

		function renderDocs(c) {
			var ul = document.getElementById('cc-d-docs');
			ul.innerHTML = '';
			var docs = c.documents || [];
			docs.forEach(function (doc) {
				var li = CC.el('li', { class: 'cc-doc-list__item' });
				var name = CC.el('span', { class: 'cc-doc-list__name', text: doc.name || doc.fileName || doc.path });
				li.appendChild(name);
				li.appendChild(CC.el('span', { class: 'cc-badge cc-badge--neutral', text: t(doc.kind || 'contract') }));
				if (doc.missing) {
					li.appendChild(CC.el('span', { class: 'cc-badge cc-badge--danger', text: t('file missing') }));
				}
				if (doc.url && !doc.missing) {
					li.appendChild(CC.el('a', { class: 'cc-btn cc-btn--secondary cc-btn--small', href: doc.url, text: t('Open') }));
				}
				if (c.canEdit) {
					var del = CC.el('button', { type: 'button', class: 'cc-btn cc-btn--secondary cc-btn--small', text: t('Unlink') });
					del.addEventListener('click', function () {
						CC.api('/api/contracts/' + contractId + '/documents/' + doc.id, { method: 'DELETE' })
							.then(function () { reload(); reloadEvents(); }).catch(CC.handleError);
					});
					li.appendChild(del);
				}
				ul.appendChild(li);
			});
			document.getElementById('cc-d-docs-empty').hidden = docs.length > 0;
		}

		function renderEvents(events) {
			var ol = document.getElementById('cc-d-events');
			ol.innerHTML = '';
			(events || []).forEach(function (ev) {
				var li = CC.el('li', { class: 'cc-event-list__item' });
				li.appendChild(CC.el('span', { class: 'cc-event-list__type', text: t(ev.event) }));
				li.appendChild(CC.el('span', { class: 'cc-muted', text: (ev.createdAt || '').slice(0, 16).replace('T', ' ') + ' · ' + (ev.actorUid || '—') }));
				ol.appendChild(li);
			});
			document.getElementById('cc-d-events-empty').hidden = !(events || []).length;
		}

		function render(c) {
			contract = c;
			document.getElementById('cc-hero-title').textContent = c.title;
			document.getElementById('cc-hero-meta').textContent =
				(c.partnerName || t('no partner')) + (c.refNo ? ' · ' + c.refNo : '');
			var badges = document.getElementById('cc-hero-badges');
			badges.innerHTML = '';
			badges.appendChild(CC.statusBadge(c.status));
			badges.appendChild(CC.el('span', { class: 'cc-badge cc-badge--neutral', text: t(c.type) }));
			badges.appendChild(CC.el('span', { class: 'cc-badge cc-badge--neutral', text: t(c.scope) }));
			renderDeadline(c.deadline);
			renderActions(c);

			var info = document.getElementById('cc-d-info');
			info.innerHTML = '';
			info.appendChild(dlRow(t('Type'), t(c.type)));
			info.appendChild(dlRow(t('Visibility'), t(c.scope)));
			if (c.ownerUid) info.appendChild(dlRow(t('Owner'), c.ownerUid));
			info.appendChild(dlRow(t('Signed'), CC.fmtDate(c.signedAt)));
			info.appendChild(dlRow(t('Start'), CC.fmtDate(c.startDate)));
			info.appendChild(dlRow(t('End'), c.endDate ? CC.fmtDate(c.endDate) : t('open-ended')));
			if (c.terminatedAt) info.appendChild(dlRow(t('Terminated'), CC.fmtDate(c.terminatedAt)));
			if (c.termKind === 'auto_renew') {
				info.appendChild(dlRow(t('Renews every'), (c.renewalValue || '—') + ' ' + t(c.renewalUnit || 'months')));
			}
			if (c.noticeValue !== null && c.noticeValue !== undefined) {
				info.appendChild(dlRow(t('Notice period'), c.noticeValue + ' ' + t(c.noticeUnit || 'months')));
			}
			info.appendChild(dlRow(t('Effective at'), t(c.noticeAnchor || 'contract_end')));
			if (c.leadDays && c.leadDays.length) info.appendChild(dlRow(t('Remind before'), c.leadDays.join(', ') + ' ' + t('days')));
			if (c.costCenter) info.appendChild(dlRow(t('Cost center'), c.costCenter));
			if (c.tags && c.tags.length) info.appendChild(dlRow(t('Tags'), c.tags.join(', ')));
			if (c.notes) info.appendChild(dlRow(t('Notes'), c.notes));

			renderCosts(c);
			renderDocs(c);

			document.getElementById('cc-detail-loading').hidden = true;
			document.getElementById('cc-detail-root').hidden = false;
		}

		function reload() {
			return CC.api('/api/contracts/' + contractId).then(render).catch(CC.handleError);
		}
		function reloadEvents() {
			CC.api('/api/contracts/' + contractId + '/events?limit=20')
				.then(renderEvents).catch(function () { /* history is best-effort */ });
		}
		reload();
		reloadEvents();

		// Cost add form
		var costForm = document.getElementById('cc-cost-form');
		costGuard = CC.busyGuard(costForm.querySelector('button[type="submit"]'));
		costForm.addEventListener('submit', function (ev) {
			ev.preventDefault();
			if (!costGuard.start()) return;
			var fd = new FormData(costForm);
			// User enters a decimal amount in currency units ("49,90" or
			// "49.90"); the API takes integer cents.
			var parsed = CC.parseAmount(fd.get('amount'));
			if (parsed === null) {
				CC.handleError({ error: 'VALIDATION', message: t('Enter an amount like 49,90'),
					fields: { amount: t('Enter an amount like 49,90') } }, costForm);
				costGuard.end();
				return;
			}
			CC.api('/api/contracts/' + contractId + '/costs', {
				method: 'POST',
				json: {
					label: fd.get('label'),
					amountCents: parsed,
					currency: fd.get('currency') || 'EUR',
					interval: fd.get('interval')
				}
			}).then(function () {
				costForm.reset();
				reload();
				reloadEvents();
			}).catch(function (e) {
				CC.handleError(e, costForm);
			}).finally(function () { costGuard.end(); });
		});

		// Document picker (file search → link)
		CC.initPicker({
			container: document.getElementById('cc-doc-picker'),
			input: document.getElementById('cc-doc-search'),
			results: document.getElementById('cc-doc-results'),
			chips: null,
			hidden: null,
			multiple: false,
			search: function (term, signal) {
				return CC.api('/api/files/search?q=' + encodeURIComponent(term), { signal: signal });
			},
			onChange: function (fileId) {
				if (!fileId) return;
				var kind = document.getElementById('cc-doc-kind').value;
				CC.api('/api/contracts/' + contractId + '/documents', {
					method: 'POST',
					json: { fileId: fileId, kind: kind }
				}).then(function () { reload(); reloadEvents(); }).catch(CC.handleError);
			}
		});
	}
})();
