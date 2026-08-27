<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Quote;

use WallsShop\WDC\Carriers\JetLogistic\Api\JetLogisticApiException;

defined( 'ABSPATH' ) || exit;

final class JetLogisticQuoteResponseParser {
	/** @param array<string,mixed> $data */
	public function parse( array $data ): JetLogisticCalculationResult {
		$currency = trim( (string) ( $data['valuta'] ?? '' ) );
		$currency_name = trim( (string) ( $data['valuta_name'] ?? '' ) );
		$currency_source = $this->currency_source( $currency, $currency_name );

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
			'' !== (string) ( $data['valuta'] ?? '' ) ? (string) $data['valuta'] : 'RUB',
			'' !== (string) ( $data['valuta_name'] ?? '' ) ? (string) $data['valuta_name'] : 'RUB',
			$currency_source
		);
	}

	private function currency_source( string $currency, string $currency_name ): string {
		if ( '' !== $currency_name ) {
			if ( $this->is_rub_currency( $currency_name ) ) {
				return 'response_name';
			}
			throw new JetLogisticApiException(
				'Jet Logistic returned non-RUB currency.',
				array(
					'error_code' => 'jet_currency_not_rub',
					'valuta' => $this->safe_currency_value( $currency ),
					'valuta_name' => $this->safe_currency_value( $currency_name ),
				)
			);
		}
		if ( '' === $currency ) {
			return 'profile';
		}
		if ( is_numeric( $currency ) ) {
			return 'profile';
		}
		if ( $this->is_rub_currency( $currency ) ) {
			return 'response_code';
		}
		throw new JetLogisticApiException(
			'Jet Logistic returned non-RUB currency.',
			array(
				'error_code' => 'jet_currency_not_rub',
				'valuta' => $this->safe_currency_value( $currency ),
				'valuta_name' => $this->safe_currency_value( $currency_name ),
			)
		);
	}

	private function is_rub_currency( string $value ): bool {
		$normalized = mb_strtoupper( trim( $value, " \t\n\r\0\x0B." ), 'UTF-8' );
		return in_array( $normalized, array( 'RUB', 'RUR', 'РУБ' ), true );
	}

	private function safe_currency_value( string $value ): string {
		$value = trim( str_replace( array( "\r", "\n" ), ' ', $value ) );
		return mb_substr( $value, 0, 64, 'UTF-8' );
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
