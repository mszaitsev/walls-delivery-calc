<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionService;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryCourierAddressNormalizer {
	public function __construct( private AddressSuggestionService $suggestions ) {}

	/** @param array<string,string> $context @return array<string,mixed> */
	public function normalize( string $original_address, array $context = array() ): array {
		$original_address = trim( $original_address );
		if ( '' === $original_address ) {
			return $this->failure( 'Введите полный адрес доставки.', $original_address );
		}
		$response = $this->suggestions->suggest( 'address', $original_address, array_merge( array( 'country_code' => 'RU' ), $context ) );
		if ( empty( $response['success'] ) ) {
			return $this->failure( 'Не удалось проверить адрес через DaData.', $original_address );
		}
		foreach ( is_array( $response['items'] ?? null ) ? $response['items'] : array() as $item ) {
			if ( is_array( $item ) && ! empty( $item['isDeliverable'] ) ) {
				return $this->from_item( $item, $original_address, $context );
			}
		}

		return $this->failure( 'Не удалось распознать адрес, попробуйте исправить его.', $original_address );
	}

	/** @param array<string,mixed> $item @param array<string,string> $context @return array<string,mixed> */
	private function from_item( array $item, string $original_address, array $context ): array {
		$data = is_array( $item['data'] ?? null ) ? $item['data'] : array();
		$fields = array(
			'selected_location_id' => $this->text( $context['selected_location_id'] ?? '' ),
			'selected_location_fias_id' => $this->text( $context['selected_location_fias_id'] ?? '' ),
			'country' => 'Россия',
			'country_code' => 'RU',
			'region' => $this->first_text( $data['region_with_type'] ?? '', $data['region'] ?? '' ),
			'city' => $this->first_text( $data['settlement_with_type'] ?? '', $data['settlement'] ?? '', $data['city_with_type'] ?? '', $data['city'] ?? '' ),
			'street' => $this->first_text( $data['street_with_type'] ?? '', $data['street'] ?? '' ),
			'street_with_type' => $this->text( $data['street_with_type'] ?? '' ),
			'house' => $this->text( $data['house'] ?? '' ),
			'stead' => $this->text( $data['stead'] ?? '' ),
			'flat' => $this->text( $data['flat'] ?? $data['room'] ?? $data['room_number'] ?? $data['premise'] ?? '' ),
			'postcode' => preg_replace( '/\D+/', '', $this->text( $data['postal_code'] ?? '' ) ) ?: '',
			'geo_lat' => $this->text( $data['geo_lat'] ?? '' ),
			'geo_lon' => $this->text( $data['geo_lon'] ?? $data['geo_lng'] ?? '' ),
			'region_fias_id' => $this->text( $data['region_fias_id'] ?? '' ),
			'city_fias_id' => $this->text( $data['city_fias_id'] ?? '' ),
			'settlement_fias_id' => $this->text( $data['settlement_fias_id'] ?? '' ),
			'street_fias_id' => $this->text( $data['street_fias_id'] ?? '' ),
			'house_fias_id' => $this->text( $data['house_fias_id'] ?? '' ),
			'normalized_address' => $this->text( $item['unrestrictedValue'] ?? $item['value'] ?? $item['label'] ?? '' ),
		);
		$errors = $this->field_errors( $fields );
		return array(
			'success' => array() === $errors,
			'message' => array() === $errors ? 'Адрес для Ozon подтвержден.' : implode( ' ', $errors ),
			'source' => 'dadata+ozon_delivery',
			'fields' => $fields,
			'display' => (string) ( $fields['normalized_address'] ?: $original_address ),
			'original_hash' => hash( 'sha256', $original_address ),
			'service_key' => 'ozon_delivery',
		);
	}

	/** @param array<string,string> $fields @return array<int,string> */
	private function field_errors( array $fields ): array {
		$errors = array();
		foreach ( array( 'postcode' => 'индекс', 'region' => 'регион', 'city' => 'город', 'street' => 'улицу' ) as $key => $label ) {
			if ( '' === trim( (string) ( $fields[ $key ] ?? '' ) ) ) {
				$errors[] = 'DaData не вернула ' . $label . '.';
			}
		}
		if ( '' === trim( (string) ( $fields['house'] ?? $fields['stead'] ?? '' ) ) ) {
			$errors[] = 'DaData не вернула дом.';
		}
		if ( ! $this->valid_coordinates( $fields['geo_lat'] ?? '', $fields['geo_lon'] ?? '' ) ) {
			$errors[] = 'DaData не вернула корректные координаты.';
		}
		if ( ! $this->locality_fias_matches( $fields ) ) {
			$errors[] = 'Адрес относится к другому населённому пункту. Проверьте город и адрес доставки.';
		}

		return $errors;
	}

	/** @param array<string,string> $fields */
	private function locality_fias_matches( array $fields ): bool {
		$selected = $this->normalized_fias( $fields['selected_location_fias_id'] ?? '' );
		if ( '' === $selected ) {
			return true;
		}
		$candidates = array_values(
			array_filter(
				array(
					$this->normalized_fias( $fields['city_fias_id'] ?? '' ),
					$this->normalized_fias( $fields['settlement_fias_id'] ?? '' ),
				),
				static fn( string $value ): bool => '' !== $value
			)
		);
		if ( array() === $candidates ) {
			return true;
		}

		return in_array( $selected, $candidates, true );
	}

	private function normalized_fias( mixed $value ): string {
		$value = $this->text( $value );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}

	private function valid_coordinates( string $lat, string $lon ): bool {
		if ( ! is_numeric( $lat ) || ! is_numeric( $lon ) ) {
			return false;
		}
		$lat_float = (float) $lat;
		$lon_float = (float) $lon;

		return $lat_float >= -90 && $lat_float <= 90 && $lon_float >= -180 && $lon_float <= 180 && ! ( 0.0 === $lat_float && 0.0 === $lon_float );
	}

	/** @return array<string,mixed> */
	private function failure( string $message, string $original_address ): array {
		return array(
			'success' => false,
			'message' => $message,
			'source' => 'dadata+ozon_delivery',
			'fields' => array(),
			'display' => '',
			'original_hash' => hash( 'sha256', trim( $original_address ) ),
			'service_key' => 'ozon_delivery',
		);
	}

	private function text( mixed $value ): string {
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private function first_text( mixed ...$values ): string {
		foreach ( $values as $value ) {
			$value = $this->text( $value );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}
}
