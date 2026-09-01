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
		$delivery_type = (string) ( $request['delivery_type'] ?? $meta['delivery_type'] ?? '' );
		$point_id = (int) preg_replace( '/\D+/', '', (string) ( $meta['pickup_point_code'] ?? '' ) );
		$row = $point_id > 0 ? $this->repository->find_active( $point_id ) : null;
		return array(
			'delivery_type' => $delivery_type,
			'point_found' => is_array( $row ),
			'point_id' => $point_id,
			'point_address' => is_array( $row ) ? (string) ( $row['full_address'] ?? '' ) : (string) ( $meta['pickup_point_address'] ?? '' ),
			'min_weight_g' => is_array( $row ) ? (int) ( $row['min_weight_g'] ?? 0 ) : 0,
			'max_weight_g' => is_array( $row ) ? (int) ( $row['max_weight_g'] ?? 0 ) : 0,
			'max_length_mm' => is_array( $row ) ? (int) ( $row['max_length_mm'] ?? 0 ) : 0,
			'max_width_mm' => is_array( $row ) ? (int) ( $row['max_width_mm'] ?? 0 ) : 0,
			'max_height_mm' => is_array( $row ) ? (int) ( $row['max_height_mm'] ?? 0 ) : 0,
			'courier_original_address' => (string) ( $meta['courier_original_address'] ?? '' ),
			'courier_address_snapshot' => is_array( $meta['courier_address_snapshot'] ?? null ) ? $meta['courier_address_snapshot'] : array(),
			'courier_address_source' => (string) ( $meta['courier_address_source'] ?? '' ),
			'normalization_valid' => ! empty( $meta['normalization_valid'] ),
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
		<div class="notice notice-info inline"
			data-wdc-ozon-place-limits
			data-point-found="<?php echo esc_attr( ! empty( $context['point_found'] ) ? '1' : '0' ); ?>"
			data-min-weight-g="<?php echo esc_attr( (string) max( 0, (int) ( $context['min_weight_g'] ?? 0 ) ) ); ?>"
			data-max-weight-g="<?php echo esc_attr( (string) max( 0, (int) ( $context['max_weight_g'] ?? 0 ) ) ); ?>"
			data-max-length-mm="<?php echo esc_attr( (string) max( 0, (int) ( $context['max_length_mm'] ?? 0 ) ) ); ?>"
			data-max-width-mm="<?php echo esc_attr( (string) max( 0, (int) ( $context['max_width_mm'] ?? 0 ) ) ); ?>"
			data-max-height-mm="<?php echo esc_attr( (string) max( 0, (int) ( $context['max_height_mm'] ?? 0 ) ) ); ?>">
			<p><strong><?php echo esc_html__( 'Ограничения выбранного ПВЗ Ozon', 'walls-delivery-calc' ); ?></strong></p>
			<p><?php echo esc_html( sprintf( __( 'Максимальный вес одного места: %s', 'walls-delivery-calc' ), $weight ) ); ?></p>
			<p><?php echo esc_html( sprintf( __( 'Максимальные размеры: %s', 'walls-delivery-calc' ), $dimensions ) ); ?></p>
			<?php if ( ! empty( $context['point_address'] ) ) : ?><p class="description"><?php echo esc_html( (string) $context['point_address'] ); ?></p><?php endif; ?>
			<div class="wdc-ozon-place-limit-warning" data-wdc-ozon-place-limit-warning role="alert" aria-live="polite" hidden></div>
		</div>
		<?php
	}

	/** @param array<string,mixed> $draft @param array<string,mixed> $context */
	public function render_courier_fields( object $order, array $draft, array $context ): void {
		unset( $order, $draft );
		$snapshot = is_array( $context['courier_address_snapshot'] ?? null ) ? $context['courier_address_snapshot'] : array();
		$normalized = array(
			'success' => ! empty( $context['normalization_valid'] ) && array() !== $snapshot,
			'message' => ! empty( $context['normalization_valid'] ) ? 'Адрес Ozon подтвержден из заказа.' : '',
			'source' => (string) ( $snapshot['source'] ?? 'trusted_order_snapshot' ),
			'fields' => $snapshot,
			'display' => (string) ( $snapshot['normalized_address'] ?? $context['courier_original_address'] ?? '' ),
			'original_hash' => hash( 'sha256', trim( (string) ( $context['courier_original_address'] ?? '' ) ) ),
			'service_key' => OzonDeliverySettings::SERVICE_KEY,
		);
		$encoded = wp_json_encode( $normalized, JSON_UNESCAPED_UNICODE ) ?: '';
		?>
		<div data-wdc-ozon-courier-address>
			<label><?php echo esc_html__( 'Адрес получателя', 'walls-delivery-calc' ); ?><textarea name="courier_original_address" data-wdc-courier-original-address rows="2"><?php echo esc_textarea( (string) ( $context['courier_original_address'] ?? '' ) ); ?></textarea></label>
			<input type="hidden" name="normalized_address_json" value="<?php echo esc_attr( $encoded ); ?>" data-wdc-normalized-address-json>
			<p>
				<button type="button" class="button" data-wdc-normalize-address><?php echo esc_html__( 'Проанализировать адрес', 'walls-delivery-calc' ); ?></button>
				<span class="description" data-wdc-normalized-status><?php echo ! empty( $context['normalization_valid'] ) ? esc_html__( 'Адрес подтвержден.', 'walls-delivery-calc' ) : esc_html__( 'Адрес нужно подтвердить перед созданием.', 'walls-delivery-calc' ); ?></span>
			</p>
			<label><?php echo esc_html__( 'Подтвержденный адрес', 'walls-delivery-calc' ); ?><input readonly data-wdc-normalized-address-display value="<?php echo esc_attr( (string) ( $snapshot['normalized_address'] ?? '' ) ); ?>"></label>
			<div class="wdc-ozon-courier-address-grid" data-wdc-ozon-courier-address-grid>
				<label><?php echo esc_html__( 'Индекс', 'walls-delivery-calc' ); ?><input readonly data-wdc-ozon-courier-field="postcode" value="<?php echo esc_attr( (string) ( $snapshot['postcode'] ?? '' ) ); ?>"></label>
				<label><?php echo esc_html__( 'Страна', 'walls-delivery-calc' ); ?><input readonly data-wdc-ozon-courier-field="country" value="<?php echo esc_attr( (string) ( $snapshot['country'] ?? '' ) ); ?>"></label>
				<label><?php echo esc_html__( 'Регион', 'walls-delivery-calc' ); ?><input readonly data-wdc-ozon-courier-field="region" value="<?php echo esc_attr( (string) ( $snapshot['region'] ?? '' ) ); ?>"></label>
				<label><?php echo esc_html__( 'Город', 'walls-delivery-calc' ); ?><input readonly data-wdc-ozon-courier-field="city" value="<?php echo esc_attr( (string) ( $snapshot['city'] ?? '' ) ); ?>"></label>
				<label><?php echo esc_html__( 'Улица', 'walls-delivery-calc' ); ?><input readonly data-wdc-ozon-courier-field="street" value="<?php echo esc_attr( (string) ( $snapshot['street'] ?? '' ) ); ?>"></label>
				<label><?php echo esc_html__( 'Дом', 'walls-delivery-calc' ); ?><input readonly data-wdc-ozon-courier-field="house" value="<?php echo esc_attr( (string) ( $snapshot['house'] ?? $snapshot['stead'] ?? '' ) ); ?>"></label>
				<label><?php echo esc_html__( 'Квартира/офис', 'walls-delivery-calc' ); ?><input name="ozon_courier_apartment" data-wdc-ozon-courier-field="flat" value="<?php echo esc_attr( (string) ( $snapshot['flat'] ?? '' ) ); ?>"></label>
				<label><?php echo esc_html__( 'Подъезд', 'walls-delivery-calc' ); ?><input name="ozon_courier_entrance" value=""></label>
				<label><?php echo esc_html__( 'Этаж', 'walls-delivery-calc' ); ?><input name="ozon_courier_floor" value=""></label>
				<label><?php echo esc_html__( 'Домофон', 'walls-delivery-calc' ); ?><input name="ozon_courier_intercom" value=""></label>
			</div>
		</div>
		<?php
	}
}
