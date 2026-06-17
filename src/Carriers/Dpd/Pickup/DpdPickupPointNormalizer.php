<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Pickup;

defined( 'ABSPATH' ) || exit;

final class DpdPickupPointNormalizer {
	public const TYPE_PARCEL_SHOP = 'parcel_shop';
	public const TYPE_TERMINAL_SELF_DELIVERY = 'terminal_self_delivery';
	public const SOURCE_PARCEL_SHOPS = 'getParcelShops';
	public const SOURCE_TERMINALS_SELF_DELIVERY = 'getTerminalsSelfDelivery2';
	private DpdPickupPointScheduleFormatter $schedule_formatter;

	public function __construct( ?DpdPickupPointScheduleFormatter $schedule_formatter = null ) {
		$this->schedule_formatter = $schedule_formatter ?? new DpdPickupPointScheduleFormatter();
	}

	/**
	 * @return array{points:array<int,array<string,mixed>>,fetched_count:int,skipped_invalid:int}
	 */
	public function normalize_response( mixed $response, string $source, string $type ): array {
		$rows = $this->extract_rows( $response, $source );
		$points = array();
		$skipped = 0;
		foreach ( $rows as $row ) {
			$point = $this->normalize_row( $row, $source, $type );
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

	/**
	 * @return array<int,mixed>
	 */
	private function extract_rows( mixed $response, string $source ): array {
		$data = $this->to_array( $response );
		$return = $data['return'] ?? $data;
		if ( self::SOURCE_PARCEL_SHOPS === $source ) {
			return $this->listify( $return['parcelShop'] ?? $return );
		}
		if ( self::SOURCE_TERMINALS_SELF_DELIVERY === $source ) {
			return $this->listify( $return['terminal'] ?? $return );
		}

		return $this->listify( $return );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function normalize_row( mixed $row, string $source, string $type ): ?array {
		$row = $this->to_array( $row );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$address = is_array( $row['address'] ?? null ) ? $row['address'] : array();
		$coordinates = is_array( $row['geoCoordinates'] ?? null ) ? $row['geoCoordinates'] : ( is_array( $row['coordinates'] ?? null ) ? $row['coordinates'] : array() );
		$terminal_code = $this->first( $row['terminalCode'] ?? null, $row['code'] ?? null, $row['terminal_code'] ?? null );
		if ( '' === $terminal_code ) {
			return null;
		}

		return array(
			'terminal_code' => $terminal_code,
			'type' => $type,
			'country_code' => $this->first( $address['countryCode'] ?? null, $row['countryCode'] ?? null, 'RU' ),
			'region_code' => $this->first( $address['regionCode'] ?? null, $row['regionCode'] ?? null ),
			'region_name' => $this->first( $address['regionName'] ?? null, $row['regionName'] ?? null ),
			'city_id' => $this->first( $address['cityId'] ?? null, $row['cityId'] ?? null ),
			'city_code' => $this->first( $address['cityCode'] ?? null, $row['cityCode'] ?? null ),
			'city_name' => $this->first( $address['cityName'] ?? null, $row['cityName'] ?? null ),
			'address' => $this->address_to_string( $address, $row['address'] ?? null ),
			'name' => $this->first( $row['terminalName'] ?? null, $row['brand'] ?? null, $row['name'] ?? null, $terminal_code ),
			'latitude' => $this->first( $coordinates['latitude'] ?? null, $row['latitude'] ?? null, $row['lat'] ?? null ),
			'longitude' => $this->first( $coordinates['longitude'] ?? null, $row['longitude'] ?? null, $row['lng'] ?? null, $row['lon'] ?? null ),
			'schedule' => $this->schedule_formatter->format( $row['schedule'] ?? null ),
			'raw_json' => $this->json( $row ),
			'is_active' => 1,
			'source' => $source,
		);
	}

	/**
	 * @return array<int,mixed>
	 */
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
			if ( null === $value ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $address
	 */
	private function address_to_string( array $address, mixed $fallback ): string {
		if ( array() === $address && ! is_array( $fallback ) && ! is_object( $fallback ) ) {
			return trim( (string) $fallback );
		}
		$parts = array();
		foreach ( array( 'cityName', 'streetAbbr', 'street', 'houseNo', 'building', 'structure', 'ownership', 'descript' ) as $key ) {
			$value = trim( (string) ( $address[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				$parts[] = $value;
			}
		}

		return implode( ', ', array_unique( $parts ) );
	}

	private function json( mixed $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : null;
	}
}
