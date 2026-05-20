<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

final class NewShippingMethod {
	public function __construct(
		private CheckoutOrchestrator $orchestrator,
		private WooCommerceRateMapper $mapper
	) {
	}

	/**
	 * Skeleton only. Not registered in production runtime yet.
	 *
	 * @param array<string,mixed> $package
	 * @return array<int,array<string,mixed>>
	 */
	public function calculate_shipping( array $package = array() ): array {
		$country = (string) ( $package['destination']['country'] ?? 'RU' );
		$city    = (string) ( $package['destination']['city'] ?? '' );
		$total   = Money::from_rubles( (float) ( $package['contents_cost'] ?? 0 ) );

		$request = new QuoteRequest(
			$country,
			new Address( country_code: $country, city: $city ),
			new Package( array(), $total, $total, 0, 0, 0, null, null, null, null, 'cart' ),
			'',
			$total,
			date( 'Y-m-d' )
		);

		return array_map( array( $this->mapper, 'map' ), $this->orchestrator->calculate_rates( $request ) );
	}
}
