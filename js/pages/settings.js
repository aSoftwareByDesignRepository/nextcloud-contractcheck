(function () {
	'use strict';
	if (!window.CC) return;
	var t = CC.t;
	var root = document.querySelector('.cc-settings');
	var section = root ? root.getAttribute('data-section') : '';
	var settings = null;

	function groupPicker(pickerId, inputId, chipsId, resultsId, onChange) {
		return CC.initPicker({
			container: document.getElementById(pickerId),
			input: document.getElementById(inputId),
			results: document.getElementById(resultsId),
			chips: document.getElementById(chipsId),
			hidden: null,
			multiple: true,
			search: function (term, signal) {
				return CC.api('/api/directory/groups?q=' + encodeURIComponent(term), { signal: signal });
			},
			onChange: onChange
		});
	}

	function saveSettings(guardEl, patch) {
		var guard = CC.busyGuard(guardEl);
		if (!guard.start()) return;
		var merged = {};
		Object.keys(settings.settings).forEach(function (k) { merged[k] = settings.settings[k]; });
		Object.keys(patch).forEach(function (k) { merged[k] = patch[k]; });
		CC.api('/api/settings', {
			method: 'POST',
			json: { version: settings.version, settings: merged }
		}).then(function (data) {
			settings = data;
			CC.toast('success', t('Saved.'));
		}).catch(function (e) {
			CC.handleError(e);
		}).finally(function () { guard.end(); });
	}

	function nonManager() {
		if (CC.role.manager) return false;
		document.querySelectorAll('.cc-settings .cc-btn--primary').forEach(function (b) {
			b.disabled = true;
		});
		CC.toast('error', t('Only managers can change these settings.'));
		return true;
	}

	if (section === 'deadlines') {
		var decSep = (typeof OC !== 'undefined' && OC.getLocale && String(OC.getLocale()).indexOf('de') === 0) ? ',' : '.';
		function budgetsToText(map) {
			var lines = [];
			Object.keys(map || {}).forEach(function (k) {
				lines.push(k + ' = ' + (map[k] / 100).toFixed(2).replace('.', decSep));
			});
			return lines.join('\n');
		}

		// "IT = 12000,00" lines → {IT: 1200000} — strict parse, any bad line
		// is a field error (never silently dropped, family validation rule).
		function budgetsFromText(text) {
			var map = {};
			var bad = false;
			text.split('\n').forEach(function (line) {
				var trimmed = line.trim();
				if (trimmed === '') return;
				var m = /^([^=]{1,64})\s*=\s*(\d{1,9})([.,]\d{1,2})?\s*$/.exec(trimmed);
				if (!m) { bad = true; return; }
				var euros = parseInt(m[2], 10);
				var frac = (m[3] || '').replace(',', '.');
				var cents = euros * 100 + Math.round(parseFloat(frac || '0') * 100);
				map[m[1].trim()] = cents;
			});
			return bad ? null : map;
		}

		CC.api('/api/settings').then(function (s) {
			settings = s;
			CC.setLeadDays('cc-set-lead-days', (s.settings && s.settings.defaultLeadDays) || []);
			document.getElementById('cc-set-orgname').value = (s.settings && s.settings.orgName) || '';
			document.getElementById('cc-set-budgets').value = budgetsToText(s.settings && s.settings.costCenterBudgets);
		}).catch(CC.handleError);

		document.getElementById('cc-set-save').addEventListener('click', function (ev) {
			if (nonManager() || !settings) return;
			var errEl = document.getElementById('cc-set-budgets-error');
			var budgets = budgetsFromText(document.getElementById('cc-set-budgets').value);
			if (budgets === null) {
				errEl.textContent = t('Budget lines must look like: Cost center = amount');
				errEl.hidden = false;
				document.getElementById('cc-set-budgets').setAttribute('aria-invalid', 'true');
				return;
			}
			errEl.hidden = true;
			document.getElementById('cc-set-budgets').removeAttribute('aria-invalid');
			saveSettings(ev.currentTarget, {
				defaultLeadDays: CC.getLeadDays('cc-set-lead-days'),
				orgName: document.getElementById('cc-set-orgname').value.trim(),
				costCenterBudgets: budgets
			});
		});
	}

	if (section === 'access') {
		var pending = { allowed: [], managers: [], costViewers: [] };
		var pickers = {
			allowed: groupPicker('cc-allowed-picker', 'cc-set-allowed', 'cc-allowed-chips', 'cc-allowed-results',
				function (ids) { pending.allowed = ids; }),
			managers: groupPicker('cc-managers-picker', 'cc-set-managers', 'cc-managers-chips', 'cc-managers-results',
				function (ids) { pending.managers = ids; }),
			costViewers: groupPicker('cc-costviewers-picker', 'cc-set-costviewers', 'cc-costviewers-chips', 'cc-costviewers-results',
				function (ids) { pending.costViewers = ids; })
		};

		CC.api('/api/settings').then(function (s) {
			settings = s;
			var st = s.settings || {};
			var map = { allowed: 'allowedGroups', managers: 'managerGroups', costViewers: 'costViewerGroups' };
			Object.keys(map).forEach(function (key) {
				var gids = st[map[key]] || [];
				pending[key] = gids.slice();
				pickers[key].setSelected(gids.map(function (g) { return { id: g, label: g }; }));
			});
		}).catch(CC.handleError);

		document.getElementById('cc-set-save-access').addEventListener('click', function (ev) {
			if (nonManager() || !settings) return;
			saveSettings(ev.currentTarget, {
				allowedGroups: pending.allowed,
				managerGroups: pending.managers,
				costViewerGroups: pending.costViewers
			});
		});
	}

	if (section === 'feed') {
		var urlInput = document.getElementById('cc-feed-url');
		var revokeBtn = document.getElementById('cc-feed-revoke');
		var hint = document.getElementById('cc-feed-hint');
		var hasToken = false;
		var mintGuard = CC.busyGuard(document.getElementById('cc-feed-mint'));
		var revokeGuard = CC.busyGuard(revokeBtn);

		CC.api('/api/settings').then(function (s) {
			settings = s;
			hasToken = !!(s.feed && s.feed.hasToken);
			if (hasToken) {
				// The token itself is stored hashed — the URL can only be
				// shown once, right after (re-)generation.
				hint.textContent = t('A feed link exists. Regenerate to see it, or revoke to disable.');
				revokeBtn.hidden = false;
			}
		}).catch(CC.handleError);

		document.getElementById('cc-feed-copy').addEventListener('click', function () {
			if (!urlInput.value) return;
			urlInput.select();
			if (navigator.clipboard && navigator.clipboard.writeText) {
				navigator.clipboard.writeText(urlInput.value).then(function () {
					CC.toast('success', t('Copied.'));
				});
			} else {
				document.execCommand('copy');
				CC.toast('success', t('Copied.'));
			}
		});

		document.getElementById('cc-feed-mint').addEventListener('click', function () {
			if (hasToken && !window.confirm(
				t('Replace the existing feed link? The old link stops working immediately.'))) return;
			if (!mintGuard.start()) return;
			CC.api('/api/feed/token', { method: 'POST' }).then(function (r) {
				hasToken = true;
				urlInput.value = r.url;
				hint.textContent = t('This URL is shown once — save it now.');
				revokeBtn.hidden = false;
				CC.toast('success', t('New feed link generated.'));
			}).catch(CC.handleError).finally(function () { mintGuard.end(); });
		});

		revokeBtn.addEventListener('click', function () {
			if (!window.confirm(t('Revoke the feed link? Calendar subscriptions using it stop updating.'))) return;
			if (!revokeGuard.start()) return;
			CC.api('/api/feed/token', { method: 'DELETE' }).then(function () {
				hasToken = false;
				urlInput.value = '';
				hint.textContent = t('Generate a link below first.');
				revokeBtn.hidden = true;
				CC.toast('success', t('Feed link revoked.'));
			}).catch(CC.handleError).finally(function () { revokeGuard.end(); });
		});
	}
})();
