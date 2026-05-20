<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Runtime;

use Throwable;
use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

final class CarrierExecutionGuard {
	public function __construct(
		private CheckoutLogger $logger,
		private int $timeout_seconds = 5
	) {
	}

	/**
	 * @param array<string,string> $carrier_errors
	 */
	public function quote( CarrierAdapterInterface $adapter, QuoteRequest $request, array &$carrier_errors ): DeliveryQuote {
		$key     = $adapter->get_identity()->key;
		$started = microtime( true );

		$this->logger->info( 'Carrier quote started.', array( 'carrier' => $key ) );

		try {
			$quote   = $adapter->quote( $request );
			$elapsed = microtime( true ) - $started;

			if ( $elapsed > $this->timeout_seconds ) {
				$carrier_errors[ $key ] = 'Carrier timeout threshold exceeded.';
				$this->logger->warning( 'Carrier quote exceeded timeout threshold.', array( 'carrier' => $key, 'elapsed' => round( $elapsed, 3 ) ) );
			}

			$this->logger->info( 'Carrier quote finished.', array( 'carrier' => $key, 'rates_count' => count( $quote->rates ) ) );

			return $quote;
		} catch ( Throwable $exception ) {
			$carrier_errors[ $key ] = $exception->getMessage();
			$this->logger->warning( 'Carrier quote failed.', array( 'carrier' => $key, 'error' => $exception->getMessage() ) );

			return new DeliveryQuote( $key . '-error', $key, $request->destination, $request->package, array(), false, 'carrier_error', $exception->getMessage(), false, 'manual' );
		}
	}
}
