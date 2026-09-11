<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\Manual\ManualDeliveryGeographyRepository;
use WallsShop\WDC\Carriers\Manual\ManualDeliverySettings;
use WallsShop\WDC\Carriers\Manual\ManualDeliveryWeightRangeRepository;
use WallsShop\WDC\Carriers\Manual\ManualPickupPointRepository;
use WallsShop\WDC\Carriers\RussianPost\Admin\RussianPostPickupDiagnosticsTab;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\DeliveryServices\Admin\DeliveryServicesAdminPage;
use WallsShop\WDC\DeliveryServices\Application\DeliveryServiceKeyRenameService;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupDiagnosticsService;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupLocationResolver;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Rules\Admin\RulesAdminPage;
use WallsShop\WDC\Rules\Storage\RuleRepository;
use WallsShop\WDC\Shipments\Application\ShipmentStatusAutoSyncCron;
use WallsShop\WDC\Shipments\RussianPost\RussianPostTrackingStatusMapper;

function russian_post_status_mapping_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class RussianPostStatusMappingRedirect extends RuntimeException {}
final class RussianPostStatusMappingNonceFailure extends RuntimeException {}

function current_time( string $type ): string { return '2026-09-12 12:00:00'; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_rp_status_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_rp_status_options'][ $key ] = $value; return true; }
function sanitize_key( mixed $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function esc_attr( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( string $text, string $domain = 'default' ): string { return $text; }
function __( string $text, string $domain = 'default' ): string { return $text; }
function wp_kses_post( string $value ): string { return $value; }
function selected( mixed $selected, mixed $current, bool $display = true ): string { $result = (string) $selected === (string) $current ? ' selected="selected"' : ''; if ( $display ) { echo $result; } return $result; }
function checked( mixed $checked, mixed $current = true, bool $display = true ): string { $result = (string) $checked === (string) $current ? ' checked="checked"' : ''; if ( $display ) { echo $result; } return $result; }
function disabled( mixed $disabled, mixed $current = true, bool $display = true ): string { $result = (string) $disabled === (string) $current ? ' disabled="disabled"' : ''; if ( $display ) { echo $result; } return $result; }
function wp_nonce_field( string $action ): void { echo '<input type="hidden" name="_wpnonce" value="nonce">'; }
function check_admin_referer( string $action ): bool { ++$GLOBALS['wdc_rp_status_nonce_checks']; if ( ! $GLOBALS['wdc_rp_status_nonce_valid'] ) { throw new RussianPostStatusMappingNonceFailure(); } return true; }
function current_user_can( string $capability ): bool { return AdminMenu::CAPABILITY === $capability && $GLOBALS['wdc_rp_status_can_manage']; }
function is_admin(): bool { return true; }
function admin_url( string $path = '' ): string { return 'http://example.test/wp-admin/' . ltrim( $path, '/' ); }
function wp_safe_redirect( string $url ): void { throw new RussianPostStatusMappingRedirect( $url ); }
function submit_button( string $text = 'Save Changes', string $type = 'primary', string $name = 'submit', bool $wrap = true ): void { echo '<button type="submit">' . esc_html( $text ) . '</button>'; }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $services = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function get_row( string $query, mixed $output = null ): ?array {
			foreach ( $this->services as $row ) {
				if ( str_contains( $query, "service_key = '" . (string) $row['service_key'] . "'" ) && empty( $row['deleted'] ) ) {
					return $row;
				}
			}
			return null;
		}

		public function get_results( string $query, mixed $output = null ): array { return $this->services; }
		public function get_col( string $query ): array { return array(); }
		public function insert( string $table, array $data, array $format = array() ): bool { return true; }
		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool { return true; }
		public function delete( string $table, array $where, array $format = array() ): bool { return true; }
	}
}

$GLOBALS['wdc_rp_status_options'] = array();
$GLOBALS['wdc_rp_status_can_manage'] = true;
$GLOBALS['wdc_rp_status_nonce_valid'] = true;
$GLOBALS['wdc_rp_status_nonce_checks'] = 0;
$GLOBALS['wpdb'] = new wpdb();

$settings = new SettingsRepository();
$mapper = new RussianPostTrackingStatusMapper( $settings );
$catalog = RussianPostTrackingStatusMapper::catalog();
$defaults = RussianPostTrackingStatusMapper::default_mapping();

russian_post_status_mapping_assert( 490 === count( $catalog ), 'Official Russian Post native catalog must contain 490 rows.' );
russian_post_status_mapping_assert( array_keys( $catalog ) === array_keys( $defaults ), 'Catalog keys and default mapping keys must be identical.' );
$catalog_keys = array_keys( $catalog );
$numeric_catalog_keys = $catalog_keys;
usort(
	$numeric_catalog_keys,
	static function ( string $left, string $right ): int {
		[ $left_type, $left_attr ] = explode( ':', $left, 2 );
		[ $right_type, $right_attr ] = explode( ':', $right, 2 );
		return ( (int) $left_type <=> (int) $right_type ) ?: ( ( '-' === $left_attr ? -1 : (int) $left_attr ) <=> ( '-' === $right_attr ? -1 : (int) $right_attr ) );
	}
);
russian_post_status_mapping_assert( $numeric_catalog_keys === $catalog_keys, 'Russian Post native rows must use stable numeric type/attribute ordering.' );
foreach ( $catalog as $key => $row ) {
	russian_post_status_mapping_assert( '' !== trim( $row['label'] ), 'Every Russian Post status must have a native label: ' . $key );
	russian_post_status_mapping_assert( DeliveryStatus::is_valid( $row['status'] ), 'Every Russian Post status must have a valid default mapping: ' . $key );
	russian_post_status_mapping_assert( is_bool( $row['terminal'] ), 'Every Russian Post status must have terminal metadata: ' . $key );
}

$new_pairs = array(
	'4:20' => 'Досылка почты — Переадресация Бокс-сервис',
	'4:21' => 'Досылка почты — Досыл с уведомлением',
	'8:94' => 'Обработка — Определен метод доставки "электронный"',
	'8:95' => 'Обработка — Определен метод доставки "на бумаге"',
);
foreach ( $new_pairs as $key => $label ) {
	russian_post_status_mapping_assert( $label === $catalog[ $key ]['label'], 'New official label must match the Russian Post dictionary: ' . $key );
	russian_post_status_mapping_assert( DeliveryStatus::UNKNOWN === $defaults[ $key ], 'New official pair must default to unknown: ' . $key );
	russian_post_status_mapping_assert( false === $catalog[ $key ]['terminal'], 'New official pair must retain its current non-terminal dictionary metadata: ' . $key );
}

russian_post_status_mapping_assert( DeliveryStatus::DELIVERED === $defaults['2:25'], 'Compatibility pair 2:25 must remain delivered.' );
russian_post_status_mapping_assert( false === $catalog['2:25']['terminal'], '2:25 remains terminal=false for backward compatibility, although the current official Russian Post dictionary reports isTerminal=true.' );

$legacy_rows = array_diff_key( $catalog, array_fill_keys( array_keys( $new_pairs ), true ) );
$legacy_fingerprint_rows = array();
foreach ( $legacy_rows as $key => $row ) {
	$legacy_fingerprint_rows[ $key ] = $key . '|' . $row['status'] . '|' . ( $row['terminal'] ? 'true' : 'false' );
}
ksort( $legacy_fingerprint_rows, SORT_STRING );
russian_post_status_mapping_assert( 486 === count( $legacy_fingerprint_rows ), 'Legacy compatibility set must contain 486 pairs.' );
$legacy_fingerprint = hash( 'sha256', implode( "\n", $legacy_fingerprint_rows ) );
russian_post_status_mapping_assert( 'c025ba98e316f93831b6b493f410e86518a16aa4010247f26b98fa2267d1cdbd' === $legacy_fingerprint, 'All 486 legacy universal and terminal mappings must remain byte-for-byte compatible with 1.0.10; got ' . $legacy_fingerprint . '.' );

$mapper->save_mapping( array( '2:1' => DeliveryStatus::IN_TRANSIT, '2:25' => DeliveryStatus::UNKNOWN, 'not:official' => DeliveryStatus::DELIVERED ) );
$reloaded = new RussianPostTrackingStatusMapper( new SettingsRepository() );
$mapped_override = $reloaded->map_record( array( 'operation_type_id' => '2', 'operation_attr_id' => '1' ) );
russian_post_status_mapping_assert( DeliveryStatus::IN_TRANSIT === $mapped_override['universal_status_code'], 'Saved override must be consumed after mapper reload.' );
russian_post_status_mapping_assert( true === $mapped_override['carrier_status_is_terminal'], 'Universal override must not change carrier terminal metadata.' );
russian_post_status_mapping_assert( ! array_key_exists( 'not:official', $reloaded->mapping() ), 'Unknown native keys must not be persisted.' );
$mapper->save_mapping( array( '2:1' => 'invalid-status' ) );
russian_post_status_mapping_assert( DeliveryStatus::DELIVERED === $mapper->map_record( array( 'operation_type_id' => '2', 'operation_attr_id' => '1' ) )['universal_status_code'], 'Invalid universal override must fall back to the catalog default.' );
$unknown = $mapper->map_record( array( 'operation_type_id' => '9999', 'operation_attr_id' => '1' ) );
russian_post_status_mapping_assert( DeliveryStatus::UNKNOWN === $unknown['universal_status_code'] && false === $unknown['carrier_status_is_terminal'], 'Unknown native pairs must remain safe and non-terminal.' );

$GLOBALS['wpdb']->services[] = array(
	'id' => 1,
	'service_key' => RussianPostDomesticSettings::SERVICE_KEY,
	'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
	'service_type' => DeliveryService::TYPE_API,
	'title' => 'Почта России',
	'enabled' => 1,
	'deleted' => 0,
);

$rules_admin = ( new ReflectionClass( RulesAdminPage::class ) )->newInstanceWithoutConstructor();
$page = new DeliveryServicesAdminPage(
	services: new DeliveryServiceRepository( $GLOBALS['wpdb'] ),
	countries: new DeliveryServiceCountryRepository( $GLOBALS['wpdb'] ),
	rules_admin: $rules_admin,
	rules: new RuleRepository( $GLOBALS['wpdb'] ),
	russian_post_pickup_diagnostics: new RussianPostPickupDiagnosticsTab(
		new RussianPostPickupDiagnosticsService(
			new RussianPostPickupPointRepository( $GLOBALS['wpdb'] ),
			new LocationRepository( $GLOBALS['wpdb'] ),
			$GLOBALS['wpdb'],
			location_resolver: new RussianPostPickupLocationResolver( new LocationRepository( $GLOBALS['wpdb'] ), $GLOBALS['wpdb'] )
		)
	),
	manual_delivery_settings: new ManualDeliverySettings( new DeliveryServiceSettingsRepository( $GLOBALS['wpdb'] ) ),
	manual_delivery_geography: new ManualDeliveryGeographyRepository( $GLOBALS['wpdb'] ),
	manual_delivery_weight_ranges: new ManualDeliveryWeightRangeRepository( $GLOBALS['wpdb'] ),
	delivery_service_key_rename: new DeliveryServiceKeyRenameService( new DeliveryServiceRepository( $GLOBALS['wpdb'] ), new RuleRepository( $GLOBALS['wpdb'] ) ),
	manual_pickup_points: new ManualPickupPointRepository( $GLOBALS['wpdb'] ),
	shop_processing_queue_counter: new WallsShop\WDC\Orders\Application\ShopProcessingOrderQueueCounter( new WallsShop\WDC\Infrastructure\Logging\Logger(), static fn( array $statuses ): int => 0 ),
	russian_post_status_mapping: $mapper
);
$service_row = $GLOBALS['wpdb']->get_row( "SELECT * FROM wp_wdc_delivery_services WHERE service_key = 'russian_post_domestic' AND deleted = 0 LIMIT 1", ARRAY_A );
$service = DeliveryService::from_array( $service_row );
$render = new ReflectionMethod( DeliveryServicesAdminPage::class, 'render_russian_post_statuses_tab' );
$render->setAccessible( true );
ob_start();
$render->invoke( $page, $service );
$html = ob_get_clean() ?: '';
russian_post_status_mapping_assert( str_contains( $html, 'russian_post_status_mapping[2:25]' ) && str_contains( $html, 'Вручение — Адресату по QR коду' ), 'Russian Post status tab must render the compatibility pair.' );
russian_post_status_mapping_assert( 490 === substr_count( $html, 'name="russian_post_status_mapping[' ), 'Russian Post status tab must render all 490 native rows.' );
russian_post_status_mapping_assert( ! str_contains( $html, 'status_polling_frequency_minutes' ) && ! str_contains( $html, 'status_auto_sync_wc_statuses' ), 'Russian Post tab must not expose carrier-specific autosync cadence or enablement.' );

$_POST = array(
	'wdc_delivery_services_action' => 'save_russian_post_statuses',
	'service_key' => RussianPostDomesticSettings::SERVICE_KEY,
	'id' => '1',
	RussianPostTrackingStatusMapper::MAPPING_KEY => array( '2:1' => DeliveryStatus::IN_TRANSIT ),
);
$GLOBALS['wdc_rp_status_can_manage'] = false;
$page->handle_actions();
russian_post_status_mapping_assert( DeliveryStatus::DELIVERED === $mapper->map_record( array( 'operation_type_id' => '2', 'operation_attr_id' => '1' ) )['universal_status_code'], 'Admin save without capability must not persist.' );
$GLOBALS['wdc_rp_status_can_manage'] = true;
$GLOBALS['wdc_rp_status_nonce_valid'] = false;
try {
	$page->handle_actions();
	russian_post_status_mapping_assert( false, 'Admin save with invalid nonce must be rejected.' );
} catch ( RussianPostStatusMappingNonceFailure ) {
}
russian_post_status_mapping_assert( DeliveryStatus::DELIVERED === $mapper->map_record( array( 'operation_type_id' => '2', 'operation_attr_id' => '1' ) )['universal_status_code'], 'Invalid nonce must not persist mapping.' );
$GLOBALS['wdc_rp_status_nonce_valid'] = true;
try {
	$page->handle_actions();
} catch ( RussianPostStatusMappingRedirect ) {
}
russian_post_status_mapping_assert( DeliveryStatus::IN_TRANSIT === $mapper->map_record( array( 'operation_type_id' => '2', 'operation_attr_id' => '1' ) )['universal_status_code'], 'Capability- and nonce-protected admin save must persist mapping.' );
russian_post_status_mapping_assert( $GLOBALS['wdc_rp_status_nonce_checks'] >= 2, 'Russian Post mapping save must pass through the common nonce guard.' );

$source_root = dirname( __DIR__, 2 ) . '/src';
$forbidden_status_scheduler_fragments = array( 'russian_post_status_sync_enabled', 'russian_post_status_interval', 'russian_post_status_schedule' );
$source_iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $source_root, FilesystemIterator::SKIP_DOTS ) );
foreach ( $source_iterator as $file ) {
	if ( ! $file->isFile() || 'php' !== strtolower( $file->getExtension() ) ) {
		continue;
	}
	$source = (string) file_get_contents( $file->getPathname() );
	foreach ( $forbidden_status_scheduler_fragments as $fragment ) {
		russian_post_status_mapping_assert( ! str_contains( $source, $fragment ), 'No Russian Post-specific shipment status scheduler setting may exist: ' . $fragment );
	}
}
russian_post_status_mapping_assert( 'wdc_shipment_status_autosync' === ShipmentStatusAutoSyncCron::HOOK, 'Shipment statuses must retain the single common autosync hook.' );
$pickup_settings_source = (string) file_get_contents( $source_root . '/Carriers/RussianPost/Otpravka/RussianPostOtpravkaApiSettings.php' );
russian_post_status_mapping_assert( str_contains( $pickup_settings_source, 'russian_post_pickup_schedule_enabled' ) && str_contains( $pickup_settings_source, 'russian_post_pickup_schedule_weekday' ) && str_contains( $pickup_settings_source, 'russian_post_pickup_schedule_time' ), 'Independent Russian Post pickup weekly scheduler settings must remain intact.' );

echo "Russian Post status mapping smoke passed\n";
