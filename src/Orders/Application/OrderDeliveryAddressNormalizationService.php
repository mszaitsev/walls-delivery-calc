<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Application;

use WallsShop\WDC\Checkout\Address\CheckoutAddressRuntime;

defined( 'ABSPATH' ) || exit;

final class OrderDeliveryAddressNormalizationService {
	public function __construct(
		private ?CheckoutAddressRuntime $runtime = null
	) {
	}

	/**
	 * @param array<string,mixed> $selected_location
	 * @return array{success:bool,payload:array<string,mixed>,message:string}
	 */
	public function normalize( object $order, array $selected_location, string $address_line ): array {
		$address_line = trim( $address_line );
		if ( '' === $address_line ) {
			return array(
				'success' => false,
				'payload' => array( 'normalized' => false, 'fallback' => false ),
				'message' => 'Введите адрес доставки.',
			);
		}

		$checkout_data = $this->checkout_data( $order, $selected_location, $address_line );
		if ( $this->runtime instanceof CheckoutAddressRuntime ) {
			$result = $this->runtime->resolve_checkout_address( $checkout_data );
			$payload = $this->payload_from_result( $result->to_array(), $address_line );
			return array(
				'success' => ! empty( $payload['normalized'] ) && empty( $payload['fallback'] ),
				'payload' => $payload,
				'message' => ! empty( $payload['normalized'] ) && empty( $payload['fallback'] ) ? 'Адрес нормализован.' : (string) ( $payload['message'] ?? 'Адрес не удалось нормализовать.' ),
			);
		}

		$payload = $this->fallback_payload( $checkout_data, $address_line );
		return array(
			'success' => true,
			'payload' => $payload,
			'message' => 'Адрес нормализован.',
		);
	}

	/**
	 * @param array<string,mixed> $selected_location
	 * @return array{success:bool,lat:?float,lng:?float,address:string,formatted_address:string,message:string}
	 */
	public function geocode( object $order, array $selected_location, string $address_line ): array {
		$result = $this->normalize( $order, $selected_location, $address_line );
		$payload = is_array( $result['payload'] ?? null ) ? $result['payload'] : array();
		$lat = $this->coordinate( $payload, array( 'lat', 'latitude', 'geo_lat' ) );
		$lng = $this->coordinate( $payload, array( 'lng', 'lon', 'longitude', 'geo_lon' ) );
		if ( null !== $lat && null !== $lng && ! empty( $result['success'] ) ) {
			$address = (string) ( $payload['full_address'] ?? $address_line );
			return array(
				'success' => true,
				'lat' => $lat,
				'lng' => $lng,
				'address' => $address,
				'formatted_address' => $address,
				'message' => 'Адрес найден.',
			);
		}

		return array(
			'success' => false,
			'lat' => null,
			'lng' => null,
			'address' => '',
			'formatted_address' => '',
			'message' => (string) ( $result['message'] ?? 'Адрес не найден или геокодинг недоступен.' ),
		);
	}

	/**
	 * @param array<string,mixed> $selected_location
	 * @return array<string,mixed>
	 */
	private function checkout_data( object $order, array $selected_location, string $address_line ): array {
		$city = (string) ( $selected_location['city_value'] ?? $selected_location['city_name'] ?? $selected_location['display_name'] ?? ( method_exists( $order, 'get_shipping_city' ) ? $order->get_shipping_city() : '' ) );
		$postcode = (string) ( $selected_location['postal_code'] ?? $selected_location['postcode'] ?? ( method_exists( $order, 'get_shipping_postcode' ) ? $order->get_shipping_postcode() : '' ) );
		$region = (string) ( $selected_location['region_name'] ?? $selected_location['state_value'] ?? ( method_exists( $order, 'get_shipping_state' ) ? $order->get_shipping_state() : '' ) );
		$country = (string) ( $selected_location['country_code'] ?? ( method_exists( $order, 'get_shipping_country' ) ? $order->get_shipping_country() : 'RU' ) );

		return array(
			'shipping_country' => '' !== $country ? $country : 'RU',
			'shipping_state' => $region,
			'shipping_city' => $city,
			'shipping_postcode' => $postcode,
			'shipping_address_1' => $address_line,
			'shipping_address_2' => '',
			'wdc_platform_location_id' => (string) ( $selected_location['location_id'] ?? $selected_location['id'] ?? '' ),
			'wdc_platform_location_fias_id' => (string) ( $selected_location['fias_id'] ?? '' ),
			'wdc_platform_location_gar_object_id' => (string) ( $selected_location['gar_object_id'] ?? $selected_location['gar_id'] ?? '' ),
			'wdc_platform_location_display_name' => (string) ( $selected_location['display_name'] ?? $city ),
			'wdc_platform_location_region_name' => $region,
			'wdc_platform_location_city_name' => $city,
			'wdc_platform_location_lat' => (string) ( $selected_location['lat'] ?? $selected_location['latitude'] ?? '' ),
			'wdc_platform_location_lng' => (string) ( $selected_location['lng'] ?? $selected_location['longitude'] ?? '' ),
		);
	}

	/**
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	private function payload_from_result( array $result, string $address_line ): array {
		$address = is_array( $result['address'] ?? null ) ? $result['address'] : array();
		$city = (string) ( $address['settlement'] ?? $address['city'] ?? '' );
		$street = (string) ( $address['street'] ?? '' );
		$house = (string) ( $address['house'] ?? '' );
		$flat = (string) ( $address['apartment'] ?? '' );
		$address_1 = trim( implode( ', ', array_filter( array( $street, $house ), static fn( string $part ): bool => '' !== $part ) ) );
		if ( '' === $address_1 ) {
			$address_1 = $address_line;
		}

		return array(
			'country' => (string) ( $address['country_code'] ?? 'RU' ),
			'region' => (string) ( $address['region_name'] ?? '' ),
			'city' => $city,
			'postcode' => (string) ( $address['postcode'] ?? '' ),
			'street' => $street,
			'house' => $house,
			'flat' => $flat,
			'address_1' => $address_1,
			'address_2' => $flat,
			'full_address' => (string) ( $address['raw_address'] ?? $address_line ),
			'fias_id' => (string) ( $address['fias_id'] ?? '' ),
			'gar_id' => (string) ( $address['gar_id'] ?? '' ),
			'lat' => $this->coordinate( $address, array( 'lat', 'latitude', 'geo_lat' ) ),
			'lng' => $this->coordinate( $address, array( 'lng', 'lon', 'longitude', 'geo_lon' ) ),
			'normalized' => ! empty( $address['normalized'] ) && ! empty( $result['success'] ),
			'fallback' => ! empty( $address['fallback'] ),
			'source' => (string) ( $result['source'] ?? '' ),
			'message' => (string) ( $result['error_message'] ?? '' ),
		);
	}

	/**
	 * @param array<string,mixed> $checkout_data
	 * @return array<string,mixed>
	 */
	private function fallback_payload( array $checkout_data, string $address_line ): array {
		return array(
			'country' => (string) ( $checkout_data['shipping_country'] ?? 'RU' ),
			'region' => (string) ( $checkout_data['shipping_state'] ?? '' ),
			'city' => (string) ( $checkout_data['shipping_city'] ?? '' ),
			'postcode' => (string) ( $checkout_data['shipping_postcode'] ?? '' ),
			'street' => $address_line,
			'house' => '',
			'flat' => '',
			'address_1' => $address_line,
			'address_2' => '',
			'full_address' => $address_line,
			'fias_id' => (string) ( $checkout_data['wdc_platform_location_fias_id'] ?? '' ),
			'gar_id' => (string) ( $checkout_data['wdc_platform_location_gar_object_id'] ?? '' ),
			'lat' => $this->coordinate( $checkout_data, array( 'wdc_platform_location_lat', 'lat', 'latitude' ) ),
			'lng' => $this->coordinate( $checkout_data, array( 'wdc_platform_location_lng', 'lng', 'longitude' ) ),
			'normalized' => true,
			'fallback' => false,
			'source' => 'admin',
			'message' => '',
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<int,string> $keys
	 */
	private function coordinate( array $payload, array $keys ): ?float {
		foreach ( $keys as $key ) {
			if ( ! array_key_exists( $key, $payload ) || '' === (string) $payload[ $key ] ) {
				continue;
			}
			$value = (float) str_replace( ',', '.', (string) $payload[ $key ] );
			if ( 0.0 !== $value ) {
				return $value;
			}
		}

		return null;
	}
}
