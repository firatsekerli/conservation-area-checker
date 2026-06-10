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

	// -----------------------------------------------------------------------
	// "Check a postcode" tool: runs the full live pipeline for one postcode.
	// -----------------------------------------------------------------------
	var pcBtn = document.getElementById('cac-pc-btn');
	var pcInput = document.getElementById('cac-pc-input');
	var pcOut = document.getElementById('cac-pc-result');

	if (pcBtn && pcInput && pcOut && cacAdmin.pc) {
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

		var row = function (label, value) {
			return '<tr><th scope="row" style="width:240px">' + escapeHtml(label) + '</th><td>' + value + '</td></tr>';
		};

		var yesNo = function (flag) {
			return flag ? '<strong>' + escapeHtml(pc.yes) + '</strong>' : escapeHtml(pc.no);
		};

		var renderPostcode = function (d) {
			if (d.error) {
				pcOut.innerHTML = '<div class="notice notice-error inline"><p>' + escapeHtml(d.error) + '</p></div>';
				return;
			}

			var L = pc.labels;
			var rows = '';
			rows += row(L.postcode, escapeHtml(d.postcode));
			rows += row(L.coords, escapeHtml(d.lat) + ', ' + escapeHtml(d.lon));
			rows += row(L.county, d.county ? escapeHtml(d.county) : '<em>' + escapeHtml(pc.none) + '</em>');
			rows += row(L.district, d.district ? escapeHtml(d.district) : '<em>' + escapeHtml(pc.none) + '</em>');
			rows += row(L.constituency, d.constituency ? escapeHtml(d.constituency) : '<em>' + escapeHtml(pc.none) + '</em>');
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

			pcOut.innerHTML = '<table class="widefat striped" style="max-width:680px;margin-top:10px"><tbody>' + rows + '</tbody></table>';
		};

		var runPostcode = function () {
			var value = (pcInput.value || '').trim();
			if (!value) {
				pcOut.innerHTML = '<div class="notice notice-error inline"><p>' + escapeHtml(pc.enter) + '</p></div>';
				return;
			}

			pcBtn.disabled = true;
			pcOut.innerHTML = '<p>' + escapeHtml(pc.checking) + '</p>';

			var body = new URLSearchParams();
			body.append('action', 'cac_test_postcode');
			body.append('nonce', pc.nonce);
			body.append('postcode', value);

			fetch(cacAdmin.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: body.toString()
			})
				.then(function (response) { return response.json(); })
				.then(function (res) {
					if (res && res.success && res.data) {
						renderPostcode(res.data);
					} else {
						pcOut.innerHTML = '<div class="notice notice-error inline"><p>' + escapeHtml(pc.failed) + '</p></div>';
					}
				})
				.catch(function () {
					pcOut.innerHTML = '<div class="notice notice-error inline"><p>' + escapeHtml(pc.failed) + '</p></div>';
				})
				.then(function () { pcBtn.disabled = false; });
		};

		pcBtn.addEventListener('click', function (event) {
			event.preventDefault();
			runPostcode();
		});

		pcInput.addEventListener('keydown', function (event) {
			if (event.key === 'Enter') {
				event.preventDefault();
				runPostcode();
			}
		});
	}
})();
