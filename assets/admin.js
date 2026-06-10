/**
 * Settings page helper: the Boundary data "Test connection" button.
 *
 * Calls a server-side handler that probes the Planning Data API from the
 * site's own server and reports whether it is reachable, so the admin can
 * confirm the live lookup will work without guessing from postcodes.
 */
(function () {
	'use strict';

	var btn = document.getElementById('cac-test-connection');
	var out = document.getElementById('cac-test-result');
	if (!btn || !out || typeof cacAdmin === 'undefined') {
		return;
	}

	function escapeHtml(value) {
		var div = document.createElement('div');
		div.textContent = (value === null || value === undefined) ? '' : String(value);
		return div.innerHTML;
	}

	function render(data) {
		var cls = data.ok ? 'notice-success' : 'notice-error';
		var heading = data.ok ? cacAdmin.okMsg : cacAdmin.failMsg;
		var html = '<div class="notice ' + cls + ' inline"><p><strong>' + escapeHtml(heading) + '</strong></p><ul style="list-style:disc;margin-left:20px">';

		(data.checks || []).forEach(function (c) {
			var mark = c.ok ? '✓' : '✗';
			var line = mark + ' ' + escapeHtml(c.label);
			if (c.status) {
				line += ' (HTTP ' + escapeHtml(c.status) + ')';
			}
			if (c.count !== null && c.count !== undefined) {
				line += ', ' + escapeHtml(c.count) + ' match' + (c.count === 1 ? '' : 'es');
			}
			if (c.detail) {
				line += ' - ' + escapeHtml(c.detail);
			}
			html += '<li>' + line + '</li>';
		});

		html += '</ul></div>';
		out.innerHTML = html;
	}

	btn.addEventListener('click', function (event) {
		event.preventDefault();
		btn.disabled = true;
		out.innerHTML = '<p>' + escapeHtml(cacAdmin.testing) + '</p>';

		var body = new URLSearchParams();
		body.append('action', 'cac_test_connection');
		body.append('nonce', cacAdmin.nonce);

		fetch(cacAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		})
			.then(function (response) { return response.json(); })
			.then(function (res) {
				if (res && res.success && res.data) {
					render(res.data);
				} else {
					out.innerHTML = '<div class="notice notice-error inline"><p>' + escapeHtml(cacAdmin.failed) + '</p></div>';
				}
			})
			.catch(function () {
				out.innerHTML = '<div class="notice notice-error inline"><p>' + escapeHtml(cacAdmin.failed) + '</p></div>';
			})
			.then(function () { btn.disabled = false; });
	});
})();
