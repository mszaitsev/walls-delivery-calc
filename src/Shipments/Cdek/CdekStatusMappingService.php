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
			'REMOVED' => DeliveryStatus::CANCELLED,
			'RECEIVED_AT_SHIPMENT_WAREHOUSE' => DeliveryStatus::IN_TRANSIT,
			'DELIVERED' => DeliveryStatus::DELIVERED,
			'NOT_DELIVERED' => DeliveryStatus::REJECTED,
			'READY_FOR_SHIPMENT_IN_SENDER_CITY' => DeliveryStatus::IN_TRANSIT,
			'TAKEN_BY_TRANSPORTER_FROM_SENDER_CITY' => DeliveryStatus::IN_TRANSIT,
			'SENT_TO_RECIPIENT_CITY' => DeliveryStatus::IN_TRANSIT,
			'ACCEPTED_IN_RECIPIENT_CITY' => DeliveryStatus::IN_TRANSIT,
			'ACCEPTED_AT_RECIPIENT_CITY_WAREHOUSE' => DeliveryStatus::IN_TRANSIT,
			'TAKEN_BY_COURIER' => DeliveryStatus::HANDED_TO_COURIER,
			'ACCEPTED_AT_PICK_UP_POINT' => DeliveryStatus::READY_FOR_PICKUP,
			'ACCEPTED_AT_TRANSIT_WAREHOUSE' => DeliveryStatus::IN_TRANSIT,
			'RETURNED_TO_SENDER_CITY_WAREHOUSE' => DeliveryStatus::RETURNED_TO_SENDER,
			'RETURNED_TO_TRANSIT_WAREHOUSE' => DeliveryStatus::IN_TRANSIT,
			'RETURNED_TO_RECIPIENT_CITY_WAREHOUSE' => DeliveryStatus::IN_TRANSIT,
			'READY_FOR_SHIPMENT_IN_TRANSIT_CITY' => DeliveryStatus::IN_TRANSIT,
			'TAKEN_BY_TRANSPORTER_FROM_TRANSIT_CITY' => DeliveryStatus::IN_TRANSIT,
			'SENT_TO_TRANSIT_CITY' => DeliveryStatus::IN_TRANSIT,
			'ACCEPTED_IN_TRANSIT_CITY' => DeliveryStatus::IN_TRANSIT,
			'SENT_TO_SENDER_CITY' => DeliveryStatus::RETURNING_TO_SENDER,
			'ACCEPTED_IN_SENDER_CITY' => DeliveryStatus::RETURNING_TO_SENDER,
			'ENTERED_TO_TRANSIT_WAREHOUSE' => DeliveryStatus::IN_TRANSIT,
			'ENTERED_TO_RECIPIENT_CITY_WAREHOUSE' => DeliveryStatus::IN_TRANSIT,
			'ENTERED_TO_PICK_UP_POINT' => DeliveryStatus::READY_FOR_PICKUP,
			'IN_CUSTOMS_INTERNATIONAL' => DeliveryStatus::IN_TRANSIT,
			'SHIPPED_TO_DESTINATION' => DeliveryStatus::IN_TRANSIT,
			'PASSED_TO_TRANSIT_CARRIER' => DeliveryStatus::IN_TRANSIT,
			'IN_CUSTOMS_LOCAL' => DeliveryStatus::IN_TRANSIT,
			'CUSTOMS_COMPLETE' => DeliveryStatus::IN_TRANSIT,
			'POSTOMAT_POSTED' => DeliveryStatus::READY_FOR_PICKUP,
			'POSTOMAT_SEIZED' => DeliveryStatus::RETURNING_TO_SENDER,
			'POSTOMAT_RECEIVED' => DeliveryStatus::DELIVERED,
			'INVALID' => DeliveryStatus::REJECTED,
		);
	}

	/**
	 * @return array<string,string>
	 */
	public static function status_labels(): array {
		return array(
			'ACCEPTED' => 'Принят',
			'CREATED' => 'Создан',
			'REMOVED' => 'Удален',
			'RECEIVED_AT_SHIPMENT_WAREHOUSE' => 'Принят на склад отправителя',
			'DELIVERED' => 'Вручен',
			'NOT_DELIVERED' => 'Не вручен',
			'READY_FOR_SHIPMENT_IN_SENDER_CITY' => 'Готов к отправке в городе-отправителе',
			'TAKEN_BY_TRANSPORTER_FROM_SENDER_CITY' => 'Сдан перевозчику в городе-отправителе',
			'SENT_TO_RECIPIENT_CITY' => 'Отправлен в город-получатель',
			'ACCEPTED_IN_RECIPIENT_CITY' => 'Встречен в городе-получателе',
			'ACCEPTED_AT_RECIPIENT_CITY_WAREHOUSE' => 'Принят на склад доставки',
			'TAKEN_BY_COURIER' => 'Выдан на доставку',
			'ACCEPTED_AT_PICK_UP_POINT' => 'Принят на склад до востребования',
			'ACCEPTED_AT_TRANSIT_WAREHOUSE' => 'Принят на склад транзита',
			'RETURNED_TO_SENDER_CITY_WAREHOUSE' => 'Возвращен на склад отправителя',
			'RETURNED_TO_TRANSIT_WAREHOUSE' => 'Возвращен на склад транзита',
			'RETURNED_TO_RECIPIENT_CITY_WAREHOUSE' => 'Возвращен на склад доставки',
			'READY_FOR_SHIPMENT_IN_TRANSIT_CITY' => 'Выдан на отправку в городе-транзите',
			'TAKEN_BY_TRANSPORTER_FROM_TRANSIT_CITY' => 'Сдан перевозчику в городе-транзите',
			'SENT_TO_TRANSIT_CITY' => 'Отправлен в город-транзит',
			'ACCEPTED_IN_TRANSIT_CITY' => 'Встречен в городе-транзите',
			'SENT_TO_SENDER_CITY' => 'Отправлен в город-отправитель',
			'ACCEPTED_IN_SENDER_CITY' => 'Встречен в городе-отправителе',
			'ENTERED_TO_TRANSIT_WAREHOUSE' => 'Поступил в город транзита',
			'ENTERED_TO_RECIPIENT_CITY_WAREHOUSE' => 'Поступил на склад доставки',
			'ENTERED_TO_PICK_UP_POINT' => 'Поступил на склад до востребования',
			'IN_CUSTOMS_INTERNATIONAL' => 'Таможенное оформление в стране отправления',
			'SHIPPED_TO_DESTINATION' => 'Отправлено в страну назначения',
			'PASSED_TO_TRANSIT_CARRIER' => 'Передано транзитному перевозчику',
			'IN_CUSTOMS_LOCAL' => 'Таможенное оформление в стране назначения',
			'CUSTOMS_COMPLETE' => 'Таможенное оформление завершено',
			'POSTOMAT_POSTED' => 'Заложен в постамат',
			'POSTOMAT_SEIZED' => 'Изъят из постамата курьером',
			'POSTOMAT_RECEIVED' => 'Изъят из постамата клиентом',
			'INVALID' => 'Некорректный заказ',
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
	 */
	public function save_mapping( array $mapping ): void {
		$this->settings->set( self::MAPPING_KEY, $this->sanitize_mapping( $mapping ) );
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
