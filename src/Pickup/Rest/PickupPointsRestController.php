<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Rest;

use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointTypeSettings;
use WallsShop\WDC\Pickup\Search\PickupAddressSearchService;

defined( 'ABSPATH' ) || exit;

final class PickupPointsRestController {
	private const NAMESPACE = 'wdc/v1';

	public function __construct(
		private RussianPostPickupPointRepository $repository,
		private ?RussianPostPickupPointTypeSettings $type_settings = null,
		private ?PickupAddressSearchService $address_search = null
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
		if ( 'russian_post' !== $carrier ) {
			return $this->response( array() );
		}

		$query = trim( $this->param( $request, 'query' ) );
		if ( '' === $query ) {
			$query = trim( $this->param( $request, 'q' ) );
		}

		$types = $this->allowed_types( $request );
		if ( array() === $types ) {
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
				)
			)
		);
	}

	public function search( mixed $request ): mixed {
		$query = trim( $this->param( $request, 'q' ) );
		$carrier = $this->carrier( $request );
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
		return array(
			'id' => (int) ( $row['id'] ?? 0 ),
			'carrier' => 'russian_post',
			'point_type' => (string) ( $row['point_type'] ?? '' ),
			'title' => $this->title( $row ),
			'address' => (string) ( $row['address'] ?? '' ),
			'city' => (string) ( $row['city_name'] ?? '' ),
			'region' => (string) ( $row['region_name'] ?? '' ),
			'postal_code' => (string) ( $row['postcode'] ?? '' ),
			'lat' => null !== ( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null,
			'lng' => null !== ( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null,
			'work_time' => (string) ( $row['work_time'] ?? '' ),
			'description' => (string) ( $row['description'] ?? '' ),
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
