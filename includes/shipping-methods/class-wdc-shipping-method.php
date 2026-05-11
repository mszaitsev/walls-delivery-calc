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
		$carrier = new WDC_Russian_Post_Carrier();
		$quote = $carrier->get_quote( is_array( $package ) ? $package : array() );

		if ( ! empty( $quote['success'] ) && $this->add_quote_rates( $quote ) ) {
			return;
		}

		$this->add_fallback_rate();
	}

	/**
	 * Add one WooCommerce rate per normalized quote rate.
	 *
	 * @param array<string, mixed> $quote Normalized carrier quote.
	 */
	protected function add_quote_rates( array $quote ): bool {
		if ( empty( $quote['rates'] ) || ! is_array( $quote['rates'] ) ) {
			return false;
		}

		$added = false;
		$destination_country = isset( $quote['destination']['country'] ) ? (string) $quote['destination']['country'] : '';

		foreach ( $quote['rates'] as $rate ) {
			if ( ! is_array( $rate ) ) {
				continue;
			}

			$rate_id = isset( $rate['rate_id'] ) ? sanitize_key( (string) $rate['rate_id'] ) : '';
			if ( '' === $rate_id ) {
				continue;
			}

			$label = isset( $rate['label_template'] ) && '' !== (string) $rate['label_template']
				? (string) $rate['label_template']
				: (string) ( $rate['rate_title'] ?? '' );
			$label = str_replace( '{country}', $destination_country, $label );

			$this->add_rate(
				array(
					'id'    => $this->get_rate_id( $rate_id ),
					'label' => sanitize_text_field( $label ),
					'cost'  => isset( $rate['price'] ) ? (float) $rate['price'] : 0,
				)
			);
			$added = true;
		}

		return $added;
	}

	private function add_fallback_rate(): void {
		$quote = ( new WDC_Quote_Normalizer() )->create_fallback_quote();
		$this->add_quote_rates( $quote );
	}
}
