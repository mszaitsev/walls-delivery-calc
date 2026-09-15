(function ($) {
	'use strict';

	var FIELD_NAMES = [
		'billing_address_1',
		'shipping_address_1',
		'billing_postcode',
		'shipping_postcode',
		'billing_city',
		'shipping_city'
	];

	function field(id) {
		return document.getElementById(id);
	}

	function value(id) {
		var element = field(id);
		return element ? String(element.value || '').trim() : '';
	}

	function isVisible(element) {
		return !!element && !!(element.offsetWidth || element.offsetHeight || element.getClientRects().length);
	}

	function activePrefix(summary) {
		var shippingAddress = field('shipping_address_1');
		var shipToDifferent = field('ship_to_different_address');
		var billingAddress = field('billing_address_1');
		if (shipToDifferent && shipToDifferent.checked) {
			return 'shipping';
		}
		if (!billingAddress && shippingAddress) {
			return 'shipping';
		}

		return 'billing';
	}

	function addressParts(prefix) {
		return [
			value(prefix + '_postcode'),
			value(prefix + '_city'),
			value(prefix + '_address_1')
		].filter(function (part) {
			return part !== '';
		});
	}

	function selectedMethodItem() {
		var checked = document.querySelector('input[name^="shipping_method"]:checked');
		return checked ? checked.closest('li') : null;
	}

	function selectedCourierSummaries() {
		var item = selectedMethodItem();
		if (!item) {
			return [];
		}

		return Array.prototype.slice.call(item.querySelectorAll('[data-wdc-courier-address-summary]'));
	}

	function updateSummary(summary) {
		var prefix = activePrefix(summary);
		var addressField = prefix + '_address_1';
		var address1 = value(addressField);
		var hasAddress1 = address1 !== '';
		var address = addressParts(prefix).join(', ');
		var valueNode = summary.querySelector('.wdc-courier-address-summary__value');
		var warningNode = summary.querySelector('.wdc-courier-address-summary__warning');
		var warningLink = warningNode ? warningNode.querySelector('a') : null;

		summary.setAttribute('data-address-field', addressField);
		if (warningLink) {
			warningLink.setAttribute('href', '#' + addressField);
		}
		if (valueNode) {
			valueNode.textContent = hasAddress1 ? address : '';
			valueNode.hidden = !hasAddress1;
		}
		if (warningNode) {
			warningNode.hidden = hasAddress1;
		}
	}

	function updateRequired() {
		var summaries = selectedCourierSummaries();
		var courierSelected = summaries.length > 0;
		var prefix = courierSelected ? activePrefix(summaries[0]) : 'billing';

		['billing', 'shipping'].forEach(function (name) {
			var input = field(name + '_address_1');
			if (!input) {
				return;
			}
			var required = courierSelected && name === prefix;
			input.required = required;
			input.setAttribute('aria-required', required ? 'true' : 'false');
			updateRequiredMarker(input, required);
		});
	}

	function updateRequiredMarker(input, required) {
		var label = document.querySelector('label[for="' + input.id + '"]');
		if (!label) {
			var wrapper = input.closest('.form-row');
			label = wrapper ? wrapper.querySelector('label') : null;
		}
		if (!label) {
			return;
		}

		var marker = label.querySelector('.required');
		if (required && !marker) {
			marker = document.createElement('abbr');
			marker.className = 'required';
			marker.setAttribute('title', 'required');
			marker.setAttribute('data-wdc-added', 'true');
			marker.textContent = '*';
			label.appendChild(document.createTextNode(' '));
			label.appendChild(marker);
		} else if (!required && marker && marker.getAttribute('data-wdc-added') === 'true') {
			marker.remove();
		}
	}

	function updateVisibility() {
		var selectedItem = selectedMethodItem();
		document.querySelectorAll('[data-wdc-courier-address-summary]').forEach(function (summary) {
			var visible = !!selectedItem && selectedItem.contains(summary);
			summary.hidden = !visible;
			if (visible) {
				updateSummary(summary);
			}
		});
		updateRequired();
	}

	function focusFromAnchor(event) {
		var link = event.target.closest('.wdc-courier-address-summary__warning a');
		if (!link) {
			return;
		}
		var target = document.getElementById((link.getAttribute('href') || '').replace(/^#/, ''));
		if (target) {
			window.setTimeout(function () {
				target.focus();
			}, 0);
		}
	}

	function bind() {
		$(document.body)
			.off('input.wdcCourierAddress change.wdcCourierAddress')
			.on('input.wdcCourierAddress change.wdcCourierAddress', FIELD_NAMES.map(function (name) {
				return '#' + name;
			}).join(','), updateVisibility)
			.on('change.wdcCourierAddress', 'input[name^="shipping_method"], #ship_to_different_address', updateVisibility)
			.off('updated_checkout.wdcCourierAddress')
			.on('updated_checkout.wdcCourierAddress', updateVisibility);

		document.removeEventListener('click', focusFromAnchor);
		document.addEventListener('click', focusFromAnchor);
		updateVisibility();
	}

	window.wdcCourierAddressSummary = {
		update: updateVisibility,
		addressParts: addressParts
	};

	$(bind);
})(jQuery);
