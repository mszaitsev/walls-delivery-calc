<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Quote;

use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryMessageSanitizer;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Common\MoneyParser;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryQuoteParser {
	public function __construct( private OzonDeliveryMessageSanitizer $sanitizer ) {}

	/** @param array<string,mixed> $data @param array<int,int> $request_ids */
	public function parse( array $data, array $request_ids, string $point_id, int $shipment_method_id, int $http_status = 200 ): OzonDeliveryQuoteResult {
		$results = $data['results'] ?? null;
		if ( ! is_array( $results ) || array_is_list( $data ) || count( $results ) !== count( $request_ids ) ) {
			throw new OzonDeliveryQuoteException( 'ozon_quote_response_malformed', 'order_checkout', $http_status, 'Ozon Delivery вернул некорректный ответ расчета.' );
		}
		$expected = array_fill_keys( array_map( 'intval', $request_ids ), true );
		$total = Money::from_kopecks( 0 );
		$delivery_total_kopecks = 0;
		$insurance_total_kopecks = 0;
		$max_days = null;
		$normalized = array();
		foreach ( $results as $result ) {
			if ( ! is_array( $result ) ) {
				throw new OzonDeliveryQuoteException( 'ozon_quote_result_malformed', 'order_checkout', $http_status, 'Ozon Delivery вернул некорректный результат расчета.' );
			}
			$request_id = (int) ( $result['request_id'] ?? 0 );
			if ( $request_id <= 0 || ! isset( $expected[ $request_id ] ) ) {
				throw new OzonDeliveryQuoteException( 'ozon_quote_request_id_mismatch', 'order_checkout', $http_status, 'Ozon Delivery вернул неожиданный request_id.' );
			}
			unset( $expected[ $request_id ] );
			if ( is_array( $result['error'] ?? null ) ) {
				$error = $result['error'];
				$code = $this->sanitizer->code( $error['code'] ?? '' ) ?: 'ozon_quote_carrier_error';
				throw new OzonDeliveryQuoteException( $code, 'order_checkout', $http_status, $this->sanitizer->sanitize( $error['message'] ?? null, 'Ozon Delivery не рассчитал доставку.' ) );
			}
			$posting = is_array( $result['posting'] ?? null ) ? $result['posting'] : array();
			$delivery_kopecks = $this->money_kopecks( $posting['estimated_delivery_cost'] ?? null, 'ozon_quote_price_missing', 'ozon_quote_price_malformed', 'ozon_quote_currency_unexpected', 'Ozon Delivery не вернул стоимость доставки.', $http_status );
			$insurance_kopecks = $this->money_kopecks( $posting['estimated_insurance_cost'] ?? null, 'ozon_quote_insurance_missing', 'ozon_quote_insurance_malformed', 'ozon_quote_insurance_currency_unexpected', 'Ozon Delivery не вернул стоимость страховки.', $http_status );
			$days = $posting['estimated_delivery_days'] ?? null;
			if ( null !== $days && ! is_int( $days ) && ! ( is_string( $days ) && ctype_digit( $days ) ) ) {
				throw new OzonDeliveryQuoteException( 'ozon_quote_days_malformed', 'order_checkout', $http_status, 'Ozon Delivery вернул некорректный срок доставки.' );
			}
			$posting_total_kopecks = $delivery_kopecks + $insurance_kopecks;
			$delivery_total_kopecks += $delivery_kopecks;
			$insurance_total_kopecks += $insurance_kopecks;
			$total = $total->add( Money::from_kopecks( $posting_total_kopecks, 'RUB' ) );
			$days_int = null === $days ? null : max( 0, (int) $days );
			$max_days = null === $days_int ? $max_days : max( (int) ( $max_days ?? 0 ), $days_int );
			$normalized[] = array(
				'request_id' => $request_id,
				'delivery_cost_rub' => $this->rubles( $delivery_kopecks ),
				'insurance_cost_rub' => $this->rubles( $insurance_kopecks ),
				'total_cost_rub' => $this->rubles( $posting_total_kopecks ),
				'delivery_days' => $days_int,
			);
		}
		if ( array() !== $expected || $total->is_zero() ) {
			throw new OzonDeliveryQuoteException( 'ozon_quote_incomplete', 'order_checkout', $http_status, 'Ozon Delivery вернул неполный расчет.' );
		}

		return new OzonDeliveryQuoteResult(
			$total,
			null === $max_days ? new DateRange() : DateRange::single( $max_days, DateRange::UNIT_CALENDAR_DAYS ),
			$point_id,
			count( $request_ids ),
			$shipment_method_id,
			'POST /v1/order/checkout',
			$http_status,
			array(
				'postings' => $normalized,
				'delivery_total_rub' => $this->rubles( $delivery_total_kopecks ),
				'insurance_total_rub' => $this->rubles( $insurance_total_kopecks ),
				'total_rub' => $this->rubles( $total->get_kopecks() ),
			)
		);
	}

	private function money_kopecks( mixed $money, string $missing_code, string $amount_code, string $currency_code, string $message, int $http_status ): int {
		if ( ! is_array( $money ) ) {
			throw new OzonDeliveryQuoteException( $missing_code, 'order_checkout', $http_status, $message );
		}
		if ( 'RUB' !== (string) ( $money['currency_code'] ?? '' ) ) {
			throw new OzonDeliveryQuoteException( $currency_code, 'order_checkout', $http_status, 'Ozon Delivery вернул неподдерживаемую валюту.' );
		}
		if ( ! array_key_exists( 'amount', $money ) ) {
			throw new OzonDeliveryQuoteException( $missing_code, 'order_checkout', $http_status, $message );
		}
		if ( ! is_int( $money['amount'] ) && ! is_float( $money['amount'] ) && ! is_string( $money['amount'] ) ) {
			throw new OzonDeliveryQuoteException( $amount_code, 'order_checkout', $http_status, 'Ozon Delivery вернул некорректную сумму.' );
		}
		$kopecks = MoneyParser::numeric_to_kopecks( $money['amount'] );
		if ( null === $kopecks || $kopecks < 0 ) {
			throw new OzonDeliveryQuoteException( $amount_code, 'order_checkout', $http_status, 'Ozon Delivery вернул некорректную сумму.' );
		}

		return $kopecks;
	}

	private function rubles( int $kopecks ): string {
		return number_format( $kopecks / 100, 2, '.', '' );
	}
}
