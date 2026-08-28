<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Api;
defined( 'ABSPATH' ) || exit;
final class OzonDeliveryApiException extends \RuntimeException {
	public function __construct( public readonly string $operation, public readonly string $safe_code, public readonly int $http_status, public readonly bool $retryable, string $message ) { parent::__construct( $message ); }
}
