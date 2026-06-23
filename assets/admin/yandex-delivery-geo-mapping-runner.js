(function () {
	'use strict';

	var config = window.wdcYandexDeliveryGeoMappingRunner || {};
	var state = config.initialState || {};
	var running = false;

	function qs(selector) {
		return document.querySelector(selector);
	}

	function qsa(selector) {
		return Array.prototype.slice.call(document.querySelectorAll(selector));
	}

	function post(action, extra) {
		var body = new window.URLSearchParams();
		body.set('action', 'wdc_yandex_delivery_geo_mapping_runner_' + action);
		body.set('nonce', config.nonce || '');
		if (extra) {
			Object.keys(extra).forEach(function (key) {
				body.set(key, extra[key]);
			});
		}
		return window.fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
			body: body.toString()
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success) {
				throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Runner AJAX failed.');
			}
			return payload.data || {};
		});
	}

	function setText(field, value) {
		var node = qs('[data-wdc-yandex-geo-runner-field="' + field + '"]');
		if (node) {
			node.textContent = value == null ? '' : String(value);
		}
	}

	function render(nextState) {
		state = nextState || state || {};
		['status', 'mode', 'session_id', 'last_location_id', 'processed', 'mapped', 'needs_review', 'not_found', 'tech_errors', 'total_estimated', 'updated_at', 'message', 'batch_size'].forEach(function (field) {
			setText(field, state[field] || (field === 'batch_size' ? '20' : ''));
		});
		var done = Number(state.processed || 0);
		var total = Number(state.total_estimated || 0);
		var percent = total > 0 ? Math.min(100, Math.floor(done / total * 100)) : 0;
		var bar = qs('[data-wdc-yandex-geo-runner-progress-bar]');
		var progress = qs('[data-wdc-yandex-geo-runner-progress-text]');
		if (bar) {
			bar.style.width = percent + '%';
		}
		if (progress) {
			progress.textContent = percent + '%';
		}
		var errorsBody = qs('[data-wdc-yandex-geo-runner-errors]');
		if (errorsBody) {
			var errors = Array.isArray(state.errors_last) ? state.errors_last : [];
			errorsBody.innerHTML = errors.length ? errors.map(function (error) {
				return '<tr><td>' + escapeHtml(error.location_id || 0) + '</td><td>' + escapeHtml(error.message || '') + '</td><td>' + escapeHtml(error.checked_at || '') + '</td></tr>';
			}).join('') : '<tr><td colspan="3">нет ошибок</td></tr>';
		}
		qsa('.wdc-yandex-geo-runner-notice').forEach(function (node) {
			node.style.display = state.status === 'running' ? '' : 'none';
		});
	}

	function escapeHtml(value) {
		return String(value).replace(/[&<>'"]/g, function (char) {
			return {'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'}[char];
		});
	}

	function loop() {
		if (!running || !state || state.status !== 'running') {
			running = false;
			return;
		}
		post('step', {session_id: state.session_id || ''}).then(function (nextState) {
			render(nextState);
			if (nextState.status === 'running') {
				window.setTimeout(loop, 250);
			} else {
				running = false;
			}
		}).catch(function (error) {
			running = false;
			render(Object.assign({}, state, {status: 'error', message: error.message || 'AJAX error'}));
		});
	}

	function startLoop(nextState) {
		render(nextState);
		running = state.status === 'running';
		if (running) {
			loop();
		}
	}

	document.addEventListener('click', function (event) {
		var button = event.target.closest('[data-wdc-yandex-geo-runner-action]');
		if (!button) {
			return;
		}
		event.preventDefault();
		var action = button.getAttribute('data-wdc-yandex-geo-runner-action');
		if (action === 'start') {
			post('start').then(startLoop);
		} else if (action === 'retry_errors') {
			post('retry_errors').then(startLoop);
		} else if (action === 'step') {
			post('step', {session_id: state.session_id || ''}).then(render);
		} else if (action === 'pause') {
			running = false;
			post('pause').then(render);
		} else if (action === 'reset') {
			running = false;
			post('reset').then(render);
		}
	});

	post('status').then(function (nextState) {
		render(nextState);
		if (nextState.status === 'running') {
			startLoop(nextState);
		}
	}).catch(function () {
		render(state);
	});
}());