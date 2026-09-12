#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const sourcePath = path.resolve(__dirname, '../../assets/admin/russian-post-pickup-import.js');
const source = fs.readFileSync(sourcePath, 'utf8');
const instrumentedSource = source.replace(
	'lastRenderedRevision = revision;',
	'lastRenderedRevision = revision; window.__wdcTestLastRenderedRevision = lastRenderedRevision;'
);

function deferred() {
	let resolve;
	let reject;
	const promise = new Promise((resolvePromise, rejectPromise) => {
		resolve = resolvePromise;
		reject = rejectPromise;
	});
	return { promise, resolve, reject };
}

async function flush() {
	for (let index = 0; index < 10; index += 1) {
		await Promise.resolve();
	}
}

function createHarness(initialStatus = 'running') {
	const fields = new Map();
	const requests = [];
	const intervals = [];
	let nextIntervalId = 1;
	const refreshButton = { listener: null, addEventListener(_type, listener) { this.listener = listener; } };
	const spinner = { classList: { toggle() {} } };
	const summary = { textContent: '', appendChild() {} };
	const runButton = { disabled: false };
	const root = {
		attrs: { 'data-wdc-rp-status': initialStatus },
		querySelector(selector) {
			if (selector === '[data-wdc-rp-refresh-status]') return refreshButton;
			if (selector === '[data-wdc-rp-spinner]') return spinner;
			if (selector === '[data-wdc-rp-status-summary]') return summary;
			const match = selector.match(/^\[data-wdc-rp-field="([^"]+)"\]$/);
			if (!match) return null;
			if (!fields.has(match[1])) fields.set(match[1], { textContent: '' });
			return fields.get(match[1]);
		},
		setAttribute(name, value) { this.attrs[name] = String(value); },
		getAttribute(name) { return this.attrs[name] || ''; }
	};
	const window = {
		wdcRussianPostPickupImport: { ajaxUrl: 'https://example.test/admin-ajax.php', nonce: 'nonce' },
		setInterval(callback, delay) {
			const interval = { id: nextIntervalId++, callback, delay, active: true };
			intervals.push(interval);
			return interval.id;
		},
		clearInterval(id) {
			const interval = intervals.find((entry) => entry.id === id);
			if (interval) interval.active = false;
		}
	};
	function fetch(_url, options) {
		const request = deferred();
		const body = new URLSearchParams(String(options.body || ''));
		requests.push({ action: body.get('action'), request });
		return request.promise;
	}
	vm.runInContext(
		instrumentedSource,
		vm.createContext({ window, document: { querySelector: (selector) => selector === '[data-wdc-rp-pickup-import-status]' ? root : (selector.startsWith('button[') ? runButton : null) }, fetch, URLSearchParams, Promise, Number, String, Array, Object }),
		{ filename: sourcePath }
	);
	function tick() {
		const interval = intervals.find((entry) => entry.active);
		assert(interval, 'expected active polling interval');
		interval.callback();
	}
	function resolve(index, state) {
		requests[index].request.resolve({ json: () => Promise.resolve({ success: true, data: state }) });
	}
	return { fields, intervals, requests, root, window, tick, resolve, reject: (index) => requests[index].request.reject(new Error('network')) };
}

function state(overrides = {}) {
	return Object.assign({ status: 'running', stage: 'parse', state_revision: 1, parsed: 0, inserted: 0 }, overrides);
}

(async () => {
	assert(source.includes("wdc_russian_post_pickup_import_status"), 'browser must use the read-only status endpoint');
	assert(!source.includes('wdc_russian_post_pickup_import_batch') && !source.includes('run_import_batch'), 'browser must not execute worker processing');

	let harness = createHarness();
	assert.equal(harness.intervals.filter((entry) => entry.active).length, 1, 'busy state starts one polling interval');
	harness.tick();
	assert.equal(harness.requests.length, 1, 'first polling tick starts one status request');
	harness.tick();
	assert.equal(harness.requests.length, 1, 'inFlight guard prevents a parallel status request');
	harness.resolve(0, state({ state_revision: 10, parsed: 3500, inserted: 3400 }));
	await flush();
	assert.equal(harness.fields.get('parsed').textContent, '3500', 'new revision renders current parsed counter');
	assert.equal(harness.window.__wdcTestLastRenderedRevision, 10, 'lastRenderedRevision records revision 10');
	harness.tick();
	harness.resolve(1, state({ state_revision: 9, parsed: 2000, inserted: 1900 }));
	await flush();
	assert.equal(harness.fields.get('parsed').textContent, '3500', 'late lower revision cannot roll parsed counter back from 3500 to 2000');
	assert.equal(harness.window.__wdcTestLastRenderedRevision, 10, 'ignored revision 9 cannot lower lastRenderedRevision');

	harness.tick();
	harness.reject(2);
	await flush();
	harness.tick();
	assert.equal(harness.requests.length, 4, 'polling recovers after a temporary transport failure');
	assert(harness.requests.every((request) => request.action === 'wdc_russian_post_pickup_import_status'), 'transport retry remains status-only and never mutates worker state');
	assert.equal(harness.fields.get('parsed').textContent, '3500', 'transport failure preserves the newest rendered counters');

	for (const terminalStatus of ['success', 'failed']) {
		harness = createHarness();
		harness.tick();
		harness.resolve(0, state({ status: terminalStatus, stage: terminalStatus === 'success' ? 'finished' : 'failed', state_revision: 20 }));
		await flush();
		assert.equal(harness.intervals.filter((entry) => entry.active).length, 0, `${terminalStatus} state stops polling`);
	}

	console.log('Russian Post pickup import runner smoke OK');
})().catch((error) => {
	console.error(error && error.stack ? error.stack : error);
	process.exit(1);
});
