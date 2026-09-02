<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Geography;

defined( 'ABSPATH' ) || exit;

final class JetLogisticCityNameNormalizer {
	public function normalize( string $value ): string {
		$value = strtr( trim( $value ), array( 'Ё' => 'Е', 'ё' => 'е' ) );
		$value = mb_strtolower( $value, 'UTF-8' );
		$value = preg_replace( '/^\s*(г\.|город)\s+/u', '', $value ) ?? $value;
		$value = preg_replace( '/[.]+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;

		return trim( $value );
	}

	public function normalize_api_city( string $value ): string {
		$value = $this->normalize( $value );
		$value = preg_replace( '/\s+опт\s*$/u', '', $value ) ?? $value;

		return trim( $value );
	}

	public function identity( string $city, string $region, string $country_code ): string {
		return hash( 'sha256', strtoupper( trim( $country_code ) ) . '|' . $this->normalize( $region ) . '|' . $this->normalize( $city ) );
	}
}
