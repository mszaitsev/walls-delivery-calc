( function () {
	'use strict';

	const config = window.wdcOrderDeliveryRecalculation || {};
	const activeRequests = new WeakSet();

	function closestBox( element ) {
		return element ? element.closest( '[data-wdc-order-delivery-recalculation]' ) : null;
	}

	function modal( box ) {
		return box ? box.querySelector( '[data-wdc-order-delivery-modal]' ) : null;
	}

	function modalDialog( box ) {
		const node = modal( box );
		return node ? node.querySelector( '.wdc-order-delivery-modal__dialog' ) : null;
	}

	function modalContent( box ) {
		const node = modal( box );
		return node ? node.querySelector( '[data-wdc-order-delivery-modal-content]' ) : null;
	}

	function setStatus( box, message, type ) {
		const node = modal( box );
		const status = node && node.querySelector( '[data-wdc-order-delivery-modal-status]' );
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

	function openModal( box ) {
		const node = modal( box );
		if ( ! node ) {
			return;
		}
		node.hidden = false;
		document.body.classList.add( 'wdc-order-delivery-modal-open' );
		window.setTimeout( function () {
			const close = node.querySelector( '[data-wdc-order-delivery-modal-close]' );
			if ( close && close.focus ) {
				close.focus();
				return;
			}
			const dialog = modalDialog( box );
			if ( dialog && dialog.focus ) {
				dialog.focus();
			}
		}, 0 );
	}

	function closeModal( box ) {
		const node = modal( box );
		if ( ! node ) {
			return;
		}
		node.hidden = true;
		if ( ! document.querySelector( '[data-wdc-order-delivery-modal]:not([hidden])' ) ) {
			document.body.classList.remove( 'wdc-order-delivery-modal-open' );
		}
	}

	function resetModal( box ) {
		const content = modalContent( box );
		if ( content ) {
			content.innerHTML = '';
		}
		clearPickupPlaceholders( box );
	}

	function renderPreview( box, html ) {
		const content = modalContent( box );
		if ( ! content ) {
			return;
		}
		content.innerHTML = html || '';
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
		if ( ! box || ! orderId || activeRequests.has( box ) ) {
			return;
		}

		const form = new FormData();
		form.append( 'action', config.action || 'wdc_order_delivery_recalculate_preview' );
		form.append( 'nonce', config.nonce || '' );
		form.append( 'order_id', orderId );

		activeRequests.add( box );
		openModal( box );
		resetModal( box );
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
				activeRequests.delete( box );
				setLoading( button, false );
			} );
	}

	document.addEventListener( 'click', function ( event ) {
		const openButton = event.target && event.target.closest( '[data-wdc-order-delivery-recalculate]' );
		if ( openButton ) {
			event.preventDefault();
			requestPreview( openButton );
			return;
		}

		const closeButton = event.target && event.target.closest( '[data-wdc-order-delivery-modal-close]' );
		if ( closeButton ) {
			event.preventDefault();
			const box = closestBox( closeButton );
			if ( box ) {
				closeModal( box );
			}
		}
	} );

	document.addEventListener( 'change', function ( event ) {
		const input = event.target;
		if ( ! input || 'wdc_order_delivery_preview_rate' !== input.name ) {
			return;
		}
		selectedRateChanged( input );
	} );

	document.addEventListener( 'keydown', function ( event ) {
		if ( 'Escape' !== event.key ) {
			return;
		}
		document.querySelectorAll( '[data-wdc-order-delivery-modal]:not([hidden])' ).forEach( function ( node ) {
			const box = closestBox( node );
			if ( box ) {
				closeModal( box );
			}
		} );
	} );
} )();
