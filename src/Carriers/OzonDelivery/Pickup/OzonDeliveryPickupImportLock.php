<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Pickup;
defined( 'ABSPATH' ) || exit;
final class OzonDeliveryPickupImportLock {
	private const OPTION = 'wdc_ozon_delivery_pickup_import_lock';
	private const TTL = 900;
	public function acquire(): ?string { $owner = bin2hex( random_bytes( 16 ) ); $value = array( 'owner' => $owner, 'expires_at' => time() + self::TTL ); if ( add_option( self::OPTION, $value, '', 'no' ) ) { return $owner; } $current = get_option( self::OPTION, array() ); if ( is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) <= time() ) { delete_option( self::OPTION ); return add_option( self::OPTION, $value, '', 'no' ) ? $owner : null; } return null; }
	public function renew( string $owner ): bool { $current = get_option( self::OPTION, array() ); $now = time(); $current_expiry = (int) ( is_array( $current ) ? ( $current['expires_at'] ?? 0 ) : 0 ); if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['owner'] ?? '' ), $owner ) || $current_expiry <= $now ) { return false; } $new_expiry = max( $now + self::TTL, $current_expiry + 1 ); $value = array( 'owner' => $owner, 'expires_at' => $new_expiry ); if ( update_option( self::OPTION, $value, false ) ) { return true; } $persisted = get_option( self::OPTION, array() ); return is_array( $persisted ) && hash_equals( (string) ( $persisted['owner'] ?? '' ), $owner ) && (int) ( $persisted['expires_at'] ?? 0 ) >= $new_expiry; }
	public function current_owner(): ?string { $current = get_option( self::OPTION, array() ); if ( ! is_array( $current ) || (int) ( $current['expires_at'] ?? 0 ) <= time() ) { return null; } $owner = (string) ( $current['owner'] ?? '' ); return '' !== $owner ? $owner : null; }
	public function owns( string $owner ): bool { $current = get_option( self::OPTION, array() ); return is_array( $current ) && hash_equals( (string) ( $current['owner'] ?? '' ), $owner ); }
	public function release( string $owner ): void { $current = get_option( self::OPTION, array() ); if ( is_array( $current ) && hash_equals( (string) ( $current['owner'] ?? '' ), $owner ) ) { delete_option( self::OPTION ); } }
}
