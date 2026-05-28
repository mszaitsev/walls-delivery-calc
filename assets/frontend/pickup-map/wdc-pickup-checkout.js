(function (window, document) {
	'use strict';

	var labels = (window.wdcPickupCheckout && window.wdcPickupCheckout.labels) || {};
	var activeMethod = '';

	function init(container) {
		if (container.dataset.wdcPickupReady) {
			return;
		}
		container.dataset.wdcPickupReady = '1';
		var method = container.getAttribute('data-shipping-method-id') || (window.wdcPickupCheckout && window.wdcPickupCheckout.shippingMethodId) || '';
		var openButton = container.querySelector('[data-wdc-pickup-open]');
		activeMethod = currentShippingMethod() || method;
		toggleForMethod(container);
		if (openButton) {
			openButton.addEventListener('click', function () { openModal(container, activeMethod || method); });
		}
	}

	function openModal(container, method) {
		var modal = window.WDCPickupModal.create(labels);
		var confirmButton = modal.root.querySelector('[data-wdc-confirm]');
		var search = modal.root.querySelector('[data-wdc-search]');
		var map = window.WDCPickupMap.create(modal.root.querySelector('[data-wdc-map]'), modal.root.querySelector('[data-wdc-card]'), confirmButton, labels, initialContext());

		function close() {
			map.destroy();
			modal.destroy();
		}

		modal.root.addEventListener('wdc:close', close);
		search.addEventListener('change', function () {
			if (search.value.trim()) {
				map.search(search.value.trim());
			}
		});
		confirmButton.addEventListener('click', function () {
			var point = map.selected();
			if (!point) {
				return;
			}
			confirmButton.disabled = true;
			window.WDCPickupApi.save(point.id, method).then(function (response) {
				applySelection(container, response.pickup_point || {});
				close();
				triggerCheckoutUpdate();
			}).catch(function () {
				confirmButton.disabled = false;
			});
		});
	}

	function applySelection(container, point) {
		var snapshot = point.snapshot || {};
		container.querySelector('[data-wdc-pickup-point-id]').value = point.id || '';
		container.querySelector('[data-wdc-pickup-point-code]').value = point.point_code || '';
		container.querySelector('[data-wdc-pickup-address]').textContent = point.address || '';
		container.querySelector('[data-wdc-pickup-postcode]').textContent = point.postcode || '';
		container.querySelector('[data-wdc-pickup-work-time]').textContent = snapshot.work_time || '';
		container.querySelector('[data-wdc-pickup-selection]').hidden = !point.point_code;
		container.querySelector('[data-wdc-pickup-open]').textContent = point.point_code ? labels.change : labels.choose;
	}

	function resetSelection() {
		window.WDCPickupApi.reset().catch(function () {});
		document.querySelectorAll('[data-wdc-pickup-checkout]').forEach(function (container) {
			applySelection(container, {});
		});
	}

	function currentShippingMethod() {
		var checked = document.querySelector('input[name^="shipping_method"]:checked');
		return checked ? checked.value.replace(/^wdc_platform:/, '') : '';
	}

	function initialContext() {
		var config = (window.wdcPickupCheckout && window.wdcPickupCheckout.initialContext) || {};
		var context = {
			lat: config.lat || '',
			lng: config.lng || '',
			query: config.query || ''
		};
		var fieldContext = contextFromFields();
		return {
			lat: context.lat || fieldContext.lat,
			lng: context.lng || fieldContext.lng,
			query: fieldContext.query || context.query
		};
	}

	function contextFromFields() {
		var country = fieldValue('shipping_country') || fieldValue('billing_country');
		if (country && country.toUpperCase() !== 'RU') {
			return {};
		}
		var postcode = fieldValue('shipping_postcode') || fieldValue('billing_postcode');
		var city = fieldValue('shipping_city') || fieldValue('billing_city');
		var query = [postcode, city].filter(Boolean).join(' ').trim();
		return query ? { query: query } : {};
	}

	function fieldValue(name) {
		var field = document.querySelector('[name="' + name + '"]');
		return field ? String(field.value || '').trim() : '';
	}

	function toggleForMethod(container) {
		var method = currentShippingMethod();
		activeMethod = method || activeMethod;
		var visible = !method || method.indexOf('russian_post_domestic_pickup') === 0;
		container.hidden = !visible;
	}

	function triggerCheckoutUpdate() {
		if (window.jQuery) {
			window.jQuery(document.body).trigger('update_checkout');
		}
	}

	function boot() {
		document.querySelectorAll('[data-wdc-pickup-checkout]').forEach(init);
	}

	document.addEventListener('change', function (event) {
		if (event.target.matches('#billing_city, #shipping_city, #billing_country, #shipping_country, #billing_postcode, #shipping_postcode, [name="billing_city"], [name="shipping_city"], [name="billing_country"], [name="shipping_country"], [name="billing_postcode"], [name="shipping_postcode"]')) {
			resetSelection();
			document.querySelectorAll('[data-wdc-pickup-checkout]').forEach(toggleForMethod);
			return;
		}
		if (event.target.matches('input[name^="shipping_method"]')) {
			document.querySelectorAll('[data-wdc-pickup-checkout]').forEach(toggleForMethod);
		}
	});
	document.addEventListener('DOMContentLoaded', boot);
	if (window.jQuery) {
		window.jQuery(document.body).on('updated_checkout', boot);
	}
})(window, document);
