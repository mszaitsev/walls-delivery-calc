<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\DaData;

use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class DaDataLogger {
	private const SENSITIVE_KEYS = array( 'address', 'raw_address', 'token', 'secret', 'authorization', 'x-secret', 'email', 'phone', 'name' );

	public function __construct( private Logger $logger ) {
	}

	public function request_start( array $context = array() ): void {
		$this->logger->debug( 'DaData request started.', $this->context( $context ) );
	}

	public function response_status( int $status_code, array $context = array() ): void {
		$context['status_code'] = $status_code;
		$this->logger->debug( 'DaData response received.', $this->context( $context ) );
	}

	public function parse_error( array $context = array() ): void {
		$this->logger->warning( 'DaData response parse failed.', $this->context( $context ) );
	}

	public function timeout( array $context = array() ): void {
		$this->logger->warning( 'DaData request timed out.', $this->context( $context ) );
	}

	public function failure( array $context = array() ): void {
		$this->logger->warning( 'DaData request failed.', $this->context( $context ) );
	}

	private function context( array $context ): array {
		foreach ( self::SENSITIVE_KEYS as $key ) {
			if ( array_key_exists( $key, $context ) ) {
				$context[ $key ] = '[redacted]';
			}
		}

		return $context;
	}
}
