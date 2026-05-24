<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

defined( 'ABSPATH' ) || exit;

final class OrderShippingMetaPersister {
	public function __construct(
		private CheckoutSessionManager $session_manager
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'persist' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_shipping_item', array( $this, 'persist_shipping_item_meta' ), 20, 4 );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function persist( mixed $order, array $data = array() ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$dadata_meta = $this->dadata_meta_from_checkout_data( $data );
		$location_meta = $this->location_meta_from_checkout_data( $data );

		$rate = $this->selected_rate();
		if ( array() === $rate ) {
			foreach ( array_merge( $dadata_meta, $location_meta ) as $key => $value ) {
				$order->update_meta_data( $key, $value );
			}
			return;
		}

		$map = array(
			'_wdc_platform_carrier_key'              => $rate['carrier_key'] ?? '',
			'_wdc_platform_rate_id'                  => $rate['rate_id'] ?? '',
			'_wdc_platform_delivery_type'            => $rate['delivery_type'] ?? '',
			'_wdc_platform_crossed_price'            => $rate['crossed_price'] ?? null,
			'_wdc_platform_planned_delivery_comment' => $rate['planned_delivery_comment'] ?? '',
			'_wdc_platform_comments'                 => $rate['comments'] ?? array(),
			'_wdc_platform_fallback_used'            => ! empty( $rate['fallback_used'] ) || 'fallback' === (string) ( $rate['carrier_key'] ?? '' ),
		);

		$address = $this->session_manager->normalized_address_result();
		if ( null !== $address ) {
			$city_context = $this->session_manager->city_context();
			$city_source = (string) ( $city_context['source'] ?? 'manual' );
			$address_fallback_used = 'manual' === $city_source && ( $address->address->fallback || ! $address->address->normalized );
			$map['_wdc_platform_normalized']           = $address->address->normalized;
			$map['_wdc_platform_normalization_source'] = $address->source;
			$map['_wdc_platform_fallback_city']        = $this->session_manager->fallback_city();
			$map['_wdc_platform_fallback_address']     = $address_fallback_used ? $address->address->raw_address : '';
			$map['_wdc_platform_address_fallback_used'] = $address_fallback_used;
			$map['_wdc_platform_resolved_postcode']    = 'dadata' === $address->source && '' !== trim( $address->address->postcode ) ? $address->address->postcode : (string) ( $city_context['postcode'] ?? $address->address->postcode );
			$map['_wdc_platform_fias_id']              = $address->address->fias_id;
			$map['_wdc_platform_gar_id']               = $address->address->gar_id;
			$map['_wdc_platform_city_source']          = $city_source;
			$map['_wdc_platform_city_display_name']    = $this->city_display( $city_context, $address );
			$map['_wdc_platform_city_postcode']        = (string) ( $city_context['postcode'] ?? $address->address->postcode );
			$map['_wdc_platform_city_fias_id']         = (string) ( $city_context['fias_id'] ?? '' );
			$map['_wdc_platform_city_gar_id']          = (string) ( $city_context['gar_id'] ?? '' );
		}

		$map = array_merge( $map, $dadata_meta, $this->compatible_dadata_meta( $data ), $location_meta );

		$pickup = $this->session_manager->pickup_selection();
		if (
			'pickup' === (string) ( $rate['delivery_type'] ?? '' )
			&& $this->session_manager->pickup_selection_matches( (string) ( $rate['carrier_key'] ?? '' ), (string) ( $rate['rate_id'] ?? '' ) )
		) {
			$map['_wdc_platform_pickup_code']      = $pickup['point_code'] ?? '';
			$map['_wdc_platform_pickup_address']   = $pickup['point_address'] ?? '';
			$map['_wdc_platform_pickup_comment']   = $pickup['point_comment'] ?? '';
			$map['_wdc_platform_pickup_work_time'] = $pickup['point_work_time'] ?? '';
			$this->set_pickup_shipping_address( $order, $pickup, $address );
		}

		foreach ( $map as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
	}

	/**
	 * @param mixed $item WooCommerce shipping item.
	 * @param mixed $package_key Checkout package key.
	 * @param mixed $package Checkout package.
	 * @param mixed $order WooCommerce order.
	 */
	public function persist_shipping_item_meta( mixed $item, mixed $package_key = null, mixed $package = null, mixed $order = null ): void {
		unset( $package_key, $package, $order );

		if ( ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) ) {
			return;
		}

		$rate = $this->selected_rate();
		if ( array() === $rate ) {
			return;
		}

		$delivery_type = (string) ( $rate['delivery_type'] ?? '' );
		$rows          = array(
			'Перевозчик'       => (string) ( $rate['carrier_key'] ?? '' ),
			'Способ доставки'  => (string) ( $rate['rate_id'] ?? '' ),
			'Тип доставки'     => $this->delivery_type_label( $delivery_type ),
			'Срок доставки'    => (string) ( $rate['planned_delivery_comment'] ?? '' ),
			'Населенный пункт' => $this->address_summary(),
			'Нормализация'     => $this->normalization_summary(),
		);

		$pickup = $this->session_manager->pickup_selection();
		if (
			'pickup' === $delivery_type
			&& $this->session_manager->pickup_selection_matches( (string) ( $rate['carrier_key'] ?? '' ), (string) ( $rate['rate_id'] ?? '' ) )
		) {
			$rows['Код ПВЗ']          = (string) ( $pickup['point_code'] ?? '' );
			$rows['Адрес ПВЗ']        = (string) ( $pickup['point_address'] ?? '' );
			$rows['Комментарий ПВЗ']  = (string) ( $pickup['point_comment'] ?? '' );
			$rows['Режим работы ПВЗ'] = (string) ( $pickup['point_work_time'] ?? '' );
		}

		foreach ( $rows as $label => $value ) {
			if ( '' === trim( (string) $value ) ) {
				continue;
			}

			$item->add_meta_data( $label, $value, true );
		}
	}

	/**
	 * @param array<string,mixed> $pickup
	 */
	private function set_pickup_shipping_address( object $order, array $pickup, mixed $address_result ): void {
		$address = is_object( $address_result ) && isset( $address_result->address ) ? $address_result->address : null;
		$this->call_order_setter( $order, 'set_shipping_address_1', (string) ( $pickup['point_address'] ?? '' ) );
		$this->call_order_setter( $order, 'set_shipping_address_2', '' !== (string) ( $pickup['point_code'] ?? '' ) ? 'Код ПВЗ: ' . (string) $pickup['point_code'] : '' );
		$this->call_order_setter( $order, 'set_shipping_city', is_object( $address ) ? (string) ( $address->settlement ?: $address->city ) : '' );
		$this->call_order_setter( $order, 'set_shipping_postcode', is_object( $address ) ? (string) $address->postcode : '' );
		$this->call_order_setter( $order, 'set_shipping_country', is_object( $address ) && '' !== (string) $address->country_code ? (string) $address->country_code : 'RU' );
	}

	private function call_order_setter( object $order, string $method, string $value ): void {
		if ( '' !== $value && method_exists( $order, $method ) ) {
			$order->{$method}( $value );
		}
	}

	private function delivery_type_label( string $delivery_type ): string {
		return match ( $delivery_type ) {
			'pickup' => 'Пункт выдачи',
			'courier' => 'Курьер',
			default => $delivery_type,
		};
	}

	private function address_summary(): string {
		$city_context = $this->session_manager->city_context();
		if ( array() !== $city_context ) {
			return trim( $this->city_display( $city_context, $this->session_manager->normalized_address_result() ) . ( '' !== (string) ( $city_context['postcode'] ?? '' ) ? ' / ' . (string) $city_context['postcode'] : '' ), ' /' );
		}

		$address_result = $this->session_manager->normalized_address_result();
		if ( null === $address_result ) {
			return $this->session_manager->fallback_city();
		}

		$address  = $address_result->address;
		$city     = (string) ( $address->settlement ?: $address->city ?: $this->session_manager->fallback_city() );
		$postcode = (string) $address->postcode;

		return trim( $city . ( '' !== $postcode ? ' / ' . $postcode : '' ), ' /' );
	}

	private function normalization_summary(): string {
		$address_result = $this->session_manager->normalized_address_result();
		if ( null === $address_result ) {
			return '';
		}

		if ( $address_result->address->fallback || ! $address_result->address->normalized ) {
			return 'не выполнялась';
		}

		return in_array( $address_result->source, array( 'fias', 'gar' ), true ) ? 'ФИАС/ГАР' : (string) $address_result->source;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function dadata_meta_from_checkout_data( array $data ): array {
		$meta = array();
		foreach ( array( 'billing', 'shipping' ) as $prefix ) {
			foreach ( $this->dadata_field_keys() as $field ) {
				$key = $prefix . '_dadata_' . $field;
				if ( ! array_key_exists( $key, $data ) ) {
					continue;
				}

				$meta[ '_' . $key ] = $this->sanitize_checkout_value( $data[ $key ] );
			}
		}

		return $meta;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function location_meta_from_checkout_data( array $data ): array {
		$fias_id = $this->checkout_string( $data, 'wdc_platform_location_fias_id' );
		if ( '' === $fias_id ) {
			return array();
		}

		return array(
			'_wdc_platform_location_fias_id'     => $fias_id,
			'_wdc_platform_location_display_name' => $this->checkout_string( $data, 'wdc_platform_location_display_name' ),
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function compatible_dadata_meta( array $data ): array {
		$prefix = $this->preferred_dadata_prefix( $data );
		if ( '' === $prefix ) {
			return array();
		}

		$status     = $this->checkout_string( $data, $prefix . '_dadata_status' );
		$normalized = in_array( $status, array( 'house_selected', 'resolved' ), true );
		$source     = $normalized || in_array( $status, array( 'city_selected', 'street_selected' ), true ) ? 'dadata' : ( 'manual' === $status ? 'manual' : 'fallback' );
		$address    = trim( $this->checkout_string( $data, $prefix . '_address_1' ) . ' ' . $this->checkout_string( $data, $prefix . '_address_2' ) );

		return array(
			'_wdc_platform_fias_id'               => $this->checkout_string( $data, $prefix . '_dadata_fias_id' ),
			'_wdc_platform_gar_id'                => '',
			'_wdc_platform_resolved_postcode'     => $this->checkout_string( $data, $prefix . '_postcode' ),
			'_wdc_platform_normalized'            => $normalized,
			'_wdc_platform_normalization_source'  => $source,
			'_wdc_platform_fallback_address'      => $normalized ? '' : $address,
			'_wdc_platform_address_fallback_used' => ! $normalized,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function preferred_dadata_prefix( array $data ): string {
		foreach ( array( 'shipping', 'billing' ) as $prefix ) {
			$status = $this->checkout_string( $data, $prefix . '_dadata_status' );
			if ( '' !== $status ) {
				return $prefix;
			}
		}

		return '';
	}

	/**
	 * @return array<int,string>
	 */
	private function dadata_field_keys(): array {
		return array(
			'status',
			'unrestricted_value',
			'region',
			'region_with_type',
			'region_fias_id',
			'region_kladr_id',
			'city',
			'city_with_type',
			'city_fias_id',
			'city_kladr_id',
			'settlement',
			'settlement_with_type',
			'settlement_fias_id',
			'settlement_kladr_id',
			'street',
			'street_with_type',
			'street_fias_id',
			'street_kladr_id',
			'house',
			'house_fias_id',
			'house_kladr_id',
			'block',
			'flat',
			'fias_id',
			'kladr_id',
			'fias_level',
		);
	}

	private function checkout_string( array $data, string $key ): string {
		if ( ! array_key_exists( $key, $data ) ) {
			return '';
		}

		return $this->sanitize_checkout_value( $data[ $key ] );
	}

	private function sanitize_checkout_value( mixed $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;

		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( $value );
	}

	/**
	 * @param array<string,mixed> $city_context
	 */
	private function city_display( array $city_context, mixed $address_result = null ): string {
		$display = trim( (string) ( $city_context['display_name'] ?? '' ) );
		if ( '' !== $display ) {
			return $display;
		}

		$city = trim( (string) ( $city_context['settlement_name'] ?? '' ) );
		if ( '' === $city ) {
			$city = trim( (string) ( $city_context['city_name'] ?? '' ) );
		}

		if ( '' === $city && is_object( $address_result ) && isset( $address_result->address ) ) {
			$city = (string) ( $address_result->address->settlement ?: $address_result->address->city );
		}

		$region = trim( (string) ( $city_context['region_name'] ?? '' ) );

		return trim( $city . ( '' !== $region ? ' — ' . $region : '' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function selected_rate(): array {
		$rates  = $this->session_manager->rates();
		$chosen = $this->chosen_shipping_methods();

		foreach ( $chosen as $rate_id ) {
			if ( isset( $rates[ $rate_id ] ) ) {
				return $rates[ $rate_id ];
			}

			if ( ! str_starts_with( $rate_id, NewShippingMethod::METHOD_ID . ':' ) ) {
				continue;
			}

			$normalized = substr( $rate_id, strlen( NewShippingMethod::METHOD_ID . ':' ) );
			if ( isset( $rates[ $normalized ] ) ) {
				return $rates[ $normalized ];
			}
		}

		return array();
	}

	/**
	 * @return array<int,string>
	 */
	private function chosen_shipping_methods(): array {
		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->session ) && is_object( WC()->session ) && method_exists( WC()->session, 'get' ) ) {
			$chosen = WC()->session->get( 'chosen_shipping_methods', array() );

			return is_array( $chosen ) ? array_map( 'strval', $chosen ) : array();
		}

		return array();
	}
}
