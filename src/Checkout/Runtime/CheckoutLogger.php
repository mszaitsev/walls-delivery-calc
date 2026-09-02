<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Runtime;

use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class CheckoutLogger {
	public function __construct(
		private ?Logger $logger = null
	) {
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->write( 'debug', $message, $context );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function info( string $message, array $context = array() ): void {
		$this->write( 'info', $message, $context );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->write( 'warning', $message, $context );
	}

	/**
	 * @param array<string,mixed> $context
	 */
	private function write( string $level, string $message, array $context ): void {
		$context = $this->without_pii( $context );

		if ( $this->logger instanceof Logger ) {
			$this->logger->{$level}( $message, $context );
			return;
		}

		if ( function_exists( 'error_log' ) ) {
			error_log( '[wdc-checkout] ' . $level . ': ' . $message );
		}
	}

	/**
	 * @param array<string,mixed> $context
	 * @return array<string,mixed>
	 */
	private function without_pii( array $context ): array {
		unset( $context['city'], $context['address'], $context['postcode'], $context['customer'] );

		return $context;
	}
}
