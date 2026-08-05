<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Shipments\Modal\CarrierShipmentModalExtensionInterface;

defined( 'ABSPATH' ) || exit;

final class PekShipmentModalExtension implements CarrierShipmentModalExtensionInterface {
	public function __construct( private PekSettings $settings ) {
	}

	public function carrier_key(): string {
		return PekSettings::CARRIER_KEY;
	}

	/** @param array<string,mixed> $draft @return array<string,mixed> */
	public function modal_context( object $order, array $draft ): array {
		unset( $order );
		$request = is_array( $draft['request'] ?? null ) ? $draft['request'] : array();
		$meta = is_array( $request['meta'] ?? null ) ? $request['meta'] : array();
		$warehouse = $this->settings->sender_warehouse();

		return array(
			'default_sender_warehouse' => $warehouse,
			'current_sender_warehouse_id' => (string) ( $meta['pek_sender_warehouse_id'] ?? $warehouse['warehouseId'] ?? '' ),
			'receiver_warehouse_id' => (string) ( $meta['pek_receiver_warehouse_id'] ?? $meta['pickup_point_code'] ?? '' ),
			'receiver_branch_id' => (string) ( $meta['pek_receiver_branch_id'] ?? '' ),
			'destination_location_id' => (int) ( $meta['pek_destination_location_id'] ?? 0 ),
			'provider_destination_fingerprint' => (string) ( $meta['provider_destination_fingerprint'] ?? '' ),
			'recipient_type' => 'physical',
			'sms_release_status' => 'required',
			'destination_summary' => (string) ( $meta['selected_pickup_point_title'] ?? $meta['pickup_point_title'] ?? '' ),
			'delivery_type' => (string) ( $request['delivery_type'] ?? '' ),
			'declared_value' => is_array( $request['declared_value'] ?? null ) ? $request['declared_value'] : array(),
			'product_weight_g' => (int) ( $meta['pek_product_weight_g'] ?? 0 ),
			'cargo_constraints' => array( 'type' => PekSettings::LTL_PRODUCT_TYPE, 'orderType' => 0 ),
		);
	}

	/** @param array<string,mixed> $draft @param array<string,mixed> $context */
	public function render_fields( object $order, array $draft, array $context ): void {
		unset( $order, $draft );
		$warehouse = is_array( $context['default_sender_warehouse'] ?? null ) ? $context['default_sender_warehouse'] : array();
		?>
		<p><strong><?php echo esc_html__( 'Получатель', 'walls-delivery-calc' ); ?>:</strong> <?php echo esc_html__( 'физическое лицо, выдача по СМС', 'walls-delivery-calc' ); ?></p>
		<input type="hidden" name="recipient_type" value="physical">
		<input type="hidden" name="pek_sender_warehouse_id" value="<?php echo esc_attr( (string) ( $context['current_sender_warehouse_id'] ?? '' ) ); ?>" data-wdc-pek-sender-warehouse-id>
		<p><strong><?php echo esc_html__( 'Склад самопривоза ПЭК', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-pek-sender-warehouse-title><?php echo esc_html( (string) ( $warehouse['divisionName'] ?? $warehouse['branchName'] ?? '-' ) ); ?></span></p>
		<p class="description"><?php echo esc_html( (string) ( $warehouse['address'] ?? '' ) ); ?></p>
		<p><button type="button" class="button" data-wdc-pek-open-sender-warehouse-picker><?php echo esc_html__( 'Выбрать другой склад ПЭК', 'walls-delivery-calc' ); ?></button></p>
		<p class="description"><?php echo esc_html__( 'Страхование и выдача по СМС обязательны; объявленная стоимость берётся из товарных строк заказа.', 'walls-delivery-calc' ); ?></p>
		<?php
	}

	/** @param array<string,mixed> $draft @param array<string,mixed> $context */
	public function render_pickup_fields( object $order, array $draft, array $context ): void {
		unset( $order, $draft );
		?>
		<p><strong><?php echo esc_html__( 'Терминал ПЭК получателя', 'walls-delivery-calc' ); ?>:</strong> <?php echo esc_html( (string) ( $context['destination_summary'] ?? '-' ) ); ?></p>
		<?php
	}

	/** @param array<string,mixed> $draft @param array<string,mixed> $context */
	public function render_courier_fields( object $order, array $draft, array $context ): void {
		unset( $draft, $context );
		$address = method_exists( $order, 'get_shipping_address_1' ) ? trim( (string) $order->get_shipping_address_1() . ' ' . (string) $order->get_shipping_address_2() ) : '';
		?>
		<p><strong><?php echo esc_html__( 'Адрес доставки ПЭК', 'walls-delivery-calc' ); ?>:</strong> <?php echo esc_html( '' !== $address ? $address : '-' ); ?></p>
		<?php
	}
}
