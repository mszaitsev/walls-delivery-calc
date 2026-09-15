const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const checkoutSource = fs.readFileSync(path.join(root, 'assets/frontend/pickup-map/wdc-pickup-checkout.js'), 'utf8');
const apiSource = fs.readFileSync(path.join(root, 'assets/frontend/pickup-map/wdc-pickup-api.js'), 'utf8');

function checkoutHarness() {
	const documentObject = {
		body: {
			addEventListener() {},
			dispatchEvent() { return true; },
			querySelectorAll() { return []; }
		},
		addEventListener() {},
		querySelector() { return null; },
		querySelectorAll() { return []; }
	};
	const sandbox = {
		window: {
			wdcPickupCheckout: {
				carrier: 'russian_post',
				pickupFamilies: [
					'russian_post:pickup',
					'yandex_delivery:pickup',
					'manual:manual_nsk:pickup',
					'manual:manual_shop:pickup'
				],
				initialContext: {}
			}
		},
		document: documentObject,
		console,
		setTimeout,
		clearTimeout,
		AbortController: class AbortController {}
	};
	const instrumented = checkoutSource.replace(
		/\}\)\(window, document\);\s*$/,
		'window.__wdcPickupRoutingTest = { shippingMethodFamily: shippingMethodFamily, pickupCarrierFromFamily: pickupCarrierFromFamily, withCarrierContext: withCarrierContext, prefetchIdentity: prefetchIdentity, prefetchIdentityMatches: prefetchIdentityMatches, registerPickupContainerContext: registerPickupContainerContext, isPickupRateValue: isPickupRateValue }; })(window, document);'
	);
	vm.runInNewContext(instrumented, sandbox, { filename: 'wdc-pickup-checkout.js' });

	return sandbox.window.__wdcPickupRoutingTest;
}

function coldCheckoutHarness() {
	const documentObject = {
		body: {
			addEventListener() {},
			dispatchEvent() { return true; },
			querySelectorAll() { return []; }
		},
		addEventListener() {},
		querySelector() { return null; },
		querySelectorAll() { return []; }
	};
	const sandbox = {
		window: {
			wdcPickupCheckout: {
				carrier: '',
				pickupFamilies: [],
				initialContext: {}
			}
		},
		document: documentObject,
		console,
		setTimeout,
		clearTimeout,
		AbortController: class AbortController {}
	};
	const instrumented = checkoutSource.replace(
		/\}\)\(window, document\);\s*$/,
		'window.__wdcPickupRoutingTest = { shippingMethodFamily: shippingMethodFamily, registerPickupContainerContext: registerPickupContainerContext, isPickupRateValue: isPickupRateValue, withCarrierContext: withCarrierContext }; })(window, document);'
	);
	vm.runInNewContext(instrumented, sandbox, { filename: 'wdc-pickup-checkout.js' });

	return { api: sandbox.window.__wdcPickupRoutingTest, window: sandbox.window };
}

async function apiHarness() {
	const calls = [];
	const sandbox = {
		window: {
			wdcPickupCheckout: {
				restUrl: 'https://example.test/wp-json/wdc/v1/',
				nonce: 'nonce',
				carrier: 'russian_post'
			}
		},
		URLSearchParams,
		Error,
		Promise,
		fetch: (url, options) => {
			calls.push({ url: String(url), options: options || {} });
			return Promise.resolve({ ok: true, json: () => Promise.resolve([]) });
		}
	};
	vm.runInNewContext(apiSource, sandbox, { filename: 'wdc-pickup-api.js' });

	const manualContext = {
		carrier: 'manual',
		carrier_key: 'manual',
		shipping_method_id: 'manual:manual_nsk',
		pickup_family: 'manual:manual_nsk:pickup',
		country_code: 'RU',
		region_name: 'Новосибирская область',
		place_name: 'Новосибирск',
		location_id: '10'
	};
	await sandbox.window.WDCPickupApi.points('', null, manualContext);
	const manualUrl = new URL(calls[0].url);
	assert.strictEqual(manualUrl.searchParams.get('carrier'), 'manual');
	assert.strictEqual(manualUrl.searchParams.get('shipping_method_id'), 'manual:manual_nsk');
	assert.strictEqual(manualUrl.searchParams.get('pickup_family'), 'manual:manual_nsk:pickup');

	sandbox.window.wdcPickupCheckout = { restUrl: 'https://example.test/wp-json/wdc/v1/' };
	await assert.rejects(
		() => sandbox.window.WDCPickupApi.points('', null, {}),
		/pickup_carrier_context_missing/
	);
	assert.strictEqual(calls.length, 1, 'Unknown generic pickup context must fail closed before fetch.');
}

(async () => {
	const checkout = checkoutHarness();

	assert.strictEqual(checkout.pickupCarrierFromFamily('russian_post:pickup'), 'russian_post');
	assert.strictEqual(checkout.pickupCarrierFromFamily('yandex_delivery:pickup'), 'yandex_delivery');
	assert.strictEqual(checkout.pickupCarrierFromFamily('manual:manual_nsk:pickup'), 'manual');
	assert.strictEqual(checkout.pickupCarrierFromFamily('manual:manual_shop:pickup'), 'manual');
	assert.strictEqual(checkout.pickupCarrierFromFamily(''), '');
	assert.strictEqual(checkout.pickupCarrierFromFamily('manual:foo:courier'), '');

	assert.strictEqual(checkout.shippingMethodFamily('manual:manual_nsk'), 'manual:manual_nsk:pickup');
	const context = checkout.withCarrierContext({ carrier: 'russian_post' }, 'manual:manual_nsk');
	assert.strictEqual(context.carrier, 'manual');
	assert.strictEqual(context.carrier_key, 'manual');
	assert.strictEqual(context.pickup_family, 'manual:manual_nsk:pickup');
	assert.strictEqual(context.shipping_method_id, 'manual:manual_nsk');

	const cold = coldCheckoutHarness();
	const coldContainer = {
		getAttribute(name) {
			return name === 'data-shipping-method-id' ? 'manual:manual_cold_pickup' : '';
		},
		querySelector(selector) {
			if (selector === '[data-wdc-pickup-family]') {
				return { value: 'manual:manual_cold_pickup:pickup' };
			}
			return null;
		}
	};
	assert.strictEqual(cold.api.isPickupRateValue('manual:manual_cold_pickup'), false);
	cold.api.registerPickupContainerContext(coldContainer);
	assert.strictEqual(cold.api.shippingMethodFamily('manual:manual_cold_pickup'), 'manual:manual_cold_pickup:pickup');
	assert.strictEqual(cold.api.isPickupRateValue('manual:manual_cold_pickup'), true);
	assert.strictEqual(JSON.stringify(cold.window.wdcPickupCheckout.pickupFamilies), JSON.stringify(['manual:manual_cold_pickup:pickup']));
	const coldContext = cold.api.withCarrierContext({ carrier: 'russian_post' }, 'manual:manual_cold_pickup');
	assert.strictEqual(coldContext.carrier, 'manual');
	assert.strictEqual(coldContext.pickup_family, 'manual:manual_cold_pickup:pickup');

	const explicitContext = checkout.withCarrierContext({ carrier: 'russian_post', pickup_family: 'manual:manual_shop:pickup' }, 'manual:manual_shop');
	assert.strictEqual(explicitContext.carrier, 'manual');
	assert.strictEqual(explicitContext.pickup_family, 'manual:manual_shop:pickup');

	const identityA = checkout.prefetchIdentity(context, 'manual:manual_nsk');
	const identityB = checkout.prefetchIdentity(explicitContext, 'manual:manual_shop');
	assert.strictEqual(identityA.carrier, 'manual');
	assert.strictEqual(identityB.carrier, 'manual');
	assert.notStrictEqual(identityA.key, identityB.key);
	assert(!checkout.prefetchIdentityMatches(identityA, identityB), 'Manual service family switch must not reuse stale prefetch.');

	await apiHarness();
	console.log('Pickup provider routing JS smoke passed.');
})().catch((error) => {
	console.error(error);
	process.exit(1);
});
