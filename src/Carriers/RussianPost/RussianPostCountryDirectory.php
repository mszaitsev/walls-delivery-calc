<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost;

use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class RussianPostCountryDirectory {
	private const CACHE_KEY = 'wdc_platform_russian_post_countries';
	private const CACHE_TTL = 604800;

	public function __construct(
		private RussianPostApiClient $client,
		private Logger $logger,
		private ?RussianPostCountryMappingRepository $repository = null,
		private ?RussianPostCountryMappingService $service = null,
		private ?RussianPostSettings $settings = null
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_country( string $country_code ): array {
		$country_code = strtoupper( trim( $country_code ) );
		if ( '' === $country_code || 'RU' === $country_code ) {
			return array();
		}

		$repository = $this->repository();
		if ( 0 === $repository->count_all() && $this->auto_refresh_if_empty() ) {
			$this->service()?->refresh_from_api();
		}

		$mapping = $repository->find_by_wc_country_code( $country_code );
		if ( ! $mapping instanceof RussianPostCountryMapping || ! $mapping->effective_enabled ) {
			return array();
		}

		return array(
			'carrier_country_id' => $mapping->rp_country_id,
			'name'               => $mapping->rp_country_name,
			'iso2'               => $mapping->wc_country_code,
			'effective_enabled'  => true,
			'availability'       => array(
				'has_parcel'     => $mapping->has_parcel,
				'parcel_block'   => $mapping->parcel_block,
				'api_available'  => $mapping->api_available,
				'manual_mode'    => $mapping->manual_mode,
			),
			'raw'                => $mapping->raw,
		);
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	public function countries(): array {
		$repository = $this->repository();
		if ( 0 === $repository->count_all() && $this->auto_refresh_if_empty() ) {
			$this->service()?->refresh_from_api();
		}

		$countries = array();
		foreach ( $repository->all() as $mapping ) {
			if ( $mapping->effective_enabled && 'RU' !== $mapping->wc_country_code ) {
				$countries[ $mapping->wc_country_code ] = array(
					'carrier_country_id' => $mapping->rp_country_id,
					'name'               => $mapping->rp_country_name,
					'iso2'               => $mapping->wc_country_code,
					'effective_enabled'  => true,
					'raw'                => $mapping->raw,
				);
			}
		}
		if ( array() !== $countries ) {
			return $countries;
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<int,mixed>
	 */
	private function extract_items( array $raw ): array {
		if ( isset( $raw['country'] ) && is_array( $raw['country'] ) ) {
			return array_values( $raw['country'] );
		}

		return array_is_list( $raw ) ? array_values( $raw ) : array();
	}

	/**
	 * @param array<string,mixed> $raw
	 * @return array<string,mixed>
	 */
	private function normalize( array $raw ): array {
		$iso2 = strtoupper( $this->first_code( $raw, array( 'iso2', 'alpha2', 'a2', 'code2', 'countryCode2', 'country_code_iso2', 'country_iso2', 'country_code' ), 2 ) );
		if ( '' === $iso2 ) {
			$iso2 = strtoupper( $this->first_code( $raw, array( 'code', 'Code' ), 2 ) );
		}

		$carrier_country_id = $this->first_scalar( $raw, array( 'carrier_country_id', 'country_id', 'country-id', 'countryId', 'id', 'Id', 'code', 'Code', 'country', 'country-to' ) );
		if ( '' !== $carrier_country_id && ! is_numeric( $carrier_country_id ) ) {
			$carrier_country_id = $this->first_numeric_scalar( $raw, array( 'id', 'Id', 'country_id', 'countryId', 'code', 'Code' ) );
		}

		$name = $this->first_scalar( $raw, array( 'name', 'Name', 'country_name', 'countryName', 'fullname', 'fullName', 'nameRu', 'nameRus', 'russianName' ) );
		$has_parcel = isset( $raw['parcel'] ) && is_array( $raw['parcel'] );
		$block = $has_parcel && array_key_exists( 'block', $raw['parcel'] ) ? $raw['parcel']['block'] : null;
		$is_ru = 'RU' === $iso2 || in_array( $this->normalize_name( $name ), array( 'РОССИЯ', 'РОССИЙСКАЯ ФЕДЕРАЦИЯ', 'РФ', 'RUSSIA', 'RUSSIAN FEDERATION' ), true );

		return array(
			'carrier_country_id' => $carrier_country_id,
			'name'               => '' !== $name ? $name : $iso2,
			'iso2'               => $iso2,
			'effective_enabled'  => ! $is_ru && '' !== $iso2 && '' !== $carrier_country_id && $has_parcel,
			'availability'       => array(
				'has_parcel' => $has_parcel,
				'parcel_block' => $block,
				'requires_check' => 1 === $block || '1' === $block,
			),
			'raw'                => $raw,
		);
	}

	/**
	 * @param array<string,mixed> $raw
	 * @param array<int,string>  $keys
	 */
	private function first_scalar( array $raw, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
				return trim( (string) $raw[ $key ] );
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $raw
	 * @param array<int,string>  $keys
	 */
	private function first_numeric_scalar( array $raw, array $keys ): string {
		foreach ( $keys as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) && is_numeric( $raw[ $key ] ) ) {
				return trim( (string) $raw[ $key ] );
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $raw
	 * @param array<int,string>  $keys
	 */
	private function first_code( array $raw, array $keys, int $length ): string {
		foreach ( $keys as $key ) {
			if ( isset( $raw[ $key ] ) && is_scalar( $raw[ $key ] ) ) {
				$value = strtoupper( trim( (string) $raw[ $key ] ) );
				if ( preg_match( '/^[A-Z]{' . $length . '}$/', $value ) ) {
					return $value;
				}
			}
		}

		return '';
	}

	private function normalize_name( string $name ): string {
		$name = str_replace( array( 'ё', 'Ё' ), array( 'е', 'Е' ), trim( $name ) );
		$name = preg_replace( '/\s+/u', ' ', $name );
		$name = is_string( $name ) ? $name : '';

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $name, 'UTF-8' ) : strtoupper( $name );
	}

	private function cache_get(): mixed {
		return function_exists( 'get_transient' ) ? get_transient( self::CACHE_KEY ) : false;
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function cache_set( array $payload ): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( self::CACHE_KEY, $payload, self::CACHE_TTL );
		}
	}

	private function repository(): RussianPostCountryMappingRepository {
		if ( ! $this->repository instanceof RussianPostCountryMappingRepository ) {
			$this->repository = new RussianPostCountryMappingRepository();
		}

		return $this->repository;
	}

	private function service(): ?RussianPostCountryMappingService {
		return $this->service;
	}

	private function auto_refresh_if_empty(): bool {
		if ( ! $this->settings instanceof RussianPostSettings ) {
			return false;
		}

		return ! empty( $this->settings->all()['auto_refresh_countries_if_empty'] );
	}
}
