(function () {
	'use strict';

	var config = window.wdcRussianPostPickupImport || {};
	var root = document.querySelector('[data-wdc-rp-pickup-import-status]');
	if (!root || !config.ajaxUrl || !config.nonce) {
		return;
	}

	var refreshButton = root.querySelector('[data-wdc-rp-refresh-status]');
	var spinner = root.querySelector('[data-wdc-rp-spinner]');
	var summary = root.querySelector('[data-wdc-rp-status-summary]');
	var runButton = document.querySelector('button[name="wdc_delivery_services_action"][value="run_russian_post_pickup_import"]');
	var timer = null;

	var statusLabels = {
		idle: 'Ожидание',
		queued: 'В очереди',
		running: 'Выполняется',
		success: 'Успешно',
		failed: 'Ошибка'
	};

	var stageLabels = {
		queued: 'В очереди',
		download: 'Загрузка',
		extract: 'Распаковка',
		parse: 'Обработка',
		upsert: 'Запись в staging',
		deactivate: 'Финализация',
		finalize: 'Финализация',
		finished: 'Завершено',
		failed: 'Ошибка'
	};

	var sourceLabels = {
		api_download: 'Автоматическая загрузка из API',
		uploaded_zip: 'Загруженный ZIP',
		uploaded_payload: 'Загруженный TXT/JSON',
		uploaded_file: 'Загруженный файл'
	};

	var messageLabels = {
		'Unable to queue pickup import. Another import may be running.': 'Не удалось поставить импорт в очередь. Возможно, уже выполняется другой импорт.',
		'Unable to queue ZIP import. Another import may be running.': 'Не удалось поставить импорт ZIP в очередь. Возможно, уже выполняется другой импорт.',
		'Unable to schedule background import job.': 'Не удалось запланировать фоновую задачу импорта.',
		'Unable to schedule background import batch job.': 'Не удалось запланировать фоновую batch-задачу импорта.',
		'Uploaded TXT/JSON payload file is missing or empty.': 'Загруженный TXT/JSON-файл отсутствует или пуст.',
		'Uploaded TXT/JSON payload file is missing, empty, or has an invalid extension.': 'Загруженный TXT/JSON-файл отсутствует, пуст или имеет недопустимое расширение.',
		'Uploaded ZIP file is missing or empty.': 'Загруженный ZIP-файл отсутствует или пуст.',
		'ZIP extract failed. Try uploading extracted TXT/JSON payload.': 'Не удалось распаковать ZIP. Попробуйте загрузить распакованный TXT/JSON-файл.',
		'PHP ZipArchive extension is not available.': 'На сервере недоступно расширение PHP ZipArchive. Проверьте PHP extension zip.',
		'ZIP does not contain JSON/TXT passport payload.': 'ZIP не содержит JSON/TXT-файл с passportElements.',
		'Unable to open ZIP archive.': 'Не удалось открыть ZIP-архив.',
		'ZipArchive code:': 'Код ZipArchive:',
		'Download stage timed out/stale.': 'Этап загрузки завис или превысил лимит ожидания.',
		'API download is unstable in this environment. Use manual ZIP upload import.': 'Автоматическая загрузка через API нестабильна в этом окружении. Используйте ручную загрузку ZIP.',
		'Extract stage timed out/stale. Check PHP ZipArchive extension or use extracted JSON/TXT import.': 'Этап распаковки завис или превысил лимит ожидания. Проверьте PHP ZipArchive или загрузите распакованный TXT/JSON.',
		'Batch stage timed out/stale.': 'Batch-обработка зависла или превысила лимит ожидания.',
		'Import was manually cancelled/reset by admin.': 'Импорт был вручную отменен/сброшен администратором.',
		'Pickup import file upload failed or no file was selected.': 'Не удалось загрузить файл импорта или файл не выбран.',
		'Only ZIP, TXT, or JSON files are allowed for Russian Post pickup import.': 'Для импорта ПВЗ Почты России разрешены только ZIP, TXT или JSON-файлы.',
		'Uploaded file failed ZIP/TXT/JSON type validation.': 'Загруженный файл не прошел проверку типа ZIP/TXT/JSON.',
		'Unable to store uploaded pickup import file.': 'Не удалось сохранить загруженный файл импорта ПВЗ.'
	};

	function label(labels, value, fallback) {
		var key = value === undefined || value === null ? '' : String(value);
		if (key === '') {
			return fallback || '-';
		}
		return labels[key] || key;
	}

	function translateMessage(value) {
		var text = value === undefined || value === null ? '' : String(value);
		Object.keys(messageLabels).forEach(function (key) {
			text = text.split(key).join(messageLabels[key]);
		});
		return text;
	}

	function formatValue(key, value) {
		if (Array.isArray(value)) {
			return value.map(translateMessage).join('; ');
		}
		if (key === 'status') {
			return label(statusLabels, value, 'Ожидание');
		}
		if (key === 'stage') {
			return label(stageLabels, value, '-');
		}
		if (key === 'source') {
			return label(sourceLabels, value, '-');
		}
		if (['fallback_used', 'ziparchive_available', 'extract_success', 'parser_completed'].indexOf(key) !== -1) {
			return value ? 'да' : 'нет';
		}
		return translateMessage(value);
	}

	function setField(key, value) {
		var field = root.querySelector('[data-wdc-rp-field="' + key + '"]');
		if (!field) {
			return;
		}
		field.textContent = formatValue(key, value);
	}

	function isBusy(status) {
		return status === 'queued' || status === 'running';
	}

	function renderSummary(state) {
		if (!summary) {
			return;
		}
		var status = state && state.status ? String(state.status) : 'idle';
		var stage = state && state.stage ? String(state.stage) : '';
		var parsed = state && state.parsed ? Number(state.parsed) : 0;
		var inserted = state && state.inserted ? Number(state.inserted) : 0;
		summary.textContent = 'Статус: ' + label(statusLabels, status, 'Ожидание') + '; этап: ' + label(stageLabels, stage, '-') + '; обработано: ' + parsed + '; записано: ' + inserted + ' ';
		if (spinner) {
			summary.appendChild(spinner);
		}
	}

	function render(state) {
		var status = state && state.status ? String(state.status) : 'idle';
		var busy = isBusy(status);
		root.setAttribute('data-wdc-rp-status', status);
		setField('status', status);
		[
			'stage',
			'started_at',
			'finished_at',
			'last_activity_at',
			'type',
			'source',
			'original_upload_name',
			'uploaded_file_size',
			'import_id',
			'download_url',
			'download_started_at',
			'download_duration_ms',
			'download_http_code',
			'download_response_message',
			'download_error',
			'download_backend',
			'fallback_used',
			'first_backend_error',
			'curl_errno',
			'curl_error',
			'temp_file_size',
			'extract_started_at',
			'extract_duration_ms',
			'extract_backend',
			'ziparchive_available',
			'extract_zip_file',
			'extract_zip_size',
			'extract_success',
			'extracted_payload_entry_name',
			'extracted_payload_entry_index',
			'extracted_payload_file',
			'extracted_payload_size',
			'extract_error',
			'payload_file',
			'payload_size',
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
		renderSummary(state || {});
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

	if (isBusy(root.getAttribute('data-wdc-rp-status') || 'idle')) {
		startPolling();
	}
}());
