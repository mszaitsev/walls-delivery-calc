<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\RussianPost;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Shipments\Modal\CarrierShipmentModalExtensionInterface;

defined( 'ABSPATH' ) || exit;

final class RussianPostShipmentModalExtension implements CarrierShipmentModalExtensionInterface {
	public function carrier_key(): string {
		return RussianPostDomesticSettings::CARRIER_KEY;
	}

	public function modal_context( object $order, array $draft ): array {
		$request = is_array( $draft['request'] ?? null ) ? $draft['request'] : array();
		$meta = is_array( $request['meta'] ?? null ) ? $request['meta'] : array();
		$address = is_array( $request['recipient_address'] ?? null ) ? $request['recipient_address'] : array();
		$pickup = is_array( $request['pickup_point'] ?? null ) ? $request['pickup_point'] : array();
		$pickup_row = is_array( $meta['pickup_point_row'] ?? null ) ? $meta['pickup_point_row'] : array();
		$pickup_context = is_array( $meta['pickup_location_context'] ?? null ) ? $meta['pickup_location_context'] : array();
		$postoffice_codes = is_array( $draft['postoffice_codes'] ?? null ) ? $draft['postoffice_codes'] : array( '630005' );
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
		$pickup_destination_index = $this->pickup_destination_index( $pickup_code, $recipient_postcode, $meta );
		$pickup_demand_address = $this->join_non_empty( array( $pickup_destination_index, $region, $city, 'до востребования' ) );
		$normalized_address = is_array( $meta['normalized_address'] ?? null ) ? $meta['normalized_address'] : array();
		$normalized_status = array() !== $normalized_address
			? ( ! empty( $normalized_address['success'] ) ? 'Адрес обработан Почтой России.' : 'Адрес не подтвержден Почтой России, создание отправления заблокировано.' )
			: 'Адрес нужно обработать перед созданием отправления.';

		return array(
			'requires_tariff' => true,
			'requires_postoffice' => true,
			'requires_successful_preview' => false,
			'selected_tariff_object' => (string) ( $meta['tariff_object'] ?? '' ),
			'selected_service_tariffs' => $this->tariffs_for_delivery_type( is_array( $draft['services'] ?? null ) ? $draft['services'] : array(), (string) ( $request['delivery_type'] ?? $meta['delivery_type'] ?? DeliveryType::PICKUP ) ),
			'selected_tariff_has_declared_value' => false,
			'postoffice_codes' => $postoffice_codes,
			'pickup_code' => $pickup_code,
			'pickup_display_value' => $pickup_destination_index,
			'pickup_postcode' => (string) ( $pickup_row['postcode'] ?? $pickup_destination_index ),
			'pickup_address' => '' !== trim( (string) ( $pickup_row['address'] ?? '' ) ) ? (string) $pickup_row['address'] : $pickup_demand_address,
			'pickup_city' => $city,
			'pickup_region' => $region,
			'pickup_row' => $pickup_row,
			'pickup_context' => $pickup_context,
			'pickup_family' => (string) ( $meta['pickup_family'] ?? RussianPostDomesticSettings::CARRIER_KEY . ':pickup' ),
			'pickup_point_found' => ! empty( $meta['pickup_point_found'] ),
			'pickup_location_postcode' => $recipient_postcode,
			'pickup_location_address' => $recipient_address_context,
			'delivery_city_id' => (string) ( $meta['delivery_city_id'] ?? '' ),
			'normalized_address' => $normalized_address,
			'normalized_json' => wp_json_encode( $normalized_address, JSON_UNESCAPED_UNICODE ) ?: '',
			'normalized_display' => (string) ( $normalized_address['display'] ?? '' ),
			'normalized_status' => $normalized_status,
			'courier_original_address' => (string) ( $meta['courier_original_address'] ?? '' ),
		);
	}

	public function render_fields( object $order, array $draft, array $context ): void {
		unset( $order );
		$request = is_array( $draft['request'] ?? null ) ? $draft['request'] : array();
		$meta = is_array( $request['meta'] ?? null ) ? $request['meta'] : array();
		$postoffice_codes = is_array( $context['postoffice_codes'] ?? null ) ? $context['postoffice_codes'] : array( '630005' );
		?>
		<label><?php echo esc_html__( 'Индекс места приема', 'walls-delivery-calc' ); ?><select name="postoffice_code">
			<?php foreach ( $postoffice_codes as $code ) : ?>
				<option value="<?php echo esc_attr( (string) $code ); ?>" <?php selected( (string) ( $meta['postoffice_code'] ?? '' ), (string) $code ); ?>><?php echo esc_html( (string) $code ); ?></option>
			<?php endforeach; ?>
		</select></label>
		<?php
	}

	public function render_pickup_fields( object $order, array $draft, array $context ): void {
		unset( $order, $draft );
		$pickup_row = is_array( $context['pickup_row'] ?? null ) ? $context['pickup_row'] : array();
		$pickup_context = is_array( $context['pickup_context'] ?? null ) ? $context['pickup_context'] : array();
		?>
		<input type="hidden" name="pickup_point_code" value="<?php echo esc_attr( (string) ( $context['pickup_code'] ?? '' ) ); ?>">
		<input type="hidden" name="pickup_point_postcode" value="<?php echo esc_attr( (string) ( $context['pickup_postcode'] ?? '' ) ); ?>" data-wdc-pickup-postcode-field>
		<input type="hidden" name="pickup_point_address" value="<?php echo esc_attr( (string) ( $context['pickup_address'] ?? '' ) ); ?>" data-wdc-pickup-address-field>
		<input type="hidden" name="pickup_point_city" value="<?php echo esc_attr( (string) ( $context['pickup_city'] ?? '' ) ); ?>" data-wdc-pickup-city-field>
		<input type="hidden" name="pickup_point_region" value="<?php echo esc_attr( (string) ( $context['pickup_region'] ?? '' ) ); ?>" data-wdc-pickup-region-field>
		<?php $this->render_pickup_common_hidden( $pickup_row, $pickup_context, $context ); ?>
		<p><strong><?php echo esc_html__( 'Индекс выбранного ПВЗ / ОПС', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-pickup-index><?php echo esc_html( '' !== (string) ( $context['pickup_display_value'] ?? '' ) ? (string) $context['pickup_display_value'] : '-' ); ?></span></p>
		<p><strong><?php echo esc_html__( 'Адрес ПВЗ / ОПС', 'walls-delivery-calc' ); ?>:</strong> <span data-wdc-pickup-address><?php echo esc_html( '' !== (string) ( $context['pickup_address'] ?? '' ) ? (string) $context['pickup_address'] : '-' ); ?></span></p>
		<p><button type="button" class="button" data-wdc-open-pickup-picker><?php echo esc_html__( 'Выбрать другой ПВЗ', 'walls-delivery-calc' ); ?></button></p>
		<?php if ( empty( $context['pickup_point_found'] ) ) : ?>
			<p class="description wdc-shipment-warning" data-wdc-pickup-warning><?php echo esc_html__( 'ПВЗ/ОПС не найден в справочнике Почты России. Создание отправления заблокировано до выбора корректного ПВЗ.', 'walls-delivery-calc' ); ?></p>
		<?php endif; ?>
		<?php
	}

	public function render_courier_fields( object $order, array $draft, array $context ): void {
		unset( $order, $draft );
		?>
		<?php $this->render_recipient_location_hidden( array(), $context ); ?>
		<label><?php echo esc_html__( 'Оригинальный адрес покупателя', 'walls-delivery-calc' ); ?><textarea name="courier_original_address" rows="3" data-wdc-courier-original-address><?php echo esc_textarea( (string) ( $context['courier_original_address'] ?? '' ) ); ?></textarea></label>
		<button type="button" class="button" data-wdc-normalize-address><?php echo esc_html__( 'Обработать адрес', 'walls-delivery-calc' ); ?></button>
		<input type="hidden" name="normalized_address_json" value="<?php echo esc_attr( (string) ( $context['normalized_json'] ?? '' ) ); ?>" data-wdc-normalized-address-json>
		<p class="description" data-wdc-normalized-status><?php echo esc_html( (string) ( $context['normalized_status'] ?? '' ) ); ?></p>
		<label><span data-wdc-normalized-address-label><?php echo esc_html__( 'Нормализованный адрес Почты России', 'walls-delivery-calc' ); ?></span><textarea rows="3" readonly data-wdc-normalized-address-display><?php echo esc_textarea( (string) ( $context['normalized_display'] ?? '' ) ); ?></textarea></label>
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

	/** @param array<string,mixed> $meta */
	private function pickup_destination_index( string $pickup_code, string $postcode, array $meta ): string {
		$explicit = preg_replace( '/\D+/', '', (string) ( $meta['pickup_point_postcode'] ?? $meta['pickup_postcode'] ?? '' ) ) ?? '';
		if ( 1 === preg_match( '/^\d{6}$/', $explicit ) ) {
			return $explicit;
		}
		if ( 1 === preg_match( '/^(\d{6})/', trim( $pickup_code ), $matches ) ) {
			return (string) $matches[1];
		}
		$postcode = preg_replace( '/\D+/', '', $postcode ) ?? '';

		return 1 === preg_match( '/^\d{6}$/', $postcode ) ? $postcode : '';
	}

	/** @param array<int,string> $values */
	private function join_non_empty( array $values ): string {
		return implode( ', ', array_filter( $values, static fn ( string $value ): bool => '' !== trim( $value ) ) );
	}

	/** @param array<string,mixed> $pickup_row @param array<string,mixed> $pickup_context @param array<string,mixed> $context */
	private function render_pickup_common_hidden( array $pickup_row, array $pickup_context, array $context ): void {
		?>
		<input type="hidden" name="pickup_point_type" value="<?php echo esc_attr( (string) ( $pickup_row['point_type'] ?? '' ) ); ?>" data-wdc-pickup-type-field>
		<input type="hidden" name="pickup_point_title" value="<?php echo esc_attr( (string) ( $pickup_row['display_title'] ?? $pickup_row['point_title'] ?? '' ) ); ?>" data-wdc-pickup-title-field>
		<input type="hidden" name="pickup_point_lat" value="<?php echo esc_attr( (string) ( $pickup_row['lat'] ?? '' ) ); ?>" data-wdc-pickup-lat-field>
		<input type="hidden" name="pickup_point_lng" value="<?php echo esc_attr( (string) ( $pickup_row['lng'] ?? '' ) ); ?>" data-wdc-pickup-lng-field>
		<input type="hidden" name="pickup_carrier_key" value="<?php echo esc_attr( RussianPostDomesticSettings::CARRIER_KEY ); ?>" data-wdc-pickup-carrier-key>
		<input type="hidden" name="pickup_service_key" value="<?php echo esc_attr( RussianPostDomesticSettings::SERVICE_KEY ); ?>" data-wdc-pickup-service-key>
		<input type="hidden" name="pickup_family" value="<?php echo esc_attr( (string) ( $context['pickup_family'] ?? RussianPostDomesticSettings::CARRIER_KEY . ':pickup' ) ); ?>" data-wdc-pickup-family>
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
