<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Pickup;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryPickupPointNormalizer {
	public const TYPE_PICKUP_POINT = 'pickup_point';

	/**
	 * @return array{points:array<int,array<string,mixed>>,fetched_count:int,skipped_invalid:int}
	 */
	public function normalize_response( mixed $response ): array {
		$rows = $this->extract_rows( $response );
		$points = array();
		$skipped = 0;
		foreach ( $rows as $row ) {
			$point = $this->normalize( $row );
			if ( null === $point ) {
				++$skipped;
				continue;
			}
			$points[] = $point;
		}

		return array(
			'points' => $points,
			'fetched_count' => count( $rows ),
			'skipped_invalid' => $skipped,
		);
	}

	/** @return array<string,mixed>|null */
	public function normalize( mixed $raw ): ?array {
		$row = $this->to_array( $raw );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$platform_station_id = $this->first( $row['platform_station_id'] ?? null, $row['id'] ?? null, $row['station_id'] ?? null, $row['code'] ?? null );
		if ( '' === $platform_station_id ) {
			return null;
		}
		$operator = is_array( $row['operator'] ?? null ) ? $row['operator'] : array();
		$address = is_array( $row['address'] ?? null ) ? $row['address'] : array();
		$location = is_array( $row['location'] ?? null ) ? $row['location'] : array();
		$position = is_array( $row['position'] ?? null ) ? $row['position'] : array();
		$coordinates = is_array( $row['coordinates'] ?? null ) ? $row['coordinates'] : ( is_array( $row['geo'] ?? null ) ? $row['geo'] : array() );

		return array(
			'platform_station_id' => $platform_station_id,
			'operator_id' => $this->first( $row['operator_id'] ?? null, $operator['id'] ?? null ),
			'operator_name' => $this->first( $row['operator_name'] ?? null, $operator['name'] ?? null ),
			'name' => $this->first( $row['name'] ?? null, $row['title'] ?? null, $platform_station_id ),
			'type' => $this->first( $row['type'] ?? null, self::TYPE_PICKUP_POINT ),
			'address' => $this->address_to_string( $address, $row['address'] ?? null, $row['full_address'] ?? null, $row['formatted_address'] ?? null ),
			'geo_id' => $this->first( $row['geo_id'] ?? null, $address['geo_id'] ?? null, $address['geoId'] ?? null, $location['geo_id'] ?? null ),
			'country_code' => $this->first( $row['country_code'] ?? null, $address['country_code'] ?? null, $address['countryCode'] ?? null, 'RU' ),
			'region_name' => $this->first( $row['region_name'] ?? null, $address['region_name'] ?? null, $address['regionName'] ?? null, $location['region_name'] ?? null ),
			'city_name' => $this->first( $row['city_name'] ?? null, $address['city_name'] ?? null, $address['cityName'] ?? null, $address['locality'] ?? null, $location['city_name'] ?? null ),
			'latitude' => $this->first( $row['latitude'] ?? null, $row['lat'] ?? null, $coordinates['latitude'] ?? null, $coordinates['lat'] ?? null, $position['latitude'] ?? null ),
			'longitude' => $this->first( $row['longitude'] ?? null, $row['lon'] ?? null, $row['lng'] ?? null, $coordinates['longitude'] ?? null, $coordinates['lon'] ?? null, $coordinates['lng'] ?? null, $position['longitude'] ?? null ),
			'schedule' => $this->json_or_string( $row['schedule'] ?? null ),
			'payment_methods' => $this->json_or_string( $row['payment_methods'] ?? $row['paymentMethods'] ?? null ),
			'available_for_dropoff' => $this->bool( $row['available_for_dropoff'] ?? $row['availableForDropoff'] ?? false ),
			'available_for_c2c_dropoff' => $this->bool( $row['available_for_c2c_dropoff'] ?? $row['availableForC2cDropoff'] ?? false ),
			'is_yandex_branded' => $this->bool( $row['is_yandex_branded'] ?? $row['isYandexBranded'] ?? $row['yandex_branded'] ?? false ),
			'raw_json' => $this->json( $row ),
		);
	}

	/** @return array<int,mixed> */
	private function extract_rows( mixed $response ): array {
		$data = $this->to_array( $response );
		if ( is_array( $data['body'] ?? null ) ) {
			$data = $data['body'];
		}
		foreach ( array( 'points', 'pickup_points', 'pickupPoints', 'items', 'results' ) as $key ) {
			if ( isset( $data[ $key ] ) ) {
				return $this->listify( $data[ $key ] );
			}
		}

		return $this->listify( $data );
	}

	/** @return array<int,mixed> */
	private function listify( mixed $value ): array {
		if ( null === $value || '' === $value ) {
			return array();
		}
		if ( ! is_array( $value ) ) {
			return array( $value );
		}
		if ( array_is_list( $value ) ) {
			return $value;
		}

		return array( $value );
	}

	private function to_array( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->to_array( $item );
			}
		}

		return $value;
	}

	private function first( mixed ...$values ): string {
		foreach ( $values as $value ) {
			if ( null === $value || is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/** @param array<string,mixed> $address */
	private function address_to_string( array $address, mixed ...$fallbacks ): string {
		foreach ( $fallbacks as $fallback ) {
			if ( ! is_array( $fallback ) && ! is_object( $fallback ) && '' !== trim( (string) $fallback ) ) {
				return trim( (string) $fallback );
			}
		}
		$parts = array();
		foreach ( array( 'country', 'region_name', 'regionName', 'city_name', 'cityName', 'locality', 'street', 'house', 'building', 'full_address', 'formatted' ) as $key ) {
			$value = trim( (string) ( $address[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}

		return implode( ', ', array_unique( $parts ) );
	}

	private function bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value > 0;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'y' ), true );
	}

	private function json_or_string( mixed $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		if ( is_array( $value ) || is_object( $value ) ) {
			return $this->json( $value );
		}

		return trim( (string) $value );
	}

	private function json( mixed $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : null;
	}
}
