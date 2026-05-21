<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Admin;

defined( 'ABSPATH' ) || exit;

final class OrderDeliveryMetabox {
	private const META_KEYS = array(
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

		echo '<table class="widefat striped wdc-platform-order-delivery"><tbody>';
		foreach ( $this->rows( $order ) as $label => $value ) {
			if ( '' === trim( $value ) ) {
				continue;
			}

			echo '<tr><th style="width:45%;text-align:left;">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
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
			if ( '' !== trim( $this->order_meta( $order, $key ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return array<string,string>
	 */
	private function rows( object $order ): array {
		$delivery_type = $this->order_meta( $order, '_wdc_platform_delivery_type' );
		$rows          = array(
			'Перевозчик'             => $this->order_meta( $order, '_wdc_platform_carrier_key' ),
			'Способ доставки'        => $this->order_meta( $order, '_wdc_platform_rate_id' ),
			'Тип доставки'           => $this->delivery_type_label( $delivery_type ),
			'Срок доставки'          => $this->order_meta( $order, '_wdc_platform_planned_delivery_comment' ),
			'Населенный пункт' => $this->city_summary( $order ),
			'Источник населенного пункта' => $this->city_source_label( $order ),
			'Индекс населенного пункта' => $this->order_meta( $order, '_wdc_platform_city_postcode' ),
			'Нормализация адреса' => $this->normalization_label( $order ),
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

	private function order_meta( object $order, string $key ): string {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return '';
		}

		$value = $order->get_meta( $key, true );
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
		$fias_id    = $this->order_meta( $order, '_wdc_platform_city_fias_id' );
		$gar_id     = $this->order_meta( $order, '_wdc_platform_city_gar_id' );

		if ( '1' === $normalized || 'true' === $normalized ) {
			$label = in_array( $source, array( 'fias', 'gar' ), true ) ? 'ФИАС/ГАР' : $source;
			$ids   = trim( $fias_id . ( '' !== $gar_id ? ' / ' . $gar_id : '' ), ' /' );

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
