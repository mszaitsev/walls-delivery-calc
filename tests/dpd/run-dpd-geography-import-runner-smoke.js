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
	const promise = new Promise((res, rej) => { resolve = res; reject = rej; });
	return { promise, resolve, reject };
}

async function flush() {
	for (let i = 0; i < 10; i += 1) await Promise.resolve();
}

function createHarness() {
	const fields = new Map();
	const requests = [];
	const timers = [];
	let nextTimerId = 1;
	const root = {
		attrs: { 'data-wdc-dpd-phase': 'ready' },
		querySelector(selector) {
			if (selector === '[data-wdc-dpd-progress-bar]') return this.bar;
			if (selector === '[data-wdc-dpd-summary]') return this.summary;
			const match = selector.match(/^\[data-wdc-dpd-field="([^"]+)"\]$/);
			if (!match) return null;
			if (!fields.has(match[1])) fields.set(match[1], { textContent: '' });
			return fields.get(match[1]);
		},
		setAttribute(name, value) { this.attrs[name] = String(value); },
		bar: { style: { width: '' } },
		summary: { textContent: '' }
	};
	const window = {
		wdcDpdGeographyImport: { ajaxUrl: 'https://example.test/admin-ajax.php', nonce: 'nonce', busyRetryMs: 20 },
		setTimeout(fn, delay) {
			const id = nextTimerId++;
			timers.push({ id, fn, delay, cleared: false });
			return id;
		},
		clearTimeout(id) {
			const timer = timers.find((entry) => entry.id === id);
			if (timer) timer.cleared = true;
		}
	};
	function fetch(url, options) {
		const deferred = createDeferred();
		const body = new URLSearchParams(String(options.body || ''));
		requests.push({ url, options, action: body.get('action'), jobId: body.get('job_id'), expectedOffset: body.get('expected_byte_offset'), deferred });
		return deferred.promise;
	}
	vm.runInContext(source, vm.createContext({ window, document: { getElementById: () => root }, fetch, URLSearchParams, Promise, Number, String, Array, Math, Error }), { filename: sourcePath });
	function resolveRequest(index, state) {
		requests[index].deferred.resolve({ json: () => Promise.resolve({ success: true, data: state }) });
	}
	function rejectRequest(index) { requests[index].deferred.reject(new Error('network')); }
	function runNextTimer() {
		const timer = timers.find((entry) => !entry.cleared);
		assert(timer, 'expected scheduled timer');
		timer.cleared = true;
		timer.fn();
		return timer;
	}
	return { fields, requests, root, resolveRequest, rejectRequest, runNextTimer };
}

function state(overrides = {}) {
	return Object.assign({ job_id: 'job-1', state_revision: 1, phase: 'importing', status: '', rows_read: 0, byte_offset: 100, percent_complete: 1, last_message: 'running' }, overrides);
}

(async () => {
	assert(!/setInterval\s*\(/.test(source), 'production UI must not use setInterval');
	assert(!source.includes('wdc_dpd_geography_import_step'), 'browser must not execute geography work');
	assert(!source.includes('expected_byte_offset'), 'browser must not own worker checkpoints');

	let h = createHarness();
	assert.strictEqual(h.requests[0].action, 'wdc_dpd_geography_import_status', 'first request is read-only status');
	h.resolveRequest(0, state({ phase: 'ready', state_revision: 1 }));
	await flush();
	h.runNextTimer();
	assert.strictEqual(h.requests[1].action, 'wdc_dpd_geography_import_status', 'active jobs schedule another read-only status poll');
	assert.strictEqual(h.requests[1].jobId, null, 'status poll sends no worker owner id');
	assert.strictEqual(h.requests[1].expectedOffset, null, 'status poll sends no byte-offset checkpoint');
	assert.throws(() => h.runNextTimer(), /expected scheduled timer/, 'in-flight status request prevents overlap');
	h.resolveRequest(1, state({ state_revision: 2, rows_read: 1500, byte_offset: 500 }));
	await flush();
	h.runNextTimer();
	assert.strictEqual(h.requests[2].action, 'wdc_dpd_geography_import_status', 'subsequent progress remains polling-only');

	h = createHarness();
	h.rejectRequest(0);
	await flush();
	h.runNextTimer();
	assert.strictEqual(h.requests[1].action, 'wdc_dpd_geography_import_status', 'network failure retries read-only status');

	h = createHarness();
	h.resolveRequest(0, state({ phase: 'finished', status: 'success', state_revision: 3 }));
	await flush();
	assert.throws(() => h.runNextTimer(), /expected scheduled timer/, 'terminal state stops polling');

	h = createHarness();
	h.resolveRequest(0, state({ phase: 'importing', state_revision: 10, operation_control: { outcome: 'reset_required' } }));
	await flush();
	assert.match(h.root.summary.textContent, /сброс/, 'legacy reset-required state renders recovery guidance');
	assert.throws(() => h.runNextTimer(), /expected scheduled timer/, 'reset-required state stops polling');

	h = createHarness();
	h.resolveRequest(0, state({ state_revision: 4, worker_slice_units: 4, worker_slice_duration_ms: 17000, worker_slice_stop_reason: 'time_budget', last_step_duration_ms: 250, max_step_duration_ms: 900 }));
	await flush();
	assert.strictEqual(h.fields.get('worker_slice_units').textContent, '4', 'worker slice units render');
	assert.strictEqual(h.fields.get('worker_slice_duration_ms').textContent, '17000', 'worker slice duration renders');
	assert.strictEqual(h.fields.get('worker_slice_stop_reason').textContent, 'time_budget', 'worker slice stop reason renders');
	assert.strictEqual(h.fields.get('last_step_duration_ms').textContent, '250', 'last atomic step duration renders');
	assert.strictEqual(h.fields.get('max_step_duration_ms').textContent, '900', 'max atomic step duration renders');
	assert(/ожидание/.test(source) && /Связь с сервером прервана/.test(source), 'UI keeps Russian user-facing labels and transport recovery message');

	console.log('DPD geography import runner smoke OK');
})().catch((error) => {
	console.error(error && error.stack ? error.stack : error);
	process.exit(1);
});
