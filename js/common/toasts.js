(function () {
	'use strict';

	var DURATIONS = { success: 4000, error: 7000 };

	function live(id, text) {
		var el = document.getElementById(id);
		if (el) {
			el.textContent = text;
		}
	}

	function ensureContainer() {
		var c = document.getElementById('cc-toasts');
		if (!c) {
			c = document.createElement('div');
			c.id = 'cc-toasts';
			c.className = 'cc-toasts';
			document.body.appendChild(c);
		}
		return c;
	}

	/**
	 * Dedup on kind+text with timer reset: an identical toast already showing
	 * gets its dismiss timer reset instead of stacking a duplicate (rapid
	 * retries, repeated API failures).
	 */
	function show(kind, regionId, message) {
		live(regionId, message);
		var container = ensureContainer();
		var dedupKey = kind + '|' + String(message);
		var existing = typeof container.querySelectorAll === 'function'
			? container.querySelectorAll('.cc-toast[data-cc-toast-key]')
			: Array.prototype.slice.call(container.childNodes || []);
		for (var i = 0; i < existing.length; i++) {
			var prev = existing[i];
			var prevKey = typeof prev.getAttribute === 'function'
				? prev.getAttribute('data-cc-toast-key')
				: prev._ccToastKey;
			if (prevKey === dedupKey || prev._ccToastKey === dedupKey) {
				if (prev._ccDismissTimer) {
					window.clearTimeout(prev._ccDismissTimer);
				}
				prev._ccDismissTimer = window.setTimeout(function () {
					if (prev.parentNode) {
						prev.parentNode.removeChild(prev);
					}
				}, DURATIONS[kind]);
				return;
			}
		}
		var toast = document.createElement('div');
		toast.className = 'cc-toast cc-toast--' + kind;
		toast.setAttribute('role', kind === 'error' ? 'alert' : 'status');
		toast.setAttribute('data-cc-toast-key', dedupKey);
		toast._ccToastKey = dedupKey;
		var text = document.createElement('span');
		text.className = 'cc-toast__text';
		text.textContent = String(message);
		var close = document.createElement('button');
		close.type = 'button';
		close.className = 'cc-toast__close';
		close.setAttribute('aria-label', t('contractcheck', 'Dismiss'));
		close.textContent = '✕';
		close.addEventListener('click', function () {
			if (toast._ccDismissTimer) {
				window.clearTimeout(toast._ccDismissTimer);
			}
			toast.remove();
		});
		toast.appendChild(text);
		if (kind === 'error') {
			// Family contract (_shared/app-feedback): error toasts offer a
			// "Report this problem" mailto next to the message.
			try {
				var feedback = window.SbdAppFeedback;
				if (feedback && typeof feedback.buildMailto === 'function') {
					var link = document.createElement('a');
					link.className = 'cc-toast__feedback';
					link.href = feedback.buildMailto('problem', {});
					link.textContent = t('contractcheck', 'Report this problem');
					toast.appendChild(link);
				}
			} catch (e) { /* never break the toast */ }
		}
		toast.appendChild(close);
		container.appendChild(toast);
		toast._ccDismissTimer = window.setTimeout(function () {
			if (toast.parentNode) {
				toast.parentNode.removeChild(toast);
			}
		}, DURATIONS[kind]);
	}

	window.ContractCheckToasts = {
		showSuccess: function (message) {
			show('success', 'cc-live-region', message);
		},
		showError: function (message) {
			show('error', 'cc-alert-region', message);
		}
	};
})();
