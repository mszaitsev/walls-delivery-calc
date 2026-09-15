<?php
declare(strict_types=1);

namespace WallsShop\WDC\Infrastructure\Logging;

defined( 'ABSPATH' ) || exit;

final class LogRedactor {
	/** @var array<int, string> */
	private array $sensitive_keys = array(
		'password',
		'token',
		'secret',
		'api_key',
		'client_key',
		'clientkey',
		'authorization',
		'bearer',
		'phone',
		'email',
		'address',
	);

	/**
	 * @param array<string, mixed> $context
	 * @return array<string, mixed>
	 */
	public function redact_context( array $context ): array {
		return $this->redact_array( $context );
	}

	/**
	 * @param array<string|int, mixed> $data
	 * @return array<string|int, mixed>
	 */
	private function redact_array( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_string( $key ) && $this->is_sensitive_key( $key ) ) {
				$data[ $key ] = '[redacted]';
				continue;
			}

			if ( is_array( $value ) ) {
				$data[ $key ] = $this->redact_array( $value );
			}
		}

		return $data;
	}

	private function is_sensitive_key( string $key ): bool {
		$normalized = strtolower( str_replace( array( '-', ' ' ), '_', $key ) );

		foreach ( $this->sensitive_keys as $sensitive_key ) {
			if ( false !== strpos( $normalized, $sensitive_key ) ) {
				return true;
			}
		}

		return false;
	}
}
