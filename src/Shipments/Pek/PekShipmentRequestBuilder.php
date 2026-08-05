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
		private PekSmsReleaseAvailabilityService $sms
	) {
	}

	/** @return array{payload:array<string,mixed>,preview:array<string,mixed>,summary:array<string,mixed>} */
	public function build( object $order, ShipmentCreateRequest $request, bool $live_sms_check ): array {
		$this->validate_scope( $request );
		$declared = $this->declared_values->resolve( $request );
		$declared_kopecks = $declared->get_kopecks();
		$sender = $this->sender_warehouses->resolve( $request );
		$receiver_warehouse_id = $this->receiver_warehouse_id( $request );
		$cargo = $this->cargo->build( $request, $declared_kopecks );
		$counterpart_guid = $this->settings->sender_counterpart_guid();
		if ( '' === $counterpart_guid ) {
			throw new \RuntimeException( 'Не подтверждён контрагент отправителя ПЭК.' );
		}
		$sms = $live_sms_check
			? $this->sms->check( $counterpart_guid, (string) ( $sender['branchId'] ?? '' ), $this->receiver_branch_id( $request ), $declared_kopecks )
			: new PekSmsReleaseResult( true, $this->settings->sms_release_limit_rub() * 100, true, true );
		if ( ! $sms->success ) {
			throw new \RuntimeException( $sms->message );
		}
		$correlation = $this->correlations->resolve( $request, (string) $sender['warehouseId'], $receiver_warehouse_id );
		$payload = array(
			'common' => array(
				'orderType' => 0,
				'type' => PekSettings::LTL_PRODUCT_TYPE,
				'customerCorrelation' => $correlation,
				'orderNumber' => (string) ( $request->meta['order_num'] ?? $request->order_id ),
				'counterpartClientCard' => $this->settings->client_card(),
			),
			'sender' => $this->sender_payload( $sender ),
			'cargos' => array(
				array_merge(
					$cargo['payload'],
					array(
						'receiver' => $this->recipients->build_physical_recipient( $order, $request, $receiver_warehouse_id ),
						'services' => $this->services( $request, $declared_kopecks ),
					)
				),
			),
			'payer' => array( 'type' => 'sender' ),
		);
		$summary = array(
			'correlation' => $correlation,
			'sender_warehouse' => $sender,
			'receiver_warehouse_id' => $receiver_warehouse_id,
			'receiver_branch_id' => $this->receiver_branch_id( $request ),
			'declared_value_kopecks' => $declared_kopecks,
			'sms' => $sms->to_safe_array(),
			'cargo' => $cargo['summary'],
			'shipment_mode' => $request->delivery_type,
			'recipient_type' => 'physical',
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

	/** @param array<string,mixed> $sender @return array<string,mixed> */
	private function sender_payload( array $sender ): array {
		return array(
			'legalForm' => $this->settings->sender_legal_form(),
			'fs' => $this->settings->sender_fs(),
			'title' => $this->settings->sender_full_name(),
			'inn' => $this->settings->sender_inn(),
			'kpp' => $this->settings->sender_kpp(),
			'countryOfRegistrationCode' => $this->settings->sender_registration_classifier_code(),
			'person' => $this->settings->sender_contact_name(),
			'phone' => $this->settings->sender_phone(),
			'email' => $this->settings->sender_email(),
			'warehouseId' => (string) $sender['warehouseId'],
		);
	}

	/** @return array<string,mixed> */
	private function services( ShipmentCreateRequest $request, int $declared_kopecks ): array {
		return array(
			'transporting' => array( 'enabled' => true, 'payer' => 'sender' ),
			'insurance' => array( 'enabled' => true, 'payer' => 'sender', 'cost' => round( $declared_kopecks / 100, 2 ) ),
			'delivery' => array( 'enabled' => DeliveryType::COURIER === $request->delivery_type, 'payer' => 'sender' ),
			'smsRelease' => array( 'enabled' => true, 'payer' => 'sender' ),
			'hardPacking' => array( 'enabled' => false ),
			'sealing' => array( 'enabled' => false ),
		);
	}

	private function receiver_warehouse_id( ShipmentCreateRequest $request ): string {
		if ( DeliveryType::COURIER === $request->delivery_type ) {
			return '';
		}
		$code = trim( (string) ( $request->pickup_point?->point_code ?? $request->meta['pickup_point_code'] ?? '' ) );
		if ( '' === $code ) {
			throw new \RuntimeException( 'Не выбран терминал ПЭК.' );
		}

		return $code;
	}

	private function receiver_branch_id( ShipmentCreateRequest $request ): string {
		$branch = trim( (string) ( $request->meta['pek_receiver_branch_id'] ?? $request->meta['receiver_branch_id'] ?? '' ) );
		if ( '' === $branch && is_array( $request->meta['pickup_provider_query'] ?? null ) ) {
			$branch = trim( (string) ( $request->meta['pickup_provider_query']['branchId'] ?? '' ) );
		}
		if ( '' === $branch ) {
			$branch = trim( (string) ( $request->meta['pek_destination_branch_id'] ?? '' ) );
		}
		if ( '' === $branch ) {
			throw new \RuntimeException( 'Не подтверждён филиал назначения ПЭК для SMS.' );
		}

		return $branch;
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
			'payers' => array( 'transporting' => 'sender', 'insurance' => 'sender', 'delivery' => 'sender', 'smsRelease' => 'sender' ),
			'sealing' => false,
			'client_card_present' => '' !== $this->settings->client_card(),
			'counterpart_confirmed' => '' !== $this->settings->sender_counterpart_guid(),
		);
	}
}
