'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const rootDir = path.resolve(__dirname, '..', '..');
const source = fs.readFileSync(path.join(rootDir, 'assets', 'admin', 'yandex-delivery-pickup-v2-runner.js'), 'utf8');
const rootSelectors = [
	'[data-wdc-yandex-geo-pipeline-v2]',
	'[data-wdc-yandex-pickup-v2-runner]',
	'[data-wdc-yandex-geo-v2-builder]',
	'[data-wdc-yandex-geo-v2-region-enrichment]',
	'[data-wdc-yandex-location-mapping-v2]'
];

function element() {
	return {
		attributes: {},
		disabled: false,
		listeners: {},
		textContent: '',
		addEventListener(type, listener) { this.listeners[type] = listener; },
		setAttribute(name, value) { this.attributes[name] = String(value); },
		removeAttribute(name) { delete this.attributes[name]; },
		getAttribute(name) { return this.attributes[name] || ''; },
		click() { if (this.listeners.click) { this.listeners.click(); } }
	};
}

function rootElement() {
	const elements = new Map();
	return {
		attributes: {},
		elements,
		setAttribute(name, value) { this.attributes[name] = String(value); },
		querySelector(selector) {
			if (!elements.has(selector)) {
				elements.set(selector, element());
			}
			return elements.get(selector);
		},
		querySelectorAll() { return []; }
	};
}

function createHarness(pipelineStatus, lowerStatus) {
	const roots = new Map(rootSelectors.map((selector) => [selector, rootElement()]));
	const actions = [];
	const timers = [];
	class FormData {
		constructor() { this.values = {}; }
		append(key, value) { this.values[key] = value; }
	}
	const context = {
		console,
		document: { querySelector: (selector) => roots.get(selector) || null },
		window: {
			FormData,
			setTimeout: (callback) => { timers.push(callback); return timers.length; },
			wdcYandexDeliveryPickupV2Runner: {
				ajaxUrl: '/wp-admin/admin-ajax.php',
				nonce: 'nonce',
				geoPipelineInitialState: { status: pipelineStatus, message: pipelineStatus },
				initialState: { status: lowerStatus.pickup, message: 'pickup' },
				geoBuilderInitialState: { status: lowerStatus.geo, message: 'geo' },
				geoRegionEnrichmentInitialState: { status: lowerStatus.enrichment, message: 'enrichment' },
				locationMappingInitialState: { status: lowerStatus.mapping, message: 'mapping' }
			},
			fetch: (_url, request) => {
				const action = request.body.values.action;
				actions.push(action);
				const state = action === 'wdc_yandex_delivery_geo_pipeline_v2_status'
					? { status: 'running', message: 'running' }
					: { status: 'idle', message: 'idle' };
				return Promise.resolve({ status: 200, text: () => Promise.resolve(JSON.stringify({ success: true, data: state })) });
			}
		}
	};
	vm.runInNewContext(source, context, { filename: 'yandex-delivery-pickup-v2-runner.js' });
	return { roots, actions, timers };
}

function lowerButtons(harness) {
	return rootSelectors.slice(1).flatMap((rootSelector) => Array.from(harness.roots.get(rootSelector).elements.values()).filter((item) => item.listeners.click));
}

async function flushPromises() {
	await new Promise((resolve) => setImmediate(resolve));
	await new Promise((resolve) => setImmediate(resolve));
}

(async () => {
	for (const pipelineStatus of ['running', 'paused']) {
		const harness = createHarness(pipelineStatus, {
			pickup: 'importing',
			geo: 'building',
			enrichment: 'enriching_regions',
			mapping: 'mapping'
		});
		assert.deepEqual(harness.actions, [], `active ${pipelineStatus} pipeline must suppress every lower auto-loop`);
		assert.ok(lowerButtons(harness).length >= 12, 'all standalone lower controls must be wired');
		assert.ok(lowerButtons(harness).every((button) => button.disabled), `active ${pipelineStatus} pipeline must disable standalone lower controls`);
		lowerButtons(harness).forEach((button) => button.click());
		assert.deepEqual(harness.actions, [], `programmatic lower clicks must remain blocked while pipeline is ${pipelineStatus}`);
		if (pipelineStatus === 'running') {
			assert.equal(harness.timers.length, 1, 'running full pipeline must schedule status polling');
			harness.timers.shift()();
			await flushPromises();
			assert.deepEqual(harness.actions, ['wdc_yandex_delivery_geo_pipeline_v2_status'], 'full pipeline browser must remain status-poll-only');
		} else {
			assert.equal(harness.timers.length, 0, 'paused full pipeline must not schedule work');
		}
	}

	const inactive = createHarness('done', { pickup: 'idle', geo: 'idle', enrichment: 'idle', mapping: 'idle' });
	assert.ok(lowerButtons(inactive).every((button) => !button.disabled), 'terminal full pipeline must re-enable every standalone lower control');
	[
		['[data-wdc-yandex-pickup-v2-runner]', '[data-wdc-yandex-v2-start]'],
		['[data-wdc-yandex-geo-v2-builder]', '[data-wdc-yandex-geo-v2-start]'],
		['[data-wdc-yandex-geo-v2-region-enrichment]', '[data-wdc-yandex-geo-v2-region-enrichment-start]'],
		['[data-wdc-yandex-location-mapping-v2]', '[data-wdc-yandex-location-mapping-v2-start]']
	].forEach(([rootSelector, buttonSelector]) => inactive.roots.get(rootSelector).elements.get(buttonSelector).click());
	await flushPromises();
	assert.deepEqual(inactive.actions, [
		'wdc_yandex_delivery_pickup_v2_runner_start',
		'wdc_yandex_delivery_geo_v2_builder_start',
		'wdc_yandex_geo_v2_region_enrichment_start',
		'wdc_yandex_location_mapping_v2_start'
	], 'inactive full pipeline must preserve every standalone lower flow');

	console.log('Yandex Delivery pickup v2 browser ownership smoke OK');
})().catch((error) => {
	console.error(error);
	process.exitCode = 1;
});
