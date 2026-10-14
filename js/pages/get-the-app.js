(function () {
	'use strict';
	if (!window.CC) return;
	var t = CC.t;

	CC.api('/api/companion/bootstrap').then(function (s) {
		document.getElementById('cc-app-api').textContent = s.apiVersion
			? 'v' + s.apiVersion : '—';
		document.getElementById('cc-app-role').textContent =
			(s.role && s.role.manager) ? t('manager')
			: (s.role && s.role.costViewer) ? t('cost viewer') : t('viewer');
		document.getElementById('cc-app-min').textContent = s.minAppVersion || '—';
	}).catch(function () {
		document.getElementById('cc-app-api').textContent = t('unavailable');
		document.getElementById('cc-app-role').textContent = '—';
		document.getElementById('cc-app-min').textContent = '—';
	});
})();
