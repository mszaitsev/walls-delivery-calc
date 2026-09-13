#!/usr/bin/env node
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const sourcePath = path.resolve(__dirname, '../../assets/admin/ozon-delivery-pickup-sync.js');
const source = fs.readFileSync(sourcePath, 'utf8');
function deferred() { let resolve; let reject; const promise = new Promise((ok, fail) => { resolve = ok; reject = fail; }); return { promise, resolve, reject }; }
async function flush() { for (let index = 0; index < 10; index += 1) await Promise.resolve(); }

function harness(initialState = state()) {
	const fields = new Map(); const requests = []; const intervals = []; let nextId = 1;
	const root = { attrs: {}, querySelector(selector) { if (selector === '[data-wdc-ozon-progress-bar]') return { hidden: false }; if (selector === '[data-wdc-ozon-error-row]' || selector === '[data-wdc-ozon-stall]') return { hidden: true, textContent: '' }; const match = selector.match(/^\[data-wdc-ozon-field="([^"]+)"\]$/); if (!match) return null; if (!fields.has(match[1])) fields.set(match[1], { textContent: '' }); return fields.get(match[1]); }, querySelectorAll() { return []; }, setAttribute(name, value) { this.attrs[name] = String(value); } };
	const window = { wdcOzonDeliveryPickupProgress: { ajaxUrl: '/admin-ajax.php', nonce: 'nonce', pollingIntervalMs: 3000, stallAfterSeconds: 60, initialState }, confirm: () => true, setInterval(callback, delay) { const item = { id: nextId++, callback, delay, active: true }; intervals.push(item); return item.id; }, clearInterval(id) { const item = intervals.find((entry) => entry.id === id); if (item) item.active = false; } };
	const document = { querySelector(selector) { if (selector === '[data-wdc-ozon-pickup-progress]') return root; if (selector === '[data-wdc-ozon-start]') return { disabled: false, value: '' }; if (selector === '[data-wdc-ozon-stop]' || selector === '[data-wdc-ozon-snapshot-message]') return null; return null; } };
	function fetch(_url, options) { const request = deferred(); const body = new URLSearchParams(String(options.body || '')); requests.push({ action: body.get('action'), body, request }); return request.promise; }
	vm.runInContext(source, vm.createContext({ window, document, fetch, URLSearchParams, Promise, Number, String, Array, Math, Date }), { filename: sourcePath });
	function tick() { const interval = intervals.find((entry) => entry.active); assert(interval, 'expected active interval'); interval.callback(); }
	function resolve(index, value) { requests[index].request.resolve({ json: () => Promise.resolve({ success: true, data: value }) }); }
	return { fields, intervals, requests, root, tick, resolve, reject: (index) => requests[index].request.reject(new Error('network')) };
}
function state(overrides = {}) { return Object.assign({ state: 'building', phase: 'discovery', is_running: true, discovery_page_count: 1, discovered_count: 100, enrichment_processed_count: 0, pending_count: 100 }, overrides); }

(async () => {
	assert(source.includes('wdc_ozon_delivery_pickup_status'), 'browser must call status action');
	assert(!source.includes('wdc_ozon_delivery_pickup_step'), 'browser must never call worker step action');
	let h = harness();
	assert.equal(h.intervals.filter((item) => item.active).length, 1, 'initial/reloaded building state starts status polling');
	h.tick(); h.tick();
	assert.equal(h.requests.length, 1, 'pending guard prevents overlapping status requests');
	assert.equal(h.requests[0].action, 'wdc_ozon_delivery_pickup_status', 'request is status-only');
	h.resolve(0, state({ phase: 'enrichment', discovery_page_count: 8, discovered_count: 800, enrichment_processed_count: 300, pending_count: 500 })); await flush();
	assert.equal(h.fields.get('discovered_count').textContent, '800', 'status response renders discovery counters');
	assert.equal(h.fields.get('enrichment_processed_count').textContent, '300', 'status response renders enrichment counters');
	h.tick(); assert.equal(h.requests.length, 2, 'next poll is allowed after previous response settles');
	h.resolve(1, state({ discovery_page_count: 9, discovered_count: 900 })); await flush();

	for (let index = 0; index < 3; index += 1) { h.tick(); h.reject(2 + index); await flush(); }
	h.tick();
	assert.equal(h.requests.length, 6, 'temporary transport failures do not permanently stop status polling');
	assert(h.requests.every((request) => request.action === 'wdc_ozon_delivery_pickup_status'), 'transport recovery remains status-only');

	for (const terminal of ['active', 'failed', 'cancelled']) {
		h = harness(); h.tick(); h.resolve(0, state({ state: terminal, is_running: false })); await flush();
		assert.equal(h.intervals.filter((item) => item.active).length, 0, `${terminal} stops polling`);
	}

	console.log('Ozon Delivery pickup browser smoke OK');
})().catch((error) => { console.error(error && error.stack ? error.stack : error); process.exit(1); });
