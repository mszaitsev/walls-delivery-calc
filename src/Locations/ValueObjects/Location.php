<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\ValueObjects;

defined( 'ABSPATH' ) || exit;

final class Location {
	public function __construct(
		public readonly ?int $id = null,
		public readonly string $fias_id = '',
		public readonly string $gar_id = '',
		public readonly string $country_code = '',
		public readonly string $region_name = '',
		public readonly string $region_code = '',
		public readonly string $city_name = '',
		public readonly string $settlement_name = '',
		public readonly string $settlement_type = '',
		public readonly string $display_name = '',
		public readonly string $postcode = '',
		public readonly ?float $latitude = null,
		public readonly ?float $longitude = null,
		public readonly bool $active = true
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function to_array(): array {
		return array(
			'id'              => $this->id,
			'fias_id'         => $this->fias_id,
			'gar_id'          => $this->gar_id,
			'country_code'    => $this->country_code,
			'region_name'     => $this->region_name,
			'region_code'     => $this->region_code,
			'city_name'       => $this->city_name,
			'settlement_name' => $this->settlement_name,
			'settlement_type' => $this->settlement_type,
			'display_name'    => $this->display_name,
			'postcode'        => $this->postcode,
			'latitude'        => $this->latitude,
			'longitude'       => $this->longitude,
			'active'          => $this->active,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		return new self(
			isset( $data['id'] ) && '' !== (string) $data['id'] ? (int) $data['id'] : null,
			(string) ( $data['fias_id'] ?? '' ),
			(string) ( $data['gar_id'] ?? '' ),
			(string) ( $data['country_code'] ?? '' ),
			(string) ( $data['region_name'] ?? '' ),
			(string) ( $data['region_code'] ?? '' ),
			(string) ( $data['city_name'] ?? '' ),
			(string) ( $data['settlement_name'] ?? '' ),
			(string) ( $data['settlement_type'] ?? '' ),
			(string) ( $data['display_name'] ?? '' ),
			(string) ( $data['postcode'] ?? '' ),
			isset( $data['latitude'] ) && '' !== (string) $data['latitude'] ? (float) $data['latitude'] : null,
			isset( $data['longitude'] ) && '' !== (string) $data['longitude'] ? (float) $data['longitude'] : null,
			(bool) ( $data['active'] ?? true )
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function validate(): array {
		$errors = array();

		if ( '' === trim( $this->country_code ) ) {
			$errors[] = 'country_code is required';
		}

		if ( '' === trim( $this->city_name ) && '' === trim( $this->settlement_name ) ) {
			$errors[] = 'city_name or settlement_name is required';
		}

		if ( '' === trim( $this->display_name ) ) {
			$errors[] = 'display_name is required';
		}

		if ( null !== $this->latitude && ( $this->latitude < -90.0 || $this->latitude > 90.0 ) ) {
			$errors[] = 'latitude must be between -90 and 90';
		}

		if ( null !== $this->longitude && ( $this->longitude < -180.0 || $this->longitude > 180.0 ) ) {
			$errors[] = 'longitude must be between -180 and 180';
		}

		return $errors;
	}

	public function get_searchable_text(): string {
		return self::normalize_search_text(
			implode(
				' ',
				array(
					$this->country_code,
					$this->region_name,
					$this->region_code,
					$this->city_name,
					$this->settlement_name,
					$this->settlement_type,
					$this->display_name,
					$this->postcode,
					$this->fias_id,
					$this->gar_id,
				)
			)
		);
	}

	public function has_coordinates(): bool {
		return null !== $this->latitude && null !== $this->longitude;
	}

	public static function normalize_search_text( string $value ): string {
		$value = str_replace( 'Ё', 'Е', $value );
		$value = str_replace( 'ё', 'е', $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = preg_replace( '/\s+/u', ' ', trim( $value ) );

		return is_string( $value ) ? $value : '';
	}
}
