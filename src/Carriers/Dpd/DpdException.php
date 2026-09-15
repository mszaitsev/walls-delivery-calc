<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class DpdException extends RuntimeException {
	/**
	 * @param array<string,mixed> $context
	 */
	public function __construct( string $message, public readonly array $context = array(), int $code = 0, ?\Throwable $previous = null ) {
		parent::__construct( $message, $code, $previous );
	}
}

