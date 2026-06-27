(function () {
	'use strict';

	var config = window.wdcYandexDeliveryPickupV2Runner || null;
	if (!config) {
		return;
	}

	function post(action) {
		var body = new window.FormData();
		body.append('action', action);
		body.append('nonce', config.nonce);
		return window.fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function (response) {
				return response.text().then(function (text) {
					var payload = null;

					try {
						payload = JSON.parse(text);
					} catch (e) {
						throw new Error(
							'Сервер вернул не JSON. HTTP ' +
							response.status +
							'. Начало ответа: ' +
							text.slice(0, 300)
						);
					}

					if (!payload || !payload.success) {
						throw new Error(
							payload && payload.data && payload.data.message
								? payload.data.message
								: 'Yandex pickup v2 runner request failed.'
						);
					}

					return payload.data || {};
				});
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

	function runner(options) {
		var root = document.querySelector(options.root);
		if (!root) {
			return;
		}
		var looping = false;
		var fields = root.querySelectorAll(options.fieldSelector);
		var summary = root.querySelector(options.summarySelector);
		var startButton = root.querySelector(options.startSelector);
		var continueButton = options.continueSelector ? root.querySelector(options.continueSelector) : null;
		var pauseButton = root.querySelector(options.pauseSelector);
		var resetButton = root.querySelector(options.resetSelector);

		function render(state) {
			root.setAttribute(options.statusAttribute, value(state, 'status'));
			if (summary) {
				summary.textContent = value(state, 'status') + ': ' + value(state, 'message');
			}
			fields.forEach(function (field) {
				field.textContent = value(state, field.getAttribute(options.fieldAttribute) || '');
			});
		}

		function showError(error) {
			looping = false;
			if (summary) {
				summary.textContent = error.message || String(error);
			}
		}

		function loop(state) {
			render(state);
			if (options.readyStatus && state.status === options.readyStatus) {
				post(options.continueAction).then(loop).catch(showError);
				return;
			}
			if (state.status !== options.runningStatus || looping === false) {
				return;
			}
			post(options.stepAction).then(function (nextState) {
				window.setTimeout(function () { loop(nextState); }, 50);
			}).catch(showError);
		}

		if (startButton) {
			startButton.addEventListener('click', function () {
				looping = true;
				post(options.startAction).then(loop).catch(showError);
			});
		}
		if (continueButton) {
			continueButton.addEventListener('click', function () {
				looping = true;
				post(options.continueAction).then(loop).catch(showError);
			});
		}
		if (pauseButton) {
			pauseButton.addEventListener('click', function () {
				looping = false;
				post(options.pauseAction).then(render).catch(showError);
			});
		}
		if (resetButton) {
			resetButton.addEventListener('click', function () {
				looping = false;
				post(options.resetAction).then(render).catch(showError);
			});
		}

		render(options.initialState || {});
		if (options.initialState && options.initialState.status === options.runningStatus) {
			looping = true;
			loop(options.initialState);
		}
	}

	runner({
		root: '[data-wdc-yandex-pickup-v2-runner]',
		fieldSelector: '[data-wdc-yandex-v2-field]',
		fieldAttribute: 'data-wdc-yandex-v2-field',
		summarySelector: '[data-wdc-yandex-v2-summary]',
		statusAttribute: 'data-wdc-yandex-v2-status',
		startSelector: '[data-wdc-yandex-v2-start]',
		continueSelector: '[data-wdc-yandex-v2-continue]',
		pauseSelector: '[data-wdc-yandex-v2-pause]',
		resetSelector: '[data-wdc-yandex-v2-reset]',
		startAction: 'wdc_yandex_delivery_pickup_v2_runner_start',
		continueAction: 'wdc_yandex_delivery_pickup_v2_runner_start_import',
		stepAction: 'wdc_yandex_delivery_pickup_v2_runner_step',
		pauseAction: 'wdc_yandex_delivery_pickup_v2_runner_pause',
		resetAction: 'wdc_yandex_delivery_pickup_v2_runner_reset',
		readyStatus: 'ready_to_import',
		runningStatus: 'importing',
		initialState: config.initialState || {}
	});

	runner({
		root: '[data-wdc-yandex-geo-v2-builder]',
		fieldSelector: '[data-wdc-yandex-geo-v2-field]',
		fieldAttribute: 'data-wdc-yandex-geo-v2-field',
		summarySelector: '[data-wdc-yandex-geo-v2-summary]',
		statusAttribute: 'data-wdc-yandex-geo-v2-status',
		startSelector: '[data-wdc-yandex-geo-v2-start]',
		pauseSelector: '[data-wdc-yandex-geo-v2-pause]',
		resetSelector: '[data-wdc-yandex-geo-v2-reset]',
		startAction: 'wdc_yandex_delivery_geo_v2_builder_start',
		stepAction: 'wdc_yandex_delivery_geo_v2_builder_step',
		pauseAction: 'wdc_yandex_delivery_geo_v2_builder_pause',
		resetAction: 'wdc_yandex_delivery_geo_v2_builder_reset',
		runningStatus: 'building',
		initialState: config.geoBuilderInitialState || {}
	});

	runner({
		root: '[data-wdc-yandex-geo-v2-region-enrichment]',
		fieldSelector: '[data-wdc-yandex-geo-v2-region-enrichment-field]',
		fieldAttribute: 'data-wdc-yandex-geo-v2-region-enrichment-field',
		summarySelector: '[data-wdc-yandex-geo-v2-region-enrichment-summary]',
		statusAttribute: 'data-wdc-yandex-geo-v2-region-enrichment-status',
		startSelector: '[data-wdc-yandex-geo-v2-region-enrichment-start]',
		pauseSelector: '[data-wdc-yandex-geo-v2-region-enrichment-pause]',
		resetSelector: '[data-wdc-yandex-geo-v2-region-enrichment-reset]',
		startAction: 'wdc_yandex_geo_v2_region_enrichment_start',
		stepAction: 'wdc_yandex_geo_v2_region_enrichment_step',
		pauseAction: 'wdc_yandex_geo_v2_region_enrichment_pause',
		resetAction: 'wdc_yandex_geo_v2_region_enrichment_reset',
		runningStatus: 'enriching_regions',
		initialState: config.geoRegionEnrichmentInitialState || {}
	});
	runner({
		root: '[data-wdc-yandex-location-mapping-v2]',
		fieldSelector: '[data-wdc-yandex-location-mapping-v2-field]',
		fieldAttribute: 'data-wdc-yandex-location-mapping-v2-field',
		summarySelector: '[data-wdc-yandex-location-mapping-v2-summary]',
		statusAttribute: 'data-wdc-yandex-location-mapping-v2-status',
		startSelector: '[data-wdc-yandex-location-mapping-v2-start]',
		pauseSelector: '[data-wdc-yandex-location-mapping-v2-pause]',
		resetSelector: '[data-wdc-yandex-location-mapping-v2-reset]',
		startAction: 'wdc_yandex_location_mapping_v2_start',
		stepAction: 'wdc_yandex_location_mapping_v2_step',
		pauseAction: 'wdc_yandex_location_mapping_v2_pause',
		resetAction: 'wdc_yandex_location_mapping_v2_reset',
		runningStatus: 'mapping',
		initialState: config.locationMappingInitialState || {}
	});
}());
