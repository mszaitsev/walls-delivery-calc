( function () {
	'use strict';

	const config = window.wdcOrderDeliveryRecalculation || {};

	function closestBox( element ) {
		return element ? element.closest( '[data-wdc-order-delivery-recalculation]' ) : null;
	}

	function setStatus( box, message, type ) {
		const status = box && box.querySelector( '[data-wdc-order-delivery-preview-status]' );
		if ( ! status ) {
			return;
		}
		status.textContent = message || '';
		status.dataset.status = type || '';
	}

	function setLoading( button, loading ) {
		if ( ! button ) {
			return;
		}
		button.disabled = !! loading;
		button.dataset.originalText = button.dataset.originalText || button.textContent;
		button.textContent = loading ? 'Расчет...' : button.dataset.originalText;
	}

	function renderPreview( box, html ) {
		const preview = box.querySelector( '[data-wdc-order-delivery-preview]' );
		const content = box.querySelector( '[data-wdc-order-delivery-preview-content]' );
		if ( ! preview || ! content ) {
			return;
		}
		content.innerHTML = html || '';
		preview.hidden = false;
		clearPickupPlaceholders( box );
	}

	function clearPickupPlaceholders( root ) {
		root.querySelectorAll( '[data-wdc-pickup-placeholder]' ).forEach( function ( node ) {
			node.hidden = true;
		} );
	}

	function selectedRateChanged( input ) {
		const box = closestBox( input );
		if ( ! box ) {
			return;
		}
		clearPickupPlaceholders( box );
		const rate = input.closest( '[data-wdc-order-delivery-rate]' );
		if ( ! rate || '1' !== String( rate.dataset.requiresPickup || '' ) ) {
			return;
		}
		const placeholder = rate.querySelector( '[data-wdc-pickup-placeholder]' );
		if ( placeholder ) {
			placeholder.hidden = false;
		}
	}

	function requestPreview( button ) {
		const box = closestBox( button );
		const orderId = button ? String( button.dataset.orderId || '' ) : '';
		if ( ! box || ! orderId ) {
			return;
		}

		const form = new FormData();
		form.append( 'action', config.action || 'wdc_order_delivery_recalculate_preview' );
		form.append( 'nonce', config.nonce || '' );
		form.append( 'order_id', orderId );

		const preview = box.querySelector( '[data-wdc-order-delivery-preview]' );
		if ( preview ) {
			preview.hidden = false;
		}
		setStatus( box, 'Считаем доступные варианты доставки...', 'loading' );
		setLoading( button, true );

		window.fetch( config.ajaxUrl || window.ajaxurl || '', {
			method: 'POST',
			credentials: 'same-origin',
			body: form
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( payload ) {
				if ( ! payload || ! payload.success ) {
					throw new Error( payload && payload.data && payload.data.message ? payload.data.message : 'Не удалось пересчитать доставку.' );
				}
				renderPreview( box, payload.data && payload.data.html ? payload.data.html : '' );
				setStatus( box, 'Preview рассчитан. Сохранение доставки будет добавлено следующим шагом.', 'success' );
			} )
			.catch( function ( error ) {
				setStatus( box, error && error.message ? error.message : 'Не удалось пересчитать доставку.', 'error' );
			} )
			.finally( function () {
				setLoading( button, false );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		const button = event.target && event.target.closest( '[data-wdc-order-delivery-recalculate]' );
		if ( ! button ) {
			return;
		}
		event.preventDefault();
		requestPreview( button );
	} );

	document.addEventListener( 'change', function ( event ) {
		const input = event.target;
		if ( ! input || 'wdc_order_delivery_preview_rate' !== input.name ) {
			return;
		}
		selectedRateChanged( input );
	} );
} )();
