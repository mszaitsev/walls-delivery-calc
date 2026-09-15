<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\YandexDelivery;

use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class YandexStatusMapping {
	public const MAPPING_KEY = 'yandex_delivery_status_mapping';

	/**
	 * @return array<string,array{code:string,description:string,mode:string,default:string}>
	 */
	public static function statuses(): array {
		return array(
			'DRAFT' => self::status( 'DRAFT', 'Заказ создан', 'both', DeliveryStatus::PENDING_CREATION_IN_CARRIER ),
			'VALIDATING' => self::status( 'VALIDATING', 'Заявка находится на проверке', 'both', DeliveryStatus::PENDING_CREATION_IN_CARRIER ),
			'VALIDATING_ERROR' => self::status( 'VALIDATING_ERROR', 'Заказ не подтверждён в сортировочном центре', 'both', DeliveryStatus::REJECTED ),
			'CREATED' => self::status( 'CREATED', 'Заказ создан и подтверждён', 'both', DeliveryStatus::CREATED_IN_CARRIER ),
			'DELIVERY_PROCESSING_STARTED' => self::status( 'DELIVERY_PROCESSING_STARTED', 'Заказ создаётся в сортировочном центре', 'both', DeliveryStatus::CREATED_IN_CARRIER ),
			'DELIVERY_TRACK_RECIEVED' => self::status( 'DELIVERY_TRACK_RECIEVED', 'Заказ создан в системе службы доставки', 'both', DeliveryStatus::CREATED_IN_CARRIER ),
			'SORTING_CENTER_PROCESSING_STARTED' => self::status( 'SORTING_CENTER_PROCESSING_STARTED', 'Заказ начал обрабатываться в сортировочном центре', 'both', DeliveryStatus::CREATED_IN_CARRIER ),
			'SORTING_CENTER_TRACK_RECEIVED' => self::status( 'SORTING_CENTER_TRACK_RECEIVED', 'Заказ обработан в сортировочном центре', 'both', DeliveryStatus::CREATED_IN_CARRIER ),
			'SORTING_CENTER_TRACK_LOADED' => self::status( 'SORTING_CENTER_TRACK_LOADED', 'Заказ создан в сортировочном центре', 'both', DeliveryStatus::CREATED_IN_CARRIER ),
			'DELIVERY_LOADED' => self::status( 'DELIVERY_LOADED', 'Заказ добавлен в текущую отгрузку', 'both', DeliveryStatus::CREATED_IN_CARRIER ),
			'SORTING_CENTER_LOADED' => self::status( 'SORTING_CENTER_LOADED', 'Заказ подтверждён в сортировочном центре', 'both', DeliveryStatus::CREATED_IN_CARRIER ),
			'SORTING_CENTER_AT_START' => self::status( 'SORTING_CENTER_AT_START', 'Заказ поступил в точку приёма / сортировочный центр', 'both', DeliveryStatus::IN_TRANSIT ),
			'SORTING_CENTER_PREPARED' => self::status( 'SORTING_CENTER_PREPARED', 'Заказ готов к отправке в службу доставки', 'both', DeliveryStatus::IN_TRANSIT ),
			'SORTING_CENTER_TRANSMITTED' => self::status( 'SORTING_CENTER_TRANSMITTED', 'Заказ передан в дальнейшую доставку', 'both', DeliveryStatus::IN_TRANSIT ),
			'DELIVERY_AT_START' => self::status( 'DELIVERY_AT_START', 'Заказ находится в городе получателя и готовится к последней миле', 'both', DeliveryStatus::IN_TRANSIT ),
			'DELIVERY_AT_START_SORT' => self::status( 'DELIVERY_AT_START_SORT', 'Заказ находится в городе получателя и готовится к отправке курьером', 'courier', DeliveryStatus::IN_TRANSIT ),
			'DELIVERY_TRANSPORTATION_RECIPIENT' => self::status( 'DELIVERY_TRANSPORTATION_RECIPIENT', 'Заказ доставляется клиенту', 'courier', DeliveryStatus::HANDED_TO_COURIER ),
			'DELIVERY_TRANSPORTATION' => self::status( 'DELIVERY_TRANSPORTATION', 'Заказ выехал в пункт назначения', 'pickup', DeliveryStatus::IN_TRANSIT ),
			'DELIVERY_ARRIVED_PICKUP_POINT' => self::status( 'DELIVERY_ARRIVED_PICKUP_POINT', 'Заказ доставлен в ПВЗ или постамат', 'pickup', DeliveryStatus::READY_FOR_PICKUP ),
			'CONFIRMATION_CODE_RECEIVED' => self::status( 'CONFIRMATION_CODE_RECEIVED', 'Получен код подтверждения заказа', 'pickup', DeliveryStatus::READY_FOR_PICKUP ),
			'DELIVERY_TRANSMITTED_TO_RECIPIENT' => self::status( 'DELIVERY_TRANSMITTED_TO_RECIPIENT', 'Заказ выдан получателю', 'both', DeliveryStatus::DELIVERED ),
			'DELIVERY_ATTEMPT_FAILED' => self::status( 'DELIVERY_ATTEMPT_FAILED', 'Неудачная попытка вручения заказа', 'courier', DeliveryStatus::HANDED_TO_COURIER ),
			'DELIVERY_STORAGE_PERIOD_EXPIRED' => self::status( 'DELIVERY_STORAGE_PERIOD_EXPIRED', 'Срок хранения заказа в точке выдачи истёк', 'pickup', DeliveryStatus::RETURNING_TO_SENDER ),
			'PARTICULARLY_DELIVERED' => self::status( 'PARTICULARLY_DELIVERED', 'Заказ частично доставлен', 'pickup', DeliveryStatus::IN_TRANSIT ),
			'DELIVERY_DELIVERED' => self::status( 'DELIVERY_DELIVERED', 'Заказ доставлен получателю', 'both', DeliveryStatus::DELIVERED ),
			'CANCELLED' => self::status( 'CANCELLED', 'Отмена', 'both', DeliveryStatus::CANCELLED ),
			'SORTING_CENTER_RETURN_PREPARING' => self::status( 'SORTING_CENTER_RETURN_PREPARING', 'Заказ готовится к возврату', 'both', DeliveryStatus::RETURNING_TO_SENDER ),
			'SORTING_CENTER_RETURN_PREPARING_SENDER' => self::status( 'SORTING_CENTER_RETURN_PREPARING_SENDER', 'Заказ готов к отправке отправителю', 'both', DeliveryStatus::RETURNING_TO_SENDER ),
			'SORTING_CENTER_RETURN_ARRIVED' => self::status( 'SORTING_CENTER_RETURN_ARRIVED', 'Заказ доставлен в пункт отправителя', 'both', DeliveryStatus::RETURNING_TO_SENDER ),
			'SORTING_CENTER_RETURN_RETURNED' => self::status( 'SORTING_CENTER_RETURN_RETURNED', 'Заказ возвращён отправителю', 'both', DeliveryStatus::RETURNED_TO_SENDER ),
			'RETURN_PREPARING' => self::status( 'RETURN_PREPARING', 'Заказ в процессе возврата на складе сортировочного центра', 'courier', DeliveryStatus::RETURNING_TO_SENDER ),
			'RETURN_TRANSPORTATION_STARTED' => self::status( 'RETURN_TRANSPORTATION_STARTED', 'Заказ едет в точку возврата', 'both', DeliveryStatus::RETURNING_TO_SENDER ),
			'RETURN_ARRIVED_DELIVERY' => self::status( 'RETURN_ARRIVED_DELIVERY', 'Заказ возвращён на склад', 'both', DeliveryStatus::RETURNING_TO_SENDER ),
			'RETURN_TRANSMITTED_FULFILMENT' => self::status( 'RETURN_TRANSMITTED_FULFILMENT', 'Заказ передан на единый склад', 'courier', DeliveryStatus::RETURNING_TO_SENDER ),
			'RETURN_READY_FOR_PICKUP' => self::status( 'RETURN_READY_FOR_PICKUP', 'Заказ готов для передачи магазину', 'both', DeliveryStatus::RETURNING_TO_SENDER ),
			'RETURN_RETURNED' => self::status( 'RETURN_RETURNED', 'Заказ возвращён в магазин', 'both', DeliveryStatus::RETURNED_TO_SENDER ),
			'DELIVERY_TIME_INTERVALS_UPDATED' => self::status( 'DELIVERY_TIME_INTERVALS_UPDATED', 'Время доставки изменено', 'both', DeliveryStatus::IN_TRANSIT ),
		);
	}

	/** @return array<string,string> */
	public static function default_mapping(): array {
		$result = array();
		foreach ( self::statuses() as $code => $status ) {
			$result[ $code ] = $status['default'];
		}
		return $result;
	}

	public function __construct( private SettingsRepository $settings ) {
	}

	/** @return array<string,string> */
	public function mapping(): array {
		$stored = $this->settings->get_array( self::MAPPING_KEY, array() );
		return $this->sanitize_mapping( array_merge( self::default_mapping(), is_array( $stored ) ? $stored : array() ) );
	}

	public function universal_status_for( string $code ): string {
		$code = $this->normalize_code( $code );
		if ( '' === $code ) {
			return DeliveryStatus::UNKNOWN;
		}
		return (string) ( $this->mapping()[ $code ] ?? DeliveryStatus::UNKNOWN );
	}

	public function description_for( string $code ): string {
		$code = $this->normalize_code( $code );
		return (string) ( self::statuses()[ $code ]['description'] ?? '' );
	}

	public function mode_label( string $mode ): string {
		return match ( $mode ) {
			'courier' => 'До двери',
			'pickup' => 'ПВЗ/постамат',
			default => 'Оба сценария',
		};
	}

	public function known( string $code ): bool {
		return isset( self::statuses()[ $this->normalize_code( $code ) ] );
	}

	/** @param array<string,mixed> $mapping */
	public function save_mapping( array $mapping ): void {
		$this->settings->set( self::MAPPING_KEY, $this->sanitize_mapping( $mapping ) );
	}

	/**
	 * @param array<string,mixed> $mapping
	 * @return array<string,string>
	 */
	public function sanitize_mapping( array $mapping ): array {
		$result = array();
		foreach ( array_keys( self::statuses() ) as $code ) {
			$value = function_exists( 'sanitize_key' ) ? sanitize_key( (string) ( $mapping[ $code ] ?? '' ) ) : strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) ( $mapping[ $code ] ?? '' ) ) ?? '' );
			$result[ $code ] = DeliveryStatus::is_valid( $value ) ? $value : ( self::default_mapping()[ $code ] ?? DeliveryStatus::UNKNOWN );
		}
		return $result;
	}

	private static function status( string $code, string $description, string $mode, string $default ): array {
		return array( 'code' => $code, 'description' => $description, 'mode' => $mode, 'default' => $default );
	}

	private function normalize_code( string $code ): string {
		return strtoupper( trim( $code ) );
	}
}
