<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Domain\Address\Address;

defined( 'ABSPATH' ) || exit;

final class OrderStructuredAddress {
	public const SCHEMA_VERSION = 1;
	public const META_KEY = '_wdc_platform_structured_recipient_address';

	/** @param array<string,mixed> $data */
	public function __construct( private array $data ) {
		$this->data = $this->normalize( $data );
	}

	/** @param array<string,mixed> $data */
	public static function from_array( array $data ): ?self {
		$address = new self( $data );

		return $address->usable() ? $address : null;
	}

	public function usable(): bool {
		return '' !== $this->string( 'street' )
			&& '' !== $this->house()
			&& '' !== $this->string( 'city' )
			&& ( '' !== $this->string( 'country' ) || '' !== $this->string( 'country_code' ) )
			&& '' !== $this->string( 'geo_lat' )
			&& '' !== $this->string( 'geo_lon' );
	}

	public function source(): string {
		return $this->string( 'source' );
	}

	public function role(): string {
		return $this->string( 'address_role' );
	}

	public function latitude(): float {
		return (float) str_replace( ',', '.', $this->string( 'geo_lat' ) );
	}

	public function longitude(): float {
		return (float) str_replace( ',', '.', $this->string( 'geo_lon' ) );
	}

	public function house(): string {
		return $this->first( 'house', 'stead' );
	}

	public function to_address(): Address {
		$country_code = $this->string( 'country_code' );
		if ( '' === $country_code && 'Россия' === $this->string( 'country' ) ) {
			$country_code = 'RU';
		}

		return new Address(
			country_code: '' !== $country_code ? $country_code : 'RU',
			country_name: $this->string( 'country' ),
			region_name: $this->string( 'region' ),
			city: $this->string( 'city' ),
			postcode: $this->string( 'postcode' ),
			street: $this->string( 'street' ),
			house: $this->house(),
			apartment: $this->string( 'flat' ),
			raw_address: $this->string( 'normalized_address' ),
			fias_id: $this->first( 'house_fias_id', 'street_fias_id', 'settlement_fias_id', 'city_fias_id' ),
			normalized: true
		);
	}

	/** @return array<string,mixed> */
	public function to_array(): array {
		return $this->data;
	}

	public function string( string $key ): string {
		return is_scalar( $this->data[ $key ] ?? null ) ? trim( (string) $this->data[ $key ] ) : '';
	}

	private function first( string ...$keys ): string {
		foreach ( $keys as $key ) {
			$value = $this->string( $key );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/** @param array<string,mixed> $data @return array<string,mixed> */
	private function normalize( array $data ): array {
		$result = array(
			'schema_version' => self::SCHEMA_VERSION,
		);
		foreach (
			array(
				'source',
				'address_role',
				'selected_location_id',
				'selected_location_fias_id',
				'region_fias_id',
				'city_fias_id',
				'settlement_fias_id',
				'street',
				'street_with_type',
				'street_fias_id',
				'house',
				'stead',
				'house_fias_id',
				'flat',
				'postcode',
				'country',
				'country_code',
				'region',
				'city',
				'geo_lat',
				'geo_lon',
				'normalized_address',
				'confirmed_at',
			) as $key
		) {
			$value = $data[ $key ] ?? '';
			if ( is_scalar( $value ) ) {
				$value = trim( (string) $value );
				if ( '' !== $value ) {
					$result[ $key ] = $value;
				}
			}
		}

		return $result;
	}
}
