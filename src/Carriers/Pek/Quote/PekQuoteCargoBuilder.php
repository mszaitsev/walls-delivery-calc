<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Quote;

use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

final class PekQuoteCargoBuilder {
	/** @var array<string,mixed> */
	private array $last_diagnostics = array();

	/** @return array<int,array<string,mixed>> */
	public function build( QuoteRequest $request ): array {
		$this->last_diagnostics = array();
		$package = $request->package;
		$product_weight_g = max( 0, $package->weight_g );
		$total_weight_g = $package->total_weight_g > 0 ? $package->total_weight_g : $package->get_total_weight_g();
		if ( $total_weight_g <= 0 ) {
			throw new PekApiException( 'Не указан положительный вес груза ПЭК.', array( 'error_code' => 'pek_quote_weight_missing', 'failure_stage' => 'quote_calculator_contract' ) );
		}

		$this->last_diagnostics = array(
			'product_weight_g' => $product_weight_g,
			'total_weight_g' => $total_weight_g,
			'isHP' => false,
			'sealingPositionsCount' => 0,
			'product_weight_known' => $product_weight_g > 0,
		);
		$weight = $this->grams_to_kg_hundredths( $total_weight_g );
		$cargo = array(
			'weight' => $weight,
			'maxPlaceWeight' => $weight,
			'isHP' => false,
			'sealingPositionsCount' => 0,
		);

		if ( $this->has_full_dimensions( $package ) ) {
			$cargo['length'] = $this->centimeters_to_meters_hundredths( (int) $package->length_cm );
			$cargo['width'] = $this->centimeters_to_meters_hundredths( (int) $package->width_cm );
			$cargo['height'] = $this->centimeters_to_meters_hundredths( (int) $package->height_cm );

			return array( $cargo );
		}

		$volume_cm3 = $package->get_total_volume_cm3();
		if ( $volume_cm3 <= 0 ) {
			throw new PekApiException( 'Не указан положительный объём груза ПЭК.', array( 'error_code' => 'pek_quote_volume_missing', 'failure_stage' => 'quote_calculator_contract' ) );
		}
		$max_dimension_cm = max( 0, (int) ( $package->length_cm ?? 0 ), (int) ( $package->width_cm ?? 0 ), (int) ( $package->height_cm ?? 0 ) );
		if ( $max_dimension_cm <= 0 ) {
			throw new PekApiException( 'Не указан максимальный габарит груза ПЭК.', array( 'error_code' => 'pek_quote_dimensions_missing', 'failure_stage' => 'quote_calculator_contract' ) );
		}
		$cargo['volume'] = $this->cubic_centimeters_to_cubic_meters_hundredths( $volume_cm3 );
		$cargo['maxSize'] = $this->centimeters_to_meters_hundredths( $max_dimension_cm );

		return array( $cargo );
	}

	/** @return array<string,mixed> */
	public function last_diagnostics(): array {
		return $this->last_diagnostics;
	}

	private function has_full_dimensions( Package $package ): bool {
		return null !== $package->length_cm && null !== $package->width_cm && null !== $package->height_cm && $package->length_cm > 0 && $package->width_cm > 0 && $package->height_cm > 0;
	}

	private function grams_to_kg_hundredths( int $grams ): float {
		return max( 0.01, ceil( $grams / 10 ) / 100 );
	}

	private function centimeters_to_meters_hundredths( int $centimeters ): float {
		return max( 0.01, ceil( $centimeters ) / 100 );
	}

	private function cubic_centimeters_to_cubic_meters_hundredths( int $cm3 ): float {
		return max( 0.01, ceil( $cm3 / 10000 ) / 100 );
	}
}
