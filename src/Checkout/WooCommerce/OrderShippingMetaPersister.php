<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Calendar\Services\DeliveryDateFormatter;
use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder;

defined( 'ABSPATH' ) || exit;

final class OrderShippingMetaPersister {
	public const CALCULATION_META_KEY = '_wdc_delivery_calculation_data';

	public function __construct(
		private CheckoutSessionManager $session_manager,
		private DeliveryDateFormatter $date_formatter,
		private DeliveryCalculationDataBuilder $calculation_data_builder
	) {
	}

	public function register(): void {
		add_action( 'woocommerce_checkout_create_order', array( $this, 'persist' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_shipping_item', array( $this, 'persist_shipping_item_meta' ), 20, 4 );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function persist( mixed $order, array $data = array() ): void {
		if ( ! is_object( $order ) || ! method_exists( $order, 'update_meta_data' ) ) {
			return;
		}

		$dadata_meta = $this->dadata_meta_from_checkout_data( $data );
		$location_meta = $this->location_meta_from_checkout_data( $data );

		$rate = $this->selected_rate();
		if ( array() === $rate ) {
			foreach ( array_merge( $dadata_meta, $location_meta ) as $key => $value ) {
				$order->update_meta_data( $key, $value );
			}
			return;
		}

		$map = array(
			'_wdc_platform_carrier_key'              => $rate['carrier_key'] ?? '',
			'_wdc_platform_rate_id'                  => $rate['rate_id'] ?? '',
			'_wdc_platform_delivery_type'            => $rate['delivery_type'] ?? '',
			'_wdc_platform_crossed_price'            => $rate['crossed_price'] ?? null,
			'_wdc_platform_planned_delivery_date'    => $rate['planned_delivery_date'] ?? '',
			'_wdc_platform_planned_delivery_comment' => $rate['planned_delivery_comment'] ?? '',
			'_wdc_platform_comments'                 => $rate['comments'] ?? array(),
			'_wdc_platform_fallback_used'            => ! empty( $rate['fallback_used'] ) || 'fallback' === (string) ( $rate['carrier_key'] ?? '' ),
			'_wdc_platform_requires_pickup_point'    => ! empty( $rate['requires_pickup_point'] ) ? 1 : 0,
			'_wdc_platform_service_key'              => $rate['service_key'] ?? '',
			'_wdc_platform_service_title'            => $rate['service_title'] ?? '',
			'_wdc_platform_tariff_object'            => $rate['selected_tariff_object'] ?? $rate['tariff_key'] ?? '',
			'_wdc_platform_tariff_title'             => $rate['selected_tariff_title'] ?? $rate['tariff_title'] ?? '',
			'_wdc_platform_rules_source'             => $rate['rules_source'] ?? 'none',
			'_wdc_platform_round_up_applied'         => ! empty( $rate['round_up_applied'] ) ? 1 : 0,
			'_wdc_platform_minimum_price_applied'    => ! empty( $rate['minimum_price_applied'] ) ? 1 : 0,
			'_wdc_platform_rate_meta'                => $this->sanitized_rate_meta( is_array( $rate['rate_meta'] ?? null ) ? $rate['rate_meta'] : array() ),
		);

		$address = $this->session_manager->normalized_address_result();
		if ( null !== $address ) {
			$city_context = $this->session_manager->city_context();
			$city_source = (string) ( $city_context['source'] ?? 'manual' );
			$address_fallback_used = 'manual' === $city_source && ( $address->address->fallback || ! $address->address->normalized );
			$map['_wdc_platform_normalized']           = $address->address->normalized;
			$map['_wdc_platform_normalization_source'] = $address->source;
			$map['_wdc_platform_fallback_city']        = $this->session_manager->fallback_city();
			$map['_wdc_platform_fallback_address']     = $address_fallback_used ? $address->address->raw_address : '';
			$map['_wdc_platform_address_fallback_used'] = $address_fallback_used;
			$map['_wdc_platform_resolved_postcode']    = 'dadata' === $address->source && '' !== trim( $address->address->postcode ) ? $address->address->postcode : (string) ( $city_context['postcode'] ?? $address->address->postcode );
			$map['_wdc_platform_fias_id']              = $address->address->fias_id;
			$map['_wdc_platform_gar_id']               = $address->address->gar_id;
			$map['_wdc_platform_city_source']          = $city_source;
			$map['_wdc_platform_city_display_name']    = $this->city_display( $city_context, $address );
			$map['_wdc_platform_city_postcode']        = (string) ( $city_context['postcode'] ?? $address->address->postcode );
			$map['_wdc_platform_city_fias_id']         = (string) ( $city_context['fias_id'] ?? '' );
			$map['_wdc_platform_city_gar_id']          = (string) ( $city_context['gar_id'] ?? '' );
		}

		$map = array_merge( $map, $dadata_meta, $this->compatible_dadata_meta( $data ), $location_meta );

		$pickup = $this->pickup_selection_for_rate( $rate );
		if (
			'pickup' === (string) ( $rate['delivery_type'] ?? '' )
			&& $this->session_manager->pickup_selection_matches( (string) ( $rate['carrier_key'] ?? '' ), (string) ( $rate['rate_id'] ?? '' ) )
		) {
			$map['_wdc_platform_pickup_code']      = $pickup['point_code'] ?? '';
			$map['_wdc_platform_pickup_address']   = $this->pickup_address( $pickup );
			$map['_wdc_platform_pickup_comment']   = $this->first_meaningful( $pickup['description'] ?? '', $pickup['point_comment'] ?? '', $pickup['snapshot']['description'] ?? '' );
			$map['_wdc_platform_pickup_work_time'] = $this->first_meaningful( $pickup['point_work_time'] ?? '', $pickup['work_time'] ?? '' );
			$map['_wdc_pickup_point_id']           = $pickup['point_id'] ?? $pickup['id'] ?? $pickup['snapshot']['id'] ?? '';
			$map['_wdc_pickup_point_code']         = $pickup['point_code'] ?? '';
			$map['_wdc_pickup_platform_station_id'] = $this->first_meaningful( $pickup['platform_station_id'] ?? '', $pickup['snapshot']['platform_station_id'] ?? '' );
			$map['_wdc_pickup_point_type']         = $pickup['point_type'] ?? '';
			$map['_wdc_pickup_carrier_key']        = $pickup['carrier_key'] ?? '';
			$map['_wdc_pickup_service_key']        = $pickup['service_key'] ?? '';
			$map['_wdc_pickup_family']             = $pickup['pickup_family'] ?? '';
			$map['_wdc_pickup_point_type_label']   = $pickup['point_type_label'] ?? '';
			$map['_wdc_pickup_point_title']        = $pickup['point_title'] ?? '';
			$map['_wdc_pickup_marker_type']        = $pickup['marker_type'] ?? '';
			$map['_wdc_pickup_point_address']      = $this->pickup_address( $pickup );
			$map['_wdc_pickup_point_postcode']     = $this->first_meaningful( $pickup['point_postcode'] ?? '', $pickup['postcode'] ?? '', $pickup['postal_code'] ?? '', $pickup['snapshot']['postcode'] ?? '' );
			$map['_wdc_pickup_point_snapshot']     = function_exists( 'wp_json_encode' ) ? wp_json_encode( is_array( $pickup['snapshot'] ?? null ) ? $pickup['snapshot'] : $pickup, JSON_UNESCAPED_UNICODE ) : json_encode( is_array( $pickup['snapshot'] ?? null ) ? $pickup['snapshot'] : $pickup );
			if ( 'dpd' === (string) ( $pickup['carrier_key'] ?? $rate['carrier_key'] ?? '' ) ) {
				$snapshot = is_array( $pickup['snapshot'] ?? null ) ? $pickup['snapshot'] : array();
				$map['_wdc_dpd_pickup_terminal_code'] = $this->first_meaningful( $pickup['terminal_code'] ?? '', $pickup['point_code'] ?? '', $snapshot['terminal_code'] ?? '', $snapshot['point_code'] ?? '' );
				$map['_wdc_dpd_pickup_type']          = $this->first_meaningful( $pickup['point_type'] ?? '', $snapshot['point_type'] ?? '' );
				$map['_wdc_dpd_pickup_name']          = $this->first_meaningful( $pickup['point_name'] ?? '', $snapshot['point_name'] ?? '' );
				$map['_wdc_dpd_pickup_address']       = $this->pickup_address( $pickup );
				$map['_wdc_dpd_pickup_city_name']     = $this->first_meaningful( $pickup['city_name'] ?? '', $pickup['city'] ?? '', $snapshot['city_name'] ?? '', $snapshot['city'] ?? '' );
				$map['_wdc_dpd_pickup_latitude']      = $this->first_meaningful( $pickup['lat'] ?? '', $snapshot['lat'] ?? '' );
				$map['_wdc_dpd_pickup_longitude']     = $this->first_meaningful( $pickup['lng'] ?? '', $snapshot['lng'] ?? '' );
				$map['_wdc_dpd_pickup_source']        = $this->first_meaningful( $pickup['dpd_source'] ?? '', $snapshot['dpd_source'] ?? '' );
			}
			if ( YandexDeliverySettings::CARRIER_KEY === (string) ( $pickup['carrier_key'] ?? $rate['carrier_key'] ?? '' ) ) {
				$snapshot = is_array( $pickup['snapshot'] ?? null ) ? $pickup['snapshot'] : array();
				$map['_wdc_yandex_delivery_pickup_platform_station_id'] = $this->first_meaningful( $pickup['platform_station_id'] ?? '', $pickup['point_code'] ?? '', $snapshot['platform_station_id'] ?? '', $snapshot['point_code'] ?? '' );
				$map['_wdc_yandex_delivery_pickup_point_code']          = $this->first_meaningful( $pickup['point_code'] ?? '', $snapshot['point_code'] ?? '' );
				$map['_wdc_yandex_delivery_pickup_type']                = $this->first_meaningful( $pickup['point_type'] ?? '', $snapshot['point_type'] ?? '' );
				$map['_wdc_yandex_delivery_pickup_name']                = $this->first_meaningful( $pickup['point_name'] ?? '', $snapshot['point_name'] ?? '' );
				$map['_wdc_yandex_delivery_pickup_address']             = $this->pickup_address( $pickup );
				$map['_wdc_yandex_delivery_pickup_city_name']           = $this->first_meaningful( $pickup['city_name'] ?? '', $pickup['city'] ?? '', $snapshot['city_name'] ?? '', $snapshot['city'] ?? '' );
				$map['_wdc_yandex_delivery_pickup_region_name']         = $this->first_meaningful( $pickup['region_name'] ?? '', $pickup['region'] ?? '', $snapshot['region_name'] ?? '', $snapshot['region'] ?? '' );
				$map['_wdc_yandex_delivery_pickup_latitude']            = $this->first_meaningful( $pickup['lat'] ?? '', $snapshot['lat'] ?? '' );
				$map['_wdc_yandex_delivery_pickup_longitude']           = $this->first_meaningful( $pickup['lng'] ?? '', $snapshot['lng'] ?? '' );
			}
			$this->set_pickup_shipping_address( $order, $pickup, $address );
		}

		$calculation_data = $this->delivery_calculation_data( $rate, $map );
		if ( array() !== $calculation_data ) {
			$map[ self::CALCULATION_META_KEY ] = $calculation_data;
		}

		foreach ( $map as $key => $value ) {
			$order->update_meta_data( $key, $value );
		}
		if ( function_exists( 'do_action' ) ) {
			do_action( 'wdc_delivery_calculation_changed', $order );
		}
	}

	/**
	 * @param mixed $item WooCommerce shipping item.
	 * @param mixed $package_key Checkout package key.
	 * @param mixed $package Checkout package.
	 * @param mixed $order WooCommerce order.
	 */
	public function persist_shipping_item_meta( mixed $item, mixed $package_key = null, mixed $package = null, mixed $order = null ): void {
		unset( $package_key, $package, $order );

		if ( ! is_object( $item ) || ! method_exists( $item, 'add_meta_data' ) ) {
			return;
		}

		$rate = $this->selected_rate();
		if ( array() === $rate ) {
			return;
		}

		if ( $this->is_russian_post_domestic_rate( $rate ) ) {
			$method_title = $this->domestic_method_title( $rate );
			if ( method_exists( $item, 'set_method_title' ) && '' !== $method_title ) {
				$item->set_method_title( $method_title );
			}
		} elseif ( $this->is_cdek_rate( $rate ) ) {
			$method_title = $this->compact_method_title( $rate );
			if ( method_exists( $item, 'set_method_title' ) && '' !== $method_title ) {
				$item->set_method_title( $method_title );
			}
		}

		$this->delete_visible_technical_item_meta( $item );
		$planned = $this->planned_delivery_order_meta_value( $rate );
		if ( '' !== $planned ) {
			$item->add_meta_data( 'Планируемая* дата доставки', $planned, true );
		}
	}

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $order_meta
	 * @return array<string,mixed>
	 */
	private function delivery_calculation_data( array $rate, array $order_meta ): array {
		$rate_meta   = is_array( $rate['rate_meta'] ?? null ) ? $rate['rate_meta'] : array();
		return $this->calculation_data_builder->build(
			$rate,
			array(
				'destination' => $this->calculation_destination_data( $rate_meta, $order_meta ),
				'pickup'      => $this->calculation_pickup_data( $rate ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $rate
	 * @return array<string,mixed>
	 */
	private function calculation_pickup_data( array $rate ): array {
		if ( 'pickup' !== (string) ( $rate['delivery_type'] ?? '' ) ) {
			return array();
		}

		$pickup = $this->pickup_selection_for_rate( $rate );
		if ( ! $this->session_manager->pickup_selection_matches( (string) ( $rate['carrier_key'] ?? '' ), (string) ( $rate['rate_id'] ?? '' ) ) ) {
			return array();
		}
		$snapshot = is_array( $pickup['snapshot'] ?? null ) ? $pickup['snapshot'] : array();
		$is_handout = array_key_exists( 'is_handout', $pickup ) ? filter_var( $pickup['is_handout'], FILTER_VALIDATE_BOOLEAN ) : ( array_key_exists( 'is_handout', $snapshot ) ? filter_var( $snapshot['is_handout'], FILTER_VALIDATE_BOOLEAN ) : null );

		return array(
			'carrier_key'   => (string) ( $pickup['carrier_key'] ?? '' ),
			'service_key'   => (string) ( $pickup['service_key'] ?? '' ),
			'pickup_family' => (string) ( $pickup['pickup_family'] ?? '' ),
			'point_code'    => (string) ( $pickup['point_code'] ?? '' ),
			'country_code'  => strtoupper( trim( $this->first_meaningful( $pickup['country_code'] ?? '', $snapshot['country_code'] ?? '' ) ) ),
			'cdek_city_code' => (int) ( $pickup['cdek_city_code'] ?? $snapshot['cdek_city_code'] ?? 0 ),
			'is_handout'    => null === $is_handout ? null : $is_handout,
			'platform_station_id' => (string) ( $pickup['platform_station_id'] ?? $snapshot['platform_station_id'] ?? '' ),
			'point_type'    => (string) ( $pickup['point_type'] ?? '' ),
			'point_type_label' => (string) ( $pickup['point_type_label'] ?? $snapshot['point_type_label'] ?? '' ),
			'point_title'   => (string) ( $pickup['point_title'] ?? $snapshot['point_title'] ?? '' ),
			'marker_type'   => (string) ( $pickup['marker_type'] ?? $snapshot['marker_type'] ?? '' ),
			'point_name'    => (string) ( $pickup['point_name'] ?? '' ),
			'point_address' => $this->pickup_address( $pickup ),
			'point_postcode' => $this->first_meaningful( $pickup['point_postcode'] ?? '', $pickup['postcode'] ?? '', $pickup['postal_code'] ?? '', $snapshot['postcode'] ?? '' ),
			'city_name'     => $this->first_meaningful( $pickup['city_name'] ?? '', $pickup['city'] ?? '', $snapshot['city'] ?? '' ),
			'region_name'   => $this->first_meaningful( $pickup['region_name'] ?? '', $pickup['region'] ?? '', $snapshot['region'] ?? '' ),
			'latitude'      => $pickup['lat'] ?? $snapshot['lat'] ?? null,
			'longitude'     => $pickup['lng'] ?? $snapshot['lng'] ?? null,
			'work_time'     => $this->first_meaningful( $pickup['point_work_time'] ?? '', $pickup['work_time'] ?? '' ),
			'description'   => $this->first_meaningful( $pickup['description'] ?? '', $pickup['point_comment'] ?? '', $snapshot['description'] ?? '' ),
			'storage_notice' => $this->first_meaningful( $pickup['storage_notice'] ?? '', $snapshot['storage_notice'] ?? '' ),
			'cdek_code'     => (string) ( $pickup['cdek_code'] ?? $snapshot['cdek_code'] ?? '' ),
			'raw_sanitized' => is_array( $snapshot['raw_sanitized'] ?? null ) ? $snapshot['raw_sanitized'] : ( is_array( $snapshot['raw'] ?? null ) ? $snapshot['raw'] : array() ),
		);
	}

	/**
	 * @param array<string,mixed> $rate_meta
	 * @param array<string,mixed> $order_meta
	 * @return array<string,mixed>
	 */
	private function calculation_destination_data( array $rate_meta, array $order_meta ): array {
		$country = is_array( $rate_meta['country_mapping'] ?? null ) ? $rate_meta['country_mapping'] : array();

		return array_filter(
			array(
				'country_code'      => (string) ( $country['country_code'] ?? '' ),
				'country_name'      => (string) ( $country['country_name'] ?? '' ),
				'city_display_name' => (string) ( $order_meta['_wdc_platform_city_display_name'] ?? '' ),
				'fias_id'           => (string) ( $order_meta['_wdc_platform_fias_id'] ?? $order_meta['_wdc_platform_city_fias_id'] ?? '' ),
			),
			static fn ( mixed $value ): bool => '' !== $value
		);
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function is_russian_post_domestic_rate( array $rate ): bool {
		$carrier_key = (string) ( $rate['carrier_key'] ?? '' );
		$service_key = (string) ( $rate['service_key'] ?? '' );

		return RussianPostDomesticSettings::CARRIER_KEY === $carrier_key || RussianPostDomesticSettings::SERVICE_KEY === $service_key;
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function is_cdek_rate( array $rate ): bool {
		$carrier_key = (string) ( $rate['carrier_key'] ?? '' );
		$service_key = (string) ( $rate['service_key'] ?? '' );

		return 'cdek' === $carrier_key || 'cdek' === $service_key;
	}

	/**
	 * @param array<string,mixed> $delivery_days
	 */
	private function delivery_days_label( array $delivery_days ): string {
		return DeliveryDaysFormatter::format_array( $delivery_days );
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function domestic_method_title( array $rate ): string {
		$service_title = $this->domestic_method_prefix( $rate );
		$tariff_title = trim( (string) ( $rate['selected_tariff_title'] ?? $rate['tariff_title'] ?? '' ) );
		if ( '' === $service_title ) {
			return $tariff_title;
		}
		if ( '' === $tariff_title ) {
			return $service_title;
		}

		$delivery = $this->delivery_days_label( is_array( $rate['delivery_days'] ?? null ) ? $rate['delivery_days'] : array() );
		$rate_label = '' !== $delivery ? $tariff_title . ' - ' . $delivery : $tariff_title;

		return $service_title . ', ' . $rate_label;
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function domestic_method_prefix( array $rate ): string {
		$rate_meta = is_array( $rate['rate_meta'] ?? null ) ? $rate['rate_meta'] : array();
		$delivery_type = (string) ( $rate['delivery_type'] ?? '' );
		$key = DeliveryType::COURIER === $delivery_type ? 'courier_method_title' : 'pickup_method_title';
		$default = DeliveryType::COURIER === $delivery_type ? RussianPostDomesticSettings::COURIER_SERVICE_TITLE : RussianPostDomesticSettings::PICKUP_SERVICE_TITLE;
		$title = trim( (string) ( $rate[ $key ] ?? $rate_meta[ $key ] ?? '' ) );

		return '' !== $title ? $title : $default;
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function compact_method_title( array $rate ): string {
		$title  = trim( (string) ( $rate['label'] ?? '' ) );
		$tariff = trim( (string) ( $rate['selected_tariff_title'] ?? $rate['tariff_title'] ?? '' ) );
		if ( '' !== $tariff && ! str_contains( $title, $tariff ) ) {
			$title = '' !== $title ? $title . ', ' . $tariff : $tariff;
		}

		$delivery = $this->compact_delivery_label( $rate );
		if ( '' !== $delivery && ! str_contains( $title, $delivery ) ) {
			$title = '' !== $title ? $title . ' - ' . $delivery : $delivery;
		}

		return $title;
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function compact_delivery_label( array $rate ): string {
		$delivery_days = is_array( $rate['delivery_days'] ?? null ) ? $rate['delivery_days'] : array();
		$delivery      = $this->delivery_days_label( $delivery_days );
		if ( '' !== $delivery ) {
			return $delivery;
		}

		return trim( (string) ( $rate['delivery_comment'] ?? $rate['planned_delivery_comment'] ?? '' ) );
	}

	/**
	 * @param array<string,mixed> $rate
	 */
	private function delivery_label_or_not_specified( array $rate ): string {
		$delivery = $this->compact_delivery_label( $rate );

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

	private function delete_visible_technical_item_meta( object $item ): void {
		if ( ! method_exists( $item, 'delete_meta_data' ) ) {
			return;
		}

		foreach ( $this->visible_technical_item_meta_keys() as $key ) {
			$item->delete_meta_data( $key );
		}
	}

	/**
	 * @return array<int,string>
	 */
	private function visible_technical_item_meta_keys(): array {
		return array(
			'carrier_key',
			'rate_id',
			'delivery_type',
			'country_code',
			'pickup_family',
			'wdc_delivery_kind',
			'delivery_kind',
			'checkout_group_id',
			'is_courier',
			'Перевозчик',
			'Способ доставки',
			'Тип доставки',
			'Тариф',
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
			'crossed_price',
			'planned_delivery_date',
			'planned_delivery_comment',
			'comments',
			'disabled',
			'disabled_reason',
			'service_key',
			'service_title',
			'tariff_key',
			'tariff_title',
			'domestic_tariff_grouped',
			'tariff_variants',
			'selected_tariff_object',
			'selected_tariff_title',
			'selected_tariff_rate_id',
			'rules_source',
			'round_up_applied',
			'minimum_price_applied',
			'final_price_rub',
			'api_base_price_rub',
			'api_price_with_vat_rub',
			'api_price_has_vat',
			'delivery_days',
			'api_delivery_min_days',
			'api_delivery_max_days',
			'api_delivery_text',
			'api_delivery_days_text',
			'request_payload_sanitized',
			'response_tariff_sanitized',
			'cdek_from_city_code',
			'cdek_to_city_code',
			'cdek_location_source',
			'final_delivery_min_days',
			'final_delivery_max_days',
			'final_delivery_text',
			'rules_audit',
			'rate_meta',
			'object_code',
			'domestic_tariff_variant',
			'postcode',
			'pay',
			'nds',
			'paynds',
			'delivery_min_days',
			'delivery_max_days',
			'transtype',
			'delivery_to',
			'items_summary',
			'request_params',
			'http_code',
			'cache_hit',
			'package',
			'requires_pickup_point',
			'requires_courier_address',
			'no_pickup_selection',
			'fallback_used',
		);
	}

	/**
	 * @param array<string,mixed> $meta
	 * @return array<string,mixed>
	 */
	private function sanitized_rate_meta( array $meta ): array {
		foreach ( $this->transient_pickup_rejection_keys() as $key ) {
			unset( $meta[ $key ] );
		}
		unset( $meta['raw_response'] );
		if ( is_array( $meta['api_result'] ?? null ) ) {
			unset( $meta['api_result']['raw'], $meta['api_result']['parsed_response'], $meta['api_result']['raw_response'] );
		}

		return $meta;
	}

	/** @return array<int,string> */
	private function transient_pickup_rejection_keys(): array {
		return array(
			'pickup_selection_rejected',
			'pickup_selection_rejected_family',
			'pickup_selection_rejected_code',
			'pickup_selection_rejected_message',
		);
	}

	/**
	 * @param array<string,mixed> $pickup
	 */
	private function set_pickup_shipping_address( object $order, array $pickup, mixed $address_result ): void {
		$address = is_object( $address_result ) && isset( $address_result->address ) ? $address_result->address : null;
		$this->call_order_setter( $order, 'set_shipping_address_1', $this->pickup_address( $pickup ) );
		$this->call_order_setter( $order, 'set_shipping_address_2', '', true );
		$this->call_order_setter( $order, 'set_shipping_state', $this->first_meaningful( $pickup['region_name'] ?? '', $pickup['region'] ?? '', $pickup['snapshot']['region'] ?? '', is_object( $address ) ? $address->region_name : '' ) );
		$this->call_order_setter( $order, 'set_shipping_city', $this->first_meaningful( $pickup['city_name'] ?? '', $pickup['city'] ?? '', $pickup['snapshot']['city'] ?? '', is_object( $address ) ? (string) ( $address->settlement ?: $address->city ) : '' ) );
		$postcode = $this->first_meaningful( $pickup['point_postcode'] ?? '', $pickup['postcode'] ?? '', $pickup['postal_code'] ?? '', $pickup['snapshot']['postcode'] ?? '' );
		if ( '' === $postcode && is_object( $address ) ) {
			$postcode = (string) $address->postcode;
		}
		$this->call_order_setter( $order, 'set_shipping_postcode', $postcode );
		$this->call_order_setter( $order, 'set_shipping_country', $this->pickup_country_code( $pickup, $address ) );
	}

	/**
	 * @param array<string,mixed> $pickup
	 */
	private function pickup_country_code( array $pickup, mixed $address ): string {
		$snapshot = is_array( $pickup['snapshot'] ?? null ) ? $pickup['snapshot'] : array();
		$city_context = $this->session_manager->city_context();
		$country = strtoupper(
			trim(
				$this->first_meaningful(
					$pickup['country_code'] ?? '',
					$snapshot['country_code'] ?? '',
					is_object( $address ) ? (string) ( $address->country_code ?? '' ) : '',
					$city_context['country_code'] ?? ''
				)
			)
		);

		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : 'RU';
	}

	private function call_order_setter( object $order, string $method, string $value, bool $allow_empty = false ): void {
		if ( ( '' !== $value || $allow_empty ) && method_exists( $order, $method ) ) {
			$order->{$method}( $value );
		}
	}

	private function delivery_type_label( string $delivery_type ): string {
		return match ( $delivery_type ) {
			'pickup' => 'Пункт выдачи',
			'courier' => 'Курьер',
			default => $delivery_type,
		};
	}

	private function address_summary(): string {
		$city_context = $this->session_manager->city_context();
		if ( array() !== $city_context ) {
			return trim( $this->city_display( $city_context, $this->session_manager->normalized_address_result() ) . ( '' !== (string) ( $city_context['postcode'] ?? '' ) ? ' / ' . (string) $city_context['postcode'] : '' ), ' /' );
		}

		$address_result = $this->session_manager->normalized_address_result();
		if ( null === $address_result ) {
			return $this->session_manager->fallback_city();
		}

		$address  = $address_result->address;
		$city     = (string) ( $address->settlement ?: $address->city ?: $this->session_manager->fallback_city() );
		$postcode = (string) $address->postcode;

		return trim( $city . ( '' !== $postcode ? ' / ' . $postcode : '' ), ' /' );
	}

	private function normalization_summary(): string {
		$address_result = $this->session_manager->normalized_address_result();
		if ( null === $address_result ) {
			return '';
		}

		if ( $address_result->address->fallback || ! $address_result->address->normalized ) {
			return 'не выполнялась';
		}

		return in_array( $address_result->source, array( 'fias', 'gar' ), true ) ? 'ФИАС/ГАР' : (string) $address_result->source;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function dadata_meta_from_checkout_data( array $data ): array {
		$meta = array();
		foreach ( array( 'billing', 'shipping' ) as $prefix ) {
			foreach ( $this->dadata_field_keys() as $field ) {
				$key = $prefix . '_dadata_' . $field;
				if ( ! array_key_exists( $key, $data ) ) {
					continue;
				}

				$meta[ '_' . $key ] = $this->sanitize_checkout_value( $data[ $key ] );
			}
		}

		return $meta;
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function location_meta_from_checkout_data( array $data ): array {
		$fias_id = $this->checkout_string( $data, 'wdc_platform_location_fias_id' );
		if ( '' === $fias_id ) {
			return array();
		}
		if ( ! $this->local_location_country_supported( $data ) ) {
			return array();
		}

		return array(
			'_wdc_platform_location_fias_id'     => $fias_id,
			'_wdc_platform_location_display_name' => $this->checkout_string( $data, 'wdc_platform_location_display_name' ),
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function compatible_dadata_meta( array $data ): array {
		$prefix = $this->preferred_dadata_prefix( $data );
		if ( '' === $prefix ) {
			return array();
		}

		$status     = $this->checkout_string( $data, $prefix . '_dadata_status' );
		$normalized = in_array( $status, array( 'house_selected', 'resolved' ), true );
		$source     = $normalized || in_array( $status, array( 'city_selected', 'street_selected' ), true ) ? 'dadata' : ( 'manual' === $status ? 'manual' : 'fallback' );
		$address    = trim( $this->checkout_string( $data, $prefix . '_address_1' ) . ' ' . $this->checkout_string( $data, $prefix . '_address_2' ) );

		return array(
			'_wdc_platform_fias_id'               => $this->checkout_string( $data, $prefix . '_dadata_fias_id' ),
			'_wdc_platform_gar_id'                => '',
			'_wdc_platform_resolved_postcode'     => $this->checkout_string( $data, $prefix . '_postcode' ),
			'_wdc_platform_normalized'            => $normalized,
			'_wdc_platform_normalization_source'  => $source,
			'_wdc_platform_fallback_address'      => $normalized ? '' : $address,
			'_wdc_platform_address_fallback_used' => ! $normalized,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function preferred_dadata_prefix( array $data ): string {
		foreach ( array( 'shipping', 'billing' ) as $prefix ) {
			$status = $this->checkout_string( $data, $prefix . '_dadata_status' );
			if ( '' !== $status ) {
				return $prefix;
			}
		}

		return '';
	}

	/**
	 * @return array<int,string>
	 */
	private function dadata_field_keys(): array {
		return array(
			'status',
			'unrestricted_value',
			'region',
			'region_with_type',
			'region_fias_id',
			'region_kladr_id',
			'city',
			'city_with_type',
			'city_fias_id',
			'city_kladr_id',
			'settlement',
			'settlement_with_type',
			'settlement_fias_id',
			'settlement_kladr_id',
			'street',
			'street_with_type',
			'street_fias_id',
			'street_kladr_id',
			'house',
			'house_fias_id',
			'house_kladr_id',
			'block',
			'flat',
			'fias_id',
			'kladr_id',
			'fias_level',
		);
	}

	private function checkout_string( array $data, string $key ): string {
		if ( ! array_key_exists( $key, $data ) ) {
			return '';
		}

		return $this->sanitize_checkout_value( $data[ $key ] );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function local_location_country_supported( array $data ): bool {
		$country_code = $this->checkout_country_code( $data );
		if ( '' === $country_code ) {
			return false;
		}
		$index = function_exists( 'get_option' ) ? get_option( 'wdc_location_country_codes', array() ) : array();
		$countries = is_array( $index['countries'] ?? null ) ? $index['countries'] : ( is_array( $index ) ? $index : array() );
		$countries = array_values(
			array_filter(
				array_map(
					static fn( mixed $code ): string => strtoupper( trim( (string) $code ) ),
					$countries
				),
				static fn( string $code ): bool => (bool) preg_match( '/^[A-Z]{2}$/', $code )
			)
		);

		return in_array( $country_code, $countries, true );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function checkout_country_code( array $data ): string {
		$ship_to_different = ! empty( $data['ship_to_different_address'] ) && '0' !== (string) $data['ship_to_different_address'];
		$key = $ship_to_different ? 'shipping_country' : 'billing_country';
		$country_code = $this->checkout_string( $data, $key );
		if ( '' === $country_code ) {
			$country_code = $this->checkout_string( $data, 'shipping_country' );
		}
		if ( '' === $country_code ) {
			$country_code = $this->checkout_string( $data, 'billing_country' );
		}
		$country_code = strtoupper( trim( $country_code ) );

		return preg_match( '/^[A-Z]{2}$/', $country_code ) ? $country_code : '';
	}

	private function sanitize_checkout_value( mixed $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		$value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;

		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( $value );
	}

	/**
	 * @param array<string,mixed> $city_context
	 */
	private function city_display( array $city_context, mixed $address_result = null ): string {
		$display = trim( (string) ( $city_context['display_name'] ?? '' ) );
		if ( '' !== $display ) {
			return $display;
		}

		$city = trim( (string) ( $city_context['settlement_name'] ?? '' ) );
		if ( '' === $city ) {
			$city = trim( (string) ( $city_context['city_name'] ?? '' ) );
		}

		if ( '' === $city && is_object( $address_result ) && isset( $address_result->address ) ) {
			$city = (string) ( $address_result->address->settlement ?: $address_result->address->city );
		}

		$region = trim( (string) ( $city_context['region_name'] ?? '' ) );

		return trim( $city . ( '' !== $region ? ' — ' . $region : '' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function selected_rate(): array {
		$rates  = $this->session_manager->rates();
		$chosen = $this->chosen_shipping_methods();

		foreach ( $chosen as $rate_id ) {
			if ( isset( $rates[ $rate_id ] ) ) {
				return $rates[ $rate_id ];
			}

			if ( ! str_starts_with( $rate_id, NewShippingMethod::METHOD_ID . ':' ) ) {
				continue;
			}

			$normalized = substr( $rate_id, strlen( NewShippingMethod::METHOD_ID . ':' ) );
			if ( isset( $rates[ $normalized ] ) ) {
				return $rates[ $normalized ];
			}
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $rate
	 * @return array<string,mixed>
	 */
	private function pickup_selection_for_rate( array $rate ): array {
		$family = $this->session_manager->shipping_method_family( (string) ( $rate['rate_id'] ?? '' ) );
		if ( str_ends_with( $family, ':pickup' ) ) {
			return $this->session_manager->pickup_selection_for_family( $family );
		}

		return $this->session_manager->pickup_selection();
	}

	/**
	 * @return array<int,string>
	 */
	private function chosen_shipping_methods(): array {
		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->session ) && is_object( WC()->session ) && method_exists( WC()->session, 'get' ) ) {
			$chosen = WC()->session->get( 'chosen_shipping_methods', array() );

			return is_array( $chosen ) ? array_map( 'strval', $chosen ) : array();
		}

		return array();
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

	/**
	 * @param array<string,mixed> $pickup
	 */
	private function pickup_address( array $pickup ): string {
		$snapshot = is_array( $pickup['snapshot'] ?? null ) ? $pickup['snapshot'] : array();
		$raw = is_array( $pickup['raw'] ?? null ) ? $pickup['raw'] : ( is_array( $snapshot['raw'] ?? null ) ? $snapshot['raw'] : array() );

		return $this->first_meaningful(
			$pickup['point_address'] ?? '',
			$pickup['address'] ?? '',
			$pickup['address_full'] ?? '',
			$pickup['full_address'] ?? '',
			$pickup['address_short'] ?? '',
			$pickup['location_address'] ?? '',
			$pickup['address_source'] ?? '',
			$snapshot['point_address'] ?? '',
			$snapshot['address'] ?? '',
			$snapshot['address_full'] ?? '',
			$snapshot['full_address'] ?? '',
			$snapshot['address_short'] ?? '',
			$snapshot['location_address'] ?? '',
			$snapshot['address_source'] ?? '',
			$raw['address'] ?? '',
			$raw['address_full'] ?? '',
			$raw['full_address'] ?? '',
			$raw['address_short'] ?? '',
			$raw['location_address'] ?? ''
		);
	}
}
