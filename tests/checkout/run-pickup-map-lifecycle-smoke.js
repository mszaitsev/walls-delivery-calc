const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..', '..');
const source = fs.readFileSync(path.join(root, 'assets/frontend/pickup-map/wdc-pickup-map.js'), 'utf8');
const checkoutSource = fs.readFileSync(path.join(root, 'assets/frontend/pickup-map/wdc-pickup-checkout.js'), 'utf8');
const leafletProviderSource = fs.readFileSync(path.join(root, 'assets/frontend/pickup-map/providers/wdc-map-provider-leaflet.js'), 'utf8');
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
		this.attributes = {};
		this.dataset = {};
		this.children = [];
		this.innerHTMLWrites = 0;
		this._innerHTML = '';
		this.textContent = '';
		this.disabled = false;
		this.hidden = false;
		this.value = '';
		this.checked = false;
		this.style = {
			display: '',
			removeProperty: (key) => {
				if (key === 'display') {
					this.style.display = '';
				}
			}
		};
		this.scrollHeight = 0;
		this.clientHeight = 0;
		this.scrollTop = 0;
		this.classList = {
			values: new Set(),
			contains: (value) => this.classList.values.has(value),
			add: (value) => { this.classList.values.add(value); },
			remove: (value) => { this.classList.values.delete(value); },
			toggle: (value, force) => {
				if (force === undefined ? !this.classList.values.has(value) : !!force) {
					this.classList.values.add(value);
					return true;
				}
				this.classList.values.delete(value);
				return false;
			}
		};
	}

	get innerHTML() {
		return this._innerHTML;
	}

	set innerHTML(value) {
		this.innerHTMLWrites += 1;
		this._innerHTML = String(value || '');
		if (this._innerHTML.indexOf('data-wdc-pickup-loading-text') !== -1 && !this.querySelector('[data-wdc-pickup-loading-text]')) {
			const loadingText = new FakeElement('loading-text');
			loadingText.setAttribute('data-wdc-pickup-loading-text', '');
			this.appendChild(loadingText);
		}
	}

	setAttribute(name, value) {
		this.attributeWrites = this.attributeWrites || [];
		this.attributeWrites.push([name, String(value)]);
		this.attributes[name] = String(value);
		if (name === 'aria-hidden') {
			this.ariaHidden = String(value);
		}
	}

	getAttribute(name) {
		return this.attributes[name] || '';
	}

	addEventListener(type, callback) {
		this.listeners[type] = this.listeners[type] || [];
		this.listeners[type].push(callback);
	}

	appendChild(child) {
		child.parentNode = this;
		this.children.push(child);
		return child;
	}

	remove() {
		if (!this.parentNode || !this.parentNode.children) {
			return;
		}
		this.parentNode.children = this.parentNode.children.filter((item) => item !== this);
		this.parentNode = null;
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

	dispatchEvent(event) {
		this.dispatch(event.type, event.detail);
		return true;
	}

	querySelector(selector) {
		const attrMatch = selector.match(/^\[([^=\]]+)(?:="([^"]*)")?\]$/);
		if (attrMatch) {
			const attr = attrMatch[1];
			return this.children.find((child) => child.attributes && child.attributes[attr] !== undefined) || null;
		}
		if (selector === '[data-wdc-pickup-loading-text]') {
			return this.children.find((child) => child.attributes && child.attributes['data-wdc-pickup-loading-text'] !== undefined) || null;
		}
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
	const listSelectButton = new FakeElement('list-select');
	const listParent = new FakeElement('list-parent');
	const list = new FakeElement('list');
	list.parentNode = listParent;
	listParent.insertBefore = () => {};
	listParent.querySelector = () => listSelectButton;

	const element = new FakeElement('map');
	const mapPane = new FakeElement('map-pane');
	mapPane.appendChild(element);
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
							pendingFit: false,
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
								this.pendingFit = true;
								calls.push(['fitToMarkers', options]);
							},
							cancelPendingFit() {
								this.cancelledFit += 1;
								this.pendingFit = false;
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
			requestAnimationFrame: (callback) => setTimeout(() => callback(Date.now()), 0),
			cancelAnimationFrame: (handle) => clearTimeout(handle),
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
			createElement: () => {
				const created = new FakeElement('created');
				created.querySelector = (selector) => selector === '[data-wdc-pickup-list-confirm]' ? listSelectButton : null;
				return created;
			},
			querySelector: () => null
		},
		setTimeout,
		clearTimeout,
		requestAnimationFrame: (callback) => setTimeout(() => callback(Date.now()), 0),
		cancelAnimationFrame: (handle) => clearTimeout(handle),
		AbortController,
		CustomEvent: class CustomEvent {
			constructor(type, options) {
				this.type = type;
				this.detail = options && options.detail;
			}
		},
		Promise,
		AbortController,
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
	sandbox.window.requestAnimationFrame = sandbox.requestAnimationFrame;
	sandbox.window.cancelAnimationFrame = sandbox.cancelAnimationFrame;
	vm.createContext(sandbox);
	vm.runInContext(source, sandbox);

	const map = sandbox.window.WDCPickupMap.create(element, card, confirm, {
		loading: 'loading',
		empty: 'empty',
		selectPoint: 'select'
	}, api.context || {});

	return { calls, element, mapPane, card, confirm, list, listSelectButton, provider: () => providerInstance, map };
}

function point(id, lat, lng) {
	return { id, point_code: id, address: 'Address ' + id, lat, lng };
}

function largePoints(count) {
	return Array.from({ length: count }, (_, index) => {
		const id = 'p' + String(index + 1).padStart(5, '0');
		return point(id, 55.75 + (index % 97) * 0.001, 37.61 + Math.floor(index / 97) * 0.001);
	});
}

function renderedPointRows(html) {
	return (String(html || '').match(/data-wdc-point-id=/g) || []).length;
}

function ariaBusyTrueCount(element) {
	return (element.attributeWrites || []).filter((write) => write[0] === 'aria-busy' && write[1] === 'true').length;
}

function mapLoader(harness) {
	return harness.mapPane.children.find((child) => child.className === 'wdc-pickup-map__loading') || null;
}

function abortableDeferred(signal) {
	const pending = deferred();
	if (signal && signal.addEventListener) {
		signal.addEventListener('abort', () => pending.reject({ name: 'AbortError' }));
	}
	return pending;
}

async function initialPointsFetchShowsLoaderUntilRender() {
	let pending = null;
	const api = {
		context: { carrier: 'cdek', cdek_city_code: '9220' },
		points: (bbox, signal) => {
			pending = abortableDeferred(signal);
			return pending.promise;
		}
	};
	const harness = createHarness(api);
	await wait(80);
	assert.strictEqual(mapLoader(harness).hidden, false, 'initial points fetch must show the map loading overlay');
	assert.strictEqual(mapLoader(harness).textNode.textContent, 'loading', 'initial loader must use accessible loading text');
	assert.strictEqual(harness.element.getAttribute('aria-busy'), 'true', 'map must be aria-busy while points load');
	assert.strictEqual(harness.list.getAttribute('aria-busy'), 'true', 'list must be aria-busy while points load');
	assert(harness.list.innerHTML.includes('wdc-pickup-list__loading'), 'empty sidebar must show a loading state during initial fetch');
	pending.resolve([point('min40', 53.9, 27.56)]);
	await wait(40);
	assert.strictEqual(mapLoader(harness).hidden, true, 'loader must hide only after successful marker render');
	assert.strictEqual(harness.element.getAttribute('aria-busy'), 'false', 'map aria-busy must clear after render');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'renderMarkers').length, 1, 'success must still render markers');
	harness.map.destroy();
}

async function emptyPointsFetchHidesLoader() {
	let pending = null;
	const api = {
		context: { carrier: 'cdek', cdek_city_code: '9220' },
		points: (bbox, signal) => {
			pending = abortableDeferred(signal);
			return pending.promise;
		}
	};
	const harness = createHarness(api);
	await wait(80);
	pending.resolve([]);
	await wait(40);
	assert.strictEqual(mapLoader(harness).hidden, true, 'empty points response must hide loader');
	assert.strictEqual(harness.card.textContent, 'empty', 'empty response must leave the generic empty text');
	harness.map.destroy();
}

async function pointsFetchErrorHidesLoaderAndShowsError() {
	let pending = null;
	const api = {
		context: { carrier: 'cdek', cdek_city_code: '9220' },
		points: (bbox, signal) => {
			pending = abortableDeferred(signal);
			return pending.promise;
		}
	};
	const harness = createHarness(api);
	await wait(80);
	pending.reject(new Error('network'));
	await wait(40);
	assert.strictEqual(mapLoader(harness).hidden, true, 'request error must hide loader');
	assert.strictEqual(harness.card.textContent, 'Error', 'request error must show the generic error text');
	assert.strictEqual(harness.list.innerHTML.includes('Загружаем'), false, 'request error must not leave stale list loading text');
	harness.map.destroy();
}

async function abortedRequestDoesNotHideNextLoader() {
	const pending = [];
	let requestCount = 0;
	const api = {
		context: { carrier: 'cdek', cdek_city_code: '9220' },
		points: (bbox, signal) => {
			requestCount += 1;
			const next = abortableDeferred(signal);
			pending.push(next);
			return next.promise;
		}
	};
	const harness = createHarness(api);
	await wait(80);
	assert.strictEqual(mapLoader(harness).hidden, false, 'first request must show loader');
	harness.provider().fireBounds('manual-bounds');
	await wait(320);
	assert.strictEqual(requestCount, 2, 'bounds change must start a second request');
	assert.strictEqual(mapLoader(harness).hidden, false, 'aborted first request must not hide loader for the second request');
	pending[1].resolve([point('min41', 53.91, 27.57)]);
	await wait(40);
	assert.strictEqual(mapLoader(harness).hidden, true, 'second request completion must hide loader');
	harness.map.destroy();
}

async function destroyDuringRequestClearsLoader() {
	let pending = null;
	const api = {
		context: { carrier: 'cdek', cdek_city_code: '9220' },
		points: (bbox, signal) => {
			pending = abortableDeferred(signal);
			return pending.promise;
		}
	};
	const harness = createHarness(api);
	await wait(80);
	assert.strictEqual(mapLoader(harness).hidden, false, 'request must show loader before destroy');
	harness.map.destroy();
	assert.strictEqual(mapLoader(harness).hidden, true, 'destroy must clear a stale loader');
	pending.resolve([point('late', 53.9, 27.56)]);
	await wait(40);
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'renderMarkers').length, 0, 'destroyed map must not render late points');
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

async function popupCommitFocusFalseCancelsPendingFit() {
	const selected = point('min40', 53.9, 27.56);
	const api = {
		context: {
			preloadedPoints: [selected, point('min41', 53.91, 27.57)]
		},
		points: () => Promise.resolve([])
	};
	const harness = createHarness(api);
	let selectedEvents = 0;
	harness.confirm.addEventListener('wdc:point-selected', () => {
		selectedEvents += 1;
	});
	await wait(20);
	assert.strictEqual(harness.provider().pendingFit, true, 'preloaded points should create a pending fit in the fake provider');
	harness.provider().popupSelect(selected);
	await wait(20);
	assert.strictEqual(harness.map.selected() && harness.map.selected().id, 'min40', 'popup commit should save selected point');
	assert.strictEqual(selectedEvents, 1, 'popup commit should dispatch selected event');
	assert.strictEqual(harness.provider().cancelledFit > 0, true, 'popup commit with focus=false must cancel pending fit');
	assert.strictEqual(harness.provider().pendingFit, false, 'popup commit should clear fake pending fit');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'focusPoint').length, 0, 'popup commit with focus=false must not focus point');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'fitToMarkers').length, 1, 'popup commit must not apply a second fit');
	harness.map.destroy();
}

async function listCommitFocusFalseCancelsPendingFit() {
	const selected = point('min40', 53.9, 27.56);
	const api = {
		context: {
			preloadedPoints: [selected, point('min41', 53.91, 27.57)]
		},
		points: () => Promise.resolve([])
	};
	const harness = createHarness(api);
	let selectedEvents = 0;
	harness.confirm.addEventListener('wdc:point-selected', () => {
		selectedEvents += 1;
	});
	await wait(20);
	harness.provider().pointClick(selected);
	harness.listSelectButton.dispatch('click');
	await wait(20);
	assert.strictEqual(harness.map.selected() && harness.map.selected().id, 'min40', 'list confirmation should save selected point');
	assert.strictEqual(selectedEvents, 1, 'list confirmation should dispatch selected event');
	assert.strictEqual(harness.provider().cancelledFit > 0, true, 'list confirmation with focus=false must cancel pending fit');
	assert.strictEqual(harness.provider().pendingFit, false, 'list confirmation should clear fake pending fit');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'focusPoint').length, 0, 'list confirmation with focus=false must not focus point');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'fitToMarkers').length, 1, 'list confirmation must not apply a second fit');
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

async function fixedAreaLargeDatasetDoesNotReloadOnViewportChange() {
	let pointRequests = 0;
	const points = largePoints(6403);
	const api = {
		context: {
			carrier: 'carrier_with_fixed_area_dataset',
			reload_on_viewport_change: false
		},
		points: () => {
			pointRequests += 1;
			return Promise.resolve(points);
		}
	};
	const harness = createHarness(api);
	harness.list.clientHeight = 520;
	await wait(120);
	assert.strictEqual(pointRequests, 1, 'fixed-area dataset must perform exactly one initial REST fetch');
	assert.strictEqual(ariaBusyTrueCount(harness.element), 1, 'fixed-area initial load must show the remote loading state once');
	assert.strictEqual(mapLoader(harness).hidden, true, 'fixed-area initial success must hide the loader');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'renderMarkers').length, 1, 'initial fixed-area fetch must render markers once');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'renderMarkers')[0][1].length, 6403, 'cluster source must keep the full fixed-area dataset');
	assert(renderedPointRows(harness.list.innerHTML) <= 80, 'virtualized sidebar must keep DOM rows bounded for 6403 source points');
	const busyAfterInitial = ariaBusyTrueCount(harness.element);
	const listWritesAfterInitial = harness.list.innerHTMLWrites;
	harness.list.scrollTop = 2400;
	harness.provider().fireBounds('37.1,55.1,38.1,56.1');
	harness.provider().fireBounds('37.0,55.0,38.2,56.2');
	harness.provider().fireBounds('36.9,54.9,38.3,56.3');
	harness.provider().fireBounds('36.8,54.8,38.4,56.4');
	await wait(380);
	assert.strictEqual(pointRequests, 1, 'fixed-area viewport changes must not download the same points again');
	assert.strictEqual(ariaBusyTrueCount(harness.element), busyAfterInitial, 'fixed-area viewport changes must not show a fake loading/search state');
	assert.strictEqual(mapLoader(harness).hidden, true, 'fixed-area viewport changes must keep the loader hidden');
	assert.strictEqual(harness.list.innerHTMLWrites, listWritesAfterInitial, 'fixed-area viewport-only changes must not rerender the full sidebar list');
	assert.strictEqual(harness.list.scrollTop, 2400, 'fixed-area viewport-only changes must preserve sidebar scroll position');
	assert.strictEqual(harness.calls.filter((call) => call[0] === 'renderMarkers').length, 1, 'fixed-area viewport changes must not force map-level marker rerender through REST');
	assert(renderedPointRows(harness.list.innerHTML) <= 80, 'virtualized sidebar must remain bounded after viewport changes');
	harness.list.scrollTop = 6402 * 112;
	harness.list.dispatch('scroll');
	await wait(30);
	assert(harness.list.innerHTML.includes('p06403'), 'virtualized sidebar must make the last point reachable by scroll');
	await harness.map.search('Address p06403');
	assert.strictEqual(pointRequests, 1, 'fixed-area local search must use the loaded source array');
	assert(harness.list.innerHTML.includes('p06403'), 'fixed-area local search must find points outside the initially rendered DOM window');
	harness.map.destroy();
}

async function viewportFilteredFixedDatasetUpdatesListWithoutLoader() {
	let pointRequests = 0;
	const inside = point('inside', 55.75, 37.61);
	const outside = point('outside', 56.20, 38.20);
	const api = {
		context: {
			carrier: 'yandex_delivery',
			reload_on_viewport_change: false,
			preloadedPoints: [inside, outside]
		},
		points: () => {
			pointRequests += 1;
			return Promise.resolve([]);
		}
	};
	const harness = createHarness(api);
	harness.list.clientHeight = 520;
	await wait(40);
	const busyAfterInitial = ariaBusyTrueCount(harness.element);
	const writesAfterInitial = harness.list.innerHTMLWrites;
	harness.element.dispatch('pointerdown');
	harness.provider().fireBounds('37.5,55.5,37.8,55.9');
	await wait(40);
	assert.strictEqual(pointRequests, 0, 'viewport-filtered fixed dataset must not run remote points requests for bounds changes');
	assert.strictEqual(ariaBusyTrueCount(harness.element), busyAfterInitial, 'viewport-filtered local bounds changes must not show loading');
	assert(harness.list.innerHTMLWrites > writesAfterInitial, 'viewport-filtered local bounds changes must update the sidebar list');
	assert(harness.list.innerHTML.includes('inside') && !harness.list.innerHTML.includes('outside'), 'viewport-filtered local list must follow the current bbox');
	harness.map.destroy();
}

function createCheckoutContainer(method, family, noticeText, selectedPoint) {
	const notice = new FakeElement('inline-notice');
	notice.textContent = noticeText || '';
	notice.hidden = !noticeText;
	if (noticeText) {
		notice.setAttribute('aria-hidden', 'false');
	} else {
		notice.setAttribute('aria-hidden', 'true');
	}
	const emptyButton = new FakeElement('empty-button');
	emptyButton.setAttribute('data-wdc-pickup-empty-open', '');
	const card = new FakeElement('pickup-card');
	card.setAttribute('data-wdc-pickup-card', '');
	const fields = {
		'[data-wdc-pickup-point-id]': new FakeElement('point-id'),
		'[data-wdc-pickup-point-code]': new FakeElement('point-code'),
		'[data-wdc-pickup-carrier-key]': new FakeElement('carrier-key'),
		'[data-wdc-pickup-service-key]': new FakeElement('service-key'),
		'[data-wdc-pickup-family]': new FakeElement('family'),
		'[data-wdc-pickup-point-type]': new FakeElement('point-type'),
		'[data-wdc-pickup-point-type-label]': new FakeElement('point-type-label'),
		'[data-wdc-pickup-point-title]': new FakeElement('point-title'),
		'[data-wdc-pickup-point-name]': new FakeElement('point-name'),
		'[data-wdc-pickup-point-address]': new FakeElement('point-address'),
		'[data-wdc-pickup-point-postcode]': new FakeElement('point-postcode'),
		'[data-wdc-pickup-city-name]': new FakeElement('city-name'),
		'[data-wdc-pickup-region-name]': new FakeElement('region-name'),
		'[data-wdc-pickup-work-time-field]': new FakeElement('work-time-field'),
		'[data-wdc-pickup-description-field]': new FakeElement('description-field'),
		'[data-wdc-pickup-storage-notice-field]': new FakeElement('storage-notice-field'),
		'[data-wdc-pickup-marker-type]': new FakeElement('marker-type'),
		'[data-wdc-pickup-cdek-code]': new FakeElement('cdek-code'),
		'[data-wdc-pickup-location-id]': new FakeElement('location-id'),
		'[data-wdc-pickup-fias-id]': new FakeElement('fias-id'),
		'[data-wdc-pickup-gar-object-id]': new FakeElement('gar-object-id'),
		'[data-wdc-pickup-destination-fingerprint]': new FakeElement('destination-fingerprint')
	};
	fields['[data-wdc-pickup-family]'].value = family;
	if (selectedPoint) {
		fields['[data-wdc-pickup-point-id]'].value = selectedPoint.id || selectedPoint.point_code || '';
		fields['[data-wdc-pickup-point-code]'].value = selectedPoint.point_code || '';
		fields['[data-wdc-pickup-carrier-key]'].value = selectedPoint.carrier_key || '';
		fields['[data-wdc-pickup-service-key]'].value = selectedPoint.service_key || '';
		fields['[data-wdc-pickup-point-address]'].value = selectedPoint.point_address || selectedPoint.address || '';
	}
	const container = new FakeElement('pickup-container-' + method);
	container.setAttribute('data-shipping-method-id', method);
	container.setAttribute('data-wdc-pickup-checkout', '');
	emptyButton.closest = (selector) => selector === '[data-wdc-pickup-checkout]' ? container : null;
	container.querySelector = (selector) => {
		if (selector === '[data-wdc-pickup-inline-notice]') {
			return notice;
		}
		return fields[selector] || null;
	};
	container.querySelectorAll = (selector) => {
		if (selector === '[data-wdc-pickup-checkout]') {
			return [container];
		}
		if (selector === '[data-wdc-pickup-open]' || selector === '[data-wdc-pickup-empty-open]') {
			return [emptyButton];
		}
		if (selector === '[data-wdc-pickup-card]') {
			return [card];
		}
		if (selector.indexOf('[data-wdc-pickup-point-id]') !== -1) {
			return Object.values(fields);
		}
		if (selector === 'input[name], select[name], textarea[name]') {
			return Object.values(fields);
		}
		return [];
	};
	return { container, notice, emptyButton, card, fields };
}

function createCheckoutNoticeHarness(options) {
	options = options || {};
	const listeners = {};
	const bodyListeners = {};
	const jqueryHandlers = {};
	let containers = [];
	let stateResponse = options.stateResponse;
	let stateCalls = 0;
	let pointCalls = 0;
	const resetCalls = [];
	const mapContexts = [];
	const modalRoots = [];
	const shippingInputs = {
		'pek:pickup': new FakeElement('shipping-pek'),
		yandex_pickup: new FakeElement('shipping-yandex'),
		'ozon_delivery:pickup': new FakeElement('shipping-ozon')
	};
	shippingInputs['pek:pickup'].value = 'pek:pickup';
	shippingInputs['pek:pickup'].checked = true;
	shippingInputs.yandex_pickup.value = 'wdc_platform_delivery:yandex_pickup';
	shippingInputs['ozon_delivery:pickup'].value = 'ozon_delivery:pickup';
	Object.keys(shippingInputs).forEach((key) => {
		shippingInputs[key].matches = (selector) => selector === 'input[name^="shipping_method"]';
	});
	const fields = {
		shipping_country: 'RU',
		wdc_platform_location_id: '153912',
		wdc_platform_location_display_name: 'Москва',
		wdc_platform_location_city_name: 'Москва'
	};
	Object.assign(fields, options.fields || {});
	if (stateResponse === undefined) {
		stateResponse = {
			active_pickup_family: options.activePickupFamily || 'pek:pickup',
			city_context: options.currentContext || options.initialContext || { country_code: 'RU', location_id: '153912', display_name: 'Москва', city_name: 'Москва' },
			pickup_rate_capabilities: options.pickupRateCapabilities || {},
			pickup_selections: {}
		};
	}
	const documentStub = {
		body: new FakeElement('body'),
		addEventListener: (type, callback) => { listeners[type] = listeners[type] || []; listeners[type].push(callback); },
		querySelector: (selector) => {
			if (selector === '[data-wdc-pickup-checkout]') {
				return containers[0] ? containers[0].container : null;
			}
			if (selector === 'input[name^="shipping_method"]:checked') {
				return Object.values(shippingInputs).find((input) => input.checked) || null;
			}
			const nameMatch = selector.match(/^\[name="([^"]+)"\]$/);
			if (nameMatch) {
				const field = new FakeElement(nameMatch[1]);
				field.value = fields[nameMatch[1]] || '';
				return field.value ? field : null;
			}
			return null;
		},
		querySelectorAll: (selector) => {
			if (selector === '[data-wdc-pickup-checkout]') {
				return containers.map((item) => item.container);
			}
			if (selector === 'input[name^="shipping_method"]') {
				return Object.values(shippingInputs);
			}
			return [];
		},
		createElement: () => new FakeElement('created')
	};
	documentStub.body.addEventListener = (type, callback) => { bodyListeners[type] = bodyListeners[type] || []; bodyListeners[type].push(callback); };
	const sandbox = {
		window: {
			wdcPickupCheckout: {
				pickupFamilies: ['pek:pickup', 'yandex_delivery:pickup', 'ozon_delivery:pickup'],
				activePickupFamily: options.activePickupFamily || 'pek:pickup',
				activeShippingMethod: options.activeShippingMethod || 'pek:pickup',
				currentContext: options.currentContext || { country_code: 'RU', location_id: '153912', display_name: 'Москва', city_name: 'Москва' },
				initialContext: options.initialContext || { country_code: 'RU', location_id: '153912', display_name: 'Москва', city_name: 'Москва' },
				pickupRateCapabilities: options.pickupRateCapabilities || {}
			},
			WDCPickupApi: {
				state: () => {
					stateCalls += 1;
					return options.state ? options.state() : Promise.resolve(stateResponse);
				},
				points: (bbox, signal, context) => {
					pointCalls += 1;
					return options.points ? options.points(bbox, signal, context) : Promise.resolve([]);
				},
				searchInitial: (query, signal, context) => options.searchInitial ? options.searchInitial(query, signal, context) : Promise.resolve([]),
				reset: (payload) => { resetCalls.push(payload || {}); return Promise.resolve({ pickup_selections: {} }); }
			},
			WDCPickupModal: {
				create: () => {
					const modalRoot = new FakeElement('modal-root');
					const modalElements = {
						'[data-wdc-confirm]': new FakeElement('modal-confirm'),
						'[data-wdc-search]': new FakeElement('modal-search'),
						'[data-wdc-search-submit]': new FakeElement('modal-search-submit'),
						'[data-wdc-geolocation]': new FakeElement('modal-geolocation'),
						'[data-wdc-map]': new FakeElement('modal-map'),
						'[data-wdc-card]': new FakeElement('modal-card')
					};
					modalRoot.querySelector = (selector) => modalElements[selector] || null;
					modalRoot.querySelectorAll = () => [];
					modalRoots.push(modalRoot);
					return {
						root: modalRoot,
						open: () => {},
						destroy: () => {}
					};
				}
			},
			WDCPickupMap: {
				create: (element, card, confirm, modalLabels, context) => {
					mapContexts.push(Object.assign({}, context || {}));
					return {
						selected: () => null,
						search: () => Promise.resolve([]),
						useUserLocation: () => {},
						destroy: () => {}
					};
				}
			},
			jQuery: (target) => ({
				on: (event, callback) => { jqueryHandlers[event] = callback; },
				trigger: (event) => { if (jqueryHandlers[event]) { jqueryHandlers[event](); } },
				one: (event, callback) => { jqueryHandlers[event] = callback; },
				off: () => {}
			}),
			setTimeout,
			clearTimeout,
			AbortController,
			Event: class Event {
				constructor(type) { this.type = type; }
			}
		},
		document: documentStub,
		setTimeout,
		clearTimeout,
		Date,
		Promise,
		AbortController,
		Number,
		Math,
		parseFloat,
		isFinite,
		isNaN,
		console
	};
	sandbox.window.window = sandbox.window;
	sandbox.window.document = documentStub;
	sandbox.window.Promise = Promise;
	sandbox.window.Date = Date;
	sandbox.window.AbortController = AbortController;
	vm.createContext(sandbox);
	vm.runInContext(checkoutSource, sandbox);
	function setContainers(next) {
		containers = next;
	}
	function dispatchDomReady() {
		(listeners.DOMContentLoaded || []).forEach((callback) => callback());
	}
	function updatedCheckout() {
		if (jqueryHandlers.updated_checkout) {
			jqueryHandlers.updated_checkout();
		}
	}
	function setStateResponse(response) {
		stateResponse = response;
	}
	function changeMethod(method) {
		Object.keys(shippingInputs).forEach((key) => { shippingInputs[key].checked = key === method; });
		(listeners.change || []).forEach((callback) => callback({ target: shippingInputs[method] }));
	}
	function changeDestination(locationId, displayName) {
		fields.wdc_platform_location_id = locationId;
		fields.wdc_platform_location_display_name = displayName;
		fields.wdc_platform_location_city_name = displayName;
		fields.wdc_platform_location_lat = '';
		fields.wdc_platform_location_lng = '';
		(listeners.change || []).forEach((callback) => callback({ target: { matches: (selector) => selector.indexOf('shipping_city') !== -1 } }));
	}
	function open(container) {
		(listeners.click || []).forEach((callback) => callback({
			target: {
				closest: (selector) => selector === '[data-wdc-pickup-open]' ? container.emptyButton : null,
				matches: () => false
			},
			preventDefault: () => {}
		}));
	}
	function apiCounts() {
		return { state: stateCalls, points: pointCalls };
	}
	return { setContainers, dispatchDomReady, updatedCheckout, changeMethod, changeDestination, setStateResponse, resetCalls, sandbox, open, mapContexts, modalRoots, shippingInputs, apiCounts };
}

async function destinationFingerprintChangeResetsLocalSelection() {
	const harness = createCheckoutNoticeHarness();
	const selectedPoint = {
		id: 'msk-a',
		point_code: 'msk-a',
		carrier_key: 'pek',
		service_key: 'pek',
		pickup_family: 'pek:pickup',
		point_address: 'Москва, Тверская',
		address: 'Москва, Тверская',
		country_code: 'RU',
		location_id: '153912',
		destination_fingerprint: 'country=RU|location_id=153912'
	};
	harness.sandbox.window.wdcPickupCheckout.pickupSelections = { 'pek:pickup': selectedPoint };
	harness.sandbox.window.wdcPickupCheckout.selectedPickupPoints = { 'pek:pickup': selectedPoint };
	harness.sandbox.window.wdcPickupCheckout.selectedPickupPoint = selectedPoint;
	harness.setContainers([createCheckoutContainer('pek:pickup', 'pek:pickup', '', selectedPoint)]);
	harness.changeDestination('154954', 'Санкт-Петербург');
	await wait(20);
	assert.strictEqual(harness.resetCalls.length, 1, 'destination fingerprint change must call the generic server reset endpoint once');
	assert.strictEqual(Object.keys(harness.resetCalls[0]).length, 0, 'destination fingerprint change must request a global pickup reset without carrier-specific payload');
	assert.strictEqual(harness.sandbox.window.wdcPickupCheckout.pickupSelections['pek:pickup'], undefined, 'destination fingerprint change must remove the stale local pickup selection');
	assert.strictEqual(harness.sandbox.window.wdcPickupCheckout.selectedPickupPoint, null, 'destination fingerprint change must clear the visible selected pickup card state');

	const stableHarness = createCheckoutNoticeHarness();
	stableHarness.sandbox.window.wdcPickupCheckout.pickupSelections = { 'pek:pickup': selectedPoint };
	stableHarness.sandbox.window.wdcPickupCheckout.selectedPickupPoints = { 'pek:pickup': selectedPoint };
	stableHarness.sandbox.window.wdcPickupCheckout.selectedPickupPoint = selectedPoint;
	stableHarness.setContainers([createCheckoutContainer('pek:pickup', 'pek:pickup', '', selectedPoint)]);
	stableHarness.changeDestination('153912', 'Москва');
	await wait(20);
	assert.strictEqual(stableHarness.resetCalls.length, 0, 'same destination fingerprint must not reset the pickup selection');
	assert.strictEqual(stableHarness.sandbox.window.wdcPickupCheckout.pickupSelections['pek:pickup'].point_code, 'msk-a', 'same destination fingerprint must preserve the local pickup selection');
}

async function pickupRateCapabilitySurvivesCheckoutStateRefresh() {
	const harness = createCheckoutNoticeHarness({
		activePickupFamily: 'ozon_delivery:pickup',
		activeShippingMethod: 'ozon_delivery:pickup',
		pickupRateCapabilities: {
			'ozon_delivery:pickup': { reload_on_viewport_change: false, prefetch_points: false }
		}
	});
	harness.changeMethod('ozon_delivery:pickup');
	const ozon = createCheckoutContainer('ozon_delivery:pickup', 'ozon_delivery:pickup', '');
	harness.setContainers([ozon]);
	harness.open(ozon);
	await wait(20);
	assert.strictEqual(harness.mapContexts.length, 1, 'initial localized pickup rate capability must open the modal once');
	assert.strictEqual(harness.mapContexts[0].reload_on_viewport_change, false, 'initial localized pickup rate capability must reach final map context.');

	harness.setStateResponse({
		active_pickup_family: 'ozon_delivery:pickup',
		city_context: { country_code: 'RU', location_id: '153912', display_name: 'Москва', city_name: 'Москва' },
		pickup_selections: {},
		pickup_rate_capabilities: {
			'ozon_delivery:pickup': { reload_on_viewport_change: false, prefetch_points: false }
		}
	});
	harness.updatedCheckout();
	await wait(30);
	assert.strictEqual(harness.sandbox.window.wdcPickupCheckout.currentContext.location_id, '153912', 'authoritative REST state must update destination context.');
	assert.strictEqual(Object.prototype.hasOwnProperty.call(harness.sandbox.window.wdcPickupCheckout.currentContext, 'reload_on_viewport_change'), false, 'authoritative REST state must keep destination context free of rate capabilities.');
	harness.open(ozon);
	await wait(20);
	assert.strictEqual(harness.mapContexts[1].reload_on_viewport_change, false, 'REST state pickup rate capability must survive ordinary destination context refresh.');

	harness.setStateResponse({
		active_pickup_family: 'ozon_delivery:pickup',
		city_context: { country_code: 'RU', location_id: '154954', display_name: 'Санкт-Петербург', city_name: 'Санкт-Петербург' },
		pickup_selections: {},
		pickupRateCapabilities: {
			'ozon_delivery:pickup': { reload_on_viewport_change: false, prefetch_points: false }
		}
	});
	harness.changeDestination('154954', 'Санкт-Петербург');
	harness.updatedCheckout();
	await wait(30);
	harness.open(ozon);
	await wait(20);
	assert.strictEqual(harness.mapContexts[2].reload_on_viewport_change, false, 'destination change with refreshed Ozon rate must not lose fixed-area capability.');

	harness.setStateResponse({
		active_pickup_family: 'ozon_delivery:pickup',
		city_context: { country_code: 'RU', location_id: '154954', display_name: 'Санкт-Петербург', city_name: 'Санкт-Петербург' },
		pickup_selections: {
			'ozon_delivery:pickup': {
				id: 'spb-a',
				point_code: 'spb-a',
				carrier_key: 'ozon_delivery',
				pickup_family: 'ozon_delivery:pickup',
				point_address: 'Санкт-Петербург',
				destination_fingerprint: 'country=RU|location_id=154954'
			}
		},
		pickup_rate_capabilities: {
			'ozon_delivery:pickup': { reload_on_viewport_change: false, prefetch_points: false }
		}
	});
	harness.updatedCheckout();
	await wait(30);
	harness.open(ozon);
	await wait(20);
	assert.strictEqual(harness.mapContexts[3].reload_on_viewport_change, false, 'authoritative repricing/state refresh after selected pickup must keep fixed-area capability.');

	const defaultHarness = createCheckoutNoticeHarness();
	defaultHarness.changeMethod('pek:pickup');
	const pek = createCheckoutContainer('pek:pickup', 'pek:pickup', '');
	defaultHarness.setContainers([pek]);
	defaultHarness.open(pek);
	await wait(20);
	assert.strictEqual(Object.prototype.hasOwnProperty.call(defaultHarness.mapContexts[0], 'reload_on_viewport_change'), false, 'pickup rate without capability must keep historical map default behavior.');
}

async function prefetchPointsCapabilityControlsBackgroundFetch() {
	const disabledHarness = createCheckoutNoticeHarness({
		activePickupFamily: 'ozon_delivery:pickup',
		activeShippingMethod: 'ozon_delivery:pickup',
		currentContext: { country_code: 'RU', location_id: '153912', display_name: 'Москва', city_name: 'Москва', lat: 55.75, lng: 37.61 },
		initialContext: { country_code: 'RU', location_id: '153912', display_name: 'Москва', city_name: 'Москва', lat: 55.75, lng: 37.61 },
		fields: {
			wdc_platform_location_lat: '55.75',
			wdc_platform_location_lng: '37.61'
		},
		pickupRateCapabilities: {
			'ozon_delivery:pickup': { reload_on_viewport_change: false, prefetch_points: false }
		},
		points: () => Promise.resolve([point('should-not-prefetch', 55.75, 37.61)])
	});
	disabledHarness.changeMethod('ozon_delivery:pickup');
	const ozon = createCheckoutContainer('ozon_delivery:pickup', 'ozon_delivery:pickup', '');
	disabledHarness.setContainers([ozon]);
	disabledHarness.dispatchDomReady();
	await wait(900);
	assert.strictEqual(disabledHarness.apiCounts().points, 0, 'prefetch_points=false must not background-prefetch the fixed-area point dataset.');
	disabledHarness.open(ozon);
	await wait(40);
	assert.strictEqual(disabledHarness.mapContexts[0].reload_on_viewport_change, false, 'fixed-area modal context must keep reload_on_viewport_change=false.');
	assert.strictEqual(disabledHarness.mapContexts[0].prefetch_points, false, 'fixed-area modal context must keep prefetch_points=false.');
	assert.strictEqual(Array.isArray(disabledHarness.mapContexts[0].preloadedPoints), false, 'prefetch_points=false must never inject preloadedPoints into the modal.');

	const enabledHarness = createCheckoutNoticeHarness({
		activePickupFamily: 'pek:pickup',
		activeShippingMethod: 'pek:pickup',
		currentContext: { country_code: 'RU', location_id: '153912', display_name: 'Москва', city_name: 'Москва', lat: 55.75, lng: 37.61 },
		initialContext: { country_code: 'RU', location_id: '153912', display_name: 'Москва', city_name: 'Москва', lat: 55.75, lng: 37.61 },
		fields: {
			wdc_platform_location_lat: '55.75',
			wdc_platform_location_lng: '37.61'
		},
		points: () => Promise.resolve([point('can-prefetch', 55.75, 37.61)])
	});
	const pek = createCheckoutContainer('pek:pickup', 'pek:pickup', '');
	enabledHarness.setContainers([pek]);
	enabledHarness.dispatchDomReady();
	await wait(900);
	assert.strictEqual(enabledHarness.apiCounts().points, 1, 'carrier without prefetch_points=false must keep historical background prefetch behavior.');
}

async function openModalRefreshesAuthoritativeStateBeforeMapCreate() {
	const harness = createCheckoutNoticeHarness({
		activePickupFamily: 'ozon_delivery:pickup',
		activeShippingMethod: 'ozon_delivery:pickup',
		initialContext: { country_code: 'RU', location_id: '153912', display_name: 'Москва', city_name: 'Москва', lat: 55.75, lng: 37.61 },
		currentContext: { country_code: 'RU', location_id: '153912', display_name: 'Москва', city_name: 'Москва', lat: 55.75, lng: 37.61 },
		fields: {
			wdc_platform_location_lat: '55.75',
			wdc_platform_location_lng: '37.61'
		},
		pickupRateCapabilities: {
			'ozon_delivery:pickup': { reload_on_viewport_change: false, prefetch_points: false }
		}
	});
	harness.changeMethod('ozon_delivery:pickup');
	const ozon = createCheckoutContainer('ozon_delivery:pickup', 'ozon_delivery:pickup', '');
	harness.setContainers([ozon]);
	harness.changeDestination('154954', 'Санкт-Петербург');
	harness.setStateResponse({
		active_pickup_family: 'ozon_delivery:pickup',
		city_context: { country_code: 'RU', location_id: '154954', display_name: 'Санкт-Петербург', city_name: 'Санкт-Петербург', lat: 59.93, lng: 30.31 },
		pickup_rate_capabilities: {
			'ozon_delivery:pickup': { reload_on_viewport_change: false, prefetch_points: false }
		}
	});
	harness.open(ozon);
	await wait(30);
	assert.strictEqual(harness.apiCounts().state, 1, 'opening the pickup modal must request authoritative checkout state even when local coordinates exist.');
	assert.strictEqual(harness.mapContexts.length, 1, 'map must be created after the authoritative state promise resolves.');
	assert.strictEqual(harness.mapContexts[0].location_id, '154954', 'map context must use fresh REST destination instead of stale localized destination.');
	assert.strictEqual(harness.mapContexts[0].reload_on_viewport_change, false, 'fresh REST rate capability must be applied before map creation.');
}

async function staleServerStateRetriesBeforeMapCreate() {
	let stateCall = 0;
	const harness = createCheckoutNoticeHarness({
		activePickupFamily: 'ozon_delivery:pickup',
		activeShippingMethod: 'ozon_delivery:pickup',
		currentContext: { country_code: 'RU', location_id: '153912', display_name: 'Москва', city_name: 'Москва', lat: 55.75, lng: 37.61 },
		initialContext: { country_code: 'RU', location_id: '153912', display_name: 'Москва', city_name: 'Москва', lat: 55.75, lng: 37.61 },
		fields: {
			wdc_platform_location_lat: '55.75',
			wdc_platform_location_lng: '37.61'
		},
		pickupRateCapabilities: {
			'ozon_delivery:pickup': { reload_on_viewport_change: false, prefetch_points: false }
		},
		state: () => {
			stateCall += 1;
			return Promise.resolve({
				active_pickup_family: 'ozon_delivery:pickup',
				city_context: stateCall === 1
					? { country_code: 'RU', location_id: '153912', display_name: 'Москва', city_name: 'Москва', lat: 55.75, lng: 37.61 }
					: { country_code: 'RU', location_id: '650000', display_name: 'Новосибирск', city_name: 'Новосибирск', lat: 55.03, lng: 82.92 },
				pickup_rate_capabilities: {
					'ozon_delivery:pickup': { reload_on_viewport_change: false, prefetch_points: false }
				},
				pickup_selections: {}
			});
		}
	});
	harness.changeMethod('ozon_delivery:pickup');
	const ozon = createCheckoutContainer('ozon_delivery:pickup', 'ozon_delivery:pickup', '');
	harness.setContainers([ozon]);
	harness.changeDestination('650000', 'Новосибирск');
	harness.open(ozon);
	await wait(320);
	assert.strictEqual(stateCall, 2, 'stale server state must be retried before map creation.');
	assert.strictEqual(harness.mapContexts.length, 1, 'map must be created only after current destination state is returned.');
	assert.strictEqual(harness.mapContexts[0].location_id, '650000', 'stale server state must not create a previous-city map.');
}

async function stalePrefetchNeverRendersAfterDestinationChange() {
	const moscowPoint = point('msk-old', 55.75, 37.61);
	const harness = createCheckoutNoticeHarness({
		activePickupFamily: 'ozon_delivery:pickup',
		activeShippingMethod: 'ozon_delivery:pickup',
		initialContext: { country_code: 'RU', location_id: '153912', display_name: 'Москва', city_name: 'Москва', lat: 55.75, lng: 37.61 },
		currentContext: { country_code: 'RU', location_id: '153912', display_name: 'Москва', city_name: 'Москва', lat: 55.75, lng: 37.61 },
		fields: {
			wdc_platform_location_lat: '55.75',
			wdc_platform_location_lng: '37.61'
		},
		pickupRateCapabilities: {
			'ozon_delivery:pickup': { reload_on_viewport_change: false }
		},
		points: () => Promise.resolve([moscowPoint])
	});
	harness.changeMethod('ozon_delivery:pickup');
	const ozon = createCheckoutContainer('ozon_delivery:pickup', 'ozon_delivery:pickup', '');
	harness.setContainers([ozon]);
	harness.dispatchDomReady();
	await wait(900);
	assert.strictEqual(harness.apiCounts().points, 1, 'initial prefetch must be allowed for the first destination.');
	harness.changeDestination('650000', 'Новосибирск');
	harness.setStateResponse({
		active_pickup_family: 'ozon_delivery:pickup',
		city_context: { country_code: 'RU', location_id: '650000', display_name: 'Новосибирск', city_name: 'Новосибирск', lat: 55.03, lng: 82.92 },
		pickup_rate_capabilities: {
			'ozon_delivery:pickup': { reload_on_viewport_change: false }
		}
	});
	harness.open(ozon);
	await wait(30);
	assert.strictEqual(harness.mapContexts.length, 1, 'destination-changed modal must still open after fresh state.');
	assert.strictEqual(harness.mapContexts[0].location_id, '650000', 'destination-changed modal must use the new city context.');
	assert.strictEqual(Array.isArray(harness.mapContexts[0].preloadedPoints), false, 'stale previous-city prefetch must not be injected into the new modal.');
}

async function stalePrefetchRaceCannotRepopulateCache() {
	const pendingA = deferred();
	const pendingB = deferred();
	const calls = [];
	const harness = createCheckoutNoticeHarness({
		activePickupFamily: 'ozon_delivery:pickup',
		activeShippingMethod: 'ozon_delivery:pickup',
		initialContext: { country_code: 'RU', location_id: '153912', display_name: 'Москва', city_name: 'Москва', lat: 55.75, lng: 37.61 },
		currentContext: { country_code: 'RU', location_id: '153912', display_name: 'Москва', city_name: 'Москва', lat: 55.75, lng: 37.61 },
		fields: {
			wdc_platform_location_lat: '55.75',
			wdc_platform_location_lng: '37.61'
		},
		pickupRateCapabilities: {
			'ozon_delivery:pickup': { reload_on_viewport_change: false }
		},
		points: (bbox, signal, context) => {
			calls.push(context.location_id);
			return context.location_id === '650000' ? pendingB.promise : pendingA.promise;
		}
	});
	harness.changeMethod('ozon_delivery:pickup');
	const ozon = createCheckoutContainer('ozon_delivery:pickup', 'ozon_delivery:pickup', '');
	harness.setContainers([ozon]);
	harness.dispatchDomReady();
	await wait(900);
	assert.deepStrictEqual(calls, ['153912'], 'prefetch race test must start with Moscow request.');
	harness.changeDestination('650000', 'Новосибирск');
	harness.setStateResponse({
		active_pickup_family: 'ozon_delivery:pickup',
		city_context: { country_code: 'RU', location_id: '650000', display_name: 'Новосибирск', city_name: 'Новосибирск', lat: 55.03, lng: 82.92 },
		pickup_rate_capabilities: {
			'ozon_delivery:pickup': { reload_on_viewport_change: false }
		}
	});
	harness.updatedCheckout();
	await wait(900);
	assert.deepStrictEqual(calls, ['153912', '650000'], 'destination change must start a new prefetch for the new city.');
	pendingA.resolve([point('msk-late', 55.75, 37.61)]);
	await wait(20);
	harness.open(ozon);
	await wait(30);
	assert.strictEqual(Array.isArray(harness.mapContexts[0].preloadedPoints), false, 'late old-city prefetch response must not repopulate cache for the new modal.');
	pendingB.resolve([point('nsk-current', 55.03, 82.92)]);
	await wait(20);
	harness.open(ozon);
	await wait(30);
	assert.strictEqual(harness.mapContexts[1].preloadedPoints[0].id, 'nsk-current', 'current-city prefetch may accelerate the modal after it resolves.');
}

async function checkoutInlineNoticeLatchLifecycle() {
	const message = 'Не удалось рассчитать доставку в выбранный пункт ПЭК. Выберите другой пункт.';
	const harness = createCheckoutNoticeHarness();
	const stalePoint = {
		id: 'bad',
		point_code: 'bad',
		carrier_key: 'pek',
		service_key: 'pek',
		pickup_family: 'pek:pickup',
		point_address: 'Bad terminal',
		address: 'Bad terminal',
		country_code: 'RU',
		location_id: '153912',
		destination_location_id: '153912',
		destination_city_name: 'Москва',
		destination_fingerprint: 'ru|москва|153912'
	};
	harness.sandbox.window.wdcPickupCheckout.pickupSelections = { 'pek:pickup': stalePoint };
	harness.sandbox.window.wdcPickupCheckout.selectedPickupPoints = { 'pek:pickup': stalePoint };
	harness.sandbox.window.wdcPickupCheckout.selectedPickupPoint = stalePoint;
	harness.setStateResponse({ pickup_selections: {} });
	let recovery = createCheckoutContainer('pek:pickup', 'pek:pickup', message);
	harness.setContainers([recovery]);
	harness.updatedCheckout();
	assert.strictEqual(recovery.notice.hidden, false, 'server recovery render must show inline pickup notice');
	assert.strictEqual(recovery.notice.textContent, message, 'server recovery render must keep message text');

	let ordinary = createCheckoutContainer('pek:pickup', 'pek:pickup', '');
	harness.setContainers([ordinary]);
	harness.updatedCheckout();
	assert.strictEqual(ordinary.notice.hidden, false, 'ordinary stabilization update must restore remembered inline notice');
	assert.strictEqual(ordinary.notice.textContent, message, 'ordinary stabilization update must preserve message text');
	assert.strictEqual(harness.sandbox.window.wdcPickupCheckout.pickupSelections['pek:pickup'].point_code, 'bad', 'stale local point exists before authoritative state resolves');
	await wait(20);
	assert.strictEqual(ordinary.notice.hidden, false, 'authoritative empty state must not clear remembered inline notice');
	assert.strictEqual(harness.sandbox.window.wdcPickupCheckout.pickupSelections['pek:pickup'], undefined, 'authoritative empty state must remove stale local pickup selection');
	assert.strictEqual(harness.sandbox.window.wdcPickupCheckout.selectedPickupPoint, null, 'authoritative empty state must clear stale global selected point');
	assert.strictEqual(ordinary.emptyButton.hidden, false, 'authoritative empty state must leave empty pickup chooser visible');

	let third = createCheckoutContainer('pek:pickup', 'pek:pickup', '');
	harness.setContainers([third]);
	harness.updatedCheckout();
	await wait(20);
	assert.strictEqual(third.notice.hidden, false, 'third repeated update must keep remembered inline notice visible');
	assert.strictEqual(third.notice.textContent, message, 'third repeated update must keep remembered message text');

	const selectedPoint = {
		id: 'good',
		point_code: 'good',
		carrier_key: 'pek',
		service_key: 'pek',
		pickup_family: 'pek:pickup',
		point_address: 'Good terminal',
		address: 'Good terminal',
		country_code: 'RU',
		location_id: '153912',
		destination_location_id: '153912',
		destination_city_name: 'Москва',
		destination_fingerprint: 'ru|москва|153912'
	};
	harness.setStateResponse(null);
	harness.sandbox.window.wdcPickupCheckout.pickupSelections['pek:pickup'] = selectedPoint;
	harness.sandbox.window.wdcPickupCheckout.selectedPickupPoints['pek:pickup'] = selectedPoint;
	harness.sandbox.window.wdcPickupCheckout.selectedPickupPoint = selectedPoint;
	let selected = createCheckoutContainer('pek:pickup', 'pek:pickup', '', selectedPoint);
	harness.setContainers([selected]);
	harness.updatedCheckout();
	await wait(20);
	assert.strictEqual(selected.notice.hidden, false, 'POST save optimistic selected point alone must not clear remembered inline notice');

	harness.setStateResponse({ pickup_selections: { 'pek:pickup': selectedPoint } });
	harness.updatedCheckout();
	await wait(20);
	assert.strictEqual(selected.notice.hidden, true, 'authoritative successful selected calculation must clear remembered inline notice');
	assert.strictEqual(selected.notice.textContent, '', 'successful selected calculation must clear message text');

	let afterSelected = createCheckoutContainer('pek:pickup', 'pek:pickup', '');
	harness.setContainers([afterSelected]);
	harness.updatedCheckout();
	await wait(20);
	assert.strictEqual(afterSelected.notice.hidden, true, 'later update after valid selection must not restore old message');

	delete harness.sandbox.window.wdcPickupCheckout.pickupSelections['pek:pickup'];
	delete harness.sandbox.window.wdcPickupCheckout.selectedPickupPoints['pek:pickup'];
	harness.sandbox.window.wdcPickupCheckout.selectedPickupPoint = null;
	harness.setStateResponse({ pickup_selections: {} });
	let invalidAgain = createCheckoutContainer('pek:pickup', 'pek:pickup', message);
	harness.setContainers([invalidAgain]);
	harness.updatedCheckout();
	await wait(20);
	assert.strictEqual(invalidAgain.notice.hidden, false, 'new invalid point recovery must show inline notice again');

	let destinationChanged = createCheckoutContainer('pek:pickup', 'pek:pickup', '');
	harness.setContainers([destinationChanged]);
	harness.changeDestination('154954', 'Санкт-Петербург');
	harness.updatedCheckout();
	await wait(20);
	assert.strictEqual(destinationChanged.notice.hidden, true, 'destination change must clear remembered PEK inline notice');

	let recoveryForMethod = createCheckoutContainer('pek:pickup', 'pek:pickup', message);
	harness.setContainers([recoveryForMethod]);
	harness.updatedCheckout();
	await wait(20);
	assert.strictEqual(recoveryForMethod.notice.hidden, false, 'method-change scenario starts with remembered PEK notice');
	let yandex = createCheckoutContainer('yandex_pickup', 'yandex_delivery:pickup', '');
	harness.setContainers([yandex]);
	harness.changeMethod('yandex_pickup');
	harness.updatedCheckout();
	await wait(20);
	assert.strictEqual(yandex.notice.hidden, true, 'Yandex block must not receive PEK inline notice after method change');
	let backToPek = createCheckoutContainer('pek:pickup', 'pek:pickup', '');
	harness.setContainers([backToPek]);
	harness.changeMethod('pek:pickup');
	harness.updatedCheckout();
	await wait(20);
	assert.strictEqual(backToPek.notice.hidden, true, 'return to PEK without new rejection must not restore cleared notice');

	const freshHarness = createCheckoutNoticeHarness();
	let freshOrdinary = createCheckoutContainer('pek:pickup', 'pek:pickup', '');
	freshHarness.setContainers([freshOrdinary]);
	freshHarness.dispatchDomReady();
	assert.strictEqual(freshOrdinary.notice.hidden, true, 'fresh module initialization after reload must not restore old inline notice');
}

async function run() {
	assert(source.includes('function createLoadingOverlay')
		&& source.includes('wdc-pickup-map__loading')
		&& source.includes('aria-busy')
		&& source.includes('activeLoadingRequestId')
		&& source.includes('endLoading(requestId)')
		&& source.includes('reload_on_viewport_change')
		&& source.includes('function viewportReloadRequired()')
		&& source.includes('function listFollowsViewport()')
		&& source.includes('function renderVirtualList')
		&& source.includes('VIRTUAL_LIST_THRESHOLD')
		&& !source.includes("family === 'ozon_delivery:pickup'")
		&& !source.includes("carrier === 'ozon_delivery'"), 'pickup map loader must be generic, accessible and tied to the current request lifecycle.');
	assert(checkoutSource.includes('pickupRateCapabilities = normalizePickupRateCapabilities')
		&& checkoutSource.includes('function mergePickupRateCapabilitiesFromResponse(response)')
		&& checkoutSource.includes('function withRateCapabilities(context, method)')
		&& checkoutSource.includes('function refreshModalContext(method)')
		&& checkoutSource.includes('refreshCheckoutContextOnce(1200, { returnContext: true, currentFieldsOnly: true })')
		&& checkoutSource.includes('function stateContextMatchesCurrentFields(context)')
		&& checkoutSource.includes('withRateCapabilities(withPrefetch(withCarrierContext(resolvedContext, method), method), method)')
		&& checkoutSource.includes('function prefetchIdentity(context, method)')
		&& checkoutSource.includes('function prefetchIdentityMatches(cached, current)')
		&& checkoutSource.includes('function prefetchPointsAllowed(method)')
		&& checkoutSource.includes('prefetchGeneration++')
		&& !checkoutSource.includes('reload_on_viewport_change: config.reload_on_viewport_change'), 'pickup checkout must keep rate capabilities separate from mutable destination context and apply them when opening the modal.');
	assert(leafletProviderSource.includes("map.on('zoomend', scheduleClusterRebuild)")
		&& leafletProviderSource.includes('function cancelScheduledClusterRebuild()')
		&& leafletProviderSource.includes('requestAnimationFrame')
		&& !leafletProviderSource.includes("map.on('zoomend', rebuildClusters)"), 'Leaflet zoom reclustering must be scheduled and coalesced instead of bound as a synchronous full rebuild.');
	assert(checkoutSource.includes('function hasAuthoritativePickupSelections(response)')
		&& checkoutSource.includes('? extractPickupSelections(response)')
		&& checkoutSource.includes(': mergeSelectedPickupPoints(selectedPickupPoints, extractPickupSelections(response))'), 'checkout state response with explicit pickup selections must replace local selections instead of preserving stale selected points.');
	assert(checkoutSource.includes('window.wdcPickupCheckout.selectedPickupPoint = response.selectedPickupPoint || response.selected_pickup_point || response.pickup_point')
		&& checkoutSource.includes(': null;'), 'checkout state response with selected_pickup_point=null must clear the selected pickup card.');
	assert(checkoutSource.includes('var pickupInlineNotices = {};')
		&& checkoutSource.includes('var authoritativePickupSelections = {};')
		&& checkoutSource.includes('function syncPickupInlineNotices()')
		&& checkoutSource.includes('function clearPickupInlineNotice(family)')
		&& checkoutSource.includes('function reconcilePickupInlineNoticesWithState(state)')
		&& checkoutSource.includes('function removeLocalPickupSelection(family)')
		&& !checkoutSource.includes('hasSuccessfulPickupSelection')
		&& !checkoutSource.includes('localStorage')
		&& !checkoutSource.includes('sessionStorage')
		&& !checkoutSource.includes('document.cookie')
		&& !checkoutSource.includes('setTimeout(function () { clearPickupInlineNotice'), 'checkout rejected pickup inline notices must be page-scoped memory only, with no browser storage or timer auto-hide.');
	assert(checkoutSource.includes("Object.prototype.hasOwnProperty.call(point, 'requires_rate_refresh')")
		&& checkoutSource.includes("Object.prototype.hasOwnProperty.call(snapshot, 'requires_rate_refresh')")
		&& checkoutSource.includes("point.requires_rate_refresh === true")
		&& !checkoutSource.includes("family === 'ozon_delivery:pickup'"), 'pickup checkout refresh after save must use generic requires_rate_refresh capability without an Ozon-specific branch.');
	await checkoutInlineNoticeLatchLifecycle();
	await destinationFingerprintChangeResetsLocalSelection();
	await pickupRateCapabilitySurvivesCheckoutStateRefresh();
	await prefetchPointsCapabilityControlsBackgroundFetch();
	await openModalRefreshesAuthoritativeStateBeforeMapCreate();
	await staleServerStateRetriesBeforeMapCreate();
	await stalePrefetchNeverRendersAfterDestinationChange();
	await stalePrefetchRaceCannotRepopulateCache();
	await initialPointsFetchShowsLoaderUntilRender();
	await emptyPointsFetchHidesLoader();
	await pointsFetchErrorHidesLoaderAndShowsError();
	await abortedRequestDoesNotHideNextLoader();
	await destroyDuringRequestClearsLoader();
	await programmaticSuppressionAllowsFirstUserPan();
	await lateAsyncDoesNotAutoFitAfterInteraction();
	await preloadedPointsFitOnceWithoutSelection();
	await emptyThenNonEmptyStillFits();
	await selectedPointBeatsPrefetchCenter();
	await popupCommitFocusFalseCancelsPendingFit();
	await listCommitFocusFalseCancelsPendingFit();
	await canonicalDestinationBeatsDerivedCenter();
	await derivedCenterStillAllowsFit();
	await invalidCoordinatesDoNotDriveViewport();
	await destroyPreventsLateMutation();
	await yandexPendingFitCanBeCancelled();
	await addressSearchWithoutPointsLoadsNewBounds();
	await addressSearchWithPointsRendersImmediately();
	await geolocationResponseDoesNotAutoFit();
	await destroyAfterAddressSearchPreventsLatePointsMutation();
	await fixedAreaLargeDatasetDoesNotReloadOnViewportChange();
	await viewportFilteredFixedDatasetUpdatesListWithoutLoader();
	console.log('Pickup map lifecycle smoke OK');
}

run().catch((error) => {
	console.error(error);
	process.exit(1);
});
