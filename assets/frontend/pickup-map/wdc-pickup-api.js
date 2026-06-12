(function (window) {
	'use strict';

	function request(path, options) {
		var config = window.wdcPickupCheckout || {};
		var headers = Object.assign({ 'Content-Type': 'application/json' }, options && options.headers ? options.headers : {});
		if (config.nonce) {
			headers['X-WP-Nonce'] = config.nonce;
		}
		return fetch((config.restUrl || '/wp-json/wdc/v1/') + path.replace(/^\/+/, ''), Object.assign({}, options || {}, { headers: headers }))
			.then(function (response) {
				if (!response.ok) {
					throw new Error('HTTP ' + response.status);
				}
				return response.json();
			});
	}

	window.WDCPickupApi = {
		points: function (bbox, signal, context) {
			var params = contextParams(context);
			params.set('limit', '500');
			if (bbox) {
				params.set('bbox', bbox);
			}
			return request('points?' + params.toString(), { signal: signal }).then(normalizePoints);
		},
		search: function (query, signal, context) {
			var params = contextParams(context);
			params.set('limit', '25');
			params.set('q', query || '');
			return request('points/search?' + params.toString(), { signal: signal }).then(normalizePoints);
		},
		searchInitial: function (query, signal, context) {
			var params = contextParams(context);
			params.set('limit', '10');
			params.set('q', query || '');
			return request('points/search?' + params.toString(), { signal: signal }).then(normalizePoints);
		},
		addressSearch: function (query, context, signal) {
			context = context || {};
			var params = new URLSearchParams();
			params.set('carrier', context.carrier || context.carrier_key || currentCarrier());
			params.set('query', query);
			if (context.location_id) {
				params.set('location_id', context.location_id);
			}
			if (context.country_code) {
				params.set('country_code', context.country_code);
			}
			return request('points/address-search?' + params.toString(), { signal: signal });
		},
		save: function (pointId, shippingMethodId, point) {
			var payload = { point_id: typeof pointId === 'object' ? (pointId.id || '') : pointId, shipping_method_id: shippingMethodId };
			if (typeof pointId === 'object') {
				point = pointId;
			}
			if (point && typeof point === 'object') {
				payload.point = point;
				payload.point_code = point.point_code || '';
				payload.carrier = point.carrier_key || point.carrier || currentCarrier();
			} else {
				payload.carrier = currentCarrier();
			}
			return request('checkout/pickup-point', {
				method: 'POST',
				body: JSON.stringify(payload)
			});
		},
		resolveLocation: function (point, checkoutContext) {
			return request('checkout/pickup-point/resolve-location', {
				method: 'POST',
				body: JSON.stringify({ point: point || {}, checkout_context: checkoutContext || {} })
			});
		},
		reset: function (payload) {
			var params = new URLSearchParams();
			payload = payload || {};
			if (payload.pickup_family) {
				params.set('pickup_family', payload.pickup_family);
			}
			if (payload.shipping_method_id) {
				params.set('shipping_method_id', payload.shipping_method_id);
			}
			var path = 'checkout/pickup-point' + (params.toString() ? '?' + params.toString() : '');
			return request(path, { method: 'DELETE' });
		},
		state: function () {
			return request('checkout/state');
		}
	};

	function normalizePoints(data) {
		return Array.isArray(data) ? data : [];
	}

	function currentCarrier() {
		var config = window.wdcPickupCheckout || {};
		return config.carrier || 'russian_post';
	}

	function contextParams(context) {
		context = context || {};
		var params = new URLSearchParams();
		params.set('carrier', context.carrier || context.carrier_key || currentCarrier());
		[
			'city_code',
			'cdek_city_code',
			'country_code',
			'region_name',
			'state_value',
			'city_name',
			'city_value',
			'settlement_name',
			'place_name',
			'display_name',
			'postal_code',
			'postcode',
			'fias_id',
			'city_fias_id',
			'gar_id',
			'gar_object_id',
			'location_id'
		].forEach(function (key) {
			if (context[key]) {
				params.set(key, context[key]);
			}
		});
		return params;
	}
})(window);
