<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Cdek;

use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Shipments\Modal\CarrierShipmentModalExtensionInterface;

defined( 'ABSPATH' ) || exit;

final class CdekShipmentModalExtension implements CarrierShipmentModalExtensionInterface {
	public function carrier_key(): string {
		return CdekSettings::CARRIER_KEY;
	}

	public function modal_context( object $order, array $draft ): array {
		$request = is_array( $draft['request'] ?? null ) ? $draft['request'] : array();
		$services = is_array( $draft['services'] ?? null ) ? $draft['services'] : array();
		$meta = is_array( $request['meta'] ?? null ) ? $request['meta'] : array();
		$address = is_array( $request['recipient_address'] ?? null ) ? $request['recipient_address'] : array();
		$pickup = is_array( $request['pickup_point'] ?? null ) ? $request['pickup_point'] : array();
		$pickup_row = is_array( $meta['pickup_point_row'] ?? null ) ? $meta['pickup_point_row'] : array();
		$pickup_context = is_array( $meta['pickup_location_context'] ?? null ) ? $meta['pickup_location_context'] : array();
		$delivery_type = (string) ( $request['delivery_type'] ?? $meta['delivery_type'] ?? DeliveryType::PICKUP );
		$tariffs = $this->tariffs_for_delivery_type( $services, $delivery_type );
		$tariff_object = (string) ( $meta['tariff_object'] ?? '' );
		if ( '' === $tariff_object && array() !== $tariffs ) {
			$tariff_object = (string) ( $tariffs[0]['object_code'] ?? '' );
		}
		$tariff_title = $this->tariff_title( $tariffs, $tariff_object, (string) ( $meta['selected_tariff_title'] ?? $meta['tariff_title'] ?? '' ) );
		$delivery_mode = $this->delivery_mode( $tariffs, $tariff_object, (int) ( $meta['delivery_mode'] ?? $meta['cdek_delivery_mode'] ?? 0 ) );
		$shipment_point = (string) ( $meta['shipment_point'] ?? '' );
		$shipment_point_address = (string) ( $meta['shipment_point_address'] ?? '' );
		$order_shipping_city = method_exists( $order, 'get_shipping_city' ) ? (string) $order->get_shipping_city() : '';
		$order_shipping_region = method_exists( $order, 'get_shipping_state' ) ? (string) $order->get_shipping_state() : '';
		$order_shipping_postcode = method_exists( $order, 'get_shipping_postcode' ) ? (string) $order->get_shipping_postcode() : '';
		$order_shipping_address = $this->join_non_empty(
			array(
				method_exists( $order, 'get_shipping_address_1' ) ? (string) $order->get_shipping_address_1() : '',
				method_exists( $order, 'get_shipping_address_2' ) ? (string) $order->get_shipping_address_2() : '',
			)
		);
		$region = (string) ( $address['region_name'] ?? $order_shipping_region );
		$city = (string) ( $address['settlement'] ?? $address['city'] ?? $order_shipping_city );
		$recipient_postcode = (string) ( $address['postcode'] ?? $order_shipping_postcode );
		$recipient_address_context = (string) ( $address['raw_address'] ?? $order_shipping_address );
		$recipient_country = strtoupper( trim( (string) ( $address['country_code'] ?? ( method_exists( $order, 'get_shipping_country' ) ? $order->get_shipping_country() : 'RU' ) ) ) );
		$recipient_country = '' === $recipient_country ? 'RU' : $recipient_country;
		$pickup_code = (string) ( $pickup['point_code'] ?? $meta['pickup_point_code'] ?? '' );
		$pickup_address = $recipient_address_context;
		$normalized_address = is_array( $meta['normalized_address'] ?? null ) ? $meta['normalized_address'] : array();
		$normalized_is_cdek = in_array( (string) ( $normalized_address['source'] ?? '' ), array( 'dadata+cdek_location', 'cdek_eaeu_raw_address' ), true );
		$normalized_status = array() !== $normalized_address
			? ( ! empty( $normalized_address['success'] ) ? '✅ Данные для СДЭК корректны' : (string) ( $normalized_address['message'] ?? 'Адрес не подтвержден СДЭК, создание отправления заблокировано.' ) )
			: 'Адрес нужно обработать перед созданием отправления.';

		return array(
			'requires_tariff' => true,
			'requires_successful_preview' => false,
			'selected_tariff_object' => $tariff_object,
			'selected_service_tariffs' => $tariffs,
			'selected_tariff_title' => $tariff_title,
			'selected_tariff_has_declared_value' => $this->tariff_has_declared_value( $tariffs, $tariff_object ),
			'shipment_point' => $shipment_point,
			'shipment_point_address' => $shipment_point_address,
			'shipment_point_display' => $this->join_non_empty( array( $shipment_point, $shipment_point_address ) ),
			'sender_from_door_display' => $this->join_non_empty( array( (string) ( $meta['sender_city_name'] ?? '' ), (string) ( $meta['sender_address'] ?? '' ) ) ),
			'cdek_sender_door' => in_array( $delivery_mode, array( 1, 2 ), true ),
			'cdek_recipient_door' => in_array( $delivery_mode, array( 1, 3 ), true ),
			'pickup_code' => $pickup_code,
			'recipient_country' => $recipient_country,
			'pickup_postcode' => (string) ( $pickup_row['postcode'] ?? $pickup_code ),
			'pickup_address' => $pickup_address,
			'pickup_city' => $city,
			'pickup_region' => $region,
			'pickup_row' => $pickup_row,
			'pickup_context' => $pickup_context,
			'pickup_family' => (string) ( $meta['pickup_family'] ?? CdekSettings::CARRIER_KEY . ':pickup' ),
			'delivery_city_id' => (string) ( $meta['delivery_city_id'] ?? $pickup_row['cdek_city_code'] ?? '' ),
			'pickup_point_found' => ! empty( $meta['pickup_point_found'] ),
			'pickup_type_label' => $this->pickup_type_label( $pickup_row ),
			'pickup_location_postcode' => $recipient_postcode,
			'pickup_location_address' => $recipient_address_context,
			'normalized_address' => $normalized_address,
			'normalized_json' => wp_json_encode( $normalized_address, JSON_UNESCAPED_UNICODE ) ?: '',
			'normalized_display' => (string) ( $normalized_address['display'] ?? '' ),
			'normalized_status' => $normalized_status,
			'normalized_is_cdek' => $normalized_is_cdek,
			'courier_original_address' => (string) ( $meta['courier_original_address'] ?? '' ),
			'cdek_courier_comment' => (string) ( $meta['cdek_courier_comment'] ?? '' ),
		);
	}

	public function render_fields( object $order, array $draft, array $context ): void {
		unset( $order, $draft );
		$recipient_country = strtoupper( trim( (string) ( $context['recipient_country'] ?? 'RU' ) ) );
		$recipient_country = '' === $recipient_country ? 'RU' : $recipient_country;
		$document_visible = $this->recipient_document_visible( $recipient_country );
		$document_help = $this->recipient_document_help( $recipient_country );
		?>
		<p><strong><?php echo esc_html__( 'В заказе тариф', 'walls-delivery-calc' ); ?>:</strong> <?php echo esc_html( '' !== (string) ( $context['selected_tariff_title'] ?? '' ) ? (string) $context['selected_tariff_title'] : '-' ); ?></p>
		<input type="hidden" name="shipment_point" value="<?php echo esc_attr( (string) ( $context['shipment_point'] ?? '' ) ); ?>" data-wdc-sender-shipment-point>
		<input type="hidden" name="sender_shipment_point" value="<?php echo esc_attr( (string) ( $context['shipment_point'] ?? '' ) ); ?>">
		<input type="hidden" name="shipment_point_address" value="<?php echo esc_attr( (string) ( $context['shipment_point_address'] ?? '' ) ); ?>" data-wdc-sender-shipment-point-address>
		<input type="hidden" name="sender_shipment_point_address" value="<?php echo esc_attr( (string) ( $context['shipment_point_address'] ?? '' ) ); ?>">
		<input type="hidden" name="sender_pickup_city" value="Новосибирск" data-wdc-sender-pickup-city>
		<input type="hidden" value="<?php echo esc_attr( $recipient_country ); ?>" data-wdc-cdek-recipient-country>
		<label data-wdc-cdek-recipient-document-row <?php echo $document_visible ? '' : 'hidden'; ?>><?php echo esc_html__( 'Документ получателя', 'walls-delivery-calc' ); ?><input type="text" name="cdek_recipient_document" value="" maxlength="30" autocomplete="off" data-wdc-cdek-recipient-document <?php disabled( ! $document_visible ); ?>><span class="description" data-wdc-cdek-recipient-document-help><?php echo esc_html( $document_help ); ?></span></label>
		<div data-wdc-cdek-sender-door <?php echo ! empty( $context['cdek_sender_door'] ) ? '' : 'hidden'; ?>>
			<p><strong><?php echo esc_html__( 'Отправитель', 'walls-delivery-calc' ); ?>:</strong> <?php echo esc_html__( 'от двери', 'walls-delivery-calc' ); ?></p>
			<p><strong><?php echo esc_html__( 'Адрес отправителя', 'walls-delivery-calc' ); ?>:</strong> <?php echo esc_html( '' !== (string) ( $context['sender_from_door_display'] ?? '' ) ? (string) $context['sender_from_door_display'] : '-' ); ?></p>
		</div>
		<div data-wdc-cdek-sender-warehouse <?php echo ! empty( $context['cdek_sender_door'] ) ? 'hidden' : ''; ?>>
			<p><strong><?php echo esc_html__( 'ПВЗ отправителя', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-sender-shipment-point-display><?php echo esc_html( '' !== (string) ( $context['shipment_point_display'] ?? '' ) ? (string) $context['shipment_point_display'] : '-' ); ?></span></p>
			<p><button type="button" class="button" data-wdc-open-sender-pickup-picker><?php echo esc_html__( 'Выбрать другой ПВЗ отправителя', 'walls-delivery-calc' ); ?></button></p>
		</div>
		<?php
	}

	public function render_pickup_fields( object $order, array $draft, array $context ): void {
		unset( $order, $draft );
		$pickup_row = is_array( $context['pickup_row'] ?? null ) ? $context['pickup_row'] : array();
		$pickup_context = is_array( $context['pickup_context'] ?? null ) ? $context['pickup_context'] : array();
		$pickup_code = (string) ( $context['pickup_code'] ?? '' );
		$pickup_row_has_handout = array_key_exists( 'is_handout', $pickup_row ) && null !== $pickup_row['is_handout'];
		$pickup_row_handout_value = $pickup_row_has_handout ? ( true === filter_var( $pickup_row['is_handout'], FILTER_VALIDATE_BOOLEAN ) ? '1' : '0' ) : '';
		?>
		<input type="hidden" name="pickup_point_code" value="<?php echo esc_attr( $pickup_code ); ?>">
		<input type="hidden" name="delivery_point" value="<?php echo esc_attr( $pickup_code ); ?>" data-wdc-delivery-point-field>
		<input type="hidden" name="pickup_point_postcode" value="<?php echo esc_attr( (string) ( $context['pickup_postcode'] ?? '' ) ); ?>" data-wdc-pickup-postcode-field>
		<input type="hidden" name="pickup_point_address" value="<?php echo esc_attr( (string) ( $context['pickup_address'] ?? '' ) ); ?>" data-wdc-pickup-address-field>
		<input type="hidden" name="pickup_point_city" value="<?php echo esc_attr( (string) ( $context['pickup_city'] ?? '' ) ); ?>" data-wdc-pickup-city-field>
		<input type="hidden" name="pickup_point_region" value="<?php echo esc_attr( (string) ( $context['pickup_region'] ?? '' ) ); ?>" data-wdc-pickup-region-field>
		<input type="hidden" name="pickup_point_country" value="<?php echo esc_attr( (string) ( $context['recipient_country'] ?? 'RU' ) ); ?>" data-wdc-pickup-country-field>
		<input type="hidden" name="pickup_point_cdek_city_code" value="<?php echo esc_attr( (string) ( $pickup_row['cdek_city_code'] ?? '' ) ); ?>" data-wdc-pickup-cdek-city-code-field>
		<input type="hidden" name="pickup_point_is_handout" value="<?php echo esc_attr( $pickup_row_handout_value ); ?>" data-wdc-pickup-handout-field>
		<?php $this->render_pickup_common_hidden( $pickup_row, $pickup_context, $context, CdekSettings::CARRIER_KEY, CdekSettings::SERVICE_KEY, CdekSettings::CARRIER_KEY . ':pickup' ); ?>
		<p><strong><?php echo esc_html__( 'Код ПВЗ', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-pickup-index><?php echo esc_html( '' !== $pickup_code ? $pickup_code : '-' ); ?></span></p>
		<?php if ( '' !== (string) ( $context['pickup_type_label'] ?? '' ) ) : ?>
			<p><strong><?php echo esc_html__( 'Тип точки', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-cdek-pickup-type-label data-wdc-pickup-type-label><?php echo esc_html( (string) $context['pickup_type_label'] ); ?></span></p>
		<?php endif; ?>
		<p><strong><?php echo esc_html__( 'Адрес ПВЗ', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-pickup-address><?php echo esc_html( '' !== (string) ( $context['pickup_address'] ?? '' ) ? (string) $context['pickup_address'] : '-' ); ?></span></p>
		<p><button type="button" class="button" data-wdc-open-pickup-picker><?php echo esc_html__( 'Выбрать другой ПВЗ', 'walls-delivery-calc' ); ?></button></p>
		<?php if ( empty( $context['pickup_point_found'] ) ) : ?>
			<p class="description wdc-shipment-warning" data-wdc-pickup-warning><?php echo esc_html__( 'ПВЗ СДЭК не выбран. Создание отправления заблокировано до выбора корректного ПВЗ.', 'walls-delivery-calc' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function render_courier_fields( object $order, array $draft, array $context ): void {
		unset( $order, $draft );
		$normalized_address = is_array( $context['normalized_address'] ?? null ) ? $context['normalized_address'] : array();
		?>
		<?php $this->render_recipient_location_hidden( array(), $context ); ?>
		<label><?php echo esc_html__( 'Оригинальный адрес покупателя', 'walls-delivery-calc' ); ?><textarea name="courier_original_address" rows="3" data-wdc-courier-original-address><?php echo esc_textarea( (string) ( $context['courier_original_address'] ?? '' ) ); ?></textarea></label>
		<button type="button" class="button" data-wdc-normalize-address><?php echo esc_html__( 'Обработать адрес', 'walls-delivery-calc' ); ?></button>
		<input type="hidden" name="normalized_address_json" value="<?php echo esc_attr( (string) ( $context['normalized_json'] ?? '' ) ); ?>" data-wdc-normalized-address-json>
		<p class="description" data-wdc-normalized-status><?php echo esc_html( (string) ( $context['normalized_status'] ?? '' ) ); ?></p>
		<label><span data-wdc-normalized-address-label><?php echo esc_html__( 'Нормализованный адрес СДЭК', 'walls-delivery-calc' ); ?></span><textarea rows="3" readonly data-wdc-normalized-address-display><?php echo esc_textarea( (string) ( $context['normalized_display'] ?? '' ) ); ?></textarea></label>
		<p class="description" data-wdc-cdek-city-code-row <?php echo ( ! empty( $normalized_address['fields']['cdek_city_code'] ) ) ? '' : 'hidden'; ?>><?php echo esc_html__( 'Код города СДЭК', 'walls-delivery-calc' ); ?>: <span data-wdc-cdek-city-code><?php echo esc_html( (string) ( $normalized_address['fields']['cdek_city_code'] ?? '' ) ); ?></span></p>
		<label data-wdc-cdek-courier-comment-row <?php echo ! empty( $context['cdek_recipient_door'] ) ? '' : 'hidden'; ?>><?php echo esc_html__( 'Комментарий курьеру', 'walls-delivery-calc' ); ?><textarea name="cdek_courier_comment" rows="2" maxlength="255"><?php echo esc_textarea( (string) ( $context['cdek_courier_comment'] ?? '' ) ); ?></textarea><span class="description"><?php echo esc_html__( 'Будет передан в СДЭК как комментарий к заказу. Не более 255 символов.', 'walls-delivery-calc' ); ?></span></label>
		<?php
	}

	/** @param array<int,array<string,mixed>> $services @return array<int,array<string,mixed>> */
	private function tariffs_for_delivery_type( array $services, string $delivery_type ): array {
		foreach ( $services as $service ) {
			if ( $delivery_type === (string) ( $service['delivery_type'] ?? '' ) ) {
				return is_array( $service['tariffs'] ?? null ) ? $service['tariffs'] : array();
			}
		}

		return array();
	}

	/** @param array<int,array<string,mixed>> $tariffs */
	private function tariff_has_declared_value( array $tariffs, string $object ): bool {
		foreach ( $tariffs as $tariff ) {
			if ( $object === (string) ( $tariff['object_code'] ?? '' ) ) {
				return ! empty( $tariff['has_declared_value'] );
			}
		}

		return false;
	}

	/** @param array<int,array<string,mixed>> $tariffs */
	private function tariff_title( array $tariffs, string $object, string $fallback ): string {
		foreach ( $tariffs as $tariff ) {
			if ( $object === (string) ( $tariff['object_code'] ?? '' ) ) {
				return (string) ( $tariff['title'] ?? $fallback );
			}
		}

		return '' !== trim( $fallback ) || '' === $object ? $fallback : sprintf( __( 'тариф %s', 'walls-delivery-calc' ), $object );
	}

	/** @param array<int,array<string,mixed>> $tariffs */
	private function delivery_mode( array $tariffs, string $object, int $fallback ): int {
		foreach ( $tariffs as $tariff ) {
			if ( $object === (string) ( $tariff['object_code'] ?? '' ) && in_array( (int) ( $tariff['delivery_mode'] ?? 0 ), array( 1, 2, 3, 4 ), true ) ) {
				return (int) $tariff['delivery_mode'];
			}
		}

		return $fallback;
	}

	/** @param array<int,string> $values */
	private function join_non_empty( array $values ): string {
		return implode( ', ', array_filter( $values, static fn ( string $value ): bool => '' !== trim( $value ) ) );
	}

	/** @param array<string,mixed> $pickup_row */
	private function pickup_type_label( array $pickup_row ): string {
		$type = (string) ( $pickup_row['point_type'] ?? '' );
		if ( '' === $type ) {
			return '';
		}

		return 'PVZ' === strtoupper( $type ) ? __( 'ПВЗ', 'walls-delivery-calc' ) : $type;
	}

	private function recipient_document_visible( string $country_code ): bool {
		return in_array( strtoupper( trim( $country_code ) ), array( 'AM', 'BY', 'KZ', 'KG' ), true );
	}

	private function recipient_document_help( string $country_code ): string {
		return match ( strtoupper( trim( $country_code ) ) ) {
			'KZ' => __( 'ИИН / IIN получателя — необязательно. Значение передаётся только в СДЭК и не сохраняется.', 'walls-delivery-calc' ),
			'KG' => __( 'ИИН получателя — необязательно. Значение передаётся только в СДЭК и не сохраняется.', 'walls-delivery-calc' ),
			'AM', 'BY' => __( 'Номер паспорта получателя — необязательно. Значение передаётся только в СДЭК и не сохраняется.', 'walls-delivery-calc' ),
			default => __( 'Значение передаётся только в СДЭК и не сохраняется.', 'walls-delivery-calc' ),
		};
	}

	/** @param array<string,mixed> $pickup_row @param array<string,mixed> $pickup_context @param array<string,mixed> $context */
	private function render_pickup_common_hidden( array $pickup_row, array $pickup_context, array $context, string $carrier_key, string $service_key, string $fallback_family ): void {
		?>
		<input type="hidden" name="pickup_point_type" value="<?php echo esc_attr( (string) ( $pickup_row['point_type'] ?? '' ) ); ?>" data-wdc-pickup-type-field>
		<input type="hidden" name="pickup_point_title" value="<?php echo esc_attr( (string) ( $pickup_row['display_title'] ?? $pickup_row['point_title'] ?? '' ) ); ?>" data-wdc-pickup-title-field>
		<input type="hidden" name="pickup_point_lat" value="<?php echo esc_attr( (string) ( $pickup_row['lat'] ?? '' ) ); ?>" data-wdc-pickup-lat-field>
		<input type="hidden" name="pickup_point_lng" value="<?php echo esc_attr( (string) ( $pickup_row['lng'] ?? '' ) ); ?>" data-wdc-pickup-lng-field>
		<input type="hidden" name="pickup_carrier_key" value="<?php echo esc_attr( $carrier_key ); ?>" data-wdc-pickup-carrier-key>
		<input type="hidden" name="pickup_service_key" value="<?php echo esc_attr( $service_key ); ?>" data-wdc-pickup-service-key>
		<input type="hidden" name="pickup_family" value="<?php echo esc_attr( (string) ( $context['pickup_family'] ?? $fallback_family ) ); ?>" data-wdc-pickup-family>
		<?php $this->render_recipient_location_hidden( $pickup_context, $context, true ); ?>
		<?php
	}

	/** @param array<string,mixed> $pickup_context @param array<string,mixed> $context */
	private function render_recipient_location_hidden( array $pickup_context, array $context, bool $include_city_id = false ): void {
		?>
		<input type="hidden" name="recipient_location_country" value="<?php echo esc_attr( (string) ( $pickup_context['country_code'] ?? $context['recipient_country'] ?? 'RU' ) ); ?>" <?php echo $include_city_id ? 'data-wdc-pickup-location-country' : ''; ?>>
		<input type="hidden" name="recipient_location_city" value="<?php echo esc_attr( (string) ( $pickup_context['city_name'] ?? $pickup_context['city_value'] ?? $context['pickup_city'] ?? '' ) ); ?>" <?php echo $include_city_id ? 'data-wdc-pickup-location-city' : ''; ?>>
		<input type="hidden" name="recipient_location_region" value="<?php echo esc_attr( (string) ( $pickup_context['region_name'] ?? $pickup_context['state_value'] ?? $context['pickup_region'] ?? '' ) ); ?>" <?php echo $include_city_id ? 'data-wdc-pickup-location-region' : ''; ?>>
		<input type="hidden" name="recipient_location_postcode" value="<?php echo esc_attr( (string) ( $pickup_context['postal_code'] ?? $pickup_context['postcode'] ?? $context['pickup_location_postcode'] ?? '' ) ); ?>" <?php echo $include_city_id ? 'data-wdc-pickup-location-postcode' : ''; ?>>
		<input type="hidden" name="recipient_location_address" value="<?php echo esc_attr( (string) ( $pickup_context['address'] ?? $pickup_context['display_name'] ?? $context['pickup_location_address'] ?? '' ) ); ?>" <?php echo $include_city_id ? 'data-wdc-pickup-location-address' : ''; ?>>
		<input type="hidden" name="recipient_location_fias_id" value="<?php echo esc_attr( (string) ( $pickup_context['fias_id'] ?? '' ) ); ?>" <?php echo $include_city_id ? 'data-wdc-pickup-location-fias' : ''; ?>>
		<input type="hidden" name="recipient_location_gar_id" value="<?php echo esc_attr( (string) ( $pickup_context['gar_id'] ?? '' ) ); ?>" <?php echo $include_city_id ? 'data-wdc-pickup-location-gar' : ''; ?>>
		<input type="hidden" name="recipient_location_id" value="<?php echo esc_attr( (string) ( $pickup_context['location_id'] ?? '' ) ); ?>" <?php echo $include_city_id ? 'data-wdc-pickup-location-id' : ''; ?>>
		<?php if ( $include_city_id ) : ?>
			<input type="hidden" name="recipient_location_city_id" value="<?php echo esc_attr( (string) ( $context['delivery_city_id'] ?? '' ) ); ?>" data-wdc-pickup-location-city-id>
		<?php endif; ?>
		<input type="hidden" name="recipient_location_lat" value="<?php echo esc_attr( (string) ( $pickup_context['lat'] ?? '' ) ); ?>" <?php echo $include_city_id ? 'data-wdc-pickup-location-lat' : ''; ?>>
		<input type="hidden" name="recipient_location_lng" value="<?php echo esc_attr( (string) ( $pickup_context['lng'] ?? '' ) ); ?>" <?php echo $include_city_id ? 'data-wdc-pickup-location-lng' : ''; ?>>
		<?php
	}
}
