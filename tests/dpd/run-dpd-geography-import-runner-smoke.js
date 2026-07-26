#!/usr/bin/env node
'use strict';

const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const sourcePath = path.resolve(__dirname, '../../assets/admin/dpd-geography-import.js');
const source = fs.readFileSync(sourcePath, 'utf8');

function createDeferred() {
	let resolve;
	let reject;
	const promise = new Promise((res, rej) => {
		resolve = res;
		reject = rej;
	});
	return { promise, resolve, reject };
}

async function flush() {
	for (let i = 0; i < 10; i += 1) {
		await Promise.resolve();
	}
}

function createHarness() {
	const fields = new Map();
	const requests = [];
	const timers = [];
	let nextTimerId = 1;
	const root = {
		attrs: { 'data-wdc-dpd-phase': 'ready' },
		querySelector(selector) {
			if (selector === '[data-wdc-dpd-progress-bar]') {
				return this.bar;
			}
			if (selector === '[data-wdc-dpd-summary]') {
				return this.summary;
			}
			const match = selector.match(/^\[data-wdc-dpd-field="([^"]+)"\]$/);
			if (match) {
				if (!fields.has(match[1])) {
					fields.set(match[1], { textContent: '' });
				}
				return fields.get(match[1]);
			}
			return null;
		},
		getAttribute(name) {
			return this.attrs[name] || '';
		},
		setAttribute(name, value) {
			this.attrs[name] = String(value);
		},
		bar: { style: { width: '' } },
		summary: { textContent: '' }
	};
	const window = {
		wdcDpdGeographyImport: {
			ajaxUrl: 'https://example.test/admin-ajax.php',
			nonce: 'nonce',
			stepDelayMs: 10,
			busyRetryMs: 20
		},
		setTimeout(fn, delay) {
			const id = nextTimerId++;
			timers.push({ id, fn, delay, cleared: false });
			return id;
		},
		clearTimeout(id) {
			const timer = timers.find((entry) => entry.id === id);
			if (timer) {
				timer.cleared = true;
			}
		}
	};
	function fetch(url, options) {
		const deferred = createDeferred();
		const body = new URLSearchParams(String(options.body || ''));
		requests.push({
			url,
			options,
			action: body.get('action'),
			jobId: body.get('job_id'),
			expectedOffset: body.get('expected_byte_offset'),
			deferred
		});
		return deferred.promise;
	}
	const context = vm.createContext({
		window,
		document: {
			getElementById(id) {
				return id === 'wdc-dpd-geography-import-progress' ? root : null;
			}
		},
		fetch,
		URLSearchParams,
		Promise,
		Number,
		String,
		Array,
		Math,
		Error
	});
	vm.runInContext(source, context, { filename: sourcePath });
	function resolveRequest(index, state) {
		requests[index].deferred.resolve({
			json: () => Promise.resolve({ success: true, data: state })
		});
	}
	function rejectRequest(index) {
		requests[index].deferred.reject(new Error('network'));
	}
	function runNextTimer() {
		const timer = timers.find((entry) => !entry.cleared);
		assert(timer, 'expected scheduled timer');
		timer.cleared = true;
		timer.fn();
		return timer;
	}
	return { fields, requests, timers, root, resolveRequest, rejectRequest, runNextTimer };
}

function state(overrides = {}) {
	return Object.assign({
		job_id: 'job-1',
		state_revision: 1,
		phase: 'importing',
		status: '',
		rows_read: 0,
		byte_offset: 100,
		percent_complete: 1,
		last_message: 'running'
	}, overrides);
}

(async () => {
	assert(!/setInterval\s*\(/.test(source), 'production runner must not use setInterval');

	let h = createHarness();
	assert.strictEqual(h.requests[0].action, 'wdc_dpd_geography_import_status', 'first request is read-only status');
	h.resolveRequest(0, state({ phase: 'ready', state_revision: 1, byte_offset: 100 }));
	await flush();
	h.runNextTimer();
	assert.strictEqual(h.requests[1].action, 'wdc_dpd_geography_import_step', 'ready status schedules mutating step');
	assert.strictEqual(h.requests[1].jobId, 'job-1', 'step sends job_id');
	assert.strictEqual(h.requests[1].expectedOffset, '100', 'step sends expected_byte_offset');

	h.runNextTimer = h.runNextTimer.bind(h);
	for (let i = 0; i < 3; i += 1) {
		assert.throws(() => h.runNextTimer(), /expected scheduled timer/, 'pending step does not schedule overlapping timers');
	}
	assert.strictEqual(h.requests.length, 2, 'pending step prevents overlapping fetches');
	h.resolveRequest(1, state({ state_revision: 2, rows_read: 500, byte_offset: 500 }));
	await flush();
	h.runNextTimer();
	assert.strictEqual(h.requests[2].action, 'wdc_dpd_geography_import_step', 'next step is scheduled only after previous step resolves');
	assert.strictEqual(h.requests[2].expectedOffset, '500', 'next step uses updated byte_offset');

	h = createHarness();
	h.resolveRequest(0, state({ phase: 'ready', state_revision: 1, byte_offset: 100 }));
	await flush();
	h.runNextTimer();
	h.rejectRequest(1);
	await flush();
	h.runNextTimer();
	assert.strictEqual(h.requests[2].action, 'wdc_dpd_geography_import_status', 'step network failure recovers through read-only status');
	h.resolveRequest(2, state({ state_revision: 2, byte_offset: 700 }));
	await flush();
	h.runNextTimer();
	assert.strictEqual(h.requests[3].action, 'wdc_dpd_geography_import_step', 'runner resumes step after status recovery');
	assert.strictEqual(h.requests[3].expectedOffset, '700', 'runner uses recovered offset after network failure');

	h = createHarness();
	h.resolveRequest(0, state({ phase: 'ready', state_revision: 1, byte_offset: 100 }));
	await flush();
	h.runNextTimer();
	h.resolveRequest(1, state({ state_revision: 1, byte_offset: 100, step_control: { outcome: 'busy', retry_after_ms: 20 } }));
	await flush();
	h.runNextTimer();
	assert.strictEqual(h.requests[2].action, 'wdc_dpd_geography_import_status', 'busy response schedules status retry, not parallel step');

	h = createHarness();
	h.resolveRequest(0, state({ phase: 'ready', state_revision: 1, byte_offset: 100 }));
	await flush();
	h.runNextTimer();
	h.resolveRequest(1, state({ state_revision: 2, byte_offset: 900, step_control: { outcome: 'stale', retry_after_ms: 10 } }));
	await flush();
	h.runNextTimer();
	assert.strictEqual(h.requests[2].action, 'wdc_dpd_geography_import_step', 'stale response schedules a fresh step');
	assert.strictEqual(h.requests[2].expectedOffset, '900', 'stale response advances to the latest byte_offset');

	h = createHarness();
	h.resolveRequest(0, state({ phase: 'finished', status: 'success', state_revision: 3 }));
	await flush();
	assert.throws(() => h.runNextTimer(), /expected scheduled timer/, 'terminal state stops the runner');

	h = createHarness();
	h.resolveRequest(0, state({
		state_revision: 4,
		foreign_rows: 12,
		foreign_am_rows: 1,
		foreign_by_rows: 2,
		foreign_kz_rows: 3,
		foreign_kg_rows: 4,
		foreign_locations_inserted: 5,
		foreign_locations_updated: 6,
		errors_total: 7,
		finalized_changes: 8,
		stale_cleared: 9,
		stale_cleanup_skipped: true
	}));
	await flush();
	assert.strictEqual(h.fields.get('foreign_rows').textContent, '12', 'foreign_rows renders');
	assert.strictEqual(h.fields.get('foreign_am_rows').textContent, '1', 'foreign AM rows render');
	assert.strictEqual(h.fields.get('foreign_by_rows').textContent, '2', 'foreign BY rows render');
	assert.strictEqual(h.fields.get('foreign_kz_rows').textContent, '3', 'foreign KZ rows render');
	assert.strictEqual(h.fields.get('foreign_kg_rows').textContent, '4', 'foreign KG rows render');
	assert.strictEqual(h.fields.get('foreign_locations_inserted').textContent, '5', 'foreign inserted counter renders');
	assert.strictEqual(h.fields.get('foreign_locations_updated').textContent, '6', 'foreign updated counter renders');
	assert.strictEqual(h.fields.get('errors_total').textContent, '7', 'errors_total renders');
	assert.strictEqual(h.fields.get('finalized_changes').textContent, '8', 'finalized_changes renders');
	assert.strictEqual(h.fields.get('stale_cleared').textContent, '9', 'stale_cleared renders');
	assert.strictEqual(h.fields.get('stale_cleanup_skipped').textContent, 'yes', 'stale cleanup skipped renders');

	console.log('DPD geography import runner smoke OK');
})().catch((error) => {
	console.error(error && error.stack ? error.stack : error);
	process.exit(1);
});
