<?php
declare(strict_types=1);

namespace WallsShop\WDC\Domain\Status;

final class DeliveryStatus {
	public const ACCEPTED_AT_CARRIER_WAREHOUSE = 'accepted_at_carrier_warehouse';
	public const IN_TRANSIT                    = 'in_transit';
	public const PICKUP_READY                  = 'pickup_ready';
	public const HANDED_TO_COURIER             = 'handed_to_courier';
	public const DELIVERED                     = 'delivered';
	public const CANCELLED                     = 'cancelled';
	public const RETURNING_TO_SENDER           = 'returning_to_sender';
	public const RETURN_READY                  = 'return_ready';
	public const RETURNED_TO_SENDER            = 'returned_to_sender';
	public const UNKNOWN                       = 'unknown';

	/**
	 * @return array<int,string>
	 */
	public static function all(): array {
		return array( self::ACCEPTED_AT_CARRIER_WAREHOUSE, self::IN_TRANSIT, self::PICKUP_READY, self::HANDED_TO_COURIER, self::DELIVERED, self::CANCELLED, self::RETURNING_TO_SENDER, self::RETURN_READY, self::RETURNED_TO_SENDER, self::UNKNOWN );
	}

	/**
	 * @return array<int,string>
	 */
	public static function terminal(): array {
		return array( self::DELIVERED, self::CANCELLED, self::RETURNED_TO_SENDER );
	}

	public static function is_valid( string $value ): bool {
		return in_array( $value, self::all(), true );
	}

	public static function is_terminal( string $value ): bool {
		return in_array( $value, self::terminal(), true );
	}
}
