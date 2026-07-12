<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Api;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryApiException extends RuntimeException {
	/** @var array<string,mixed> */
	private array $details;

	/** @param array<string,mixed> $details */
	public function __construct( string $message, array $details = array(), int $code = 0, ?\Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );
		$this->details = $details;
	}

	/** @return array<string,mixed> */
	public function details(): array {
		return $this->details;
	}

	public function http_code(): int {
		return (int) ( $this->details['http_code'] ?? 0 );
	}

	public function error_body(): string {
		$response = $this->details['response'] ?? null;
		if ( is_array( $response ) && isset( $response['_raw'] ) ) {
			return (string) $response['_raw'];
		}

		return is_string( $this->details['error_body'] ?? null ) ? (string) $this->details['error_body'] : '';
	}

	/** @return array<string,mixed> */
	public function decoded_response(): array {
		$response = $this->details['response'] ?? null;

		return is_array( $response ) ? $response : array();
	}
}

