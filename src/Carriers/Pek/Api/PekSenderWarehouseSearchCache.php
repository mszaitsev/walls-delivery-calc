<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Api;

defined( 'ABSPATH' ) || exit;

final class PekSenderWarehouseSearchCache {
	public const DEFAULT_TTL_SECONDS = 600;
	public const MAX_TTL_SECONDS = 900;

	public function __construct( private int $ttl_seconds = self::DEFAULT_TTL_SECONDS ) {
	}

	/** @param array<string,mixed> $result */
	public function save_for_current_user( array $result ): void {
		set_transient( $this->key_for_current_user(), $this->sanitize_result( $result ), $this->ttl_seconds() );
	}

	/** @return array<string,mixed> */
	public function current_for_current_user(): array {
		$value = get_transient( $this->key_for_current_user() );

		return is_array( $value ) ? $this->sanitize_result( $value ) : array();
	}

	public function clear_for_current_user(): void {
		delete_transient( $this->key_for_current_user() );
	}

	public function ttl_seconds(): int {
		return max( 60, min( self::MAX_TTL_SECONDS, $this->ttl_seconds ) );
	}

	public function key_for_current_user(): string {
		$user_id = function_exists( 'get_current_user_id' ) ? max( 0, (int) get_current_user_id() ) : 0;

		return 'wdc_pek_sender_warehouse_search_' . $user_id;
	}

	/** @param array<string,mixed> $result @return array<string,mixed> */
	private function sanitize_result( array $result ): array {
		$out = array(
			'success' => ! empty( $result['success'] ),
			'message' => trim( (string) ( $result['message'] ?? '' ) ),
			'items' => array(),
			'requested' => is_array( $result['requested'] ?? null ) ? $this->sanitize_requested( $result['requested'] ) : array(),
			'checked_at' => trim( (string) ( $result['checked_at'] ?? '' ) ),
		);
		foreach ( is_array( $result['items'] ?? null ) ? $result['items'] : array() as $item ) {
			if ( is_array( $item ) ) {
				$out['items'][] = $this->sanitize_item( $item );
			}
		}

		return $out;
	}

	/** @param array<string,mixed> $requested @return array<string,mixed> */
	private function sanitize_requested( array $requested ): array {
		return array(
			'address' => trim( (string) ( $requested['address'] ?? '' ) ),
			'departmentOperation' => (int) ( $requested['departmentOperation'] ?? 0 ),
			'type' => (int) ( $requested['type'] ?? 0 ),
			'searchRadius' => (int) ( $requested['searchRadius'] ?? 0 ),
			'limit' => (int) ( $requested['limit'] ?? 0 ),
		);
	}

	/** @param array<string,mixed> $item @return array<string,mixed> */
	private function sanitize_item( array $item ): array {
		return array(
			'warehouseId' => trim( (string) ( $item['warehouseId'] ?? '' ) ),
			'branchId' => trim( (string) ( $item['branchId'] ?? '' ) ),
			'branchName' => trim( (string) ( $item['branchName'] ?? '' ) ),
			'divisionName' => trim( (string) ( $item['divisionName'] ?? '' ) ),
			'departmentTypeId' => (int) ( $item['departmentTypeId'] ?? 0 ),
			'departmentType' => trim( (string) ( $item['departmentType'] ?? '' ) ),
			'address' => trim( (string) ( $item['address'] ?? '' ) ),
			'coordinates' => is_array( $item['coordinates'] ?? null ) ? array(
				'latitude' => (string) ( $item['coordinates']['latitude'] ?? '' ),
				'longitude' => (string) ( $item['coordinates']['longitude'] ?? '' ),
			) : array(),
			'priority' => (int) ( $item['priority'] ?? 0 ),
			'source' => trim( (string) ( $item['source'] ?? '' ) ),
			'maxWeight' => $item['maxWeight'] ?? null,
			'maxVolume' => $item['maxVolume'] ?? null,
			'maxDimension' => $item['maxDimension'] ?? null,
			'maxWeightOnePlace' => $item['maxWeightOnePlace'] ?? null,
			'maxCount' => $item['maxCount'] ?? null,
		);
	}
}
