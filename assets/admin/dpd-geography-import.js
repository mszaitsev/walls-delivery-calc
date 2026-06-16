(function () {
	'use strict';

	var root = document.getElementById('wdc-dpd-geography-import-progress');
	var config = window.wdcDpdGeographyImport || {};
	if (!root || !config.ajaxUrl || !config.nonce) {
		return;
	}

	var timer = null;
	var bar = root.querySelector('[data-wdc-dpd-progress-bar]');
	var summary = root.querySelector('[data-wdc-dpd-summary]');

	function isBusy(phase) {
		return ['preparing', 'indexing_locations', 'downloading', 'ready', 'importing', 'finalizing'].indexOf(String(phase || '')) !== -1;
	}

	function labelPhase(phase) {
		var labels = {
			idle: 'ожидание',
			preparing: 'подготовка',
			indexing_locations: 'индексация населенных пунктов',
			downloading: 'загрузка',
			ready: 'готов к импорту',
			importing: 'импорт',
			finalizing: 'завершение',
			finished: 'завершен',
			failed: 'ошибка',
			cancelled: 'сброшен'
		};
		return labels[phase] || phase || '-';
	}

	function format(value) {
		if (Array.isArray(value)) {
			return value.join('; ');
		}
		return value === null || typeof value === 'undefined' ? '' : String(value);
	}

	function setField(key, value) {
		var node = root.querySelector('[data-wdc-dpd-field="' + key + '"]');
		if (node) {
			node.textContent = key === 'phase' ? labelPhase(String(value || '')) : format(value);
		}
	}

	function render(state) {
		var phase = String((state && state.phase) || 'idle');
		var percent = Number((state && state.percent_complete) || 0);
		var read = Number((state && state.rows_read) || 0);
		root.setAttribute('data-wdc-dpd-phase', phase);
		if (bar) {
			bar.style.width = Math.max(0, Math.min(100, percent)) + '%';
		}
		if (summary) {
			summary.textContent = 'Фаза: ' + labelPhase(phase) + '. Обработано ' + read + ' строк. Прочитано ' + Math.max(0, Math.min(100, percent)).toFixed(1) + '% файла.';
		}
		[
			'phase',
			'source',
			'source_file',
			'rows_read',
			'file_size',
			'byte_offset',
			'ru_rows',
			'skipped_non_ru',
			'skipped_invalid',
			'matched_by_fias',
			'matched_by_kladr',
			'matched_by_name',
			'saved_candidates',
			'finalized_mappings',
			'unchanged_mappings',
			'conflicts',
			'ambiguous',
			'unmatched',
			'errors',
			'percent_complete',
			'last_message',
			'started_at',
			'updated_at',
			'finished_at'
		].forEach(function (key) {
			setField(key, state ? state[key] : '');
		});
		if (isBusy(phase)) {
			startPolling();
		} else {
			stopPolling();
		}
	}

	function requestStatus() {
		var body = new URLSearchParams();
		body.set('action', 'wdc_dpd_geography_import_status');
		body.set('nonce', config.nonce);
		return fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: body.toString()
		})
			.then(function (response) {
				return response.json();
			})
			.then(function (payload) {
				if (payload && payload.success && payload.data) {
					render(payload.data);
				}
			})
			.catch(function () {
				stopPolling();
			});
	}

	function startPolling() {
		if (timer) {
			return;
		}
		timer = window.setInterval(requestStatus, 1500);
	}

	function stopPolling() {
		if (!timer) {
			return;
		}
		window.clearInterval(timer);
		timer = null;
	}

	if (isBusy(root.getAttribute('data-wdc-dpd-phase') || 'idle')) {
		requestStatus();
		startPolling();
	}
}());
