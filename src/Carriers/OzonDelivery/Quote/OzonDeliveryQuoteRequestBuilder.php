<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Quote;

use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Packaging\PackagingParcel;
use WallsShop\WDC\Packaging\PackagingResult;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryQuoteRequestBuilder {
	public function __construct( private OzonDeliverySettings $settings ) {}

	/** @return array{body:array<string,mixed>,request_ids:array<int,int>,diagnostics:array<string,mixed>} */
	public function build( QuoteRequest $request, PackagingResult $packaging, string $delivery_point_id ): array {
		$shipment_method_id = $this->settings->shipment_method_id();
		if ( $shipment_method_id <= 0 ) {
			throw new OzonDeliveryQuoteException( 'ozon_shipment_method_missing', 'order_checkout', 0, 'Не указан метод доставки Ozon.' );
		}
		$phone = $this->recipient_phone( $request );
		if ( '' === $phone ) {
			throw new OzonDeliveryQuoteException( 'ozon_recipient_phone_missing', 'order_checkout', 0, 'Для расчета Ozon нужен телефон получателя.' );
		}
		if ( ! ctype_digit( $delivery_point_id ) || (int) $delivery_point_id <= 0 ) {
			throw new OzonDeliveryQuoteException( 'ozon_delivery_point_missing', 'order_checkout', 0, 'Не выбран ПВЗ Ozon для расчета.' );
		}
		$declared = $this->money_amount( max( 1, (int) round( (float) ( $packaging->diagnostics['declared_value_rub'] ?? $request->order_total->get_rubles() ) * 100 ) ) );
		$postings = array();
		$request_ids = array();
		$request_id = 100;
		foreach ( $packaging->parcels() as $parcel ) {
			if ( ! $parcel instanceof PackagingParcel ) {
				continue;
			}
			for ( $index = 0; $index < max( 1, $parcel->quantity ); ++$index ) {
				++$request_id;
				$request_ids[] = $request_id;
				$postings[] = array(
					'request_id' => $request_id,
					'shipment_method_id' => $shipment_method_id,
					'declared_value' => array( 'amount' => $declared, 'currency_code' => 'RUB' ),
					'dimensions' => array(
						'weight_g' => max( 1, $parcel->weight_g ),
						'length_mm' => max( 1, (int) ceil( $parcel->length_cm * 10 ) ),
						'width_mm' => max( 1, (int) ceil( $parcel->width_cm * 10 ) ),
						'height_mm' => max( 1, (int) ceil( $parcel->height_cm * 10 ) ),
					),
				);
			}
		}
		if ( array() === $postings || count( $postings ) > 100 ) {
			throw new OzonDeliveryQuoteException( 'ozon_package_count_unsupported', 'order_checkout', 0, 'Количество отправлений Ozon не поддерживается.' );
		}

		return array(
			'body' => array(
				'recipient' => array( 'phone_number' => $phone ),
				'postings' => $postings,
				'delivery' => array( 'delivery_point' => array( 'delivery_point_id' => (int) $delivery_point_id ) ),
			),
			'request_ids' => $request_ids,
			'diagnostics' => array(
				'endpoint' => 'POST /v1/order/checkout',
				'shipment_method_id' => $shipment_method_id,
				'destination_point_id' => $delivery_point_id,
				'packages_count' => count( $postings ),
				'declared_value_rub' => $declared,
			),
		);
	}

	private function recipient_phone( QuoteRequest $request ): string {
		foreach ( array( 'recipient_phone', 'billing_phone', 'phone_number', 'customer_phone' ) as $key ) {
			$value = preg_replace( '/[^\d+]+/', '', (string) ( $request->customer_context[ $key ] ?? '' ) ) ?? '';
			if ( preg_match( '/^\+7\d{10}$/', $value ) ) {
				return $value;
			}
		}

		return '';
	}

	private function money_amount( int $kopecks ): string {
		return number_format( $kopecks / 100, 2, '.', '' );
	}
}
