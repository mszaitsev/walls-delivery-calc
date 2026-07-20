<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin\Ajax;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Domain\Common\MoneyParser;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostService;

defined( 'ABSPATH' ) || exit;

final class ShipmentActualCostAjaxController {
	public function __construct(
		private ShipmentActualCostService $actual_costs,
		private ShipmentAdminCarrierUiPayloadBuilder $payloads
	) {
	}

	public function handle_save(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( ShipmentAdminAjaxService::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		$order = $this->order();
		$carrier_key = sanitize_key( wp_unslash( $_POST['shipment_key'] ?? '' ) );
		$amount = $this->parse_amount_kopecks( wp_unslash( $_POST['actual_cost'] ?? '' ) );
		try {
			$shipment = $this->actual_costs->manual_set( $order, $carrier_key, $amount );
			wp_send_json_success(
				array_merge(
					$this->payloads->carrier_ui_payload( $order, $carrier_key, $shipment ),
					array( 'message' => __( 'Фактическая стоимость отправления сохранена.', 'walls-delivery-calc' ) )
				)
			);
		} catch ( \InvalidArgumentException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
		}
	}

	public function handle_clear(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( ShipmentAdminAjaxService::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		$order = $this->order();
		$carrier_key = sanitize_key( wp_unslash( $_POST['shipment_key'] ?? '' ) );
		try {
			$shipment = $this->actual_costs->clear( $order, $carrier_key );
			wp_send_json_success(
				array_merge(
					$this->payloads->carrier_ui_payload( $order, $carrier_key, $shipment ),
					array( 'message' => __( 'Фактическая стоимость отправления очищена.', 'walls-delivery-calc' ) )
				)
			);
		} catch ( \InvalidArgumentException $exception ) {
			wp_send_json_error( array( 'message' => $exception->getMessage() ), 400 );
		}
	}

	private function order(): object {
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ) ), 404 );
		}

		return $order;
	}

	private function parse_amount_kopecks( mixed $value ): int {
		$raw = trim( str_replace( ',', '.', (string) $value ) );
		if ( '' === $raw ) {
			throw new \InvalidArgumentException( __( 'Укажите фактическую стоимость отправления.', 'walls-delivery-calc' ) );
		}
		if ( 1 !== preg_match( '/^\d+(?:\.\d{1,2})?$/', $raw ) ) {
			throw new \InvalidArgumentException( __( 'Стоимость должна быть положительным числом с максимум двумя знаками после запятой.', 'walls-delivery-calc' ) );
		}
		$amount = MoneyParser::rubles_to_kopecks( $raw );
		if ( $amount <= 0 ) {
			throw new \InvalidArgumentException( __( 'Фактическая стоимость должна быть больше нуля.', 'walls-delivery-calc' ) );
		}

		return $amount;
	}
}
