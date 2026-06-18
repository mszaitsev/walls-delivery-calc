<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Cdek\Tariffs\CdekTariffRepository;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointService;
use WallsShop\WDC\Carriers\Runtime\CdekCarrier;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Pickup\PickupPointSelection;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Shipments\RussianPost\RussianPostShipmentProductMapper;

defined( 'ABSPATH' ) || exit;

final class OrderShipmentDraftFactory {
	public function __construct(
		private DeliveryServiceRepository $services,
		private ShipmentServiceSettings $shipment_settings,
		private ?RussianPostDomesticSettings $domestic_settings = null,
		private ?RussianPostOtpravkaApiSettings $otpravka_settings = null,
		private ?RussianPostPickupPointRepository $pickup_points = null,
		private ?CdekSettings $cdek_settings = null,
		private ?CdekTariffRepository $cdek_tariffs = null,
		private ?DpdSettings $dpd_settings = null,
		private ?DpdPickupPointService $dpd_pickup_points = null
	) {
	}

	public function create_request_from_order( object $order ): ShipmentCreateRequest {
		$carrier_key = $this->active_carrier_key( $order );
		if ( CdekSettings::CARRIER_KEY === $carrier_key ) {
			return $this->create_cdek_request_from_order( $order );
		}
		if ( DpdSettings::CARRIER_KEY === $carrier_key ) {
			return $this->create_dpd_request_from_order( $order );
		}
		$service_key = RussianPostDomesticSettings::SERVICE_KEY;
		$delivery_type = $this->delivery_type_from_order( $order );
		$service = $this->services->find_by_service_key( $service_key );
		$items = $this->order_items( $order );
		$weight = $this->default_weight_g( $order, $items );
		$default_declared_value_rub = $this->default_declared_value_rub( $items );
		$declared_value = Money::from_kopecks( 0 );
		$place = new ShipmentPlace( 1, $weight, 0, 0, 0, $declared_value, $items );
		$settings = $this->shipment_settings->for_service( $service );
		$order_number = $this->order_number( $order );
		$settings['shelf_life_days'] = (int) ( $settings[ ShipmentServiceSettings::SHELF_LIFE_DAYS_DEFAULT ] ?? 30 );
		$settings['combine_goods_items'] = ! empty( $settings[ ShipmentServiceSettings::COMBINE_GOODS_ITEMS_DEFAULT ] );
		$settings['combined_goods_name_template'] = (string) ( $settings[ ShipmentServiceSettings::COMBINED_GOODS_NAME_TEMPLATE ] ?? 'Товары по заказу {order_number}' );
		$settings['combined_goods_name'] = str_replace( '{order_number}', $order_number, $settings['combined_goods_name_template'] );
		$tariff_object = $this->meta_string( $order, '_wdc_platform_tariff_object' );
		$tariff = $this->tariff_for_service_object( $service, $tariff_object, $delivery_type );
		$pickup_row = DeliveryType::PICKUP === $delivery_type ? $this->pickup_point_row( $order ) : null;
		$original_address = DeliveryType::COURIER === $delivery_type ? $this->shipping_address( $order ) : '';
		$normalized_address = DeliveryType::COURIER === $delivery_type ? $this->cached_normalized_address( $order, $service_key, $original_address ) : array();

		return new ShipmentCreateRequest(
			order_id: $this->order_id( $order ),
			carrier_key: RussianPostDomesticSettings::CARRIER_KEY,
			delivery_type: $delivery_type,
			rate_id: RussianPostDomesticSettings::checkout_group_id( $delivery_type ),
			recipient_address: $this->recipient_address( $order, $delivery_type, $pickup_row, $normalized_address ),
			pickup_point: DeliveryType::PICKUP === $delivery_type ? $this->pickup_point( $order ) : null,
			places: array( $place ),
			declared_value: $declared_value,
			services: $settings,
			recipient: array(
				'name' => $this->recipient_name( $order ),
				'phone' => $this->phone( $order ),
				'email' => $this->email( $order ),
			),
			meta: array(
				'service_key' => $service_key,
				'delivery_type' => $delivery_type,
				'service_title' => $service instanceof DeliveryService ? $service->title : $this->meta_string( $order, '_wdc_platform_service_title' ),
				'tariff_object' => $tariff_object,
				'tariff_title' => (string) ( $tariff['title'] ?? $this->meta_string( $order, '_wdc_platform_tariff_title' ) ),
				'tariff_is_ecom' => ! empty( $tariff['is_ecom'] ),
				'tariff_has_declared_value' => ! empty( $tariff['has_declared_value'] ),
				'default_declared_value_rub' => $default_declared_value_rub,
				'order_num' => $order_number,
				'postoffice_code' => $this->from_postcode( $service_key ),
				'pickup_point_code' => $this->meta_string( $order, '_wdc_pickup_point_code' ),
				'pickup_point_postcode' => $this->meta_string( $order, '_wdc_pickup_point_postcode' ),
				'pickup_point_found' => is_array( $pickup_row ),
				'pickup_point_row' => is_array( $pickup_row ) ? $this->safe_pickup_row( $pickup_row ) : array(),
				'courier_original_address' => $original_address,
				'courier_original_hash' => $this->original_address_hash( $original_address ),
				'normalized_address' => $normalized_address,
				'normalization_required' => DeliveryType::COURIER === $delivery_type,
				'normalization_valid' => DeliveryType::COURIER === $delivery_type && ! empty( $normalized_address['success'] ) && (string) ( $normalized_address['original_hash'] ?? '' ) === $this->original_address_hash( $original_address ),
				'normalization_attempted' => DeliveryType::COURIER === $delivery_type && array() !== $normalized_address,
				'calculation_data' => $this->calculation_data( $order ),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function draft_array( object $order ): array {
		$request = $this->create_request_from_order( $order );
		if ( CdekSettings::CARRIER_KEY === $request->carrier_key ) {
			return array(
				'request' => $request->to_array(),
				'services' => $this->cdek_service_variants( $request ),
				'postoffice_codes' => array(),
			);
		}
		if ( DpdSettings::CARRIER_KEY === $request->carrier_key ) {
			return array(
				'request' => $request->to_array(),
				'services' => $this->dpd_service_variants( $request ),
				'postoffice_codes' => array(),
			);
		}
		$service = $this->services->find_by_service_key( RussianPostDomesticSettings::SERVICE_KEY );
		$service_variants = array();
		if ( $service instanceof DeliveryService ) {
			foreach ( array( DeliveryType::PICKUP, DeliveryType::COURIER ) as $delivery_type ) {
				$service_variants[] = array(
					'service_key' => $service->service_key,
					'group_id' => RussianPostDomesticSettings::checkout_group_id( $delivery_type ),
					'title' => DeliveryType::COURIER === $delivery_type ? RussianPostDomesticSettings::COURIER_SERVICE_TITLE : RussianPostDomesticSettings::PICKUP_SERVICE_TITLE,
					'delivery_type' => $delivery_type,
					'tariffs' => $this->tariffs_for_service( $service, $delivery_type ),
				);
			}
		}

		return array(
			'request' => $request->to_array(),
			'services' => $service_variants,
			'postoffice_codes' => $this->postoffice_codes(),
		);
	}

	public function create_request_from_admin_data( object $order, array $data ): ShipmentCreateRequest {
		$base = $this->create_request_from_order( $order );
		if ( CdekSettings::CARRIER_KEY === $base->carrier_key ) {
			return $this->create_cdek_request_from_admin_data( $base, $data );
		}
		if ( DpdSettings::CARRIER_KEY === $base->carrier_key ) {
			return $this->create_dpd_request_from_admin_data( $base, $data );
		}
		$service_key = RussianPostDomesticSettings::SERVICE_KEY;
		$service = $this->services->find_by_service_key( $service_key );
		$delivery_type = RussianPostDomesticSettings::normalize_delivery_type( sanitize_key( wp_unslash( $data['delivery_type'] ?? $base->delivery_type ) ) );
		$tariff_object = sanitize_text_field( wp_unslash( $data['tariff_object'] ?? $base->meta['tariff_object'] ?? '' ) );
		$tariff = $this->tariff_for_service_object( $service, $tariff_object, $delivery_type );
		$tariff_has_declared_value = ! empty( $tariff['has_declared_value'] );
		$places = array();
		$place_rows = is_array( $data['places'] ?? null ) ? $data['places'] : array();
		foreach ( $place_rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$places[] = new ShipmentPlace(
				$index + 1,
				$this->whole_number_from_place_row( $row, 'weight_g' ),
				$this->whole_number_from_place_row( $row, 'length_cm' ),
				$this->whole_number_from_place_row( $row, 'width_cm' ),
				$this->whole_number_from_place_row( $row, 'height_cm' ),
				$tariff_has_declared_value ? $this->declared_value_from_place_row( $row ) : Money::from_kopecks( 0 ),
				0 === $index ? ( $base->places[0]->items ?? array() ) : array()
			);
		}
		$settings = $this->shipment_settings->for_service( $service );
		$settings['shelf_life_days'] = max( 15, min( 60, (int) ( $settings[ ShipmentServiceSettings::SHELF_LIFE_DAYS_DEFAULT ] ?? 30 ) ) );
		$settings['send_goods_items'] = ! empty( $data['send_goods_items'] ) && ! empty( $settings[ ShipmentServiceSettings::SEND_GOODS_ITEMS ] );
		$settings['combine_goods_items'] = ! empty( $data['combine_goods_items'] );
		$settings['combined_goods_name'] = sanitize_text_field( wp_unslash( $data['combined_goods_name'] ?? $settings[ ShipmentServiceSettings::COMBINED_GOODS_NAME_TEMPLATE ] ?? '' ) );
		$original_address = sanitize_text_field( wp_unslash( $data['courier_original_address'] ?? $base->meta['courier_original_address'] ?? '' ) );
		$normalized_address = $this->normalized_address_from_admin_data( $data, $original_address, $service_key );
		$admin_pickup_row = DeliveryType::PICKUP === $delivery_type ? $this->pickup_row_from_admin_data( $data, $base->meta ) : array();

		return new ShipmentCreateRequest(
			$base->order_id,
			$base->carrier_key,
			$delivery_type,
			RussianPostDomesticSettings::checkout_group_id( $delivery_type ),
			$this->address_from_admin_data( $base->recipient_address, $data, $delivery_type, $base->meta, $normalized_address, $original_address, $admin_pickup_row ),
			DeliveryType::PICKUP === $delivery_type ? $this->pickup_from_admin_data( $base->pickup_point, $data ) : null,
			array() !== $places ? $places : $base->places,
			$base->declared_value,
			false,
			$settings,
			array(
				'name' => sanitize_text_field( wp_unslash( $data['recipient_name'] ?? $base->recipient['name'] ?? '' ) ),
				'phone' => sanitize_text_field( wp_unslash( $data['recipient_phone'] ?? $base->recipient['phone'] ?? '' ) ),
				'email' => sanitize_email( wp_unslash( $data['recipient_email'] ?? $base->recipient['email'] ?? '' ) ),
			),
			array_merge(
				$base->meta,
				array(
					'service_key' => $service_key,
					'delivery_type' => $delivery_type,
					'service_title' => $service instanceof DeliveryService ? $service->title : (string) ( $base->meta['service_title'] ?? '' ),
					'tariff_object' => $tariff_object,
					'tariff_title' => (string) ( $tariff['title'] ?? $base->meta['tariff_title'] ?? '' ),
					'tariff_is_ecom' => ! empty( $tariff['is_ecom'] ),
					'tariff_has_declared_value' => $tariff_has_declared_value,
					'postoffice_code' => preg_replace( '/\D+/', '', (string) wp_unslash( $data['postoffice_code'] ?? $base->meta['postoffice_code'] ?? '' ) ) ?: '',
					'courier_original_address' => $original_address,
					'courier_original_hash' => $this->original_address_hash( $original_address ),
					'normalized_address' => $normalized_address,
					'normalization_required' => DeliveryType::COURIER === $delivery_type,
					'normalization_valid' => DeliveryType::COURIER === $delivery_type && ! empty( $normalized_address['success'] ) && (string) ( $normalized_address['original_hash'] ?? '' ) === $this->original_address_hash( $original_address ),
					'normalization_attempted' => DeliveryType::COURIER === $delivery_type && array() !== $normalized_address,
					'pickup_point_code' => DeliveryType::PICKUP === $delivery_type ? (string) ( $admin_pickup_row['point_code'] ?? $base->meta['pickup_point_code'] ?? '' ) : (string) ( $base->meta['pickup_point_code'] ?? '' ),
					'pickup_point_postcode' => DeliveryType::PICKUP === $delivery_type ? (string) ( $admin_pickup_row['postcode'] ?? $base->meta['pickup_point_postcode'] ?? '' ) : (string) ( $base->meta['pickup_point_postcode'] ?? '' ),
					'pickup_point_found' => DeliveryType::PICKUP === $delivery_type ? array() !== $admin_pickup_row : ! empty( $base->meta['pickup_point_found'] ),
					'pickup_point_row' => DeliveryType::PICKUP === $delivery_type ? $this->safe_pickup_row( $admin_pickup_row ) : (array) ( $base->meta['pickup_point_row'] ?? array() ),
				)
			)
		);
	}

	/**
	 * @return array<int,PackageItem>
	 */
	private function order_items( object $order ): array {
		if ( ! method_exists( $order, 'get_items' ) ) {
			return array();
		}
		$items = array();
		foreach ( $order->get_items() as $item ) {
			if ( ! is_object( $item ) ) {
				continue;
			}
			$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
			$qty = method_exists( $item, 'get_quantity' ) ? max( 1, (int) $item->get_quantity() ) : 1;
			$total = method_exists( $item, 'get_total' ) ? (float) $item->get_total() : 0.0;
			$weight_g = is_object( $product ) && method_exists( $product, 'get_weight' ) ? (int) round( (float) str_replace( ',', '.', (string) $product->get_weight() ) * 1000 ) : 0;
			$length_cm = $this->product_dimension_cm( $product, 'get_length' );
			$width_cm = $this->product_dimension_cm( $product, 'get_width' );
			$height_cm = $this->product_dimension_cm( $product, 'get_height' );
			$items[] = new PackageItem(
				is_object( $product ) && method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '',
				method_exists( $item, 'get_name' ) ? (string) $item->get_name() : 'Товар',
				$qty,
				Money::from_rubles( $qty > 0 ? $total / $qty : $total ),
				Money::from_rubles( $total ),
				$weight_g,
				$length_cm,
				$width_cm,
				$height_cm
			);
		}

		return $items;
	}

	private function product_dimension_cm( mixed $product, string $getter ): int {
		if ( ! is_object( $product ) || ! method_exists( $product, $getter ) ) {
			return 0;
		}

		$value = (string) $product->{$getter}();
		if ( '' === trim( $value ) ) {
			return 0;
		}

		$dimension = function_exists( 'wc_get_dimension' ) ? (float) wc_get_dimension( $value, 'cm' ) : (float) str_replace( ',', '.', $value );
		return max( 0, (int) round( $dimension ) );
	}

	private function create_cdek_request_from_order( object $order ): ShipmentCreateRequest {
		$calculation = $this->calculation_data( $order );
		$delivery_type = $this->delivery_type_from_order( $order );
		$items = $this->order_items( $order );
		$weight = 0;
		foreach ( $items as $item ) {
			$weight += $item instanceof PackageItem ? $item->get_total_weight_g() : 0;
		}
		$dimensions = is_array( $calculation['package']['dimensions_cm'] ?? null ) ? $calculation['package']['dimensions_cm'] : array();
		$place = new ShipmentPlace(
			1,
			max( 1, $weight ?: (int) ( $calculation['package']['products_weight_g'] ?? 1000 ) ),
			max( 1, (int) ( $dimensions['length'] ?? 20 ) ),
			max( 1, (int) ( $dimensions['width'] ?? 20 ) ),
			max( 1, (int) ( $dimensions['height'] ?? 10 ) ),
			Money::from_kopecks( 0 ),
			$items
		);
		$pickup = is_array( $calculation['pickup'] ?? null ) ? $calculation['pickup'] : array();
		$api = is_array( $calculation['api'] ?? null ) ? $calculation['api'] : array();
		$rate_meta = $this->rate_meta_data( $order );
		$tariff_code = preg_replace( '/\D+/', '', (string) ( $calculation['selected_tariff_object'] ?? $this->meta_string( $order, '_wdc_platform_tariff_object' ) ) ) ?: '';
		$tariff_row = $this->cdek_tariff_row( $tariff_code );
		$response_tariff = is_array( $api['response_tariff_sanitized'] ?? null ) ? $api['response_tariff_sanitized'] : array();
		$delivery_mode = $this->cdek_delivery_mode_from_calculation( $delivery_type, $calculation, $api, $response_tariff, $tariff_row );
		$cdek_to_city_code = $this->cdek_city_code_from_saved_data( $calculation, $rate_meta );
		$tariff_title = $this->cdek_tariff_title( $tariff_row, $tariff_code, (string) ( $calculation['selected_tariff_title'] ?? $response_tariff['tariff_name'] ?? '' ) );
		$pickup_code = $this->cdek_pickup_code( $pickup, $this->meta_string( $order, '_wdc_platform_pickup_code' ) ?: $this->meta_string( $order, '_wdc_pickup_point_code' ) );
		$pickup_row = $this->cdek_pickup_row( $pickup );
		$location_context = $this->recipient_location_context( $order, $pickup_row );

		return new ShipmentCreateRequest(
			order_id: $this->order_id( $order ),
			carrier_key: CdekSettings::CARRIER_KEY,
			delivery_type: $delivery_type,
			rate_id: CdekCarrier::checkout_group_id( $delivery_type ),
			recipient_address: $this->recipient_address( $order, $delivery_type, $pickup_row ),
			pickup_point: DeliveryType::PICKUP === $delivery_type && '' !== $pickup_code ? new PickupPointSelection( CdekSettings::CARRIER_KEY, CdekSettings::SERVICE_KEY, $pickup_code, (string) ( $pickup['point_address'] ?? '' ), $this->now() ) : null,
			places: array( $place ),
			declared_value: Money::from_kopecks( 0 ),
			services: array(),
			recipient: array(
				'name' => $this->recipient_name( $order ),
				'phone' => $this->phone( $order ),
				'email' => $this->email( $order ),
			),
			meta: array(
				'carrier_key' => CdekSettings::CARRIER_KEY,
				'service_key' => CdekSettings::SERVICE_KEY,
				'delivery_type' => $delivery_type,
				'service_title' => CdekSettings::TITLE,
				'tariff_object' => $tariff_code,
				'tariff_code' => $tariff_code,
				'tariff_title' => $tariff_title,
				'selected_tariff_title' => $tariff_title,
				'delivery_mode' => $delivery_mode,
				'cdek_delivery_mode' => $delivery_mode,
				'place_weight_hint_g' => $this->default_weight_g( $order, $items ),
				'cdek_to_city_code' => $cdek_to_city_code,
				'sender_city_code' => $this->cdek_settings instanceof CdekSettings ? $this->cdek_settings->sender_city_code() : 0,
				'sender_city_name' => $this->cdek_settings instanceof CdekSettings ? $this->cdek_settings->sender_city_name() : '',
				'sender_postal_code' => $this->cdek_settings instanceof CdekSettings ? $this->cdek_settings->sender_postal_code() : '',
				'sender_address' => $this->cdek_settings instanceof CdekSettings ? $this->cdek_settings->sender_address() : '',
				'shipment_point' => $this->cdek_settings instanceof CdekSettings ? $this->cdek_settings->shipment_point() : '',
				'shipment_point_address' => $this->cdek_settings instanceof CdekSettings ? $this->cdek_settings->shipment_point_address() : '',
				'delivery_point' => $pickup_code,
				'pickup_point_code' => $pickup_code,
				'pickup_point_postcode' => (string) ( $pickup['point_postcode'] ?? '' ),
				'pickup_point_found' => '' !== $pickup_code,
				'pickup_point_row' => $pickup_row,
				'pickup_family' => CdekSettings::CARRIER_KEY . ':pickup',
				'pickup_location_context' => $location_context,
				'courier_original_address' => $this->shipping_address( $order ),
				'order_num' => $this->order_number( $order ),
				'calculation_data' => $calculation,
				'rate_meta' => $rate_meta,
			)
		);
	}

	private function create_cdek_request_from_admin_data( ShipmentCreateRequest $base, array $data ): ShipmentCreateRequest {
		$delivery_type = RussianPostDomesticSettings::normalize_delivery_type( sanitize_key( wp_unslash( $data['delivery_type'] ?? $base->delivery_type ) ) );
		$tariff_code = preg_replace( '/\D+/', '', (string) wp_unslash( $data['tariff_object'] ?? $base->meta['tariff_code'] ?? '' ) ) ?: '';
		$tariff_row = $this->cdek_tariff_row( $tariff_code );
		$tariff_title = $this->cdek_tariff_title( $tariff_row, $tariff_code, (string) ( $base->meta['tariff_title'] ?? '' ) );
		$delivery_mode = $this->cdek_delivery_mode_from_calculation(
			$delivery_type,
			is_array( $base->meta['calculation_data'] ?? null ) ? $base->meta['calculation_data'] : array(),
			is_array( $base->meta['calculation_data']['api'] ?? null ) ? $base->meta['calculation_data']['api'] : array(),
			array(),
			$tariff_row
		);
		$shipment_point = preg_replace( '/[^A-Z0-9_\-]/', '', strtoupper( sanitize_text_field( wp_unslash( $data['shipment_point'] ?? $data['sender_shipment_point'] ?? $base->meta['shipment_point'] ?? '' ) ) ) ) ?? '';
		$shipment_point_address = sanitize_text_field( wp_unslash( $data['shipment_point_address'] ?? $data['sender_shipment_point_address'] ?? $base->meta['shipment_point_address'] ?? '' ) );
		$pickup_row = DeliveryType::PICKUP === $delivery_type ? $this->cdek_pickup_row_from_admin_data( $data, $base->meta ) : array();
		$pickup_code = DeliveryType::PICKUP === $delivery_type ? (string) ( $pickup_row['point_code'] ?? $base->meta['pickup_point_code'] ?? '' ) : '';
		$original_address = sanitize_text_field( wp_unslash( $data['courier_original_address'] ?? $base->meta['courier_original_address'] ?? '' ) );
		$normalized_address = DeliveryType::COURIER === $delivery_type ? $this->normalized_address_from_admin_data( $data, $original_address, CdekSettings::SERVICE_KEY ) : array();
		$places = array();
		foreach ( is_array( $data['places'] ?? null ) ? $data['places'] : array() as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$places[] = new ShipmentPlace(
				$index + 1,
				$this->whole_number_from_place_row( $row, 'weight_g' ),
				$this->whole_number_from_place_row( $row, 'length_cm' ),
				$this->whole_number_from_place_row( $row, 'width_cm' ),
				$this->whole_number_from_place_row( $row, 'height_cm' ),
				Money::from_kopecks( 0 ),
				0 === $index ? ( $base->places[0]->items ?? array() ) : array()
			);
		}
		$cdek_items = $this->cdek_item_rows_from_admin_data( $data );

		return new ShipmentCreateRequest(
			$base->order_id,
			CdekSettings::CARRIER_KEY,
			$delivery_type,
			$base->rate_id,
			DeliveryType::PICKUP === $delivery_type && array() !== $pickup_row ? $this->address_from_admin_data( $base->recipient_address, $data, $delivery_type, $base->meta, array(), '', $pickup_row ) : $this->cdek_courier_address_from_normalized( $base->recipient_address, $normalized_address ),
			DeliveryType::PICKUP === $delivery_type && '' !== $pickup_code ? new PickupPointSelection( CdekSettings::CARRIER_KEY, CdekSettings::SERVICE_KEY, $pickup_code, (string) ( $pickup_row['address'] ?? '' ), $base->pickup_point?->selected_at ?: $this->now() ) : null,
			array() !== $places ? $places : $base->places,
			$base->declared_value,
			false,
			array(),
			array(
				'name' => sanitize_text_field( wp_unslash( $data['recipient_name'] ?? $base->recipient['name'] ?? '' ) ),
				'phone' => sanitize_text_field( wp_unslash( $data['recipient_phone'] ?? $base->recipient['phone'] ?? '' ) ),
				'email' => sanitize_email( wp_unslash( $data['recipient_email'] ?? $base->recipient['email'] ?? '' ) ),
			),
			array_merge(
				$base->meta,
				array(
					'tariff_code' => $tariff_code,
					'tariff_object' => $tariff_code,
					'tariff_title' => $tariff_title,
					'selected_tariff_title' => (string) ( $base->meta['selected_tariff_title'] ?? $base->meta['tariff_title'] ?? $tariff_title ),
					'delivery_type' => $delivery_type,
					'delivery_mode' => $delivery_mode,
					'cdek_delivery_mode' => $delivery_mode,
					'shipment_point' => $shipment_point,
					'shipment_point_address' => $shipment_point_address,
					'cdek_courier_comment' => $this->short_text_from_admin_data( $data, 'cdek_courier_comment', 255 ),
					'courier_original_address' => $original_address,
					'courier_original_hash' => $this->original_address_hash( $original_address ),
					'normalized_address' => $normalized_address,
					'normalization_required' => DeliveryType::COURIER === $delivery_type,
					'normalization_valid' => DeliveryType::COURIER === $delivery_type && ! empty( $normalized_address['success'] ) && (int) ( $normalized_address['fields']['cdek_city_code'] ?? 0 ) > 0,
					'normalization_attempted' => DeliveryType::COURIER === $delivery_type && array() !== $normalized_address,
					'cdek_city_code' => (int) ( $normalized_address['fields']['cdek_city_code'] ?? $base->meta['cdek_city_code'] ?? $base->meta['cdek_to_city_code'] ?? 0 ),
					'cdek_city_name' => (string) ( $normalized_address['fields']['cdek_city_name'] ?? $base->meta['cdek_city_name'] ?? '' ),
					'cdek_postal_code' => (string) ( $normalized_address['fields']['cdek_postal_code'] ?? $base->meta['cdek_postal_code'] ?? '' ),
					'cdek_delivery_address' => (string) ( $normalized_address['fields']['cdek_delivery_address'] ?? $base->meta['cdek_delivery_address'] ?? '' ),
					'cdek_lat' => (string) ( $normalized_address['fields']['cdek_lat'] ?? $base->meta['cdek_lat'] ?? '' ),
					'cdek_lon' => (string) ( $normalized_address['fields']['cdek_lon'] ?? $base->meta['cdek_lon'] ?? '' ),
					'delivery_point' => DeliveryType::PICKUP === $delivery_type ? $pickup_code : (string) ( $base->meta['delivery_point'] ?? '' ),
					'pickup_point_code' => DeliveryType::PICKUP === $delivery_type ? $pickup_code : (string) ( $base->meta['pickup_point_code'] ?? '' ),
					'pickup_point_postcode' => DeliveryType::PICKUP === $delivery_type ? (string) ( $pickup_row['postcode'] ?? $base->meta['pickup_point_postcode'] ?? '' ) : (string) ( $base->meta['pickup_point_postcode'] ?? '' ),
					'pickup_point_found' => DeliveryType::PICKUP === $delivery_type ? '' !== $pickup_code : ! empty( $base->meta['pickup_point_found'] ),
					'pickup_point_row' => DeliveryType::PICKUP === $delivery_type && array() !== $pickup_row ? $this->safe_pickup_row( $pickup_row ) : (array) ( $base->meta['pickup_point_row'] ?? array() ),
					'cdek_item_rows' => $cdek_items,
				)
			)
		);
	}

	private function create_dpd_request_from_order( object $order ): ShipmentCreateRequest {
		$calculation = $this->calculation_data( $order );
		$rate_meta = $this->rate_meta_data( $order );
		$delivery_type = $this->delivery_type_from_order( $order );
		$items = $this->order_items( $order );
		$service_code = strtoupper( $this->first_non_empty(
			$rate_meta['dpd_service_code'] ?? '',
			$rate_meta['tariff_code'] ?? '',
			$calculation['selected_tariff_object'] ?? '',
			$this->meta_string( $order, '_wdc_platform_tariff_object' )
		) );
		$pickup_city_id = $this->digits_string( $this->first_non_empty(
			$rate_meta['dpd_sender_city_id'] ?? '',
			$rate_meta['request_payload_sanitized']['pickup']['cityId'] ?? '',
			$calculation['api']['request_payload_sanitized']['pickup']['cityId'] ?? '',
			$this->dpd_settings instanceof DpdSettings ? $this->dpd_settings->tariff_sender_dpd_city_id() : ''
		) );
		$delivery_city_id = $this->digits_string( $this->first_non_empty(
			$rate_meta['dpd_receiver_city_id'] ?? '',
			$rate_meta['request_payload_sanitized']['delivery']['cityId'] ?? '',
			$calculation['api']['request_payload_sanitized']['delivery']['cityId'] ?? ''
		) );
		$default_sender_terminal = $this->dpd_settings instanceof DpdSettings ? $this->dpd_settings->tariff_default_sender_terminal_code() : '';
		$pickup_terminal_code = $this->terminal_code( $this->first_non_empty(
			$default_sender_terminal,
			$rate_meta['dpd_pickup_terminal_code'] ?? '',
			$rate_meta['request_payload_sanitized']['pickup']['terminalCode'] ?? '',
			$calculation['api']['request_payload_sanitized']['pickup']['terminalCode'] ?? ''
		) );
		$delivery_terminal_code = $this->terminal_code( $this->first_non_empty(
			$this->meta_string( $order, '_wdc_dpd_pickup_terminal_code' ),
			$rate_meta['dpd_delivery_terminal_code'] ?? '',
			$rate_meta['request_payload_sanitized']['delivery']['terminalCode'] ?? '',
			$calculation['api']['request_payload_sanitized']['delivery']['terminalCode'] ?? ''
		) );
		$delivery_terminal = $this->dpd_delivery_terminal_snapshot( $order, $delivery_terminal_code, $delivery_city_id );
		$sender_terminal = $this->dpd_terminal_snapshot( $pickup_terminal_code, $pickup_city_id );
		$recipient_address = DeliveryType::PICKUP === $delivery_type
			? new Address( country_code: 'RU', city: (string) ( $delivery_terminal['city_name'] ?? $this->meta_string( $order, '_wdc_dpd_pickup_city_name' ) ), raw_address: (string) ( $delivery_terminal['address'] ?? $this->meta_string( $order, '_wdc_dpd_pickup_address' ) ) )
			: new Address( country_code: 'RU', city: method_exists( $order, 'get_shipping_city' ) ? (string) $order->get_shipping_city() : '', postcode: method_exists( $order, 'get_shipping_postcode' ) ? (string) $order->get_shipping_postcode() : '', raw_address: $this->shipping_address( $order ) );

		return new ShipmentCreateRequest(
			order_id: $this->order_id( $order ),
			carrier_key: DpdSettings::CARRIER_KEY,
			delivery_type: $delivery_type,
			rate_id: DpdSettings::CARRIER_KEY . ':' . $delivery_type,
			recipient_address: $recipient_address,
			pickup_point: DeliveryType::PICKUP === $delivery_type && '' !== $delivery_terminal_code ? new PickupPointSelection( DpdSettings::CARRIER_KEY, DpdSettings::SERVICE_KEY, $delivery_terminal_code, (string) ( $delivery_terminal['address'] ?? '' ), $this->now() ) : null,
			places: array(),
			declared_value: Money::from_rubles( $this->default_declared_value_rub( $items ) ),
			services: array(),
			recipient: array(
				'name' => $this->recipient_name( $order ),
				'phone' => $this->phone( $order ),
				'email' => $this->email( $order ),
			),
			meta: array(
				'carrier_key' => DpdSettings::CARRIER_KEY,
				'service_key' => DpdSettings::SERVICE_KEY,
				'service_title' => DpdSettings::TITLE,
				'delivery_type' => $delivery_type,
				'service_code' => $service_code,
				'tariff_object' => $service_code,
				'tariff_title' => $this->first_non_empty( $rate_meta['selected_tariff_title'] ?? '', $rate_meta['tariff_name'] ?? '', $calculation['selected_tariff_title'] ?? '', DpdSettings::known_service_codes()[ $service_code ] ?? $service_code ),
				'pickup_city_id' => $pickup_city_id,
				'delivery_city_id' => $delivery_city_id,
				'pickup_terminal_code' => $pickup_terminal_code,
				'delivery_terminal_code' => DeliveryType::PICKUP === $delivery_type ? $delivery_terminal_code : '',
				'delivery_terminal_source' => (string) ( $rate_meta['dpd_delivery_terminal_source'] ?? '' ),
				'default_sender_terminal_configured' => '' !== $default_sender_terminal,
				'sender_terminal' => $sender_terminal,
				'delivery_terminal' => $delivery_terminal,
				'pickup_point_code' => DeliveryType::PICKUP === $delivery_type ? $delivery_terminal_code : '',
				'pickup_point_found' => DeliveryType::PICKUP === $delivery_type ? '' !== $delivery_terminal_code : true,
				'pickup_point_row' => DeliveryType::PICKUP === $delivery_type ? $delivery_terminal : array(),
				'selected_pickup_point_snapshot' => $this->dpd_selected_pickup_snapshot( $order ),
				'declared_value_rub' => $this->default_declared_value_rub( $items ),
				'order_num' => $this->order_number( $order ),
				'calculation_data' => $calculation,
				'rate_meta' => $rate_meta,
			)
		);
	}

	private function create_dpd_request_from_admin_data( ShipmentCreateRequest $base, array $data ): ShipmentCreateRequest {
		$delivery_type = RussianPostDomesticSettings::normalize_delivery_type( sanitize_key( wp_unslash( $data['delivery_type'] ?? $base->delivery_type ) ) );
		$places = array();
		foreach ( is_array( $data['places'] ?? null ) ? $data['places'] : array() as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$places[] = new ShipmentPlace(
				$index + 1,
				$this->whole_number_from_place_row( $row, 'weight_g' ),
				$this->whole_number_from_place_row( $row, 'length_cm' ),
				$this->whole_number_from_place_row( $row, 'width_cm' ),
				$this->whole_number_from_place_row( $row, 'height_cm' ),
				Money::from_kopecks( 0 ),
				array()
			);
		}
		$delivery_terminal_code = DeliveryType::PICKUP === $delivery_type ? $this->terminal_code( (string) wp_unslash( $data['pickup_point_code'] ?? $base->meta['delivery_terminal_code'] ?? '' ) ) : '';
		$delivery_city_id = $this->digits_string( (string) ( $base->meta['delivery_city_id'] ?? '' ) );
		$delivery_terminal = DeliveryType::PICKUP === $delivery_type ? $this->dpd_terminal_snapshot( $delivery_terminal_code, $delivery_city_id ) : array();
		$recipient_address = DeliveryType::PICKUP === $delivery_type
			? new Address( country_code: 'RU', city: (string) ( $delivery_terminal['city_name'] ?? $base->recipient_address->city ), raw_address: (string) ( $delivery_terminal['address'] ?? $base->recipient_address->raw_address ) )
			: new Address( country_code: 'RU', city: $base->recipient_address->city, postcode: $base->recipient_address->postcode, raw_address: sanitize_text_field( wp_unslash( $data['courier_original_address'] ?? $base->recipient_address->raw_address ) ) );

		return new ShipmentCreateRequest(
			$base->order_id,
			DpdSettings::CARRIER_KEY,
			$delivery_type,
			$base->rate_id,
			$recipient_address,
			DeliveryType::PICKUP === $delivery_type && '' !== $delivery_terminal_code ? new PickupPointSelection( DpdSettings::CARRIER_KEY, DpdSettings::SERVICE_KEY, $delivery_terminal_code, (string) ( $delivery_terminal['address'] ?? '' ), $base->pickup_point?->selected_at ?: $this->now() ) : null,
			$places,
			$base->declared_value,
			false,
			array(),
			array(
				'name' => sanitize_text_field( wp_unslash( $data['recipient_name'] ?? $base->recipient['name'] ?? '' ) ),
				'phone' => sanitize_text_field( wp_unslash( $data['recipient_phone'] ?? $base->recipient['phone'] ?? '' ) ),
				'email' => sanitize_email( wp_unslash( $data['recipient_email'] ?? $base->recipient['email'] ?? '' ) ),
			),
			array_merge(
				$base->meta,
				array(
					'delivery_type' => $delivery_type,
					'delivery_terminal_code' => $delivery_terminal_code,
					'delivery_terminal' => $delivery_terminal,
					'comment' => sanitize_textarea_field( wp_unslash( $data['dpd_comment'] ?? $data['comment'] ?? '' ) ),
				)
			)
		);
	}

	private function default_weight_g( object $order, array $items ): int {
		$calculation = $this->calculation_data( $order );
		$package = is_array( $calculation['package'] ?? null ) ? $calculation['package'] : array();
		$products_weight = (int) ( $package['products_weight_g'] ?? 0 );
		$packaging_weight = (int) ( $package['packaging_weight_g'] ?? 0 );
		$weight = (int) ( $package['package_weight_with_packaging_g'] ?? $package['final_weight_g'] ?? 0 );
		if ( $weight <= 0 && $products_weight > 0 ) {
			$weight = $products_weight + max( 0, $packaging_weight );
		}
		if ( $weight > 0 ) {
			return $weight;
		}
		$total = 0;
		foreach ( $items as $item ) {
			$total += $item instanceof PackageItem ? $item->get_total_weight_g() : 0;
		}

		return max( 1, $total ?: 1000 );
	}

	/**
	 * @param array<string,mixed> $calculation
	 * @param array<string,mixed> $api
	 * @param array<string,mixed> $response_tariff
	 */
	private function cdek_delivery_mode_from_calculation( string $delivery_type, array $calculation, array $api, array $response_tariff, array $tariff_row = array() ): int {
		$rate_meta = is_array( $calculation['rate_meta'] ?? null ) ? $calculation['rate_meta'] : array();
		foreach ( array(
			$tariff_row['delivery_mode'] ?? null,
			$response_tariff['delivery_mode'] ?? null,
			$api['delivery_mode'] ?? null,
			$api['transtype'] ?? null,
			$rate_meta['delivery_mode'] ?? null,
			$rate_meta['cdek_delivery_mode'] ?? null,
			$rate_meta['transtype'] ?? null,
			$calculation['delivery_mode'] ?? null,
			$calculation['transtype'] ?? null,
		) as $candidate ) {
			$mode = (int) $candidate;
			if ( in_array( $mode, array( 1, 2, 3, 4 ), true ) ) {
				return $mode;
			}
		}

		return DeliveryType::PICKUP === $delivery_type ? 4 : ( DeliveryType::COURIER === $delivery_type ? 3 : 0 );
	}

	/**
	 * @param array<int,PackageItem> $items
	 */
	private function default_declared_value_rub( array $items ): int {
		$total_kopecks = 0;
		foreach ( $items as $item ) {
			if ( ! $item instanceof PackageItem ) {
				continue;
			}
			$total_kopecks += $item->total_price->get_kopecks();
		}

		return max( 0, (int) round( $total_kopecks / 100 ) );
	}

	private function recipient_address( object $order, string $delivery_type, ?array $pickup_row = null, array $normalized_address = array() ): Address {
		if ( DeliveryType::PICKUP === $delivery_type ) {
			$row = is_array( $pickup_row ) ? $pickup_row : array();

			return new Address(
				country_code: 'RU',
				region_name: (string) ( $row['region_name'] ?? '' ),
				city: (string) ( $row['city_name'] ?? '' ),
				postcode: preg_replace( '/\D+/', '', (string) ( $row['postcode'] ?? $this->meta_string( $order, '_wdc_pickup_point_postcode' ) ) ) ?: '',
				raw_address: (string) ( $row['address'] ?? '' )
			);
		}

		$fields = ! empty( $normalized_address['success'] ) && is_array( $normalized_address['fields'] ?? null ) ? $normalized_address['fields'] : array();
		$postcode = (string) ( $fields['index-to'] ?? '' );
		if ( '' === $postcode && method_exists( $order, 'get_shipping_postcode' ) ) {
			$postcode = (string) $order->get_shipping_postcode();
		}
		$region = (string) ( $fields['region-to'] ?? '' );
		if ( '' === $region ) {
			$region = method_exists( $order, 'get_shipping_state' ) ? (string) $order->get_shipping_state() : '';
		}
		$city = (string) ( $fields['place-to'] ?? '' );
		if ( '' === $city ) {
			$city = method_exists( $order, 'get_shipping_city' ) ? (string) $order->get_shipping_city() : '';
		}

		return new Address(
			country_code: method_exists( $order, 'get_shipping_country' ) ? (string) $order->get_shipping_country() : 'RU',
			region_name: $region,
			city: $city,
			postcode: $postcode,
			street: method_exists( $order, 'get_shipping_address_1' ) ? (string) $order->get_shipping_address_1() : '',
			apartment: method_exists( $order, 'get_shipping_address_2' ) ? (string) $order->get_shipping_address_2() : '',
			raw_address: $this->shipping_address( $order ),
			fias_id: $this->meta_string( $order, '_wdc_platform_fias_id' ),
			gar_id: $this->meta_string( $order, '_wdc_platform_gar_id' )
		);
	}

	private function address_from_admin_data( Address $base, array $data, string $delivery_type, array $base_meta = array(), array $normalized_address = array(), string $original_address = '', array $pickup_row = array() ): Address {
		if ( DeliveryType::PICKUP === $delivery_type ) {
			if ( array() === $pickup_row ) {
				return $base;
			}

			return new Address(
				country_code: 'RU',
				region_name: (string) ( $pickup_row['region_name'] ?? $base->region_name ),
				city: (string) ( $pickup_row['city_name'] ?? $base->city ),
				postcode: preg_replace( '/\D+/', '', (string) ( $pickup_row['postcode'] ?? $base->postcode ) ) ?: '',
				raw_address: (string) ( $pickup_row['address'] ?? $base->raw_address )
			);
		}
		$fields = ! empty( $normalized_address['success'] ) && is_array( $normalized_address['fields'] ?? null ) ? $normalized_address['fields'] : array();

		return new Address(
			country_code: $base->country_code ?: 'RU',
			region_name: sanitize_text_field( wp_unslash( $fields['region-to'] ?? $base->region_name ) ),
			city: sanitize_text_field( wp_unslash( $fields['place-to'] ?? $base->city ) ),
			postcode: preg_replace( '/\D+/', '', (string) wp_unslash( $fields['index-to'] ?? $base->postcode ) ) ?: '',
			raw_address: ! empty( $normalized_address['success'] ) ? (string) ( $normalized_address['display'] ?? '' ) : '',
			fias_id: $base->fias_id,
			gar_id: $base->gar_id
		);
	}

	/**
	 * @param array<string,mixed> $normalized_address
	 */
	private function cdek_courier_address_from_normalized( Address $base, array $normalized_address ): Address {
		if ( empty( $normalized_address['success'] ) || ! is_array( $normalized_address['fields'] ?? null ) ) {
			return $base;
		}
		$fields = $normalized_address['fields'];

		return new Address(
			country_code: $base->country_code ?: 'RU',
			region_name: sanitize_text_field( wp_unslash( $fields['region'] ?? $base->region_name ) ),
			city: sanitize_text_field( wp_unslash( $fields['cdek_city_name'] ?? $fields['city'] ?? $base->city ) ),
			postcode: preg_replace( '/\D+/', '', (string) wp_unslash( $fields['cdek_postal_code'] ?? $fields['postal_code'] ?? $base->postcode ) ) ?: '',
			raw_address: sanitize_text_field( wp_unslash( $fields['cdek_delivery_address'] ?? $base->raw_address ) ),
			fias_id: sanitize_text_field( wp_unslash( $fields['fias_id'] ?? $base->fias_id ) ),
			gar_id: $base->gar_id
		);
	}

	private function pickup_point( object $order ): ?PickupPointSelection {
		$code = $this->meta_string( $order, '_wdc_pickup_point_code' );
		if ( '' === $code ) {
			return null;
		}

		return new PickupPointSelection( RussianPostDomesticSettings::CARRIER_KEY, RussianPostDomesticSettings::SERVICE_KEY, $code, $this->meta_string( $order, '_wdc_pickup_point_address' ), $this->now() );
	}

	private function pickup_from_admin_data( ?PickupPointSelection $base, array $data ): ?PickupPointSelection {
		$code = sanitize_text_field( wp_unslash( $data['pickup_point_code'] ?? $base?->point_code ?? '' ) );
		if ( '' === $code ) {
			return null;
		}

		return new PickupPointSelection(
			RussianPostDomesticSettings::CARRIER_KEY,
			RussianPostDomesticSettings::SERVICE_KEY,
			$code,
			sanitize_text_field( wp_unslash( $data['pickup_point_address'] ?? $base?->point_address ?? '' ) ),
			$base?->selected_at ?: $this->now()
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $base_meta
	 * @return array<string,mixed>
	 */
	private function pickup_row_from_admin_data( array $data, array $base_meta ): array {
		$point_code = sanitize_text_field( wp_unslash( $data['pickup_point_code'] ?? $base_meta['pickup_point_code'] ?? '' ) );
		$postcode = preg_replace( '/\D+/', '', (string) wp_unslash( $data['pickup_point_postcode'] ?? $base_meta['pickup_point_postcode'] ?? '' ) ) ?: '';
		$address = sanitize_text_field( wp_unslash( $data['pickup_point_address'] ?? '' ) );
		$city = sanitize_text_field( wp_unslash( $data['pickup_point_city'] ?? '' ) );
		$region = sanitize_text_field( wp_unslash( $data['pickup_point_region'] ?? '' ) );
		$latitude = is_numeric( $data['pickup_point_lat'] ?? null ) ? (float) $data['pickup_point_lat'] : null;
		$longitude = is_numeric( $data['pickup_point_lng'] ?? null ) ? (float) $data['pickup_point_lng'] : null;
		if ( '' === $address && is_array( $base_meta['pickup_point_row'] ?? null ) ) {
			$address = (string) ( $base_meta['pickup_point_row']['address'] ?? '' );
		}
		if ( '' === $city && is_array( $base_meta['pickup_point_row'] ?? null ) ) {
			$city = (string) ( $base_meta['pickup_point_row']['city_name'] ?? '' );
		}
		if ( '' === $region && is_array( $base_meta['pickup_point_row'] ?? null ) ) {
			$region = (string) ( $base_meta['pickup_point_row']['region_name'] ?? '' );
		}
		if ( '' === $point_code || '' === $postcode || '' === $address ) {
			return array();
		}

		return array(
			'point_code' => $point_code,
			'postcode' => $postcode,
			'region_name' => $region,
			'city_name' => $city,
			'address' => $address,
			'latitude' => $latitude,
			'longitude' => $longitude,
		);
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function pickup_point_row( object $order ): ?array {
		if ( ! $this->pickup_points instanceof RussianPostPickupPointRepository ) {
			return null;
		}
		$code = $this->meta_string( $order, '_wdc_pickup_point_code' );
		if ( '' !== $code ) {
			$row = $this->pickup_points->find_row_by_point_code( $code );
			if ( is_array( $row ) ) {
				return $row;
			}
		}
		$postcode = preg_replace( '/\D+/', '', $this->meta_string( $order, '_wdc_pickup_point_postcode' ) ) ?: '';
		if ( '' !== $postcode ) {
			$rows = $this->pickup_points->find_rows_by_postcode( $postcode, array( 'limit' => 1 ) );
			if ( is_array( $rows[0] ?? null ) ) {
				return $rows[0];
			}
		}

		return null;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function safe_pickup_row( array $row ): array {
		if ( array() === $row ) {
			return array();
		}

		return array(
			'point_code' => (string) ( $row['point_code'] ?? '' ),
			'postcode' => (string) ( $row['postcode'] ?? '' ),
			'region_name' => (string) ( $row['region_name'] ?? '' ),
			'city_name' => (string) ( $row['city_name'] ?? '' ),
			'address' => (string) ( $row['address'] ?? '' ),
			'point_type' => (string) ( $row['point_type'] ?? '' ),
			'point_title' => (string) ( $row['point_title'] ?? '' ),
			'display_title' => (string) ( $row['display_title'] ?? '' ),
			'cdek_code' => (string) ( $row['cdek_code'] ?? $row['point_code'] ?? '' ),
			'lat' => null !== ( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null,
			'lng' => null !== ( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null,
		);
	}

	private function digits_string( string $value ): string {
		return preg_replace( '/\D+/', '', $value ) ?? '';
	}

	private function terminal_code( string $value ): string {
		return substr( preg_replace( '/[^A-Za-z0-9_\-]+/', '', strtoupper( trim( $value ) ) ) ?? '', 0, 64 );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function dpd_terminal_snapshot( string $terminal_code, string $city_id = '' ): array {
		$terminal_code = $this->terminal_code( $terminal_code );
		if ( '' === $terminal_code || ! $this->dpd_pickup_points instanceof DpdPickupPointService ) {
			return array();
		}
		$point = $this->dpd_pickup_points->find_runtime_parcel_shop_by_terminal_code( $terminal_code, '' !== $city_id ? (int) $city_id : null );
		if ( null === $point ) {
			$point = $this->dpd_pickup_points->find_runtime_parcel_shop_by_terminal_code( $terminal_code );
		}
		if ( null === $point ) {
			return array();
		}

		return array(
			'terminal_code' => (string) ( $point['terminal_code'] ?? $terminal_code ),
			'type' => (string) ( $point['type'] ?? '' ),
			'name' => (string) ( $point['name'] ?? '' ),
			'address' => (string) ( $point['address'] ?? '' ),
			'city_id' => (string) ( $point['city_id'] ?? '' ),
			'city_name' => (string) ( $point['city_name'] ?? '' ),
			'source' => (string) ( $point['source'] ?? '' ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function dpd_delivery_terminal_snapshot( object $order, string $terminal_code, string $city_id ): array {
		$snapshot = $this->dpd_terminal_snapshot( $terminal_code, $city_id );
		if ( array() !== $snapshot ) {
			return $snapshot;
		}

		return array_filter(
			array(
				'terminal_code' => $terminal_code,
				'type' => $this->meta_string( $order, '_wdc_dpd_pickup_type' ),
				'name' => $this->meta_string( $order, '_wdc_dpd_pickup_name' ),
				'address' => $this->meta_string( $order, '_wdc_dpd_pickup_address' ),
				'city_id' => $city_id,
				'city_name' => $this->meta_string( $order, '_wdc_dpd_pickup_city_name' ),
				'source' => $this->meta_string( $order, '_wdc_dpd_pickup_source' ),
			),
			static fn ( mixed $value ): bool => '' !== (string) $value
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function dpd_selected_pickup_snapshot( object $order ): array {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}
		$value = $order->get_meta( '_wdc_pickup_point_snapshot', true );
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @param array<string,mixed> $pickup
	 * @return array<string,mixed>
	 */
	private function cdek_pickup_row( array $pickup ): array {
		if ( array() === $pickup ) {
			return array();
		}

		$point_code = $this->cdek_pickup_code( $pickup );

		return array(
			'point_code' => $point_code,
			'postcode' => (string) ( $pickup['point_postcode'] ?? $pickup['postcode'] ?? '' ),
			'region_name' => (string) ( $pickup['region_name'] ?? '' ),
			'city_name' => (string) ( $pickup['city_name'] ?? '' ),
			'address' => (string) ( $pickup['point_address'] ?? $pickup['address'] ?? '' ),
			'point_type' => (string) ( $pickup['point_type'] ?? $pickup['type'] ?? '' ),
			'point_title' => (string) ( $pickup['point_title'] ?? $pickup['display_title'] ?? '' ),
			'display_title' => (string) ( $pickup['display_title'] ?? '' ),
			'cdek_code' => $point_code,
			'latitude' => is_numeric( $pickup['lat'] ?? $pickup['latitude'] ?? null ) ? (float) ( $pickup['lat'] ?? $pickup['latitude'] ) : null,
			'longitude' => is_numeric( $pickup['lng'] ?? $pickup['longitude'] ?? null ) ? (float) ( $pickup['lng'] ?? $pickup['longitude'] ) : null,
		);
	}

	/**
	 * @param array<string,mixed> $pickup
	 */
	private function cdek_pickup_code( array $pickup, string $fallback = '' ): string {
		$code = $this->first_non_empty( $pickup['delivery_point'] ?? '', $pickup['point_code'] ?? '', $pickup['cdek_code'] ?? '', $fallback );
		if ( '' === $code ) {
			return '';
		}
		$postcode = preg_replace( '/\D+/', '', (string) ( $pickup['point_postcode'] ?? $pickup['postcode'] ?? $pickup['postal_code'] ?? '' ) ) ?: '';
		$digits = preg_replace( '/\D+/', '', $code ) ?: '';
		if ( '' !== $postcode && $digits === $postcode ) {
			return '';
		}
		if ( preg_match( '/^\d{6}$/', $code ) ) {
			return '';
		}

		return strtoupper( preg_replace( '/[^A-Z0-9_\-]/', '', strtoupper( $code ) ) ?? '' );
	}

	private function first_non_empty( mixed ...$values ): string {
		foreach ( $values as $value ) {
			if ( is_array( $value ) || is_object( $value ) || null === $value ) {
				continue;
			}
			$text = trim( (string) $value );
			if ( '' !== $text ) {
				return $text;
			}
		}

		return '';
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $base_meta
	 * @return array<string,mixed>
	 */
	private function cdek_pickup_row_from_admin_data( array $data, array $base_meta ): array {
		$base_row = is_array( $base_meta['pickup_point_row'] ?? null ) ? $base_meta['pickup_point_row'] : array();
		$postcode = preg_replace( '/\D+/', '', (string) wp_unslash( $data['pickup_point_postcode'] ?? $base_meta['pickup_point_postcode'] ?? $base_row['postcode'] ?? '' ) ) ?: '';
		$point_code = $this->cdek_pickup_code(
			array(
				'delivery_point' => wp_unslash( $data['delivery_point'] ?? '' ),
				'point_code' => wp_unslash( $data['pickup_point_code'] ?? $base_meta['pickup_point_code'] ?? '' ),
				'cdek_code' => wp_unslash( $data['cdek_code'] ?? $base_meta['cdek_code'] ?? '' ),
				'postcode' => $postcode,
			),
			(string) ( $base_meta['delivery_point'] ?? '' )
		);
		$address = sanitize_text_field( wp_unslash( $data['pickup_point_address'] ?? $base_row['address'] ?? '' ) );
		$city = sanitize_text_field( wp_unslash( $data['pickup_point_city'] ?? $base_row['city_name'] ?? '' ) );
		$region = sanitize_text_field( wp_unslash( $data['pickup_point_region'] ?? $base_row['region_name'] ?? '' ) );
		$latitude = is_numeric( $data['pickup_point_lat'] ?? null ) ? (float) $data['pickup_point_lat'] : ( is_numeric( $base_row['lat'] ?? null ) ? (float) $base_row['lat'] : null );
		$longitude = is_numeric( $data['pickup_point_lng'] ?? null ) ? (float) $data['pickup_point_lng'] : ( is_numeric( $base_row['lng'] ?? null ) ? (float) $base_row['lng'] : null );
		if ( '' === $point_code ) {
			return array();
		}

		return array(
			'point_code' => $point_code,
			'postcode' => $postcode,
			'region_name' => $region,
			'city_name' => $city,
			'address' => $address,
			'point_type' => sanitize_text_field( wp_unslash( $data['pickup_point_type'] ?? $base_row['point_type'] ?? '' ) ),
			'point_title' => sanitize_text_field( wp_unslash( $data['pickup_point_title'] ?? $base_row['point_title'] ?? '' ) ),
			'display_title' => sanitize_text_field( wp_unslash( $data['pickup_point_title'] ?? $base_row['display_title'] ?? '' ) ),
			'cdek_code' => $point_code,
			'latitude' => $latitude,
			'longitude' => $longitude,
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function cdek_service_variants( ShipmentCreateRequest $request ): array {
		return array(
			array(
				'service_key' => CdekSettings::SERVICE_KEY,
				'group_id' => CdekCarrier::checkout_group_id( DeliveryType::PICKUP ),
				'title' => CdekSettings::DEFAULT_PICKUP_METHOD_TITLE,
				'delivery_type' => DeliveryType::PICKUP,
				'tariffs' => $this->cdek_tariff_options( DeliveryType::PICKUP, $request ),
			),
			array(
				'service_key' => CdekSettings::SERVICE_KEY,
				'group_id' => CdekCarrier::checkout_group_id( DeliveryType::COURIER ),
				'title' => CdekSettings::DEFAULT_COURIER_METHOD_TITLE,
				'delivery_type' => DeliveryType::COURIER,
				'tariffs' => $this->cdek_tariff_options( DeliveryType::COURIER, $request ),
			),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function dpd_service_variants( ShipmentCreateRequest $request ): array {
		$code = (string) ( $request->meta['service_code'] ?? $request->meta['tariff_object'] ?? '' );
		$title = (string) ( $request->meta['tariff_title'] ?? ( DpdSettings::known_service_codes()[ $code ] ?? $code ) );

		return array(
			array(
				'service_key' => DpdSettings::SERVICE_KEY,
				'group_id' => DpdSettings::CARRIER_KEY . ':' . $request->delivery_type,
				'title' => DeliveryType::PICKUP === $request->delivery_type ? DpdSettings::DEFAULT_PICKUP_METHOD_TITLE : DpdSettings::DEFAULT_COURIER_METHOD_TITLE,
				'delivery_type' => $request->delivery_type,
				'tariffs' => array(
					array(
						'object_code' => $code,
						'title' => '' !== $title ? $title : $code,
						'has_declared_value' => false,
					),
				),
			),
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function cdek_tariff_options( string $delivery_type, ShipmentCreateRequest $request ): array {
		$selected_code = (string) ( $request->meta['tariff_code'] ?? $request->meta['tariff_object'] ?? '' );
		$options = array();
		foreach ( $this->cdek_tariff_rows() as $row ) {
			if ( empty( $row['is_active'] ) || $delivery_type !== (string) ( $row['delivery_type'] ?? '' ) ) {
				continue;
			}
			$code = (string) ( $row['tariff_code'] ?? '' );
			if ( '' === $code ) {
				continue;
			}
			$options[] = array(
				'object_code' => $code,
				'title' => $this->cdek_tariff_label( $row, $code ),
				'delivery_type' => $delivery_type,
				'delivery_mode' => (int) ( $row['delivery_mode'] ?? 0 ),
				'selected_missing' => false,
			);
		}
		if ( '' === $selected_code || $delivery_type !== $request->delivery_type ) {
			return $options;
		}
		foreach ( $options as $option ) {
			if ( $selected_code === (string) ( $option['object_code'] ?? '' ) ) {
				return $options;
			}
		}
		$title = $this->cdek_tariff_title( $this->cdek_tariff_row( $selected_code ), $selected_code, (string) ( $request->meta['tariff_title'] ?? '' ) );
		$options[] = array(
			'object_code' => $selected_code,
			'title' => sprintf( '%s (%s)', $title, __( 'сохранен в заказе, не активен', 'walls-delivery-calc' ) ),
			'delivery_type' => $delivery_type,
			'delivery_mode' => (int) ( $request->meta['delivery_mode'] ?? $request->meta['cdek_delivery_mode'] ?? 0 ),
			'selected_missing' => true,
		);

		return $options;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function cdek_tariff_rows(): array {
		return $this->cdek_tariffs instanceof CdekTariffRepository ? $this->cdek_tariffs->all() : array();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function cdek_tariff_row( string $code ): array {
		if ( '' === $code || ! $this->cdek_tariffs instanceof CdekTariffRepository ) {
			return array();
		}
		$row = $this->cdek_tariffs->find_by_code( $code );

		return is_array( $row ) ? $row : array();
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function cdek_tariff_title( array $row, string $code, string $fallback = '' ): string {
		foreach ( array( 'custom_title', 'tariff_name_from_cdek' ) as $key ) {
			$value = trim( (string) ( $row[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		$fallback = trim( $fallback );
		if ( '' !== $fallback ) {
			return $fallback;
		}

		return '' !== $code ? sprintf( 'тариф %s', $code ) : '';
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function cdek_tariff_label( array $row, string $code ): string {
		$title = $this->cdek_tariff_title( $row, $code );
		if ( '' === $code || str_contains( $title, '(' . $code . ')' ) ) {
			return $title;
		}

		return sprintf( '%s (%s)', $title, $code );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function cdek_item_rows_from_admin_data( array $data ): array {
		$rows = array();
		foreach ( is_array( $data['cdek_items'] ?? null ) ? $data['cdek_items'] : array() as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$item_key = sanitize_text_field( wp_unslash( $row['item_key'] ?? '' ) );
			if ( '' === $item_key ) {
				$item_key = 'manual-' . (string) ( count( $rows ) + 1 );
			}
			$rows[] = array(
				'item_key' => $item_key,
				'ordered_quantity' => max( 1, (int) ( $row['ordered_quantity'] ?? $row['amount'] ?? 1 ) ),
				'place_number' => max( 1, (int) ( $row['place_number'] ?? 1 ) ),
				'name' => sanitize_text_field( wp_unslash( $row['name'] ?? 'Товар' ) ),
				'ware_key' => substr( sanitize_text_field( wp_unslash( $row['ware_key'] ?? $item_key ) ), 0, 20 ),
				'amount' => max( 1, (int) ( $row['amount'] ?? 1 ) ),
				'cost' => $this->decimal_from_admin_row( $row, 'cost' ),
				'weight' => max( 0, (int) ( $row['weight'] ?? 0 ) ),
				'length_cm' => $this->decimal_from_admin_row( $row, 'length_cm' ),
				'width_cm' => $this->decimal_from_admin_row( $row, 'width_cm' ),
				'height_cm' => $this->decimal_from_admin_row( $row, 'height_cm' ),
			);
		}

		return $rows;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function decimal_from_admin_row( array $row, string $key ): float {
		return max( 0.0, (float) str_replace( ',', '.', (string) wp_unslash( $row[ $key ] ?? '0' ) ) );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function short_text_from_admin_data( array $data, string $key, int $max_length ): string {
		$value = sanitize_text_field( wp_unslash( $data[ $key ] ?? '' ) );
		$value = trim( preg_replace( '/\s+/', ' ', $value ) ?? $value );

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, $max_length ) : substr( $value, 0, $max_length );
	}

	private function original_address_hash( string $original_address ): string {
		return hash( 'sha256', trim( $original_address ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function cached_normalized_address( object $order, string $service_key, string $original_address ): array {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}
		$value = $order->get_meta( '_wdc_shipment_rp_clean_address', true );
		$snapshot = is_array( $value ) ? $value : array();
		if ( array() === $snapshot ) {
			return array();
		}
		if ( (int) ( $snapshot['order_id'] ?? 0 ) !== $this->order_id( $order ) ) {
			return array();
		}
		if ( (string) ( $snapshot['service_key'] ?? '' ) !== $service_key ) {
			return array();
		}
		if ( (string) ( $snapshot['original_hash'] ?? '' ) !== $this->original_address_hash( $original_address ) ) {
			return array();
		}
		$expires_at = strtotime( (string) ( $snapshot['expires_at'] ?? '' ) );
		if ( false !== $expires_at && $expires_at < time() ) {
			return array();
		}

		return $snapshot;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function normalized_address_from_admin_data( array $data, string $original_address, string $service_key ): array {
		$json = (string) wp_unslash( $data['normalized_address_json'] ?? '' );
		$decoded = '' !== trim( $json ) ? json_decode( $json, true ) : array();
		$snapshot = is_array( $decoded ) ? $decoded : array();
		if ( array() === $snapshot ) {
			return array();
		}
		if ( (string) ( $snapshot['service_key'] ?? $service_key ) !== $service_key ) {
			return array();
		}
		if ( (string) ( $snapshot['original_hash'] ?? '' ) !== $this->original_address_hash( $original_address ) ) {
			return array();
		}

		return $snapshot;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function tariffs_for_service( DeliveryService $service, string $delivery_type ): array {
		$settings = $this->domestic_settings instanceof RussianPostDomesticSettings ? $this->domestic_settings->all( $service->service_key ) : array();
		$variants = is_array( $settings['tariff_variants'] ?? null ) ? $settings['tariff_variants'] : array();
		if ( is_array( $variants['value'] ?? null ) ) {
			$variants = $variants['value'];
		}
		if ( array() === $variants ) {
			$variants = array_map( static fn ( object $variant ): array => method_exists( $variant, 'to_array' ) ? $variant->to_array() : array(), ( new \WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticTariffVariantResolver() )->defaults() );
		}
		$mapper = new RussianPostShipmentProductMapper();
		$tariffs = array();
		foreach ( $variants as $variant ) {
			if ( ! is_array( $variant ) || empty( $variant['enabled'] ) || (string) ( $variant['delivery_type'] ?? '' ) !== $delivery_type ) {
				continue;
			}
			$object_code = (string) ( $variant['object_code'] ?? '' );
			if ( '' === $object_code ) {
				continue;
			}
			$tariffs[] = array(
				'object_code' => $object_code,
				'title' => (string) ( $variant['title'] ?? $object_code ),
				'is_ecom' => ! empty( $variant['is_ecom'] ) || ! empty( $variant['ecom'] ),
				'has_declared_value' => ! empty( $mapper->by_object_code( $object_code, $delivery_type )['has_declared_value'] ),
			);
		}

		return $tariffs;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function tariff_for_service_object( ?DeliveryService $service, string $object_code, string $delivery_type ): array {
		if ( ! $service instanceof DeliveryService ) {
			return array();
		}
		foreach ( $this->tariffs_for_service( $service, $delivery_type ) as $tariff ) {
			if ( $object_code === (string) ( $tariff['object_code'] ?? '' ) ) {
				return $tariff;
			}
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function declared_value_from_place_row( array $row ): Money {
		if ( array_key_exists( 'declared_value_rub', $row ) ) {
			return Money::from_kopecks( $this->whole_number_from_place_row( $row, 'declared_value_rub' ) * 100 );
		}

		return Money::from_kopecks( $this->whole_number_from_place_row( $row, 'declared_value_kopecks' ) );
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function whole_number_from_place_row( array $row, string $key ): int {
		$value = preg_replace( '/\D+/', '', (string) ( $row[ $key ] ?? '' ) ) ?? '';

		return '' !== $value ? max( 0, (int) $value ) : 0;
	}

	private function meta_string( object $order, string $key ): string {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}
		$value = $order->get_meta( $key, true );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	private function delivery_type_from_order( object $order ): string {
		$delivery_type = $this->meta_string( $order, '_wdc_platform_delivery_type' );
		if ( '' !== $delivery_type ) {
			return RussianPostDomesticSettings::normalize_delivery_type( $delivery_type );
		}
		$rate_id_delivery_type = RussianPostDomesticSettings::delivery_type_from_rate_id( $this->meta_string( $order, '_wdc_platform_rate_id' ) );

		return '' !== $rate_id_delivery_type ? $rate_id_delivery_type : DeliveryType::PICKUP;
	}

	private function active_carrier_key( object $order ): string {
		$carrier = $this->meta_string( $order, '_wdc_platform_carrier_key' );
		if ( '' !== $carrier ) {
			return $carrier;
		}
		$calculation = $this->calculation_data( $order );
		$carrier = (string) ( $calculation['carrier_key'] ?? '' );
		if ( '' !== $carrier ) {
			return $carrier;
		}

		return RussianPostDomesticSettings::CARRIER_KEY;
	}

	/**
	 * @param array<string,mixed> $pickup_row
	 * @return array<string,mixed>
	 */
	private function recipient_location_context( object $order, array $pickup_row = array() ): array {
		$city = method_exists( $order, 'get_shipping_city' ) ? trim( (string) $order->get_shipping_city() ) : '';
		$region = method_exists( $order, 'get_shipping_state' ) ? trim( (string) $order->get_shipping_state() ) : '';
		$postcode = method_exists( $order, 'get_shipping_postcode' ) ? trim( (string) $order->get_shipping_postcode() ) : '';
		$address = $this->shipping_address( $order );
		if ( '' === $city ) {
			$city = (string) ( $pickup_row['city_name'] ?? '' );
		}
		if ( '' === $region ) {
			$region = (string) ( $pickup_row['region_name'] ?? '' );
		}

		return array(
			'carrier_key' => CdekSettings::CARRIER_KEY,
			'service_key' => CdekSettings::SERVICE_KEY,
			'pickup_family' => CdekSettings::CARRIER_KEY . ':pickup',
			'country_code' => method_exists( $order, 'get_shipping_country' ) ? (string) $order->get_shipping_country() : 'RU',
			'city_name' => $city,
			'city_value' => $city,
			'region_name' => $region,
			'state_value' => $region,
			'postal_code' => $postcode,
			'postcode' => $postcode,
			'display_name' => '' !== $address ? $address : trim( implode( ', ', array_filter( array( $postcode, $region, $city ) ) ) ),
			'address' => $address,
			'fias_id' => $this->first_meta_string( $order, array( '_wdc_platform_fias_id', '_wdc_platform_city_fias_id', '_wdc_location_fias_id', '_shipping_fias_id' ) ),
			'gar_id' => $this->first_meta_string( $order, array( '_wdc_platform_gar_id', '_wdc_platform_city_gar_id', '_wdc_location_gar_id' ) ),
			'location_id' => $this->first_meta_string( $order, array( '_wdc_platform_location_id', '_wdc_platform_city_location_id', '_wdc_location_id' ) ),
			'lat' => $this->first_meta_string( $order, array( '_wdc_platform_lat', '_wdc_platform_location_lat', '_wdc_location_lat' ) ),
			'lng' => $this->first_meta_string( $order, array( '_wdc_platform_lng', '_wdc_platform_location_lng', '_wdc_location_lng' ) ),
		);
	}

	/**
	 * @param array<int,string> $keys
	 */
	private function first_meta_string( object $order, array $keys ): string {
		foreach ( $keys as $key ) {
			$value = $this->meta_string( $order, $key );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	private function calculation_data( object $order ): array {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}
		$value = $order->get_meta( '_wdc_delivery_calculation_data', true );
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function rate_meta_data( object $order ): array {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}
		$value = $order->get_meta( '_wdc_platform_rate_meta', true );
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $value ) ? $value : array();
	}

	/**
	 * @param array<string,mixed> $calculation
	 * @param array<string,mixed> $rate_meta
	 */
	private function cdek_city_code_from_saved_data( array $calculation, array $rate_meta ): int {
		foreach ( array(
			$calculation['api']['cdek_to_city_code'] ?? null,
			$rate_meta['api']['cdek_to_city_code'] ?? null,
			$rate_meta['location']['cdek_to_city_code'] ?? null,
			$calculation['api']['request_payload_sanitized']['to_location']['code'] ?? null,
			$rate_meta['request_payload_sanitized']['to_location']['code'] ?? null,
			$rate_meta['api']['request_payload_sanitized']['to_location']['code'] ?? null,
		) as $value ) {
			if ( is_numeric( $value ) && (int) $value > 0 ) {
				return (int) $value;
			}
		}

		return 0;
	}

	private function from_postcode( string $service_key ): string {
		$codes = $this->postoffice_codes();
		if ( array() !== $codes ) {
			return $codes[0];
		}
		$settings = $this->domestic_settings instanceof RussianPostDomesticSettings ? $this->domestic_settings->all( $service_key ) : array();

		return preg_replace( '/\D+/', '', (string) ( $settings['default_from_postcode'] ?? $settings['return_postcode'] ?? '630005' ) ) ?: '630005';
	}

	/**
	 * @return array<int,string>
	 */
	private function postoffice_codes(): array {
		return $this->otpravka_settings instanceof RussianPostOtpravkaApiSettings ? $this->otpravka_settings->postoffice_codes() : array( '630005' );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function order_id( object $order ): int {
		return method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
	}

	private function order_number( object $order ): string {
		return method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) $this->order_id( $order );
	}

	private function recipient_name( object $order ): string {
		$parts = array();
		foreach ( array( 'get_shipping_first_name', 'get_shipping_last_name' ) as $method ) {
			if ( method_exists( $order, $method ) ) {
				$parts[] = (string) $order->{$method}();
			}
		}
		$name = trim( implode( ' ', $parts ) );
		if ( '' !== $name ) {
			return $name;
		}

		return trim( ( method_exists( $order, 'get_billing_first_name' ) ? (string) $order->get_billing_first_name() : '' ) . ' ' . ( method_exists( $order, 'get_billing_last_name' ) ? (string) $order->get_billing_last_name() : '' ) );
	}

	private function phone( object $order ): string {
		return method_exists( $order, 'get_billing_phone' ) ? (string) $order->get_billing_phone() : '';
	}

	private function email( object $order ): string {
		return method_exists( $order, 'get_billing_email' ) ? (string) $order->get_billing_email() : '';
	}

	private function shipping_address( object $order ): string {
		return implode(
			', ',
			array_values(
				array_filter(
					array(
						method_exists( $order, 'get_shipping_postcode' ) ? trim( (string) $order->get_shipping_postcode() ) : '',
						method_exists( $order, 'get_shipping_state' ) ? trim( (string) $order->get_shipping_state() ) : '',
						method_exists( $order, 'get_shipping_city' ) ? trim( (string) $order->get_shipping_city() ) : '',
						method_exists( $order, 'get_shipping_address_1' ) ? trim( (string) $order->get_shipping_address_1() ) : '',
						method_exists( $order, 'get_shipping_address_2' ) ? trim( (string) $order->get_shipping_address_2() ) : '',
					),
					static fn ( string $value ): bool => '' !== trim( $value )
				)
			)
		);
	}

}
