(function () {
	'use strict';

	var config = window.wdcYandexDeliveryPickupImport || {};
	var root = document.querySelector('[data-wdc-yandex-pickup-import]');
	if (!root || !config.ajaxUrl || !config.nonce) {
		return;
	}

	var startButton = root.querySelector('[data-wdc-yandex-import-start]');
	var resetButton = root.querySelector('[data-wdc-yandex-import-reset]');
	var summary = root.querySelector('[data-wdc-yandex-summary]');
	var running = false;
	var timer = null;

	function isRunning(state) {
		return state && String(state.status || '') === 'running';
	}

	function format(value) {
		if (Array.isArray(value)) {
			return value.join('; ');
		}
		if (value === null || typeof value === 'undefined') {
			return '';
		}
		return String(value);
	}

	function setField(key, value) {
		var node = root.querySelector('[data-wdc-yandex-field="' + key + '"]');
		if (node) {
			node.textContent = format(value);
		}
	}

	function render(state) {
		state = state || {};
		var status = String(state.status || 'idle');
		var needsReset = status === 'error' || status === 'stale_lock';
		root.setAttribute('data-wdc-yandex-status', status);
		[
			'status',
			'page',
			'fetched',
			'normalized',
			'saved',
			'inactive',
			'mode',
			'geo_id',
			'geo_label',
			'memory_peak_mb',
			'updated_at',
			'message',
			'errors'
		].forEach(function (key) {
			setField(key, state[key]);
		});
		if (summary) {
			if (status === 'success') {
				summary.textContent = 'Статус: success. Импортировано: ' + format(state.fetched || 0) + ', сохранено: ' + format(state.saved || 0) + '. ' + format(state.geo_label || 'Москва') + ', geo_id=' + format(state.geo_id || 213) + (needsReset ? '. Требуется сброс lock или повторный запуск импорта.' : '');
			} else {
				summary.textContent = 'Статус: ' + status + '. Импортируется: ' + format(state.geo_label || 'Москва') + ', geo_id=' + format(state.geo_id || 213) + (needsReset ? '. Требуется сброс lock или повторный запуск импорта.' : '');
			}
		}
		if (startButton) {
			startButton.disabled = !needsReset && (isRunning(state) || running);
		}
		if (resetButton) {
			resetButton.disabled = false;
		}
	}

	function request(action, data) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', config.nonce);
		Object.keys(data || {}).forEach(function (key) {
			body.set(key, data[key]);
		});

		return fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: body.toString()
		})
			.then(function (response) {
				return response.text().then(function (text) {
					var payload = null;
					try {
						payload = JSON.parse(text);
					} catch (error) {
						throw new Error('Сервер вернул не JSON. Вероятна критическая ошибка PHP во время шага импорта. Проверьте wp-content/debug.log или лог PHP.');
					}
					if (!response.ok) {
						throw new Error('HTTP ' + response.status + ': ' + (payload && payload.data && payload.data.message ? payload.data.message : 'AJAX request failed.'));
					}
					return payload;
				});
			})
			.then(function (payload) {
				if (!payload || !payload.success) {
					throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'AJAX request failed.');
				}
				return payload.data || {};
			});
	}

	function fail(message) {
		var originalMessage = message || 'AJAX import step failed.';
		running = false;
		render({
			status: 'error',
			message: 'Ошибка AJAX-запроса или timeout. Если импорт был прерван, нажмите «Сбросить результат и lock» и запустите импорт заново.',
			errors: [originalMessage]
		});
	}

	function runStep(sessionId) {
		request('wdc_yandex_delivery_pickup_import_step', { session_id: sessionId })
			.then(function (state) {
				render(state);
				if (isRunning(state)) {
					timer = window.setTimeout(function () {
						runStep(String(state.session_id || sessionId));
					}, 350);
					return;
				}
				running = false;
				render(state);
			})
			.catch(function (error) {
				fail(error.message);
			});
	}

	function startImport() {
		if (running) {
			return;
		}
		running = true;
		if (timer) {
			window.clearTimeout(timer);
			timer = null;
		}
		request('wdc_yandex_delivery_pickup_import_start', {})
			.then(function (state) {
				render(state);
				if (isRunning(state) && state.session_id) {
					runStep(String(state.session_id));
					return;
				}
				running = false;
			})
			.catch(function (error) {
				fail(error.message);
			});
	}

	function resetImport() {
		if (timer) {
			window.clearTimeout(timer);
			timer = null;
		}
		running = false;
		request('wdc_yandex_delivery_pickup_import_reset', {})
			.then(render)
			.catch(function (error) {
				fail(error.message);
			});
	}

	function refreshStatus() {
		request('wdc_yandex_delivery_pickup_import_status', {})
			.then(function (state) {
				render(state);
				if (isRunning(state) && state.session_id && !running) {
					running = true;
					runStep(String(state.session_id));
				}
			})
			.catch(function () {});
	}

	if (startButton) {
		startButton.addEventListener('click', startImport);
	}
	if (resetButton) {
		resetButton.addEventListener('click', resetImport);
	}

	render(config.initialState || {});
	refreshStatus();
}());
