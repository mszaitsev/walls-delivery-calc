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
		points: function (bbox, signal) {
			return request('points?carrier=russian_post&limit=500&bbox=' + encodeURIComponent(bbox), { signal: signal }).then(normalizePoints);
		},
		search: function (query, signal) {
			return request('points/search?carrier=russian_post&limit=25&q=' + encodeURIComponent(query), { signal: signal }).then(normalizePoints);
		},
		searchInitial: function (query, signal) {
			return request('points/search?carrier=russian_post&limit=10&q=' + encodeURIComponent(query), { signal: signal }).then(normalizePoints);
		},
		addressSearch: function (query, context, signal) {
			context = context || {};
			var params = new URLSearchParams();
			params.set('carrier', 'russian_post');
			params.set('query', query);
			if (context.location_id) {
				params.set('location_id', context.location_id);
			}
			if (context.country_code) {
				params.set('country_code', context.country_code);
			}
			return request('points/address-search?' + params.toString(), { signal: signal });
		},
		save: function (pointId, shippingMethodId) {
			return request('checkout/pickup-point', {
				method: 'POST',
				body: JSON.stringify({ point_id: pointId, shipping_method_id: shippingMethodId })
			});
		},
		resolveLocation: function (point, checkoutContext) {
			return request('checkout/pickup-point/resolve-location', {
				method: 'POST',
				body: JSON.stringify({ point: point || {}, checkout_context: checkoutContext || {} })
			});
		},
		reset: function () {
			return request('checkout/pickup-point', { method: 'DELETE' });
		},
		state: function () {
			return request('checkout/state');
		}
	};

	function normalizePoints(data) {
		return Array.isArray(data) ? data : [];
	}
})(window);
