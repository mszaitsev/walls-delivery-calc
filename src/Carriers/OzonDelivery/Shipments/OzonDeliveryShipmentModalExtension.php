<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupRepository;
use WallsShop\WDC\Shipments\Modal\CarrierShipmentModalExtensionInterface;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryShipmentModalExtension implements CarrierShipmentModalExtensionInterface {
	public function __construct( private OzonDeliveryPickupRepository $repository ) {}

	public function carrier_key(): string {
		return OzonDeliverySettings::CARRIER_KEY;
	}

	/** @param array<string,mixed> $draft @return array<string,mixed> */
	public function modal_context( object $order, array $draft ): array {
		unset( $order );
		$request = is_array( $draft['request'] ?? null ) ? $draft['request'] : array();
		$meta = is_array( $request['meta'] ?? null ) ? $request['meta'] : array();
		$point_id = (int) preg_replace( '/\D+/', '', (string) ( $meta['pickup_point_code'] ?? '' ) );
		$row = $point_id > 0 ? $this->repository->find_active( $point_id ) : null;
		return array(
			'point_found' => is_array( $row ),
			'point_id' => $point_id,
			'point_address' => is_array( $row ) ? (string) ( $row['full_address'] ?? '' ) : (string) ( $meta['pickup_point_address'] ?? '' ),
			'max_weight_g' => is_array( $row ) ? (int) ( $row['max_weight_g'] ?? 0 ) : 0,
			'max_length_mm' => is_array( $row ) ? (int) ( $row['max_length_mm'] ?? 0 ) : 0,
			'max_width_mm' => is_array( $row ) ? (int) ( $row['max_width_mm'] ?? 0 ) : 0,
			'max_height_mm' => is_array( $row ) ? (int) ( $row['max_height_mm'] ?? 0 ) : 0,
		);
	}

	/** @param array<string,mixed> $draft @param array<string,mixed> $context */
	public function render_fields( object $order, array $draft, array $context ): void {
		unset( $order, $draft, $context );
		?>
		<p class="description"><?php echo esc_html__( 'Создание Ozon использует фактические грузоместа и распределение товаров из этой формы.', 'walls-delivery-calc' ); ?></p>
		<?php
	}

	/** @param array<string,mixed> $draft @param array<string,mixed> $context */
	public function render_pickup_fields( object $order, array $draft, array $context ): void {
		unset( $order, $draft );
		$limits = array_filter( array( (int) ( $context['max_length_mm'] ?? 0 ), (int) ( $context['max_width_mm'] ?? 0 ), (int) ( $context['max_height_mm'] ?? 0 ) ) );
		rsort( $limits, SORT_NUMERIC );
		$dimensions = 3 === count( $limits ) ? sprintf( '%d × %d × %d см', (int) ceil( $limits[0] / 10 ), (int) ceil( $limits[1] / 10 ), (int) ceil( $limits[2] / 10 ) ) : 'не указаны';
		$weight = (int) ( $context['max_weight_g'] ?? 0 ) > 0 ? rtrim( rtrim( number_format( (int) $context['max_weight_g'] / 1000, 3, ',', '' ), '0' ), ',' ) . ' кг' : 'не указан';
		?>
		<div class="notice notice-info inline">
			<p><strong><?php echo esc_html__( 'Ограничения выбранного ПВЗ Ozon', 'walls-delivery-calc' ); ?></strong></p>
			<p><?php echo esc_html( sprintf( __( 'Максимальный вес одного места: %s', 'walls-delivery-calc' ), $weight ) ); ?></p>
			<p><?php echo esc_html( sprintf( __( 'Максимальные размеры: %s', 'walls-delivery-calc' ), $dimensions ) ); ?></p>
			<?php if ( ! empty( $context['point_address'] ) ) : ?><p class="description"><?php echo esc_html( (string) $context['point_address'] ); ?></p><?php endif; ?>
		</div>
		<?php
	}

	/** @param array<string,mixed> $draft @param array<string,mixed> $context */
	public function render_courier_fields( object $order, array $draft, array $context ): void {
		unset( $order, $draft, $context );
	}
}
