<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Admin;

use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteException;
use WallsShop\WDC\Carriers\OzonDelivery\Quote\OzonDeliveryQuoteService;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Phone\RussianPhoneNormalizer;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryQuoteDiagnosticService {
	private RussianPhoneNormalizer $phones;

	public function __construct( private OzonDeliverySettings $settings, private OzonDeliveryQuoteService $quotes, ?RussianPhoneNormalizer $phones = null ) {
		$this->phones = $phones ?? new RussianPhoneNormalizer();
	}

	/** @param array<string,mixed> $input @return array<string,mixed> */
	public function run( array $input ): array {
		$result = array(
			'checked_at' => gmdate( 'c' ),
			'endpoint' => 'POST /v1/order/checkout',
			'shipment_method_id' => $this->settings->shipment_method_id(),
			'success' => false,
		);
		try {
			$request = $this->request( $input );
			$quote = $this->quotes->quote_pickup( $request );
			$result = array_merge(
				$result,
				array(
					'success' => true,
					'http_status' => $quote->http_status,
					'price_rub' => $quote->price->get_rubles(),
					'currency' => $quote->price->get_currency(),
					'delivery_total_rub' => $quote->meta['delivery_total_rub'] ?? null,
					'insurance_total_rub' => $quote->meta['insurance_total_rub'] ?? null,
					'total_rub' => $quote->meta['total_rub'] ?? null,
					'delivery_min_days' => $quote->delivery_days->min_days,
					'delivery_max_days' => $quote->delivery_days->max_days,
					'destination_point_id' => $quote->destination_point_id,
					'packages_count' => $quote->package_count,
					'pickup_source' => (string) ( $quote->meta['pickup_source'] ?? '' ),
				)
			);
		} catch ( OzonDeliveryQuoteException $exception ) {
			$result = array_merge(
				$result,
				array(
					'operation' => $exception->operation,
					'http_status' => $exception->http_status,
					'error_code' => $exception->safe_code,
					'message' => $exception->getMessage(),
				)
			);
		}
		$this->settings->save_last_quote_diagnostic( $result );

		return $result;
	}

	/** @param array<string,mixed> $input */
	private function request( array $input ): QuoteRequest {
		$lat = $this->number( $input['ozon_delivery_quote_latitude'] ?? null, -90, 90 );
		$lng = $this->number( $input['ozon_delivery_quote_longitude'] ?? null, -180, 180 );
		$point_id = preg_replace( '/\D+/', '', (string) ( $input['ozon_delivery_quote_point_id'] ?? '' ) ) ?? '';
		$weight = max( 1, (int) ( $input['ozon_delivery_quote_weight_g'] ?? 1000 ) );
		$length = max( 1, (int) ( $input['ozon_delivery_quote_length_cm'] ?? 10 ) );
		$width = max( 1, (int) ( $input['ozon_delivery_quote_width_cm'] ?? 10 ) );
		$height = max( 1, (int) ( $input['ozon_delivery_quote_height_cm'] ?? 10 ) );
		$value = max( 1.0, (float) str_replace( ',', '.', (string) ( $input['ozon_delivery_quote_declared_value_rub'] ?? '1000' ) ) );
		$phone = $this->phone( (string) ( $input['ozon_delivery_quote_phone'] ?? '' ) );
		if ( null === $lat || null === $lng || '' === $point_id || '' === $phone ) {
			throw new OzonDeliveryQuoteException( 'ozon_quote_diagnostic_input_invalid', 'order_checkout', 0, 'Заполните телефон, координаты и ID ПВЗ Ozon.' );
		}
		$money = Money::from_rubles( $value );
		$context = array(
			'source' => 'ozon_delivery_admin_quote_diagnostic',
			'recipient_phone' => $phone,
			'destination_latitude' => $lat,
			'destination_longitude' => $lng,
			'pickup_selections' => array(
				OzonDeliverySettings::PICKUP_FAMILY => array(
					'carrier_key' => OzonDeliverySettings::CARRIER_KEY,
					'pickup_family' => OzonDeliverySettings::PICKUP_FAMILY,
					'point_code' => $point_id,
				),
			),
		);

		return new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Новосибирск' ), new Package( array(), $money, $money, $weight, 0, $weight, $length, $width, $height, $length * $width * $height, 'manual' ), '', $money, gmdate( 'Y-m-d' ), $context );
	}

	private function number( mixed $value, float $min, float $max ): ?float {
		if ( ! is_numeric( $value ) ) {
			return null;
		}
		$number = (float) str_replace( ',', '.', (string) $value );

		return is_finite( $number ) && $number >= $min && $number <= $max ? $number : null;
	}

	private function phone( string $value ): string {
		return $this->phones->normalize( $value );
	}
}
