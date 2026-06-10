<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Rules\Services\RuleFormulaFormatter;

defined( 'ABSPATH' ) || exit;

final class OrderShippingMetaPersister {
	public const CALCULATION_META_KEY = '_wdc_delivery_calculation_data';

	public function __construct(
		private CheckoutSessionManager $session_manager
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

		$pickup = $this->session_manager->pickup_selection();
		if (
			'pickup' === (string) ( $rate['delivery_type'] ?? '' )
			&& $this->session_manager->pickup_selection_matches( (string) ( $rate['carrier_key'] ?? '' ), (string) ( $rate['rate_id'] ?? '' ) )
		) {
			$map['_wdc_platform_pickup_code']      = $pickup['point_code'] ?? '';
			$map['_wdc_platform_pickup_address']   = $pickup['point_address'] ?? '';
			$map['_wdc_platform_pickup_comment']   = $pickup['point_comment'] ?? '';
			$map['_wdc_platform_pickup_work_time'] = $pickup['point_work_time'] ?? '';
			$map['_wdc_pickup_point_id']           = $pickup['point_id'] ?? '';
			$map['_wdc_pickup_point_code']         = $pickup['point_code'] ?? '';
			$map['_wdc_pickup_point_type']         = $pickup['point_type'] ?? '';
			$map['_wdc_pickup_point_address']      = $pickup['point_address'] ?? '';
			$map['_wdc_pickup_point_postcode']     = $pickup['point_postcode'] ?? '';
			$map['_wdc_pickup_point_snapshot']     = function_exists( 'wp_json_encode' ) ? wp_json_encode( is_array( $pickup['snapshot'] ?? null ) ? $pickup['snapshot'] : $pickup, JSON_UNESCAPED_UNICODE ) : json_encode( is_array( $pickup['snapshot'] ?? null ) ? $pickup['snapshot'] : $pickup );
			$this->set_pickup_shipping_address( $order, $pickup, $address );
		}

		$calculation_data = $this->delivery_calculation_data( $rate, $map );
		if ( array() !== $calculation_data ) {
			$map[ self::CALCULATION_META_KEY ] = $calculation_data;
		}

		foreach ( $map as $key => $value ) {
			$order->update_meta_data( $key, $value );
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
		$item->add_meta_data( 'Срок доставки', $this->delivery_label_or_not_specified( $rate ), true );
	}

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $order_meta
	 * @return array<string,mixed>
	 */
	private function delivery_calculation_data( array $rate, array $order_meta ): array {
		$rate_meta   = is_array( $rate['rate_meta'] ?? null ) ? $rate['rate_meta'] : array();
		$destination = $this->calculation_destination_data( $rate_meta, $order_meta );
		$api         = $this->calculation_api_data( $rate_meta );
		$result      = $this->calculation_result_data( $rate, $rate_meta );

		return array_filter(
			array(
				'carrier_key'   => (string) ( $rate['carrier_key'] ?? '' ),
				'service_key'   => (string) ( $rate['service_key'] ?? '' ),
				'service_title' => (string) ( $rate['service_title'] ?? '' ),
				'rate_id'       => (string) ( $rate['rate_id'] ?? '' ),
				'selected_tariff_object' => (string) ( $rate['selected_tariff_object'] ?? $rate['tariff_key'] ?? '' ),
				'selected_tariff_title' => (string) ( $rate['selected_tariff_title'] ?? $rate['tariff_title'] ?? '' ),
				'delivery_type' => (string) ( $rate['delivery_type'] ?? '' ),
				'pickup'        => $this->calculation_pickup_data( $rate ),
				'destination'   => $destination,
				'package'       => $this->calculation_package_data( $rate_meta ),
				'api'           => $api,
				'rules'         => $this->calculation_rules_data( $rate, $rate_meta, $api, $result ),
				'result'        => $result,
			),
			static fn ( mixed $value ): bool => array() !== $value && '' !== $value
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

		$pickup = $this->session_manager->pickup_selection();
		if ( ! $this->session_manager->pickup_selection_matches( (string) ( $rate['carrier_key'] ?? '' ), (string) ( $rate['rate_id'] ?? '' ) ) ) {
			return array();
		}

		return array(
			'point_code'    => (string) ( $pickup['point_code'] ?? '' ),
			'point_type'    => (string) ( $pickup['point_type'] ?? '' ),
			'point_name'    => (string) ( $pickup['point_name'] ?? '' ),
			'point_address' => (string) ( $pickup['point_address'] ?? '' ),
			'point_postcode' => (string) ( $pickup['point_postcode'] ?? '' ),
			'point_raw'     => $pickup,
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
	 * @param array<string,mixed> $rate_meta
	 * @return array<string,mixed>
	 */
	private function calculation_package_data( array $rate_meta ): array {
		$package      = is_array( $rate_meta['package'] ?? null ) ? $rate_meta['package'] : array();
		$final_weight = (int) ( $rate_meta['package_weight_with_packaging_g'] ?? $package['total_weight_g'] ?? $package['weight_g'] ?? 0 );

		return array(
			'products_weight_g'          => (int) ( $rate_meta['products_weight_g'] ?? $package['items_weight_g'] ?? $package['weight_g'] ?? $final_weight ),
			'packaging_weight_g'         => (int) ( $rate_meta['packaging_weight_g'] ?? $package['packaging_weight_g'] ?? 0 ),
			'final_weight_g'             => $final_weight,
			'include_packaging_weight'   => ! empty( $rate_meta['include_packaging_weight'] ),
			'packaging_weight_mode'      => (string) ( $rate_meta['packaging_weight_mode'] ?? '' ),
			'dimensions_cm'              => is_array( $package['dimensions_cm'] ?? null ) ? $package['dimensions_cm'] : array(),
		);
	}

	/**
	 * @param array<string,mixed> $rate_meta
	 * @return array<string,mixed>
	 */
	private function calculation_api_data( array $rate_meta ): array {
		$country    = is_array( $rate_meta['country_mapping'] ?? null ) ? $rate_meta['country_mapping'] : array();
		$api_result = is_array( $rate_meta['api_result'] ?? null ) ? $rate_meta['api_result'] : array();
		$location   = is_array( $rate_meta['location'] ?? null ) ? $rate_meta['location'] : array();

		return array_filter(
			array(
				'api_base_price_rub'      => $this->nullable_float( $rate_meta['api_base_price_rub'] ?? $rate_meta['api_price_with_vat_rub'] ?? null ),
				'api_price_has_vat'       => array_key_exists( 'api_price_has_vat', $rate_meta ) ? (bool) $rate_meta['api_price_has_vat'] : null,
				'api_price_with_vat_rub'  => $this->nullable_float( $rate_meta['api_price_with_vat_rub'] ?? null ),
				'pay'                     => array_key_exists( 'pay', $rate_meta ) ? (int) $rate_meta['pay'] : null,
				'nds'                     => array_key_exists( 'nds', $rate_meta ) ? (int) $rate_meta['nds'] : null,
				'paynds'                  => array_key_exists( 'paynds', $rate_meta ) ? (int) $rate_meta['paynds'] : null,
				'delivery_min_days'       => array_key_exists( 'delivery_min_days', $rate_meta ) ? (int) $rate_meta['delivery_min_days'] : null,
				'delivery_max_days'       => array_key_exists( 'delivery_max_days', $rate_meta ) ? (int) $rate_meta['delivery_max_days'] : null,
				'api_delivery_min_days'   => array_key_exists( 'delivery_min_days', $rate_meta ) ? (int) $rate_meta['delivery_min_days'] : null,
				'api_delivery_max_days'   => array_key_exists( 'delivery_max_days', $rate_meta ) ? (int) $rate_meta['delivery_max_days'] : null,
				'api_delivery_text'       => $this->delivery_days_label(
					array(
						'min_days' => $rate_meta['delivery_min_days'] ?? null,
						'max_days' => $rate_meta['delivery_max_days'] ?? null,
					)
				),
				'api_delivery_days_text'  => (string) ( $rate_meta['api_delivery_days_text'] ?? '' ),
				'request_payload_sanitized' => is_array( $rate_meta['request_payload_sanitized'] ?? null ) ? $rate_meta['request_payload_sanitized'] : array(),
				'response_tariff_sanitized' => is_array( $rate_meta['response_tariff_sanitized'] ?? null ) ? $rate_meta['response_tariff_sanitized'] : array(),
				'cdek_from_city_code'     => $location['cdek_from_city_code'] ?? null,
				'cdek_to_city_code'       => $location['cdek_to_city_code'] ?? null,
				'cdek_location_source'    => (string) ( $location['cdek_location_source'] ?? '' ),
				'transtype'               => array_key_exists( 'transtype', $rate_meta ) ? (int) $rate_meta['transtype'] : null,
				'delivery_to'             => (string) ( $rate_meta['delivery_to'] ?? '' ),
				'items_summary'           => is_array( $rate_meta['items_summary'] ?? null ) ? $rate_meta['items_summary'] : array(),
				'vat_rate'                => $this->nullable_float( $rate_meta['vat_rate'] ?? null ),
				'request_params'          => is_array( $rate_meta['request_params'] ?? null ) ? $this->sanitize_request_params( $rate_meta['request_params'] ) : array(),
				'cache_hit'               => ! empty( $rate_meta['cache_hit'] ),
				'http_code'               => (int) ( $rate_meta['http_code'] ?? $api_result['http_code'] ?? 0 ),
				'carrier_country_id'      => (string) ( $country['carrier_country_id'] ?? '' ),
				'country_name'            => (string) ( $country['country_name'] ?? '' ),
			),
			static fn ( mixed $value ): bool => null !== $value && array() !== $value && '' !== $value && 0 !== $value
		);
	}

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $rate_meta
	 * @return array<string,mixed>
	 */
	private function calculation_result_data( array $rate, array $rate_meta ): array {
		$is_fallback = ! empty( $rate_meta['fallback'] ) || ! empty( $rate['fallback_used'] );
		$final_price = $this->nullable_float( $rate_meta['final_price_rub'] ?? null )
			?? $this->nullable_float( $rate['final_price_rub'] ?? null )
			?? $this->nullable_float( $rate['cost'] ?? null );
		$result = array(
			'final_price_rub'       => null !== $final_price ? $final_price : ( $is_fallback ? 0.0 : null ),
			'round_up_applied'      => ! empty( $rate['round_up_applied'] ) || ! empty( $rate_meta['round_up_applied'] ),
			'minimum_price_applied' => ! empty( $rate['minimum_price_applied'] ) || ! empty( $rate_meta['minimum_price_applied'] ),
			'fallback'              => $is_fallback,
			'fallback_reason'       => (string) ( $rate_meta['fallback_reason'] ?? '' ),
			'fallback_text'         => (string) ( $rate_meta['fallback_text'] ?? '' ),
		);

		$delivery_days = is_array( $rate['delivery_days'] ?? null ) ? $rate['delivery_days'] : array();
		$min_days      = $delivery_days['min_days'] ?? $delivery_days['min'] ?? null;
		$max_days      = $delivery_days['max_days'] ?? $delivery_days['max'] ?? null;
		if ( is_numeric( $min_days ) && (int) $min_days > 0 ) {
			$result['final_delivery_days_min'] = (int) $min_days;
			$result['final_delivery_min_days'] = (int) $min_days;
		}
		if ( is_numeric( $max_days ) && (int) $max_days > 0 ) {
			$result['final_delivery_days_max'] = (int) $max_days;
			$result['final_delivery_max_days'] = (int) $max_days;
		}
		$final_delivery_text = $this->delivery_days_label( $delivery_days );
		if ( '' !== $final_delivery_text ) {
			$result['final_delivery_text'] = $final_delivery_text;
		}

		return array_filter( $result, static fn ( mixed $value ): bool => '' !== $value && null !== $value );
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
	 * @param array<string,mixed> $rate_meta
	 * @param array<string,mixed> $api
	 * @param array<string,mixed> $result
	 * @return array<string,mixed>
	 */
	private function calculation_rules_data( array $rate, array $rate_meta, array $api, array $result ): array {
		$source      = (string) ( $rate['rules_source'] ?? $rate_meta['rules_source'] ?? 'none' );
		$is_fallback = ! empty( $result['fallback'] ) || ! empty( $rate_meta['terminal_fallback'] );
		if ( $is_fallback ) {
			return array(
				'rules_source'          => 'skipped_fallback' === $source ? $source : 'none',
				'applied_rules'         => array(),
				'formula_visualization' => array(),
			);
		}

		$audit = is_array( $rate_meta['rules_audit'] ?? null )
			? array_values( $rate_meta['rules_audit'] )
			: ( is_array( $rate['rules_audit'] ?? null ) ? array_values( $rate['rules_audit'] ) : array() );
		$base  = $this->nullable_float( $api['api_base_price_rub'] ?? null ) ?? $this->nullable_float( $rate['cost'] ?? null ) ?? 0.0;
		$final = $this->nullable_float( $result['final_price_rub'] ?? null );
		$has_price_formula = array() !== $audit || ! empty( $result['round_up_applied'] ) || ! empty( $result['minimum_price_applied'] );
		if ( null === $final || ! $has_price_formula ) {
			return array(
				'rules_source'          => $source,
				'applied_rules'         => $audit,
				'formula_visualization' => array(),
			);
		}

		return array(
			'rules_source'          => $source,
			'applied_rules'         => $audit,
			'formula_visualization' => ( new RuleFormulaFormatter() )->lines(
				$base,
				$audit,
				$final,
				array(
					'round_up_applied'      => ! empty( $result['round_up_applied'] ) && $final > 0,
					'minimum_price_applied' => ! empty( $result['minimum_price_applied'] ) && $final > 0,
				)
			),
		);
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
			'wdc_delivery_kind',
			'delivery_kind',
			'checkout_group_id',
			'is_courier',
			'Перевозчик',
			'Способ доставки',
			'Тип доставки',
			'Тариф',
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
			'crossed_price',
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
	 * @param array<string,mixed> $params
	 * @return array<string,scalar>
	 */
	private function sanitize_request_params( array $params ): array {
		$sanitized = array();
		foreach ( $params as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$sanitized[ (string) $key ] = $value;
			}
		}

		return $sanitized;
	}

	/**
	 * @param array<string,mixed> $meta
	 * @return array<string,mixed>
	 */
	private function sanitized_rate_meta( array $meta ): array {
		unset( $meta['raw_response'] );
		if ( is_array( $meta['api_result'] ?? null ) ) {
			unset( $meta['api_result']['raw'], $meta['api_result']['parsed_response'], $meta['api_result']['raw_response'] );
		}

		return $meta;
	}

	private function nullable_float( mixed $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}

	/**
	 * @param array<string,mixed> $pickup
	 */
	private function set_pickup_shipping_address( object $order, array $pickup, mixed $address_result ): void {
		$address = is_object( $address_result ) && isset( $address_result->address ) ? $address_result->address : null;
		$this->call_order_setter( $order, 'set_shipping_address_1', (string) ( $pickup['point_address'] ?? '' ) );
		$this->call_order_setter( $order, 'set_shipping_city', is_object( $address ) ? (string) ( $address->settlement ?: $address->city ) : '' );
		$postcode = (string) ( $pickup['point_postcode'] ?? '' );
		if ( '' === $postcode && is_object( $address ) ) {
			$postcode = (string) $address->postcode;
		}
		$this->call_order_setter( $order, 'set_shipping_postcode', $postcode );
		$this->call_order_setter( $order, 'set_shipping_country', is_object( $address ) && '' !== (string) $address->country_code ? (string) $address->country_code : 'RU' );
	}

	private function call_order_setter( object $order, string $method, string $value ): void {
		if ( '' !== $value && method_exists( $order, $method ) ) {
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
	 * @return array<int,string>
	 */
	private function chosen_shipping_methods(): array {
		if ( function_exists( 'WC' ) && is_object( WC() ) && isset( WC()->session ) && is_object( WC()->session ) && method_exists( WC()->session, 'get' ) ) {
			$chosen = WC()->session->get( 'chosen_shipping_methods', array() );

			return is_array( $chosen ) ? array_map( 'strval', $chosen ) : array();
		}

		return array();
	}
}
