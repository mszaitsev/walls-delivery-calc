<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Locations;

use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Services\LocationCountryIndexService;
use WallsShop\WDC\Locations\Services\LocationDisplayNameFormatter;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class CheckoutLocationAjax {
	public const ACTION = 'wdc_platform_search_locations';
	public const CHECKOUT_ACTION = 'wdc_platform_search_checkout_location';
	public const RESOLVE_ACTION = 'wdc_platform_resolve_checkout_location';
	public const NONCE_ACTION = 'wdc_platform_location_search';

	public function __construct(
		private CheckoutLocationSearch $search,
		private SettingsRepository $settings,
		private ?LocationCountryIndexService $country_index = null
	) {
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_' . self::CHECKOUT_ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::CHECKOUT_ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_' . self::RESOLVE_ACTION, array( $this, 'handle_resolve' ) );
		add_action( 'wp_ajax_nopriv_' . self::RESOLVE_ACTION, array( $this, 'handle_resolve' ) );
	}

	public function handle(): void {
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['nonce'] ) ) : '';
		if ( function_exists( 'wp_verify_nonce' ) && ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->send_error( __( 'Ошибка проверки безопасности.', 'walls-delivery-calc' ), 403 );
			return;
		}

		$query = isset( $_REQUEST['query'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['query'] ) ) : '';
		$force_region_code = isset( $_REQUEST['force_region_code'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['force_region_code'] ) ) : '';
		$country_code = $this->request_country_code();
		if ( ! $this->local_database_available( $country_code ) ) {
			$this->send_success( $this->empty_payload( $this->limit(), $this->region_limit(), $country_code ) );
			return;
		}
		$this->send_success( $this->payload( $query, $force_region_code, $country_code ) );
	}

	public function handle_resolve(): void {
		$nonce = isset( $_REQUEST['nonce'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['nonce'] ) ) : '';
		if ( function_exists( 'wp_verify_nonce' ) && ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->send_error( __( 'Ошибка проверки безопасности.', 'walls-delivery-calc' ), 403 );
			return;
		}

		$region_text = isset( $_REQUEST['region_text'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['region_text'] ) ) : '';
		$city_text = isset( $_REQUEST['city_text'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['city_text'] ) ) : '';
		$country_code = $this->request_country_code();
		if ( ! $this->local_database_available( $country_code ) ) {
			$this->send_success(
				array(
					'status'                   => 'manual_allowed',
					'selected'                 => null,
					'local_database_available' => false,
					'supported_countries'      => $this->supported_countries(),
					'country_code'             => $country_code,
				)
			);
			return;
		}
		$resolved = $this->search->resolve_checkout_fields( $region_text, $city_text, $country_code );
		$location = $resolved['location'] ?? null;
		$this->send_success(
			array(
				'status'                   => (string) ( $resolved['status'] ?? 'not_found' ),
				'selected'                 => $location instanceof Location ? $this->location_payload( $location ) : null,
				'local_database_available' => true,
				'supported_countries'      => $this->supported_countries(),
				'country_code'             => $country_code,
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function payload( string $query, string $force_region_code = '', string $country_code = '' ): array {
		$limit = $this->limit();
		$region_limit = $this->region_limit();
		$country_code = $this->normalize_country_code( $country_code );
		if ( '' !== $country_code && ! $this->local_database_available( $country_code ) ) {
			return $this->empty_payload( $limit, $region_limit, $country_code );
		}
		if ( $this->length( $query ) < 3 && '' === trim( $force_region_code ) && 'мо' !== $this->normalize_short_query( $query ) ) {
			return array_merge( $this->empty_payload( $limit, $region_limit, $country_code ), array( 'local_database_available' => true ) );
		}

		$result = $this->search->search_for_picker( $query, $limit, $region_limit, $force_region_code, $country_code );
		$groups = array();
		foreach ( $result['groups'] as $group ) {
			$groups[] = array(
				'region_key'       => (string) $group['region_key'],
				'region_code'      => (string) $group['region_key'],
				'region_label'     => (string) $group['region_label'],
				'region_sort_name' => (string) $group['region_sort_name'],
				'total_in_region'  => (int) $group['total_in_region'],
				'shown_count'      => (int) $group['shown_count'],
				'has_more'         => (bool) $group['has_more'],
				'expand_query'     => (string) $group['expand_query'],
				'items'            => array_map( array( $this, 'location_payload' ), $group['items'] ),
				'region'           => (string) $group['region_label'],
				'locations'        => array_map( array( $this, 'location_payload' ), $group['items'] ),
			);
		}
		$total = (int) $result['total'];
		$shown_total = (int) ( $result['shown_total'] ?? 0 );
		$has_more_total = (bool) ( $result['has_more_total'] ?? false );

		return array_merge(
			array( 'groups' => $groups, 'total' => $total, 'shown_total' => $shown_total, 'limit' => $limit, 'region_limit' => $region_limit, 'limit_reached' => $has_more_total, 'local_database_available' => true, 'supported_countries' => $this->supported_countries(), 'country_code' => $country_code ),
			$this->search->last_search_meta()
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function empty_payload( int $limit, int $region_limit, string $country_code ): array {
		return array(
			'groups'                   => array(),
			'total'                    => 0,
			'shown_total'              => 0,
			'limit'                    => $limit,
			'region_limit'             => $region_limit,
			'limit_reached'            => false,
			'local_database_available' => false,
			'supported_countries'      => $this->supported_countries(),
			'country_code'             => $country_code,
		);
	}

	private function limit(): int {
		return max( 10, min( 500, $this->settings->get_int( 'checkout_location_search_limit', 100 ) ) );
	}

	private function region_limit(): int {
		return max( 3, min( 50, $this->settings->get_int( 'checkout_location_region_limit', 10 ) ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function location_payload( Location $location ): array {
		$formatter = $this->formatter();
		return array(
			'id'              => $location->id,
			'label'           => $formatter->format_checkout_location_option( $location ),
			'display_name'    => $location->resolved_display_name(),
			'option_label'    => $formatter->format_checkout_location_option( $location ),
			'state_value'     => $formatter->format_checkout_state_value( $location ),
			'city_value'      => $formatter->format_checkout_city_value( $location ),
			'postal_code'     => $location->postal_code,
			'latitude'        => $location->latitude,
			'longitude'       => $location->longitude,
			'lat'             => $location->latitude,
			'lng'             => $location->longitude,
			'fias_id'         => $location->fias_id,
			'country_code'    => $location->country_code,
			'gar_object_id'   => $location->gar_object_id,
			'gar_id'          => $location->gar_id,
			'kladr_id'        => $location->kladr_id,
			'region_code'     => $location->region_code,
			'region_name'     => $location->region_name,
			'region_type'     => $location->region_type,
			'district_name'   => $location->district_name,
			'district_type'   => $location->district_type,
			'city_name'       => $location->city_name,
			'city_type'       => $location->city_type,
			'place_name'      => $location->resolved_place_name(),
			'place_type'      => $location->resolved_place_type(),
			'settlement_name' => $location->settlement_name,
		);
	}

	private function formatter(): LocationDisplayNameFormatter {
		$rules = function_exists( 'get_option' ) ? get_option( 'wdc_location_type_display_rules', array() ) : array();
		return LocationDisplayNameFormatter::from_rules( is_array( $rules ) ? $rules : array() );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function send_success( array $data ): void {
		if ( function_exists( 'wp_send_json_success' ) ) {
			wp_send_json_success( $data );
			return;
		}

		echo wp_json_encode( array( 'success' => true, 'data' => $data ) );
	}

	private function send_error( string $message, int $status_code ): void {
		if ( function_exists( 'wp_send_json_error' ) ) {
			wp_send_json_error( array( 'message' => $message ), $status_code );
			return;
		}

		echo wp_json_encode( array( 'success' => false, 'data' => array( 'message' => $message ) ) );
	}

	private function length( string $value ): int {
		return function_exists( 'mb_strlen' ) ? mb_strlen( trim( $value ), 'UTF-8' ) : strlen( trim( $value ) );
	}

	private function normalize_short_query( string $query ): string {
		$query = str_replace( array( 'Ё', 'ё' ), array( 'Е', 'е' ), $query );
		$query = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $query ), 'UTF-8' ) : strtolower( trim( $query ) );
		return $query;
	}

	private function request_country_code(): string {
		$country_code = isset( $_REQUEST['country_code'] ) ? sanitize_text_field( wp_unslash( (string) $_REQUEST['country_code'] ) ) : '';
		return $this->normalize_country_code( $country_code );
	}

	private function normalize_country_code( string $country_code ): string {
		$country_code = strtoupper( trim( $country_code ) );
		return preg_match( '/^[A-Z]{2}$/', $country_code ) ? $country_code : '';
	}

	private function local_database_available( string $country_code ): bool {
		if ( '' === $country_code ) {
			return true;
		}
		if ( ! $this->country_index instanceof LocationCountryIndexService ) {
			return true;
		}
		return $this->country_index->has_country( $country_code );
	}

	/**
	 * @return array<int,string>
	 */
	private function supported_countries(): array {
		return $this->country_index instanceof LocationCountryIndexService ? $this->country_index->countries() : array();
	}
}
