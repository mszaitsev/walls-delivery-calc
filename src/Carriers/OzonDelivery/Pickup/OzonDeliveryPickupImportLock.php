<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Pickup;
defined( 'ABSPATH' ) || exit;
final class OzonDeliveryPickupImportLock {
	private const OPTION = 'wdc_ozon_delivery_pickup_import_lock';
	private const TTL = 900;
	private const EXECUTION_TTL = 900;
	private mixed $wpdb;
	public function __construct( mixed $db = null ) { if ( is_object( $db ) ) { $this->wpdb = $db; return; } global $wpdb; $this->wpdb = is_object( $wpdb ?? null ) ? $wpdb : null; }
	public function acquire(): ?string { $owner = bin2hex( random_bytes( 16 ) ); $value = array( 'owner' => $owner, 'expires_at' => time() + self::TTL ); if ( add_option( self::OPTION, $value, '', 'no' ) ) { return $owner; } $current = get_option( self::OPTION, array() ); if ( is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) <= time() && $this->compare_and_delete( $current ) ) { return add_option( self::OPTION, $value, '', 'no' ) ? $owner : null; } return null; }
	public function renew( string $owner ): bool { $current = get_option( self::OPTION, array() ); $now = time(); $current_expiry = (int) ( is_array( $current ) ? ( $current['expires_at'] ?? 0 ) : 0 ); if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['owner'] ?? '' ), $owner ) || $current_expiry <= $now ) { return false; } $value = $current; $value['expires_at'] = max( $now + self::TTL, $current_expiry + 1 ); return $this->compare_and_replace( $current, $value ); }
	public function claim_execution( string $owner ): ?string {
		$current = get_option( self::OPTION, array() ); $now = time();
		if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['owner'] ?? '' ), $owner ) || (int) ( $current['expires_at'] ?? 0 ) <= $now || ( '' !== (string) ( $current['execution_token'] ?? '' ) && (int) ( $current['execution_expires_at'] ?? 0 ) > $now ) ) { return null; }
		$token = bin2hex( random_bytes( 16 ) ); $replacement = $current; $replacement['expires_at'] = max( $now + self::TTL, (int) $current['expires_at'] + 1 ); $replacement['execution_token'] = $token; $replacement['execution_expires_at'] = $now + self::EXECUTION_TTL;
		return $this->compare_and_replace( $current, $replacement ) ? $token : null;
	}
	public function renew_execution( string $owner, string $token ): bool {
		$current = get_option( self::OPTION, array() ); $now = time();
		if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['owner'] ?? '' ), $owner ) || ! hash_equals( (string) ( $current['execution_token'] ?? '' ), $token ) || (int) ( $current['expires_at'] ?? 0 ) <= $now || (int) ( $current['execution_expires_at'] ?? 0 ) <= $now ) { return false; }
		$replacement = $current; $replacement['expires_at'] = max( $now + self::TTL, (int) $current['expires_at'] + 1 ); $replacement['execution_expires_at'] = max( $now + self::EXECUTION_TTL, (int) $current['execution_expires_at'] + 1 );
		return $this->compare_and_replace( $current, $replacement );
	}
	public function owns_execution( string $owner, string $token ): bool { $current = get_option( self::OPTION, array() ); $now = time(); return is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) > $now && (int) ( $current['execution_expires_at'] ?? 0 ) > $now && hash_equals( (string) ( $current['owner'] ?? '' ), $owner ) && hash_equals( (string) ( $current['execution_token'] ?? '' ), $token ); }
	public function release_execution( string $owner, string $token ): void { for ( $attempt = 0; $attempt < 2; ++$attempt ) { $current = get_option( self::OPTION, array() ); if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['owner'] ?? '' ), $owner ) || ! hash_equals( (string) ( $current['execution_token'] ?? '' ), $token ) ) { return; } $replacement = $current; unset( $replacement['execution_token'], $replacement['execution_expires_at'] ); if ( $this->compare_and_replace( $current, $replacement ) ) { return; } $this->clear_option_cache(); } }
	public function current_owner(): ?string { $current = get_option( self::OPTION, array() ); if ( ! is_array( $current ) || (int) ( $current['expires_at'] ?? 0 ) <= time() ) { return null; } $owner = (string) ( $current['owner'] ?? '' ); return '' !== $owner ? $owner : null; }
	public function owns( string $owner ): bool { $current = get_option( self::OPTION, array() ); return is_array( $current ) && (int) ( $current['expires_at'] ?? 0 ) > time() && hash_equals( (string) ( $current['owner'] ?? '' ), $owner ); }
	public function release( string $owner ): void { for ( $attempt = 0; $attempt < 2; ++$attempt ) { $current = get_option( self::OPTION, array() ); if ( ! is_array( $current ) || ! hash_equals( (string) ( $current['owner'] ?? '' ), $owner ) ) { return; } if ( $this->compare_and_delete( $current ) ) { return; } $this->clear_option_cache(); } }
	/** @param array<string,mixed> $expected */
	private function compare_and_delete( array $expected ): bool {
		if ( ! is_object( $this->wpdb ) || ! isset( $this->wpdb->options ) || ! method_exists( $this->wpdb, 'prepare' ) || ! method_exists( $this->wpdb, 'query' ) ) { if ( get_option( self::OPTION, array() ) !== $expected ) { return false; } return delete_option( self::OPTION ); }
		$sql = $this->wpdb->prepare( "DELETE FROM {$this->wpdb->options} WHERE option_name=%s AND option_value=%s LIMIT 1", self::OPTION, $this->serialize( $expected ) );
		$result = $this->wpdb->query( $sql );
		if ( false === $result ) { throw new \RuntimeException( 'Ozon pickup import lock compare-delete failed.' ); }
		if ( 1 !== (int) $result ) { return false; }
		$this->clear_option_cache();
		return true;
	}
	/** @param array<string,mixed> $expected @param array<string,mixed> $replacement */
	private function compare_and_replace( array $expected, array $replacement ): bool {
		if ( ! is_object( $this->wpdb ) || ! isset( $this->wpdb->options ) || ! method_exists( $this->wpdb, 'prepare' ) || ! method_exists( $this->wpdb, 'query' ) ) { if ( get_option( self::OPTION, array() ) !== $expected ) { return false; } if ( update_option( self::OPTION, $replacement, false ) ) { return true; } $persisted = get_option( self::OPTION, array() ); return $persisted === $replacement; }
		$sql = $this->wpdb->prepare( "UPDATE {$this->wpdb->options} SET option_value=%s WHERE option_name=%s AND option_value=%s LIMIT 1", $this->serialize( $replacement ), self::OPTION, $this->serialize( $expected ) );
		$result = $this->wpdb->query( $sql );
		if ( false === $result ) { throw new \RuntimeException( 'Ozon pickup import lock compare-renew failed.' ); }
		if ( 1 !== (int) $result ) { $this->clear_option_cache(); return false; }
		$this->clear_option_cache();
		return true;
	}
	/** @param array<string,mixed> $value */
	private function serialize( array $value ): string { return (string) ( function_exists( 'maybe_serialize' ) ? maybe_serialize( $value ) : serialize( $value ) ); }
	private function clear_option_cache(): void { if ( function_exists( 'wp_cache_delete' ) ) { wp_cache_delete( self::OPTION, 'options' ); wp_cache_delete( 'notoptions', 'options' ); wp_cache_delete( 'alloptions', 'options' ); } }
}
