<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Fias;

use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class FiasLogger {
	private const SENSITIVE_KEYS = array( 'address', 'raw_address', 'email', 'phone', 'tel', 'customer_email', 'customer_phone' );

	public function __construct( private Logger $logger ) {
	}

	public function request_start( string $operation, array $context = array() ): void {
		$this->logger->debug( 'FIAS request started.', $this->context( $operation, $context ) );
	}

	public function timeout( string $operation, array $context = array() ): void {
		$this->logger->warning( 'FIAS request timed out.', $this->context( $operation, $context ) );
	}

	public function limiter_block( string $operation, array $context = array() ): void {
		$this->logger->warning( 'FIAS rate limiter blocked request.', $this->context( $operation, $context ) );
	}

	public function fallback_used( string $operation, array $context = array() ): void {
		$this->logger->info( 'FIAS fallback path used.', $this->context( $operation, $context ) );
	}

	public function parse_error( string $operation, array $context = array() ): void {
		$this->logger->warning( 'FIAS response parse failed.', $this->context( $operation, $context ) );
	}

	public function response_status( string $operation, int $status_code, array $context = array() ): void {
		$context['status_code'] = $status_code;
		$this->logger->debug( 'FIAS response received.', $this->context( $operation, $context ) );
	}

	private function context( string $operation, array $context ): array {
		$context['operation'] = $operation;
		foreach ( self::SENSITIVE_KEYS as $key ) {
			if ( array_key_exists( $key, $context ) ) {
				$context[ $key ] = '[redacted]';
			}
		}

		return $context;
	}
}
