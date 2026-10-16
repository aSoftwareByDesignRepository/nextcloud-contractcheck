(function () {
	'use strict';

	/**
	 * ContractCheck front-end kernel.
	 * - ccApi: fetch wrapper around the {success,data|error,fields} envelope
	 * - pickers: accessible combobox (users / groups / files) with abortable
	 *   searches and removable chips — never raw uid input
	 * - helpers: formatting, debounce, element creation
	 */

	var boot = {};
	try {
		boot = (window.OCP && OCP.InitialState && OCP.InitialState.loadState)
			? OCP.InitialState.loadState('contractcheck', 'boot') : {};
	} catch (e) {
		boot = {};
	}
	var role = (boot && boot.role) || { manager: false, costViewer: false };

	function t(text) {
		if (window.OC && OC.L10N && OC.L10N.translate) {
			return OC.L10N.translate('contractcheck', text);
		}
		return text;
	}

	function el(tag, attrs, children) {
		var node = document.createElement(tag);
		if (attrs) {
			Object.keys(attrs).forEach(function (k) {
				if (k === 'class') node.className = attrs[k];
				else if (k === 'text') node.textContent = attrs[k];
				else if (k === 'html') node.innerHTML = attrs[k];
				else node.setAttribute(k, attrs[k]);
			});
		}
		(children || []).forEach(function (c) {
			if (c) node.appendChild(typeof c === 'string' ? document.createTextNode(c) : c);
		});
		return node;
	}

	/* Column labels for the ≤768px card reflow (.cc-table--responsive paints
	   attr(data-label) before each cell). Read them from the live thead so the
	   label always matches the translated header — never a second vocabulary. */
	function colLabels(table) {
		var labels = [];
		if (table) {
			table.querySelectorAll('thead th').forEach(function (th) {
				labels.push((th.textContent || '').trim());
			});
		}
		return labels;
	}

	/* ---------- API ---------- */

	function ApiError(payload, status) {
		this.error = (payload && payload.error) || 'SERVER_ERROR';
		this.message = (payload && payload.message) || 'Unexpected error';
		this.fields = (payload && payload.fields) || {};
		this.status = status || 0;
	}

	function requestToken() {
		if (window.OC && OC.requestToken) return OC.requestToken;
		var meta = document.querySelector('meta[name="requesttoken"]');
		var input = document.getElementById('requesttoken');
		return (meta && meta.getAttribute('content')) || (input && input.value) || '';
	}

	function appBase() {
		var webroot = (window.OC && OC.getRootPath) ? OC.getRootPath() : '';
		return webroot + '/apps/contractcheck';
	}

	// Nextcloud's /f/<fileid> route resolves a file id to its Files location.
	function fileUrl(fileId) {
		var webroot = (window.OC && OC.getRootPath) ? OC.getRootPath() : '';
		return webroot + '/f/' + encodeURIComponent(String(fileId));
	}

	function ccApi(path, opts) {
		opts = opts || {};
		var headers = {
			'OCS-APIREQUEST': 'true',
			'Accept': 'application/json',
			'requesttoken': requestToken()
		};
		var init = { method: opts.method || 'GET', headers: headers, credentials: 'same-origin' };
		if (opts.json !== undefined) {
			headers['Content-Type'] = 'application/json';
			init.body = JSON.stringify(opts.json);
		} else if (opts.formData) {
			init.body = opts.formData;
		}
		if (opts.signal) init.signal = opts.signal;

		return fetch(appBase() + path, init).then(function (res) {
			return res.json().catch(function () { return {}; }).then(function (body) {
				if (!res.ok || body.success === false) {
					throw new ApiError(body, res.status);
				}
				return body.data;
			});
		});
	}

	/* ---------- formatting ---------- */

	function fmtDate(iso) {
		if (!iso) return '—';
		try {
			return new Date(iso + 'T00:00:00').toLocaleDateString(undefined, {
				year: 'numeric', month: 'short', day: 'numeric'
			});
		} catch (e) { return iso; }
	}

	function fmtCents(cents, currency) {
		if (cents === null || cents === undefined) return '—';
		var amount = cents / 100;
		try {
			return amount.toLocaleString(undefined, {
				style: 'currency', currency: currency || 'EUR'
			});
		} catch (e) {
			return amount.toFixed(2) + ' ' + (currency || '');
		}
	}

	function fmtRollup(rollup) {
		var annual = fmtRollupParts(rollup);
		if (!rollup || !rollup.once_cents) return annual;
		var once = [];
		Object.keys(rollup.once_cents).forEach(function (cur) {
			if (rollup.once_cents[cur]) once.push(fmtCents(rollup.once_cents[cur], cur));
		});
		if (!once.length) return annual;
		// US-201 AC-5: one-time costs never enter the annualized figure but
		// stay visible in the total view.
		var suffix = once.join(' / ') + ' ' + t('one-time');
		return annual !== '—' ? annual + ' · ' + suffix : suffix;
	}

	function fmtRollupParts(rollup) {
		return fmtCentsMap(rollup && rollup.annual_cents);
	}

	function fmtCentsMap(map) {
		if (!map) return '—';
		var parts = [];
		Object.keys(map).forEach(function (cur) {
			parts.push(fmtCents(map[cur], cur));
		});
		return parts.length ? parts.join(' / ') : '—';
	}

	function daysBadge(daysLeft, missed) {
		if (missed) {
			return el('span', { class: 'cc-badge cc-badge--danger', text: t('missed') });
		}
		if (daysLeft === null || daysLeft === undefined) return null;
		var cls = 'cc-badge--neutral';
		if (daysLeft <= 7) cls = 'cc-badge--danger';
		else if (daysLeft <= 30) cls = 'cc-badge--warn';
		return el('span', {
			class: 'cc-badge ' + cls,
			text: daysLeft <= 0 ? t('today') : daysLeft + ' ' + t('days')
		});
	}

	function statusBadge(status) {
		var map = {
			draft: 'cc-badge--neutral', active: 'cc-badge--success',
			terminated: 'cc-badge--warn', expired: 'cc-badge--danger',
			archived: 'cc-badge--neutral'
		};
		return el('span', {
			class: 'cc-badge ' + (map[status] || 'cc-badge--neutral'),
			text: enumLabel('status', status)
		});
	}

	// Display labels for stable enum tokens (API values are snake_case and
	// never meant to be rendered). Mirrors ContractValidator, ContractEvent,
	// ContractDocument::KINDS and CostRollup::INTERVALS.
	var ENUM_LABELS = {
		status: {
			draft: 'Draft', active: 'Active', terminated: 'Terminated',
			expired: 'Expired', archived: 'Archived'
		},
		type: {
			rent: 'Rent', leasing: 'Leasing', service: 'Service',
			insurance: 'Insurance', maintenance: 'Maintenance', grant: 'Grant',
			employment_related: 'Employment-related', other: 'Other'
		},
		scope: {
			org: 'Whole organization', dept: 'Owner’s group(s)',
			restricted: 'Only owner & managers'
		},
		termKind: {
			fixed: 'Fixed term', auto_renew: 'Auto-renewing', indefinite: 'Open-ended'
		},
		unit: { days: 'Days', months: 'Months', years: 'Years' },
		interval: { monthly: 'monthly', quarterly: 'quarterly', yearly: 'yearly', once: 'once' },
		noticeAnchor: {
			contract_end: 'Contract end', month_end: 'End of month',
			quarter_end: 'End of quarter', year_end: 'End of year', anytime: 'Any time'
		},
		docKind: {
			contract: 'Contract', amendment: 'Amendment', termination: 'Termination',
			invoice: 'Invoice', other: 'Other'
		},
		event: {
			created: 'Created', updated: 'Updated', activated: 'Activated',
			terminated: 'Terminated', expired: 'Expired', renewed: 'Renewed',
			archived: 'Archived', reopened: 'Reopened',
			doc_linked: 'Document linked', doc_unlinked: 'Document unlinked',
			doc_missing: 'Document missing',
			cost_added: 'Cost added', cost_updated: 'Cost updated', cost_removed: 'Cost removed',
			reminder_sent: 'Reminder sent', imported: 'Imported'
		}
	};

	function enumLabel(domain, value) {
		var label = (ENUM_LABELS[domain] || {})[value];
		return label === undefined ? String(value == null ? '' : value) : t(label);
	}

	function debounce(fn, ms) {
		var h = null;
		return function () {
			var args = arguments, self = this;
			window.clearTimeout(h);
			h = window.setTimeout(function () { fn.apply(self, args); }, ms || 250);
		};
	}

	function toast(kind, msg) {
		if (!window.ContractCheckToasts) return;
		if (kind === 'error') ContractCheckToasts.showError(msg);
		else ContractCheckToasts.showSuccess(msg);
	}

	function handleError(e, form) {
		if (e && e.fields && Object.keys(e.fields).length && window.CheckFieldErrors) {
			CheckFieldErrors.markValidationFields(e.fields);
		}
		if (e && e.error === 'CONFLICT') {
			toast('error', (boot.i18n && boot.i18n.conflict) || t('This record changed in the meantime. Reload and try again.'));
			return;
		}
		toast('error', (e && e.message) || (boot.i18n && boot.i18n.error) || t('Something went wrong.'));
	}

	/* ---------- pickers (combobox + chips) ---------- */

	/**
	 * Accessible searchable picker: input combobox → async result list →
	 * selected entries as removable chips. Works for users, groups, files.
	 *
	 * @param {object} cfg
	 *  container: element containing input/results/chips regions
	 *  input: combobox input
	 *  results: <ul role=listbox>
	 *  chips: chips container (may be null → single-select stored in hidden input)
	 *  hidden: hidden input (single mode) OR null (chips mode keeps array)
	 *  search(term, signal) → Promise<list<{id,label}>>
	 *  onChange(list|id)
	 *  multiple: bool
	 */
	function initPicker(cfg) {
		var state = { items: [], aborter: null, selected: [] };
		var input = cfg.input, results = cfg.results;

		function renderChips() {
			if (!cfg.chips) return;
			cfg.chips.innerHTML = '';
			state.selected.forEach(function (item) {
				var chip = el('span', { class: 'cc-chip', role: 'listitem' }, [
					el('span', { class: 'cc-chip__label', text: item.label }),
					(function () {
						var b = el('button', {
							type: 'button', class: 'cc-chip__remove',
							'aria-label': t('Remove') + ' ' + item.label
						});
						b.textContent = '×';
						b.addEventListener('click', function () {
							state.selected = state.selected.filter(function (x) { return x.id !== item.id; });
							renderChips();
							emit();
							input.focus();
						});
						return b;
					})()
				]);
				cfg.chips.appendChild(chip);
			});
		}

		function emit() {
			if (cfg.multiple) {
				cfg.onChange(state.selected.map(function (x) { return x.id; }));
			} else {
				cfg.onChange(state.selected.length ? state.selected[0].id : '');
			}
		}

		function hideResults() {
			results.hidden = true;
			results.innerHTML = '';
			input.setAttribute('aria-expanded', 'false');
		}

		function choose(item) {
			if (cfg.multiple) {
				if (!state.selected.some(function (x) { return x.id === item.id; })) {
					state.selected.push(item);
				}
			} else {
				state.selected = [item];
				if (cfg.hidden) cfg.hidden.value = item.id;
			}
			input.value = '';
			renderChips();
			emit();
			hideResults();
		}

		var doSearch = debounce(function () {
			var term = input.value.trim();
			if (term.length < 1) { hideResults(); return; }
			if (state.aborter) state.aborter.abort();
			state.aborter = new AbortController();
			cfg.search(term, state.aborter.signal).then(function (items) {
				results.innerHTML = '';
				items.slice(0, 8).forEach(function (item) {
					var li = el('li', {
						class: 'cc-picker__result', role: 'option', tabindex: '-1',
						'data-id': String(item.id)
					}, [el('span', { text: item.label })]);
					li.addEventListener('mousedown', function (ev) {
						ev.preventDefault();
						choose(item);
					});
					results.appendChild(li);
				});
				results.hidden = items.length === 0;
				input.setAttribute('aria-expanded', items.length ? 'true' : 'false');
			}).catch(function (e) {
				if (e && e.name === 'AbortError') return;
				hideResults();
			});
		}, 250);

		input.addEventListener('input', doSearch);
		input.addEventListener('keydown', function (ev) {
			if (ev.key === 'Escape') { hideResults(); input.value = ''; }
			if (ev.key === 'ArrowDown' && !results.hidden && results.firstChild) {
				ev.preventDefault();
				results.firstChild.focus();
			}
			if (ev.key === 'Backspace' && input.value === '' && cfg.multiple && state.selected.length) {
				state.selected.pop();
				renderChips();
				emit();
			}
		});
		results.addEventListener('keydown', function (ev) {
			var cur = document.activeElement;
			if (ev.key === 'ArrowDown' && cur.nextSibling) cur.nextSibling.focus();
			if (ev.key === 'ArrowUp' && cur.previousSibling) cur.previousSibling.focus();
			if (ev.key === 'Enter' || ev.key === ' ') {
				ev.preventDefault();
				var id = cur.getAttribute('data-id');
				var item = Array.prototype.find.call(results.children, function () { return false; });
				cfg.searchLastItem = cfg.searchLastItem || {};
				var hit = cfg.searchLastItem[id];
				if (hit) choose(hit);
			}
			if (ev.key === 'Escape') { hideResults(); input.focus(); }
		});
		document.addEventListener('click', function (ev) {
			if (!cfg.container.contains(ev.target)) hideResults();
		});

		return {
			setSelected: function (items) {
				state.selected = items || [];
				if (!cfg.multiple && cfg.hidden) {
					cfg.hidden.value = state.selected.length ? state.selected[0].id : '';
				}
				renderChips();
			},
			getSelected: function () { return state.selected.slice(); }
		};
	}

	/* ---------- lead-days checkbox group ---------- */

	function getLeadDays(containerId) {
		var days = [];
		document.querySelectorAll('#' + containerId + ' input[name="leadDay"]:checked')
			.forEach(function (cb) { days.push(parseInt(cb.value, 10)); });
		return days;
	}

	function setLeadDays(containerId, days) {
		var set = {};
		(days || []).forEach(function (d) { set[d] = true; });
		document.querySelectorAll('#' + containerId + ' input[name="leadDay"]')
			.forEach(function (cb) { cb.checked = !!set[parseInt(cb.value, 10)]; });
	}

	/* ---------- amount parsing (decimal in → integer cents out) ---------- */

	function parseAmount(raw) {
		if (raw === null || raw === undefined) return null;
		var v = String(raw).trim().replace(/\s/g, '');
		if (v === '') return null;
		// Accept "49.90", "49,90", "1.234,56" (de) and "1,234.56" (en).
		if (v.indexOf(',') !== -1 && v.indexOf('.') !== -1) {
			if (v.lastIndexOf(',') > v.lastIndexOf('.')) {
				v = v.replace(/\./g, '').replace(',', '.'); // de grouping
			} else {
				v = v.replace(/,/g, ''); // en grouping
			}
		} else {
			v = v.replace(',', '.');
		}
		if (!/^\d+(\.\d{1,2})?$/.test(v)) return null;
		return Math.round(parseFloat(v) * 100);
	}

	/* ---------- dialogs ---------- */

	var ccDlgSeq = 0;

	/**
	 * confirmDialog(title, message, opts) → Promise<bool>
	 * confirmDialog(message, opts)         → Promise<bool> (shorthand)
	 *   opts: { ok|confirmLabel, danger, cancelLabel }
	 *
	 * Replaces the banned window.confirm: a styled <dialog class="cc-dialog">
	 * (top layer — keeps it above app chrome). Escape is handled by an
	 * explicit keydown listener on the dialog itself because NC's
	 * notifications app preventDefault()s the global Escape keydown, which
	 * suppresses the native `cancel` event.
	 */
	function confirmDialog(title, message, opts) {
		// 2-arg shorthand: confirmDialog(message, opts)
		if (message !== null && typeof message === 'object') {
			opts = message;
			message = title;
			title = undefined;
		}
		opts = opts || {};
		return new Promise(function (resolve) {
			var resolved = false;
			var titleId = 'cc-confirm-title-' + (++ccDlgSeq);
			var dlg = el('dialog', { class: 'cc-dialog confirm-dialog', 'aria-labelledby': titleId });
			var h = el('h3', { class: 'cc-dialog__title', id: titleId, text: title || t('Please confirm') });
			var bodyText = el('p', { class: 'cc-dialog__text', text: message || '' });
			var actions = el('div', { class: 'cc-form-actions' });
			var cancel = el('button', { type: 'button', class: 'cc-btn cc-btn--secondary', text: opts.cancelLabel || t('Cancel') });
			var submit = el('button', {
				type: 'button',
				class: 'cc-btn ' + (opts.danger === false ? 'cc-btn--primary' : 'cc-btn--danger'),
				text: opts.ok || opts.confirmLabel || t('Confirm')
			});
			actions.appendChild(submit);
			actions.appendChild(cancel);
			dlg.appendChild(h);
			dlg.appendChild(bodyText);
			dlg.appendChild(actions);
			// Append inside the app root when present so #app-content-scoped
			// rules (cc-btn--danger fill, cc-form-actions) still match — DOM
			// placement does not affect top-layer modal rendering.
			(document.querySelector('#app-content.cc-app') || document.body).appendChild(dlg);

			function done(ok) {
				if (resolved) return;
				resolved = true;
				resolve(ok);
				if (dlg.open) dlg.close();
				dlg.remove();
				if (back && document.contains(back)) back.focus();
			}
			var back = document.activeElement;

			submit.addEventListener('click', function () { done(true); });
			cancel.addEventListener('click', function () { done(false); });
			dlg.addEventListener('cancel', function (ev) { ev.preventDefault(); done(false); });
			dlg.addEventListener('keydown', function (ev) {
				if (ev.key === 'Escape') { ev.preventDefault(); ev.stopPropagation(); done(false); }
			});
			dlg.showModal();
			submit.focus();
		});
	}

	/* ---------- busy guard (async submit, re-entry prevention) ---------- */

	function busyGuard(btn) {
		var inFlight = false;
		return {
			start: function () {
				if (inFlight) return false;
				inFlight = true;
				btn.disabled = true;
				btn.setAttribute('aria-busy', 'true');
				return true;
			},
			end: function () {
				inFlight = false;
				btn.disabled = false;
				btn.removeAttribute('aria-busy');
			}
		};
	}

	/* ---------- field errors ---------- */
	if (window.CheckFieldErrors) {
		CheckFieldErrors.install({ prefix: 'cc' });
	}

	window.CC = {
		boot: boot,
		role: role,
		t: t,
		el: el,
		api: ccApi,
		appBase: appBase,
		fileUrl: fileUrl,
		fmtDate: fmtDate,
		fmtCents: fmtCents,
		fmtRollup: fmtRollup,
		fmtRollupParts: fmtRollupParts,
		fmtCentsMap: fmtCentsMap,
		daysBadge: daysBadge,
		statusBadge: statusBadge,
		enumLabel: enumLabel,
		debounce: debounce,
		toast: toast,
		handleError: handleError,
		initPicker: initPicker,
		confirmDialog: confirmDialog,
		getLeadDays: getLeadDays,
		setLeadDays: setLeadDays,
		parseAmount: parseAmount,
		colLabels: colLabels,
		busyGuard: busyGuard
	};
})();
