<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;

defined( 'ABSPATH' ) || exit;

final class PekShipmentRequestBuilder {
	public function __construct(
		private PekSettings $settings,
		private PekShipmentDeclaredValueResolver $declared_values,
		private PekShipmentSenderWarehouseResolver $sender_warehouses,
		private PekShipmentCargoBuilder $cargo,
		private PekShipmentRecipientBuilder $recipients,
		private PekShipmentCorrelationResolver $correlations,
		private PekSmsReleaseAvailabilityService $sms,
		private PekShipmentDestinationResolver $destinations,
		private PekShipmentProductWeightResolver $product_weights
	) {
	}

	/** @return array{payload:array<string,mixed>,preview:array<string,mixed>,summary:array<string,mixed>} */
	public function build( object $order, ShipmentCreateRequest $request, bool $live_sms_check ): array {
		return $this->prepare( $order, $request, $live_sms_check );
	}

	/** @return array{payload:array<string,mixed>,preview:array<string,mixed>,summary:array<string,mixed>} */
	public function prepare( object $order, ShipmentCreateRequest $request, bool $live_sms_check ): array {
		$this->validate_scope( $request );
		$declared = $this->declared_values->resolve( $request );
		$declared_kopecks = $declared->get_kopecks();
		$sender = $this->sender_warehouses->resolve( $request );
		$destination = $this->destinations->resolve( $request );
		$receiver_warehouse_id = (string) ( $destination['warehouse_id'] ?? '' );
		$correlation = $this->correlations->resolve( $request, (string) $sender['warehouseId'], $receiver_warehouse_id );
		$cargo = $this->cargo->build( $request, $declared_kopecks );
		$cargo['payload']['common']['customerCorrelation'] = $correlation;
		$cargo['payload']['common']['orderNumber'] = (string) ( $request->meta['order_num'] ?? $request->order_id );
		$sealing = $this->product_weights->sealing_required( $request );
		$counterpart_guid = $this->settings->sender_counterpart_guid();
		$this->assert_counterpart_current( $counterpart_guid );
		$sms = $live_sms_check
			? $this->sms->check( $counterpart_guid, (string) ( $sender['branchId'] ?? '' ), (string) ( $destination['branch_id'] ?? '' ), $declared_kopecks )
			: new PekSmsReleaseResult( true, $this->settings->sms_release_limit_rub() * 100, true, true );
		if ( ! $sms->success ) {
			throw new \RuntimeException( $sms->message );
		}
		$common = array( 'orderType' => 0 );
		$client_card = trim( $this->settings->client_card() );
		if ( '' !== $client_card ) {
			$common['counterpartClientCard'] = $client_card;
		}
		$payload = array(
			'common' => $common,
			'sender' => $this->sender_payload( $sender ),
			'cargos' => array(
				array_merge(
					$cargo['payload'],
					array(
						'receiver' => $this->recipients->build_physical_recipient( $order, $request, $receiver_warehouse_id ),
						'services' => $this->services( $request, $declared_kopecks, $sealing ),
					)
				),
			),
		);
		$summary = array(
			'correlation' => $correlation,
			'sender_warehouse' => $sender,
			'receiver_warehouse_id' => $receiver_warehouse_id,
			'receiver_branch_id' => (string) ( $destination['branch_id'] ?? '' ),
			'destination' => $destination,
			'declared_value_kopecks' => $declared_kopecks,
			'product_weight_g' => $this->product_weights->product_weight_g( $request ),
			'sms' => $sms->to_safe_array(),
			'cargo' => $cargo['summary'],
			'shipment_mode' => $request->delivery_type,
			'recipient_type' => 'physical',
			'sealing_requested' => $sealing,
		);

		return array( 'payload' => $payload, 'preview' => $this->preview( $summary ), 'summary' => $summary );
	}

	/** @return array<int,string> */
	public function validate( ShipmentCreateRequest $request ): array {
		try {
			$this->validate_scope( $request );
			return array();
		} catch ( \Throwable $e ) {
			return array( $e->getMessage() );
		}
	}

	private function validate_scope( ShipmentCreateRequest $request ): void {
		if ( PekSettings::CARRIER_KEY !== $request->carrier_key ) {
			throw new \RuntimeException( 'Некорректный carrier_key для ПЭК.' );
		}
		if ( ! in_array( $request->delivery_type, array( DeliveryType::PICKUP, DeliveryType::COURIER ), true ) ) {
			throw new \RuntimeException( 'ПЭК поддерживает только pickup и courier.' );
		}
		if ( 'RU' !== strtoupper( $request->recipient_address->country_code ) ) {
			throw new \RuntimeException( 'Создание отправлений ПЭК поддерживает только RU.' );
		}
	}

	private function assert_counterpart_current( string $counterpart_guid ): void {
		$snapshot = $this->settings->sender_counterpart_snapshot();
		if (
			'' === $counterpart_guid
			|| $counterpart_guid !== (string) ( $snapshot['guid'] ?? '' )
			|| (int) ( $snapshot['legalForm'] ?? 0 ) !== $this->settings->sender_legal_form()
			|| (string) ( $snapshot['identity_hash'] ?? '' ) !== $this->settings->sender_identity_hash()
		) {
			throw new \RuntimeException( 'Данные отправителя ПЭК изменились. Повторно подтвердите контрагента в настройках.' );
		}
	}

	/** @param array<string,mixed> $sender @return array<string,mixed> */
	private function sender_payload( array $sender ): array {
		$this->validate_sender_settings();
		$payload = array(
			'legalForm' => $this->settings->sender_legal_form(),
			'fs' => $this->settings->sender_fs(),
			'title' => $this->settings->sender_full_name(),
			'inn' => $this->settings->sender_inn(),
			'countryOfRegistrationCode' => $this->settings->sender_registration_classifier_code(),
			'person' => $this->settings->sender_contact_name(),
			'personPhones' => array( array( 'phone' => $this->normalize_ru_phone( $this->settings->sender_phone() ) ) ),
			'warehouseId' => (string) $sender['warehouseId'],
		);
		$kpp = trim( $this->settings->sender_kpp() );
		if ( '' !== $kpp ) {
			$payload['kpp'] = $kpp;
		}
		$email = trim( $this->settings->sender_email() );
		if ( '' !== $email && function_exists( 'is_email' ) && false === is_email( $email ) ) {
			throw new \RuntimeException( 'Некорректный email отправителя ПЭК.' );
		}
		if ( '' !== $email ) {
			$payload['email'] = $email;
		}

		return $payload;
	}

	/** @return array<string,mixed> */
	private function services( ShipmentCreateRequest $request, int $declared_kopecks, bool $sealing ): array {
		$services = array(
			'transporting' => array( 'payer' => array( 'type' => 1 ) ),
			'insurance' => array( 'enabled' => true, 'payer' => array( 'type' => 1 ), 'cost' => $this->kopecks_to_rub_number( $declared_kopecks ) ),
		);
		if ( DeliveryType::COURIER === $request->delivery_type ) {
			$services['delivery'] = array( 'enabled' => true, 'payer' => array( 'type' => 1 ) );
		}
		if ( $sealing ) {
			$services['sealing'] = array( 'enabled' => true, 'payer' => array( 'type' => 1 ) );
		}

		return $services;
	}

	private function validate_sender_settings(): void {
		$legal_form = $this->settings->sender_legal_form();
		foreach ( array(
			(string) $legal_form,
			$this->settings->sender_fs(),
			$this->settings->sender_full_name(),
			$this->settings->sender_inn(),
			$this->settings->sender_registration_classifier_code(),
			$this->settings->sender_contact_name(),
		) as $value ) {
			if ( '' === trim( $value ) ) {
				throw new \RuntimeException( 'Не заполнены обязательные данные отправителя ПЭК.' );
			}
		}
		$inn = preg_replace( '/\D+/', '', $this->settings->sender_inn() ) ?? '';
		$kpp = preg_replace( '/\D+/', '', $this->settings->sender_kpp() ) ?? '';
		if ( PekSettings::LEGAL_FORM_LEGAL_ENTITY === $legal_form && 10 !== strlen( $inn ) ) {
			throw new \RuntimeException( 'Некорректный ИНН юрлица-отправителя ПЭК.' );
		}
		if ( PekSettings::LEGAL_FORM_INDIVIDUAL_ENTREPRENEUR === $legal_form && 12 !== strlen( $inn ) ) {
			throw new \RuntimeException( 'Некорректный ИНН ИП-отправителя ПЭК.' );
		}
		if ( '' === $this->normalize_ru_phone( $this->settings->sender_phone() ) ) {
			throw new \RuntimeException( 'Некорректный телефон отправителя ПЭК.' );
		}
		if ( PekSettings::LEGAL_FORM_LEGAL_ENTITY === $legal_form && 9 !== strlen( $kpp ) ) {
			throw new \RuntimeException( 'Для юрлица-отправителя ПЭК нужен КПП.' );
		}
	}

	private function normalize_ru_phone( string $value ): string {
		$value = preg_replace( '/[^\d+]/', '', $value ) ?? '';
		if ( 1 === preg_match( '/^8(\d{10})$/', $value, $matches ) ) {
			return '+7' . $matches[1];
		}
		if ( 1 === preg_match( '/^7(\d{10})$/', $value, $matches ) ) {
			return '+7' . $matches[1];
		}
		if ( 1 === preg_match( '/^\+7\d{10}$/', $value ) ) {
			return $value;
		}

		return '';
	}

	private function kopecks_to_rub_number( int $kopecks ): int|float {
		$rubles = intdiv( $kopecks, 100 );
		$cents = $kopecks % 100;
		if ( 0 === $cents ) {
			return $rubles;
		}

		return (float) ( $rubles . '.' . str_pad( (string) $cents, 2, '0', STR_PAD_LEFT ) );
	}

	/** @param array<string,mixed> $summary @return array<string,mixed> */
	private function preview( array $summary ): array {
		$sender = is_array( $summary['sender_warehouse'] ?? null ) ? $summary['sender_warehouse'] : array();
		$cargo = is_array( $summary['cargo'] ?? null ) ? $summary['cargo'] : array();
		$sms = is_array( $summary['sms'] ?? null ) ? $summary['sms'] : array();

		return array(
			'orderType' => 0,
			'type' => PekSettings::LTL_PRODUCT_TYPE,
			'correlation_hash' => hash( 'sha256', (string) ( $summary['correlation'] ?? '' ) ),
			'sender_warehouse_id' => (string) ( $sender['warehouseId'] ?? '' ),
			'sender_warehouse_title' => (string) ( $sender['divisionName'] ?? $sender['branchName'] ?? '' ),
			'sender_warehouse_source' => (string) ( $sender['source'] ?? '' ),
			'receiver_mode' => (string) ( $summary['shipment_mode'] ?? '' ),
			'receiver_warehouse_id' => (string) ( $summary['receiver_warehouse_id'] ?? '' ),
			'courier_address_present' => '' === (string) ( $summary['receiver_warehouse_id'] ?? '' ),
			'place_count' => (int) ( $cargo['place_count'] ?? 0 ),
			'aggregate_weight_kg' => $cargo['aggregate_weight_kg'] ?? null,
			'aggregate_volume_m3' => $cargo['aggregate_volume_m3'] ?? null,
			'insurance_enabled' => true,
			'insurance_value_kopecks' => (int) ( $summary['declared_value_kopecks'] ?? 0 ),
			'sms_release_requested' => true,
			'sms_release_confirmed' => ! empty( $sms['success'] ),
			'sms_effective_limit_kopecks' => (int) ( $sms['effective_limit_kopecks'] ?? 0 ),
			'payers' => array_filter(
				array(
					'transporting' => 1,
					'insurance' => 1,
					'delivery' => DeliveryType::COURIER === (string) ( $summary['shipment_mode'] ?? '' ) ? 1 : null,
					'sealing' => ! empty( $summary['sealing_requested'] ) ? 1 : null,
				),
				static fn( mixed $value ): bool => null !== $value
			),
			'sealing' => ! empty( $summary['sealing_requested'] ),
			'product_weight_g' => (int) ( $summary['product_weight_g'] ?? 0 ),
			'client_card_present' => '' !== $this->settings->client_card(),
			'counterpart_confirmed' => '' !== $this->settings->sender_counterpart_guid(),
		);
	}
}
