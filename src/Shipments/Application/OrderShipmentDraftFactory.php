<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Application;

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

defined( 'ABSPATH' ) || exit;

final class OrderShipmentDraftFactory {
	public function __construct(
		private DeliveryServiceRepository $services,
		private ShipmentServiceSettings $shipment_settings,
		private ?RussianPostDomesticSettings $domestic_settings = null,
		private ?RussianPostOtpravkaApiSettings $otpravka_settings = null
	) {
	}

	public function create_request_from_order( object $order ): ShipmentCreateRequest {
		$service_key = $this->meta_string( $order, '_wdc_platform_service_key' );
		if ( '' === $service_key ) {
			$service_key = $this->meta_string( $order, '_wdc_platform_rate_id' );
		}
		if ( '' === $service_key || ! in_array( $service_key, array( RussianPostDomesticSettings::PICKUP_SERVICE_KEY, RussianPostDomesticSettings::COURIER_SERVICE_KEY ), true ) ) {
			$service_key = RussianPostDomesticSettings::PICKUP_SERVICE_KEY;
		}
		$service = $this->services->find_by_service_key( $service_key );
		$delivery_type = RussianPostDomesticSettings::service_delivery_type( $service_key );
		$items = $this->order_items( $order );
		$weight = $this->default_weight_g( $order, $items );
		$declared_value = Money::from_kopecks( 0 );
		$place = new ShipmentPlace( 1, $weight, 20, 20, 10, $declared_value, $items );
		$settings = $this->shipment_settings->for_service( $service );
		$order_number = $this->order_number( $order );
		$settings['shelf_life_days'] = (int) ( $settings[ ShipmentServiceSettings::SHELF_LIFE_DAYS_DEFAULT ] ?? 30 );
		$settings['combine_goods_items'] = ! empty( $settings[ ShipmentServiceSettings::COMBINE_GOODS_ITEMS_DEFAULT ] );
		$settings['combined_goods_name_template'] = (string) ( $settings[ ShipmentServiceSettings::COMBINED_GOODS_NAME_TEMPLATE ] ?? 'Товары по заказу {order_number}' );
		$settings['combined_goods_name'] = str_replace( '{order_number}', $order_number, $settings['combined_goods_name_template'] );
		$tariff_object = $this->meta_string( $order, '_wdc_platform_tariff_object' );
		$tariff = $this->tariff_for_service_object( $service, $tariff_object );

		return new ShipmentCreateRequest(
			order_id: $this->order_id( $order ),
			carrier_key: RussianPostDomesticSettings::CARRIER_KEY,
			delivery_type: $delivery_type,
			rate_id: $service_key,
			recipient_address: $this->recipient_address( $order, $delivery_type ),
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
				'service_title' => $service instanceof DeliveryService ? $service->title : $this->meta_string( $order, '_wdc_platform_service_title' ),
				'tariff_object' => $tariff_object,
				'tariff_title' => (string) ( $tariff['title'] ?? $this->meta_string( $order, '_wdc_platform_tariff_title' ) ),
				'tariff_is_ecom' => ! empty( $tariff['is_ecom'] ),
				'order_num' => $order_number,
				'postoffice_code' => $this->from_postcode( $service_key ),
				'pickup_point_code' => $this->meta_string( $order, '_wdc_pickup_point_code' ),
				'pickup_point_postcode' => $this->meta_string( $order, '_wdc_pickup_point_postcode' ),
				'calculation_data' => $this->calculation_data( $order ),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function draft_array( object $order ): array {
		$request = $this->create_request_from_order( $order );
		$services = array_values(
			array_filter(
				$this->services->list_active(),
				static fn ( DeliveryService $service ): bool => RussianPostDomesticSettings::CARRIER_KEY === $service->carrier_key
			)
		);

		return array(
			'request' => $request->to_array(),
			'services' => array_map(
				fn ( DeliveryService $service ): array => array(
					'service_key' => $service->service_key,
					'title' => $service->title,
					'delivery_type' => RussianPostDomesticSettings::service_delivery_type( $service->service_key ),
					'tariffs' => $this->tariffs_for_service( $service ),
				),
				$services
			),
			'postoffice_codes' => $this->postoffice_codes(),
		);
	}

	public function create_request_from_admin_data( object $order, array $data ): ShipmentCreateRequest {
		$base = $this->create_request_from_order( $order );
		$service_key = sanitize_key( wp_unslash( $data['service_key'] ?? $base->rate_id ) );
		$service = $this->services->find_by_service_key( $service_key );
		$delivery_type = RussianPostDomesticSettings::service_delivery_type( $service_key );
		$places = array();
		$place_rows = is_array( $data['places'] ?? null ) ? $data['places'] : array();
		foreach ( $place_rows as $index => $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$places[] = new ShipmentPlace(
				$index + 1,
				max( 0, (int) ( $row['weight_g'] ?? 0 ) ),
				max( 0, (int) ( $row['length_cm'] ?? 0 ) ),
				max( 0, (int) ( $row['width_cm'] ?? 0 ) ),
				max( 0, (int) ( $row['height_cm'] ?? 0 ) ),
				$this->declared_value_from_place_row( $row ),
				0 === $index ? ( $base->places[0]->items ?? array() ) : array()
			);
		}
		$settings = $this->shipment_settings->for_service( $service );
		$settings['shelf_life_days'] = max( 15, min( 60, (int) ( $settings[ ShipmentServiceSettings::SHELF_LIFE_DAYS_DEFAULT ] ?? 30 ) ) );
		$settings['send_goods_items'] = ! empty( $data['send_goods_items'] ) && ! empty( $settings[ ShipmentServiceSettings::SEND_GOODS_ITEMS ] );
		$settings['combine_goods_items'] = ! empty( $data['combine_goods_items'] );
		$settings['combined_goods_name'] = sanitize_text_field( wp_unslash( $data['combined_goods_name'] ?? $settings[ ShipmentServiceSettings::COMBINED_GOODS_NAME_TEMPLATE ] ?? '' ) );
		$tariff_object = sanitize_text_field( wp_unslash( $data['tariff_object'] ?? $base->meta['tariff_object'] ?? '' ) );
		$tariff = $this->tariff_for_service_object( $service, $tariff_object );

		return new ShipmentCreateRequest(
			$base->order_id,
			$base->carrier_key,
			$delivery_type,
			$service_key,
			$this->address_from_admin_data( $base->recipient_address, $data, $delivery_type ),
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
					'service_title' => $service instanceof DeliveryService ? $service->title : (string) ( $base->meta['service_title'] ?? '' ),
					'tariff_object' => $tariff_object,
					'tariff_title' => (string) ( $tariff['title'] ?? $base->meta['tariff_title'] ?? '' ),
					'tariff_is_ecom' => ! empty( $tariff['is_ecom'] ),
					'postoffice_code' => preg_replace( '/\D+/', '', (string) wp_unslash( $data['postoffice_code'] ?? $base->meta['postoffice_code'] ?? '' ) ) ?: '',
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
			$items[] = new PackageItem(
				is_object( $product ) && method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '',
				method_exists( $item, 'get_name' ) ? (string) $item->get_name() : 'Товар',
				$qty,
				Money::from_rubles( $qty > 0 ? $total / $qty : $total ),
				Money::from_rubles( $total ),
				$weight_g
			);
		}

		return $items;
	}

	private function default_weight_g( object $order, array $items ): int {
		$calculation = $this->calculation_data( $order );
		$package = is_array( $calculation['package'] ?? null ) ? $calculation['package'] : array();
		$weight = (int) ( $package['package_weight_with_packaging_g'] ?? $package['final_weight_g'] ?? $package['products_weight_g'] ?? 0 );
		if ( $weight > 0 ) {
			return $weight;
		}
		$total = 0;
		foreach ( $items as $item ) {
			$total += $item instanceof PackageItem ? $item->get_total_weight_g() : 0;
		}

		return max( 1, $total ?: 1000 );
	}

	private function recipient_address( object $order, string $delivery_type ): Address {
		$postcode = $this->meta_string( $order, '_wdc_pickup_point_postcode' ) ?: $this->meta_string( $order, '_wdc_platform_resolved_postcode' );
		if ( DeliveryType::COURIER === $delivery_type && method_exists( $order, 'get_shipping_postcode' ) ) {
			$postcode = (string) $order->get_shipping_postcode() ?: $postcode;
		}

		return new Address(
			country_code: method_exists( $order, 'get_shipping_country' ) ? (string) $order->get_shipping_country() : 'RU',
			region_name: $this->shipping_region( $order ),
			city: method_exists( $order, 'get_shipping_city' ) ? (string) $order->get_shipping_city() : $this->meta_string( $order, '_wdc_platform_city_display_name' ),
			postcode: $postcode,
			street: method_exists( $order, 'get_shipping_address_1' ) ? (string) $order->get_shipping_address_1() : '',
			apartment: method_exists( $order, 'get_shipping_address_2' ) ? (string) $order->get_shipping_address_2() : '',
			raw_address: $this->shipping_address( $order ),
			fias_id: $this->meta_string( $order, '_wdc_platform_fias_id' ),
			gar_id: $this->meta_string( $order, '_wdc_platform_gar_id' )
		);
	}

	private function address_from_admin_data( Address $base, array $data, string $delivery_type ): Address {
		return new Address(
			country_code: $base->country_code ?: 'RU',
			region_name: sanitize_text_field( wp_unslash( $data['region_name'] ?? $data['region_to'] ?? $base->region_name ) ),
			city: sanitize_text_field( wp_unslash( $data['city'] ?? $base->city ) ),
			postcode: preg_replace( '/\D+/', '', (string) wp_unslash( $data['postcode'] ?? $base->postcode ) ) ?: '',
			raw_address: sanitize_text_field( wp_unslash( $data['raw_address'] ?? $base->raw_address ) ),
			fias_id: $base->fias_id,
			gar_id: $base->gar_id
		);
	}

	private function pickup_point( object $order ): ?PickupPointSelection {
		$code = $this->meta_string( $order, '_wdc_pickup_point_code' );
		if ( '' === $code ) {
			return null;
		}

		return new PickupPointSelection( RussianPostDomesticSettings::CARRIER_KEY, RussianPostDomesticSettings::PICKUP_SERVICE_KEY, $code, $this->meta_string( $order, '_wdc_pickup_point_address' ), $this->now() );
	}

	private function pickup_from_admin_data( ?PickupPointSelection $base, array $data ): ?PickupPointSelection {
		$code = sanitize_text_field( wp_unslash( $data['pickup_point_code'] ?? $base?->point_code ?? '' ) );
		if ( '' === $code ) {
			return null;
		}

		return new PickupPointSelection(
			RussianPostDomesticSettings::CARRIER_KEY,
			RussianPostDomesticSettings::PICKUP_SERVICE_KEY,
			$code,
			sanitize_text_field( wp_unslash( $data['pickup_point_address'] ?? $base?->point_address ?? '' ) ),
			$base?->selected_at ?: $this->now()
		);
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function tariffs_for_service( DeliveryService $service ): array {
		$settings = $this->domestic_settings instanceof RussianPostDomesticSettings ? $this->domestic_settings->all( $service->service_key ) : array();
		$variants = is_array( $settings['tariff_variants'] ?? null ) ? $settings['tariff_variants'] : array();
		if ( array() === $variants ) {
			$variants = array_map( static fn ( object $variant ): array => method_exists( $variant, 'to_array' ) ? $variant->to_array() : array(), ( new \WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticTariffVariantResolver() )->defaults() );
		}
		$delivery_type = RussianPostDomesticSettings::service_delivery_type( $service->service_key );
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
			);
		}

		return $tariffs;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function tariff_for_service_object( ?DeliveryService $service, string $object_code ): array {
		if ( ! $service instanceof DeliveryService ) {
			return array();
		}
		foreach ( $this->tariffs_for_service( $service ) as $tariff ) {
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
			$rubles = trim( (string) $row['declared_value_rub'] );
			return Money::from_kopecks( 1 === preg_match( '/^\d+$/', $rubles ) ? max( 0, (int) $rubles ) * 100 : 0 );
		}

		return Money::from_kopecks( max( 0, (int) ( $row['declared_value_kopecks'] ?? 0 ) ) );
	}

	private function meta_string( object $order, string $key ): string {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}
		$value = $order->get_meta( $key, true );

		return is_scalar( $value ) ? trim( (string) $value ) : '';
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
		$parts = array();
		foreach ( array( 'get_shipping_postcode', 'get_shipping_city', 'get_shipping_address_1', 'get_shipping_address_2' ) as $method ) {
			if ( ! method_exists( $order, $method ) ) {
				continue;
			}
			$value = trim( (string) $order->{$method}() );
			if ( '' !== $value ) {
				$parts[ $method ] = $value;
			}
		}

		return implode(
			', ',
			array_values(
				array_filter(
					array(
						$parts['get_shipping_postcode'] ?? '',
						$this->shipping_region( $order ),
						$parts['get_shipping_city'] ?? '',
						$parts['get_shipping_address_1'] ?? '',
						$this->address_2_for_raw_address( $parts['get_shipping_address_2'] ?? '' ),
					),
					static fn ( string $value ): bool => '' !== trim( $value )
				)
			)
		);
	}

	private function shipping_region( object $order ): string {
		if ( method_exists( $order, 'get_shipping_state' ) ) {
			$state = trim( (string) $order->get_shipping_state() );
			if ( '' !== $state ) {
				return $state;
			}
		}

		foreach ( array( '_wdc_platform_region_name', '_wdc_platform_location_region_name' ) as $key ) {
			$value = $this->meta_string( $order, $key );
			if ( '' !== $value ) {
				return $value;
			}
		}

		$calculation = $this->calculation_data( $order );
		$destination = is_array( $calculation['destination'] ?? null ) ? $calculation['destination'] : array();
		foreach ( array( 'region_name', 'region', 'state', 'state_name' ) as $key ) {
			$value = trim( (string) ( $destination[ $key ] ?? '' ) );
			if ( '' !== $value ) {
				return $value;
			}
		}

		$display = $this->meta_string( $order, '_wdc_platform_city_display_name' );
		if ( str_contains( $display, '—' ) ) {
			$parts = array_map( 'trim', explode( '—', $display, 2 ) );
			return (string) ( $parts[1] ?? '' );
		}

		return '';
	}

	private function address_2_for_raw_address( string $address_2 ): string {
		$address_2 = trim( $address_2 );
		if ( '' === $address_2 ) {
			return '';
		}

		return 1 === preg_match( '/^Код\s+ПВЗ/iu', $address_2 ) ? '' : $address_2;
	}
}
