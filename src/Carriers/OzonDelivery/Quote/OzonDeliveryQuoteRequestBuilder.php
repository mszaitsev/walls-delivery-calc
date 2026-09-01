<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Quote;

use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Domain\Common\MoneyParser;
use WallsShop\WDC\Domain\Phone\RussianPhoneNormalizer;
use WallsShop\WDC\Packaging\PackagingParcel;
use WallsShop\WDC\Packaging\PackagingResult;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryQuoteRequestBuilder {
	private RussianPhoneNormalizer $phones;
	private OzonDeliveryCourierAddressMapper $courier_address;

	public function __construct( private OzonDeliverySettings $settings, ?RussianPhoneNormalizer $phones = null, ?OzonDeliveryCourierAddressMapper $courier_address = null ) {
		$this->phones = $phones ?? new RussianPhoneNormalizer();
		$this->courier_address = $courier_address ?? new OzonDeliveryCourierAddressMapper();
	}

	/** @return array{body:array<string,mixed>,request_ids:array<int,int>,diagnostics:array<string,mixed>} */
	public function build( QuoteRequest $request, PackagingResult $packaging, string $delivery_point_id ): array {
		return $this->build_pickup( $request, $packaging, $delivery_point_id );
	}

	/** @return array{body:array<string,mixed>,request_ids:array<int,int>,diagnostics:array<string,mixed>} */
	public function build_pickup( QuoteRequest $request, PackagingResult $packaging, string $delivery_point_id ): array {
		$shipment_method_id = $this->settings->pickup_shipment_method_id();
		if ( $shipment_method_id <= 0 ) {
			throw new OzonDeliveryQuoteException( 'ozon_shipment_method_missing', 'order_checkout', 0, 'Не указан метод доставки Ozon.' );
		}
		if ( ! ctype_digit( $delivery_point_id ) || (int) $delivery_point_id <= 0 ) {
			throw new OzonDeliveryQuoteException( 'ozon_delivery_point_missing', 'order_checkout', 0, 'Не выбран ПВЗ Ozon для расчета.' );
		}

		return $this->build_for_delivery(
			$request,
			$packaging,
			$shipment_method_id,
			array( 'delivery_point' => array( 'delivery_point_id' => (int) $delivery_point_id ) ),
			array(
				'delivery_type' => 'pickup',
				'destination_point_id' => $delivery_point_id,
			)
		);
	}

	/** @return array{body:array<string,mixed>,request_ids:array<int,int>,diagnostics:array<string,mixed>} */
	public function build_courier( QuoteRequest $request, PackagingResult $packaging ): array {
		$shipment_method_id = $this->settings->courier_shipment_method_id();
		if ( $shipment_method_id <= 0 ) {
			throw new OzonDeliveryQuoteException( 'ozon_courier_shipment_method_missing', 'order_checkout', 0, 'Не указан метод доставки Ozon курьером.' );
		}

		return $this->build_for_delivery(
			$request,
			$packaging,
			$shipment_method_id,
			$this->courier_address->delivery( $request ),
			array(
				'delivery_type' => 'courier',
				'courier_coordinates_present' => true,
			)
		);
	}

	/**
	 * @param array<string,mixed> $delivery
	 * @param array<string,mixed> $extra_diagnostics
	 * @return array{body:array<string,mixed>,request_ids:array<int,int>,diagnostics:array<string,mixed>}
	 */
	private function build_for_delivery( QuoteRequest $request, PackagingResult $packaging, int $shipment_method_id, array $delivery, array $extra_diagnostics ): array {
		$phone = $this->recipient_phone( $request );
		if ( '' === $phone ) {
			throw new OzonDeliveryQuoteException( 'ozon_recipient_phone_missing', 'order_checkout', 0, 'Для расчета Ozon нужен телефон получателя.' );
		}
		$posting_count = $this->posting_count( $packaging );
		if ( $posting_count < 1 || $posting_count > 100 ) {
			throw new OzonDeliveryQuoteException( 'ozon_package_count_unsupported', 'order_checkout', 0, 'Количество отправлений Ozon не поддерживается.' );
		}
		$total_declared_kopecks = $this->declared_value_kopecks( $packaging, $request );
		$declared_kopecks = 1 === $posting_count ? $total_declared_kopecks : $this->ceil_declared_per_posting_kopecks( $total_declared_kopecks, $posting_count );
		$declared = $this->money_amount( $declared_kopecks );
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
		if ( count( $postings ) !== $posting_count ) {
			throw new OzonDeliveryQuoteException( 'ozon_package_count_unsupported', 'order_checkout', 0, 'Количество отправлений Ozon не поддерживается.' );
		}

		return array(
			'body' => array(
				'recipient' => array( 'phone_number' => $phone ),
				'postings' => $postings,
				'delivery' => $delivery,
			),
			'request_ids' => $request_ids,
			'diagnostics' => array_merge( array(
				'endpoint' => 'POST /v1/order/checkout',
				'shipment_method_id' => $shipment_method_id,
				'packages_count' => count( $postings ),
				'total_declared_value_rub' => $this->money_amount( $total_declared_kopecks ),
				'declared_value_per_posting_rub' => $declared,
				'declared_value_rub' => $declared,
			), $extra_diagnostics ),
		);
	}

	private function recipient_phone( QuoteRequest $request ): string {
		$customer_phone = $this->phones->normalize( $request->customer_context['recipient_phone'] ?? '' );
		if ( '' !== $customer_phone ) {
			return $customer_phone;
		}

		return $this->settings->quote_fallback_phone();
	}

	private function money_amount( int $kopecks ): string {
		return number_format( $kopecks / 100, 2, '.', '' );
	}

	private function posting_count( PackagingResult $packaging ): int {
		$count = 0;
		foreach ( $packaging->parcels() as $parcel ) {
			if ( $parcel instanceof PackagingParcel ) {
				$count += max( 1, $parcel->quantity );
			}
		}

		return $count;
	}

	private function declared_value_kopecks( PackagingResult $packaging, QuoteRequest $request ): int {
		$source = $packaging->diagnostics['declared_value_rub'] ?? '';
		$declared = is_int( $source ) || is_float( $source ) || is_string( $source )
			? MoneyParser::numeric_to_kopecks( $source )
			: null;
		if ( null === $declared ) {
			$declared = $request->order_total->get_kopecks();
		}

		return max( 1, $declared );
	}

	private function ceil_declared_per_posting_kopecks( int $total_kopecks, int $posting_count ): int {
		$whole_rubles = intdiv( $total_kopecks + ( $posting_count * 100 ) - 1, $posting_count * 100 );

		return max( 100, $whole_rubles * 100 );
	}
}
