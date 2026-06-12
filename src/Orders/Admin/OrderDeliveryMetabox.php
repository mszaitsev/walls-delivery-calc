<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Admin;

use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class OrderDeliveryMetabox {
	private const META_KEYS = array(
		OrderShippingMetaPersister::CALCULATION_META_KEY,
		'_wdc_platform_carrier_key',
		'_wdc_platform_rate_id',
		'_wdc_platform_delivery_type',
	);

	public function __construct(
		private ?OrderShipmentRepository $shipments = null
	) {
		$this->shipments = $this->shipments ?? new OrderShipmentRepository();
	}

	public function register(): void {
		add_action( 'add_meta_boxes_woocommerce_page_wc-orders', array( $this, 'add_hpos_meta_box' ) );
		add_action( 'add_meta_boxes_shop_order', array( $this, 'add_classic_meta_box' ) );
	}

	public function add_hpos_meta_box(): void {
		$screen = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'woocommerce_page_wc-orders';
		add_meta_box( 'wdc_platform_delivery', 'Калькулятор доставок', array( $this, 'render' ), $screen, 'side', 'default' );
	}

	public function add_classic_meta_box(): void {
		add_meta_box( 'wdc_platform_delivery', 'Калькулятор доставок', array( $this, 'render' ), 'shop_order', 'side', 'default' );
	}

	public function render( mixed $post_or_order ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$order = $this->resolve_order( $post_or_order );
		if ( null === $order || ! $this->has_wdc_meta( $order ) ) {
			echo '<p>' . esc_html__( 'Данные WDC для заказа не сохранены.', 'walls-delivery-calc' ) . '</p>';
			if ( null !== $order ) {
				$this->render_recalculation_preview_block( $order );
			}
			return;
		}

		$calculation = $this->calculation_data( $order );
		if ( array() !== $calculation ) {
			$this->render_calculation_data( $calculation );
			$this->render_recalculation_preview_block( $order );
			return;
		}

		$this->render_rows( $this->legacy_rows( $order ) );
		$this->render_recalculation_preview_block( $order );
	}

	private function render_calculation_data( array $calculation ): void {
		$destination = is_array( $calculation['destination'] ?? null ) ? $calculation['destination'] : array();
		$pickup      = is_array( $calculation['pickup'] ?? null ) ? $calculation['pickup'] : array();
		$package     = is_array( $calculation['package'] ?? null ) ? $calculation['package'] : array();
		$api         = is_array( $calculation['api'] ?? null ) ? $calculation['api'] : array();
		$rules       = is_array( $calculation['rules'] ?? null ) ? $calculation['rules'] : array();
		$result      = is_array( $calculation['result'] ?? null ) ? $calculation['result'] : array();
		$fallback    = ! empty( $result['fallback'] );

		$rows = array(
			'Служба доставки' => (string) ( $calculation['service_title'] ?? '' ),
			'Выбранный тариф' => (string) ( $calculation['selected_tariff_title'] ?? '' ),
			'Тип доставки' => $this->delivery_type_label( (string) ( $calculation['delivery_type'] ?? '' ) ),
			'Страна назначения' => $this->should_show_country( $destination ) ? $this->country_label( $destination ) : '',
			'Вес товаров' => $this->grams( $package['products_weight_g'] ?? null ),
			'Вес упаковки' => $this->grams( $package['packaging_weight_g'] ?? null ),
			'Итоговый вес для API' => $this->grams( $package['final_weight_g'] ?? null ),
		);

		if ( array() !== $pickup ) {
			$rows['Код ПВЗ']   = (string) ( $pickup['point_code'] ?? '' );
			$rows['Тип ПВЗ']   = (string) ( $pickup['point_type'] ?? '' );
			$rows['Адрес ПВЗ'] = (string) ( $pickup['point_address'] ?? '' );
			$rows['Описание ПВЗ'] = (string) ( $pickup['description'] ?? '' );
			$rows['Срок хранения'] = (string) ( $pickup['storage_notice'] ?? '' );
		}

		if ( $fallback ) {
			$rows['Fallback reason'] = (string) ( $result['fallback_reason'] ?? '' );
			$rows['Fallback text']   = (string) ( $result['fallback_text'] ?? '' );
		} else {
			$rows['Базовая стоимость API'] = $this->rubles( $api['api_base_price_rub'] ?? null );
			$api_delivery_days = $this->api_delivery_days_label( $api );
			if ( '' !== $api_delivery_days ) {
				$rows['Срок по API'] = $api_delivery_days;
			}
			$formula = is_array( $rules['formula_visualization'] ?? null ) ? $rules['formula_visualization'] : array();
			if ( array() !== $formula ) {
				$rows['Правила расчета'] = $formula;
			}
		}

		$rows['Итоговая стоимость'] = $this->rubles( $result['final_price_rub'] ?? null );
		$delivery_days = $this->delivery_days_label( $result );
		if ( '' !== $delivery_days ) {
			$rows['Итоговый срок'] = $delivery_days;
		}

		$this->render_rows( $rows );
	}

	/**
	 * @param array<string,mixed> $rows
	 */
	private function render_rows( array $rows ): void {
		echo '<table class="widefat striped wdc-platform-order-delivery"><tbody>';
		foreach ( $rows as $label => $value ) {
			if ( is_array( $value ) ) {
				$value = array_values( array_filter( array_map( 'strval', $value ), static fn ( string $line ): bool => '' !== trim( $line ) ) );
				if ( array() === $value ) {
					continue;
				}
				echo '<tr><th style="width:45%;text-align:left;">' . esc_html( (string) $label ) . '</th><td>' . implode( '<br>', array_map( 'esc_html', $value ) ) . '</td></tr>';
				continue;
			}

			if ( '' === trim( (string) $value ) ) {
				continue;
			}

			echo '<tr><th style="width:45%;text-align:left;">' . esc_html( (string) $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function render_recalculation_preview_block( object $order ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		echo '<div class="wdc-order-delivery-recalculation" data-wdc-order-delivery-recalculation>';
		$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		$current_location = $this->current_location_payload( $order );
		$current_pickup = $this->current_pickup_payload( $order );
		$current_shipping_address = $this->current_shipping_address_payload( $order );
		echo '<p><button type="button" class="button" data-wdc-order-delivery-recalculate data-order-id="' . esc_attr( (string) $order_id ) . '">' . esc_html__( 'Пересчитать доставку', 'walls-delivery-calc' ) . '</button></p>';
		echo '<div class="wdc-order-delivery-modal" data-wdc-order-delivery-modal hidden>';
		echo '<div class="wdc-order-delivery-modal__overlay" data-wdc-order-delivery-modal-close></div>';
		echo '<div class="wdc-order-delivery-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="wdc-order-delivery-modal-title-' . esc_attr( (string) $order_id ) . '" tabindex="-1">';
		echo '<header class="wdc-order-delivery-modal__header">';
		echo '<h2 id="wdc-order-delivery-modal-title-' . esc_attr( (string) $order_id ) . '">' . esc_html__( 'Пересчет доставки', 'walls-delivery-calc' ) . '</h2>';
		echo '<button type="button" class="button-link wdc-order-delivery-modal__close" data-wdc-order-delivery-modal-close aria-label="' . esc_attr__( 'Закрыть', 'walls-delivery-calc' ) . '">×</button>';
		echo '</header>';
		echo '<div class="wdc-order-delivery-location" data-wdc-order-delivery-location>';
		echo '<div class="wdc-order-delivery-location__summary">';
		echo '<strong>' . esc_html__( 'Населенный пункт', 'walls-delivery-calc' ) . '</strong>';
		echo '<span data-wdc-order-delivery-location-current>' . esc_html( $current_location['label'] ) . '</span>';
		echo '<button type="button" class="button-link" data-wdc-order-delivery-location-edit>' . esc_html__( 'Изменить', 'walls-delivery-calc' ) . '</button>';
		echo '</div>';
		echo '<div class="wdc-order-delivery-location__search" data-wdc-order-delivery-location-search hidden>';
		echo '<label class="screen-reader-text" for="wdc-order-delivery-location-input-' . esc_attr( (string) $order_id ) . '">' . esc_html__( 'Поиск населенного пункта', 'walls-delivery-calc' ) . '</label>';
		echo '<input type="search" class="widefat" id="wdc-order-delivery-location-input-' . esc_attr( (string) $order_id ) . '" data-wdc-order-delivery-location-input value="' . esc_attr( $current_location['label'] ) . '" placeholder="' . esc_attr__( 'Введите населенный пункт', 'walls-delivery-calc' ) . '" autocomplete="off">';
		echo '<div class="wdc-order-delivery-location__results" data-wdc-order-delivery-location-results></div>';
		echo '</div>';
		echo '<button type="button" class="button" data-wdc-order-delivery-modal-preview>' . esc_html__( 'Пересчитать', 'walls-delivery-calc' ) . '</button>';
		echo '<script type="application/json" data-wdc-order-delivery-current-location>' . $this->json_encode( $current_location ) . '</script>';
		echo '<script type="application/json" data-wdc-order-delivery-current-pickup>' . $this->json_encode( $current_pickup ) . '</script>';
		echo '<script type="application/json" data-wdc-order-delivery-current-shipping-address>' . $this->json_encode( $current_shipping_address ) . '</script>';
		echo '</div>';
		echo '<div class="wdc-order-delivery-modal__status" data-wdc-order-delivery-modal-status></div>';
		echo '<div class="wdc-order-delivery-modal__content" data-wdc-order-delivery-modal-content></div>';
		echo '<footer class="wdc-order-delivery-modal__footer">';
		echo '<div class="wdc-order-delivery-modal__save-warning" data-wdc-order-delivery-save-warning hidden></div>';
		echo '<button type="button" class="button" data-wdc-order-delivery-modal-close>' . esc_html__( 'Закрыть', 'walls-delivery-calc' ) . '</button>';
		echo '<button type="button" class="button button-primary" data-wdc-order-delivery-save disabled>' . esc_html__( 'Сохранить новый вариант доставки', 'walls-delivery-calc' ) . '</button>';
		echo '</footer>';
		echo '</div>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * @return array<string,string>
	 */
	private function current_location_payload( object $order ): array {
		$calculation = $this->calculation_data( $order );
		$destination = is_array( $calculation['destination'] ?? null ) ? $calculation['destination'] : array();
		$name = trim( (string) ( $destination['city_display_name'] ?? $destination['display_name'] ?? '' ) );
		if ( '' === $name ) {
			$name = $this->meta_string( $order, '_wdc_platform_city_display_name' );
		}
		if ( '' === $name ) {
			$name = $this->order_string( $order, 'get_shipping_city' );
		}
		$region = trim( (string) ( $destination['region_name'] ?? $this->order_string( $order, 'get_shipping_state' ) ) );
		$postcode = trim( (string) ( $destination['postal_code'] ?? $destination['postcode'] ?? '' ) );
		if ( '' === $postcode ) {
			$postcode = $this->meta_string( $order, '_wdc_platform_city_postcode' ) ?: $this->order_string( $order, 'get_shipping_postcode' );
		}
		$country = strtoupper( trim( (string) ( $destination['country_code'] ?? $this->order_string( $order, 'get_shipping_country' ) ?: 'RU' ) ) );
		$label = $this->location_label_without_region_duplicate( $name, $region );

		return array(
			'id' => '',
			'fias_id' => trim( (string) ( $destination['fias_id'] ?? $this->meta_string( $order, '_wdc_platform_location_fias_id' ) ?: $this->meta_string( $order, '_wdc_platform_city_fias_id' ) ) ),
			'display_name' => $name,
			'city_value' => $name,
			'postal_code' => $postcode,
			'country_code' => '' !== $country ? $country : 'RU',
			'region_name' => $region,
			'state_value' => $region,
			'label' => '' !== $label ? $label : $name,
		);
	}

	private function location_label_without_region_duplicate( string $name, string $region ): string {
		$name = trim( $name );
		$region = trim( $region );
		if ( '' === $name ) {
			return $region;
		}
		if ( '' === $region ) {
			return $name;
		}
		$normalized_name = $this->normalize_location_label_part( $name );
		$normalized_region = $this->normalize_location_label_part( $region );
		if ( '' !== $normalized_region && str_contains( $normalized_name, $normalized_region ) ) {
			return $name;
		}
		return trim( $region . ', ' . $name );
	}

	private function normalize_location_label_part( string $value ): string {
		$value = str_replace( array( 'Ё', 'ё' ), array( 'Е', 'е' ), $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = preg_replace( '/\b(область|обл\.?|край|республика|респ\.?)\b/u', ' ', $value );
		$value = preg_replace( '/[^\p{L}\p{N}]+/u', ' ', is_string( $value ) ? $value : '' );
		return trim( is_string( $value ) ? $value : '' );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function current_pickup_payload( object $order ): array {
		$snapshot = $this->raw_order_meta( $order, '_wdc_pickup_point_snapshot' );
		if ( is_string( $snapshot ) && '' !== trim( $snapshot ) ) {
			$decoded = json_decode( $snapshot, true );
			$snapshot = is_array( $decoded ) ? $decoded : array();
		}
		$snapshot = is_array( $snapshot ) ? $snapshot : array();
		$point_code = $this->meta_string( $order, '_wdc_pickup_point_code' );
		if ( '' === $point_code ) {
			$point_code = $this->meta_string( $order, '_wdc_platform_pickup_code' );
		}
		$point_address = $this->meta_string( $order, '_wdc_pickup_point_address' );
		if ( '' === $point_address ) {
			$point_address = $this->meta_string( $order, '_wdc_platform_pickup_address' );
		}

		return array_filter(
			array(
				'point_code' => $point_code,
				'point_type' => $this->meta_string( $order, '_wdc_pickup_point_type' ),
				'point_name' => (string) ( $snapshot['point_name'] ?? $snapshot['name'] ?? '' ),
				'point_address' => $point_address,
				'point_postcode' => $this->meta_string( $order, '_wdc_pickup_point_postcode' ),
				'point_raw' => $snapshot,
			),
			static fn( mixed $value ): bool => array() !== $value && '' !== $value
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function current_shipping_address_payload( object $order ): array {
		$address_1 = $this->order_string( $order, 'get_shipping_address_1' );
		$address_2 = $this->order_string( $order, 'get_shipping_address_2' );
		$city = $this->order_string( $order, 'get_shipping_city' );
		$region = $this->order_string( $order, 'get_shipping_state' );
		$postcode = $this->order_string( $order, 'get_shipping_postcode' );
		return array(
			'country' => $this->order_string( $order, 'get_shipping_country' ) ?: 'RU',
			'region' => $region,
			'city' => $city,
			'postcode' => $postcode,
			'address_1' => $address_1,
			'address_2' => $address_2,
			'full_address' => trim( implode( ', ', array_filter( array( $postcode, $region, $city, $address_1, $address_2 ), static fn( string $part ): bool => '' !== trim( $part ) ) ) ),
		);
	}

	/**
	 * @param array<string,mixed> $value
	 */
	private function json_encode( array $value ): string {
		$encoded = json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT );
		return false === $encoded ? '{}' : (string) $encoded;
	}

	private function has_blocking_shipment( object $order ): bool {
		$repository = $this->shipments ?? new OrderShipmentRepository();
		foreach ( $repository->all_for_order( $order ) as $shipment ) {
			if ( ! is_array( $shipment ) ) {
				continue;
			}
			$status = (string) ( $shipment['status'] ?? '' );
			$tracking = trim( (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
			$backlog_order_id = trim( (string) ( $shipment['backlog_order_id'] ?? '' ) );
			if ( in_array( $status, array( 'created', 'registered' ), true ) || '' !== $tracking || '' !== $backlog_order_id ) {
				return true;
			}
		}

		return false;
	}

	private function resolve_order( mixed $post_or_order ): ?object {
		if ( is_object( $post_or_order ) && method_exists( $post_or_order, 'get_meta' ) ) {
			return $post_or_order;
		}

		$order_id = 0;
		if ( is_object( $post_or_order ) && isset( $post_or_order->ID ) ) {
			$order_id = (int) $post_or_order->ID;
		} elseif ( is_numeric( $post_or_order ) ) {
			$order_id = (int) $post_or_order;
		}

		if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
			return is_object( $order ) && method_exists( $order, 'get_meta' ) ? $order : null;
		}

		return null;
	}

	private function has_wdc_meta( object $order ): bool {
		foreach ( self::META_KEYS as $key ) {
			$value = $this->raw_order_meta( $order, $key );
			if ( is_array( $value ) && array() !== $value ) {
				return true;
			}
			if ( ! is_array( $value ) && '' !== trim( (string) $value ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function calculation_data( object $order ): array {
		$value = $this->raw_order_meta( $order, OrderShippingMetaPersister::CALCULATION_META_KEY );
		if ( is_array( $value ) ) {
			return $value;
		}

		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$decoded = json_decode( $value, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		return array();
	}

	/**
	 * @return array<string,string>
	 */
	private function legacy_rows( object $order ): array {
		$delivery_type = $this->order_meta( $order, '_wdc_platform_delivery_type' );
		$rows          = array(
			'Перевозчик' => $this->order_meta( $order, '_wdc_platform_carrier_key' ),
			'Способ доставки' => $this->order_meta( $order, '_wdc_platform_rate_id' ),
			'Тип доставки' => $this->delivery_type_label( $delivery_type ),
			'Срок доставки' => $this->order_meta( $order, '_wdc_platform_planned_delivery_comment' ),
			'Населенный пункт' => $this->city_summary( $order ),
			'Источник населенного пункта' => $this->city_source_label( $order ),
			'Индекс населенного пункта' => $this->order_meta( $order, '_wdc_platform_city_postcode' ),
			'Нормализация адреса' => $this->normalization_label( $order ),
			'Индекс' => $this->order_meta( $order, '_wdc_platform_resolved_postcode' ),
			'FIAS ID' => $this->order_meta( $order, '_wdc_platform_fias_id' ),
		);

		if ( 'pickup' === $delivery_type ) {
			$rows['Код ПВЗ']      = $this->order_meta( $order, '_wdc_platform_pickup_code' );
			$rows['Адрес ПВЗ']    = $this->order_meta( $order, '_wdc_platform_pickup_address' );
			$rows['Режим работы'] = $this->order_meta( $order, '_wdc_platform_pickup_work_time' );
			$rows['Комментарий']  = $this->order_meta( $order, '_wdc_platform_pickup_comment' );
			$rows['ID ПВЗ']       = $this->order_meta( $order, '_wdc_pickup_point_id' );
			$rows['Индекс ПВЗ']   = $this->order_meta( $order, '_wdc_pickup_point_postcode' );
			$rows['Тип ПВЗ']      = $this->order_meta( $order, '_wdc_pickup_point_type' );
		} elseif ( 'courier' === $delivery_type ) {
			$rows['Адрес доставки'] = $this->shipping_address( $order );
		}

		return $rows;
	}

	private function raw_order_meta( object $order, string $key ): mixed {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}

		return $order->get_meta( $key, true );
	}

	private function order_meta( object $order, string $key ): string {
		$value = $this->raw_order_meta( $order, $key );
		if ( is_array( $value ) || is_object( $value ) ) {
			return '';
		}

		return (string) $value;
	}

	private function meta_string( object $order, string $key ): string {
		return trim( $this->order_meta( $order, $key ) );
	}

	private function order_string( object $order, string $method ): string {
		return method_exists( $order, $method ) ? trim( (string) $order->{$method}() ) : '';
	}

	private function delivery_type_label( string $delivery_type ): string {
		return match ( $delivery_type ) {
			'pickup' => 'Пункт выдачи',
			'courier' => 'Курьер',
			default => $delivery_type,
		};
	}

	private function country_label( array $destination ): string {
		$name = trim( (string) ( $destination['country_name'] ?? '' ) );
		$code = trim( (string) ( $destination['country_code'] ?? '' ) );

		return trim( $name . ( '' !== $code ? ' (' . $code . ')' : '' ) );
	}

	/**
	 * @param array<string,mixed> $destination
	 */
	private function should_show_country( array $destination ): bool {
		$code = strtoupper( trim( (string) ( $destination['country_code'] ?? '' ) ) );
		return '' !== $code && 'RU' !== $code;
	}

	private function grams( mixed $value ): string {
		return is_numeric( $value ) ? (string) (int) $value . ' г' : '';
	}

	private function rubles( mixed $value ): string {
		if ( ! is_numeric( $value ) ) {
			return '';
		}

		$formatted = number_format( (float) $value, 2, '.', ' ' );
		$formatted = str_ends_with( $formatted, '.00' ) ? substr( $formatted, 0, -3 ) : $formatted;

		return $formatted . ' руб.';
	}

	/**
	 * @param array<string,mixed> $result
	 */
	private function delivery_days_label( array $result ): string {
		return (string) ( $result['final_delivery_text'] ?? '' )
			?: DeliveryDaysFormatter::format_values( $result['final_delivery_min_days'] ?? $result['final_delivery_days_min'] ?? null, $result['final_delivery_max_days'] ?? $result['final_delivery_days_max'] ?? null );
	}

	/**
	 * @param array<string,mixed> $api
	 */
	private function api_delivery_days_label( array $api ): string {
		return (string) ( $api['api_delivery_text'] ?? '' )
			?: DeliveryDaysFormatter::format_values( $api['api_delivery_min_days'] ?? $api['delivery_min_days'] ?? null, $api['api_delivery_max_days'] ?? $api['delivery_max_days'] ?? null );
	}

	private function city_summary( object $order ): string {
		$city = $this->order_meta( $order, '_wdc_platform_city_display_name' );
		if ( '' !== $city ) {
			return $city;
		}

		$city     = $this->order_meta( $order, '_wdc_platform_fallback_city' );
		$postcode = $this->order_meta( $order, '_wdc_platform_city_postcode' );
		if ( '' === $postcode ) {
			$postcode = $this->order_meta( $order, '_wdc_platform_resolved_postcode' );
		}
		if ( '' === $city && method_exists( $order, 'get_shipping_city' ) ) {
			$city = trim( (string) $order->get_shipping_city() );
		}

		return trim( $city . ( '' !== $postcode ? ' / ' . $postcode : '' ), ' /' );
	}

	private function city_source_label( object $order ): string {
		return match ( $this->order_meta( $order, '_wdc_platform_city_source' ) ) {
			'local_db' => 'справочник плагина',
			'manual' => 'введено вручную',
			default => '',
		};
	}

	private function normalization_label( object $order ): string {
		$normalized = $this->order_meta( $order, '_wdc_platform_normalized' );
		$source     = $this->order_meta( $order, '_wdc_platform_normalization_source' );
		$fias_id    = $this->order_meta( $order, '_wdc_platform_fias_id' );
		if ( '' === $fias_id ) {
			$fias_id = $this->order_meta( $order, '_wdc_platform_city_fias_id' );
		}
		$gar_id = $this->order_meta( $order, '_wdc_platform_city_gar_id' );

		if ( '1' === $normalized || 'true' === $normalized ) {
			$label = match ( $source ) {
				'fias', 'gar' => 'ФИАС/ГАР',
				'dadata' => 'DaData',
				default => $source,
			};
			$ids = trim( $fias_id . ( '' !== $gar_id ? ' / ' . $gar_id : '' ), ' /' );

			return trim( $label . ( '' !== $ids ? ': ' . $ids : '' ) );
		}

		if ( 'fallback' === $source ) {
			return 'fallback';
		}

		return '' !== $source ? $source : 'не выполнялась';
	}

	private function shipping_address( object $order ): string {
		$parts = array();
		foreach ( array( 'get_shipping_address_1', 'get_shipping_address_2', 'get_shipping_city', 'get_shipping_postcode', 'get_shipping_country' ) as $method ) {
			if ( method_exists( $order, $method ) ) {
				$value = trim( (string) $order->{$method}() );
				if ( '' !== $value ) {
					$parts[] = $value;
				}
			}
		}

		return implode( ', ', $parts );
	}
}
