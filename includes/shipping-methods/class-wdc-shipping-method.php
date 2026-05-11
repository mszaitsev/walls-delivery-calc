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
		$this->add_rate(
			array(
				'id'    => $this->get_rate_id(),
				'label' => __( 'Расчет доставки', 'walls-delivery-calc' ),
				'cost'  => 0,
			)
		);
	}
}
