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
		wp_enqueue_style( 'wdc-leaflet', $base . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
		wp_enqueue_script( 'wdc-leaflet', $base . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
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
					'restUrl'          => function_exists( 'rest_url' ) ? rest_url( 'wdc/v1/' ) : '/wp-json/wdc/v1/',
					'nonce'            => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '',
					'carrier'          => 'russian_post',
					'shippingMethodId' => RussianPostDomesticSettings::PICKUP_SERVICE_KEY,
					'initialContext'   => $this->initial_context(),
					'labels'           => array(
						'choose'            => 'Выбрать пункт выдачи',
						'change'            => 'Изменить пункт выдачи',
						'confirm'           => 'Выбрать этот пункт',
						'searchPlaceholder' => 'Адрес или индекс',
						'empty'             => 'Переместите карту или воспользуйтесь поиском.',
						'loading'           => 'Загрузка пунктов выдачи...',
						'notFound'          => 'Пункты выдачи рядом с выбранным населенным пунктом не найдены. Попробуйте поиск по адресу или индексу.',
						'notSelected'       => 'Пункт выдачи не выбран.',
						'error'             => 'Не удалось загрузить пункты выдачи.',
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

	/**
	 * @return array<string,mixed>
	 */
	private function initial_context(): array {
		$context = $this->session_manager->city_context();
		$selected_city = $this->session_manager->selected_city();
		if ( array() !== $selected_city ) {
			foreach ( $selected_city as $key => $value ) {
				if ( ! array_key_exists( $key, $context ) || '' === (string) $context[ $key ] || null === $context[ $key ] ) {
					$context[ $key ] = $value;
				}
			}
		}

		if ( 'RU' !== strtoupper( (string) ( $context['country_code'] ?? 'RU' ) ) ) {
			return array();
		}

		$lat = $this->numeric_context_value( $context, array( 'lat', 'latitude' ) );
		$lng = $this->numeric_context_value( $context, array( 'lng', 'lon', 'longitude' ) );
		if ( ! $this->has_usable_coordinates( $lat, $lng ) ) {
			$lat = null;
			$lng = null;
		}

		return array_filter(
			array(
				'lat'   => $lat,
				'lng'   => $lng,
				'query' => $this->initial_query( $context ),
			),
			static fn( mixed $value ): bool => null !== $value && '' !== $value
		);
	}

	/**
	 * @param array<string,mixed> $context
	 * @param array<int,string>  $keys
	 */
	private function numeric_context_value( array $context, array $keys ): ?float {
		foreach ( $keys as $key ) {
			if ( isset( $context[ $key ] ) && is_numeric( $context[ $key ] ) ) {
				return (float) $context[ $key ];
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function initial_query( array $context ): string {
		$postcode = (string) ( $context['postcode'] ?? $context['postal_code'] ?? '' );
		$city = (string) ( $context['city_name'] ?? $context['settlement_name'] ?? $context['place_name'] ?? '' );
		$display = (string) ( $context['display_name'] ?? '' );
		$query = trim( implode( ' ', array_filter( array( $postcode, $city ) ) ) );
		if ( '' !== $query ) {
			return $query;
		}
		if ( '' !== trim( $display ) ) {
			return trim( $display );
		}

		return trim( $this->session_manager->fallback_city() );
	}

	private function has_usable_coordinates( ?float $lat, ?float $lng ): bool {
		if ( null === $lat || null === $lng ) {
			return false;
		}
		if ( abs( $lat ) < 0.000001 && abs( $lng ) < 0.000001 ) {
			return false;
		}

		return $lat >= -90.0 && $lat <= 90.0 && $lng >= -180.0 && $lng <= 180.0;
	}
}
