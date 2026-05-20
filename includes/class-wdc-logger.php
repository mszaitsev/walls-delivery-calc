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
	 * @param mixed $level WooCommerce log level.
	 * @param mixed $message Log message.
	 * @param mixed $context Extra context.
	 */
	public function log( $level, $message, $context = array() ): void {
		$level = is_scalar( $level ) ? sanitize_key( (string) $level ) : 'info';
		$level = '' !== $level ? $level : 'info';
		$message = $this->normalize_message( $message );
		$context = is_array( $context ) ? $context : array( 'context' => $this->normalize_message( $context ) );

		try {
			if ( function_exists( 'wc_get_logger' ) ) {
				$logger = wc_get_logger();
				$logger->log(
					$level,
					$message,
					array_merge(
						array(
							'source' => self::SOURCE,
						),
						$context
					)
				);

				return;
			}
		} catch ( Throwable $exception ) {
			$message .= ' Logger error: ' . $exception->getMessage();
		}

		error_log( '[' . self::SOURCE . '] [' . $level . '] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}

	private function normalize_message( $message ): string {
		if ( is_scalar( $message ) || null === $message ) {
			return sanitize_text_field( (string) $message );
		}

		if ( function_exists( 'wp_json_encode' ) ) {
			$encoded = wp_json_encode( $message );
		} else {
			$encoded = json_encode( $message );
		}

		return is_string( $encoded ) ? sanitize_text_field( $encoded ) : 'Unloggable message';
	}
}
