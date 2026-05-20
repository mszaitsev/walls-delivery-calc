<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Contracts;

use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

interface CarrierAdapterInterface {
	public function get_identity(): CarrierIdentity;

	public function get_capabilities(): CarrierCapabilities;

	public function supports_country( string $countryCode ): bool;

	public function quote( QuoteRequest $request ): DeliveryQuote;
}
