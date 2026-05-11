<?php
/**
 * WooCommerce shipping method.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Shipping_Method extends WC_Shipping_Method {
	public function __construct( int $instance_id = 0 ) {
		$this->id                 = 'wdc_dynamic_delivery';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Walls Delivery Calc', 'walls-delivery-calc' );
		$this->method_description = __( 'Расчет стоимости доставки через внешние API.', 'walls-delivery-calc' );
		$this->supports           = array(
			'shipping-zones',
			'instance-settings',
		);

		$this->init();
	}

	public function init(): void {
		$this->title = __( 'Walls Delivery Calc', 'walls-delivery-calc' );
	}

	/**
	 * @param array<string, mixed> $package WooCommerce package data.
	 */
	public function calculate_shipping( $package = array() ) {
		unset( $package );

		$this->add_rate(
			array(
				'id'    => $this->get_rate_id(),
				'label' => __( 'Расчет доставки', 'walls-delivery-calc' ),
				'cost'  => 0,
			)
		);
	}

	/**
	 * Future helper for adding one WooCommerce rate per normalized quote rate.
	 *
	 * @param array<string, mixed> $quote Normalized carrier quote.
	 */
	protected function add_quote_rates( array $quote ): void {
		if ( empty( $quote['rates'] ) || ! is_array( $quote['rates'] ) ) {
			return;
		}

		foreach ( $quote['rates'] as $rate ) {
			if ( ! is_array( $rate ) ) {
				continue;
			}

			$rate_id = isset( $rate['rate_id'] ) ? sanitize_key( (string) $rate['rate_id'] ) : '';
			if ( '' === $rate_id ) {
				continue;
			}

			$this->add_rate(
				array(
					'id'    => $this->get_rate_id( $rate_id ),
					'label' => isset( $rate['rate_title'] ) ? sanitize_text_field( (string) $rate['rate_title'] ) : '',
					'cost'  => isset( $rate['price'] ) ? (float) $rate['price'] : 0,
				)
			);
		}
	}
}
