<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Core\PluginEnvironment;

defined( 'ABSPATH' ) || exit;

final class PickupMapCheckout {
	public function __construct(
		private CheckoutSessionManager $session_manager,
		private PluginEnvironment $environment
	) {
	}

	public function register(): void {
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 30 );
	}

	public function enqueue_assets(): void {
		if ( ! function_exists( 'wp_enqueue_script' ) || ! function_exists( 'wp_enqueue_style' ) ) {
			return;
		}
		if ( function_exists( 'is_checkout' ) && ! is_checkout() ) {
			return;
		}
		if ( ! $this->has_domestic_pickup_rate() ) {
			return;
		}

		$base = $this->environment->plugin_url();
		$version = $this->environment->version();
		wp_enqueue_style( 'wdc-leaflet', $base . 'assets/frontend/pickup-map/leaflet/leaflet.css', array(), '1.9.4' );
		wp_enqueue_script( 'wdc-leaflet', $base . 'assets/frontend/pickup-map/leaflet/leaflet.js', array(), '1.9.4', true );
		wp_enqueue_style( 'wdc-pickup-map', $base . 'assets/frontend/pickup-map/wdc-pickup-map.css', array( 'wdc-leaflet' ), $version );
		wp_enqueue_script( 'wdc-pickup-api', $base . 'assets/frontend/pickup-map/wdc-pickup-api.js', array(), $version, true );
		wp_enqueue_script( 'wdc-pickup-modal', $base . 'assets/frontend/pickup-map/wdc-pickup-modal.js', array(), $version, true );
		wp_enqueue_script( 'wdc-pickup-map', $base . 'assets/frontend/pickup-map/wdc-pickup-map.js', array( 'wdc-leaflet', 'wdc-pickup-api' ), $version, true );
		wp_enqueue_script( 'wdc-pickup-checkout', $base . 'assets/frontend/pickup-map/wdc-pickup-checkout.js', array( 'wdc-pickup-api', 'wdc-pickup-modal', 'wdc-pickup-map' ), $version, true );

		if ( function_exists( 'wp_localize_script' ) ) {
			wp_localize_script(
				'wdc-pickup-checkout',
				'wdcPickupCheckout',
				array(
					'restUrl' => function_exists( 'rest_url' ) ? rest_url( 'wdc/v1/' ) : '/wp-json/wdc/v1/',
					'nonce' => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '',
					'carrier' => 'russian_post',
					'shippingMethodId' => RussianPostDomesticSettings::PICKUP_SERVICE_KEY,
					'labels' => array(
						'choose' => 'Выбрать пункт выдачи',
						'change' => 'Изменить пункт выдачи',
						'confirm' => 'Выбрать этот пункт',
						'searchPlaceholder' => 'Адрес или индекс',
						'empty' => 'Переместите карту или воспользуйтесь поиском.',
						'loading' => 'Загрузка пунктов выдачи...',
						'notSelected' => 'Пункт выдачи не выбран.',
						'error' => 'Не удалось загрузить пункты выдачи.',
					),
				)
			);
		}
	}

	private function has_domestic_pickup_rate(): bool {
		foreach ( $this->session_manager->rates() as $rate ) {
			if ( RussianPostDomesticSettings::PICKUP_SERVICE_KEY === (string) ( $rate['service_key'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}
}
