<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Application;

use WallsShop\WDC\Checkout\Address\CheckoutAddressRuntime;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionClientInterface;

defined( 'ABSPATH' ) || exit;

final class OrderDeliveryAddressNormalizationService {
	public function __construct(
		private ?CheckoutAddressRuntime $runtime = null,
		private ?AddressSuggestionClientInterface $suggestions = null
	) {
	}

	/**
	 * @param array<string,mixed> $selected_location
	 * @return array{success:bool,payload:array<string,mixed>,message:string,requires_selection?:bool,suggestions?:array<int,array<string,mixed>>,debug?:array<string,mixed>}
	 */
	public function normalize( object $order, array $selected_location, string $address_line ): array {
		$address_line = trim( $address_line );
		if ( '' === $address_line ) {
			return array(
				'success' => false,
				'payload' => array( 'normalized' => false, 'fallback' => false ),
				'message' => 'Введите адрес доставки.',
				'debug' => array(
					'input' => '',
					'suggestions_count' => 0,
					'rejected_reasons' => array( 'empty_input' ),
				),
			);
		}

		$checkout_data = $this->checkout_data( $order, $selected_location, $address_line );
		if ( $this->suggestions instanceof AddressSuggestionClientInterface ) {
			$suggestion_result = $this->normalize_from_suggestions( $selected_location, $address_line );
			$suggestions = is_array( $suggestion_result['suggestions'] ?? null ) ? $suggestion_result['suggestions'] : array();
			if ( 1 === count( $suggestions ) ) {
				return array(
					'success' => true,
					'payload' => is_array( $suggestions[0]['address'] ?? null ) ? $suggestions[0]['address'] : array(),
					'message' => 'Адрес нормализован.',
					'suggestions' => array(),
					'debug' => is_array( $suggestion_result['debug'] ?? null ) ? $suggestion_result['debug'] : array(),
				);
			}
			if ( count( $suggestions ) > 1 ) {
				return array(
					'success' => false,
					'payload' => array( 'normalized' => false, 'fallback' => false ),
					'message' => 'Выберите подходящий адрес из вариантов.',
					'requires_selection' => true,
					'suggestions' => $suggestions,
					'debug' => is_array( $suggestion_result['debug'] ?? null ) ? $suggestion_result['debug'] : array(),
				);
			}
		}

		if ( $this->runtime instanceof CheckoutAddressRuntime ) {
			$result = $this->runtime->resolve_checkout_address( $checkout_data );
			$payload = $this->payload_from_result( $result->to_array(), $address_line );
			return array(
				'success' => ! empty( $payload['normalized'] ) && empty( $payload['fallback'] ),
				'payload' => $payload,
				'message' => ! empty( $payload['normalized'] ) && empty( $payload['fallback'] ) ? 'Адрес нормализован.' : (string) ( $payload['message'] ?? 'Адрес не удалось нормализовать.' ),
				'debug' => array(
					'input' => $address_line,
					'suggestions_count' => 0,
					'rejected_reasons' => array( 'runtime_result' ),
				),
			);
		}

		$payload = $this->fallback_payload( $checkout_data, $address_line );
		return array(
			'success' => true,
			'payload' => $payload,
			'message' => 'Адрес нормализован.',
			'debug' => array(
				'input' => $address_line,
				'suggestions_count' => 0,
				'rejected_reasons' => array( 'fallback_payload' ),
			),
		);
	}

	/**
	 * @param array<string,mixed> $selected_location
	 * @return array{success:bool,lat:?float,lng:?float,address:string,formatted_address:string,message:string}
	 */
	public function geocode( object $order, array $selected_location, string $address_line ): array {
		$address_line = trim( $address_line );
		if ( '' === $address_line ) {
			return array(
				'success' => false,
				'lat' => null,
				'lng' => null,
				'address' => '',
				'formatted_address' => '',
				'message' => 'Введите адрес для поиска.',
			);
		}

		if ( $this->suggestions instanceof AddressSuggestionClientInterface ) {
			$suggestion = $this->geocode_from_suggestions( $selected_location, $address_line );
			if ( null !== $suggestion ) {
				return $suggestion;
			}

			return array(
				'success' => false,
				'lat' => null,
				'lng' => null,
				'address' => '',
				'formatted_address' => '',
				'message' => 'Адрес не найден или координаты недоступны.',
			);
		}

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
			'message' => 'Адрес не найден или координаты недоступны.',
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
		$address_1 = $this->address_1_from_parts( $street, $house, '', $flat );
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
			'address_2' => '',
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
	 * @param array<string,mixed> $selected_location
	 * @return array{success:bool,lat:?float,lng:?float,address:string,formatted_address:string,message:string}|null
	 */
	private function geocode_from_suggestions( array $selected_location, string $address_line ): ?array {
		$response = $this->suggestions?->suggest( 'address', $address_line, $this->suggestion_context( $selected_location ) );
		if ( ! is_array( $response ) || empty( $response['success'] ) ) {
			return null;
		}

		$suggestions = is_array( $response['suggestions'] ?? null ) ? $response['suggestions'] : array();
		foreach ( $suggestions as $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}
			$data = is_array( $suggestion['data'] ?? null ) ? $suggestion['data'] : array();
			$lat = $this->coordinate( $data, array( 'geo_lat', 'lat', 'latitude' ) );
			$lng = $this->coordinate( $data, array( 'geo_lon', 'lng', 'lon', 'longitude' ) );
			if ( null === $lat || null === $lng ) {
				continue;
			}
			$address = (string) ( $suggestion['unrestricted_value'] ?? $suggestion['value'] ?? $address_line );

			return array(
				'success' => true,
				'lat' => $lat,
				'lng' => $lng,
				'address' => $address,
				'formatted_address' => $address,
				'message' => 'Адрес найден.',
			);
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $selected_location
	 * @return array{suggestions:array<int,array{label:string,address:array<string,mixed>}>,debug:array<string,mixed>}
	 */
	private function normalize_from_suggestions( array $selected_location, string $address_line ): array {
		$response = $this->suggestions?->suggest( 'address', $address_line, $this->suggestion_context( $selected_location ) );
		$debug = array(
			'input' => $address_line,
			'suggestions_count' => 0,
			'first_suggestion_keys' => array(),
			'first_data' => array(),
			'rejected_reasons' => array(),
		);
		if ( ! is_array( $response ) || empty( $response['success'] ) ) {
			$debug['rejected_reasons'][] = 'suggestion_client_error';
			return array( 'suggestions' => array(), 'debug' => $debug );
		}
		$suggestions = is_array( $response['suggestions'] ?? null ) ? $response['suggestions'] : array();
		$debug['suggestions_count'] = count( $suggestions );
		if ( isset( $suggestions[0] ) && is_array( $suggestions[0] ) ) {
			$debug['first_suggestion_keys'] = array_values( array_keys( $suggestions[0] ) );
			$first_data = is_array( $suggestions[0]['data'] ?? null ) ? $suggestions[0]['data'] : array();
			$debug['first_data'] = array(
				'value' => (string) ( $suggestions[0]['value'] ?? '' ),
				'unrestricted_value' => (string) ( $suggestions[0]['unrestricted_value'] ?? '' ),
				'qc' => $first_data['qc'] ?? null,
				'qc_complete' => $first_data['qc_complete'] ?? null,
				'fias_level' => (string) ( $first_data['fias_level'] ?? '' ),
				'house' => (string) ( $first_data['house'] ?? '' ),
				'house_fias_id' => (string) ( $first_data['house_fias_id'] ?? '' ),
				'block' => (string) ( $first_data['block'] ?? '' ),
				'flat' => (string) ( $first_data['flat'] ?? '' ),
				'geo_lat' => (string) ( $first_data['geo_lat'] ?? '' ),
				'geo_lon' => (string) ( $first_data['geo_lon'] ?? '' ),
			);
		}
		$payloads = array();
		foreach ( $suggestions as $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				$debug['rejected_reasons'][] = 'suggestion_not_array';
				continue;
			}
			$data = is_array( $suggestion['data'] ?? null ) ? $suggestion['data'] : array();
			if ( ! $this->suggestion_has_delivery_address( $data ) ) {
				$debug['rejected_reasons'][] = 'missing_delivery_address';
				continue;
			}
			$payload = $this->payload_from_suggestion( $suggestion, $selected_location, $address_line );
			if ( '' === trim( (string) ( $payload['address_1'] ?? '' ) ) ) {
				$debug['rejected_reasons'][] = 'empty_address_1';
				continue;
			}
			$payloads[] = array(
				'label' => (string) ( $suggestion['unrestricted_value'] ?? $suggestion['value'] ?? $payload['full_address'] ?? $payload['address_1'] ),
				'address' => $payload,
			);
		}

		return array( 'suggestions' => $payloads, 'debug' => $debug );
	}

	/**
	 * @param array<string,mixed> $suggestion
	 * @param array<string,mixed> $selected_location
	 * @return array<string,mixed>
	 */
	private function payload_from_suggestion( array $suggestion, array $selected_location, string $address_line ): array {
		$data = is_array( $suggestion['data'] ?? null ) ? $suggestion['data'] : array();
		$street = (string) ( $data['street_with_type'] ?? $data['street'] ?? '' );
		$house = (string) ( $data['house'] ?? '' );
		$block = (string) ( $data['block'] ?? '' );
		$flat = (string) ( $data['flat'] ?? '' );
		$address_1 = $this->address_1_from_parts( $street, $house, $block, $flat, $data );
		$full = (string) ( $suggestion['unrestricted_value'] ?? $suggestion['value'] ?? $address_line );

		return array(
			'country' => (string) ( $data['country_iso_code'] ?? 'RU' ),
			'region' => (string) ( $data['region_with_type'] ?? $data['region'] ?? $selected_location['region_name'] ?? '' ),
			'city' => (string) ( $data['settlement_with_type'] ?? $data['city_with_type'] ?? $data['settlement'] ?? $data['city'] ?? $selected_location['city_value'] ?? $selected_location['city_name'] ?? '' ),
			'postcode' => (string) ( $data['postal_code'] ?? $selected_location['postal_code'] ?? $selected_location['postcode'] ?? '' ),
			'street' => $street,
			'house' => $house,
			'flat' => $flat,
			'address_1' => '' !== $address_1 ? $address_1 : $address_line,
			'address_2' => '',
			'full_address' => $full,
			'fias_id' => (string) ( $data['fias_id'] ?? $data['house_fias_id'] ?? '' ),
			'gar_id' => (string) ( $data['gar_id'] ?? '' ),
			'lat' => $this->coordinate( $data, array( 'geo_lat', 'lat', 'latitude' ) ),
			'lng' => $this->coordinate( $data, array( 'geo_lon', 'lng', 'lon', 'longitude' ) ),
			'normalized' => true,
			'fallback' => false,
			'source' => 'dadata',
			'message' => '',
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function suggestion_has_delivery_address( array $data ): bool {
		$fias_level = (string) ( $data['fias_level'] ?? '' );
		$has_house = '' !== (string) ( $data['house'] ?? '' )
			|| '' !== (string) ( $data['house_fias_id'] ?? '' )
			|| '' !== (string) ( $data['house_kladr_id'] ?? '' )
			|| '' !== (string) ( $data['stead'] ?? '' );
		$has_street = '' !== (string) ( $data['street_with_type'] ?? '' ) || '' !== (string) ( $data['street'] ?? '' );

		return ( $has_house && $has_street )
			|| ( $has_house && in_array( $fias_level, array( '8', '9', '75' ), true ) );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function address_1_from_parts( string $street, string $house, string $block = '', string $flat = '', array $data = array() ): string {
		$parts = array();
		if ( '' !== trim( $street ) ) {
			$parts[] = trim( $street );
		}
		if ( '' !== trim( $house ) ) {
			$house_type = trim( (string) ( $data['house_type'] ?? $data['house_type_full'] ?? 'д' ) );
			$parts[] = trim( $house_type . ' ' . $house );
		}
		if ( '' !== trim( $block ) ) {
			$block_type = trim( (string) ( $data['block_type'] ?? $data['block_type_full'] ?? 'корп' ) );
			$parts[] = trim( $block_type . ' ' . $block );
		}
		if ( '' !== trim( $flat ) ) {
			$flat_type = trim( (string) ( $data['flat_type'] ?? $data['flat_type_full'] ?? 'кв' ) );
			$parts[] = trim( $flat_type . ' ' . $flat );
		}

		return trim( implode( ', ', array_filter( $parts, static fn( string $part ): bool => '' !== $part ) ) );
	}

	/**
	 * @param array<string,mixed> $selected_location
	 * @return array<string,string>
	 */
	private function suggestion_context( array $selected_location ): array {
		$context = array(
			'country_code' => (string) ( $selected_location['country_code'] ?? 'RU' ),
			'location_fias_id' => (string) ( $selected_location['fias_id'] ?? '' ),
			'location_city_fias_id' => (string) ( $selected_location['fias_id'] ?? '' ),
			'location_kladr_id' => (string) ( $selected_location['kladr_id'] ?? '' ),
			'location_city_kladr_id' => (string) ( $selected_location['city_kladr_id'] ?? $selected_location['kladr_id'] ?? '' ),
		);

		return array_filter( $context, static fn( string $value ): bool => '' !== trim( $value ) );
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
