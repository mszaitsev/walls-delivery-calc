<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Api;
defined( 'ABSPATH' ) || exit;
final class OzonDeliveryApiException extends \RuntimeException {
	/** @param array<string,scalar|array<int,string>> $metadata */
	public function __construct( public readonly string $operation, public readonly string $safe_code, public readonly int $http_status, public readonly bool $retryable, string $message, public readonly array $metadata = array() ) { parent::__construct( $message ); }
}
