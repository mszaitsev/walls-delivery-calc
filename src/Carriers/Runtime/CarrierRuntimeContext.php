<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Runtime;

defined( 'ABSPATH' ) || exit;

final class CarrierRuntimeContext {
	public function __construct(
		public readonly string $carrier_key,
		public readonly int $request_started_at,
		public readonly int $timeout_seconds,
		public readonly bool $cache_enabled,
		public readonly bool $debug_enabled
	) {
	}
}
