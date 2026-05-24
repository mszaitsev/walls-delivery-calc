<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost;

use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class RussianPostCountryMappingService {
	public function __construct(
		private RussianPostCountryMappingRepository $repository,
		private RussianPostApiClient $client,
		private Logger $logger
	) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function refresh_from_api(): array {
		$stats = array(
			'raw_api_count'    => 0,
			'wc_count'         => 0,
			'matched'          => 0,
			'enabled'          => 0,
			'skipped'          => 0,
			'manual_enabled'   => 0,
			'manual_disabled'  => 0,
			'errors'           => array(),
		);
		$this->logger->info( 'Russian Post country mapping refresh started.' );

		$result = $this->client->fetch_countries();
		if ( empty( $result['success'] ) || ! is_array( $result['raw'] ?? null ) ) {
			$stats['errors'][] = (string) ( $result['error_code'] ?? 'api_error' );
			$this->logger->warning( 'Russian Post country mapping refresh failed.', $stats );

			return $stats;
		}

		$items = $this->extract_items( $result['raw'] );
		$stats['raw_api_count'] = count( $items );
		$rp_by_iso = $this->index_by_iso2( $items );
		$wc_countries = $this->wc_countries();
		$stats['wc_count'] = count( $wc_countries );

		foreach ( $wc_countries as $wc_code => $wc_name ) {
			$wc_code = strtoupper( (string) $wc_code );
			if ( 'RU' === $wc_code ) {
				continue;
			}

			$existing = $this->repository->find_by_wc_country_code( $wc_code );
			$rp = $rp_by_iso[ $wc_code ] ?? null;
			$has_parcel = is_array( $rp ) && isset( $rp['parcel'] ) && is_array( $rp['parcel'] );
			$parcel_block = $has_parcel && ( 1 === ( $rp['parcel']['block'] ?? null ) || '1' === ( $rp['parcel']['block'] ?? null ) );
			$matched = is_array( $rp );
			$api_available = $matched && $has_parcel;
			$manual_mode = $existing?->manual_mode ?? RussianPostCountryMapping::MODE_AUTO;
			$manual_comment = $existing?->manual_comment ?? '';
			$effective = $this->effective_enabled( $manual_mode, $matched, $api_available, $has_parcel, $parcel_block );

			$this->repository->upsert_mapping(
				array(
					'wc_country_code'   => $wc_code,
					'wc_country_name'   => (string) $wc_name,
					'rp_country_id'     => is_array( $rp ) ? $this->first_scalar( $rp, array( 'carrier_country_id', 'country_id', 'country-id', 'countryId', 'id', 'Id', 'code', 'Code', 'country', 'country-to' ) ) : '',
					'rp_country_name'   => is_array( $rp ) ? $this->first_scalar( $rp, array( 'name', 'Name', 'country_name', 'countryName', 'fullname', 'fullName', 'nameRu', 'nameRus', 'russianName' ) ) : '',
					'rp_iso2'           => is_array( $rp ) ? $wc_code : '',
					'has_parcel'        => $has_parcel,
					'parcel_block'      => $parcel_block,
					'api_available'     => $api_available,
					'matched'           => $matched,
					'manual_mode'       => $manual_mode,
					'manual_comment'    => $manual_comment,
					'last_checked_at'   => $this->now(),
					'raw'               => is_array( $rp ) ? $rp : array(),
				)
			);

			if ( $matched ) {
				++$stats['matched'];
			} else {
				++$stats['skipped'];
			}
			if ( $effective ) {
				++$stats['enabled'];
			}
			if ( RussianPostCountryMapping::MODE_ENABLED === $manual_mode ) {
				++$stats['manual_enabled'];
			}
			if ( RussianPostCountryMapping::MODE_DISABLED === $manual_mode ) {
				++$stats['manual_disabled'];
			}
		}

		$this->logger->info( 'Russian Post country mapping refresh completed.', $stats );

		return $stats;
	}

	/**
	 * @param array<int,string> $available_lines
	 * @param array<int,string> $unavailable_lines
	 * @return array<string,mixed>
	 */
	public function preview_bulk_lists( array $available_lines, array $unavailable_lines ): array {
		$available = $this->normalize_lines( $available_lines );
		$unavailable = $this->normalize_lines( $unavailable_lines );
		$duplicates = array_values( array_intersect( array_keys( $available ), array_keys( $unavailable ) ) );
		if ( array() !== $duplicates ) {
			return array( 'success' => false, 'error' => 'duplicate_rows', 'duplicates' => array_values( array_intersect_key( $available, array_flip( $duplicates ) ) ) );
		}

		$index = $this->country_index();
		$available_codes = $this->resolved_codes( $available, $index );
		$unavailable_codes = $this->resolved_codes( $unavailable, $index );
		$duplicate_codes = array_values( array_intersect( $available_codes, $unavailable_codes ) );
		if ( array() !== $duplicate_codes ) {
			return array( 'success' => false, 'error' => 'duplicate_rows', 'duplicates' => $duplicate_codes );
		}
		$preview = array(
			'success' => true,
			'available' => $this->preview_bucket( $available, RussianPostCountryMapping::MODE_ENABLED, $index ),
			'unavailable' => $this->preview_bucket( $unavailable, RussianPostCountryMapping::MODE_DISABLED, $index ),
			'unrecognized' => array(),
		);
		$preview['unrecognized'] = array_values( array_merge( $preview['available']['unrecognized'], $preview['unavailable']['unrecognized'] ) );

		return $preview;
	}

	/**
	 * @param array<string,mixed> $preview
	 * @return array<string,mixed>
	 */
	public function apply_bulk_preview( array $preview ): array {
		if ( empty( $preview['success'] ) ) {
			return array( 'success' => false, 'updated' => 0 );
		}

		$comment = 'изменено вручную ' . ( function_exists( 'wp_date' ) ? wp_date( 'd.m.Y' ) : gmdate( 'd.m.Y' ) );
		$updated = 0;
		foreach ( array( 'available' => RussianPostCountryMapping::MODE_ENABLED, 'unavailable' => RussianPostCountryMapping::MODE_DISABLED ) as $bucket => $mode ) {
			$changes = is_array( $preview[ $bucket ]['changes'] ?? null ) ? $preview[ $bucket ]['changes'] : array();
			foreach ( $changes as $change ) {
				if ( ! is_array( $change ) || empty( $change['wc_country_code'] ) ) {
					continue;
				}
				$this->repository->set_manual_mode( (string) $change['wc_country_code'], $mode, $comment );
				++$updated;
			}
		}

		return array( 'success' => true, 'updated' => $updated, 'manual_comment' => $comment );
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
	 * @param array<int,mixed> $items
	 * @return array<string,array<string,mixed>>
	 */
	private function index_by_iso2( array $items ): array {
		$indexed = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$iso2 = strtoupper( $this->first_code( $item, array( 'iso2', 'alpha2', 'a2', 'code2', 'countryCode2', 'country_code_iso2', 'country_iso2', 'country_code' ), 2 ) );
			if ( '' === $iso2 ) {
				$iso2 = strtoupper( $this->first_code( $item, array( 'code', 'Code' ), 2 ) );
			}
			if ( '' !== $iso2 && 'RU' !== $iso2 ) {
				$indexed[ $iso2 ] = $item;
			}
		}

		return $indexed;
	}

	/**
	 * @return array<string,string>
	 */
	private function wc_countries(): array {
		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->countries ) && is_object( WC()->countries ) && method_exists( WC()->countries, 'get_countries' ) ) {
			$countries = WC()->countries->get_countries();

			return is_array( $countries ) ? array_map( 'strval', $countries ) : array();
		}

		return array();
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
	private function first_code( array $raw, array $keys, int $length ): string {
		foreach ( $keys as $key ) {
			if ( ! isset( $raw[ $key ] ) || ! is_scalar( $raw[ $key ] ) ) {
				continue;
			}
			$value = strtoupper( trim( (string) $raw[ $key ] ) );
			if ( preg_match( '/^[A-Z]{' . $length . '}$/', $value ) ) {
				return $value;
			}
		}

		return '';
	}

	private function effective_enabled( string $mode, bool $matched, bool $api_available, bool $has_parcel, bool $parcel_block ): bool {
		if ( RussianPostCountryMapping::MODE_ENABLED === $mode ) {
			return true;
		}
		if ( RussianPostCountryMapping::MODE_DISABLED === $mode ) {
			return false;
		}

		return $matched && $api_available && $has_parcel && ! $parcel_block;
	}

	/**
	 * @param array<int,string> $lines
	 * @return array<string,string>
	 */
	private function normalize_lines( array $lines ): array {
		$result = array();
		foreach ( $lines as $line ) {
			$value = trim( (string) $line );
			if ( '' === $value ) {
				continue;
			}
			$result[ $this->normalize_key( $value ) ] = $value;
		}

		return $result;
	}

	/**
	 * @return array<string,RussianPostCountryMapping>
	 */
	private function country_index(): array {
		$index = array();
		foreach ( $this->repository->all() as $mapping ) {
			foreach ( array( $mapping->wc_country_code, $mapping->rp_iso2, $mapping->wc_country_name, $mapping->rp_country_name ) as $value ) {
				$key = $this->normalize_key( $value );
				if ( '' !== $key && ! isset( $index[ $key ] ) ) {
					$index[ $key ] = $mapping;
				}
			}
		}

		return $index;
	}

	/**
	 * @param array<string,string> $lines
	 * @param array<string,RussianPostCountryMapping> $index
	 * @return array<int,string>
	 */
	private function resolved_codes( array $lines, array $index ): array {
		$codes = array();
		foreach ( array_keys( $lines ) as $normalized ) {
			$mapping = $index[ $normalized ] ?? null;
			if ( $mapping instanceof RussianPostCountryMapping ) {
				$codes[] = $mapping->wc_country_code;
			}
		}

		return array_values( array_unique( $codes ) );
	}

	/**
	 * @param array<string,string> $lines
	 * @param array<string,RussianPostCountryMapping> $index
	 * @return array{changes:array<int,array<string,string>>,unchanged:array<int,array<string,string>>,unrecognized:array<int,string>}
	 */
	private function preview_bucket( array $lines, string $mode, array $index ): array {
		$result = array( 'changes' => array(), 'unchanged' => array(), 'unrecognized' => array() );
		foreach ( $lines as $normalized => $original ) {
			$mapping = $index[ $normalized ] ?? null;
			if ( ! $mapping instanceof RussianPostCountryMapping ) {
				$result['unrecognized'][] = $original;
				continue;
			}
			$row = array( 'wc_country_code' => $mapping->wc_country_code, 'wc_country_name' => $mapping->wc_country_name, 'input' => $original );
			if ( $mapping->manual_mode === $mode || ( RussianPostCountryMapping::MODE_AUTO === $mapping->manual_mode && $mapping->effective_enabled === ( RussianPostCountryMapping::MODE_ENABLED === $mode ) ) ) {
				$result['unchanged'][] = $row;
			} else {
				$result['changes'][] = $row;
			}
		}

		return $result;
	}

	private function normalize_key( string $value ): string {
		$value = trim( str_replace( array( 'ё', 'Ё' ), array( 'е', 'Е' ), $value ) );
		$value = preg_replace( '/\s+/u', ' ', $value );
		$value = is_string( $value ) ? $value : '';

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
