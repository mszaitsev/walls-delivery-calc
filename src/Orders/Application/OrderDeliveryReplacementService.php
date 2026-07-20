<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Application;

use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Calendar\Services\DeliveryDateFormatter;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Locations\Services\LocationDisplayNameFormatter;
use WallsShop\WDC\Locations\ValueObjects\Location;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class OrderDeliveryReplacementService {
	private const METHOD_ID = 'wdc_platform_delivery';

	public function __construct(
		private OrderShipmentRepository $shipments,
		private DeliveryDateFormatter $date_formatter,
		private DeliveryCalculationDataBuilder $calculation_data_builder
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
			$pickup = $this->canonical_pickup_for_save( $order, $rate, $pickup );
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
			if ( is_array( $tariff['delivery_days'] ?? null ) ) {
				$rate['delivery_days'] = $tariff['delivery_days'];
			}
			if ( array_key_exists( 'delivery_days_label', $tariff ) ) {
				$rate['delivery_days_label'] = (string) $tariff['delivery_days_label'];
			}
			if ( is_array( $tariff['rate_meta'] ?? null ) ) {
				$rate['rate_meta'] = $tariff['rate_meta'];
			}
			foreach ( array( 'api_base_price_rub', 'crossed_price', 'planned_delivery_date', 'planned_delivery_comment', 'rules_source', 'round_up_applied', 'minimum_price_applied' ) as $key ) {
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
			if ( method_exists( $item, 'add_meta_data' ) ) {
				$planned = $this->planned_delivery_order_meta_value( $rate );
				if ( '' !== $planned ) {
					$item->add_meta_data( 'Планируемая* дата доставки', $planned, true );
				}
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
				'meta' => '' !== $this->planned_delivery_order_meta_value( $rate ) ? array( 'Планируемая* дата доставки' => $this->planned_delivery_order_meta_value( $rate ) ) : array(),
			);
		}
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function delivery_label_or_not_specified( array $rate ): string {
		$delivery = trim( (string) ( $rate['delivery_comment'] ?? $rate['planned_delivery_comment'] ?? '' ) );

		return '' !== $delivery ? $delivery : 'не указан';
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function planned_delivery_order_meta_value( array $rate ): string {
		$date = trim( (string) ( $rate['planned_delivery_date'] ?? '' ) );
		if ( '' === $date ) {
			return '';
		}

		return $this->date_formatter instanceof DeliveryDateFormatter ? $this->date_formatter->format_order_meta_value( $date ) : '';
	}

	/**
	 * @return array<int,string>
	 */
	private function visible_shipping_item_meta_keys(): array {
		return array(
			'carrier_key',
			'rate_id',
			'delivery_type',
			'planned_delivery_date',
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
			'Планируемая* дата доставки',
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
			'_wdc_platform_planned_delivery_date' => (string) ( $rate['planned_delivery_date'] ?? '' ),
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
			'_wdc_platform_location_id' => isset( $location['id'] ) && is_numeric( $location['id'] ) ? (int) $location['id'] : '',
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
			$map['_wdc_platform_pickup_comment'] = $this->first_meaningful( $pickup['description'] ?? '', $pickup['point_comment'] ?? '' );
			$map['_wdc_platform_pickup_work_time'] = $this->first_meaningful( $pickup['work_time'] ?? '', $pickup['point_work_time'] ?? '' );
			$map['_wdc_pickup_point_code'] = (string) ( $pickup['point_code'] ?? '' );
			$map['_wdc_pickup_platform_station_id'] = $this->first_meaningful( $pickup['platform_station_id'] ?? '', $pickup['snapshot']['platform_station_id'] ?? '' );
			$map['_wdc_pickup_point_type'] = (string) ( $pickup['point_type'] ?? '' );
			$map['_wdc_pickup_carrier_key'] = (string) ( $pickup['carrier_key'] ?? $rate['carrier_key'] ?? '' );
			$map['_wdc_pickup_service_key'] = (string) ( $pickup['service_key'] ?? $rate['service_key'] ?? $rate['carrier_key'] ?? '' );
			$map['_wdc_pickup_family'] = (string) ( $pickup['pickup_family'] ?? ( (string) ( $rate['carrier_key'] ?? '' ) !== '' ? (string) $rate['carrier_key'] . ':pickup' : '' ) );
			$map['_wdc_pickup_point_type_label'] = (string) ( $pickup['point_type_label'] ?? '' );
			$map['_wdc_pickup_point_title'] = (string) ( $pickup['point_title'] ?? '' );
			$map['_wdc_pickup_marker_type'] = (string) ( $pickup['marker_type'] ?? '' );
			$map['_wdc_pickup_point_address'] = (string) ( $pickup['point_address'] ?? $pickup['address'] ?? '' );
			$map['_wdc_pickup_point_postcode'] = (string) ( $pickup['point_postcode'] ?? $pickup['postcode'] ?? '' );
			$map['_wdc_pickup_point_snapshot'] = function_exists( 'wp_json_encode' ) ? wp_json_encode( $pickup, JSON_UNESCAPED_UNICODE ) : json_encode( $pickup );
			if ( DpdSettings::CARRIER_KEY === (string) ( $rate['carrier_key'] ?? $pickup['carrier_key'] ?? '' ) ) {
				$snapshot = is_array( $pickup['snapshot'] ?? null ) ? $pickup['snapshot'] : array();
				$map['_wdc_dpd_pickup_terminal_code'] = $this->first_meaningful( $pickup['terminal_code'] ?? '', $pickup['point_code'] ?? '', $snapshot['terminal_code'] ?? '', $snapshot['point_code'] ?? '' );
				$map['_wdc_dpd_pickup_type'] = $this->first_meaningful( $pickup['point_type'] ?? '', $snapshot['point_type'] ?? '' );
				$map['_wdc_dpd_pickup_name'] = $this->first_meaningful( $pickup['point_name'] ?? '', $snapshot['point_name'] ?? '' );
				$map['_wdc_dpd_pickup_address'] = (string) ( $pickup['point_address'] ?? $pickup['address'] ?? '' );
				$map['_wdc_dpd_pickup_city_name'] = $this->first_meaningful( $pickup['city_name'] ?? '', $pickup['city'] ?? '', $snapshot['city_name'] ?? '', $snapshot['city'] ?? '' );
				$map['_wdc_dpd_pickup_latitude'] = $this->first_meaningful( $pickup['lat'] ?? '', $pickup['latitude'] ?? '', $snapshot['lat'] ?? '' );
				$map['_wdc_dpd_pickup_longitude'] = $this->first_meaningful( $pickup['lng'] ?? '', $pickup['longitude'] ?? '', $snapshot['lng'] ?? '' );
				$map['_wdc_dpd_pickup_source'] = $this->first_meaningful( $pickup['dpd_source'] ?? '', $pickup['source'] ?? '', $snapshot['dpd_source'] ?? '', $snapshot['source'] ?? '', 'recalculation/admin' );
			}
			if ( YandexDeliverySettings::CARRIER_KEY === (string) ( $rate['carrier_key'] ?? $pickup['carrier_key'] ?? '' ) ) {
				$snapshot = is_array( $pickup['snapshot'] ?? null ) ? $pickup['snapshot'] : array();
				$map['_wdc_yandex_delivery_pickup_platform_station_id'] = $this->first_meaningful( $pickup['platform_station_id'] ?? '', $pickup['point_code'] ?? '', $snapshot['platform_station_id'] ?? '', $snapshot['point_code'] ?? '' );
				$map['_wdc_yandex_delivery_pickup_point_code'] = $this->first_meaningful( $pickup['point_code'] ?? '', $snapshot['point_code'] ?? '' );
				$map['_wdc_yandex_delivery_pickup_type'] = $this->first_meaningful( $pickup['point_type'] ?? '', $snapshot['point_type'] ?? '' );
				$map['_wdc_yandex_delivery_pickup_name'] = $this->first_meaningful( $pickup['point_name'] ?? '', $snapshot['point_name'] ?? '' );
				$map['_wdc_yandex_delivery_pickup_address'] = (string) ( $pickup['point_address'] ?? $pickup['address'] ?? '' );
				$map['_wdc_yandex_delivery_pickup_city_name'] = $this->first_meaningful( $pickup['city_name'] ?? '', $pickup['city'] ?? '', $snapshot['city_name'] ?? '', $snapshot['city'] ?? '' );
				$map['_wdc_yandex_delivery_pickup_region_name'] = $this->first_meaningful( $pickup['region_name'] ?? '', $pickup['region'] ?? '', $snapshot['region_name'] ?? '', $snapshot['region'] ?? '' );
				$map['_wdc_yandex_delivery_pickup_latitude'] = $this->first_meaningful( $pickup['lat'] ?? '', $snapshot['lat'] ?? '' );
				$map['_wdc_yandex_delivery_pickup_longitude'] = $this->first_meaningful( $pickup['lng'] ?? '', $snapshot['lng'] ?? '' );
			}
		} else {
			$map['_wdc_platform_pickup_code'] = '';
			$map['_wdc_platform_pickup_address'] = '';
			$map['_wdc_platform_pickup_comment'] = '';
			$map['_wdc_platform_pickup_work_time'] = '';
			$map['_wdc_pickup_point_code'] = '';
			$map['_wdc_pickup_platform_station_id'] = '';
			$map['_wdc_pickup_point_type'] = '';
			$map['_wdc_pickup_carrier_key'] = '';
			$map['_wdc_pickup_service_key'] = '';
			$map['_wdc_pickup_family'] = '';
			$map['_wdc_pickup_point_type_label'] = '';
			$map['_wdc_pickup_point_title'] = '';
			$map['_wdc_pickup_marker_type'] = '';
			$map['_wdc_pickup_point_address'] = '';
			$map['_wdc_pickup_point_postcode'] = '';
			$map['_wdc_pickup_point_snapshot'] = '';
			$map['_wdc_dpd_pickup_terminal_code'] = '';
			$map['_wdc_dpd_pickup_type'] = '';
			$map['_wdc_dpd_pickup_name'] = '';
			$map['_wdc_dpd_pickup_address'] = '';
			$map['_wdc_dpd_pickup_city_name'] = '';
			$map['_wdc_dpd_pickup_latitude'] = '';
			$map['_wdc_dpd_pickup_longitude'] = '';
			$map['_wdc_dpd_pickup_source'] = '';
			$map['_wdc_yandex_delivery_pickup_platform_station_id'] = '';
			$map['_wdc_yandex_delivery_pickup_point_code'] = '';
			$map['_wdc_yandex_delivery_pickup_type'] = '';
			$map['_wdc_yandex_delivery_pickup_name'] = '';
			$map['_wdc_yandex_delivery_pickup_address'] = '';
			$map['_wdc_yandex_delivery_pickup_city_name'] = '';
			$map['_wdc_yandex_delivery_pickup_region_name'] = '';
			$map['_wdc_yandex_delivery_pickup_latitude'] = '';
			$map['_wdc_yandex_delivery_pickup_longitude'] = '';
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
			$values['set_shipping_country'] = 'RU';
			$values['set_shipping_state'] = (string) ( $pickup['region_name'] ?? $pickup['region'] ?? $location_values['state'] );
			$values['set_shipping_city'] = (string) ( $pickup['city_name'] ?? $pickup['city'] ?? $location_values['city'] );
			$values['set_shipping_postcode'] = (string) ( $pickup['point_postcode'] ?? $pickup['postcode'] ?? $location_values['postcode'] );
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
		return $this->calculation_data_builder->build(
			$rate,
			array(
				'destination' => array(
					'country_code' => (string) ( $address['country'] ?? $location['country_code'] ?? 'RU' ),
					'city_display_name' => $this->location_label( $location ),
					'region_name' => (string) ( $address['region'] ?? $location['region_name'] ?? '' ),
					'postcode' => (string) ( $address['postcode'] ?? $location['postal_code'] ?? $location['postcode'] ?? '' ),
					'fias_id' => (string) ( $location['fias_id'] ?? $address['fias_id'] ?? '' ),
				),
				'pickup' => DeliveryType::PICKUP === (string) ( $rate['delivery_type'] ?? '' ) ? array(
					'carrier_key' => (string) ( $pickup['carrier_key'] ?? $rate['carrier_key'] ?? '' ),
					'service_key' => (string) ( $pickup['service_key'] ?? $rate['service_key'] ?? $rate['carrier_key'] ?? '' ),
					'pickup_family' => (string) ( $pickup['pickup_family'] ?? ( (string) ( $rate['carrier_key'] ?? '' ) !== '' ? (string) $rate['carrier_key'] . ':pickup' : '' ) ),
					'point_code' => (string) ( $pickup['point_code'] ?? '' ),
					'delivery_point' => (string) ( $pickup['delivery_point'] ?? $pickup['point_code'] ?? '' ),
					'point_type' => (string) ( $pickup['point_type'] ?? '' ),
					'point_type_label' => (string) ( $pickup['point_type_label'] ?? '' ),
					'point_title' => (string) ( $pickup['point_title'] ?? '' ),
					'marker_type' => (string) ( $pickup['marker_type'] ?? '' ),
					'point_name' => (string) ( $pickup['point_name'] ?? '' ),
					'point_address' => (string) ( $pickup['point_address'] ?? $pickup['address'] ?? '' ),
					'point_postcode' => (string) ( $pickup['point_postcode'] ?? $pickup['postcode'] ?? '' ),
					'city_name' => (string) ( $pickup['city_name'] ?? $pickup['city'] ?? '' ),
					'region_name' => (string) ( $pickup['region_name'] ?? $pickup['region'] ?? '' ),
					'latitude' => $pickup['lat'] ?? $pickup['latitude'] ?? null,
					'longitude' => $pickup['lng'] ?? $pickup['longitude'] ?? null,
					'work_time' => $this->first_meaningful( $pickup['work_time'] ?? '', $pickup['point_work_time'] ?? '' ),
					'description' => $this->first_meaningful( $pickup['description'] ?? '', $pickup['point_comment'] ?? '' ),
					'storage_notice' => $this->first_meaningful( $pickup['storage_notice'] ?? '' ),
					'cdek_code' => (string) ( $pickup['cdek_code'] ?? $pickup['point_code'] ?? '' ),
					'terminal_code' => $this->first_meaningful( $pickup['terminal_code'] ?? '', $pickup['point_code'] ?? '' ),
					'dpd_source' => $this->first_meaningful( $pickup['dpd_source'] ?? '', $pickup['source'] ?? '' ),
					'raw_sanitized' => is_array( $pickup['raw_sanitized'] ?? null ) ? $pickup['raw_sanitized'] : ( is_array( $pickup['raw'] ?? null ) ? $pickup['raw'] : array() ),
				) : array(),
			)
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
		$delivery = $this->delivery_label_from_value( $rate['delivery_days'] ?? null ) ?: trim( (string) ( $rate['delivery_comment'] ?? $rate['planned_delivery_comment'] ?? '' ) );
		$original = $this->delivery_label_from_value( $rate['original_delivery_days'] ?? ( $rate['rate_meta']['original_delivery_days'] ?? null ) );
		if ( '' === $delivery ) {
			return $title;
		}
		if ( str_ends_with( $title, $delivery ) ) {
			return $title;
		}
		if ( '' !== $original && str_ends_with( $title, $original ) ) {
			$title = rtrim( substr( $title, 0, -strlen( $original ) ) );
			$title = rtrim( $title, " \t\n\r\0\x0B-" );
		}
		return '' !== $title ? $title . ' - ' . $delivery : $delivery;
	}

	private function delivery_label_from_value( mixed $value ): string {
		if ( is_array( $value ) ) { return DeliveryDaysFormatter::format_array( $value ); }
		if ( is_numeric( $value ) ) { return DeliveryDaysFormatter::format_values( (int) $value, (int) $value ); }
		return is_string( $value ) ? trim( $value ) : '';
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
		foreach ( array(
			$this->nullable_float( $rate['api_base_price_rub'] ?? null ),
			$this->nullable_float( $meta['api_base_price_rub'] ?? null ),
			$this->kopecks_value( $meta['pricing_total_kopecks'] ?? null ),
			$this->kopecks_value( $rate['pricing_total_kopecks'] ?? null ),
			$this->money_value( $rate['original_cost'] ?? null ),
			$this->money_value( $meta['original_cost'] ?? null ),
			$this->nullable_float( $api['api_base_price_rub'] ?? null ),
			$this->nullable_float( $meta['api_price_with_vat_rub'] ?? null ),
			$this->nullable_float( $api['api_price_with_vat_rub'] ?? null ),
			$this->nullable_float( $rate['cost'] ?? null ),
		) as $value ) {
			if ( null !== $value ) {
				return $value;
			}
		}
		return 0.0;
	}

	private function kopecks_value( mixed $value ): ?float {
		return is_numeric( $value ) ? (float) $value / 100 : null;
	}

	private function money_value( mixed $value ): ?float {
		if ( is_array( $value ) ) {
			if ( is_numeric( $value['amount_kopecks'] ?? null ) ) {
				return (float) $value['amount_kopecks'] / 100;
			}
			if ( is_numeric( $value['amount'] ?? null ) ) {
				return (float) $value['amount'] / 100;
			}
			if ( is_numeric( $value['rubles'] ?? null ) ) {
				return (float) $value['rubles'];
			}
			return null;
		}

		return is_numeric( $value ) ? (float) $value : null;
	}

	private function nullable_float( mixed $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
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

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $pickup
	 * @return array<string,mixed>
	 */
	private function canonical_pickup_for_save( object $order, array $rate, array $pickup ): array {
		$carrier = (string) ( $rate['carrier_key'] ?? $pickup['carrier_key'] ?? '' );
		if ( YandexDeliverySettings::CARRIER_KEY === $carrier ) {
			$snapshot = is_array( $pickup['snapshot'] ?? null ) ? $pickup['snapshot'] : array();
			$station_id = $this->first_meaningful(
				$pickup['platform_station_id'] ?? '',
				$snapshot['platform_station_id'] ?? '',
				$pickup['point_code'] ?? '',
				$snapshot['point_code'] ?? ''
			);
			$pickup_carrier = (string) ( $pickup['carrier_key'] ?? $pickup['carrier'] ?? $snapshot['carrier_key'] ?? '' );
			$pickup_family = (string) ( $pickup['pickup_family'] ?? $snapshot['pickup_family'] ?? '' );
			if (
				YandexDeliverySettings::CARRIER_KEY !== $pickup_carrier
				|| YandexDeliverySettings::CARRIER_KEY . ':pickup' !== $pickup_family
				|| '' === $station_id
			) {
				return array();
			}

			$pickup['carrier_key'] = YandexDeliverySettings::CARRIER_KEY;
			$pickup['service_key'] = YandexDeliverySettings::SERVICE_KEY;
			$pickup['pickup_family'] = YandexDeliverySettings::CARRIER_KEY . ':pickup';
			$pickup['platform_station_id'] = $station_id;
			$pickup['point_code'] = $station_id;

			return $pickup;
		}
		if ( 'cdek' !== $carrier ) {
			if ( DpdSettings::CARRIER_KEY === $carrier ) {
				$code = $this->first_meaningful( $pickup['terminal_code'] ?? '', $pickup['point_code'] ?? '' );
				$pickup['carrier_key'] = DpdSettings::CARRIER_KEY;
				$pickup['service_key'] = DpdSettings::SERVICE_KEY;
				$pickup['pickup_family'] = DpdSettings::CARRIER_KEY . ':pickup';
				$pickup['terminal_code'] = $code;
				$pickup['point_code'] = $code;
				if ( '' === (string) ( $pickup['point_title'] ?? '' ) ) {
					$pickup['point_title'] = 'Пункт выдачи DPD';
				}
				if ( '' === (string) ( $pickup['marker_type'] ?? '' ) ) {
					$pickup['marker_type'] = 'pickup';
				}
				return $pickup;
			}
			if ( '' === trim( (string) ( $pickup['point_code'] ?? '' ) ) ) {
				$postcode = trim( (string) ( $pickup['point_postcode'] ?? $pickup['postcode'] ?? '' ) );
				if ( '' !== $postcode ) {
					$pickup['point_code'] = $postcode;
				}
			}
			return $pickup;
		}

		$existing = $this->existing_cdek_pickup_payload( $order );
		$new_code = $this->cdek_pickup_code_from_row( $pickup );
		$old_code = $this->cdek_pickup_code_from_row( $existing );
		$code = '' !== $new_code ? $new_code : $old_code;
		$source = '' !== $new_code ? $pickup : $existing;
		$merged = array_replace( $existing, $pickup );
		$merged['carrier_key'] = 'cdek';
		$merged['service_key'] = 'cdek';
		$merged['pickup_family'] = 'cdek:pickup';
		$merged['point_code'] = $code;
		$merged['cdek_code'] = $code;
		$merged['delivery_point'] = $code;
		$merged['point_address'] = $this->first_meaningful( $pickup['point_address'] ?? '', $pickup['address'] ?? '', $existing['point_address'] ?? '', $existing['address'] ?? '' );
		$merged['address'] = $this->first_meaningful( $pickup['address'] ?? '', $pickup['point_address'] ?? '', $existing['address'] ?? '', $existing['point_address'] ?? '' );
		$merged['point_postcode'] = $this->first_meaningful( $source['point_postcode'] ?? '', $source['postcode'] ?? '', $existing['point_postcode'] ?? '', $existing['postcode'] ?? '' );
		$merged['postcode'] = $this->first_meaningful( $source['postcode'] ?? '', $source['point_postcode'] ?? '', $existing['postcode'] ?? '', $existing['point_postcode'] ?? '' );

		return $merged;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function existing_cdek_pickup_payload( object $order ): array {
		$calculation = $this->order_meta_value( $order, OrderShippingMetaPersister::CALCULATION_META_KEY );
		$pickup = is_array( $calculation ) && is_array( $calculation['pickup'] ?? null ) ? $calculation['pickup'] : array();
		$snapshot = $this->json_order_meta_array( $order, '_wdc_pickup_point_snapshot' );
		foreach ( array( '_wdc_platform_pickup_point', '_wdc_platform_selected_pickup_point', '_wdc_platform_pickup_selection' ) as $key ) {
			$value = $this->order_meta_value( $order, $key );
			if ( is_array( $value ) ) {
				$snapshot = array_replace( $snapshot, $value );
			}
		}
		$selections = $this->order_meta_value( $order, '_wdc_platform_pickup_selections' );
		if ( is_array( $selections ) && is_array( $selections['cdek:pickup'] ?? null ) ) {
			$snapshot = array_replace( $snapshot, $selections['cdek:pickup'] );
		}

		return array_replace(
			$pickup,
			$snapshot,
			array(
				'carrier_key' => 'cdek',
				'service_key' => 'cdek',
				'pickup_family' => 'cdek:pickup',
				'point_code' => $this->first_meaningful(
					$pickup['delivery_point'] ?? '',
					$pickup['point_code'] ?? '',
					$pickup['cdek_code'] ?? '',
					$snapshot['delivery_point'] ?? '',
					$snapshot['point_code'] ?? '',
					$snapshot['cdek_code'] ?? '',
					$this->order_meta_string( $order, '_wdc_platform_pickup_code' ),
					$this->order_meta_string( $order, '_wdc_pickup_point_code' )
				),
				'point_address' => $this->first_meaningful( $pickup['point_address'] ?? '', $pickup['address'] ?? '', $snapshot['point_address'] ?? '', $snapshot['address'] ?? '', $this->order_meta_string( $order, '_wdc_platform_pickup_address' ), $this->order_meta_string( $order, '_wdc_pickup_point_address' ) ),
				'point_postcode' => $this->first_meaningful( $pickup['point_postcode'] ?? '', $pickup['postcode'] ?? '', $snapshot['point_postcode'] ?? '', $snapshot['postcode'] ?? '', $this->order_meta_string( $order, '_wdc_pickup_point_postcode' ) ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function cdek_pickup_code_from_row( array $row ): string {
		$code = $this->first_meaningful( $row['delivery_point'] ?? '', $row['point_code'] ?? '', $row['cdek_code'] ?? '' );
		$postcode = preg_replace( '/\D+/', '', (string) ( $row['point_postcode'] ?? $row['postcode'] ?? $row['postal_code'] ?? '' ) ) ?: '';
		if ( '' === $code ) {
			return '';
		}
		$digits = preg_replace( '/\D+/', '', $code ) ?: '';
		if ( '' !== $postcode && $digits === $postcode ) {
			return '';
		}
		if ( preg_match( '/^\d{6}$/', $code ) ) {
			return '';
		}

		return strtoupper( preg_replace( '/[^A-Z0-9_\-]/', '', strtoupper( $code ) ) ?? '' );
	}

	private function order_meta_value( object $order, string $key ): mixed {
		if ( method_exists( $order, 'get_meta' ) ) {
			return $order->get_meta( $key, true );
		}
		if ( property_exists( $order, 'meta' ) && is_array( $order->meta ) ) {
			return $order->meta[ $key ] ?? null;
		}
		return null;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function json_order_meta_array( object $order, string $key ): array {
		$value = $this->order_meta_value( $order, $key );
		if ( is_array( $value ) ) {
			return $value;
		}
		if ( ! is_string( $value ) || '' === trim( $value ) ) {
			return array();
		}
		$decoded = json_decode( $value, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	private function meaningful_text( mixed $value ): string {
		if ( null === $value || is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		$text = trim( (string) $value );
		if ( '' === $text ) {
			return '';
		}
		$normalized = str_replace( ',', '.', $text );
		if ( is_numeric( $normalized ) && 0.0 === (float) $normalized ) {
			return '';
		}

		return $text;
	}

	private function first_meaningful( mixed ...$values ): string {
		foreach ( $values as $value ) {
			$text = $this->meaningful_text( $value );
			if ( '' !== $text ) {
				return $text;
			}
		}

		return '';
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
