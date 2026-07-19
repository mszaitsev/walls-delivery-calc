<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\YandexDelivery;

use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Shipments\Modal\CarrierShipmentModalExtensionInterface;

defined( 'ABSPATH' ) || exit;

final class YandexShipmentModalExtension implements CarrierShipmentModalExtensionInterface {
	private ?YandexDeliveryPickupPointV2Repository $pickup_points = null;

	public function __construct( private ?YandexLocationMappingV2Repository $location_mapping = null ) {
	}

	public function carrier_key(): string {
		return YandexDeliverySettings::CARRIER_KEY;
	}

	public function modal_context( object $order, array $draft ): array {
		unset( $order );
		$request = is_array( $draft['request'] ?? null ) ? $draft['request'] : array();
		$meta = is_array( $request['meta'] ?? null ) ? $request['meta'] : array();
		$pickup = is_array( $request['pickup_point'] ?? null ) ? $request['pickup_point'] : array();
		$pickup_row = is_array( $meta['pickup_point_row'] ?? null ) ? $meta['pickup_point_row'] : array();
		$address = is_array( $request['recipient_address'] ?? null ) ? $request['recipient_address'] : array();
		$courier_details = is_array( $meta['yandex_courier_details'] ?? null ) ? $meta['yandex_courier_details'] : array();
		$courier_original_address = (string) ( $meta['courier_original_address'] ?? '' );
		$normalized_address = is_array( $meta['normalized_address'] ?? null ) ? $meta['normalized_address'] : array();
		$source_platform_station_id = trim( (string) ( $meta['yandex_source_platform_station_id'] ?? '' ) );
		$ready_from = trim( (string) ( $meta['yandex_ready_from'] ?? '' ) );
		$ready_to = trim( (string) ( $meta['yandex_ready_to'] ?? $ready_from ) );

		return array(
			'requires_tariff' => false,
			'requires_successful_preview' => false,
			'selected_tariff_object' => '',
			'selected_service_tariffs' => array(),
			'selected_tariff_has_declared_value' => false,
			'source_platform_station_id' => $source_platform_station_id,
			'source_location_id' => (int) ( $meta['yandex_source_location_id'] ?? 0 ),
			'source_dropoff' => $this->source_dropoff_presentation( $source_platform_station_id ),
			'pickup_platform_station_id' => trim( (string) ( $meta['yandex_pickup_platform_station_id'] ?? $pickup['point_code'] ?? '' ) ),
			'pickup_address' => trim( (string) ( $meta['pickup_point_address'] ?? $pickup_row['address'] ?? $address['raw_address'] ?? '' ) ),
			'ready_from' => $ready_from,
			'ready_to' => $ready_to,
			'courier_details' => $courier_details,
			'courier_full_address' => trim( (string) ( $courier_details['full_address'] ?? $courier_original_address ) ),
			'courier_verified' => ! empty( $courier_details['address_verified'] ),
			'normalized_json' => wp_json_encode( $normalized_address, JSON_UNESCAPED_UNICODE ) ?: '',
		);
	}

	public function render_fields( object $order, array $draft, array $context ): void {
		unset( $order, $draft );
		$source_dropoff = is_array( $context['source_dropoff'] ?? null ) ? $context['source_dropoff'] : array();
		$source_platform_station_id = (string) ( $context['source_platform_station_id'] ?? '' );
		$ready_from = (string) ( $context['ready_from'] ?? '' );
		$ready_to = (string) ( $context['ready_to'] ?? $ready_from );
		?>
		<input type="hidden" name="tariff_object" value="">
		<p class="description" data-wdc-yandex-offer-note><?php echo esc_html__( 'Оффер Яндекс.Доставки будет выбран автоматически по самому раннему доступному сроку.', 'walls-delivery-calc' ); ?></p>
		<div data-wdc-yandex-source-station data-wdc-yandex-source-dropoff data-default-id="<?php echo esc_attr( $source_platform_station_id ); ?>" data-default-title="<?php echo esc_attr( (string) ( $source_dropoff['title'] ?? '' ) ); ?>" data-default-address="<?php echo esc_attr( (string) ( $source_dropoff['address'] ?? '' ) ); ?>" data-default-work-time="<?php echo esc_attr( (string) ( $source_dropoff['work_time'] ?? '' ) ); ?>" data-default-lat="<?php echo esc_attr( (string) ( $source_dropoff['lat'] ?? '' ) ); ?>" data-default-lng="<?php echo esc_attr( (string) ( $source_dropoff['lng'] ?? '' ) ); ?>">
			<input type="hidden" name="yandex_source_platform_station_id" value="<?php echo esc_attr( $source_platform_station_id ); ?>" data-wdc-yandex-source-station-id>
			<input type="hidden" name="yandex_source_station_overridden" value="0" data-wdc-yandex-source-station-overridden>
			<input type="hidden" name="yandex_source_dropoff_title" value="<?php echo esc_attr( (string) ( $source_dropoff['title'] ?? '' ) ); ?>" data-wdc-yandex-source-dropoff-title-input>
			<input type="hidden" name="yandex_source_dropoff_address" value="<?php echo esc_attr( (string) ( $source_dropoff['address'] ?? '' ) ); ?>" data-wdc-yandex-source-dropoff-address-input>
			<input type="hidden" name="yandex_source_dropoff_work_time" value="<?php echo esc_attr( (string) ( $source_dropoff['work_time'] ?? '' ) ); ?>" data-wdc-yandex-source-dropoff-work-time-input>
			<input type="hidden" value="<?php echo esc_attr( (string) ( $context['source_location_id'] ?? '' ) ); ?>" data-wdc-yandex-source-location-id>
			<input type="hidden" value="<?php echo esc_attr( (string) ( $source_dropoff['lat'] ?? '' ) ); ?>" data-wdc-yandex-source-lat>
			<input type="hidden" value="<?php echo esc_attr( (string) ( $source_dropoff['lng'] ?? '' ) ); ?>" data-wdc-yandex-source-lng>
			<p><strong><?php echo esc_html__( 'ПВЗ отправления Яндекс', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-yandex-source-dropoff-title><?php echo esc_html( '' !== (string) ( $source_dropoff['title'] ?? '' ) ? (string) $source_dropoff['title'] : '-' ); ?></span></p>
			<p class="description" data-wdc-yandex-source-dropoff-address><?php echo esc_html( '' !== (string) ( $source_dropoff['address'] ?? '' ) ? (string) $source_dropoff['address'] : ( '' !== $source_platform_station_id ? $source_platform_station_id : '-' ) ); ?></p>
			<p class="description" data-wdc-yandex-source-dropoff-work-time <?php echo '' !== (string) ( $source_dropoff['work_time'] ?? '' ) ? '' : 'hidden'; ?>><?php echo esc_html( (string) ( $source_dropoff['work_time'] ?? '' ) ); ?></p>
			<?php if ( '' === $source_platform_station_id ) : ?>
				<p class="description wdc-shipment-warning" data-wdc-yandex-source-dropoff-warning><?php echo esc_html__( 'Не указана исходная станция Яндекс. Предпросмотр будет заблокирован.', 'walls-delivery-calc' ); ?></p>
			<?php elseif ( ! empty( $source_dropoff['invalid'] ) ) : ?>
				<p class="description wdc-shipment-warning" data-wdc-yandex-source-dropoff-warning><?php echo esc_html__( 'Сохранённый ПВЗ отправления Яндекс недоступен. Выберите другой ПВЗ.', 'walls-delivery-calc' ); ?></p>
			<?php else : ?>
				<p class="description wdc-shipment-warning" data-wdc-yandex-source-dropoff-warning hidden></p>
			<?php endif; ?>
			<p>
				<button type="button" class="button" data-wdc-open-yandex-source-dropoff-picker><?php echo esc_html__( 'Выбрать другой ПВЗ', 'walls-delivery-calc' ); ?></button>
				<button type="button" class="button" data-wdc-reset-yandex-source-dropoff hidden><?php echo esc_html__( 'Вернуть ПВЗ из настроек', 'walls-delivery-calc' ); ?></button>
			</p>
		</div>
		<div data-wdc-yandex-ready-interval>
			<input type="hidden" name="yandex_ready_from" value="<?php echo esc_attr( $ready_from ); ?>">
			<input type="hidden" name="yandex_ready_to" value="<?php echo esc_attr( $ready_to ); ?>">
			<p><strong><?php echo esc_html__( 'Готовность заказа', 'walls-delivery-calc' ); ?>:</strong> <span><?php echo esc_html( '' !== $ready_from ? $ready_from : '-' ); ?><?php echo $ready_to !== $ready_from && '' !== $ready_to ? ' — ' . esc_html( $ready_to ) : ''; ?></span></p>
		</div>
		<?php
	}

	public function render_pickup_fields( object $order, array $draft, array $context ): void {
		unset( $order, $draft );
		$pickup_platform_station_id = (string) ( $context['pickup_platform_station_id'] ?? '' );
		$pickup_address = (string) ( $context['pickup_address'] ?? '' );
		?>
		<input type="hidden" name="pickup_point_code" value="<?php echo esc_attr( $pickup_platform_station_id ); ?>">
		<input type="hidden" name="yandex_pickup_platform_station_id" value="<?php echo esc_attr( $pickup_platform_station_id ); ?>">
		<div data-wdc-yandex-pickup-destination>
			<p><strong><?php echo esc_html__( 'ПВЗ назначения Яндекс', 'walls-delivery-calc' ); ?>:</strong> <span><?php echo esc_html( '' !== $pickup_address ? $pickup_address : '-' ); ?></span></p>
			<p><strong><?php echo esc_html__( 'Platform station ID', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-yandex-pickup-platform-station><?php echo esc_html( '' !== $pickup_platform_station_id ? $pickup_platform_station_id : '-' ); ?></span></p>
			<?php if ( '' === $pickup_platform_station_id ) : ?>
				<p class="description wdc-shipment-warning"><?php echo esc_html__( 'Не выбран ПВЗ назначения Яндекс. Предпросмотр будет заблокирован до выбора точки.', 'walls-delivery-calc' ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	public function render_courier_fields( object $order, array $draft, array $context ): void {
		unset( $order, $draft );
		$courier_fields = ! empty( $context['courier_verified'] ) && is_array( $context['courier_details'] ?? null ) ? $context['courier_details'] : array();
		?>
		<div data-wdc-yandex-courier-destination>
			<label><?php echo esc_html__( 'Полный адрес доставки', 'walls-delivery-calc' ); ?><textarea name="courier_original_address" rows="3" data-wdc-courier-original-address data-wdc-yandex-full-address><?php echo esc_textarea( (string) ( $context['courier_full_address'] ?? '' ) ); ?></textarea></label>
			<button type="button" class="button" data-wdc-normalize-address><?php echo esc_html__( 'Проверить адрес', 'walls-delivery-calc' ); ?></button>
			<input type="hidden" name="normalized_address_json" value="<?php echo esc_attr( (string) ( $context['normalized_json'] ?? '' ) ); ?>" data-wdc-normalized-address-json>
			<input type="hidden" name="yandex_country" value="<?php echo esc_attr( (string) ( $courier_fields['country'] ?? 'Россия' ) ); ?>">
			<label><?php echo esc_html__( 'Индекс', 'walls-delivery-calc' ); ?><input name="yandex_postal_code" value="<?php echo esc_attr( (string) ( $courier_fields['postal_code'] ?? '' ) ); ?>" data-wdc-yandex-address-field="postal_code"></label>
			<label><?php echo esc_html__( 'Регион', 'walls-delivery-calc' ); ?><input name="yandex_region" value="<?php echo esc_attr( (string) ( $courier_fields['region'] ?? '' ) ); ?>" data-wdc-yandex-address-field="region"></label>
			<label><?php echo esc_html__( 'Населённый пункт', 'walls-delivery-calc' ); ?><input name="yandex_locality" value="<?php echo esc_attr( (string) ( $courier_fields['locality'] ?? '' ) ); ?>" data-wdc-yandex-address-field="locality"></label>
			<label><?php echo esc_html__( 'Улица', 'walls-delivery-calc' ); ?><input name="yandex_street" value="<?php echo esc_attr( (string) ( $courier_fields['street'] ?? '' ) ); ?>" data-wdc-yandex-address-field="street"></label>
			<label><?php echo esc_html__( 'Дом', 'walls-delivery-calc' ); ?><input name="yandex_house" value="<?php echo esc_attr( (string) ( $courier_fields['house'] ?? '' ) ); ?>" data-wdc-yandex-address-field="house"></label>
			<label><?php echo esc_html__( 'Квартира', 'walls-delivery-calc' ); ?><input name="yandex_room" value="<?php echo esc_attr( (string) ( $courier_fields['room'] ?? '' ) ); ?>" data-wdc-yandex-address-field="room"></label>
			<label><?php echo esc_html__( 'Нормализованный полный адрес', 'walls-delivery-calc' ); ?><textarea rows="3" readonly data-wdc-normalized-address-display data-wdc-yandex-address-field="full_address"><?php echo esc_textarea( (string) ( $courier_fields['normalized_full_address'] ?? '' ) ); ?></textarea></label>
			<p class="description" data-wdc-normalized-status><?php echo esc_html( ! empty( $context['courier_verified'] ) ? __( 'Адрес Яндекс проверен через DaData.', 'walls-delivery-calc' ) : __( 'Проверьте адрес доставки через DaData.', 'walls-delivery-calc' ) ); ?></p>
		</div>
		<?php
	}

	/** @return array<string,mixed> */
	private function source_dropoff_presentation( string $platform_station_id ): array {
		$empty = array( 'title' => '', 'address' => '', 'work_time' => '', 'lat' => '', 'lng' => '', 'invalid' => false );
		if ( '' === $platform_station_id ) {
			return $empty;
		}
		$row = $this->pickup_points()->find( $platform_station_id );
		if ( ! is_array( $row ) ) {
			return array_merge( $empty, array( 'title' => $platform_station_id, 'invalid' => true ) );
		}

		$title = trim( (string) ( $row['name'] ?? $row['title'] ?? '' ) );
		$address = trim( (string) ( $row['full_address'] ?? $row['address'] ?? '' ) );

		return array(
			'title' => '' !== $title ? $title : ( '' !== $address ? $address : $platform_station_id ),
			'address' => $address,
			'work_time' => (string) ( $row['schedule_text'] ?? $row['work_time'] ?? $row['schedule'] ?? '' ),
			'lat' => (string) ( $row['latitude'] ?? $row['lat'] ?? '' ),
			'lng' => (string) ( $row['longitude'] ?? $row['lng'] ?? '' ),
			'invalid' => false,
		);
	}

	private function pickup_points(): YandexDeliveryPickupPointV2Repository {
		if ( ! $this->pickup_points instanceof YandexDeliveryPickupPointV2Repository ) {
			$this->pickup_points = new YandexDeliveryPickupPointV2Repository();
		}

		return $this->pickup_points;
	}
}
