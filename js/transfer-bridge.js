/**
 * Bridge for the shared transfer page (apps/_shared/config-transfer):
 * exposes the window.ContractCheckApi / window.ContractCheckMessaging
 * contract the synced js/transfer.js expects, backed by the app's own
 * ccApi envelope ({success:true,data} / {success:false,error,fields}).
 * App-specific — not synced.
 */
(function () {
	'use strict';
	function requestToken() {
		return (window.OC && OC.requestToken) || '';
	}
	function appBase() {
		if (window.OC && typeof OC.generateUrl === 'function') {
			return OC.generateUrl('/apps/contractcheck');
		}
		var webroot = (window.OC && OC.getRootPath) ? OC.getRootPath() : '';
		return webroot + '/index.php/apps/contractcheck';
	}
	async function parse(resp) {
		const j = await resp.json().catch(() => null);
		if (!resp.ok || (j && j.success === false)) {
			const errObj = j && j.error;
			throw {
				code: (errObj && (errObj.code || errObj)) || 'SERVER_ERROR',
				context: (j && j.context) || {},
			};
		}
		return j && Object.prototype.hasOwnProperty.call(j, 'data') ? j.data : j;
	}
	window.ContractCheckApi = {
		url(path) {
			return appBase() + path;
		},
		async get(path, params) {
			let u = this.url(path);
			if (params) {
				const q = Object.keys(params)
					.filter((k) => params[k] !== undefined && params[k] !== null)
					.map((k) => encodeURIComponent(k) + '=' + encodeURIComponent(params[k]))
					.join('&');
				if (q) u += '?' + q;
			}
			return parse(await fetch(u, { credentials: 'same-origin' }));
		},
		async post(path, body) {
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json', requesttoken: requestToken() },
				body: JSON.stringify(body || {}),
			}));
		},
		async upload(path, file, extra) {
			const fd = new FormData();
			fd.append('file', file);
			if (extra) {
				Object.keys(extra).forEach((k) => fd.append(k, extra[k]));
			}
			return parse(await fetch(this.url(path), {
				method: 'POST', credentials: 'same-origin',
				headers: { requesttoken: requestToken() },
				body: fd,
			}));
		},
	};
	window.ContractCheckMessaging = {
		alert(text) {
			// App-owned toast surface (dedup + polite/error roles built in);
			// the live region stays the non-visual fallback.
			if (window.ContractCheckToasts && ContractCheckToasts.showError) {
				ContractCheckToasts.showError(text);
			}
			const el = document.getElementById('cc-transfer-result-live');
			if (el) el.textContent = text;
		},
		polite(text) {
			if (window.ContractCheckToasts && ContractCheckToasts.showSuccess) {
				ContractCheckToasts.showSuccess(text);
			}
			const el = document.getElementById('cc-transfer-result-live');
			if (el) el.textContent = text;
		},
	};
})();
