<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Contracts;

use WallsShop\WDC\Domain\Quote\DeliveryRate;

defined( 'ABSPATH' ) || exit;

interface CarrierCustomerCommentProviderInterface {
	/**
	 * @return array<int,string>
	 */
	public function customer_comments( DeliveryRate $rate ): array;
}
