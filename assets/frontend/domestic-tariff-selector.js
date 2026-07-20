(function ($) {
	'use strict';

	function config() {
		return window.wdcPlatformDomesticTariffs || {};
	}

	function normalizeShippingMethod(value) {
		return String(value || '').replace(/^wdc_platform:/, '').replace(/^wdc_platform_delivery:/, '');
	}

	function activeShippingMethod() {
		var checked = document.querySelector('input[name^="shipping_method"]:checked');
		return checked ? normalizeShippingMethod(checked.value) : '';
	}

	function methodFamily(value) {
		var method = normalizeShippingMethod(value);
		var parts = method.split(':');
		var pickupIndex = parts.indexOf('pickup');
		var courierIndex = parts.indexOf('courier');
		if (pickupIndex > 0) {
			return parts[0] + ':pickup';
		}
		if (courierIndex > 0) {
			return parts[0] + ':courier';
		}
		return method;
	}

	function syncDisabledState() {
		var activeFamily = methodFamily(activeShippingMethod());
		document.querySelectorAll('.wdc-domestic-tariff-selector').forEach(function (wrapper) {
			var groupId = normalizeShippingMethod(wrapper.getAttribute('data-wdc-checkout-group-id') || '');
			var groupFamily = methodFamily(groupId);
			var active = !!activeFamily && !!groupFamily && activeFamily === groupFamily;
			wrapper.classList.toggle('is-inactive', !active);
			wrapper.setAttribute('aria-disabled', active ? 'false' : 'true');
			wrapper.querySelectorAll('input[type="radio"]').forEach(function (input) {
				input.disabled = !active;
			});
		});
	}

	function updatePlannedDeliveryComment(input) {
		var wrapper = input && input.closest ? input.closest('.wdc-platform-rate-meta') : null;
		var comment = input ? String(input.getAttribute('data-planned-delivery-comment') || '') : '';
		var node = wrapper ? wrapper.querySelector('.wdc-platform-planned-delivery-comment') : null;
		if (!node) {
			return;
		}
		node.textContent = comment;
		node.hidden = !comment;
	}

	$(document.body).on('change', '.wdc-domestic-tariff-selector input[type="radio"]', function () {
		var input = $(this);
		if (input.prop('disabled')) {
			return;
		}
		updatePlannedDeliveryComment(this);
		var wrapper = input.closest('.wdc-domestic-tariff-selector');
		var data = config();
		if (!data.ajax_url || !data.action) {
			$(document.body).trigger('update_checkout');
			return;
		}

		$.post(data.ajax_url, {
			action: data.action,
			nonce: data.nonce || '',
			service_key: wrapper.data('wdc-service-key') || '',
			checkout_group_id: wrapper.data('wdc-checkout-group-id') || '',
			delivery_type: wrapper.data('wdc-delivery-type') || '',
			object_code: input.val() || '',
			title: input.data('title') || ''
		}).always(function () {
			$(document.body).trigger('update_checkout');
		});
	});
	$(document.body).on('change', 'input[name^="shipping_method"]', syncDisabledState);
	$(document.body).on('updated_checkout', syncDisabledState);
	$(syncDisabledState);
})(jQuery);
