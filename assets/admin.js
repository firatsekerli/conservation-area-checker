/**
 * Settings page helpers for the Conservation Area Checker.
 *
 * Two independent tools live here:
 *   1. "Test connection" - probes the Planning Data API from the site server.
 *   2. "Check a postcode" - runs one postcode through the full live pipeline.
 *
 * Each initialises on its own, so if one tool's markup is absent the other
 * still works.
 */
(function () {
	'use strict';

	if (typeof cacAdmin === 'undefined') {
		return;
	}

	function escapeHtml(value) {
		var div = document.createElement('div');
		div.textContent = (value === null || value === undefined) ? '' : String(value);
		return div.innerHTML;
	}

	function post(action, fields) {
		var body = new URLSearchParams();
		body.append('action', action);
		Object.keys(fields).forEach(function (key) {
			body.append(key, fields[key]);
		});
		return fetch(cacAdmin.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
			body: body.toString()
		}).then(function (response) { return response.json(); });
	}

	function errorNotice(message) {
		return '<div class="notice notice-error inline"><p>' + escapeHtml(message) + '</p></div>';
	}

	// -----------------------------------------------------------------------
	// Tool 1: Test connection.
	// -----------------------------------------------------------------------
	(function initConnectionTest() {
		var btn = document.getElementById('cac-test-connection');
		var out = document.getElementById('cac-test-result');
		if (!btn || !out) {
			return;
		}

		function render(data) {
			var cls = data.ok ? 'notice-success' : 'notice-error';
			var heading = data.ok ? cacAdmin.okMsg : cacAdmin.failMsg;
			var html = '<div class="notice ' + cls + ' inline"><p><strong>' + escapeHtml(heading) + '</strong></p><ul style="list-style:disc;margin-left:20px">';

			(data.checks || []).forEach(function (c) {
				var line = (c.ok ? '✓' : '✗') + ' ' + escapeHtml(c.label);
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

			post('cac_test_connection', { nonce: cacAdmin.nonce })
				.then(function (res) {
					if (res && res.success && res.data) {
						render(res.data);
					} else {
						out.innerHTML = errorNotice(cacAdmin.failed);
					}
				})
				.catch(function () {
					out.innerHTML = errorNotice(cacAdmin.failed);
				})
				.then(function () { btn.disabled = false; });
		});
	})();

	// -----------------------------------------------------------------------
	// Tool 2: Check a postcode.
	// -----------------------------------------------------------------------
	(function initPostcodeChecker() {
		var btn = document.getElementById('cac-pc-btn');
		var input = document.getElementById('cac-pc-input');
		var out = document.getElementById('cac-pc-result');
		if (!btn || !input || !out || !cacAdmin.pc) {
			return;
		}

		var pc = cacAdmin.pc;

		// Front-end CSS is not loaded in admin, so colour the badge inline.
		var STATE_COLOR = {
			outside: '#6b7280',
			none: '#16a34a',
			conservation: '#FFC407',
			article4: '#f97316',
			both: '#dc2626',
			unknown: '#9ca3af'
		};

		function row(label, value) {
			return '<tr><th scope="row" style="width:240px">' + escapeHtml(label) + '</th><td>' + value + '</td></tr>';
		}

		function yesNo(flag) {
			return flag ? '<strong>' + escapeHtml(pc.yes) + '</strong>' : escapeHtml(pc.no);
		}

		function orNone(value) {
			return value ? escapeHtml(value) : '<em>' + escapeHtml(pc.none) + '</em>';
		}

		function render(d) {
			if (d.error) {
				out.innerHTML = errorNotice(d.error);
				return;
			}

			var L = pc.labels;
			var rows = '';
			rows += row(L.postcode, escapeHtml(d.postcode));
			rows += row(L.coords, escapeHtml(d.lat) + ', ' + escapeHtml(d.lon));
			rows += row(L.county, orNone(d.county));
			rows += row(L.district, orNone(d.district));
			rows += row(L.constituency, orNone(d.constituency));
			rows += row(L.distance, escapeHtml(d.distance) + ' ' + escapeHtml(L.miles));
			rows += row(L.inArea, yesNo(d.in_area));

			if (d.planning_error) {
				rows += row(L.conservation, escapeHtml(d.planning_error));
			} else {
				rows += row(L.conservation, yesNo(d.conservation));
				rows += row(L.article4, yesNo(d.article4));
			}

			var stateLabel = pc.states[d.final] || d.final;
			var color = STATE_COLOR[d.final] || '#6b7280';
			var badge = '<span style="display:inline-block;padding:.35em .7em;border-radius:4px;background:#F7F7F9;border-left:4px solid ' + color + '"><strong>' + escapeHtml(stateLabel) + '</strong></span>';
			rows += row(L.final, badge);

			out.innerHTML = '<table class="widefat striped" style="max-width:680px;margin-top:10px"><tbody>' + rows + '</tbody></table>';
		}

		function run() {
			var value = (input.value || '').trim();
			if (!value) {
				out.innerHTML = errorNotice(pc.enter);
				return;
			}

			btn.disabled = true;
			out.innerHTML = '<p>' + escapeHtml(pc.checking) + '</p>';

			post('cac_test_postcode', { nonce: pc.nonce, postcode: value })
				.then(function (res) {
					if (res && res.success && res.data) {
						render(res.data);
					} else {
						out.innerHTML = errorNotice(pc.failed);
					}
				})
				.catch(function () {
					out.innerHTML = errorNotice(pc.failed);
				})
				.then(function () { btn.disabled = false; });
		}

		btn.addEventListener('click', function (event) {
			event.preventDefault();
			run();
		});

		input.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				run();
			}
		});
	})();
})();
