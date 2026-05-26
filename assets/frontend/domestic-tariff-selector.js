(function ($) {
	'use strict';

	function config() {
		return window.wdcPlatformDomesticTariffs || {};
	}

	$(document.body).on('change', '.wdc-domestic-tariff-selector input[type="radio"]', function () {
		var input = $(this);
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
			object_code: input.val() || '',
			title: input.data('title') || ''
		}).always(function () {
			$(document.body).trigger('update_checkout');
		});
	});
})(jQuery);
