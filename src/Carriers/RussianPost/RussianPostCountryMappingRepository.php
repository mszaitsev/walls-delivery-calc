<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost;

defined( 'ABSPATH' ) || exit;

final class RussianPostCountryMappingRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;
		$this->wpdb = $db ?? $wpdb;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function upsert_mapping( array $data ): void {
		$code = strtoupper( sanitize_text_field( (string) ( $data['wc_country_code'] ?? '' ) ) );
		if ( '' === $code ) {
			return;
		}

		$existing = $this->find_by_wc_country_code( $code );
		$now      = $this->now();
		$manual_mode = RussianPostCountryMapping::normalize_mode( (string) ( $data['manual_mode'] ?? ( $existing?->manual_mode ?? RussianPostCountryMapping::MODE_AUTO ) ) );
		$manual_comment = array_key_exists( 'manual_comment', $data ) ? sanitize_text_field( (string) $data['manual_comment'] ) : ( $existing?->manual_comment ?? '' );
		$row = array(
			'wc_country_code'   => $code,
			'wc_country_name'   => sanitize_text_field( (string) ( $data['wc_country_name'] ?? '' ) ),
			'rp_country_id'     => sanitize_text_field( (string) ( $data['rp_country_id'] ?? '' ) ),
			'rp_country_name'   => sanitize_text_field( (string) ( $data['rp_country_name'] ?? '' ) ),
			'rp_iso2'           => strtoupper( sanitize_text_field( (string) ( $data['rp_iso2'] ?? '' ) ) ),
			'has_parcel'        => ! empty( $data['has_parcel'] ) ? 1 : 0,
			'parcel_block'      => ! empty( $data['parcel_block'] ) ? 1 : 0,
			'api_available'     => ! empty( $data['api_available'] ) ? 1 : 0,
			'matched'           => ! empty( $data['matched'] ) ? 1 : 0,
			'match_source'      => RussianPostCountryMapping::normalize_match_source( (string) ( $data['match_source'] ?? 'none' ) ),
			'manual_mode'       => $manual_mode,
			'effective_enabled' => $this->effective_enabled( $manual_mode, ! empty( $data['matched'] ), ! empty( $data['api_available'] ), ! empty( $data['has_parcel'] ), ! empty( $data['parcel_block'] ) ) ? 1 : 0,
			'last_checked_at'   => (string) ( $data['last_checked_at'] ?? $now ),
			'manual_comment'    => $manual_comment,
			'raw_json'          => $this->encode_raw( $data['raw'] ?? array() ),
			'updated_at'        => $now,
		);

		if ( $existing instanceof RussianPostCountryMapping ) {
			$this->wpdb->update( $this->table(), $row, array( 'wc_country_code' => $code ), $this->formats( false ), array( '%s' ) );
			return;
		}

		$row['created_at'] = $now;
		$this->wpdb->insert( $this->table(), $row, $this->formats( true ) );
	}

	/**
	 * @return array<int,RussianPostCountryMapping>
	 */
	public function all(): array {
		$rows = $this->wpdb->get_results( "SELECT * FROM {$this->table()} ORDER BY wc_country_name ASC, wc_country_code ASC", ARRAY_A );

		return $this->rows_to_mappings( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @return array{items:array<int,RussianPostCountryMapping>,total:int,page:int,per_page:int}
	 */
	public function list( string $filter = 'all', string $search = '', int $page = 1, int $per_page = 20 ): array {
		$page     = max( 1, $page );
		$per_page = in_array( $per_page, array( 20, 50, 100 ), true ) ? $per_page : 20;
		$where    = $this->where_sql( $filter, $search );
		$offset   = ( $page - 1 ) * $per_page;
		$total    = (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()} {$where}" );
		$rows     = $this->wpdb->get_results( $this->wpdb->prepare( "SELECT * FROM {$this->table()} {$where} ORDER BY wc_country_name ASC, wc_country_code ASC LIMIT %d OFFSET %d", $per_page, $offset ), ARRAY_A );

		return array( 'items' => $this->rows_to_mappings( is_array( $rows ) ? $rows : array() ), 'total' => $total, 'page' => $page, 'per_page' => $per_page );
	}

	public function find_by_wc_country_code( string $code ): ?RussianPostCountryMapping {
		$code = strtoupper( trim( $code ) );
		if ( '' === $code ) {
			return null;
		}

		$row = $this->wpdb->get_row( $this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE wc_country_code = %s LIMIT 1", $code ), ARRAY_A );

		return is_array( $row ) ? RussianPostCountryMapping::from_row( $row ) : null;
	}

	/**
	 * @return array<string,int|string>
	 */
	public function count_stats(): array {
		$row = $this->wpdb->get_row(
			"SELECT COUNT(*) AS total,
				SUM(matched) AS matched,
				SUM(api_available) AS api_available,
				SUM(effective_enabled) AS enabled,
				SUM(CASE WHEN matched = 0 THEN 1 ELSE 0 END) AS skipped,
				SUM(CASE WHEN manual_mode = 'enabled' THEN 1 ELSE 0 END) AS manual_enabled,
				SUM(CASE WHEN manual_mode = 'disabled' THEN 1 ELSE 0 END) AS manual_disabled,
				MAX(last_checked_at) AS last_checked_at
			FROM {$this->table()}",
			ARRAY_A
		);

		$row = is_array( $row ) ? $row : array();
		return array(
			'total'           => (int) ( $row['total'] ?? 0 ),
			'matched'         => (int) ( $row['matched'] ?? 0 ),
			'api_available'   => (int) ( $row['api_available'] ?? 0 ),
			'enabled'         => (int) ( $row['enabled'] ?? 0 ),
			'skipped'         => (int) ( $row['skipped'] ?? 0 ),
			'manual_enabled'  => (int) ( $row['manual_enabled'] ?? 0 ),
			'manual_disabled' => (int) ( $row['manual_disabled'] ?? 0 ),
			'last_checked_at' => (string) ( $row['last_checked_at'] ?? '' ),
		);
	}

	public function set_manual_mode( string $wcCode, string $mode, string $comment = '' ): void {
		$mapping = $this->find_by_wc_country_code( $wcCode );
		if ( ! $mapping instanceof RussianPostCountryMapping ) {
			return;
		}

		$mode = RussianPostCountryMapping::normalize_mode( $mode );
		$comment = RussianPostCountryMapping::MODE_AUTO === $mode ? '' : $comment;
		$this->wpdb->update(
			$this->table(),
			array(
				'manual_mode'       => $mode,
				'manual_comment'    => sanitize_text_field( $comment ),
				'effective_enabled' => $this->effective_enabled( $mode, $mapping->matched, $mapping->api_available, $mapping->has_parcel, $mapping->parcel_block ) ? 1 : 0,
				'updated_at'        => $this->now(),
			),
			array( 'wc_country_code' => strtoupper( $wcCode ) ),
			array( '%s', '%s', '%d', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function set_manual_mapping( string $wcCode, array $data, string $comment = '' ): void {
		$mapping = $this->find_by_wc_country_code( $wcCode );
		if ( ! $mapping instanceof RussianPostCountryMapping ) {
			return;
		}

		$has_parcel = ! empty( $data['has_parcel'] );
		$parcel_block = ! empty( $data['parcel_block'] );
		$api_available = $has_parcel;
		$this->wpdb->update(
			$this->table(),
			array(
				'rp_country_id'     => sanitize_text_field( (string) ( $data['rp_country_id'] ?? '' ) ),
				'rp_country_name'   => sanitize_text_field( (string) ( $data['rp_country_name'] ?? '' ) ),
				'rp_iso2'           => '',
				'has_parcel'        => $has_parcel ? 1 : 0,
				'parcel_block'      => $parcel_block ? 1 : 0,
				'api_available'     => $api_available ? 1 : 0,
				'matched'           => 1,
				'match_source'      => 'manual',
				'effective_enabled' => $this->effective_enabled( $mapping->manual_mode, true, $api_available, $has_parcel, $parcel_block ) ? 1 : 0,
				'last_checked_at'   => $this->now(),
				'manual_comment'    => sanitize_text_field( $comment ),
				'raw_json'          => $this->encode_raw( $data['raw'] ?? array() ),
				'updated_at'        => $this->now(),
			),
			array( 'wc_country_code' => strtoupper( $wcCode ) ),
			array( '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s' ),
			array( '%s' )
		);
	}

	/**
	 * @param array<int,string> $wcCodes
	 */
	public function bulk_set_manual_mode( array $wcCodes, string $mode, string $comment = '' ): void {
		foreach ( $wcCodes as $code ) {
			$this->set_manual_mode( $code, $mode, $comment );
		}
	}

	public function delete_all(): void {
		$this->wpdb->query( "DELETE FROM {$this->table()}" );
	}

	/**
	 * @return array<int,string>
	 */
	public function enabled_country_codes(): array {
		$rows = $this->wpdb->get_col( "SELECT wc_country_code FROM {$this->table()} WHERE effective_enabled = 1 ORDER BY wc_country_code ASC" );

		return is_array( $rows ) ? array_map( 'strval', $rows ) : array();
	}

	public function count_all(): int {
		return (int) $this->wpdb->get_var( "SELECT COUNT(*) FROM {$this->table()}" );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,RussianPostCountryMapping>
	 */
	private function rows_to_mappings( array $rows ): array {
		return array_map( static fn( array $row ): RussianPostCountryMapping => RussianPostCountryMapping::from_row( $row ), $rows );
	}

	private function where_sql( string $filter, string $search ): string {
		$parts = array();
		$filter_map = array(
			'enabled'         => 'effective_enabled = 1',
			'disabled'        => 'effective_enabled = 0',
			'matched'         => 'matched = 1',
			'unmatched'       => 'matched = 0',
			'manual_mapping'  => "match_source = 'manual'",
			'manual_enabled'  => "manual_mode = 'enabled'",
			'manual_disabled' => "manual_mode = 'disabled'",
			'auto'            => "manual_mode = 'auto'",
		);
		if ( isset( $filter_map[ $filter ] ) ) {
			$parts[] = $filter_map[ $filter ];
		}
		if ( '' !== trim( $search ) ) {
			$like = '%' . $this->wpdb->esc_like( trim( $search ) ) . '%';
			$parts[] = $this->wpdb->prepare( '(wc_country_code LIKE %s OR wc_country_name LIKE %s OR rp_country_name LIKE %s OR rp_country_id LIKE %s)', $like, $like, $like, $like );
		}

		return array() === $parts ? '' : 'WHERE ' . implode( ' AND ', $parts );
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

	private function encode_raw( mixed $raw ): string {
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $raw ) : json_encode( $raw );

		return is_string( $json ) ? $json : '';
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * @return array<int,string>
	 */
	private function formats( bool $include_created ): array {
		$formats = array( '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%s' );
		if ( $include_created ) {
			$formats[] = '%s';
		}

		return $formats;
	}

	private function table(): string {
		return $this->wpdb->prefix . 'wdc_russian_post_country_mappings';
	}
}
