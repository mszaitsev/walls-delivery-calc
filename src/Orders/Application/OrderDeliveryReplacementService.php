<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Application;

use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Domain\Quote\DeliveryType;
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
		}

		$old = $this->note_snapshot( $order );
		$this->replace_shipping_item( $order, $shipping_items, $rate );
		$this->write_order_meta( $order, $rate, $location, $pickup, $address );
		$this->write_shipping_address( $order, $rate, $location, $address );
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
				foreach ( array( 'Срок доставки', 'Способ доставки', 'Пункт выдачи', 'Адрес ПВЗ', 'Код ПВЗ' ) as $key ) {
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
			$order->shipping_items = array(
				'method_id' => self::METHOD_ID,
				'method_title' => $title,
				'total' => (float) ( $rate['cost'] ?? 0 ),
				'meta' => array( 'Срок доставки' => (string) ( $rate['delivery_comment'] ?? '' ) ),
			);
		}
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
	 * @param array<string,mixed> $address
	 */
	private function write_shipping_address( object $order, array $rate, array $location, array $address ): void {
		$values = array(
			'set_shipping_country' => (string) ( $address['country'] ?? $location['country_code'] ?? 'RU' ),
			'set_shipping_state' => (string) ( $address['region'] ?? $location['region_name'] ?? $location['state_value'] ?? '' ),
			'set_shipping_city' => (string) ( $address['city'] ?? $location['city_value'] ?? $location['city_name'] ?? $location['display_name'] ?? '' ),
			'set_shipping_postcode' => (string) ( $address['postcode'] ?? $location['postal_code'] ?? $location['postcode'] ?? '' ),
		);
		if ( DeliveryType::COURIER === (string) ( $rate['delivery_type'] ?? '' ) && array() !== $address ) {
			$values['set_shipping_address_1'] = (string) ( $address['address_1'] ?? '' );
			$values['set_shipping_address_2'] = (string) ( $address['address_2'] ?? '' );
		}
		foreach ( $values as $method => $value ) {
			if ( '' !== $value && method_exists( $order, $method ) ) {
				$order->{$method}( $value );
			}
		}
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
			'package' => is_array( $rate_meta['package'] ?? null ) ? $rate_meta['package'] : array(),
			'api' => array( 'api_base_price_rub' => $api_base ),
			'rules' => array(
				'source' => (string) ( $rate['rules_source'] ?? 'none' ),
				'round_up_applied' => ! empty( $rate['round_up_applied'] ),
				'minimum_price_applied' => ! empty( $rate['minimum_price_applied'] ),
				'price_delta_rub' => $final - $api_base,
			),
			'result' => array(
				'final_price_rub' => $final,
				'final_delivery_text' => (string) ( $rate['delivery_comment'] ?? '' ),
			),
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
			. 'Прежний город: ' . trim( (string) $old['city'] . ( '' !== (string) $old['region'] ? ', ' . (string) $old['region'] : '' ) ) . "\n"
			. $after . "\n"
			. 'Новый город: ' . trim( $this->location_city( $location ) . ( '' !== (string) ( $location['region_name'] ?? '' ) ? ', ' . (string) $location['region_name'] : '' ) );
	}

	/**
	 * @param array<string,mixed> $old
	 * @param array<string,mixed> $location
	 */
	private function location_changed( array $old, array $location ): bool {
		$new = $this->location_city( $location );
		return '' !== $new && $this->normalize( (string) $old['city'] ) !== $this->normalize( $new );
	}

	private function method_title( array $rate ): string {
		$title = (string) ( $rate['label'] ?? '' );
		$tariff = (string) ( $rate['selected_tariff_title'] ?? $rate['tariff_title'] ?? '' );
		return '' !== $tariff && ! str_contains( $title, $tariff ) ? $title . ', ' . $tariff : $title;
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
		return (float) ( $rate['api_base_price_rub'] ?? $meta['api_base_price_rub'] ?? $rate['cost'] ?? 0 );
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
}
