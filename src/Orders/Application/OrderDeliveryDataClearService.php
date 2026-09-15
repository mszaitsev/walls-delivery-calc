<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Application;

use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Shipments\Application\OrderStructuredAddress;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class OrderDeliveryDataClearService {
	/** @var list<string> */
	public const ORDER_META_KEYS = array(
		OrderShippingMetaPersister::CALCULATION_META_KEY,
		OrderStructuredAddress::META_KEY,
		'_wdc_platform_carrier_key',
		'_wdc_platform_rate_id',
		'_wdc_platform_delivery_type',
		'_wdc_platform_crossed_price',
		'_wdc_platform_planned_delivery_date',
		'_wdc_platform_planned_delivery_comment',
		'_wdc_platform_comments',
		'_wdc_platform_customer_comments',
		'_wdc_platform_fallback_used',
		'_wdc_platform_requires_pickup_point',
		'_wdc_platform_service_key',
		'_wdc_platform_service_title',
		'_wdc_platform_tariff_object',
		'_wdc_platform_tariff_title',
		'_wdc_platform_rules_source',
		'_wdc_platform_round_up_applied',
		'_wdc_platform_minimum_price_applied',
		'_wdc_platform_rate_meta',
		'_wdc_platform_normalized',
		'_wdc_platform_normalization_source',
		'_wdc_platform_fallback_city',
		'_wdc_platform_fallback_address',
		'_wdc_platform_address_fallback_used',
		'_wdc_platform_resolved_postcode',
		'_wdc_platform_fias_id',
		'_wdc_platform_gar_id',
		'_wdc_platform_city_source',
		'_wdc_platform_city_display_name',
		'_wdc_platform_city_postcode',
		'_wdc_platform_city_fias_id',
		'_wdc_platform_city_gar_id',
		'_wdc_platform_city_location_id',
		'_wdc_platform_location_id',
		'_wdc_platform_location_display_name',
		'_wdc_platform_location_fias_id',
		'_wdc_platform_lat',
		'_wdc_platform_lng',
		'_wdc_platform_latitude',
		'_wdc_platform_longitude',
		'_wdc_platform_location_lat',
		'_wdc_platform_location_lng',
		'_wdc_platform_location_latitude',
		'_wdc_platform_location_longitude',
		'_wdc_location_id',
		'_wdc_location_fias_id',
		'_wdc_location_gar_id',
		'_wdc_location_lat',
		'_wdc_location_lng',
		'_wdc_platform_pickup_code',
		'_wdc_platform_pickup_address',
		'_wdc_platform_pickup_comment',
		'_wdc_platform_pickup_work_time',
		'_wdc_platform_pickup_point',
		'_wdc_platform_selected_pickup_point',
		'_wdc_platform_pickup_selection',
		'_wdc_platform_pickup_selections',
		'_wdc_pickup_point_id',
		'_wdc_pickup_point_code',
		'_wdc_pickup_platform_station_id',
		'_wdc_pickup_point_type',
		'_wdc_pickup_carrier_key',
		'_wdc_pickup_service_key',
		'_wdc_pickup_family',
		'_wdc_pickup_point_type_label',
		'_wdc_pickup_point_title',
		'_wdc_pickup_marker_type',
		'_wdc_pickup_point_address',
		'_wdc_pickup_point_postcode',
		'_wdc_pickup_point_snapshot',
		'_wdc_dpd_pickup_terminal_code',
		'_wdc_dpd_pickup_type',
		'_wdc_dpd_pickup_name',
		'_wdc_dpd_pickup_address',
		'_wdc_dpd_pickup_city_name',
		'_wdc_dpd_pickup_latitude',
		'_wdc_dpd_pickup_longitude',
		'_wdc_dpd_pickup_source',
		'_wdc_yandex_delivery_pickup_platform_station_id',
		'_wdc_yandex_delivery_pickup_point_code',
		'_wdc_yandex_delivery_pickup_type',
		'_wdc_yandex_delivery_pickup_name',
		'_wdc_yandex_delivery_pickup_address',
		'_wdc_yandex_delivery_pickup_city_name',
		'_wdc_yandex_delivery_pickup_region_name',
		'_wdc_yandex_delivery_pickup_latitude',
		'_wdc_yandex_delivery_pickup_longitude',
	);

	public function __construct( private OrderShipmentRepository $shipments ) {
	}

	/** @return array{success:bool,message:string,deleted_keys?:list<string>} */
	public function clear( object $order ): array {
		if ( array() !== $this->shipments->all_for_order( $order ) ) {
			return array( 'success' => false, 'message' => 'Нельзя очистить данные доставки: для заказа уже существует отправление.' );
		}
		if ( ! method_exists( $order, 'delete_meta_data' ) || ! method_exists( $order, 'save' ) ) {
			return array( 'success' => false, 'message' => 'Не удалось очистить данные доставки заказа.' );
		}

		foreach ( self::ORDER_META_KEYS as $key ) {
			$order->delete_meta_data( $key );
		}
		$order->save();
		if ( function_exists( 'do_action' ) ) {
			do_action( 'wdc_delivery_calculation_changed', $order );
		}

		return array(
			'success' => true,
			'message' => 'Сохранённые данные расчёта доставки удалены.',
			'deleted_keys' => self::ORDER_META_KEYS,
		);
	}
}
