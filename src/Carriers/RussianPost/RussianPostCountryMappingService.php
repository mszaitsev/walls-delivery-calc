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
			'indexed_by_name_count' => 0,
			'wc_count'         => 0,
			'matched'          => 0,
			'enabled'          => 0,
			'skipped'          => 0,
			'manual_enabled'   => 0,
			'manual_disabled'  => 0,
			'sample_api_keys'  => array(),
			'unmatched_api_countries' => array(),
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
		$stats['sample_api_keys'] = isset( $items[0] ) && is_array( $items[0] ) ? array_keys( $items[0] ) : array();
		$rp_by_name = $this->index_by_normalized_name( $items );
		$stats['indexed_by_name_count'] = count( $rp_by_name );
		if ( $stats['raw_api_count'] > 0 && 0 === $stats['indexed_by_name_count'] ) {
			$stats['errors'][] = 'country_name_index_empty';
		}
		$wc_countries = $this->wc_countries();
		$stats['wc_count'] = count( $wc_countries );
		$used_api_keys = array();

		foreach ( $wc_countries as $wc_code => $wc_name ) {
			$wc_code = strtoupper( (string) $wc_code );
			if ( 'RU' === $wc_code ) {
				continue;
			}

			$existing = $this->repository->find_by_wc_country_code( $wc_code );
			$match = $this->match_country( $wc_code, (string) $wc_name, $rp_by_name );
			$rp = $match['row'];
			$match_source = $match['source'];
			if ( ! is_array( $rp ) && $existing instanceof RussianPostCountryMapping && 'manual' === $existing->match_source && $existing->matched ) {
				$rp = $existing->raw;
				$match_source = 'manual';
			}
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
					'rp_country_id'     => is_array( $rp ) ? $this->first_scalar( $rp, array( 'id', 'Id' ) ) : '',
					'rp_country_name'   => is_array( $rp ) ? $this->first_scalar( $rp, array( 'name', 'Name', 'country_name', 'countryName', 'fullname', 'fullName', 'nameRu', 'nameRus', 'russianName' ) ) : '',
					'rp_iso2'           => '',
					'has_parcel'        => $has_parcel,
					'parcel_block'      => $parcel_block,
					'api_available'     => $api_available,
					'matched'           => $matched,
					'match_source'      => $match_source,
					'manual_mode'       => $manual_mode,
					'manual_comment'    => $manual_comment,
					'last_checked_at'   => $this->now(),
					'raw'               => is_array( $rp ) ? $rp : array(),
				)
			);

			if ( $matched ) {
				++$stats['matched'];
				$used_api_keys[] = $this->api_row_key( $rp );
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
		$stats['unmatched_api_countries'] = $this->unmatched_api_payload( $items, $used_api_keys );

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
	 * @return array<int,array<string,string>>
	 */
	public function manual_mapping_options(): array {
		$options = array();
		foreach ( $this->repository->all() as $mapping ) {
			if ( $mapping->matched || 'manual' === $mapping->match_source ) {
				continue;
			}
			$options[] = array(
				'wc_country_code' => $mapping->wc_country_code,
				'wc_country_name' => $mapping->wc_country_name,
			);
		}

		return $options;
	}

	/**
	 * @param array<int,array<string,mixed>> $payload
	 * @param array<string,string>          $selections
	 * @return array<string,mixed>
	 */
	public function apply_manual_mappings( array $payload, array $selections ): array {
		$by_key = array();
		foreach ( $payload as $row ) {
			if ( is_array( $row ) && isset( $row['key'] ) ) {
				$by_key[ (string) $row['key'] ] = $row;
			}
		}

		$comment = 'сопоставлено вручную ' . ( function_exists( 'wp_date' ) ? wp_date( 'd.m.Y' ) : gmdate( 'd.m.Y' ) );
		$updated = 0;
		$used_wc_codes = array();
		foreach ( $selections as $key => $wc_code ) {
			$wc_code = strtoupper( trim( (string) $wc_code ) );
			if ( '' === $wc_code || isset( $used_wc_codes[ $wc_code ] ) || ! isset( $by_key[ $key ] ) ) {
				continue;
			}
			$mapping = $this->repository->find_by_wc_country_code( $wc_code );
			if ( ! $mapping instanceof RussianPostCountryMapping || $mapping->matched || 'manual' === $mapping->match_source ) {
				continue;
			}
			$this->repository->set_manual_mapping( $wc_code, $by_key[ $key ], $comment );
			$used_wc_codes[ $wc_code ] = true;
			++$updated;
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
	private function index_by_normalized_name( array $items ): array {
		$indexed = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$name = $this->first_scalar( $item, array( 'name', 'Name', 'country_name', 'countryName', 'fullname', 'fullName', 'nameRu', 'nameRus', 'russianName' ) );
			$key = $this->normalize_key( $name );
			if ( '' !== $key ) {
				$indexed[ $key ] = $item;
			}
		}

		return $indexed;
	}

	/**
	 * @param array<int,mixed>  $items
	 * @param array<int,string> $used_keys
	 * @return array<int,array<string,mixed>>
	 */
	private function unmatched_api_payload( array $items, array $used_keys ): array {
		$used = array_fill_keys( array_filter( $used_keys ), true );
		$payload = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || $this->is_russia_row( $item ) ) {
				continue;
			}
			$key = $this->api_row_key( $item );
			if ( '' === $key || isset( $used[ $key ] ) ) {
				continue;
			}
			$payload[] = $this->manual_payload_row( $item );
		}

		return $payload;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function manual_payload_row( array $row ): array {
		$has_parcel = isset( $row['parcel'] ) && is_array( $row['parcel'] );
		$parcel_block = $has_parcel && ( 1 === ( $row['parcel']['block'] ?? null ) || '1' === ( $row['parcel']['block'] ?? null ) );
		$id = $this->first_scalar( $row, array( 'id', 'Id' ) );
		$name = $this->first_scalar( $row, array( 'name', 'Name', 'country_name', 'countryName', 'fullname', 'fullName', 'nameRu', 'nameRus', 'russianName' ) );

		return array(
			'key'             => sha1( $this->api_row_key( $row ) ),
			'rp_country_id'   => $id,
			'rp_country_name' => $name,
			'has_parcel'      => $has_parcel,
			'parcel_block'    => $parcel_block,
			'raw'             => $row,
		);
	}

	/**
	 * @param array<string,array<string,mixed>> $rp_by_name
	 * @return array{row:?array<string,mixed>,source:string}
	 */
	private function match_country( string $wc_code, string $wc_name, array $rp_by_name ): array {
		$name_key = $this->normalize_key( $wc_name );
		if ( isset( $rp_by_name[ $name_key ] ) ) {
			return array( 'row' => $rp_by_name[ $name_key ], 'source' => 'name' );
		}

		foreach ( $this->aliases_for( $wc_code, $wc_name ) as $alias ) {
			$key = $this->normalize_key( $alias );
			if ( isset( $rp_by_name[ $key ] ) ) {
				return array( 'row' => $rp_by_name[ $key ], 'source' => 'alias' );
			}
		}

		return array( 'row' => null, 'source' => 'none' );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function api_row_key( array $row ): string {
		$id = $this->first_scalar( $row, array( 'id', 'Id' ) );
		if ( '' !== $id ) {
			return 'id:' . $id;
		}

		$name = $this->first_scalar( $row, array( 'name', 'Name', 'country_name', 'countryName', 'fullname', 'fullName', 'nameRu', 'nameRus', 'russianName' ) );

		return '' === $name ? '' : 'name:' . $this->normalize_key( $name );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function is_russia_row( array $row ): bool {
		$id = $this->first_scalar( $row, array( 'id', 'Id' ) );
		$name = $this->normalize_key( $this->first_scalar( $row, array( 'name', 'Name', 'country_name', 'countryName', 'fullname', 'fullName', 'nameRu', 'nameRus', 'russianName' ) ) );

		return '643' === $id || 'РОССИЯ' === $name || 'РОССИЙСКАЯ ФЕДЕРАЦИЯ' === $name;
	}

	/**
	 * @return array<int,string>
	 */
	private function aliases_for( string $wc_code, string $wc_name ): array {
		$keys = array( strtoupper( trim( $wc_code ) ), $this->normalize_key( $wc_name ) );
		$aliases = array();
		foreach ( $keys as $key ) {
			foreach ( $this->country_aliases()[ $key ] ?? array() as $alias ) {
				$aliases[] = $alias;
			}
		}

		return array_values( array_unique( $aliases ) );
	}

	/**
	 * @return array<string,array<int,string>>
	 */
	private function country_aliases(): array {
		return array(
			'US' => array( 'СОЕДИНЕННЫЕ ШТАТЫ АМЕРИКИ', 'США' ),
			'UNITED STATES' => array( 'СОЕДИНЕННЫЕ ШТАТЫ АМЕРИКИ', 'США' ),
			'США' => array( 'СОЕДИНЕННЫЕ ШТАТЫ АМЕРИКИ' ),
			'СОЕДИНЕННЫЕ ШТАТЫ' => array( 'СОЕДИНЕННЫЕ ШТАТЫ АМЕРИКИ' ),
			'GB' => array( 'ВЕЛИКОБРИТАНИЯ' ),
			'UNITED KINGDOM' => array( 'ВЕЛИКОБРИТАНИЯ' ),
			'ВЕЛИКОБРИТАНИЯ' => array( 'ВЕЛИКОБРИТАНИЯ' ),
			'KR' => array( 'РЕСПУБЛИКА КОРЕЯ' ),
			'SOUTH KOREA' => array( 'РЕСПУБЛИКА КОРЕЯ' ),
			'ЮЖНАЯ КОРЕЯ' => array( 'РЕСПУБЛИКА КОРЕЯ' ),
			'KP' => array( 'КНДР' ),
			'NORTH KOREA' => array( 'КНДР' ),
			'СЕВЕРНАЯ КОРЕЯ' => array( 'КНДР' ),
			'CZ' => array( 'ЧЕХИЯ' ),
			'CZECHIA' => array( 'ЧЕХИЯ' ),
			'ЧЕХИЯ' => array( 'ЧЕХИЯ' ),
			'TR' => array( 'ТУРЦИЯ' ),
			'TURKEY' => array( 'ТУРЦИЯ' ),
			'ТУРЦИЯ' => array( 'ТУРЦИЯ' ),
			'AE' => array( 'ОБЪЕДИНЕННЫЕ АРАБСКИЕ ЭМИРАТЫ' ),
			'UNITED ARAB EMIRATES' => array( 'ОБЪЕДИНЕННЫЕ АРАБСКИЕ ЭМИРАТЫ' ),
			'ОАЭ' => array( 'ОБЪЕДИНЕННЫЕ АРАБСКИЕ ЭМИРАТЫ' ),
		);
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
			foreach ( array( $mapping->wc_country_code, $mapping->wc_country_name, $mapping->rp_country_name, $mapping->rp_country_id ) as $value ) {
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
		$value = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', $value );
		$value = preg_replace( '/\s+/u', ' ', $value );
		$value = is_string( $value ) ? $value : '';

		return function_exists( 'mb_strtoupper' ) ? mb_strtoupper( $value, 'UTF-8' ) : strtoupper( $value );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
