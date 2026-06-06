<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Status;

final class DeliveryStatus {
	public const CREATED_IN_CARRIER  = 'created_in_carrier';
	public const IN_TRANSIT          = 'in_transit';
	public const READY_FOR_PICKUP    = 'ready_for_pickup';
	public const HANDED_TO_COURIER   = 'handed_to_courier';
	public const DELIVERED           = 'delivered';
	public const RETURNING_TO_SENDER = 'returning_to_sender';
	public const RETURNED_TO_SENDER  = 'returned_to_sender';
	public const CANCELLED           = 'cancelled';
	public const REJECTED            = 'rejected';
	public const UNKNOWN             = 'unknown';

	public const ACCEPTED_AT_CARRIER_WAREHOUSE = self::CREATED_IN_CARRIER;
	public const PICKUP_READY                  = self::READY_FOR_PICKUP;
	public const RETURN_READY                  = self::RETURNING_TO_SENDER;

	/**
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array( self::CREATED_IN_CARRIER, self::IN_TRANSIT, self::READY_FOR_PICKUP, self::HANDED_TO_COURIER, self::DELIVERED, self::RETURNING_TO_SENDER, self::RETURNED_TO_SENDER, self::CANCELLED, self::REJECTED, self::UNKNOWN );
	}

	/**
	 * @return array<int,string>
	 */
	public static function terminal(): array {
		return array( self::DELIVERED, self::RETURNED_TO_SENDER, self::CANCELLED, self::REJECTED );
	}

	/**
	 * @return array<string,string>
	 */
	public static function labels(): array {
		return array(
			self::CREATED_IN_CARRIER => 'создан в ТК',
			self::IN_TRANSIT => 'в пути',
			self::READY_FOR_PICKUP => 'ожидает самовывоза из ПВЗ/постамата',
			self::HANDED_TO_COURIER => 'передан курьеру',
			self::DELIVERED => 'доставлен',
			self::RETURNING_TO_SENDER => 'возвращается отправителю',
			self::RETURNED_TO_SENDER => 'возвращен отправителю',
			self::CANCELLED => 'отменён',
			self::REJECTED => 'отказ',
			self::UNKNOWN => 'не определён',
		);
	}

	public static function label( string $value ): string {
		return self::labels()[ $value ] ?? self::labels()[ self::UNKNOWN ];
	}

	public static function is_valid( string $value ): bool {
		return in_array( $value, self::all(), true );
	}

	public static function is_terminal( string $value ): bool {
		return in_array( $value, self::terminal(), true );
	}
}
