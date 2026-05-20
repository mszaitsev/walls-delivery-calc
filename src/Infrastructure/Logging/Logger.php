<?php
declare(strict_types=1);

namespace WallsShop\WDC\Infrastructure\Logging;

defined( 'ABSPATH' ) || exit;

final class Logger {
	private const SOURCE = 'walls-delivery-calc';

	private LogRedactor $redactor;

	public function __construct( ?LogRedactor $redactor = null ) {
		$this->redactor = $redactor ?? new LogRedactor();
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function debug( string $message, array $context = array() ): void {
		$this->log( 'debug', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function info( string $message, array $context = array() ): void {
		$this->log( 'info', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function warning( string $message, array $context = array() ): void {
		$this->log( 'warning', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context );
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function log( string $level, string $message, array $context ): void {
		$context = $this->redactor->redact_context( $context );
		$context['source'] = self::SOURCE;

		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, $context );
			return;
		}

		error_log( sprintf( '[%s] %s: %s', self::SOURCE, $level, $message ) );
	}
}
