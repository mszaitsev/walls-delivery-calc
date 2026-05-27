(function () {
	'use strict';

	var config = window.wdcRussianPostPickupImport || {};
	var root = document.querySelector('[data-wdc-rp-pickup-import-status]');
	if (!root || !config.ajaxUrl || !config.nonce) {
		return;
	}

	var refreshButton = root.querySelector('[data-wdc-rp-refresh-status]');
	var spinner = root.querySelector('[data-wdc-rp-spinner]');
	var runButton = document.querySelector('button[name="wdc_delivery_services_action"][value="run_russian_post_pickup_import"]');
	var timer = null;

	function setField(key, value) {
		var field = root.querySelector('[data-wdc-rp-field="' + key + '"]');
		if (!field) {
			return;
		}
		if (Array.isArray(value)) {
			field.textContent = value.join('; ');
			return;
		}
		field.textContent = value === undefined || value === null ? '' : String(value);
	}

	function isBusy(status) {
		return status === 'queued' || status === 'running';
	}

	function render(state) {
		var status = state && state.status ? String(state.status) : 'idle';
		var busy = isBusy(status);
		setField('status', status);
		[
			'stage',
			'started_at',
			'finished_at',
			'last_activity_at',
			'type',
			'import_id',
			'downloaded',
			'parsed',
			'inserted',
			'updated',
			'deactivated',
			'skipped',
			'rows_inserted_to_staging',
			'objects_processed',
			'batches_processed',
			'current_batch_size',
			'last_batch_duration_ms',
			'max_batch_duration_ms',
			'payload_offset',
			'staging_table',
			'main_table',
			'backup_table',
			'swap_started_at',
			'swap_finished_at',
			'errors',
			'memory_peak'
		].forEach(function (key) {
			setField(key, state ? state[key] : '');
		});
		if (spinner) {
			spinner.classList.toggle('is-active', busy);
		}
		if (runButton) {
			runButton.disabled = busy;
		}
		if (busy) {
			startPolling();
		} else {
			stopPolling();
		}
	}

	function requestStatus() {
		var body = new URLSearchParams();
		body.set('action', 'wdc_russian_post_pickup_import_status');
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
		timer = window.setInterval(requestStatus, 3000);
	}

	function stopPolling() {
		if (!timer) {
			return;
		}
		window.clearInterval(timer);
		timer = null;
	}

	if (refreshButton) {
		refreshButton.addEventListener('click', function () {
			requestStatus();
		});
	}

	var initialStatusField = root.querySelector('[data-wdc-rp-field="status"]');
	if (initialStatusField && isBusy(initialStatusField.textContent.trim())) {
		startPolling();
	}
}());
