<?php
/**
 * Plugin logging wrapper.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Logger {
	private const SOURCE = 'walls-delivery-calc';

	/**
	 * @param string               $level WooCommerce log level.
	 * @param string               $message Log message.
	 * @param array<string, mixed> $context Extra context.
	 */
	public function log( string $level, string $message, array $context = array() ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			$logger = wc_get_logger();
			$context = array_merge(
				array(
					'source' => self::SOURCE,
				),
				$context
			);

			$logger->log( sanitize_key( $level ), sanitize_text_field( $message ), $context );
		}
	}
}
