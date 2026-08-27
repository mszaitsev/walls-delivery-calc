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
			$this->rubles( $data['price_zabor'] ?? null, 'price_zabor' ),
			$this->rubles( $data['price_terminal'] ?? null, 'price_terminal' ),
			$this->rubles( $data['price_delivery'] ?? null, 'price_delivery' ),
			$this->rubles( $data['price_dop'] ?? null, 'price_dop' ),
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

	private function rubles( mixed $value, string $field ): int {
		if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
			throw new JetLogisticApiException( 'Jet Logistic returned malformed price.', array( 'error_code' => 'jet_invalid_response', 'field' => $field ) );
		}
		$rubles = (float) $value;
		if ( $rubles < 0 ) {
			throw new JetLogisticApiException( 'Jet Logistic returned negative price.', array( 'error_code' => 'jet_invalid_response', 'field' => $field ) );
		}

		return (int) round( $rubles );
	}

	private function nullable_int( mixed $value ): ?int {
		return is_numeric( $value ) ? max( 0, (int) $value ) : null;
	}
}
