<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\ValueObjects;

defined( 'ABSPATH' ) || exit;

final class Location {
	public function __construct(
		public readonly ?int $id = null,
		public readonly string $fias_id = '',
		public readonly string $gar_id = '',
		public readonly string $country_code = 'RU',
		public readonly string $region_name = '',
		public readonly string $region_code = '',
		public readonly string $city_name = '',
		public readonly string $settlement_name = '',
		public readonly string $settlement_type = '',
		public readonly string $display_name = '',
		public readonly string $postcode = '',
		public readonly ?float $latitude = null,
		public readonly ?float $longitude = null,
		public readonly bool $active = true,
		public readonly int $gar_object_id = 0,
		public readonly string $kladr_id = '',
		public readonly string $region_type = '',
		public readonly string $city_type = '',
		public readonly string $city_fias_id = '',
		public readonly string $city_kladr_id = '',
		public readonly string $place_name = '',
		public readonly string $place_type = '',
		public readonly int $place_level = 0,
		public readonly string $okato = '',
		public readonly string $oktmo = '',
		public readonly string $postal_code = ''
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
			'gar_object_id'   => $this->gar_object_id,
			'kladr_id'        => $this->kladr_id,
			'country_code'    => $this->country_code,
			'region_name'     => $this->region_name,
			'region_type'     => $this->region_type,
			'region_code'     => $this->region_code,
			'city_name'       => $this->city_name,
			'city_type'       => $this->city_type,
			'city_fias_id'    => $this->city_fias_id,
			'city_kladr_id'   => $this->city_kladr_id,
			'settlement_name' => $this->settlement_name,
			'settlement_type' => $this->settlement_type,
			'place_name'      => $this->resolved_place_name(),
			'place_type'      => $this->resolved_place_type(),
			'place_level'     => $this->place_level,
			'display_name'    => $this->resolved_display_name(),
			'postcode'        => $this->postcode,
			'postal_code'     => $this->postal_code,
			'okato'           => $this->okato,
			'oktmo'           => $this->oktmo,
			'latitude'        => $this->latitude,
			'longitude'       => $this->longitude,
			'active'          => $this->active,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public static function from_array( array $data ): self {
		$place_name      = (string) ( $data['place_name'] ?? $data['settlement_name'] ?? $data['city_name'] ?? '' );
		$place_type      = (string) ( $data['place_type'] ?? $data['settlement_type'] ?? '' );
		$postal_code     = (string) ( $data['postal_code'] ?? $data['postcode'] ?? '' );
		$gar_object_id   = self::int_value( $data['gar_object_id'] ?? $data['gar_id'] ?? 0 );
		$settlement_name = (string) ( $data['settlement_name'] ?? $place_name );
		$settlement_type = (string) ( $data['settlement_type'] ?? $place_type );

		return new self(
			isset( $data['id'] ) && '' !== (string) $data['id'] ? (int) $data['id'] : null,
			(string) ( $data['fias_id'] ?? '' ),
			(string) ( $data['gar_id'] ?? ( $gar_object_id > 0 ? (string) $gar_object_id : '' ) ),
			(string) ( $data['country_code'] ?? 'RU' ),
			(string) ( $data['region_name'] ?? '' ),
			(string) ( $data['region_code'] ?? '' ),
			(string) ( $data['city_name'] ?? '' ),
			$settlement_name,
			$settlement_type,
			(string) ( $data['display_name'] ?? '' ),
			(string) ( $data['postcode'] ?? $postal_code ),
			isset( $data['latitude'] ) && '' !== (string) $data['latitude'] ? (float) $data['latitude'] : null,
			isset( $data['longitude'] ) && '' !== (string) $data['longitude'] ? (float) $data['longitude'] : null,
			(bool) ( $data['active'] ?? true ),
			$gar_object_id,
			(string) ( $data['kladr_id'] ?? '' ),
			(string) ( $data['region_type'] ?? '' ),
			(string) ( $data['city_type'] ?? '' ),
			(string) ( $data['city_fias_id'] ?? '' ),
			(string) ( $data['city_kladr_id'] ?? '' ),
			$place_name,
			$place_type,
			self::int_value( $data['place_level'] ?? 0 ),
			(string) ( $data['okato'] ?? '' ),
			(string) ( $data['oktmo'] ?? '' ),
			$postal_code
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

		if ( '' === trim( $this->region_code ) ) {
			$errors[] = 'region_code is required';
		}

		if ( 0 >= $this->gar_object_id && '' === trim( $this->gar_id ) ) {
			$errors[] = 'gar_object_id is required';
		}

		if ( '' === trim( $this->fias_id ) ) {
			$errors[] = 'fias_id is required';
		}

		if ( '' === trim( $this->resolved_place_name() ) ) {
			$errors[] = 'place_name is required';
		}

		if ( '' === trim( $this->resolved_display_name() ) ) {
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
					$this->resolved_display_name(),
					$this->resolved_place_name(),
					$this->resolved_place_type(),
					$this->city_name,
					$this->city_type,
					$this->region_name,
					$this->region_code,
					$this->fias_id,
					(string) $this->gar_object_id,
					$this->gar_id,
					$this->kladr_id,
				)
			)
		);
	}

	public function has_coordinates(): bool {
		return null !== $this->latitude && null !== $this->longitude;
	}

	public function resolved_place_name(): string {
		if ( '' !== trim( $this->place_name ) ) {
			return trim( $this->place_name );
		}

		if ( '' !== trim( $this->settlement_name ) ) {
			return trim( $this->settlement_name );
		}

		return trim( $this->city_name );
	}

	public function resolved_place_type(): string {
		return '' !== trim( $this->place_type ) ? trim( $this->place_type ) : trim( $this->settlement_type );
	}

	public function resolved_display_name(): string {
		if ( '' !== trim( $this->display_name ) ) {
			return trim( $this->display_name );
		}

		$name = $this->resolved_place_name();
		if ( '' === $name ) {
			return '';
		}

		return '' !== trim( $this->region_name ) ? sprintf( '%s, %s', $name, trim( $this->region_name ) ) : $name;
	}

	public static function normalize_search_text( string $value ): string {
		$value = str_replace( 'Ё', 'Е', $value );
		$value = str_replace( 'ё', 'е', $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = preg_replace( '/\s+/u', ' ', trim( $value ) );

		return is_string( $value ) ? $value : '';
	}

	private static function int_value( mixed $value ): int {
		return is_numeric( $value ) ? (int) $value : 0;
	}
}
