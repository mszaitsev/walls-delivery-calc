(function () {
	'use strict';

	var config = window.wdcYandexDeliveryGeoMappingRunner || {};
	var state = config.initialState || {};
	var running = false;
	var stopRequested = false;
	var activeSessionId = '';

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
		['status', 'mode', 'session_id', 'next_location_id', 'processed', 'mapped', 'needs_review', 'not_found', 'tech_errors', 'technical_error_markers_count', 'total_estimated', 'eta_finished_at', 'average_locations_per_second', 'elapsed_seconds', 'remaining_seconds', 'updated_at', 'message', 'batch_size'].forEach(function (field) {
			setText(field, state[field] || (field === 'batch_size' ? '50' : ''));
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

	function stopLoop() {
		running = false;
		stopRequested = true;
	}

	function loop() {
		if (!running || stopRequested || !state || state.status !== 'running') {
			running = false;
			return;
		}
		var sessionId = activeSessionId || state.session_id || '';
		post('step', {session_id: sessionId}).then(function (nextState) {
			if (stopRequested || !running) {
				return;
			}
			if (sessionId && nextState.session_id && nextState.session_id !== sessionId) {
				render(nextState);
				running = false;
				return;
			}
			render(nextState);
			if (!stopRequested && running && nextState.status === 'running') {
				window.setTimeout(loop, 250);
			} else {
				running = false;
			}
		}).catch(function (error) {
			if (stopRequested) {
				return;
			}
			running = false;
			render(Object.assign({}, state, {status: 'error', message: error.message || 'AJAX error'}));
		});
	}

	function startLoop(nextState) {
		render(nextState);
		stopRequested = false;
		running = state.status === 'running';
		activeSessionId = state.session_id || '';
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
		} else if (action === 'start_unprocessed') {
			post('start_unprocessed').then(startLoop);
		} else if (action === 'retry_errors') {
			post('retry_errors').then(startLoop);
		} else if (action === 'step') {
			post('step', {session_id: state.session_id || ''}).then(render);
		} else if (action === 'pause') {
			stopLoop();
			post('pause').then(render);
		} else if (action === 'reset') {
			stopLoop();
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
