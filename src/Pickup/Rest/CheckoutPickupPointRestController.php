<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Rest;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;

defined( 'ABSPATH' ) || exit;

final class CheckoutPickupPointRestController {
	private const NAMESPACE = 'wdc/v1';

	public function __construct(
		private RussianPostPickupPointRepository $repository,
		private CheckoutSessionManager $session_manager
	) {
	}

	public function register(): void {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}

		register_rest_route(
			self::NAMESPACE,
			'/checkout/pickup-point',
			array(
				array(
					'methods' => 'POST',
					'callback' => array( $this, 'save' ),
					'permission_callback' => array( $this, 'check_nonce' ),
				),
				array(
					'methods' => 'DELETE',
					'callback' => array( $this, 'delete' ),
					'permission_callback' => array( $this, 'check_nonce' ),
				),
			)
		);
		register_rest_route(
			self::NAMESPACE,
			'/checkout/state',
			array(
				'methods' => 'GET',
				'callback' => array( $this, 'state' ),
				'permission_callback' => array( $this, 'check_nonce' ),
			)
		);
	}

	public function check_nonce( mixed $request ): bool {
		if ( ! function_exists( 'wp_verify_nonce' ) ) {
			return true;
		}

		$nonce = $this->header( $request, 'X-WP-Nonce' );
		if ( '' === $nonce ) {
			$nonce = $this->param( $request, '_wpnonce' );
		}

		return (bool) wp_verify_nonce( $nonce, 'wp_rest' );
	}

	public function save( mixed $request ): mixed {
		$point_id = (int) $this->param( $request, 'point_id' );
		$method_id = $this->normalize_shipping_method_id( $this->param( $request, 'shipping_method_id' ) );
		if ( ! $this->is_supported_shipping_method( $method_id ) ) {
			return $this->error( 'unsupported_shipping_method', 'Pickup point can only be saved for Russian Post domestic pickup.', 400 );
		}

		$row = $this->repository->find_row_by_id( $point_id );
		if ( ! is_array( $row ) || 1 !== (int) ( $row['active'] ?? 0 ) ) {
			return $this->error( 'not_found', 'Pickup point not found.', 404 );
		}

		$selection = $this->selection_from_row( $row );
		$this->session_manager->save_checkout_pickup_point( $selection );
		$this->session_manager->save_pickup_selection(
			array(
				'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
				'rate_id' => $method_id,
				'point_id' => $selection['id'],
				'point_code' => $selection['point_code'],
				'point_type' => $selection['point_type'],
				'point_address' => $selection['address'],
				'point_postcode' => $selection['postcode'],
				'point_comment' => (string) ( $selection['snapshot']['description'] ?? '' ),
				'point_work_time' => (string) ( $selection['snapshot']['work_time'] ?? '' ),
				'lat' => $selection['lat'],
				'lng' => $selection['lng'],
				'snapshot' => $selection['snapshot'],
				'selected_at' => gmdate( 'c' ),
			)
		);

		return $this->response( array( 'pickup_point' => $selection ) );
	}

	public function delete( mixed $request = null ): mixed {
		unset( $request );
		$this->session_manager->clear_pickup_selection();

		return $this->response( array( 'pickup_point' => null ) );
	}

	public function state( mixed $request = null ): mixed {
		unset( $request );
		$point = $this->session_manager->checkout_pickup_point();

		return $this->response( array( 'pickup_point' => array() !== $point ? $point : null ) );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function selection_from_row( array $row ): array {
		$snapshot = array(
			'id' => (int) ( $row['id'] ?? 0 ),
			'point_code' => (string) ( $row['point_code'] ?? '' ),
			'point_type' => (string) ( $row['point_type'] ?? '' ),
			'postcode' => (string) ( $row['postcode'] ?? '' ),
			'address' => (string) ( $row['address'] ?? '' ),
			'city' => (string) ( $row['city_name'] ?? '' ),
			'region' => (string) ( $row['region_name'] ?? '' ),
			'lat' => null !== ( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null,
			'lng' => null !== ( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null,
			'work_time' => (string) ( $row['work_time'] ?? '' ),
			'description' => (string) ( $row['description'] ?? '' ),
		);

		return array(
			'id' => $snapshot['id'],
			'point_code' => $snapshot['point_code'],
			'point_type' => $snapshot['point_type'],
			'postcode' => $snapshot['postcode'],
			'address' => $snapshot['address'],
			'lat' => $snapshot['lat'],
			'lng' => $snapshot['lng'],
			'snapshot' => $snapshot,
		);
	}

	private function is_supported_shipping_method( string $method_id ): bool {
		return RussianPostDomesticSettings::PICKUP_SERVICE_KEY === $method_id
			|| str_starts_with( $method_id, RussianPostDomesticSettings::PICKUP_SERVICE_KEY . ':' );
	}

	private function normalize_shipping_method_id( string $method_id ): string {
		$method_id = preg_replace( '/[^A-Za-z0-9_:\\-]/', '', $method_id ) ?? '';
		$prefix = 'wdc_platform:';
		if ( str_starts_with( $method_id, $prefix ) ) {
			return substr( $method_id, strlen( $prefix ) );
		}

		return $method_id;
	}

	private function param( mixed $request, string $key ): string {
		$value = '';
		if ( is_array( $request ) ) {
			$value = $request[ $key ] ?? '';
		} elseif ( is_object( $request ) && method_exists( $request, 'get_param' ) ) {
			$value = $request->get_param( $key );
		}

		return is_array( $value ) ? '' : sanitize_text_field( wp_unslash( (string) $value ) );
	}

	private function header( mixed $request, string $key ): string {
		if ( is_object( $request ) && method_exists( $request, 'get_header' ) ) {
			return sanitize_text_field( wp_unslash( (string) $request->get_header( $key ) ) );
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
