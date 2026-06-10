<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Application;

use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Locations\Services\LocationDisplayNameFormatter;
use WallsShop\WDC\Locations\ValueObjects\Location;
use WallsShop\WDC\Rules\Services\RuleFormulaFormatter;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class OrderDeliveryReplacementService {
	private const METHOD_ID = 'wdc_platform_delivery';

	public function __construct(
		private OrderShipmentRepository $shipments
	) {
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{success:bool,message:string}
	 */
	public function save( object $order, array $payload ): array {
		$shipping_items = $this->shipping_items( $order );
		if ( count( $shipping_items ) > 1 ) {
			return array( 'success' => false, 'message' => 'Сохранение недоступно: в заказе больше одного shipping item.' );
		}
		if ( $this->has_registered_shipment( $order ) ) {
			return array( 'success' => false, 'message' => 'Сохранение недоступно: по заказу уже зарегистрировано отправление.' );
		}

		$rate = $this->selected_rate( is_array( $payload['selected_rate'] ?? null ) ? $payload['selected_rate'] : array(), is_array( $payload['selected_tariff'] ?? null ) ? $payload['selected_tariff'] : array() );
		if ( '' === (string) ( $rate['rate_id'] ?? '' ) ) {
			return array( 'success' => false, 'message' => 'Выберите вариант доставки.' );
		}
		$location = is_array( $payload['selected_location'] ?? null ) ? $payload['selected_location'] : array();
		$pickup = is_array( $payload['selected_pickup_point'] ?? null ) ? $payload['selected_pickup_point'] : array();
		$address = is_array( $payload['normalized_shipping_address'] ?? null ) ? $payload['normalized_shipping_address'] : array();
		if ( DeliveryType::PICKUP === (string) ( $rate['delivery_type'] ?? '' ) ) {
			if ( '' === trim( (string) ( $pickup['point_code'] ?? '' ) ) ) {
				return array( 'success' => false, 'message' => 'Для pickup-варианта выберите ПВЗ.' );
			}
		} elseif ( DeliveryType::COURIER === (string) ( $rate['delivery_type'] ?? '' ) && ! $this->valid_courier_address( $address ) ) {
			return array( 'success' => false, 'message' => 'Для курьерской доставки проверьте адрес доставки или используйте введенный адрес вручную.' );
		}

		$old = $this->note_snapshot( $order );
		$this->replace_shipping_item( $order, $shipping_items, $rate );
		$this->write_order_meta( $order, $rate, $location, $pickup, $address );
		$this->write_shipping_address( $order, $rate, $location, $pickup, $address );
		if ( method_exists( $order, 'calculate_totals' ) ) {
			$order->calculate_totals( false );
		} elseif ( property_exists( $order, 'total' ) ) {
			$order->total = (float) $order->total - (float) $old['shipping_cost'] + (float) ( $rate['cost'] ?? 0 );
		}
		if ( method_exists( $order, 'add_order_note' ) ) {
			$order->add_order_note( $this->note( $old, $this->note_snapshot( $order, $rate ), $this->location_changed( $old, $location ), $location ), false, false );
		}
		if ( method_exists( $order, 'save' ) ) {
			$order->save();
		}

		return array( 'success' => true, 'message' => 'Новый вариант доставки сохранен.' );
	}

	public function has_registered_shipment( object $order ): bool {
		foreach ( $this->shipments->all_for_order( $order ) as $shipment ) {
			if ( ! is_array( $shipment ) ) {
				continue;
			}
			foreach ( array( 'tracking_number', 'barcode', 'backlog_order_id', 'universal_status_code', 'carrier_status_title', 'tracking_checked_at' ) as $key ) {
				if ( '' !== trim( (string) ( $shipment[ $key ] ?? '' ) ) ) {
					return true;
				}
			}
			if ( in_array( (string) ( $shipment['status'] ?? '' ), array( 'created', 'registered' ), true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string,mixed> $address
	 */
	private function valid_courier_address( array $address ): bool {
		$line = trim( (string) ( $address['address_1'] ?? $address['full_address'] ?? '' ) );
		if ( '' === $line ) {
			return false;
		}
		if ( ! empty( $address['normalized'] ) && empty( $address['fallback'] ) ) {
			return true;
		}

		return ! empty( $address['fallback'] ) && 'admin_manual' === (string) ( $address['source'] ?? '' );
	}

	/**
	 * @return array<int,mixed>
	 */
	private function shipping_items( object $order ): array {
		if ( method_exists( $order, 'get_items' ) ) {
			$items = $order->get_items( 'shipping' );
			if ( is_array( $items ) ) {
				return array_values( $items );
			}
		}
		if ( property_exists( $order, 'shipping_items' ) ) {
			if ( array() === $order->shipping_items ) {
				return array();
			}
			return array_is_list( $order->shipping_items ) ? $order->shipping_items : array( $order->shipping_items );
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $tariff
	 * @return array<string,mixed>
	 */
	private function selected_rate( array $rate, array $tariff ): array {
		$id = (string) ( $rate['id'] ?? '' );
		if ( ! empty( $rate['is_grouped'] ) ) {
			$rate['rate_id'] = (string) ( $tariff['rate_id'] ?? $id );
			$rate['selected_tariff_object'] = (string) ( $tariff['object_code'] ?? '' );
			$rate['selected_tariff_title'] = (string) ( $tariff['title'] ?? '' );
			$rate['tariff_title'] = (string) ( $tariff['title'] ?? '' );
			$rate['cost'] = (float) ( $tariff['cost'] ?? $rate['cost'] ?? 0 );
			$rate['delivery_comment'] = (string) ( $tariff['delivery_comment'] ?? $rate['delivery_comment'] ?? '' );
			if ( is_array( $tariff['rate_meta'] ?? null ) ) {
				$rate['rate_meta'] = $tariff['rate_meta'];
			}
			foreach ( array( 'api_base_price_rub', 'crossed_price', 'planned_delivery_comment', 'rules_source', 'round_up_applied', 'minimum_price_applied' ) as $key ) {
				if ( array_key_exists( $key, $tariff ) ) {
					$rate[ $key ] = $tariff[ $key ];
				}
			}
		} else {
			$rate['rate_id'] = (string) ( $rate['rate_id'] ?? $id );
		}
		$rate['cost'] = (float) ( $rate['cost'] ?? 0 );

		return $rate;
	}

	/**
	 * @param array<int,mixed> $items
	 * @param array<string,mixed> $rate
	 */
	private function replace_shipping_item( object $order, array $items, array $rate ): void {
		$item = $items[0] ?? null;
		if ( null === $item ) {
			$item = class_exists( '\WC_Order_Item_Shipping' ) ? new \WC_Order_Item_Shipping() : array();
			if ( is_object( $item ) && method_exists( $order, 'add_item' ) ) {
				$order->add_item( $item );
			} elseif ( property_exists( $order, 'shipping_items' ) ) {
				$order->shipping_items = array();
			}
		}
		$title = $this->method_title( $rate );
		if ( is_object( $item ) ) {
			if ( method_exists( $item, 'set_method_id' ) ) {
				$item->set_method_id( self::METHOD_ID );
			}
			if ( method_exists( $item, 'set_method_title' ) ) {
				$item->set_method_title( $title );
			}
			if ( method_exists( $item, 'set_total' ) ) {
				$item->set_total( (string) ( $rate['cost'] ?? 0 ) );
			}
			if ( method_exists( $item, 'delete_meta_data' ) ) {
				foreach ( $this->visible_shipping_item_meta_keys() as $key ) {
					$item->delete_meta_data( $key );
				}
			}
			if ( method_exists( $item, 'add_meta_data' ) && '' !== (string) ( $rate['delivery_comment'] ?? '' ) ) {
				$item->add_meta_data( 'Срок доставки', (string) $rate['delivery_comment'], true );
			}
			if ( method_exists( $item, 'save' ) ) {
				$item->save();
			}
			return;
		}
		if ( property_exists( $order, 'shipping_items' ) ) {
			$meta = array();
			if ( '' !== (string) ( $rate['delivery_comment'] ?? '' ) ) {
				$meta['Срок доставки'] = (string) $rate['delivery_comment'];
			}
			$order->shipping_items = array(
				'method_id' => self::METHOD_ID,
				'method_title' => $title,
				'total' => (float) ( $rate['cost'] ?? 0 ),
				'meta' => $meta,
			);
		}
	}

	/**
	 * @return array<int,string>
	 */
	private function visible_shipping_item_meta_keys(): array {
		return array(
			'carrier_key',
			'rate_id',
			'delivery_type',
			'planned_delivery_comment',
			'service_key',
			'service_title',
			'rules_source',
			'round_up_applied',
			'minimum_price_applied',
			'final_price_rub',
			'api_base_price_rub',
			'api_price_with_vat_rub',
			'tariff_key',
			'tariff_title',
			'selected_tariff_object',
			'selected_tariff_title',
			'selected_tariff_rate_id',
			'requires_pickup_point',
			'requires_courier_address',
			'Перевозчик',
			'Способ доставки',
			'Тип доставки',
			'Срок доставки',
			'Населенный пункт',
			'Нормализация',
			'Код ПВЗ',
			'Адрес ПВЗ',
			'Комментарий ПВЗ',
			'Режим работы ПВЗ',
			'Пункт выдачи',
			'Индекс ПВЗ',
			'Тип ПВЗ',
		);
	}

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $location
	 * @param array<string,mixed> $pickup
	 * @param array<string,mixed> $address
	 */
	private function write_order_meta( object $order, array $rate, array $location, array $pickup, array $address ): void {
		$map = array(
			'_wdc_platform_carrier_key' => (string) ( $rate['carrier_key'] ?? '' ),
			'_wdc_platform_rate_id' => (string) ( $rate['rate_id'] ?? $rate['id'] ?? '' ),
			'_wdc_platform_delivery_type' => (string) ( $rate['delivery_type'] ?? '' ),
			'_wdc_platform_crossed_price' => $rate['crossed_price'] ?? null,
			'_wdc_platform_planned_delivery_comment' => (string) ( $rate['planned_delivery_comment'] ?? $rate['delivery_comment'] ?? '' ),
			'_wdc_platform_comments' => is_array( $rate['comments'] ?? null ) ? $rate['comments'] : array(),
			'_wdc_platform_fallback_used' => ! empty( $rate['fallback_used'] ) || 'fallback' === (string) ( $rate['carrier_key'] ?? '' ) ? 1 : 0,
			'_wdc_platform_requires_pickup_point' => DeliveryType::PICKUP === (string) ( $rate['delivery_type'] ?? '' ) ? 1 : 0,
			'_wdc_platform_service_key' => (string) ( $rate['service_key'] ?? '' ),
			'_wdc_platform_service_title' => (string) ( $rate['service_title'] ?? $rate['label'] ?? '' ),
			'_wdc_platform_tariff_object' => (string) ( $rate['selected_tariff_object'] ?? '' ),
			'_wdc_platform_tariff_title' => (string) ( $rate['selected_tariff_title'] ?? $rate['tariff_title'] ?? '' ),
			'_wdc_platform_rules_source' => (string) ( $rate['rules_source'] ?? 'none' ),
			'_wdc_platform_round_up_applied' => ! empty( $rate['round_up_applied'] ) ? 1 : 0,
			'_wdc_platform_minimum_price_applied' => ! empty( $rate['minimum_price_applied'] ) ? 1 : 0,
			'_wdc_platform_rate_meta' => is_array( $rate['rate_meta'] ?? null ) ? $rate['rate_meta'] : $rate,
			'_wdc_platform_city_display_name' => $this->location_label( $location ),
			'_wdc_platform_city_postcode' => (string) ( $location['postal_code'] ?? $location['postcode'] ?? $address['postcode'] ?? '' ),
			'_wdc_platform_city_fias_id' => (string) ( $location['fias_id'] ?? '' ),
			'_wdc_platform_city_gar_id' => (string) ( $location['gar_object_id'] ?? $location['gar_id'] ?? '' ),
			'_wdc_platform_resolved_postcode' => (string) ( $address['postcode'] ?? $location['postal_code'] ?? $location['postcode'] ?? '' ),
			'_wdc_platform_normalized' => ! empty( $address['normalized'] ) ? 1 : 0,
			'_wdc_platform_normalization_source' => (string) ( $address['source'] ?? '' ),
			OrderShippingMetaPersister::CALCULATION_META_KEY => $this->calculation_data( $rate, $location, $pickup, $address ),
		);
		if ( DeliveryType::PICKUP === (string) ( $rate['delivery_type'] ?? '' ) ) {
			$map['_wdc_platform_pickup_code'] = (string) ( $pickup['point_code'] ?? '' );
			$map['_wdc_platform_pickup_address'] = (string) ( $pickup['point_address'] ?? $pickup['address'] ?? '' );
			$map['_wdc_pickup_point_code'] = (string) ( $pickup['point_code'] ?? '' );
			$map['_wdc_pickup_point_type'] = (string) ( $pickup['point_type'] ?? '' );
			$map['_wdc_pickup_point_address'] = (string) ( $pickup['point_address'] ?? $pickup['address'] ?? '' );
			$map['_wdc_pickup_point_postcode'] = (string) ( $pickup['point_postcode'] ?? $pickup['postcode'] ?? '' );
			$map['_wdc_pickup_point_snapshot'] = function_exists( 'wp_json_encode' ) ? wp_json_encode( $pickup, JSON_UNESCAPED_UNICODE ) : json_encode( $pickup );
		} else {
			$map['_wdc_platform_pickup_code'] = '';
			$map['_wdc_platform_pickup_address'] = '';
			$map['_wdc_pickup_point_code'] = '';
			$map['_wdc_pickup_point_type'] = '';
			$map['_wdc_pickup_point_address'] = '';
			$map['_wdc_pickup_point_postcode'] = '';
			$map['_wdc_pickup_point_snapshot'] = '';
		}
		foreach ( $map as $key => $value ) {
			if ( method_exists( $order, 'update_meta_data' ) ) {
				$order->update_meta_data( $key, $value );
			} elseif ( property_exists( $order, 'meta' ) ) {
				$order->meta[ $key ] = $value;
			}
		}
	}

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $location
	 * @param array<string,mixed> $pickup
	 * @param array<string,mixed> $address
	 */
	private function write_shipping_address( object $order, array $rate, array $location, array $pickup, array $address ): void {
		$location_values = $this->checkout_shipping_location_values( $location, $address );
		$values = array(
			'set_shipping_country' => $location_values['country'],
			'set_shipping_state' => $location_values['state'],
			'set_shipping_city' => $location_values['city'],
			'set_shipping_postcode' => $location_values['postcode'],
		);
		if ( DeliveryType::PICKUP === (string) ( $rate['delivery_type'] ?? '' ) ) {
			$pickup_location_values = $this->checkout_shipping_location_values( $location, array() );
			$values['set_shipping_country'] = 'RU';
			$values['set_shipping_state'] = '' !== $pickup_location_values['state'] ? $pickup_location_values['state'] : (string) ( $pickup['region_name'] ?? '' );
			$values['set_shipping_city'] = '' !== $pickup_location_values['city'] ? $pickup_location_values['city'] : (string) ( $pickup['city_name'] ?? '' );
			$values['set_shipping_postcode'] = (string) ( $pickup['point_postcode'] ?? $pickup['postcode'] ?? $location['postal_code'] ?? $location['postcode'] ?? '' );
			$values['set_shipping_address_1'] = (string) ( $pickup['point_address'] ?? $pickup['address'] ?? '' );
			$values['set_shipping_address_2'] = '';
		} elseif ( DeliveryType::COURIER === (string) ( $rate['delivery_type'] ?? '' ) && array() !== $address ) {
			$values['set_shipping_address_1'] = (string) ( $address['address_1'] ?? $address['full_address'] ?? '' );
			$values['set_shipping_address_2'] = ! empty( $address['fallback'] ) && 'admin_manual' === (string) ( $address['source'] ?? '' ) ? '' : (string) ( $address['address_2'] ?? '' );
		}
		foreach ( $values as $method => $value ) {
			if ( method_exists( $order, $method ) && ( '' !== $value || 'set_shipping_address_2' === $method ) ) {
				$order->{$method}( $value );
			}
		}
	}

	/**
	 * @param array<string,mixed> $location
	 * @param array<string,mixed> $address
	 * @return array{country:string,state:string,city:string,postcode:string}
	 */
	private function checkout_shipping_location_values( array $location, array $address ): array {
		$state = trim( (string) ( $location['state_value'] ?? '' ) );
		if ( '' === $state ) {
			$state = $this->formatted_location_state( $location );
		}
		if ( '' === $state ) {
			$state = trim( (string) ( $location['region_name'] ?? $address['region'] ?? '' ) );
		}

		$city = trim( (string) ( $location['city_value'] ?? '' ) );
		if ( '' === $city ) {
			$city = $this->formatted_location_city( $location );
		}
		if ( '' === $city ) {
			$city = trim( (string) ( $address['city'] ?? $location['city_name'] ?? $location['place_name'] ?? '' ) );
		}
		if ( '' === $city ) {
			$city = $this->city_from_display_name( (string) ( $location['display_name'] ?? '' ), $state );
		}

		return array(
			'country' => (string) ( $address['country'] ?? $location['country_code'] ?? 'RU' ),
			'state' => $state,
			'city' => $city,
			'postcode' => (string) ( $address['postcode'] ?? $location['postal_code'] ?? $location['postcode'] ?? '' ),
		);
	}

	/**
	 * @param array<string,mixed> $location
	 */
	private function formatted_location_state( array $location ): string {
		$location_object = $this->location_from_payload( $location );
		return null !== $location_object ? $this->location_formatter()->format_checkout_state_value( $location_object ) : '';
	}

	/**
	 * @param array<string,mixed> $location
	 */
	private function formatted_location_city( array $location ): string {
		$location_object = $this->location_from_payload( $location );
		return null !== $location_object ? $this->location_formatter()->format_checkout_city_value( $location_object ) : '';
	}

	/**
	 * @param array<string,mixed> $location
	 */
	private function location_from_payload( array $location ): ?Location {
		if ( '' === trim( (string) ( $location['region_name'] ?? '' ) ) && '' === trim( (string) ( $location['city_name'] ?? $location['place_name'] ?? $location['settlement_name'] ?? '' ) ) ) {
			return null;
		}
		return Location::from_array( $location );
	}

	private function location_formatter(): LocationDisplayNameFormatter {
		$rules = function_exists( 'get_option' ) ? get_option( 'wdc_location_type_display_rules', array() ) : array();
		return LocationDisplayNameFormatter::from_rules( is_array( $rules ) ? $rules : array() );
	}

	private function city_from_display_name( string $display_name, string $state ): string {
		$parts = array_values(
			array_filter(
				array_map( 'trim', explode( ',', $display_name ) ),
				static fn( string $part ): bool => '' !== $part
			)
		);
		if ( array() === $parts ) {
			return '';
		}
		$state_normalized = $this->canonical_region( $state );
		$candidates = array_values(
			array_filter(
				$parts,
				fn( string $part ): bool => '' === $state_normalized || $this->canonical_region( $part ) !== $state_normalized
			)
		);
		return (string) ( end( $candidates ) ?: end( $parts ) );
	}

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $location
	 * @param array<string,mixed> $pickup
	 * @param array<string,mixed> $address
	 * @return array<string,mixed>
	 */
	private function calculation_data( array $rate, array $location, array $pickup, array $address ): array {
		$rate_meta = is_array( $rate['rate_meta'] ?? null ) ? $rate['rate_meta'] : array();
		$api_base = $this->base_price( $rate );
		$final = (float) ( $rate['cost'] ?? 0 );
		return array(
			'carrier_key' => (string) ( $rate['carrier_key'] ?? '' ),
			'service_key' => (string) ( $rate['service_key'] ?? '' ),
			'service_title' => (string) ( $rate['service_title'] ?? $rate['label'] ?? '' ),
			'rate_id' => (string) ( $rate['rate_id'] ?? $rate['id'] ?? '' ),
			'selected_tariff_object' => (string) ( $rate['selected_tariff_object'] ?? '' ),
			'selected_tariff_title' => (string) ( $rate['selected_tariff_title'] ?? $rate['tariff_title'] ?? '' ),
			'delivery_type' => (string) ( $rate['delivery_type'] ?? '' ),
			'destination' => array(
				'country_code' => (string) ( $address['country'] ?? $location['country_code'] ?? 'RU' ),
				'city_display_name' => $this->location_label( $location ),
				'region_name' => (string) ( $address['region'] ?? $location['region_name'] ?? '' ),
				'postcode' => (string) ( $address['postcode'] ?? $location['postal_code'] ?? $location['postcode'] ?? '' ),
				'fias_id' => (string) ( $location['fias_id'] ?? $address['fias_id'] ?? '' ),
			),
			'pickup' => DeliveryType::PICKUP === (string) ( $rate['delivery_type'] ?? '' ) ? array(
				'point_code' => (string) ( $pickup['point_code'] ?? '' ),
				'point_type' => (string) ( $pickup['point_type'] ?? '' ),
				'point_name' => (string) ( $pickup['point_name'] ?? '' ),
				'point_address' => (string) ( $pickup['point_address'] ?? $pickup['address'] ?? '' ),
				'point_postcode' => (string) ( $pickup['point_postcode'] ?? $pickup['postcode'] ?? '' ),
				'point_raw' => $pickup,
			) : array(),
			'package' => $this->calculation_package_data( $rate_meta ),
			'api' => $this->calculation_api_data( $rate, $rate_meta, $api_base ),
			'rules' => $this->calculation_rules_data( $rate, $rate_meta, $api_base, $final ),
			'result' => $this->calculation_result_data( $rate, $rate_meta, $final ),
		);
	}

	/**
	 * @param array<string,mixed> $rate_meta
	 * @return array<string,mixed>
	 */
	private function calculation_package_data( array $rate_meta ): array {
		$package = is_array( $rate_meta['package'] ?? null ) ? $rate_meta['package'] : array();
		$final_weight = (int) ( $rate_meta['package_weight_with_packaging_g'] ?? $package['total_weight_g'] ?? $package['final_weight_g'] ?? $package['weight_g'] ?? $rate_meta['final_weight_g'] ?? $rate_meta['package_weight_g'] ?? 0 );
		return array(
			'products_weight_g' => (int) ( $rate_meta['products_weight_g'] ?? $package['products_weight_g'] ?? $package['weight_g'] ?? $final_weight ),
			'packaging_weight_g' => (int) ( $rate_meta['packaging_weight_g'] ?? $package['packaging_weight_g'] ?? 0 ),
			'final_weight_g' => $final_weight,
			'include_packaging_weight' => ! empty( $rate_meta['include_packaging_weight'] ) || ! empty( $package['include_packaging_weight'] ),
			'packaging_weight_mode' => (string) ( $rate_meta['packaging_weight_mode'] ?? $package['packaging_weight_mode'] ?? '' ),
		);
	}

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $rate_meta
	 * @return array<string,mixed>
	 */
	private function calculation_api_data( array $rate, array $rate_meta, float $api_base ): array {
		$api = is_array( $rate_meta['api'] ?? null ) ? $rate_meta['api'] : array();
		$api_result = is_array( $rate_meta['api_result'] ?? null ) ? $rate_meta['api_result'] : array();
		$country = is_array( $rate_meta['country_mapping'] ?? null ) ? $rate_meta['country_mapping'] : array();
		$min_days = $rate_meta['delivery_min_days'] ?? $api['delivery_min_days'] ?? $api['api_delivery_min_days'] ?? $api['delivery_days'] ?? null;
		$max_days = $rate_meta['delivery_max_days'] ?? $api['delivery_max_days'] ?? $api['api_delivery_max_days'] ?? $api['delivery_days'] ?? null;
		$data = array(
			'api_base_price_rub' => $api_base,
			'api_price_has_vat' => array_key_exists( 'api_price_has_vat', $rate_meta ) ? (bool) $rate_meta['api_price_has_vat'] : ( $api['api_price_has_vat'] ?? null ),
			'api_price_with_vat_rub' => $api['api_price_with_vat_rub'] ?? $rate_meta['api_price_with_vat_rub'] ?? $api_result['paynds'] ?? null,
			'pay' => $api['pay'] ?? $rate_meta['pay'] ?? $api_result['pay'] ?? null,
			'nds' => $api['nds'] ?? $rate_meta['nds'] ?? $api_result['nds'] ?? null,
			'paynds' => $api['paynds'] ?? $rate_meta['paynds'] ?? $api_result['paynds'] ?? null,
			'delivery_min_days' => is_numeric( $min_days ) ? (int) $min_days : null,
			'delivery_max_days' => is_numeric( $max_days ) ? (int) $max_days : null,
			'api_delivery_min_days' => is_numeric( $min_days ) ? (int) $min_days : null,
			'api_delivery_max_days' => is_numeric( $max_days ) ? (int) $max_days : null,
			'api_delivery_text' => (string) ( $api['api_delivery_text'] ?? $api['delivery_text'] ?? DeliveryDaysFormatter::format_values( $min_days, $max_days ) ),
			'transtype' => array_key_exists( 'transtype', $rate_meta ) ? (int) $rate_meta['transtype'] : null,
			'delivery_to' => (string) ( $rate_meta['delivery_to'] ?? $api['delivery_to'] ?? '' ),
			'items_summary' => is_array( $rate_meta['items_summary'] ?? null ) ? $rate_meta['items_summary'] : ( is_array( $api['items_summary'] ?? null ) ? $api['items_summary'] : array() ),
			'vat_rate' => is_numeric( $rate_meta['vat_rate'] ?? null ) ? (float) $rate_meta['vat_rate'] : null,
			'request_params' => $api['request_params'] ?? $rate_meta['request_params'] ?? null,
			'cache_hit' => $api['cache_hit'] ?? $rate_meta['cache_hit'] ?? null,
			'http_code' => $api['http_code'] ?? $rate_meta['http_code'] ?? $api_result['http_code'] ?? null,
			'carrier_country_id' => (string) ( $country['carrier_country_id'] ?? '' ),
			'country_name' => (string) ( $country['country_name'] ?? '' ),
		);

		return $this->drop_null_values( $data );
	}

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $rate_meta
	 * @return array<string,mixed>
	 */
	private function calculation_rules_data( array $rate, array $rate_meta, float $api_base, float $final ): array {
		$rules = is_array( $rate_meta['rules'] ?? null ) ? $rate_meta['rules'] : array();
		$source = (string) ( $rules['rules_source'] ?? $rules['source'] ?? $rate['rules_source'] ?? $rate_meta['rules_source'] ?? 'none' );
		$audit = is_array( $rules['applied_rules'] ?? null ) ? array_values( $rules['applied_rules'] ) : ( is_array( $rate_meta['rules_audit'] ?? null ) ? array_values( $rate_meta['rules_audit'] ) : ( is_array( $rate['rules_audit'] ?? null ) ? array_values( $rate['rules_audit'] ) : ( is_array( $rate_meta['applied_rules'] ?? null ) ? array_values( $rate_meta['applied_rules'] ) : array() ) ) );
		$round = ! empty( $rules['round_up_applied'] ) || ! empty( $rate['round_up_applied'] ) || ! empty( $rate_meta['round_up_applied'] );
		$minimum = ! empty( $rules['minimum_price_applied'] ) || ! empty( $rate['minimum_price_applied'] ) || ! empty( $rate_meta['minimum_price_applied'] );
		$formula = is_array( $rules['formula_visualization'] ?? null ) ? $rules['formula_visualization'] : ( is_array( $rate_meta['formula_visualization'] ?? null ) ? $rate_meta['formula_visualization'] : array() );
		if ( array() === $formula && ( array() !== $audit || $round || $minimum ) ) {
			$formula = ( new RuleFormulaFormatter() )->lines(
				$api_base,
				$audit,
				$final,
				array(
					'round_up_applied' => $round && $final > 0,
					'minimum_price_applied' => $minimum && $final > 0,
				)
			);
		}
		return array(
			'rules_source' => $source,
			'applied_rules' => $audit,
			'formula_visualization' => $formula,
			'round_up_applied' => $round,
			'minimum_price_applied' => $minimum,
			'price_delta_rub' => $final - $api_base,
		);
	}

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $rate_meta
	 * @return array<string,mixed>
	 */
	private function calculation_result_data( array $rate, array $rate_meta, float $final ): array {
		$result = is_array( $rate_meta['result'] ?? null ) ? $rate_meta['result'] : array();
		$delivery_days = is_array( $rate['delivery_days'] ?? null ) ? $rate['delivery_days'] : array();
		$min_days = $delivery_days['min_days'] ?? $delivery_days['min'] ?? $rate_meta['delivery_min_days'] ?? null;
		$max_days = $delivery_days['max_days'] ?? $delivery_days['max'] ?? $rate_meta['delivery_max_days'] ?? null;
		return $this->drop_null_values(
			array(
				'final_price_rub' => $final,
				'final_delivery_days_min' => is_numeric( $min_days ) ? (int) $min_days : null,
				'final_delivery_min_days' => is_numeric( $min_days ) ? (int) $min_days : null,
				'final_delivery_days_max' => is_numeric( $max_days ) ? (int) $max_days : null,
				'final_delivery_max_days' => is_numeric( $max_days ) ? (int) $max_days : null,
				'final_delivery_text' => (string) ( $result['final_delivery_text'] ?? DeliveryDaysFormatter::format_values( $min_days, $max_days ) ?: ( $rate['delivery_comment'] ?? '' ) ),
				'round_up_applied' => ! empty( $rate['round_up_applied'] ) || ! empty( $rate_meta['round_up_applied'] ),
				'minimum_price_applied' => ! empty( $rate['minimum_price_applied'] ) || ! empty( $rate_meta['minimum_price_applied'] ),
				'crossed_price_rub' => $result['crossed_price_rub'] ?? $rate['crossed_price'] ?? null,
				'old_price_rub' => $result['old_price_rub'] ?? $rate['old_price'] ?? null,
				'fallback' => ! empty( $result['fallback'] ) || ! empty( $rate['fallback_used'] ),
				'fallback_reason' => (string) ( $result['fallback_reason'] ?? $rate['fallback_reason'] ?? '' ),
				'fallback_text' => (string) ( $result['fallback_text'] ?? $rate['fallback_text'] ?? '' ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function drop_null_values( array $data ): array {
		return array_filter(
			$data,
			static fn( mixed $value ): bool => null !== $value && '' !== $value
		);
	}

	private function note_snapshot( object $order, ?array $rate = null ): array {
		$shipping = $this->shipping_items( $order )[0] ?? array();
		$shipping_cost = 0.0;
		if ( null !== $rate ) {
			$shipping_cost = (float) ( $rate['cost'] ?? 0 );
		} elseif ( is_array( $shipping ) ) {
			$shipping_cost = (float) ( $shipping['total'] ?? 0 );
		} elseif ( is_object( $shipping ) && method_exists( $shipping, 'get_total' ) ) {
			$shipping_cost = (float) $shipping->get_total();
		}

		return array(
			'method' => null !== $rate ? $this->method_title( $rate ) : ( is_array( $shipping ) ? (string) ( $shipping['method_title'] ?? '' ) : ( is_object( $shipping ) && method_exists( $shipping, 'get_method_title' ) ? (string) $shipping->get_method_title() : '' ) ),
			'shipping_cost' => $shipping_cost,
			'api_base' => null !== $rate ? $this->base_price( $rate ) : $this->old_base_price( $order ),
			'total' => property_exists( $order, 'total' ) ? (float) $order->total : ( method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0.0 ),
			'city' => method_exists( $order, 'get_shipping_city' ) ? (string) $order->get_shipping_city() : '',
			'region' => method_exists( $order, 'get_shipping_state' ) ? (string) $order->get_shipping_state() : '',
			'display_name' => $this->order_meta_string( $order, '_wdc_platform_city_display_name' ),
			'fias_id' => $this->order_meta_string( $order, '_wdc_platform_city_fias_id' ),
			'gar_id' => $this->order_meta_string( $order, '_wdc_platform_city_gar_id' ),
		);
	}

	/**
	 * @param array<string,mixed> $old
	 * @param array<string,mixed> $new
	 * @param array<string,mixed> $location
	 */
	private function note( array $old, array $new, bool $location_changed, array $location ): string {
		$before = 'Было: ' . $old['method'] . ' - ' . $this->money( (float) $old['shipping_cost'] ) . ' (' . $this->money( (float) $old['api_base'] ) . '), общая цена ' . $this->money( (float) $old['total'] );
		$after = 'Стало: ' . $new['method'] . ' - ' . $this->money( (float) $new['shipping_cost'] ) . ' (' . $this->money( (float) $new['api_base'] ) . '), общая цена ' . $this->money( (float) $new['total'] );
		if ( ! $location_changed ) {
			return $before . "\n" . $after;
		}

		return $before . ".\n"
			. 'Прежний город: ' . $this->old_location_label( $old ) . "\n"
			. $after . "\n"
			. 'Новый город: ' . $this->location_label( $location );
	}

	/**
	 * @param array<string,mixed> $old
	 * @param array<string,mixed> $location
	 */
	private function location_changed( array $old, array $location ): bool {
		$old_fias = (string) ( $old['fias_id'] ?? '' );
		$new_fias = (string) ( $location['fias_id'] ?? '' );
		if ( '' !== $old_fias && '' !== $new_fias && $this->normalize( $old_fias ) === $this->normalize( $new_fias ) ) {
			return false;
		}
		$old_gar = (string) ( $old['gar_id'] ?? '' );
		$new_gar = (string) ( $location['gar_id'] ?? $location['gar_object_id'] ?? '' );
		if ( '' !== $old_gar && '' !== $new_gar && $this->normalize( $old_gar ) === $this->normalize( $new_gar ) ) {
			return false;
		}
		$old_city = $this->canonical_city( (string) ( $old['city'] ?? $old['display_name'] ?? '' ) );
		$new_city = $this->canonical_city( $this->location_city( $location ) );
		$old_region = $this->canonical_region( (string) ( $old['region'] ?? $old['display_name'] ?? '' ) );
		$new_region = $this->canonical_region( (string) ( $location['region_name'] ?? $location['state_value'] ?? $location['display_name'] ?? '' ) );
		return '' !== $new_city && ( $old_city !== $new_city || ( '' !== $new_region && '' !== $old_region && $old_region !== $new_region ) );
	}

	private function method_title( array $rate ): string {
		$title = (string) ( $rate['label'] ?? '' );
		$tariff = (string) ( $rate['selected_tariff_title'] ?? $rate['tariff_title'] ?? '' );
		$title = '' !== $tariff && ! str_contains( $title, $tariff ) ? $title . ', ' . $tariff : $title;
		$delivery = (string) ( $rate['delivery_comment'] ?? $rate['planned_delivery_comment'] ?? '' );
		return '' !== $delivery && ! str_contains( $title, $delivery ) ? $title . ' - ' . $delivery : $title;
	}

	/**
	 * @param array<string,mixed> $location
	 */
	private function location_label( array $location ): string {
		return (string) ( $location['display_name'] ?? $location['label'] ?? $location['city_value'] ?? $location['city_name'] ?? '' );
	}

	/**
	 * @param array<string,mixed> $location
	 */
	private function location_city( array $location ): string {
		return (string) ( $location['city_value'] ?? $location['city_name'] ?? $location['place_name'] ?? $location['display_name'] ?? '' );
	}

	private function base_price( array $rate ): float {
		$meta = is_array( $rate['rate_meta'] ?? null ) ? $rate['rate_meta'] : array();
		$api = is_array( $meta['api'] ?? null ) ? $meta['api'] : array();
		return (float) ( $rate['api_base_price_rub'] ?? $meta['api_base_price_rub'] ?? $api['api_base_price_rub'] ?? $rate['cost'] ?? 0 );
	}

	private function old_base_price( object $order ): float {
		$calc = method_exists( $order, 'get_meta' ) ? $order->get_meta( OrderShippingMetaPersister::CALCULATION_META_KEY, true ) : ( property_exists( $order, 'meta' ) ? ( $order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ] ?? array() ) : array() );
		return is_array( $calc ) ? (float) ( $calc['api']['api_base_price_rub'] ?? 0 ) : 0.0;
	}

	private function money( float $value ): string {
		return rtrim( rtrim( number_format( $value, 2, '.', ' ' ), '0' ), '.' ) . ' руб.';
	}

	private function normalize( string $value ): string {
		$value = trim( $value );
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );
	}

	private function canonical_city( string $value ): string {
		$value = $this->normalize( $value );
		$value = preg_replace( '/\b(г|город|с|село|д|деревня|п|поселок|посёлок)\.?\s+/u', '', $value ) ?? $value;
		$value = preg_replace( '/[^a-zа-яё0-9]+/u', '', $value ) ?? $value;
		return $value;
	}

	private function canonical_region( string $value ): string {
		$value = $this->normalize( $value );
		$value = preg_replace( '/\b(область|обл|край|республика|респ)\.?\b/u', '', $value ) ?? $value;
		$value = preg_replace( '/[^a-zа-яё0-9]+/u', '', $value ) ?? $value;
		return $value;
	}

	/**
	 * @param array<string,mixed> $old
	 */
	private function old_location_label( array $old ): string {
		$display = trim( (string) ( $old['display_name'] ?? '' ) );
		if ( '' !== $display ) {
			return $display;
		}
		return trim( (string) ( $old['city'] ?? '' ) . ( '' !== (string) ( $old['region'] ?? '' ) ? ', ' . (string) $old['region'] : '' ) );
	}

	private function order_meta_string( object $order, string $key ): string {
		if ( method_exists( $order, 'get_meta' ) ) {
			return trim( (string) $order->get_meta( $key, true ) );
		}
		if ( property_exists( $order, 'meta' ) && is_array( $order->meta ) ) {
			return trim( (string) ( $order->meta[ $key ] ?? '' ) );
		}
		return '';
	}
}
