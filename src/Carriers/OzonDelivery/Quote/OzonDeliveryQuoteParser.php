<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Quote;

use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryMessageSanitizer;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;

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
			$cost = is_array( $posting['estimated_delivery_cost'] ?? null ) ? $posting['estimated_delivery_cost'] : array();
			$currency = (string) ( $cost['currency_code'] ?? '' );
			if ( 'RUB' !== $currency ) {
				throw new OzonDeliveryQuoteException( 'ozon_quote_currency_unexpected', 'order_checkout', $http_status, 'Ozon Delivery вернул неподдерживаемую валюту.' );
			}
			$amount = $cost['amount'] ?? null;
			if ( ! is_int( $amount ) && ! is_float( $amount ) && ! is_string( $amount ) ) {
				throw new OzonDeliveryQuoteException( 'ozon_quote_price_missing', 'order_checkout', $http_status, 'Ozon Delivery не вернул стоимость доставки.' );
			}
			$days = $posting['estimated_delivery_days'] ?? null;
			if ( null !== $days && ! is_int( $days ) && ! ( is_string( $days ) && ctype_digit( $days ) ) ) {
				throw new OzonDeliveryQuoteException( 'ozon_quote_days_malformed', 'order_checkout', $http_status, 'Ozon Delivery вернул некорректный срок доставки.' );
			}
			$total = $total->add( Money::from_rubles( (string) $amount, 'RUB' ) );
			$days_int = null === $days ? null : max( 0, (int) $days );
			$max_days = null === $days_int ? $max_days : max( (int) ( $max_days ?? 0 ), $days_int );
			$normalized[] = array( 'request_id' => $request_id, 'price_rub' => (string) $amount, 'delivery_days' => $days_int );
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
			array( 'postings' => $normalized )
		);
	}
}
