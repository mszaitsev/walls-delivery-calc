<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Api;

defined( 'ABSPATH' ) || exit;

final class JetLogisticApiException extends \RuntimeException {
	/** @param array<string,mixed> $context */
	public function __construct( string $message, private array $context = array(), int $code = 0, ?\Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );
	}

	/** @return array<string,mixed> */
	public function context(): array {
		return $this->context;
	}

	public function error_code(): string {
		return (string) ( $this->context['error_code'] ?? 'jet_api_error' );
	}
}
