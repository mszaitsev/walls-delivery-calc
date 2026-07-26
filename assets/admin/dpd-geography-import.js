(function () {
	'use strict';

	var root = document.getElementById('wdc-dpd-geography-import-progress');
	var config = window.wdcDpdGeographyImport || {};
	if (!root || !config.ajaxUrl || !config.nonce) {
		return;
	}

	var timer = null;
	var inFlight = false;
	var stopped = false;
	var currentState = null;
	var lastRenderedRevision = 0;
	var bar = root.querySelector('[data-wdc-dpd-progress-bar]');
	var summary = root.querySelector('[data-wdc-dpd-summary]');
	var stepDelayMs = Number(config.stepDelayMs || 250);
	var busyRetryMs = Number(config.busyRetryMs || 1500);
	var statusRetryMs = 4000;

	function activePhase(phase) {
		return ['ready', 'importing'].indexOf(String(phase || '')) !== -1;
	}

	function statusOnlyPhase(phase) {
		return ['preparing', 'indexing_locations', 'downloading', 'finalizing'].indexOf(String(phase || '')) !== -1;
	}

	function terminalPhase(phase) {
		return ['idle', 'finished', 'failed', 'cancelled'].indexOf(String(phase || '')) !== -1;
	}

	function labelPhase(phase) {
		var labels = {
			idle: 'idle',
			preparing: 'preparing',
			indexing_locations: 'indexing locations',
			downloading: 'downloading',
			ready: 'ready',
			importing: 'importing',
			finalizing: 'finalizing',
			finished: 'finished',
			failed: 'failed',
			cancelled: 'cancelled'
		};
		return labels[phase] || phase || '-';
	}

	function format(value) {
		if (Array.isArray(value)) {
			return value.join('; ');
		}
		if (typeof value === 'boolean') {
			return value ? 'yes' : 'no';
		}
		return value === null || typeof value === 'undefined' ? '' : String(value);
	}

	function setField(key, value) {
		var node = root.querySelector('[data-wdc-dpd-field="' + key + '"]');
		if (node) {
			node.textContent = key === 'phase' ? labelPhase(String(value || '')) : format(value);
		}
	}

	function showTransportMessage(message) {
		if (summary) {
			summary.textContent = message;
		}
		setField('last_message', message);
	}

	function render(state) {
		if (!state) {
			return false;
		}
		var revision = Number(state.state_revision || 0);
		if (revision > 0 && lastRenderedRevision > 0 && revision < lastRenderedRevision) {
			return false;
		}
		if (revision > lastRenderedRevision) {
			lastRenderedRevision = revision;
		}
		currentState = state;
		var phase = String(state.phase || 'idle');
		var percent = Number(state.percent_complete || 0);
		var read = Number(state.rows_read || 0);
		root.setAttribute('data-wdc-dpd-phase', phase);
		if (bar) {
			bar.style.width = Math.max(0, Math.min(100, percent)) + '%';
		}
		if (summary) {
			summary.textContent = 'Phase: ' + labelPhase(phase) + '. Processed ' + read + ' rows. Read ' + Math.max(0, Math.min(100, percent)).toFixed(1) + '% of file.';
		}
		[
			'phase',
			'status',
			'source',
			'source_file',
			'rows_read',
			'file_size',
			'byte_offset',
			'ru_rows',
			'foreign_rows',
			'foreign_am_rows',
			'foreign_by_rows',
			'foreign_kz_rows',
			'foreign_kg_rows',
			'foreign_locations_inserted',
			'foreign_locations_updated',
			'foreign_save_failed',
			'foreign_mapping_conflicts',
			'skipped_non_ru',
			'skipped_invalid',
			'matched_by_fias',
			'matched_by_kladr',
			'matched_by_name',
			'saved_candidates',
			'finalized_mappings',
			'finalized_changes',
			'stale_cleared',
			'stale_cleanup_skipped',
			'unchanged_mappings',
			'conflicts',
			'ambiguous',
			'unmatched',
			'errors_total',
			'errors',
			'percent_complete',
			'last_message',
			'started_at',
			'updated_at',
			'finished_at'
		].forEach(function (key) {
			setField(key, state[key]);
		});

		return true;
	}

	function buildBody(action) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', config.nonce);
		if (action === 'wdc_dpd_geography_import_step' && currentState) {
			body.set('job_id', String(currentState.job_id || ''));
			body.set('expected_byte_offset', String(Number(currentState.byte_offset || 0)));
		}
		return body;
	}

	function post(action) {
		return fetch(config.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: {
				'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
			},
			body: buildBody(action).toString()
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || !payload.success) {
				throw new Error('DPD geography import request failed.');
			}
			return payload.data || {};
		});
	}

	function clearTimer() {
		if (timer) {
			window.clearTimeout(timer);
			timer = null;
		}
	}

	function schedule(fn, delay) {
		if (stopped) {
			return;
		}
		clearTimer();
		timer = window.setTimeout(fn, Math.max(0, Number(delay || 0)));
	}

	function stopRunner() {
		stopped = true;
		clearTimer();
	}

	function continueFromState(state) {
		var phase = String((state && state.phase) || 'idle');
		if (activePhase(phase)) {
			schedule(requestStep, stepDelayMs);
			return;
		}
		if (statusOnlyPhase(phase)) {
			schedule(requestStatus, busyRetryMs);
			return;
		}
		if (terminalPhase(phase)) {
			stopRunner();
		}
	}

	function requestStatus() {
		if (inFlight || stopped) {
			return Promise.resolve();
		}
		inFlight = true;
		clearTimer();
		return post('wdc_dpd_geography_import_status')
			.then(function (state) {
				render(state);
				continueFromState(state);
			})
			.catch(function () {
				showTransportMessage('Connection to server was interrupted. Checking import state...');
				schedule(requestStatus, statusRetryMs);
			})
			.finally(function () {
				inFlight = false;
			});
	}

	function requestStep() {
		if (inFlight || stopped) {
			return Promise.resolve();
		}
		inFlight = true;
		clearTimer();
		return post('wdc_dpd_geography_import_step')
			.then(function (state) {
				var control = state && state.step_control ? state.step_control : null;
				render(state);
				if (control && control.outcome === 'busy') {
					showTransportMessage('Previous import step is still running. Waiting...');
					schedule(requestStatus, Number(control.retry_after_ms || busyRetryMs));
					return;
				}
				if (control && control.outcome === 'stale') {
					schedule(requestStep, Number(control.retry_after_ms || stepDelayMs));
					return;
				}
				continueFromState(state);
			})
			.catch(function () {
				showTransportMessage('Connection to server was interrupted. Checking import state...');
				schedule(requestStatus, statusRetryMs);
			})
			.finally(function () {
				inFlight = false;
			});
	}

	stopped = false;
	requestStatus();
}());
