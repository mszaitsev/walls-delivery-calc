<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Pickup;
defined( 'ABSPATH' ) || exit;
final class OzonDeliveryPickupImportLock {
	private const OPTION = 'wdc_ozon_delivery_pickup_import_lock';
	private const TTL = 900;
	public function acquire(): ?string { $owner = bin2hex( random_bytes( 16 ) ); $value = array( 'owner' => $owner, 'expires_at' => time() + self::TTL ); if ( add_option( self::OPTION, $value, '', 'no' ) ) { return $owner; } $current = get_option( self::OPTION, array() ); if ( is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) < time() ) { delete_option( self::OPTION ); return add_option( self::OPTION, $value, '', 'no' ) ? $owner : null; } return null; }
	public function renew( string $owner ): bool { $current = get_option( self::OPTION, array() ); if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['owner'] ?? '' ), $owner ) ) { return false; } return update_option( self::OPTION, array( 'owner' => $owner, 'expires_at' => time() + self::TTL ), false ); }
	public function release( string $owner ): void { $current = get_option( self::OPTION, array() ); if ( is_array( $current ) && hash_equals( (string) ( $current['owner'] ?? '' ), $owner ) ) { delete_option( self::OPTION ); } }
}
