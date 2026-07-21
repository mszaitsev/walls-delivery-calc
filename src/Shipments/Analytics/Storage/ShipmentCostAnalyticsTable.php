<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics\Storage;

defined( 'ABSPATH' ) || exit;

final class ShipmentCostAnalyticsTable {
	public const TABLE_SUFFIX = 'wdc_shipment_cost_analytics';
	public const MIGRATION = '0041_create_shipment_cost_analytics_table.php';

	public function name(): string {
		global $wpdb;
		$prefix = is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : '';

		return $prefix . self::TABLE_SUFFIX;
	}

	public function schema(): string {
		global $wpdb;
		$charset = is_object( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' )
			? (string) $wpdb->get_charset_collate()
			: 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
		$table = $this->name();

		return "CREATE TABLE {$table} (
id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
order_id BIGINT UNSIGNED NOT NULL,
order_number VARCHAR(100) NOT NULL,
order_created_at DATETIME NOT NULL,
carrier_key VARCHAR(100) NOT NULL,
service_key VARCHAR(191) NOT NULL DEFAULT '',
service_title VARCHAR(255) NOT NULL DEFAULT '',
shipment_key VARCHAR(191) NOT NULL DEFAULT '',
shipment_identifier VARCHAR(191) NOT NULL,
base_api_cost_kopecks BIGINT NULL,
actual_cost_kopecks BIGINT NULL,
actual_cost_currency CHAR(3) NOT NULL DEFAULT 'RUB',
actual_cost_source VARCHAR(100) NOT NULL DEFAULT '',
actual_cost_source_detail VARCHAR(255) NOT NULL DEFAULT '',
actual_cost_updated_at DATETIME NULL,
difference_kopecks BIGINT NULL,
difference_percent_basis_points BIGINT NULL,
threshold_status VARCHAR(32) NOT NULL DEFAULT 'not_comparable',
indexed_at DATETIME NOT NULL,
PRIMARY KEY  (id),
UNIQUE KEY uniq_order_id (order_id),
KEY idx_created_at (order_created_at),
KEY idx_carrier_created (carrier_key, order_created_at),
KEY idx_order_number (order_number),
KEY idx_actual_cost (actual_cost_kopecks),
KEY idx_base_cost (base_api_cost_kopecks),
KEY idx_difference (difference_kopecks),
KEY idx_difference_percent (difference_percent_basis_points),
KEY idx_threshold_created (threshold_status, order_created_at)
) {$charset};";
	}
}
