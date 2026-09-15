<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Dpd;

use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Shipments\Modal\CarrierShipmentModalExtensionInterface;

defined( 'ABSPATH' ) || exit;

final class DpdShipmentModalExtension implements CarrierShipmentModalExtensionInterface {
	/** @param callable():array<int,string> $contact_history */
	public function __construct( private mixed $contact_history = null ) {
	}

	public function carrier_key(): string {
		return DpdSettings::CARRIER_KEY;
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
		$sender_terminal = is_array( $meta['sender_terminal'] ?? null ) ? $meta['sender_terminal'] : array();
		$shipment_point = (string) ( $meta['shipment_point'] ?? $meta['pickup_terminal_code'] ?? '' );
		$shipment_point_address = (string) ( $meta['shipment_point_address'] ?? $sender_terminal['address'] ?? '' );
		$sender_contact_fio = (string) ( $meta['sender_contact_fio'] ?? '' );
		$history = is_callable( $this->contact_history ) ? (array) call_user_func( $this->contact_history ) : array();
		if ( '' === trim( $sender_contact_fio ) ) {
			$sender_contact_fio = (string) ( $history[0] ?? '' );
		}
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
		$pickup_code = (string) ( $pickup['point_code'] ?? $meta['pickup_point_code'] ?? '' );
		$normalized_address = is_array( $meta['normalized_address'] ?? null ) ? $meta['normalized_address'] : array();
		$normalized_is_dpd = 'dadata+dpd' === (string) ( $normalized_address['source'] ?? '' ) || DpdSettings::SERVICE_KEY === (string) ( $normalized_address['service_key'] ?? '' );
		$normalized_status = array() !== $normalized_address
			? ( ! empty( $normalized_address['success'] ) ? 'Данные для DPD корректны' : (string) ( $normalized_address['message'] ?? 'Адрес не подтвержден DPD, предпросмотр payload заблокирован.' ) )
			: 'Адрес нужно обработать перед предпросмотром payload.';

		return array(
			'requires_tariff' => true,
			'requires_successful_preview' => true,
			'modal_create_button_label' => __( 'Создать отправление DPD', 'walls-delivery-calc' ),
			'selected_tariff_object' => $tariff_object,
			'selected_service_tariffs' => $tariffs,
			'selected_tariff_title' => $this->tariff_title( $tariffs, $tariff_object, (string) ( $meta['selected_tariff_title'] ?? $meta['tariff_title'] ?? '' ) ),
			'selected_tariff_has_declared_value' => $this->tariff_has_declared_value( $tariffs, $tariff_object ),
			'shipment_point' => $shipment_point,
			'shipment_point_address' => $shipment_point_address,
			'shipment_point_display' => $this->join_non_empty( array( $shipment_point, $shipment_point_address ) ),
			'sender_terminal' => $sender_terminal,
			'sender_contact_fio' => $sender_contact_fio,
			'delivery_type' => $delivery_type,
			'pickup_code' => $pickup_code,
			'pickup_postcode' => (string) ( $pickup_row['postcode'] ?? $pickup_code ),
			'pickup_address' => $recipient_address_context,
			'pickup_city' => $city,
			'pickup_region' => $region,
			'pickup_row' => $pickup_row,
			'pickup_context' => $pickup_context,
			'pickup_family' => (string) ( $meta['pickup_family'] ?? DpdSettings::CARRIER_KEY . ':pickup' ),
			'pickup_point_found' => ! empty( $meta['pickup_point_found'] ),
			'pickup_location_postcode' => $recipient_postcode,
			'pickup_location_address' => $recipient_address_context,
			'delivery_city_id' => (string) ( $meta['delivery_city_id'] ?? '' ),
			'normalized_address' => $normalized_address,
			'normalized_json' => wp_json_encode( $normalized_address, JSON_UNESCAPED_UNICODE ) ?: '',
			'normalized_display' => (string) ( $normalized_address['display'] ?? '' ),
			'normalized_status' => $normalized_status,
			'normalized_is_dpd' => $normalized_is_dpd,
			'courier_original_address' => (string) ( $meta['courier_original_address'] ?? '' ),
		);
	}

	public function render_fields( object $order, array $draft, array $context ): void {
		unset( $order );
		$request = is_array( $draft['request'] ?? null ) ? $draft['request'] : array();
		$meta = is_array( $request['meta'] ?? null ) ? $request['meta'] : array();
		$sender_terminal = is_array( $context['sender_terminal'] ?? null ) ? $context['sender_terminal'] : array();
		?>
		<p><strong><?php echo esc_html__( 'В заказе тариф', 'walls-delivery-calc' ); ?>:</strong> <?php echo esc_html( '' !== (string) ( $context['selected_tariff_title'] ?? '' ) ? (string) $context['selected_tariff_title'] : '-' ); ?></p>
		<input type="hidden" name="pickup_terminal_code" value="<?php echo esc_attr( (string) ( $meta['pickup_terminal_code'] ?? $context['shipment_point'] ?? '' ) ); ?>" data-wdc-sender-shipment-point>
		<input type="hidden" name="shipment_point" value="<?php echo esc_attr( (string) ( $context['shipment_point'] ?? '' ) ); ?>">
		<input type="hidden" name="sender_shipment_point" value="<?php echo esc_attr( (string) ( $context['shipment_point'] ?? '' ) ); ?>">
		<input type="hidden" name="shipment_point_address" value="<?php echo esc_attr( (string) ( $context['shipment_point_address'] ?? '' ) ); ?>" data-wdc-sender-shipment-point-address>
		<input type="hidden" name="sender_shipment_point_address" value="<?php echo esc_attr( (string) ( $context['shipment_point_address'] ?? '' ) ); ?>">
		<input type="hidden" name="sender_pickup_city_id" value="<?php echo esc_attr( (string) ( $meta['pickup_city_id'] ?? '' ) ); ?>" data-wdc-sender-pickup-city-id>
		<input type="hidden" name="sender_pickup_city" value="<?php echo esc_attr( (string) ( $sender_terminal['city_name'] ?? '' ) ); ?>" data-wdc-sender-pickup-city>
		<p><strong><?php echo esc_html__( 'ПВЗ отправителя', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-sender-shipment-point-display><?php echo esc_html( '' !== (string) ( $context['shipment_point_display'] ?? '' ) ? (string) $context['shipment_point_display'] : '-' ); ?></span></p>
		<p><button type="button" class="button" data-wdc-open-sender-pickup-picker><?php echo esc_html__( 'Выбрать другой ПВЗ отправителя', 'walls-delivery-calc' ); ?></button></p>
		<label class="wdc-dpd-date-field">
			<span class="wdc-dpd-date-label"><?php echo esc_html__( 'Дата отправки', 'walls-delivery-calc' ); ?></span>
			<span class="wdc-dpd-date-row">
				<input type="date" name="date_pickup" value="<?php echo esc_attr( (string) ( $meta['date_pickup'] ?? '' ) ); ?>" data-wdc-dpd-date-pickup>
				<button type="button" class="button button-small" data-wdc-date-step="-1" aria-label="<?php echo esc_attr__( 'На день назад', 'walls-delivery-calc' ); ?>">−</button>
				<button type="button" class="button button-small" data-wdc-date-step="1" aria-label="<?php echo esc_attr__( 'На день вперед', 'walls-delivery-calc' ); ?>">+</button>
			</span>
		</label>
		<label class="wdc-dpd-courier-contact-field"><?php echo esc_html__( 'ФИО курьера', 'walls-delivery-calc' ); ?><input type="text" name="sender_contact_fio" value="<?php echo esc_attr( (string) ( $context['sender_contact_fio'] ?? '' ) ); ?>" autocomplete="off" data-wdc-dpd-contact-fio><span class="wdc-dpd-contact-history" data-wdc-dpd-contact-history hidden></span></label>
		<label data-wdc-dpd-courier-instructions-row <?php echo DeliveryType::COURIER === (string) ( $context['delivery_type'] ?? '' ) ? '' : 'hidden'; ?>><?php echo esc_html__( 'Комментарии курьеру', 'walls-delivery-calc' ); ?><textarea name="courier_instructions" rows="2" maxlength="250" data-wdc-dpd-courier-instructions></textarea><span class="description"><?php echo esc_html__( 'Только для DPD courier instructions. Не более 250 символов.', 'walls-delivery-calc' ); ?></span></label>
		<?php if ( ! empty( $meta['date_pickup_fallback_used'] ) ) : ?>
			<p class="description wdc-shipment-warning"><?php echo esc_html__( 'Календарь магазина недоступен, дата отправки DPD рассчитана по fallback-правилу.', 'walls-delivery-calc' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function render_pickup_fields( object $order, array $draft, array $context ): void {
		unset( $order, $draft );
		$pickup_row = is_array( $context['pickup_row'] ?? null ) ? $context['pickup_row'] : array();
		$pickup_context = is_array( $context['pickup_context'] ?? null ) ? $context['pickup_context'] : array();
		$pickup_code = (string) ( $context['pickup_code'] ?? '' );
		?>
		<input type="hidden" name="pickup_point_code" value="<?php echo esc_attr( $pickup_code ); ?>">
		<input type="hidden" name="delivery_point" value="<?php echo esc_attr( $pickup_code ); ?>" data-wdc-delivery-point-field>
		<input type="hidden" name="pickup_point_postcode" value="<?php echo esc_attr( (string) ( $context['pickup_postcode'] ?? '' ) ); ?>" data-wdc-pickup-postcode-field>
		<input type="hidden" name="pickup_point_address" value="<?php echo esc_attr( (string) ( $context['pickup_address'] ?? '' ) ); ?>" data-wdc-pickup-address-field>
		<input type="hidden" name="pickup_point_city" value="<?php echo esc_attr( (string) ( $context['pickup_city'] ?? '' ) ); ?>" data-wdc-pickup-city-field>
		<input type="hidden" name="pickup_point_region" value="<?php echo esc_attr( (string) ( $context['pickup_region'] ?? '' ) ); ?>" data-wdc-pickup-region-field>
		<?php $this->render_pickup_common_hidden( $pickup_row, $pickup_context, $context, DpdSettings::CARRIER_KEY, DpdSettings::SERVICE_KEY, DpdSettings::CARRIER_KEY . ':pickup' ); ?>
		<p><strong><?php echo esc_html__( 'Код ПВЗ', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-pickup-index><?php echo esc_html( '' !== $pickup_code ? $pickup_code : '-' ); ?></span></p>
		<p><strong><?php echo esc_html__( 'Адрес ПВЗ', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-pickup-address><?php echo esc_html( '' !== (string) ( $context['pickup_address'] ?? '' ) ? (string) $context['pickup_address'] : '-' ); ?></span></p>
		<p><button type="button" class="button" data-wdc-open-pickup-picker><?php echo esc_html__( 'Выбрать другой ПВЗ', 'walls-delivery-calc' ); ?></button></p>
		<?php if ( empty( $context['pickup_point_found'] ) ) : ?>
			<p class="description wdc-shipment-warning" data-wdc-pickup-warning><?php echo esc_html__( 'DPD delivery terminalCode не найден. Исправьте выбранный ПВЗ в заказе или checkout meta.', 'walls-delivery-calc' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function render_courier_fields( object $order, array $draft, array $context ): void {
		unset( $order, $draft );
		$normalized_address = is_array( $context['normalized_address'] ?? null ) ? $context['normalized_address'] : array();
		$fields = is_array( $normalized_address['fields'] ?? null ) ? $normalized_address['fields'] : array();
		?>
		<?php $this->render_recipient_location_hidden( array(), $context ); ?>
		<label><?php echo esc_html__( 'Оригинальный адрес покупателя', 'walls-delivery-calc' ); ?><textarea name="courier_original_address" rows="3" data-wdc-courier-original-address><?php echo esc_textarea( (string) ( $context['courier_original_address'] ?? '' ) ); ?></textarea></label>
		<button type="button" class="button" data-wdc-normalize-address><?php echo esc_html__( 'Обработать адрес', 'walls-delivery-calc' ); ?></button>
		<input type="hidden" name="normalized_address_json" value="<?php echo esc_attr( (string) ( $context['normalized_json'] ?? '' ) ); ?>" data-wdc-normalized-address-json>
		<?php foreach ( array( 'countryName', 'index', 'region', 'city', 'street', 'streetAbbr', 'house', 'houseKorpus', 'str', 'vlad', 'extraInfo', 'office', 'flat' ) as $dpd_address_field ) : ?>
			<input type="hidden" name="dpd_address[<?php echo esc_attr( $dpd_address_field ); ?>]" value="<?php echo esc_attr( (string) ( $fields[ $dpd_address_field ] ?? '' ) ); ?>" data-wdc-dpd-address-field="<?php echo esc_attr( $dpd_address_field ); ?>">
		<?php endforeach; ?>
		<p class="description" data-wdc-normalized-status><?php echo esc_html( (string) ( $context['normalized_status'] ?? '' ) ); ?></p>
		<label><span data-wdc-normalized-address-label><?php echo esc_html__( 'Нормализованный адрес DPD', 'walls-delivery-calc' ); ?></span><textarea rows="3" readonly data-wdc-normalized-address-display><?php echo esc_textarea( (string) ( $context['normalized_display'] ?? '' ) ); ?></textarea></label>
		<p class="description" data-wdc-cdek-city-code-row hidden><?php echo esc_html__( 'Код города СДЭК', 'walls-delivery-calc' ); ?>: <span data-wdc-cdek-city-code></span></p>
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

	/** @param array<int,string> $values */
	private function join_non_empty( array $values ): string {
		return implode( ', ', array_filter( $values, static fn ( string $value ): bool => '' !== trim( $value ) ) );
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
