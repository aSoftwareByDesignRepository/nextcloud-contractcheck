(function () {
	'use strict';
	if (!window.CC) return;
	var t = CC.t;

	function row(c) {
		var d = c.deadline || {};
		var missed = d.state === 'missed';
		var li = CC.el('li', { class: 'cc-deadline-list__item' });
		var a = CC.el('a', {
			class: 'cc-deadline-list__link',
			href: CC.appBase() + '/contracts/' + c.id
		});
		a.appendChild(CC.el('span', { class: 'cc-deadline-list__title', text: c.title }));
		a.appendChild(CC.el('span', {
			class: 'cc-deadline-list__meta',
			text: (c.partnerName ? c.partnerName + ' · ' : '')
				+ (missed ? t('window missed — next ') + CC.fmtDate(d.effectiveAt)
					: t('cancel by ') + CC.fmtDate(d.cancelBy))
		}));
		var badge = CC.daysBadge(d.daysLeft, missed);
		if (badge) a.appendChild(badge);
		li.appendChild(a);
		return li;
	}

	function expiringRow(c) {
		var li = CC.el('li', { class: 'cc-deadline-list__item' });
		var a = CC.el('a', {
			class: 'cc-deadline-list__link',
			href: CC.appBase() + '/contracts/' + c.id
		});
		a.appendChild(CC.el('span', { class: 'cc-deadline-list__title', text: c.title }));
		a.appendChild(CC.el('span', {
			class: 'cc-deadline-list__meta',
			text: (c.partnerName ? c.partnerName + ' · ' : '')
				+ t('ends ') + CC.fmtDate(c.endDate)
		}));
		if (c.daysToEnd !== null && c.daysToEnd !== undefined) {
			a.appendChild(CC.el('span', {
				class: 'cc-badge ' + (c.daysToEnd <= 30 ? 'cc-badge--warn' : 'cc-badge--neutral'),
				text: c.daysToEnd <= 0 ? t('today') : c.daysToEnd + ' ' + t('days')
			}));
		}
		li.appendChild(a);
		return li;
	}

	function fill(listId, emptyId, items, rowFn) {
		var ul = document.getElementById(listId);
		var empty = document.getElementById(emptyId);
		if (!ul) return;
		ul.innerHTML = '';
		(items || []).forEach(function (c) { ul.appendChild((rowFn || row)(c)); });
		if (empty) empty.hidden = (items || []).length > 0;
	}

	CC.api('/api/dashboard').then(function (d) {
		document.getElementById('cc-stat-total').textContent = d.counts.total;
		document.getElementById('cc-stat-urgent').textContent = d.counts.urgent;
		document.getElementById('cc-stat-missed').textContent = d.counts.missed;
		document.getElementById('cc-stat-docs').textContent = d.counts.missingDocs;
		fill('cc-dash-urgent', 'cc-dash-urgent-empty', d.urgent);
		fill('cc-dash-upcoming', 'cc-dash-upcoming-empty', d.upcoming);
		fill('cc-dash-missed', 'cc-dash-missed-empty', d.missed);
		fill('cc-dash-expiring', 'cc-dash-expiring-empty', d.expiring, expiringRow);
		fill('cc-dash-docs', 'cc-dash-docs-empty', d.missingDocs);
		if (d.costSummary) {
			var wrap = document.getElementById('cc-stat-costs-wrap');
			wrap.hidden = false;
			document.getElementById('cc-stat-costs').textContent = CC.fmtRollup(d.costSummary);
		}
		document.getElementById('cc-dash-first-run').hidden = d.counts.total !== 0;
	}).catch(CC.handleError);
})();
