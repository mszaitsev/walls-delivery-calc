<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();
require_once __DIR__ . '/shipment-cost-analytics-test-helpers.php';

use WallsShop\WDC\Shipments\Admin\ShipmentCostAnalyticsAdminSection;
use WallsShop\WDC\Shipments\Analytics\Storage\ShipmentCostAnalyticsRecord;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostAnalyticsFilter;
use WallsShop\WDC\Shipments\Analytics\ShipmentCostThresholdPolicy;

function shipment_cost_analytics_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

if ( ! function_exists( 'esc_html' ) ) { function esc_html( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_attr' ) ) { function esc_attr( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); } }
if ( ! function_exists( 'esc_html__' ) ) { function esc_html__( string $value, string $domain = 'default' ): string { unset( $domain ); return $value; } }
if ( ! function_exists( 'esc_url' ) ) { function esc_url( string $value ): string { return $value; } }
if ( ! function_exists( 'add_query_arg' ) ) { function add_query_arg( array $args, string $url ): string { return $url . '?' . http_build_query( $args ); } }
if ( ! function_exists( 'wp_strip_all_tags' ) ) { function wp_strip_all_tags( string $value ): string { return strip_tags( $value ); } }
if ( ! function_exists( 'wp_json_encode' ) ) { function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); } }
if ( ! function_exists( 'number_format_i18n' ) ) { function number_format_i18n( float $number, int $decimals = 0 ): string { return number_format( $number, $decimals, '.', '' ); } }
if ( ! function_exists( 'wc_get_orders' ) ) { function wc_get_orders( array $args ): array { throw new RuntimeException( 'Analytics page must not scan WooCommerce orders.' ); } }
if ( ! function_exists( 'wp_enqueue_script' ) ) {
	function wp_enqueue_script( string $handle, string $src = '', array $deps = array(), string|bool|null $ver = false, bool $in_footer = false ): void {
		$GLOBALS['wdc_shipment_cost_analytics_enqueued_scripts'][ $handle ] = compact( 'src', 'deps', 'ver', 'in_footer' );
	}
}

$context = shipment_cost_analytics_test_bootstrap();
$repository = $context['repository'];
$service = $context['service'];
$options = $service->carrier_options();

for ( $i = 1; $i <= 30; ++$i ) {
	$actual = $i <= 29 ? 10000 : null;
	$repository->upsert( new ShipmentCostAnalyticsRecord( 3000 + $i, 'PAGE-' . $i, '2026-07-20 07:00:' . str_pad( (string) $i, 2, '0', STR_PAD_LEFT ), 'alpha', 'alpha_service', 'Alpha Service', 'alpha', 'A-' . $i, 10000, $actual, 'RUB', null !== $actual ? 'carrier_api' : '', '', null, null !== $actual ? 0 : null, null !== $actual ? 0 : null, null !== $actual ? 'within_threshold' : 'not_comparable', '2026-07-21 07:00:00' ) );
}

$result = $service->result( ShipmentCostAnalyticsFilter::from_request( array( 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'per_page' => 25, 'paged' => 999 ), $options, new DateTimeImmutable( '2026-07-21 00:00:00', new DateTimeZone( 'Europe/Moscow' ) ) ) );
shipment_cost_analytics_assert( 30 === $result->total_rows && 2 === $result->total_pages && 2 === $result->current_page && 5 === count( $result->rows ), 'Analytics service must use SQL result pagination and normalized current page.' );
shipment_cost_analytics_assert( 30 === $result->summary->shipment_count && 29 === $result->summary->with_actual_count && 1 === $result->summary->without_actual_count, 'Summary must come from full SQL aggregate.' );

$_GET = array( 'page' => 'wdc-platform', 'analytics_period' => 'month', 'actual_cost_mode' => 'all', 'paged' => '999', 'per_page' => '25' );
ob_start();
( new ShipmentCostAnalyticsAdminSection( $service, new ShipmentCostThresholdPolicy(), 'https://example.test/wp-content/plugins/wdc/', 'test' ) )->render();
$html = (string) ob_get_clean();
shipment_cost_analytics_assert( str_contains( $html, '<strong>2</strong>' ), 'Renderer must mark normalized current_page active.' );
shipment_cost_analytics_assert( str_contains( $html, 'data-wdc-shipment-cost-filters' ) && str_contains( $html, 'data-wdc-analytics-ranges' ) && str_contains( $html, 'data-wdc-analytics-period' ) && str_contains( $html, 'data-wdc-analytics-date-from' ) && str_contains( $html, 'data-wdc-analytics-date-to' ), 'Analytics filters form must expose stable data selectors and server range map.' );
shipment_cost_analytics_assert( isset( $GLOBALS['wdc_shipment_cost_analytics_enqueued_scripts']['wdc-shipment-cost-analytics'] ), 'Analytics admin section must enqueue the period synchronization asset.' );
$analytics_js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/shipment-cost-analytics.js' );
shipment_cost_analytics_assert( str_contains( $analytics_js, 'wdcAnalyticsRanges' ) && str_contains( $analytics_js, "period.value || '') === 'custom'" ) && str_contains( $analytics_js, "addEventListener('change'" ) && ! str_contains( $analytics_js, '.submit(' ), 'Analytics JS must update fixed date inputs from server ranges without auto-submit and must leave custom dates alone.' );

$GLOBALS['wpdb']->rows = array(
	1 => array(
		'id' => 1,
		'order_id' => 4001,
		'order_number' => 'EMPTY-ACTUAL',
		'order_created_at' => '2026-07-20 07:00:00',
		'carrier_key' => 'alpha',
		'service_key' => 'alpha_service',
		'service_title' => 'Alpha Service',
		'base_api_cost_kopecks' => 10000,
		'actual_cost_kopecks' => null,
		'actual_cost_source' => '',
		'actual_cost_source_detail' => '',
		'difference_kopecks' => null,
		'difference_percent_basis_points' => null,
		'threshold_status' => 'not_comparable',
	),
);
$_GET = array( 'page' => 'wdc-platform', 'analytics_period' => 'month' );
ob_start();
( new ShipmentCostAnalyticsAdminSection( $service, new ShipmentCostThresholdPolicy(), 'https://example.test/wp-content/plugins/wdc/', 'test' ) )->render();
$html = (string) ob_get_clean();
shipment_cost_analytics_assert( str_contains( $html, 'Включите «Показать без фактической стоимости»' ), 'Empty state must point to the visible checkbox label.' );
$old_empty_state_phrase = 'Отключите фильтр «' . 'Только с фактической стоимостью' . '»';
shipment_cost_analytics_assert( ! str_contains( $html, $old_empty_state_phrase ), 'Old empty state wording must not remain.' );

echo "Shipment cost analytics smoke passed.\n";
