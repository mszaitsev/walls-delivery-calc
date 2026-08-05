<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Contracts;

use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

interface CarrierQuoteCacheContextProviderInterface {
	/**
	 * @return array<string,mixed>
	 */
	public function quote_cache_context( QuoteRequest $request ): array;
}
