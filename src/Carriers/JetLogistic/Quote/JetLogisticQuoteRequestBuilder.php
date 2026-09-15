<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Quote;

use WallsShop\WDC\Carriers\JetLogistic\JetLogisticCredentials;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

final class JetLogisticQuoteRequestBuilder {
	private const SDOC_THRESHOLD_RUB = 20000;

	public function __construct( private JetLogisticCredentials $credentials ) {
	}

	/** @param array<string,mixed> $origin @param array<string,mixed> $destination @return array<string,mixed> */
	public function build( QuoteRequest $request, array $origin, array $destination ): array {
		$cost = $this->discounted_goods_cost_rub( $request );
		$dops = array(
			'D_HARDPACK' => 0,
			'D_EP' => 0,
			'D_PB' => 0,
			'D_VPP' => 0,
			'D_SP' => 0,
			'D_SDOC' => $cost >= self::SDOC_THRESHOLD_RUB ? 1 : 0,
			'D_EK' => 0,
		);

		return array(
			'access_token' => $this->credentials->access_token(),
			'cityfrom' => (string) ( $origin['source_city'] ?? '' ),
			'cityto' => (string) ( $destination['source_city'] ?? '' ),
			'ves' => $this->kg( $request->package->get_total_weight_g() ),
			'obm3' => $this->m3( $request->package->get_total_volume_cm3() ),
			'dlina' => $this->max_side_m( $request ),
			'mest' => max( 1, count( $request->package->get_items() ) ),
			'cost' => $cost,
			'naimenovanie' => 'ТЕКСТИЛЬ',
			'dops' => $dops,
		);
	}

	/** @return array<int,string> */
	public function validate_packaging( QuoteRequest $request ): array {
		$errors = array();
		if ( $request->package->get_total_weight_g() <= 0 ) {
			$errors[] = 'jet_weight_missing';
		}
		if ( $request->package->get_total_volume_cm3() <= 0 ) {
			$errors[] = 'jet_volume_missing';
		}
		if ( $this->max_side_m( $request ) <= 0 ) {
			$errors[] = 'jet_dimensions_missing';
		}

		return $errors;
	}

	public function discounted_goods_cost_rub( QuoteRequest $request ): int {
		return max( 0, (int) round( $request->package->cart_total->get_rubles() ) );
	}

	private function kg( int $grams ): float {
		return round( max( 0, $grams ) / 1000, 3 );
	}

	private function m3( int $cm3 ): float {
		return round( max( 0, $cm3 ) / 1000000, 6 );
	}

	private function max_side_m( QuoteRequest $request ): float {
		return round( max( 0, (int) $request->package->length_cm, (int) $request->package->width_cm, (int) $request->package->height_cm ) / 100, 3 );
	}
}
