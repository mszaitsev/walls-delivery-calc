(function (window, document) {
	'use strict';

	function focusable(root) {
		return Array.prototype.slice.call(root.querySelectorAll('button, input, [href], select, textarea, [tabindex]:not([tabindex="-1"])'))
			.filter(function (element) { return !element.disabled && element.offsetParent !== null; });
	}

	function createModal(labels) {
		var previousFocus = document.activeElement;
		var root = document.createElement('div');
		root.className = 'wdc-pickup-modal';
		root.innerHTML = [
			'<div class="wdc-pickup-modal__overlay" data-wdc-close></div>',
			'<div class="wdc-pickup-modal__dialog" role="dialog" aria-modal="true">',
			'<header class="wdc-pickup-modal__header">',
			'<div class="wdc-pickup-search">',
			'<span class="wdc-pickup-search__icon" aria-hidden="true">🔍</span>',
			'<input class="wdc-pickup-modal__search" type="search" data-wdc-search placeholder="' + (labels.searchPlaceholder || '') + '">',
			'<button type="button" class="button wdc-pickup-search__button" data-wdc-search-submit>Искать адрес</button>',
			'</div>',
			'<button type="button" class="wdc-pickup-modal__close" data-wdc-close aria-label="Close">×</button>',
			'</header>',
			'<main class="wdc-pickup-modal__body">',
			'<section class="wdc-pickup-modal__map-pane">',
			'<div class="wdc-pickup-modal__map" data-wdc-map></div>',
			'<button type="button" class="wdc-pickup-map__locate" data-wdc-geolocation title="Определить моё местоположение" aria-label="Определить моё местоположение" hidden><span aria-hidden="true" class="wdc-pickup-map__locate-icon"><svg viewBox="0 0 24 24" focusable="false"><path d="M4 11.4 20.2 3.8 12.6 20l-2.1-7.1L4 11.4Z"></path></svg></span></button>',
			'</section>',
			'<aside class="wdc-pickup-modal__side">',
			'<div class="wdc-pickup-modal__list" data-wdc-list></div>',
			'<div class="wdc-pickup-modal__card" data-wdc-card aria-live="polite">' + (labels.notSelected || '') + '</div>',
			'</aside>',
			'</main>',
			'<footer class="wdc-pickup-modal__footer">',
			'<button type="button" class="button button-primary" data-wdc-confirm disabled>' + (labels.confirm || '') + '</button>',
			'</footer>',
			'</div>'
		].join('');
		document.body.appendChild(root);

		function destroy() {
			document.removeEventListener('keydown', onKeydown);
			root.remove();
			if (previousFocus && previousFocus.focus) {
				previousFocus.focus();
			}
		}

		function onKeydown(event) {
			if (event.key === 'Escape') {
				event.preventDefault();
				root.dispatchEvent(new CustomEvent('wdc:close'));
				return;
			}
			if (event.key !== 'Tab') {
				return;
			}
			var items = focusable(root);
			if (!items.length) {
				return;
			}
			var first = items[0];
			var last = items[items.length - 1];
			if (event.shiftKey && document.activeElement === first) {
				event.preventDefault();
				last.focus();
			} else if (!event.shiftKey && document.activeElement === last) {
				event.preventDefault();
				first.focus();
			}
		}

		root.addEventListener('click', function (event) {
			if (event.target.closest('[data-wdc-close]')) {
				root.dispatchEvent(new CustomEvent('wdc:close'));
			}
		});
		document.addEventListener('keydown', onKeydown);
		setTimeout(function () {
			var close = root.querySelector('button[data-wdc-close]');
			if (close && close.focus) {
				close.focus();
			}
		}, 0);

		return { root: root, destroy: destroy };
	}

	window.WDCPickupModal = { create: createModal };
})(window, document);
