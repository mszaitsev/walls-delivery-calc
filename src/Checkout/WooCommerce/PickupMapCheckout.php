<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointTypeSettings;

defined( 'ABSPATH' ) || exit;

final class PickupMapCheckout {
	public function __construct(
		private CheckoutSessionManager $session_manager,
		private PluginEnvironment $environment,
		private ?SettingsRepository $settings = null,
		private ?RussianPostPickupPointTypeSettings $point_type_settings = null
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
		$this->session_manager->expire_stale_yandex_5post_selection();
		$base = $this->environment->plugin_url();
		$version = $this->environment->version();
		$provider = $this->map_provider();
		$provider_handle = 'wdc-map-provider-' . $provider;

		if ( 'leaflet' === $provider ) {
			wp_enqueue_style( 'wdc-leaflet', $base . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
			wp_enqueue_script( 'wdc-leaflet', $base . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
			wp_enqueue_script( $provider_handle, $base . 'assets/frontend/pickup-map/providers/wdc-map-provider-leaflet.js', array( 'wdc-leaflet' ), $version, true );
		} else {
			wp_enqueue_script( $provider_handle, $base . 'assets/frontend/pickup-map/providers/wdc-map-provider-yandex.js', array(), $version, true );
		}

		wp_enqueue_style( 'wdc-pickup-map', $base . 'assets/frontend/pickup-map/wdc-pickup-map.css', array(), $version );
		wp_enqueue_script( 'wdc-pickup-api', $base . 'assets/frontend/pickup-map/wdc-pickup-api.js', array(), $version, true );
		wp_enqueue_script( 'wdc-pickup-modal', $base . 'assets/frontend/pickup-map/wdc-pickup-modal.js', array(), $version, true );
		wp_enqueue_script( 'wdc-pickup-map', $base . 'assets/frontend/pickup-map/wdc-pickup-map.js', array( $provider_handle, 'wdc-pickup-api' ), $version, true );
		wp_enqueue_script( 'wdc-pickup-checkout', $base . 'assets/frontend/pickup-map/wdc-pickup-checkout.js', array( 'wdc-pickup-api', 'wdc-pickup-modal', 'wdc-pickup-map' ), $version, true );

		if ( function_exists( 'wp_localize_script' ) ) {
			$active_shipping_method = $this->active_shipping_method_id();
			$active_pickup_family = $this->active_pickup_family();
			$active_pickup_country = $this->active_pickup_rate_country_code();
			$initial_context = $this->initial_context();
			$pickup_selections = $this->selected_points_context( false );
			$selected_pickup_points = $this->selected_points_context( true );
			$selected_pickup_point = $this->selected_point_context( $active_pickup_family );
			wp_localize_script(
				'wdc-pickup-checkout',
				'wdcPickupCheckout',
				array(
					'restUrl'          => function_exists( 'rest_url' ) ? rest_url( 'wdc/v1/' ) : '/wp-json/wdc/v1/',
					'nonce'            => function_exists( 'wp_create_nonce' ) ? wp_create_nonce( 'wp_rest' ) : '',
					'carrier'          => $this->first_pickup_carrier(),
					'shippingMethodId' => $active_shipping_method,
					'activeShippingMethod' => $active_shipping_method,
					'initialContext'   => $initial_context,
					'pickupSelections' => $pickup_selections,
					'pickupSelectionsRaw' => $pickup_selections,
					'selectedPickupPoints' => $selected_pickup_points,
					'activePickupFamily' => $active_pickup_family,
					'activePickupCountryCode' => $active_pickup_country,
					'selectedPickupPoint' => $selected_pickup_point,
					'mapProvider'      => $provider,
					'pickupPointTypes' => $this->pickup_point_types(),
					'pickupFamilies'   => $this->pickup_families(),
					'pickupPresentation' => $this->pickup_presentation(),
					'yandexApiKeyPresent' => $this->has_yandex_api_key(),
					'yandexApiKey'     => 'yandex' === $provider && $this->has_yandex_api_key() ? $this->yandex_api_key() : '',
					'labels'           => array(
						'choose'            => 'Выбрать пункт выдачи',
						'change'            => 'Изменить пункт выдачи',
						'confirm'           => 'Выбрать этот пункт',
						'searchPlaceholder' => 'Адрес или индекс',
						'postcodeOnlyPlaceholder' => 'Сейчас работает поиск только по почтовому индексу',
						'empty'             => 'Переместите карту или воспользуйтесь поиском.',
						'loading'           => 'Поиск...',
						'addressNotFound'   => 'Адрес не найден',
						'postcodeOnly'      => 'Поиск доступен только по индексу',
						'dadataError'       => 'Ошибка DaData',
						'notFound'          => 'Пункты выдачи не найдены. Попробуйте изменить поисковый запрос или переместить карту.',
						'selectPoint'       => 'Выберите пункт на карте.',
						'notSelected'       => 'Пункт выдачи не выбран.',
						'error'             => 'Не удалось загрузить пункты выдачи.',
					),
					'errors'           => array(
						'yandexApiKeyMissing' => 'Для Яндекс.Карт не задан API key. Выберите OpenStreetMap или укажите ключ в настройках.',
					),
				)
			);
		}
	}

	private function has_pickup_rate(): bool {
		foreach ( $this->session_manager->rates() as $rate ) {
			if (
				DeliveryType::PICKUP === (string) ( $rate['delivery_type'] ?? '' )
				&& ! empty( $rate['requires_pickup_point'] )
			) {
				return true;
			}
		}

		return false;
	}

	private function first_pickup_carrier(): string {
		foreach ( $this->session_manager->rates() as $rate ) {
			if ( DeliveryType::PICKUP === (string) ( $rate['delivery_type'] ?? '' ) && ! empty( $rate['requires_pickup_point'] ) ) {
				return (string) ( $rate['carrier_key'] ?? '' );
			}
		}

		return '';
	}

	private function first_pickup_rate_id(): string {
		foreach ( $this->session_manager->rates() as $rate ) {
			if ( DeliveryType::PICKUP === (string) ( $rate['delivery_type'] ?? '' ) && ! empty( $rate['requires_pickup_point'] ) ) {
				return (string) ( $rate['rate_id'] ?? $rate['id'] ?? '' );
			}
		}

		return '';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function initial_context(): array {
		$active_family = $this->active_pickup_family();
		$context = $this->session_manager->city_context();
		$rate_location = $this->active_pickup_rate_location();
		if ( array() !== $rate_location ) {
			foreach ( $rate_location as $key => $value ) {
				if ( ! array_key_exists( $key, $context ) || '' === (string) $context[ $key ] || null === $context[ $key ] ) {
					$context[ $key ] = $value;
				}
			}
		}
		$selected_city = $this->session_manager->selected_city();
		if ( array() !== $selected_city ) {
			foreach ( $selected_city as $key => $value ) {
				if ( ! array_key_exists( $key, $context ) || '' === (string) $context[ $key ] || null === $context[ $key ] ) {
					$context[ $key ] = $value;
				}
			}
		}

		$country_code = strtoupper( (string) ( $context['country_code'] ?? 'RU' ) );
		$rate_country = $this->active_pickup_rate_country_code();
		if ( '' !== $rate_country && $country_code !== $rate_country ) {
			return array();
		}
		if ( '' === $rate_country && 'RU' !== $country_code ) {
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
				'location_id' => $context['location_id'] ?? $context['id'] ?? null,
				'city_code' => $context['city_code'] ?? $context['cdek_city_code'] ?? null,
				'cdek_city_code' => $context['cdek_city_code'] ?? $context['city_code'] ?? null,
				'city_name' => $context['city_name'] ?? $context['settlement_name'] ?? $context['place_name'] ?? null,
				'region_name' => $context['region_name'] ?? null,
				'postcode' => $context['postcode'] ?? $context['postal_code'] ?? null,
				'country_code' => $country_code,
				'selectedPoint' => $this->selected_point_context( $active_family ),
			),
			static fn( mixed $value ): bool => null !== $value && '' !== $value
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function active_pickup_rate_location(): array {
		$rate = $this->active_pickup_rate();
		$meta = $this->rate_meta( $rate );
		$location = is_array( $meta['location'] ?? null ) ? $meta['location'] : array();

		return $location;
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function selected_point_context( string $pickup_family = '', bool $require_address = true ): ?array {
		$selection = '' !== $pickup_family ? $this->session_manager->checkout_pickup_point_for_family( $pickup_family ) : $this->session_manager->checkout_pickup_point();
		if ( array() === $selection || ! $this->selection_has_identity( $selection ) ) {
			return null;
		}

		$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();
		$address = $this->pickup_address( $selection );
		if ( $require_address && '' === $address ) {
			return null;
		}

		return array_filter(
			array(
				'id' => $selection['id'] ?? $snapshot['id'] ?? null,
				'carrier_key' => $selection['carrier_key'] ?? $snapshot['carrier_key'] ?? null,
				'carrier' => $selection['carrier'] ?? $selection['carrier_key'] ?? $snapshot['carrier'] ?? $snapshot['carrier_key'] ?? null,
				'service_key' => $selection['service_key'] ?? $snapshot['service_key'] ?? null,
				'pickup_family' => $selection['pickup_family'] ?? $snapshot['pickup_family'] ?? null,
				'point_code' => $selection['point_code'] ?? $snapshot['point_code'] ?? null,
				'point_type' => $selection['point_type'] ?? $snapshot['point_type'] ?? null,
				'point_type_label' => $selection['point_type_label'] ?? $snapshot['point_type_label'] ?? null,
				'point_title' => $selection['point_title'] ?? $selection['card_title'] ?? $snapshot['point_title'] ?? $snapshot['card_title'] ?? null,
				'display_code' => $selection['display_code'] ?? $snapshot['display_code'] ?? null,
				'display_title' => $selection['display_title'] ?? $snapshot['display_title'] ?? null,
				'presentation_comment' => $selection['presentation_comment'] ?? $snapshot['presentation_comment'] ?? null,
				'point_name' => $selection['point_name'] ?? $snapshot['point_name'] ?? null,
				'point_address' => $address ?: ( $selection['point_address'] ?? $selection['address'] ?? $snapshot['address'] ?? null ),
				'point_postcode' => $selection['point_postcode'] ?? $selection['postcode'] ?? $snapshot['postcode'] ?? null,
				'postcode' => $selection['postcode'] ?? $snapshot['postcode'] ?? null,
				'address' => $address ?: ( $selection['address'] ?? $snapshot['address'] ?? null ),
				'city_name' => $selection['city_name'] ?? $selection['city'] ?? $snapshot['city_name'] ?? $snapshot['city'] ?? null,
				'region_name' => $selection['region_name'] ?? $selection['region'] ?? $snapshot['region_name'] ?? $snapshot['region'] ?? null,
				'location_id' => $selection['location_id'] ?? $snapshot['location_id'] ?? null,
				'fias_id' => $selection['fias_id'] ?? $snapshot['fias_id'] ?? null,
				'gar_object_id' => $selection['gar_object_id'] ?? $snapshot['gar_object_id'] ?? null,
				'destination_fingerprint' => $selection['destination_fingerprint'] ?? $snapshot['destination_fingerprint'] ?? null,
				'lat' => $selection['lat'] ?? $snapshot['lat'] ?? null,
				'lng' => $selection['lng'] ?? $snapshot['lng'] ?? null,
				'work_time' => $selection['work_time'] ?? $selection['point_work_time'] ?? $snapshot['work_time'] ?? null,
				'point_work_time' => $selection['point_work_time'] ?? $selection['work_time'] ?? $snapshot['work_time'] ?? null,
				'description' => $selection['description'] ?? $selection['point_comment'] ?? $snapshot['description'] ?? null,
				'storage_notice' => $selection['storage_notice'] ?? $snapshot['storage_notice'] ?? null,
				'marker_type' => $selection['marker_type'] ?? $snapshot['marker_type'] ?? null,
				'cdek_code' => $selection['cdek_code'] ?? $snapshot['cdek_code'] ?? null,
				'cdek_type' => $selection['cdek_type'] ?? $snapshot['cdek_type'] ?? null,
				'snapshot' => $snapshot,
			),
			static fn( mixed $value ): bool => null !== $value && '' !== $value && array() !== $value
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

	private function map_provider(): string {
		$provider = $this->settings instanceof SettingsRepository ? $this->settings->get_string( 'pickup_map_provider', 'leaflet' ) : 'leaflet';

		return 'yandex' === $provider ? 'yandex' : 'leaflet';
	}

	private function yandex_api_key(): string {
		return $this->settings instanceof SettingsRepository ? trim( $this->settings->get_string( 'pickup_map_yandex_api_key', '' ) ) : '';
	}

	private function has_yandex_api_key(): bool {
		return '' !== $this->yandex_api_key();
	}

	/**
	 * @return array<string,array{enabled:bool,label:string}>
	 */
	private function pickup_point_types(): array {
		$type_settings = $this->point_type_settings ?? new RussianPostPickupPointTypeSettings( $this->settings );

		return $type_settings->all();
	}

	/**
	 * @return array<int,string>
	 */
	private function pickup_families(): array {
		$families = array();
		foreach ( $this->session_manager->rates() as $rate ) {
			if ( DeliveryType::PICKUP !== (string) ( $rate['delivery_type'] ?? '' ) || empty( $rate['requires_pickup_point'] ) ) {
				continue;
			}
			$family = $this->session_manager->shipping_method_family( (string) ( $rate['rate_id'] ?? $rate['id'] ?? '' ) );
			if ( '' !== $family && ! in_array( $family, $families, true ) ) {
				$families[] = $family;
			}
		}

		return $families;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function pickup_presentation(): array {
		return array(
			'defaults' => array(
				'card_title' => 'Пункт выдачи',
				'point_type_label' => 'Пункт выдачи',
				'marker_type' => 'pickup',
				'show_code_on_checkout' => false,
				'show_postcode_on_checkout' => false,
			),
			'families' => array(
				'cdek:pickup' => array(
					'types' => array(
						'PVZ' => array( 'card_title' => 'Пункт выдачи СДЭК', 'point_type_label' => 'Пункт выдачи', 'marker_type' => 'pickup' ),
						'POSTAMAT' => array( 'card_title' => 'Постамат СДЭК', 'point_type_label' => 'Постамат', 'storage_notice' => 'Срок хранения 3 дня', 'marker_type' => 'postamat' ),
					),
				),
				'dpd:pickup' => array(
					'types' => array(
						'PARCEL_SHOP' => array( 'card_title' => 'Пункт выдачи DPD', 'point_type_label' => 'Пункт выдачи', 'marker_type' => 'pickup' ),
						'TERMINAL_SELF_DELIVERY' => array( 'card_title' => 'Терминал DPD', 'point_type_label' => 'Терминал', 'marker_type' => 'terminal' ),
					),
				),
				'yandex_delivery:pickup' => array(
					'types' => array(
						'PICKUP_POINT' => array( 'card_title' => 'Пункт выдачи Яндекс.Доставки', 'point_type_label' => 'Пункт выдачи', 'marker_type' => 'pickup' ),
						'TERMINAL' => array( 'card_title' => 'Терминал Яндекс.Доставки', 'point_type_label' => 'Терминал', 'marker_type' => 'terminal' ),
					),
				),
				'russian_post_domestic:pickup' => array(
					'types' => array(
						'OPS' => array( 'card_title' => 'Отделение Почты России', 'point_type_label' => 'Пункт выдачи', 'marker_type' => 'pickup' ),
						'PVZ' => array( 'card_title' => 'Отделение Почты России', 'point_type_label' => 'Пункт выдачи', 'marker_type' => 'pickup' ),
						'APS' => array( 'card_title' => 'Почтомат Почты России', 'point_type_label' => 'Почтомат', 'marker_type' => 'postamat' ),
					),
				),
			),
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private function selected_points_context( bool $require_address = false ): array {
		$selected = array();
		foreach ( $this->session_manager->pickup_selections() as $family => $selection ) {
			$point = $this->selected_point_context( $family, $require_address );
			if ( null !== $point ) {
				$selected[ $family ] = $point;
			}
		}

		return $selected;
	}

	/**
	 * @param array<string,mixed> $selection
	 */
	private function selection_has_identity( array $selection ): bool {
		return '' !== trim( (string) ( $selection['point_code'] ?? '' ) )
			|| '' !== trim( (string) ( $selection['point_id'] ?? $selection['id'] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $selection
	 */
	private function pickup_address( array $selection ): string {
		$snapshot = is_array( $selection['snapshot'] ?? null ) ? $selection['snapshot'] : array();
		$raw = is_array( $selection['raw'] ?? null ) ? $selection['raw'] : ( is_array( $snapshot['raw'] ?? null ) ? $snapshot['raw'] : array() );

		return $this->first_text(
			$selection['point_address'] ?? '',
			$selection['address'] ?? '',
			$selection['address_full'] ?? '',
			$selection['full_address'] ?? '',
			$selection['address_short'] ?? '',
			$selection['location_address'] ?? '',
			$selection['address_source'] ?? '',
			$snapshot['point_address'] ?? '',
			$snapshot['address'] ?? '',
			$snapshot['address_full'] ?? '',
			$snapshot['full_address'] ?? '',
			$snapshot['address_short'] ?? '',
			$snapshot['location_address'] ?? '',
			$snapshot['address_source'] ?? '',
			$raw['address'] ?? '',
			$raw['address_full'] ?? '',
			$raw['full_address'] ?? '',
			$raw['address_short'] ?? '',
			$raw['location_address'] ?? ''
		);
	}

	private function first_text( mixed ...$values ): string {
		foreach ( $values as $value ) {
			if ( is_scalar( $value ) ) {
				$text = trim( (string) $value );
				if ( '' !== $text ) {
					return $text;
				}
			}
		}

		return '';
	}

	private function active_pickup_family(): string {
		$chosen = $this->active_shipping_method_id();
		$family = '' !== $chosen ? $this->session_manager->shipping_method_family( $chosen ) : '';
		return str_ends_with( $family, ':pickup' ) ? $family : '';
	}

	private function active_shipping_method_id(): string {
		$chosen = $this->chosen_shipping_method();
		if ( '' !== $chosen ) {
			return $this->session_manager->normalize_rate_id( $chosen );
		}

		return $this->first_pickup_rate_id();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function active_pickup_rate(): array {
		$method_id = $this->active_shipping_method_id();
		if ( '' === $method_id ) {
			return array();
		}
		$rates = $this->session_manager->rates();
		if ( isset( $rates[ $method_id ] ) && is_array( $rates[ $method_id ] ) ) {
			$rate = $rates[ $method_id ];
			return $this->is_pickup_rate( $rate ) ? $rate : array();
		}
		foreach ( $rates as $rate ) {
			if ( ! is_array( $rate ) || ! $this->is_pickup_rate( $rate ) ) {
				continue;
			}
			$rate_id = $this->session_manager->normalize_rate_id( (string) ( $rate['rate_id'] ?? $rate['id'] ?? '' ) );
			if ( $rate_id === $method_id ) {
				return $rate;
			}
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function is_pickup_rate( array $rate ): bool {
		return DeliveryType::PICKUP === (string) ( $rate['delivery_type'] ?? '' ) && ! empty( $rate['requires_pickup_point'] );
	}

	private function active_pickup_rate_country_code(): string {
		$rate = $this->active_pickup_rate();
		if ( array() === $rate ) {
			return '';
		}
		$meta = $this->rate_meta( $rate );
		$location = is_array( $meta['location'] ?? null ) ? $meta['location'] : array();
		$country = strtoupper(
			trim(
				$this->first_text(
					$location['cdek_to_country_code'] ?? '',
					$location['country_code'] ?? '',
					$meta['country_code'] ?? '',
					$rate['country_code'] ?? ''
				)
			)
		);

		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
	}

	/**
	 * @param array<string,mixed> $rate
	 * @return array<string,mixed>
	 */
	private function rate_meta( array $rate ): array {
		$meta = is_array( $rate['meta'] ?? null ) ? $rate['meta'] : array();
		$legacy_meta = is_array( $rate['rate_meta'] ?? null ) ? $rate['rate_meta'] : array();

		return array_replace_recursive( $legacy_meta, $meta );
	}

	private function chosen_shipping_method(): string {
		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->session ) && is_object( WC()->session ) && method_exists( WC()->session, 'get' ) ) {
			$chosen = WC()->session->get( 'chosen_shipping_methods', array() );
			if ( is_array( $chosen ) ) {
				foreach ( $chosen as $method ) {
					$method = trim( (string) $method );
					if ( '' !== $method ) {
						return $method;
					}
				}
			}
		}

		return '';
	}

	private function carrier_from_family( string $family ): string {
		$parts = explode( ':', $family );
		return trim( (string) ( $parts[0] ?? '' ) );
	}
}
