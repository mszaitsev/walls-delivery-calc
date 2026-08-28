<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Api;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryToken {
	/** @param array<int,string> $scope */
	public function __construct(
		public readonly string $access_token,
		public readonly ?int $expires_at,
		public readonly array $scope,
		public readonly string $token_type
	) {}
}
