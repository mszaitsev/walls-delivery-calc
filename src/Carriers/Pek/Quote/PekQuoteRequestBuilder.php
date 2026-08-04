<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Quote;

use DateTimeImmutable;
use DateTimeZone;
use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

final class PekQuoteRequestBuilder {
	public function __construct(
		private PekSettings $settings,
		private PekQuoteCargoBuilder $cargo_builder
	) {
	}

	/** @return array<string,mixed> */
	public function build( QuoteRequest $request, PekQuoteOptions $options ): array {
		if ( 'RU' !== strtoupper( trim( $request->country_code ) ) ) {
			throw new PekApiException( 'Расчёт ПЭК на этом этапе поддерживает только RU → RU.', array( 'error_code' => 'pek_quote_country_not_supported', 'failure_stage' => 'quote_calculator_contract' ) );
		}

		$sender = $this->sender_warehouse();
		$this->validate_planned_date_time( $options->planned_date_time, $this->timezone_from_sender( $sender ) );
		$declared_value = $this->declared_value_rub( $request );
		$cargos = $this->cargo_builder->build( $request );
		$counterpart = $this->counterpart();

		$payload = array(
			'currencyCode' => '643',
			'types' => array( PekSettings::LTL_PRODUCT_TYPE ),
			'senderWarehouseId' => (string) $sender['warehouseId'],
			'plannedDateTime' => $options->planned_date_time,
			'isInsurance' => true,
			'isInsurancePrice' => $declared_value,
			'isPickUp' => false,
			'isDelivery' => $options->is_courier(),
			'isOpenCarSender' => false,
			'isOpenCarReceiver' => false,
			'isHyperMarket' => false,
			'needReturnDocuments' => false,
			'needArrangeTransportationDocuments' => false,
			'counterpart' => $counterpart,
			'cargos' => $cargos,
		);
		if ( $options->is_pickup() ) {
			$payload['receiverWarehouseId'] = $options->receiver_warehouse_id;
		} else {
			$delivery = array( 'address' => $options->delivery_address );
			if ( null !== $options->delivery_latitude && null !== $options->delivery_longitude ) {
				$delivery['coordinates'] = array(
					'latitude' => $this->coordinate_string( $options->delivery_latitude ),
					'longitude' => $this->coordinate_string( $options->delivery_longitude ),
				);
			}
			$payload['delivery'] = $delivery;
		}

		return $payload;
	}

	/** @return array<string,mixed> */
	public function safe_request( array $payload ): array {
		$counterpart = is_array( $payload['counterpart'] ?? null ) ? $payload['counterpart'] : array();
		return array(
			'currencyCode' => (string) ( $payload['currencyCode'] ?? '' ),
			'types' => is_array( $payload['types'] ?? null ) ? array_values( $payload['types'] ) : array(),
			'senderWarehouseId' => (string) ( $payload['senderWarehouseId'] ?? '' ),
			'receiverWarehouseId' => (string) ( $payload['receiverWarehouseId'] ?? '' ),
			'isPickUp' => (bool) ( $payload['isPickUp'] ?? false ),
			'isDelivery' => (bool) ( $payload['isDelivery'] ?? false ),
			'delivery_address_present' => is_array( $payload['delivery'] ?? null ) && '' !== trim( (string) ( $payload['delivery']['address'] ?? '' ) ),
			'coordinates_present' => is_array( $payload['delivery']['coordinates'] ?? null ),
			'plannedDateTime' => (string) ( $payload['plannedDateTime'] ?? '' ),
			'insurance_enabled' => (bool) ( $payload['isInsurance'] ?? false ),
			'insurance_value' => (float) ( $payload['isInsurancePrice'] ?? 0 ),
			'cargo_count' => is_array( $payload['cargos'] ?? null ) ? count( $payload['cargos'] ) : 0,
			'cargos' => $this->safe_cargos( $payload['cargos'] ?? array() ),
			'cargo_policy' => $this->cargo_builder->last_diagnostics(),
			'counterpart_present' => array() !== $counterpart,
			'client_card_present' => '' !== trim( (string) ( $counterpart['counterpartClientCard'] ?? '' ) ),
			'whoMakesCalculation' => is_array( $counterpart['whoMakesCalculation'] ?? null ) ? array_values( $counterpart['whoMakesCalculation'] ) : array(),
		);
	}

	/** @return array<string,mixed> */
	private function sender_warehouse(): array {
		$sender = $this->settings->sender_warehouse();
		$warehouse_id = trim( (string) ( $sender['warehouseId'] ?? '' ) );
		$source = trim( (string) ( $sender['source'] ?? '' ) );
		if ( '' === $warehouse_id || ! in_array( $source, array( 'free', 'paid', 'branches_all' ), true ) ) {
			throw new PekApiException( 'Не выбран склад самопривоза отправителя ПЭК.', array( 'error_code' => 'pek_quote_sender_warehouse_missing', 'failure_stage' => 'quote_calculator_contract' ) );
		}
		$sender['warehouseId'] = $warehouse_id;

		return $sender;
	}

	private function declared_value_rub( QuoteRequest $request ): float {
		$kopecks = $request->package->declared_value->get_kopecks();
		if ( $kopecks <= 0 ) {
			throw new PekApiException( 'Не указана положительная объявленная ценность ПЭК.', array( 'error_code' => 'pek_quote_declared_value_missing', 'failure_stage' => 'quote_calculator_contract' ) );
		}

		return round( $kopecks / 100, 2 );
	}

	/** @return array<string,mixed> */
	private function counterpart(): array {
		$inn = trim( $this->settings->sender_inn() );
		if ( '' === $inn ) {
			throw new PekApiException( 'Не указан ИНН отправителя ПЭК.', array( 'error_code' => 'pek_quote_counterpart_missing', 'failure_stage' => 'quote_calculator_contract' ) );
		}
		$counterpart = array(
			'inn' => $inn,
			'whoMakesCalculation' => array( 1, 3 ),
		);
		$kpp = trim( $this->settings->sender_kpp() );
		$client_card = trim( $this->settings->client_card() );
		if ( '' !== $kpp ) {
			$counterpart['kpp'] = $kpp;
		}
		if ( '' !== $client_card ) {
			$counterpart['counterpartClientCard'] = $client_card;
		}

		return $counterpart;
	}

	private function validate_planned_date_time( string $value, DateTimeZone $timezone ): void {
		$date = DateTimeImmutable::createFromFormat( '!Y-m-d\TH:i:s', $value, $timezone );
		$errors = DateTimeImmutable::getLastErrors();
		if ( false === $date || ( is_array( $errors ) && ( $errors['warning_count'] > 0 || $errors['error_count'] > 0 ) ) || $date->format( 'Y-m-d\TH:i:s' ) !== $value ) {
			throw new PekApiException( 'Некорректная плановая дата расчёта ПЭК.', array( 'error_code' => 'pek_quote_planned_datetime_invalid', 'failure_stage' => 'quote_calculator_contract' ) );
		}
		$now = function_exists( 'current_datetime' ) ? current_datetime() : new DateTimeImmutable( 'now', function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) );
		$planned = $date->setTimezone( $timezone );
		$now = $now->setTimezone( $timezone );
		if ( $planned <= $now || $planned > $now->modify( '+90 days' ) ) {
			throw new PekApiException( 'Плановая дата расчёта ПЭК вне допустимого периода.', array( 'error_code' => 'pek_quote_planned_datetime_invalid', 'failure_stage' => 'quote_calculator_contract' ) );
		}
	}

	/** @param array<string,mixed> $sender */
	private function timezone_from_sender( array $sender ): DateTimeZone {
		$timezone = trim( (string) ( $sender['branchTimezone'] ?? '' ) );
		if ( '' !== $timezone ) {
			try {
				return new DateTimeZone( $timezone );
			} catch ( \Throwable ) {
			}
		}

		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}

	private function coordinate_string( float $value ): string {
		$value = round( $value, 7 );
		if ( abs( $value ) < 0.00000005 ) {
			$value = 0.0;
		}
		$string = number_format( $value, 7, '.', '' );
		$string = rtrim( rtrim( $string, '0' ), '.' );

		return '' === $string || '-0' === $string ? '0' : $string;
	}

	/** @return array<int,array<string,mixed>> */
	private function safe_cargos( mixed $cargos ): array {
		if ( ! is_array( $cargos ) || ! array_is_list( $cargos ) ) {
			return array();
		}
		$safe = array();
		foreach ( $cargos as $cargo ) {
			if ( ! is_array( $cargo ) || array_is_list( $cargo ) ) {
				continue;
			}
			$row = array();
			foreach ( array( 'weight', 'maxPlaceWeight', 'length', 'width', 'height', 'volume', 'maxSize', 'isHP', 'sealingPositionsCount' ) as $key ) {
				if ( array_key_exists( $key, $cargo ) ) {
					$row[ $key ] = is_bool( $cargo[ $key ] ) ? (bool) $cargo[ $key ] : ( is_numeric( $cargo[ $key ] ) ? (float) $cargo[ $key ] : (string) $cargo[ $key ] );
				}
			}
			$safe[] = $row;
		}

		return $safe;
	}
}
