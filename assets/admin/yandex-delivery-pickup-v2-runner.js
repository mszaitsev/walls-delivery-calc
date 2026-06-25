(function () {
	'use strict';

	var config = window.wdcYandexDeliveryPickupV2Runner || null;
	if (!config) {
		return;
	}

	var root = document.querySelector('[data-wdc-yandex-pickup-v2-runner]');
	if (!root) {
		return;
	}

	var looping = false;
	var fields = root.querySelectorAll('[data-wdc-yandex-v2-field]');
	var summary = root.querySelector('[data-wdc-yandex-v2-summary]');
	var startButton = root.querySelector('[data-wdc-yandex-v2-start]');
	var continueButton = root.querySelector('[data-wdc-yandex-v2-continue]');
	var pauseButton = root.querySelector('[data-wdc-yandex-v2-pause]');
	var resetButton = root.querySelector('[data-wdc-yandex-v2-reset]');

	function post(action) {
		var body = new window.FormData();
		body.append('action', action);
		body.append('nonce', config.nonce);
		return window.fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) { return response.json(); })
			.then(function (payload) {
				if (!payload || !payload.success) {
					throw new Error(payload && payload.data && payload.data.message ? payload.data.message : 'Yandex pickup v2 runner request failed.');
				}
				return payload.data || {};
			});
	}

	function value(state, key) {
		var item = state[key];
		if (item === null || typeof item === 'undefined' || item === '') {
			return '—';
		}
		if (Array.isArray(item)) {
			return item.join('\n');
		}
		if (typeof item === 'object') {
			return JSON.stringify(item);
		}
		return String(item);
	}

	function render(state) {
		root.setAttribute('data-wdc-yandex-v2-status', value(state, 'status'));
		if (summary) {
			summary.textContent = value(state, 'status') + ': ' + value(state, 'message');
		}
		fields.forEach(function (field) {
			field.textContent = value(state, field.getAttribute('data-wdc-yandex-v2-field') || '');
		});
	}

	function loop(state) {
		render(state);
		if (state.status === 'ready_to_import') {
			post('wdc_yandex_delivery_pickup_v2_runner_start_import').then(loop).catch(showError);
			return;
		}
		if (state.status !== 'importing' || looping === false) {
			return;
		}
		post('wdc_yandex_delivery_pickup_v2_runner_step').then(function (nextState) {
			window.setTimeout(function () { loop(nextState); }, 50);
		}).catch(showError);
	}

	function showError(error) {
		looping = false;
		if (summary) {
			summary.textContent = error.message || String(error);
		}
	}

	if (startButton) {
		startButton.addEventListener('click', function () {
			looping = true;
			post('wdc_yandex_delivery_pickup_v2_runner_start').then(loop).catch(showError);
		});
	}
	if (continueButton) {
		continueButton.addEventListener('click', function () {
			looping = true;
			post('wdc_yandex_delivery_pickup_v2_runner_start_import').then(loop).catch(showError);
		});
	}
	if (pauseButton) {
		pauseButton.addEventListener('click', function () {
			looping = false;
			post('wdc_yandex_delivery_pickup_v2_runner_pause').then(render).catch(showError);
		});
	}
	if (resetButton) {
		resetButton.addEventListener('click', function () {
			looping = false;
			post('wdc_yandex_delivery_pickup_v2_runner_reset').then(render).catch(showError);
		});
	}

	render(config.initialState || {});
	if (config.initialState && config.initialState.status === 'importing') {
		looping = true;
		loop(config.initialState);
	}
}());