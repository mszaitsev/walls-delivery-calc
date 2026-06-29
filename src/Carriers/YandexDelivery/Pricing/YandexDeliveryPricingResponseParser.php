<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Pricing;

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryPricingResponseParser {
	/** @param array<string,mixed> $response */
	public function parse( array $response ): YandexDeliveryPricingResult {
		$body = is_array( $response['body'] ?? null ) ? $response['body'] : $response;
		$price = $this->price_kopecks( (string) ( $body['pricing_total'] ?? '' ) );
		if ( $price <= 0 ) {
			throw new YandexDeliveryApiException( 'Яндекс.Доставка вернула некорректную стоимость pricing-calculator.', array( 'error_code' => 'pricing_total_parse_error' ) );
		}
		$delivery_days = is_numeric( $body['delivery_days'] ?? null ) ? max( 0, (int) $body['delivery_days'] ) : null;

		return new YandexDeliveryPricingResult( $price, $delivery_days, $body );
	}

	private function price_kopecks( string $value ): int {
		if ( ! preg_match( '/([0-9]+(?:[\.,][0-9]+)?)/', $value, $matches ) ) {
			return 0;
		}

		return (int) round( (float) str_replace( ',', '.', $matches[1] ) * 100 );
	}
}
