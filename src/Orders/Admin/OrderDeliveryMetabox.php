<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Admin;

use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;

defined( 'ABSPATH' ) || exit;

final class OrderDeliveryMetabox {
	private const META_KEYS = array(
		OrderShippingMetaPersister::CALCULATION_META_KEY,
		'_wdc_platform_carrier_key',
		'_wdc_platform_rate_id',
		'_wdc_platform_delivery_type',
	);

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
			return;
		}

		$calculation = $this->calculation_data( $order );
		if ( array() !== $calculation ) {
			$this->render_calculation_data( $calculation );
			return;
		}

		$this->render_rows( $this->legacy_rows( $order ) );
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
			'Способ доставки' => $this->shipping_method_label( $calculation ),
			'Страна назначения' => $this->country_label( $destination ),
			'Вес товаров' => $this->grams( $package['products_weight_g'] ?? null ),
			'Вес упаковки' => $this->grams( $package['packaging_weight_g'] ?? null ),
			'Итоговый вес для API' => $this->grams( $package['final_weight_g'] ?? null ),
		);

		if ( array() !== $pickup ) {
			$rows['Код ПВЗ']   = (string) ( $pickup['point_code'] ?? '' );
			$rows['Адрес ПВЗ'] = (string) ( $pickup['point_address'] ?? '' );
		}

		if ( $fallback ) {
			$rows['Fallback reason'] = (string) ( $result['fallback_reason'] ?? '' );
			$rows['Fallback text']   = (string) ( $result['fallback_text'] ?? '' );
		} else {
			$rows['Базовая стоимость API'] = $this->rubles( $api['api_base_price_rub'] ?? null );
			$rows['НДС'] = $this->vat_label( $api );
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

	private function delivery_type_label( string $delivery_type ): string {
		return match ( $delivery_type ) {
			'pickup' => 'Пункт выдачи',
			'courier' => 'Курьер',
			default => $delivery_type,
		};
	}

	/**
	 * @param array<string,mixed> $calculation
	 */
	private function shipping_method_label( array $calculation ): string {
		if ( 'russian_post_worldwide_parcel' === (string) ( $calculation['service_key'] ?? '' ) ) {
			return 'международная доставка Почтой России';
		}

		return (string) ( $calculation['rate_id'] ?? '' );
	}

	private function country_label( array $destination ): string {
		$name = trim( (string) ( $destination['country_name'] ?? '' ) );
		$code = trim( (string) ( $destination['country_code'] ?? '' ) );

		return trim( $name . ( '' !== $code ? ' (' . $code . ')' : '' ) );
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
	 * @param array<string,mixed> $api
	 */
	private function vat_label( array $api ): string {
		if ( ! array_key_exists( 'api_price_has_vat', $api ) && ! array_key_exists( 'vat_rate', $api ) ) {
			return '';
		}

		$mode = ! empty( $api['api_price_has_vat'] ) ? 'включен' : 'добавлен';
		$rate = is_numeric( $api['vat_rate'] ?? null ) ? ', ставка ' . (string) $api['vat_rate'] : '';

		return $mode . $rate;
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
