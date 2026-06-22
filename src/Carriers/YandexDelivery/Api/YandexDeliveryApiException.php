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
}

