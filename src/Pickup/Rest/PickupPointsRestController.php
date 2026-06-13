<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Rest;

use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointTypeSettings;
use WallsShop\WDC\Pickup\Cdek\CdekDeliveryPointService;
use WallsShop\WDC\Pickup\Search\PickupAddressSearchService;

defined( 'ABSPATH' ) || exit;

final class PickupPointsRestController {
	private const NAMESPACE = 'wdc/v1';

	public function __construct(
		private RussianPostPickupPointRepository $repository,
		private ?RussianPostPickupPointTypeSettings $type_settings = null,
		private ?PickupAddressSearchService $address_search = null,
		private ?CdekDeliveryPointService $cdek_points = null
	) {
	}

	public function register(): void {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}

		register_rest_route(
			self::NAMESPACE,
			'/points',
			array(
				'methods' => 'GET',
				'callback' => array( $this, 'points' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/points/search',
			array(
				'methods' => 'GET',
				'callback' => array( $this, 'search' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/points/address-search',
			array(
				'methods' => 'GET',
				'callback' => array( $this, 'address_search' ),
				'permission_callback' => array( $this, 'check_nonce' ),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/points/(?P<id>\d+)',
			array(
				'methods' => 'GET',
				'callback' => array( $this, 'detail' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public function check_nonce( mixed $request ): bool|object {
		$nonce = '';
		if ( is_object( $request ) && method_exists( $request, 'get_header' ) ) {
			$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		}
		if ( '' === $nonce && isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
			$nonce = (string) wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] );
		}
		if ( '' !== $nonce && function_exists( 'wp_verify_nonce' ) && wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return true;
		}

		return $this->error( 'wdc_forbidden', 'REST nonce is missing or invalid.', 403 );
	}

	public function points( mixed $request ): mixed {
		$carrier = $this->carrier( $request );
		if ( 'cdek' === $carrier ) {
			return $this->response( $this->cdek_points( $request ) );
		}
		if ( 'russian_post' !== $carrier ) {
			return $this->response( array() );
		}
		$bbox = $this->bbox( $request );
		if ( null === $bbox ) {
			return $this->error( 'invalid_bbox', 'bbox must be minLng,minLat,maxLng,maxLat.' );
		}

		$types = $this->allowed_types( $request );
		if ( array() === $types ) {
			return $this->response( array() );
		}

		$rows = $this->repository->find_rows_by_bbox(
			$bbox['min_lng'],
			$bbox['min_lat'],
			$bbox['max_lng'],
			$bbox['max_lat'],
			array(
				'point_types' => $types,
				'limit' => $this->limit( $request, 500, 1000 ),
			)
		);

		return $this->response( array_map( fn( array $row ): array => $this->summary( $row ), $rows ) );
	}

	public function address_search( mixed $request ): mixed {
		if ( ! $this->address_search instanceof PickupAddressSearchService ) {
			return $this->error( 'address_search_unavailable', 'Address search is unavailable.', 503 );
		}
		$carrier = $this->carrier( $request );
		$include_points = 'russian_post' === $carrier;

		$query = trim( $this->param( $request, 'query' ) );
		if ( '' === $query ) {
			$query = trim( $this->param( $request, 'q' ) );
		}

		$types = $include_points ? $this->allowed_types( $request ) : array();
		if ( array() === $types ) {
			if ( ! $include_points ) {
				return $this->response(
					$this->address_search->search(
						$query,
						array(
							'location_id' => (int) $this->param( $request, 'location_id' ),
							'country_code' => strtoupper( $this->param( $request, 'country_code' ) ?: 'RU' ),
							'include_points' => false,
						)
					)
				);
			}
			return $this->response(
				array(
					'address_search_available' => true,
					'points' => array(),
				)
			);
		}

		return $this->response(
			$this->address_search->search(
				$query,
				array(
					'location_id' => (int) $this->param( $request, 'location_id' ),
					'country_code' => strtoupper( $this->param( $request, 'country_code' ) ?: 'RU' ),
					'point_types' => $types,
					'include_points' => $include_points,
				)
			)
		);
	}

	public function search( mixed $request ): mixed {
		$query = trim( $this->param( $request, 'q' ) );
		$carrier = $this->carrier( $request );
		if ( 'cdek' === $carrier ) {
			return $this->response( $this->filter_cdek_points( $this->cdek_points( $request ), $query ) );
		}
		if ( 'russian_post' !== $carrier ) {
			return $this->response( array() );
		}
		if ( '' === $query ) {
			return $this->response( array() );
		}

		$types = $this->allowed_types( $request );
		if ( array() === $types ) {
			return $this->response( array() );
		}

		$rows = $this->repository->search_point_rows(
			$query,
			array(
				'city' => trim( $this->param( $request, 'city' ) ),
				'point_types' => $types,
				'limit' => $this->limit( $request, 50, 100 ),
			)
		);

		return $this->response( array_map( fn( array $row ): array => $this->summary( $row ), $rows ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function cdek_points( mixed $request ): array {
		if ( ! $this->cdek_points instanceof CdekDeliveryPointService ) {
			return array();
		}
		$city_code = (int) $this->param( $request, 'city_code' );
		$options = array(
			'type' => $this->param( $request, 'type' ) ?: 'ALL',
			'refresh' => in_array( strtolower( $this->param( $request, 'refresh' ) ), array( '1', 'true', 'yes' ), true ),
		);
		if ( $city_code > 0 ) {
			return array_map( array( $this, 'cdek_summary' ), $this->cdek_points->pointsByCityCode( $city_code, $options ) );
		}

		return array_map( array( $this, 'cdek_summary' ), $this->cdek_points->pointsForLocation( $this->location_context( $request ), $options ) );
	}

	/**
	 * @param array<int,array<string,mixed>> $points
	 * @return array<int,array<string,mixed>>
	 */
	private function filter_cdek_points( array $points, string $query ): array {
		if ( '' === $query ) {
			return $points;
		}
		$query = $this->normalize_search_text( $query );

		return array_values(
			array_filter(
				$points,
				fn( array $point ): bool => str_contains(
					$this->normalize_search_text(
						implode(
							' ',
							array(
								(string) ( $point['point_code'] ?? '' ),
								(string) ( $point['point_name'] ?? '' ),
								(string) ( $point['point_address'] ?? $point['address'] ?? '' ),
								(string) ( $point['point_postcode'] ?? $point['postal_code'] ?? '' ),
							)
						)
					),
					$query
				)
			)
		);
	}

	/**
	 * @param array<string,mixed> $point
	 * @return array<string,mixed>
	 */
	private function cdek_summary( array $point ): array {
		$type = strtoupper( (string) ( $point['point_type'] ?? '' ) );
		$snapshot = array(
			'id' => (string) ( $point['id'] ?? ( 'cdek:' . (string) ( $point['point_code'] ?? '' ) ) ),
			'carrier_key' => 'cdek',
			'service_key' => (string) ( $point['service_key'] ?? 'cdek' ),
			'pickup_family' => (string) ( $point['pickup_family'] ?? 'cdek:pickup' ),
			'point_code' => (string) ( $point['point_code'] ?? '' ),
			'point_type' => $type,
			'point_type_label' => (string) ( $point['point_type_label'] ?? ( 'POSTAMAT' === $type ? 'Постамат' : 'Пункт выдачи' ) ),
			'point_title' => (string) ( $point['point_title'] ?? ( 'POSTAMAT' === $type ? 'Постамат СДЭК' : 'Пункт выдачи СДЭК' ) ),
			'marker_type' => (string) ( $point['marker_type'] ?? ( 'POSTAMAT' === $type ? 'postamat' : 'pickup' ) ),
			'point_name' => (string) ( $point['point_name'] ?? '' ),
			'postcode' => (string) ( $point['point_postcode'] ?? $point['postcode'] ?? '' ),
			'address' => (string) ( $point['point_address'] ?? $point['address'] ?? '' ),
			'city' => (string) ( $point['city_name'] ?? $point['city'] ?? '' ),
			'region' => (string) ( $point['region_name'] ?? $point['region'] ?? '' ),
			'lat' => $point['lat'] ?? null,
			'lng' => $point['lng'] ?? null,
			'work_time' => (string) ( $point['work_time'] ?? '' ),
			'description' => (string) ( $point['description'] ?? '' ),
			'storage_notice' => (string) ( $point['storage_notice'] ?? ( 'POSTAMAT' === $type ? 'Срок хранения 3 дня' : '' ) ),
			'cdek_code' => (string) ( $point['cdek_code'] ?? $point['point_code'] ?? '' ),
			'cdek_uuid' => (string) ( $point['cdek_uuid'] ?? '' ),
			'cdek_type' => (string) ( $point['cdek_type'] ?? $type ),
			'cdek_owner_code' => (string) ( $point['cdek_owner_code'] ?? '' ),
			'cdek_nearest_station' => (string) ( $point['cdek_nearest_station'] ?? '' ),
			'cdek_note' => (string) ( $point['cdek_note'] ?? '' ),
		);
		$snapshot['display_code'] = (string) ( $point['display_code'] ?? $snapshot['cdek_code'] );
		$snapshot['display_title'] = (string) ( $point['display_title'] ?? trim( $snapshot['point_title'] . ' ' . $snapshot['display_code'] ) );

		return array(
			'id' => $snapshot['id'],
			'carrier' => 'cdek',
			'carrier_key' => 'cdek',
			'service_key' => $snapshot['service_key'],
			'pickup_family' => $snapshot['pickup_family'],
			'point_code' => $snapshot['point_code'],
			'point_type' => $snapshot['point_type'],
			'point_type_label' => $snapshot['point_type_label'],
			'point_title' => $snapshot['point_title'],
			'card_title' => $snapshot['point_title'],
			'display_code' => $snapshot['cdek_code'],
			'display_title' => trim( $snapshot['point_title'] . ' ' . $snapshot['cdek_code'] ),
			'marker_type' => $snapshot['marker_type'],
			'title' => (string) ( $point['point_name'] ?? '' ),
			'point_name' => $snapshot['point_name'],
			'address' => $snapshot['address'],
			'point_address' => $snapshot['address'],
			'city' => $snapshot['city'],
			'city_name' => $snapshot['city'],
			'region' => $snapshot['region'],
			'region_name' => $snapshot['region'],
			'postal_code' => $snapshot['postcode'],
			'postcode' => $snapshot['postcode'],
			'point_postcode' => $snapshot['postcode'],
			'lat' => $snapshot['lat'],
			'lng' => $snapshot['lng'],
			'work_time' => $snapshot['work_time'],
			'description' => $snapshot['description'],
			'storage_notice' => $snapshot['storage_notice'],
			'raw' => is_array( $point['raw'] ?? null ) ? $point['raw'] : array(),
			'cdek_code' => $snapshot['cdek_code'],
			'cdek_uuid' => $snapshot['cdek_uuid'],
			'cdek_type' => $snapshot['cdek_type'],
			'cdek_owner_code' => $snapshot['cdek_owner_code'],
			'cdek_nearest_station' => $snapshot['cdek_nearest_station'],
			'cdek_note' => $snapshot['cdek_note'],
			'snapshot' => $snapshot,
		);
	}

	public function detail( mixed $request ): mixed {
		$id = (int) $this->param( $request, 'id' );
		$row = $this->repository->find_row_by_id( $id );
		if ( ! is_array( $row ) || 1 !== (int) ( $row['active'] ?? 0 ) ) {
			return $this->error( 'not_found', 'Pickup point not found.', 404 );
		}
		$types = $this->allowed_types( $request );
		if ( ! in_array( strtoupper( trim( (string) ( $row['point_type'] ?? '' ) ) ), $types, true ) ) {
			return $this->error( 'not_found', 'Pickup point not found.', 404 );
		}

		return $this->response( $this->details( $row ) );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function summary( array $row ): array {
		$type = strtoupper( (string) ( $row['point_type'] ?? '' ) );
		$type_label = 'APS' === $type ? 'Почтомат' : 'Пункт выдачи';
		$point_title = 'APS' === $type ? 'Почтомат Почты России' : 'Отделение Почты России';
		$marker_type = 'APS' === $type ? 'postamat' : 'pickup';
		$snapshot = array(
			'id' => (int) ( $row['id'] ?? 0 ),
			'carrier_key' => 'russian_post_domestic',
			'service_key' => 'russian_post_domestic',
			'pickup_family' => 'russian_post_domestic:pickup',
			'point_code' => (string) ( $row['point_code'] ?? '' ),
			'point_type' => $type,
			'point_type_label' => $type_label,
			'point_title' => $point_title,
			'display_code' => (string) ( $row['postcode'] ?? '' ),
			'display_title' => trim( $point_title . ' ' . (string) ( $row['postcode'] ?? '' ) ),
			'marker_type' => $marker_type,
			'point_name' => $this->title( $row ),
			'postcode' => (string) ( $row['postcode'] ?? '' ),
			'address' => (string) ( $row['address'] ?? '' ),
			'city' => (string) ( $row['city_name'] ?? '' ),
			'region' => (string) ( $row['region_name'] ?? '' ),
			'fias_location_guid' => (string) ( $row['fias_location_guid'] ?? '' ),
			'lat' => null !== ( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null,
			'lng' => null !== ( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null,
			'work_time' => (string) ( $row['work_time'] ?? '' ),
			'description' => (string) ( $row['description'] ?? '' ),
		);

		return array(
			'id' => $snapshot['id'],
			'carrier' => 'russian_post',
			'carrier_key' => $snapshot['carrier_key'],
			'service_key' => $snapshot['service_key'],
			'pickup_family' => $snapshot['pickup_family'],
			'point_code' => $snapshot['point_code'],
			'point_type' => $snapshot['point_type'],
			'point_type_label' => $snapshot['point_type_label'],
			'point_title' => $snapshot['point_title'],
			'card_title' => $snapshot['point_title'],
			'display_code' => $snapshot['postcode'],
			'display_title' => trim( $snapshot['point_title'] . ' ' . $snapshot['postcode'] ),
			'marker_type' => $snapshot['marker_type'],
			'title' => $snapshot['point_name'],
			'point_name' => $snapshot['point_name'],
			'address' => $snapshot['address'],
			'point_address' => $snapshot['address'],
			'city' => $snapshot['city'],
			'city_name' => $snapshot['city'],
			'region' => $snapshot['region'],
			'region_name' => $snapshot['region'],
			'postal_code' => $snapshot['postcode'],
			'postcode' => $snapshot['postcode'],
			'point_postcode' => $snapshot['postcode'],
			'fias_location_guid' => $snapshot['fias_location_guid'],
			'lat' => $snapshot['lat'],
			'lng' => $snapshot['lng'],
			'work_time' => $snapshot['work_time'],
			'description' => $snapshot['description'],
			'snapshot' => $snapshot,
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function details( array $row ): array {
		$detail = $this->summary( $row );
		$detail['point_code'] = (string) ( $row['point_code'] ?? '' );
		$detail['description'] = (string) ( $row['description'] ?? '' );

		return $detail;
	}

	/**
	 * @return array{min_lng:float,min_lat:float,max_lng:float,max_lat:float}|null
	 */
	private function bbox( mixed $request ): ?array {
		$parts = array_map( 'trim', explode( ',', $this->param( $request, 'bbox' ) ) );
		if ( 4 !== count( $parts ) ) {
			return null;
		}
		foreach ( $parts as $part ) {
			if ( '' === $part || ! is_numeric( $part ) ) {
				return null;
			}
		}
		$min_lng = (float) $parts[0];
		$min_lat = (float) $parts[1];
		$max_lng = (float) $parts[2];
		$max_lat = (float) $parts[3];
		if ( $min_lng < -180 || $max_lng > 180 || $min_lat < -90 || $max_lat > 90 || $min_lng > $max_lng || $min_lat > $max_lat ) {
			return null;
		}

		return compact( 'min_lng', 'min_lat', 'max_lng', 'max_lat' );
	}

	/**
	 * @return array<int,string>
	 */
	private function types( mixed $request ): array {
		$value = $this->param_raw( $request, 'type' );
		if ( array() === $value ) {
			$value = $this->param_raw( $request, 'type[]' );
		}
		$values = is_array( $value ) ? $value : ( '' !== (string) $value ? array( $value ) : array() );

		return array_values(
			array_filter(
				array_map( static fn( mixed $type ): string => strtoupper( sanitize_key( wp_unslash( (string) $type ) ) ), $values ),
				static fn( string $type ): bool => in_array( $type, array( 'OPS', 'PVZ', 'APS' ), true )
			)
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function allowed_types( mixed $request ): array {
		$requested = $this->types( $request );
		$type_settings = $this->type_settings ?? new RussianPostPickupPointTypeSettings();

		return $type_settings->allowed_types( $requested );
	}

	private function carrier( mixed $request ): string {
		$carrier = sanitize_key( wp_unslash( $this->param( $request, 'carrier' ) ) );

		return '' !== $carrier ? $carrier : 'russian_post';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function location_context( mixed $request ): array {
		$keys = array( 'city_code', 'cdek_city_code', 'country_code', 'region_name', 'state_value', 'city_name', 'city_value', 'settlement_name', 'place_name', 'display_name', 'postal_code', 'postcode', 'fias_id', 'city_fias_id', 'gar_id', 'gar_object_id' );
		$context = array();
		foreach ( $keys as $key ) {
			$value = $this->param( $request, $key );
			if ( '' !== $value ) {
				$context[ $key ] = $value;
			}
		}

		return $context;
	}

	private function normalize_search_text( string $value ): string {
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );

		return trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
	}

	private function limit( mixed $request, int $default, int $max ): int {
		$limit = (int) $this->param( $request, 'limit' );

		return max( 1, min( $max, $limit > 0 ? $limit : $default ) );
	}

	private function title( array $row ): string {
		return trim( (string) ( $row['point_type'] ?? '' ) . ' ' . (string) ( $row['postcode'] ?? '' ) );
	}

	private function param( mixed $request, string $key ): string {
		$value = $this->param_raw( $request, $key );

		return is_array( $value ) ? '' : sanitize_text_field( wp_unslash( (string) $value ) );
	}

	private function param_raw( mixed $request, string $key ): mixed {
		if ( is_array( $request ) ) {
			return $request[ $key ] ?? '';
		}
		if ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			return $request->get_param( $key );
		}

		return '';
	}

	private function response( mixed $data ): mixed {
		return function_exists( 'rest_ensure_response' ) ? rest_ensure_response( $data ) : $data;
	}

	private function error( string $code, string $message, int $status = 400 ): mixed {
		if ( class_exists( '\WP_Error' ) ) {
			return new \WP_Error( $code, $message, array( 'status' => $status ) );
		}

		return array( 'code' => $code, 'message' => $message, 'status' => $status );
	}
}
