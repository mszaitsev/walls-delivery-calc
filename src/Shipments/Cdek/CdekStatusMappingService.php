<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Cdek;

use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class CdekStatusMappingService {
	public const MAPPING_KEY = 'cdek_status_mapping';

	/**
	 * @return array<string,string>
	 */
	public static function default_mapping(): array {
		return array(
			'ACCEPTED' => DeliveryStatus::CREATED_IN_CARRIER,
			'CREATED' => DeliveryStatus::CREATED_IN_CARRIER,
			'INVALID' => DeliveryStatus::REJECTED,
			'REMOVED' => DeliveryStatus::CANCELLED,
			'RECEIVED_AT_SHIPMENT_WAREHOUSE' => DeliveryStatus::IN_TRANSIT,
			'READY_FOR_SHIPMENT_IN_SENDER_CITY' => DeliveryStatus::IN_TRANSIT,
			'TAKEN_BY_TRANSPORTER_FROM_SENDER_CITY' => DeliveryStatus::IN_TRANSIT,
			'SENT_TO_TRANSIT_CITY' => DeliveryStatus::IN_TRANSIT,
			'ACCEPTED_IN_TRANSIT_CITY' => DeliveryStatus::IN_TRANSIT,
			'SENT_TO_RECIPIENT_CITY' => DeliveryStatus::IN_TRANSIT,
			'ACCEPTED_IN_RECIPIENT_CITY' => DeliveryStatus::IN_TRANSIT,
			'READY_FOR_DELIVERY' => DeliveryStatus::IN_TRANSIT,
			'TAKEN_BY_COURIER' => DeliveryStatus::IN_TRANSIT,
			'DELIVERED' => DeliveryStatus::DELIVERED,
			'NOT_DELIVERED' => DeliveryStatus::REJECTED,
		);
	}

	/**
	 * @return array<string,string>
	 */
	public static function status_labels(): array {
		return array(
			'ACCEPTED' => 'Принят',
			'CREATED' => 'Создан',
			'INVALID' => 'Некорректный заказ',
			'REMOVED' => 'Удален',
			'RECEIVED_AT_SHIPMENT_WAREHOUSE' => 'Принят на склад отправителя',
			'READY_FOR_SHIPMENT_IN_SENDER_CITY' => 'Готов к отправке в городе отправителя',
			'TAKEN_BY_TRANSPORTER_FROM_SENDER_CITY' => 'Передан перевозчику в городе отправителя',
			'SENT_TO_TRANSIT_CITY' => 'Отправлен в транзитный город',
			'ACCEPTED_IN_TRANSIT_CITY' => 'Принят в транзитном городе',
			'SENT_TO_RECIPIENT_CITY' => 'Отправлен в город получателя',
			'ACCEPTED_IN_RECIPIENT_CITY' => 'Принят в городе получателя',
			'READY_FOR_DELIVERY' => 'Готов к доставке',
			'TAKEN_BY_COURIER' => 'Выдан курьеру',
			'DELIVERED' => 'Доставлен',
			'NOT_DELIVERED' => 'Не доставлен',
		);
	}

	public function __construct(
		private SettingsRepository $settings
	) {
	}

	/**
	 * @return array<string,string>
	 */
	public function mapping(): array {
		$stored = $this->settings->get_array( self::MAPPING_KEY, array() );
		$mapping = array_merge( self::default_mapping(), is_array( $stored ) ? $stored : array() );

		return $this->sanitize_mapping( $mapping );
	}

	public function universal_status_for( string $cdek_status_code ): string {
		$code = strtoupper( trim( $cdek_status_code ) );
		if ( '' === $code ) {
			return '';
		}
		$mapping = $this->mapping();

		return (string) ( $mapping[ $code ] ?? DeliveryStatus::UNKNOWN );
	}

	/**
	 * @param array<string,mixed> $mapping
	 * @return array<string,string>
	 */
	public function sanitize_mapping( array $mapping ): array {
		$result = array();
		foreach ( self::status_labels() as $code => $_label ) {
			$value = sanitize_key( (string) ( $mapping[ $code ] ?? '' ) );
			$result[ $code ] = DeliveryStatus::is_valid( $value ) ? $value : ( self::default_mapping()[ $code ] ?? DeliveryStatus::UNKNOWN );
		}

		return $result;
	}
}
