<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Quote;

use WallsShop\WDC\Carriers\JetLogistic\Api\JetLogisticApiException;

defined( 'ABSPATH' ) || exit;

final class JetLogisticQuoteResponseParser {
	/** @param array<string,mixed> $data */
	public function parse( array $data ): JetLogisticCalculationResult {
		$currency = strtoupper( trim( (string) ( $data['valuta'] ?? $data['valuta_name'] ?? '' ) ) );
		$currency_name = strtoupper( trim( (string) ( $data['valuta_name'] ?? '' ) ) );
		if ( ! in_array( 'RUB', array( $currency, $currency_name ), true ) && ! in_array( 'РУБ', array( $currency, $currency_name ), true ) ) {
			throw new JetLogisticApiException( 'Jet Logistic returned non-RUB currency.', array( 'error_code' => 'jet_currency_not_rub' ) );
		}

		return new JetLogisticCalculationResult(
			$this->rubles( $data['price_zabor'] ?? null ),
			$this->rubles( $data['price_terminal'] ?? null ),
			$this->rubles( $data['price_delivery'] ?? null ),
			$this->rubles( $data['price_dop'] ?? null ),
			trim( (string) ( $data['city_from'] ?? '' ) ),
			trim( (string) ( $data['city_terminal_from'] ?? '' ) ),
			trim( (string) ( $data['city_terminal_to'] ?? '' ) ),
			trim( (string) ( $data['city_to'] ?? '' ) ),
			$this->nullable_int( $data['day_from'] ?? null ),
			$this->nullable_int( $data['day_to'] ?? null ),
			(string) ( $data['valuta'] ?? '' ),
			(string) ( $data['valuta_name'] ?? '' )
		);
	}

	private function rubles( mixed $value ): int {
		if ( ! is_numeric( $value ) ) {
			return 0;
		}

		return max( 0, (int) round( (float) $value ) );
	}

	private function nullable_int( mixed $value ): ?int {
		return is_numeric( $value ) ? max( 0, (int) $value ) : null;
	}
}
