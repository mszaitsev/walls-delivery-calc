<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Rest;

use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;

defined( 'ABSPATH' ) || exit;

final class PickupPointsRestController {
	private const NAMESPACE = 'wdc/v1';

	public function __construct( private RussianPostPickupPointRepository $repository ) {
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
			'/points/(?P<id>\d+)',
			array(
				'methods' => 'GET',
				'callback' => array( $this, 'detail' ),
				'permission_callback' => '__return_true',
			)
		);
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

		$rows = $this->repository->find_rows_by_bbox(
			$bbox['min_lng'],
			$bbox['min_lat'],
			$bbox['max_lng'],
			$bbox['max_lat'],
			array(
				'point_types' => $this->types( $request ),
				'limit' => $this->limit( $request, 500, 1000 ),
			)
		);

		return $this->response( array_map( fn( array $row ): array => $this->summary( $row ), $rows ) );
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

		$rows = $this->repository->search_point_rows(
			$query,
			array(
				'city' => trim( $this->param( $request, 'city' ) ),
				'point_types' => $this->types( $request ),
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
			'accepts_cash' => $this->nullable_bool( $row['accepts_cash'] ?? null ),
			'accepts_card' => $this->nullable_bool( $row['accepts_card'] ?? null ),
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function details( array $row ): array {
		$detail = $this->summary( $row );
		$detail['point_code'] = (string) ( $row['point_code'] ?? '' );
		$detail['ecom_options'] = $this->decode_json_field( $row['ecom_options_json'] ?? null );
		$detail['payment'] = array(
			'accepts_cash' => $this->nullable_bool( $row['accepts_cash'] ?? null ),
			'accepts_card' => $this->nullable_bool( $row['accepts_card'] ?? null ),
			'partial_redemption' => $this->nullable_bool( $row['partial_redemption'] ?? null ),
			'return_available' => $this->nullable_bool( $row['return_available'] ?? null ),
			'fitting_available' => $this->nullable_bool( $row['fitting_available'] ?? null ),
			'contents_checking' => $this->nullable_bool( $row['contents_checking'] ?? null ),
			'functionality_checking' => $this->nullable_bool( $row['functionality_checking'] ?? null ),
		);
		$detail['weight_limit_grams'] = null !== ( $row['weight_limit_grams'] ?? null ) ? (int) $row['weight_limit_grams'] : null;

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

	private function carrier( mixed $request ): string {
		$carrier = sanitize_key( wp_unslash( $this->param( $request, 'carrier' ) ) );

		return '' !== $carrier ? $carrier : 'russian_post';
	}

	private function limit( mixed $request, int $default, int $max ): int {
		$limit = (int) $this->param( $request, 'limit' );

		return max( 1, min( $max, $limit > 0 ? $limit : $default ) );
	}

	private function title( array $row ): string {
		$title = trim( (string) ( $row['brand_name'] ?? '' ) );
		if ( '' !== $title ) {
			return $title;
		}

		return trim( (string) ( $row['point_type'] ?? '' ) . ' ' . (string) ( $row['postcode'] ?? '' ) );
	}

	private function nullable_bool( mixed $value ): ?bool {
		return null === $value || '' === $value ? null : (bool) (int) $value;
	}

	private function decode_json_field( mixed $value ): array {
		$decoded = json_decode( (string) $value, true );

		return is_array( $decoded ) ? $decoded : array();
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
