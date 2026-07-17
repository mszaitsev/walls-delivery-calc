<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin\Ajax;

use WallsShop\WDC\Admin\AdminMenu;

defined( 'ABSPATH' ) || exit;

final class ShipmentAdminAjaxService {
	public const NONCE_ACTION = 'wdc_shipments_admin';

	public function assert_access(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
	}

	public function resolve_order_from_request(): ?object {
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		return function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
	}

	public function carrier_key_from_request(): string {
		return sanitize_key( wp_unslash( $_POST['carrier_key'] ?? $_POST['shipment_key'] ?? '' ) );
	}

	public function public_shipment_error_message( string $message ): string {
		$message = trim( $message );
		if ( '' === $message ) {
			return __( 'Не удалось подготовить отправление.', 'walls-delivery-calc' );
		}
		$decoded = json_decode( $message, true );
		if ( is_array( $decoded ) ) {
			foreach ( array( 'message', 'error', 'detail' ) as $key ) {
				if ( isset( $decoded[ $key ] ) && '' !== trim( (string) $decoded[ $key ] ) ) {
					return trim( (string) $decoded[ $key ] );
				}
			}
		}
		return $message;
	}
}