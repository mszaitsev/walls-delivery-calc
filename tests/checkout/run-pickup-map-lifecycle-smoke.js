const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const source = fs.readFileSync(path.join(root, 'assets/frontend/pickup-map/wdc-pickup-map.js'), 'utf8');
const yandexProviderSource = fs.readFileSync(path.join(root, 'assets/frontend/pickup-map/providers/wdc-map-provider-yandex.js'), 'utf8');

function wait(ms) {
	return new Promise((resolve) => setTimeout(resolve, ms));
}

function deferred() {
	let resolve;
	let reject;
	const promise = new Promise((res, rej) => {
		resolve = res;
		reject = rej;
	});
	return { promise, resolve, reject };
}

class FakeElement {
	constructor(name) {
		this.name = name;
		this.listeners = {};
		this.parentNode = null;
		this.innerHTML = '';
		this.textContent = '';
		this.disabled = false;
		this.hidden = false;
		this.scrollHeight = 0;
		this.clientHeight = 0;
		this.scrollTop = 0;
		this.classList = {
			contains: () => false,
			add: () => {},
			remove: () => {},
			toggle: () => {}
		};
	}

	addEventListener(type, callback) {
		this.listeners[type] = this.listeners[type] || [];
		this.listeners[type].push(callback);
	}

	removeEventListener(type, callback) {
		this.listeners[type] = (this.listeners[type] || []).filter((item) => item !== callback);
	}

	dispatch(type, detail) {
		(this.listeners[type] || []).slice().forEach((callback) => callback({
			type,
			detail,
			target: this,
			preventDefault: () => {},
			closest: () => null
		}));
	}

	querySelector() {
		return null;
	}

	querySelectorAll() {
		return [];
	}

	closest() {
		return null;
	}

	getBoundingClientRect() {
		return { top: 0, bottom: 0 };
	}
}

function createHarness(api) {
	const calls = [];
	let providerInstance = null;
	const listParent = new FakeElement('list-parent');
	const list = new FakeElement('list');
	list.parentNode = listParent;
	listParent.insertBefore = () => {};
	listParent.querySelector = () => new FakeElement('list-select');

	const element = new FakeElement('map');
	const card = new FakeElement('card');
	const confirm = new FakeElement('confirm');
	const dialog = {
		querySelector: (selector) => selector === '[data-wdc-list]' ? list : null
	};
	element.closest = () => dialog;

	const sandbox = {
		window: {
			wdcPickupCheckout: {
				mapProvider: 'leaflet',
				yandexApiKeyPresent: true
			},
			WDCPickupApi: api,
			WDCPickupMapProviders: {
				leaflet: {
					create: (container, options) => {
						if (options && options.center) {
							calls.push(['setCenter', Number(options.center.lat), Number(options.center.lng), options.center.zoom]);
						}
						providerInstance = {
							options,
							selected: null,
							cancelledFit: 0,
							renderMarkers(points) {
								calls.push(['renderMarkers', points]);
							},
							clearMarkers() {
								calls.push(['clearMarkers']);
							},
							setCenter(lat, lng, zoom) {
								calls.push(['setCenter', Number(lat), Number(lng), zoom]);
							},
							fitToMarkers(options) {
								calls.push(['fitToMarkers', options]);
							},
							cancelPendingFit() {
								this.cancelledFit += 1;
								calls.push(['cancelPendingFit']);
							},
							focusPoint(point) {
								calls.push(['focusPoint', point]);
							},
							setActivePoint(pointId) {
								calls.push(['setActivePoint', pointId]);
							},
							openPointPopup() {},
							closePopup() {},
							onPointClick(callback) {
								this.pointClick = callback;
							},
							onPopupSelect(callback) {
								this.popupSelect = callback;
							},
							onPopupClose(callback) {
								this.popupClose = callback;
							},
							onMapClick(callback) {
								this.mapClick = callback;
							},
							invalidateSize() {
								calls.push(['invalidateSize']);
							},
							destroy() {
								calls.push(['providerDestroy']);
							},
							fireBounds(bbox) {
								options.onBoundsChange(bbox || '1,2,3,4');
							}
						};
						return providerInstance;
					}
				}
			},
			setTimeout,
			clearTimeout,
			AbortController,
			CustomEvent: class CustomEvent {
				constructor(type, options) {
					this.type = type;
					this.detail = options && options.detail;
				}
			}
		},
		document: {
			body: new FakeElement('body'),
			createElement: () => new FakeElement('created'),
			querySelector: () => null
		},
		setTimeout,
		clearTimeout,
		AbortController,
		CustomEvent: class CustomEvent {
			constructor(type, options) {
				this.type = type;
				this.detail = options && options.detail;
			}
		},
		Promise,
		Number,
		Math,
		parseFloat,
		isFinite,
		isNaN,
		console
	};
	sandbox.window.window = sandbox.window;
	sandbox.window.document = sandbox.document;
	sandbox.window.Promise = Promise;
	sandbox.window.Number = Number;
	sandbox.window.Math = Math;
	sandbox.window.parseFloat = parseFloat;
	sandbox.window.isFinite = isFinite;
	sandbox.window.isNaN = isNaN;
	vm.createContext(sandbox);
	vm.runInContext(source, sandbox);

	const map = sandbox.window.WDCPickupMap.create(element, card, confirm, {
		loading: 'loading',
		empty: 'empty',
		selectPoint: 'select'
	}, api.context || {});

	return { calls, element, card, confirm, provider: () => providerInstance, map };
}

function point(id, lat, lng) {
	return { id, point_code: id, address: 'Address ' + id, lat, lng };
}

async function programmaticSuppressionAllowsFirstUserPan() {
	let requests = 0;
	const api = {
		context: { preloadedPoints: [point('a', 53.9, 27.5), point('b', 53.91, 27.56)] },
		points: () => {
			requests += 1;
			return Promise.resolve([point('next', 53.92, 27.57)]);
		}
	};
	const harness = createHarness(api);
	await wait(10);
	const provider = harness.provider();
	provider.fireBounds('programmatic-1');
	provider.fireBounds('programmatic-2');
	await wait(180);
	assert.strictEqual(requests, 0, 'programmatic fit bounds must not request points');
	harness.element.dispatch('pointerdown');
	provider.fireBounds('user-pan');
	await wait(320);
	assert.strictEqual(requests, 1, 'first user pan after suppression must request points');
	harness.map.destroy();
}

async function lateAsyncDoesNotAutoFitAfterInteraction() {
	const pending = deferred();
	const api = {
		context: { carrier: 'cdek', cdek_city_code: '9220' },
		points: () => pending.promise
	};
	const harness = createHarness(api);
	await wait(80);
	const initialCenterCount = harness.calls.filter((call) => call[0] === 'setCenter').length;
	harness.element.dispatch('wheel');
	pending.resolve([point('min40', 53.9, 27.56), point('min41', 53.91, 27.57)]);
	await wait(40);
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'renderMarkers').length, 1, 'late points should still render markers');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'fitToMarkers').length, 0, 'late points must not fit markers after user interaction');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'setCenter').length, initialCenterCount, 'late points must not center after user interaction');
	harness.map.destroy();
}

async function preloadedPointsFitOnceWithoutSelection() {
	const api = {
		context: { preloadedPoints: [point('a', 53.9, 27.5), point('b', 53.91, 27.56)] },
		points: () => Promise.resolve([])
	};
	const harness = createHarness(api);
	await wait(20);
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'fitToMarkers').length, 1, 'preloaded points should fit once');
	assert.strictEqual(harness.map.selected(), null, 'auto-fit must not select a point');
	harness.map.destroy();
}

async function emptyThenNonEmptyStillFits() {
	let call = 0;
	const api = {
		context: { carrier: 'cdek', cdek_city_code: '9220' },
		points: () => {
			call += 1;
			return Promise.resolve(call === 1 ? [] : [point('min40', 53.9, 27.56), point('min41', 53.91, 27.57)]);
		}
	};
	const harness = createHarness(api);
	await wait(90);
	assert.strictEqual(harness.calls.filter((item) => item[0] === 'fitToMarkers').length, 0, 'empty first response must not consume initial fit');
	harness.provider().fireBounds('manual-second-load');
	await wait(320);
	assert.strictEqual(harness.calls.filter((item) => item[0] === 'fitToMarkers').length, 1, 'second non-empty response should fit once');
	harness.map.destroy();
}

async function selectedPointBeatsPrefetchCenter() {
	const selected = point('selected', 53.9, 27.56);
	const api = {
		context: {
			centerLat: 55.0302,
			centerLng: 82.9204,
			centerTrusted: false,
			selectedPoint: selected,
			preloadedPoints: [point('a', 55.03, 82.92), point('b', 55.04, 82.93)]
		},
		points: () => Promise.resolve([])
	};
	const harness = createHarness(api);
	await wait(20);
	const centers = harness.calls.filter((call) => call[0] === 'setCenter');
	assert.deepStrictEqual(centers[0].slice(1), [53.9, 27.56, 13], 'selected point coordinates must initialize provider before derived center');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'fitToMarkers').length, 0, 'selected point should block broad initial fit');
	harness.map.destroy();
}

async function canonicalDestinationBeatsDerivedCenter() {
	const api = {
		context: {
			lat: 53.9,
			lng: 27.56,
			centerLat: 55.0302,
			centerLng: 82.9204,
			centerTrusted: false,
			preloadedPoints: [point('a', 55.03, 82.92), point('b', 55.04, 82.93)]
		},
		points: () => Promise.resolve([])
	};
	const harness = createHarness(api);
	await wait(20);
	const centers = harness.calls.filter((call) => call[0] === 'setCenter');
	assert.deepStrictEqual(centers[0].slice(1), [53.9, 27.56, 13], 'destination coordinates must initialize provider before derived center');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'fitToMarkers').length, 0, 'trusted destination should block broad initial fit');
	harness.map.destroy();
}

async function derivedCenterStillAllowsFit() {
	const api = {
		context: {
			centerLat: 55.0302,
			centerLng: 82.9204,
			centerTrusted: false,
			preloadedPoints: [point('a', 53.9, 27.56), point('b', 53.91, 27.57)]
		},
		points: () => Promise.resolve([])
	};
	const harness = createHarness(api);
	await wait(20);
	assert.deepStrictEqual(harness.calls.filter((call) => call[0] === 'setCenter')[0].slice(1), [55.0302, 82.9204, 13], 'derived center may initialize provider');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'fitToMarkers').length, 1, 'derived center must not block point fit');
	harness.map.destroy();
}

async function invalidCoordinatesDoNotDriveViewport() {
	const api = {
		context: {
			preloadedPoints: [
				point('zero', 0, 0),
				point('bad-lat', 200, 27.56),
				point('bad-lng', 53.9, 500),
				point('infinity', Infinity, 27.56),
				point('ok', 53.9, 27.56)
			]
		},
		points: () => Promise.resolve([])
	};
	const harness = createHarness(api);
	await wait(20);
	const centers = harness.calls.filter((call) => call[0] === 'setCenter');
	assert.deepStrictEqual(centers[0].slice(1), [53.9, 27.56, 13], 'only valid coordinate should initialize provider');
	assert.deepStrictEqual(centers[1].slice(1), [53.9, 27.56, 15], 'single valid point should be centered by initial fit');
	harness.map.destroy();
}

async function destroyPreventsLateMutation() {
	const pending = deferred();
	const api = {
		context: { carrier: 'cdek', cdek_city_code: '9220' },
		points: () => pending.promise
	};
	const harness = createHarness(api);
	await wait(80);
	harness.map.destroy();
	pending.resolve([point('late', 53.9, 27.56)]);
	await wait(40);
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'renderMarkers').length, 0, 'late async response must not render after destroy');
	assert.strictEqual(Object.keys(harness.element.listeners).every((type) => harness.element.listeners[type].length === 0), true, 'destroy should remove viewport listeners');
}

async function yandexPendingFitCanBeCancelled() {
	let readyCallback = null;
	const setBoundsCalls = [];
	const setCenterCalls = [];
	const fakeMap = {
		geoObjects: {
			add: () => {},
			remove: () => {}
		},
		events: {
			add: () => {},
			remove: () => {}
		},
		container: {
			fitToViewport: () => {}
		},
		getZoom: () => 11,
		getBounds: () => [[53.8, 27.4], [54, 27.7]],
		setCenter: (coords, zoom) => {
			setCenterCalls.push([coords, zoom]);
		},
		setBounds: (bounds, options) => {
			setBoundsCalls.push([bounds, options]);
			return Promise.resolve();
		},
		setZoom: () => {},
		destroy: () => {},
		balloon: {
			close: () => {}
		}
	};
	const sandbox = {
		window: {
			wdcPickupCheckout: {},
			WDCPickupMapProviders: {},
			ymaps: {
				ready: (callback) => {
					readyCallback = callback;
				},
				templateLayoutFactory: {
					createClass: () => function () {}
				},
				Map: function () {
					return fakeMap;
				},
				Clusterer: function () {
					return {
						add: () => {},
						remove: () => {},
						removeAll: () => {}
					};
				},
				Placemark: function () {
					return {
						events: { add: () => {} },
						properties: { set: () => {} },
						balloon: { open: () => {} }
					};
				}
			},
			setTimeout,
			clearTimeout,
			Promise,
			Number,
			Math,
			parseFloat,
			isFinite,
			isNaN,
			console
		},
		document: {
			addEventListener: () => {},
			removeEventListener: () => {},
			createElement: () => ({}),
			head: { appendChild: () => {} }
		},
		setTimeout,
		clearTimeout,
		Promise,
		Number,
		Math,
		parseFloat,
		isFinite,
		isNaN,
		console
	};
	sandbox.window.window = sandbox.window;
	sandbox.window.document = sandbox.document;
	vm.createContext(sandbox);
	vm.runInContext(yandexProviderSource, sandbox);
	const provider = sandbox.window.WDCPickupMapProviders.yandex.create(new FakeElement('yandex'), {
		center: { lat: 55.0302, lng: 82.9204, zoom: 11 },
		onBoundsChange: () => {}
	});
	provider.renderMarkers([point('a', 53.9, 27.56), point('b', 53.91, 27.57)]);
	provider.fitToMarkers({ padding: 32, maxZoom: 14 });
	provider.cancelPendingFit();
	readyCallback();
	await wait(20);
	assert.strictEqual(setBoundsCalls.length, 0, 'cancelled Yandex pending fit must not call setBounds after API readiness');
	assert.strictEqual(setCenterCalls.length, 0, 'cancelled Yandex pending fit must not center after API readiness');
	provider.destroy();
}

async function addressSearchWithoutPointsLoadsNewBounds() {
	let pointRequests = 0;
	let lastBbox = '';
	const api = {
		context: {},
		addressSearch: () => Promise.resolve({
			address: { value: 'Minsk, Independence Ave', lat: 53.9, lng: 27.56 },
			points: []
		}),
		points: (bbox) => {
			pointRequests += 1;
			lastBbox = bbox;
			return Promise.resolve([point('min40', 53.901, 27.561), point('min41', 53.902, 27.562)]);
		}
	};
	const harness = createHarness(api);
	await harness.map.search('Independence Ave');
	await wait(40);
	assert.deepStrictEqual(harness.calls.filter((call) => call[0] === 'setCenter').pop().slice(1), [53.9, 27.56, 15], 'address search must center on found address');
	assert.strictEqual(pointRequests, 1, 'address search without result.points must explicitly load points for new bounds');
	assert.strictEqual(lastBbox, '27.439999999999998,53.78,27.68,54.019999999999996', 'address search points request must use the address bbox');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'renderMarkers').length, 2, 'address search should render current origin marker and loaded points');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'fitToMarkers').length, 0, 'address search response must not broad auto-fit after claim');
	assert.strictEqual(harness.map.selected(), null, 'address search must not auto-select a point');
	harness.map.destroy();
}

async function addressSearchWithPointsRendersImmediately() {
	let pointRequests = 0;
	const api = {
		context: {},
		addressSearch: () => Promise.resolve({
			address: { value: 'Minsk, Independence Ave', lat: 53.9, lng: 27.56 },
			points: [point('min40', 53.901, 27.561), point('min41', 53.902, 27.562)]
		}),
		points: () => {
			pointRequests += 1;
			return Promise.resolve([]);
		}
	};
	const harness = createHarness(api);
	await harness.map.search('Independence Ave');
	await wait(40);
	assert.strictEqual(pointRequests, 0, 'address search with result.points should not need an extra points request');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'renderMarkers').length, 1, 'address search result.points should render immediately');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'fitToMarkers').length, 0, 'address result.points must not override address center with broad fit');
	assert.strictEqual(harness.map.selected(), null, 'address result.points must not auto-select a point');
	harness.map.destroy();
}

async function geolocationResponseDoesNotAutoFit() {
	let pointRequests = 0;
	const api = {
		context: {},
		points: () => {
			pointRequests += 1;
			return Promise.resolve([point('near1', 53.901, 27.561), point('near2', 53.902, 27.562)]);
		}
	};
	const harness = createHarness(api);
	harness.map.useUserLocation(53.9, 27.56);
	await wait(40);
	assert.deepStrictEqual(harness.calls.filter((call) => call[0] === 'setCenter').pop().slice(1), [53.9, 27.56, 15], 'geolocation must center on user location');
	assert.strictEqual(pointRequests, 1, 'geolocation must request points for the user bbox');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'renderMarkers').length >= 1, true, 'geolocation response must render markers');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'fitToMarkers').length, 0, 'geolocation response must not run broad initial auto-fit');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'setCenter').length, 2, 'geolocation response must not center again on a point');
	harness.map.destroy();
}

async function destroyAfterAddressSearchPreventsLatePointsMutation() {
	const pending = deferred();
	const api = {
		context: {},
		addressSearch: () => Promise.resolve({
			address: { value: 'Minsk, Independence Ave', lat: 53.9, lng: 27.56 },
			points: []
		}),
		points: () => pending.promise
	};
	const harness = createHarness(api);
	await harness.map.search('Independence Ave');
	harness.map.destroy();
	pending.resolve([point('late', 53.901, 27.561)]);
	await wait(40);
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'renderMarkers').length, 1, 'late address-search points must not render after destroy');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'fitToMarkers').length, 0, 'late address-search points must not fit after destroy');
}

async function run() {
	await programmaticSuppressionAllowsFirstUserPan();
	await lateAsyncDoesNotAutoFitAfterInteraction();
	await preloadedPointsFitOnceWithoutSelection();
	await emptyThenNonEmptyStillFits();
	await selectedPointBeatsPrefetchCenter();
	await canonicalDestinationBeatsDerivedCenter();
	await derivedCenterStillAllowsFit();
	await invalidCoordinatesDoNotDriveViewport();
	await destroyPreventsLateMutation();
	await yandexPendingFitCanBeCancelled();
	await addressSearchWithoutPointsLoadsNewBounds();
	await addressSearchWithPointsRendersImmediately();
	await geolocationResponseDoesNotAutoFit();
	await destroyAfterAddressSearchPreventsLatePointsMutation();
	console.log('Pickup map lifecycle smoke OK');
}

run().catch((error) => {
	console.error(error);
	process.exit(1);
});
