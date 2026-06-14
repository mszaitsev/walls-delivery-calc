<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Cdek;

use WallsShop\WDC\Carriers\Cdek\CdekLocationResolver;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionClientInterface;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionSettings;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

final class CdekRecipientAddressPreparationService {
	public const CITY_CODE_ERROR = "Не удалось определить код города СДЭК для адреса получателя.\nПроверьте адрес и повторите обработку.";

	public function __construct(
		private AddressSuggestionSettings $settings,
		private AddressSuggestionClientInterface $suggestions,
		private CdekLocationResolver $locations
	) {
	}

	/**
	 * @param array<string,mixed> $location_context
	 * @return array<string,mixed>
	 */
	public function prepare( object $order, string $original_address, array $location_context, string $service_key = 'cdek' ): array {
		if ( ! $this->settings->enabled() || ! $this->settings->has_any_configured_token() ) {
			return $this->failure( 'Подсказки DaData не настроены. Невозможно проверить адрес СДЭК.', $service_key, $original_address );
		}

		$prepared_input = $this->address_for_dadata( $original_address );
		$result = $this->suggestions->suggest( 'address', $prepared_input['address_for_dadata'], $this->dadata_context( $location_context ) );
		if ( empty( $result['success'] ) ) {
			return $this->failure( 'Адрес не удалось проверить через DaData.', $service_key, $original_address );
		}

		$suggestion = $this->best_suggestion( is_array( $result['suggestions'] ?? null ) ? $result['suggestions'] : array() );
		$payload = is_array( $suggestion['data'] ?? null ) ? $suggestion['data'] : array();
		if ( array() === $payload ) {
			return $this->failure( 'DaData не вернула данные адреса.', $service_key, $original_address );
		}

		$city = $this->clean_city_name( (string) ( $payload['city'] ?? $payload['city_with_type'] ?? $payload['settlement'] ?? $payload['settlement_with_type'] ?? $location_context['city_value'] ?? $location_context['city_name'] ?? '' ) );
		$postal_code = preg_replace( '/\D+/', '', (string) ( $payload['postcode'] ?? $payload['postal_code'] ?? '' ) ) ?: '';
		$delivery_address = $this->delivery_address( $payload, $prepared_input );
		if ( '' === $delivery_address ) {
			return $this->failure( 'DaData не вернула адрес доставки до двери.', $service_key, $original_address );
		}

		$lat = $this->coordinate( $payload, array( 'lat', 'geo_lat', 'latitude' ) );
		$lng = $this->coordinate( $payload, array( 'lng', 'geo_lon', 'longitude' ) );
		if ( null === $lat || null === $lng ) {
			$lat = $this->coordinate( $location_context, array( 'lat', 'latitude' ) );
			$lng = $this->coordinate( $location_context, array( 'lng', 'lon', 'longitude' ) );
		}

		$city_code = $this->known_city_code( $location_context );
		if ( $city_code <= 0 ) {
			$city_code = $this->city_code_from_resolver( $payload, $location_context, $city, $postal_code, $lat, $lng );
		}
		if ( $city_code <= 0 ) {
			return $this->failure( self::CITY_CODE_ERROR, $service_key, $original_address, $payload );
		}

		$display = trim( implode( ', ', array_filter( array( $postal_code, $city, $delivery_address ), static fn( string $part ): bool => '' !== trim( $part ) ) ) );

		return array(
			'success' => true,
			'source' => 'dadata+cdek_location',
			'service_key' => $service_key,
			'original_hash' => $this->original_address_hash( $original_address ),
			'display' => $display,
			'message' => 'Данные для СДЭК корректны',
			'fields' => array(
				'cdek_city_code' => $city_code,
				'cdek_city_name' => $city,
				'cdek_postal_code' => $postal_code,
				'cdek_delivery_address' => $delivery_address,
				'cdek_lat' => null !== $lat ? (string) $lat : '',
				'cdek_lon' => null !== $lng ? (string) $lng : '',
				'postal_code' => $postal_code,
				'region' => (string) ( $payload['region'] ?? '' ),
				'area' => (string) ( $payload['area'] ?? '' ),
				'city' => $city,
				'settlement' => (string) ( $payload['settlement'] ?? '' ),
				'street' => (string) ( $payload['street'] ?? '' ),
				'house' => (string) ( $payload['house'] ?? '' ),
				'block' => (string) ( $payload['block'] ?? '' ),
				'flat' => $this->flat_value( $payload, $prepared_input ),
				'fias_id' => (string) ( $payload['fias_id'] ?? '' ),
				'kladr_id' => (string) ( $payload['kladr_id'] ?? '' ),
				'geo_lat' => null !== $lat ? (string) $lat : '',
				'geo_lon' => null !== $lng ? (string) $lng : '',
			),
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function failure( string $message, string $service_key, string $original_address, array $payload = array() ): array {
		return array(
			'success' => false,
			'source' => 'dadata+cdek_location',
			'service_key' => $service_key,
			'original_hash' => $this->original_address_hash( $original_address ),
			'display' => (string) ( $payload['full_address'] ?? '' ),
			'message' => $message,
			'fields' => array(),
		);
	}

	private function clean_city_name( string $city ): string {
		$city = trim( preg_replace( '/\s+/', ' ', $city ) ?? $city );
		$city = preg_replace( '/^(г|город|пос|п|с|д)\.?\s+/iu', '', $city ) ?? $city;

		return trim( $city );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<int,string> $keys
	 */
	private function coordinate( array $payload, array $keys ): ?float {
		foreach ( $keys as $key ) {
			if ( ! isset( $payload[ $key ] ) || '' === (string) $payload[ $key ] || ! is_numeric( $payload[ $key ] ) ) {
				continue;
			}

			return (float) $payload[ $key ];
		}

		return null;
	}

	/**
	 * @param array<int,mixed> $suggestions
	 * @return array<string,mixed>
	 */
	private function best_suggestion( array $suggestions ): array {
		foreach ( $suggestions as $suggestion ) {
			if ( is_array( $suggestion ) && is_array( $suggestion['data'] ?? null ) && $this->has_house_level( $suggestion['data'] ) ) {
				return $suggestion;
			}
		}

		return is_array( $suggestions[0] ?? null ) ? $suggestions[0] : array();
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function has_house_level( array $data ): bool {
		foreach ( array( 'house', 'house_fias_id', 'house_kladr_id', 'stead', 'flat', 'room', 'room_number', 'premise' ) as $key ) {
			if ( '' !== trim( (string) ( $data[ $key ] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array{address_for_dadata:string,flat:string,flat_type:string} $prepared_input
	 */
	private function delivery_address( array $payload, array $prepared_input = array( 'address_for_dadata' => '', 'flat' => '', 'flat_type' => '' ) ): string {
		$parts = array();
		$street = trim( (string) ( $payload['street_with_type'] ?? $payload['street'] ?? '' ) );
		if ( '' !== $street ) {
			$parts[] = $street;
		}
		$house = trim( (string) ( $payload['house'] ?? '' ) );
		if ( '' !== $house ) {
			$type = trim( (string) ( $payload['house_type'] ?? 'д' ) );
			$parts[] = ( '' !== $type ? $type . ' ' : '' ) . $house;
		}
		$block = trim( (string) ( $payload['block'] ?? '' ) );
		if ( '' !== $block ) {
			$type = trim( (string) ( $payload['block_type'] ?? 'к' ) );
			$parts[] = ( '' !== $type ? $type . ' ' : '' ) . $block;
		}
		$flat = $this->flat_value( $payload, $prepared_input );
		if ( '' !== $flat ) {
			$type = trim( (string) ( $payload['flat_type'] ?? $payload['room_type'] ?? $payload['premise_type'] ?? $prepared_input['flat_type'] ?? 'кв' ) );
			$parts[] = ( '' !== $type ? $type . ' ' : '' ) . $flat;
		}

		return trim( implode( ', ', array_values( array_filter( $parts, static fn( string $part ): bool => '' !== trim( $part ) ) ) ) );
	}

	/**
	 * @return array{address_for_dadata:string,flat:string,flat_type:string}
	 */
	private function address_for_dadata( string $original_address ): array {
		$address = trim( preg_replace( '/\s+/', ' ', $original_address ) ?? $original_address );
		$result = array( 'address_for_dadata' => $address, 'flat' => '', 'flat_type' => '' );
		if ( '' === $address ) {
			return $result;
		}

		if ( preg_match( '/(?:^|,\s*|\s+)(кв\.?|квартира|ап\.?|оф\.?|офис|пом\.?|помещение)\s*([A-Za-zА-Яа-яЁё0-9\/\-]+)\s*$/iu', $address, $matches, PREG_OFFSET_CAPTURE ) ) {
			$type = $this->flat_type( (string) $matches[1][0] );
			$flat = trim( (string) $matches[2][0] );
			$prefix = rtrim( substr( $address, 0, (int) $matches[0][1] ), " \t\n\r\0\x0B," );
			if ( '' !== $prefix && '' !== $flat ) {
				return array( 'address_for_dadata' => $prefix, 'flat' => $flat, 'flat_type' => $type );
			}
		}

		$parts = preg_split( '/\s*,\s*/u', $address ) ?: array();
		if ( count( $parts ) >= 2 ) {
			$tail = trim( (string) end( $parts ) );
			if ( preg_match( '/^[0-9]+[A-Za-zА-Яа-яЁё0-9\/\-]*$/u', $tail ) && ! preg_match( '/\b(г|город|ул|улица|пр|проспект|б-р|бульвар|пер|переулок|ш|шоссе|д|дом)\b\.?/iu', $tail ) ) {
				array_pop( $parts );
				$prefix = trim( implode( ', ', array_filter( array_map( 'trim', $parts ), static fn( string $part ): bool => '' !== $part ) ) );
				if ( '' !== $prefix ) {
					return array( 'address_for_dadata' => $prefix, 'flat' => $tail, 'flat_type' => 'кв' );
				}
			}
		}

		return $result;
	}

	private function flat_type( string $type ): string {
		$type = trim( function_exists( 'mb_strtolower' ) ? mb_strtolower( $type ) : strtolower( $type ) );
		if ( str_starts_with( $type, 'оф' ) ) {
			return 'оф';
		}
		if ( str_starts_with( $type, 'пом' ) ) {
			return 'пом';
		}
		if ( str_starts_with( $type, 'ап' ) ) {
			return 'ап';
		}

		return 'кв';
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array{address_for_dadata:string,flat:string,flat_type:string} $prepared_input
	 */
	private function flat_value( array $payload, array $prepared_input ): string {
		$flat = trim( (string) ( $payload['flat'] ?? $payload['room'] ?? $payload['room_number'] ?? $payload['premise'] ?? '' ) );

		return '' !== $flat ? $flat : trim( (string) ( $prepared_input['flat'] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $location_context
	 */
	private function known_city_code( array $location_context ): int {
		foreach ( array(
			'fields.cdek_city_code',
			'normalized_address.fields.cdek_city_code',
			'cdek_city_code',
			'cdek_to_city_code',
			'city_code',
			'location_code',
			'delivery_calculation_data.api.cdek_to_city_code',
			'rate_meta.location.cdek_to_city_code',
			'request_payload_sanitized.to_location.code',
		) as $path ) {
			$value = $this->path_value( $location_context, $path );
			if ( is_numeric( $value ) && (int) $value > 0 ) {
				return (int) $value;
			}
		}

		return 0;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $location_context
	 */
	private function city_code_from_resolver( array $payload, array $location_context, string $city, string $postal_code, ?float $lat, ?float $lng ): int {
		$request = new QuoteRequest(
			'RU',
			new Address(
				country_code: 'RU',
				region_name: (string) ( $payload['region'] ?? $location_context['region_name'] ?? $location_context['state_value'] ?? '' ),
				city: $city,
				settlement: (string) ( $payload['settlement'] ?? $location_context['settlement'] ?? '' ),
				postcode: $postal_code,
				fias_id: (string) ( $payload['fias_id'] ?? $location_context['fias_id'] ?? '' )
			),
			new Package( array(), Money::from_kopecks( 0 ), Money::from_kopecks( 0 ), 1, 0, 1, 1, 1, 1 ),
			'',
			Money::from_kopecks( 0 ),
			function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' ),
			array(
				'city_name' => $city,
				'selected_location_name' => (string) ( $location_context['city_name'] ?? $location_context['city_value'] ?? $city ),
				'selected_location_region' => (string) ( $location_context['region_name'] ?? $location_context['state_value'] ?? '' ),
				'selected_location_fias_id' => (string) ( $location_context['fias_id'] ?? '' ),
				'lat' => null !== $lat ? (string) $lat : '',
				'lng' => null !== $lng ? (string) $lng : '',
			)
		);
		$result = $this->locations->resolve( $request );
		$code = (int) ( $result['city_code'] ?? 0 );

		return $code > 0 ? $code : 0;
	}

	/**
	 * @param array<string,mixed> $location_context
	 * @return array<string,string>
	 */
	private function dadata_context( array $location_context ): array {
		return array_filter(
			array(
				'country_code' => (string) ( $location_context['country_code'] ?? 'RU' ),
				'location_fias_id' => (string) ( $location_context['fias_id'] ?? '' ),
				'location_city_fias_id' => (string) ( $location_context['fias_id'] ?? '' ),
				'location_kladr_id' => (string) ( $location_context['kladr_id'] ?? '' ),
				'location_city_kladr_id' => (string) ( $location_context['kladr_id'] ?? '' ),
			),
			static fn( string $value ): bool => '' !== trim( $value )
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function path_value( array $data, string $path ): mixed {
		$value = $data;
		foreach ( explode( '.', $path ) as $segment ) {
			if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
				return null;
			}
			$value = $value[ $segment ];
		}

		return $value;
	}

	private function original_address_hash( string $original_address ): string {
		return hash( 'sha256', trim( $original_address ) );
	}
}
