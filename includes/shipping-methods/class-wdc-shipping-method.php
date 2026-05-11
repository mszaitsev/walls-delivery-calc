<?php
/**
 * WooCommerce shipping method.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Shipping_Method extends WC_Shipping_Method {
	private WDC_Logger $logger;

	private WDC_Settings $settings;

	public function __construct( int $instance_id = 0 ) {
		$this->id                 = 'wdc_dynamic_delivery';
		$this->instance_id        = absint( $instance_id );
		$this->method_title       = __( 'Walls Delivery Calc', 'walls-delivery-calc' );
		$this->method_description = __( 'Расчет стоимости доставки через внешние API.', 'walls-delivery-calc' );
		$this->supports           = array(
			'shipping-zones',
			'instance-settings',
		);
		$this->logger             = new WDC_Logger();
		$this->settings           = new WDC_Settings();

		$this->init();
	}

	public function init(): void {
		$this->title = __( 'Walls Delivery Calc', 'walls-delivery-calc' );
	}

	/**
	 * @param array<string, mixed> $package WooCommerce package data.
	 */
	public function calculate_shipping( $package = array() ) {
		$carrier = new WDC_Russian_Post_Carrier( $this->settings, null, null, null, null, $this->logger );
		$quote = $carrier->get_quote( is_array( $package ) ? $package : array() );

		if ( empty( $quote['success'] ) ) {
			$this->debug_log( 'Shipping quote success=false.', array( 'quote' => $quote ) );
			return;
		}

		if ( empty( $quote['rates'] ) || ! is_array( $quote['rates'] ) ) {
			$this->debug_log( 'Shipping quote returned no rates.', array( 'quote' => $quote ) );
			return;
		}

		$this->add_quote_rates( $quote );
	}

	/**
	 * Add one WooCommerce rate per normalized quote rate.
	 *
	 * @param array<string, mixed> $quote Normalized carrier quote.
	 */
	protected function add_quote_rates( array $quote ): bool {
		if ( empty( $quote['rates'] ) || ! is_array( $quote['rates'] ) ) {
			$this->debug_log( 'No rates returned for WooCommerce shipping method.', array( 'quote' => $quote ) );
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

		if ( ! $added ) {
			$this->debug_log( 'No valid WooCommerce rates were added.', array( 'quote' => $quote ) );
		}

		return $added;
	}

	/**
	 * @param array<string, mixed> $context Debug context.
	 */
	private function debug_log( string $message, array $context = array() ): void {
		$settings = $this->settings->get();
		if ( 'yes' === $settings['debug_enabled'] ) {
			$this->logger->log( 'debug', $message, $context );
		}
	}
}
