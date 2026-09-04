<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Manual\ManualDeliverySettings;
use WallsShop\WDC\Carriers\RussianPost\Admin\RussianPostPickupDiagnosticsTab;
use WallsShop\WDC\DeliveryServices\Admin\DeliveryServicesAdminPage;
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
use WallsShop\WDC\Shipments\Cdek\CdekStatusMappingService;
use WallsShop\WDC\Shipments\Dpd\DpdStatusMapping;

function dpd_status_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class DpdStatusRedirectException extends RuntimeException {
	public function __construct( public readonly string $url ) {
		parent::__construct( $url );
	}
}

function current_time( string $type ): string { return '2026-06-19 12:00:00'; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_status_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_dpd_status_options'][ $key ] = $value; return true; }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
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
function check_admin_referer( string $action ): bool { return true; }
function current_user_can( string $capability ): bool { return AdminMenu::CAPABILITY === $capability; }
function is_admin(): bool { return true; }
function admin_url( string $path = '' ): string { return 'http://example.test/wp-admin/' . ltrim( $path, '/' ); }
function wp_safe_redirect( string $url ): void { throw new DpdStatusRedirectException( $url ); }
function submit_button( string $text = 'Save Changes', string $type = 'primary', string $name = 'submit', bool $wrap = true ): void { echo '<button type="submit" class="button button-primary">' . esc_html( $text ) . '</button>'; }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		/** @var array<int,array<string,mixed>> */
		public array $services = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function insert( string $table, array $data, array $format = array() ): bool {
			$data['id'] = ++$this->insert_id;
			$this->services[] = $data;
			return true;
		}

		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			foreach ( $this->services as $index => $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === (int) ( $where['id'] ?? 0 ) ) {
					$this->services[ $index ] = array_merge( $row, $data );
				}
			}
			return true;
		}

		public function get_row( string $query, mixed $output = null ): ?array {
			if ( preg_match( "/service_key = '([^']+)'/", $query, $matches ) ) {
				foreach ( $this->services as $row ) {
					if ( (string) $row['service_key'] === $matches[1] && empty( $row['deleted'] ) ) {
						return $row;
					}
				}
			}
			if ( preg_match( '/WHERE id = ([0-9]+)/', $query, $matches ) ) {
				foreach ( $this->services as $row ) {
					if ( (int) $row['id'] === (int) $matches[1] ) {
						return $row;
					}
				}
			}
			return null;
		}

		public function get_results( string $query, mixed $output = null ): array { return $this->services; }
		public function get_col( string $query ): array { return array(); }
		public function delete( string $table, array $where, array $format = array() ): bool { return true; }
	}
}

$GLOBALS['wdc_dpd_status_options'] = array();
$GLOBALS['wpdb'] = new wpdb();

$settings = new SettingsRepository();
$mapping = new DpdStatusMapping( $settings );
$statuses = DpdStatusMapping::statuses();
$defaults = DpdStatusMapping::default_mapping();
$doc_event_codes = array( '1001', '1101', '1201', '1301', '1401', '1501', '1601', '1603', '1701', '1801', '1802', '1810', '1811', '2101', '2102', '2103', '2201', '2202', '2203', '2204', '2205', '2209', '2210', '2301', '2302', '2303', '2304', '2305', '2306', '2307', '2309', '2310', '2311', '2314', '2401', '2402', '2404', '2405', '2406', '2407', '2408', '2409', '2410', '2411', '2416', '3701', '2501', '2601', '2602', '2701', '2801', '2901', '2904', '3001', '3201', '3202', '3203', '3204', '3205', '3206', '3211', '3216', '3301', '3302', '3303', '3304', '3305', '3306', '3308', '3401', '3501', '3601', '3901', '4001', '4101' );

$all_statuses = DeliveryStatus::all();
dpd_status_assert( DeliveryStatus::PENDING_CREATION_IN_CARRIER === 'pending_creation_in_carrier', 'Universal status pending_creation_in_carrier must exist.' );
dpd_status_assert( DeliveryStatus::PENDING_CREATION_IN_CARRIER === $all_statuses[0] && DeliveryStatus::CREATED_IN_CARRIER === $all_statuses[1], 'pending_creation_in_carrier must be ordered before created_in_carrier.' );
dpd_status_assert( 'Попытка создания в ТК' === DeliveryStatus::label( DeliveryStatus::PENDING_CREATION_IN_CARRIER ), 'pending_creation_in_carrier label must be available.' );

dpd_status_assert( $doc_event_codes === array_map( 'strval', array_keys( $statuses ) ), 'DPD dictionary must contain all EventCode values from docs/dpd/ws-integration-guide.docx section 5.5.4.' );
foreach ( $statuses as $event_code => $status ) {
	dpd_status_assert( '' !== $status['event_name'], 'Every DPD EventCode must have EventName: ' . $event_code );
	dpd_status_assert( array_key_exists( 'status_code', $status ), 'Every DPD EventCode must expose DPD marker/code name field: ' . $event_code );
	dpd_status_assert( ! array_key_exists( 'parameters', $status ), 'DPD statuses must not contain ParamName/parameters: ' . $event_code );
	dpd_status_assert( isset( $defaults[ $event_code ] ) && DeliveryStatus::is_valid( $defaults[ $event_code ] ), 'Every DPD EventCode must have a valid default universal status: ' . $event_code );
}

dpd_status_assert( 75 === count( $statuses ), 'DPD status dictionary must contain 75 EventCode rows.' );

foreach ( array( '1001', '1101', '1201', '1301' ) as $event_code ) {
	dpd_status_assert( DeliveryStatus::PENDING_CREATION_IN_CARRIER === $defaults[ $event_code ], 'DPD EventCode ' . $event_code . ' must default to pending_creation_in_carrier.' );
}
foreach ( array( '2402', '2408', '2410', '2411', '2501', '2701', '2801', '2901', '2904', '3301', '3302', '3401' ) as $event_code ) {
	dpd_status_assert( DeliveryStatus::UNKNOWN === $defaults[ $event_code ], 'DPD EventCode ' . $event_code . ' must default to unknown.' );
}
foreach ( array( '2202', '2210', '2301', '2304', '2401', '2407', '3701', '3303', '3501', '3601' ) as $event_code ) {
	dpd_status_assert( DeliveryStatus::IN_TRANSIT === $defaults[ $event_code ], 'DPD EventCode ' . $event_code . ' must default to in_transit.' );
}
foreach ( array( '2404', '2405', '2406', '2416' ) as $event_code ) {
	dpd_status_assert( DeliveryStatus::RETURNING_TO_SENDER === $defaults[ $event_code ], 'DPD EventCode ' . $event_code . ' must default to returning_to_sender.' );
}
foreach ( array( '3304', '3305', '3308' ) as $event_code ) {
	dpd_status_assert( DeliveryStatus::DELIVERED === $defaults[ $event_code ], 'DPD EventCode ' . $event_code . ' must default to delivered.' );
}
dpd_status_assert( DeliveryStatus::READY_FOR_PICKUP === $defaults['2201'] && DeliveryStatus::READY_FOR_PICKUP === $defaults['2209'], 'DPD pickup-ready events must default to ready_for_pickup.' );
foreach ( array( '2102', '2203', '2204', '2305', '2309', '2314' ) as $event_code ) {
	dpd_status_assert( DeliveryStatus::RETURNING_TO_SENDER === $defaults[ $event_code ], 'DPD return flow EventCode ' . $event_code . ' must default to returning_to_sender.' );
}
dpd_status_assert( DeliveryStatus::RETURNED_TO_SENDER === $defaults['3306'], 'DPD EventCode 3306 must default to returned_to_sender.' );

$mapping->save_mapping( array( '3305' => DeliveryStatus::PENDING_CREATION_IN_CARRIER ) );
dpd_status_assert( DeliveryStatus::PENDING_CREATION_IN_CARRIER === $mapping->resolve( '3305' ), 'Saved DPD mapping must persist pending_creation_in_carrier.' );
dpd_status_assert( DeliveryStatus::UNKNOWN === $mapping->resolve( '9999' ), 'Unknown DPD EventCode must return safe fallback universal status.' );
$mapping->save_mapping( array( '3305' => 'invalid-status' ) );
dpd_status_assert( DeliveryStatus::DELIVERED === $mapping->resolve( '3305' ), 'Invalid saved DPD mapping must fall back to default mapping.' );
dpd_status_assert( isset( $settings->defaults()[ DpdStatusMapping::MAPPING_KEY ]['3305'] ), 'DPD status mapping defaults must be registered in SettingsRepository.' );

$GLOBALS['wpdb']->services[] = array(
	'id' => 1,
	'service_key' => DpdSettings::SERVICE_KEY,
	'carrier_key' => DpdSettings::CARRIER_KEY,
	'service_type' => DeliveryService::TYPE_API,
	'title' => 'DPD',
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
	dpd_status_mapping: $mapping
);
$service = $GLOBALS['wpdb']->get_row( "SELECT * FROM wp_wdc_delivery_services WHERE service_key = 'dpd' AND deleted = 0 LIMIT 1", ARRAY_A );
$service = DeliveryService::from_array( $service );
$render = new ReflectionMethod( DeliveryServicesAdminPage::class, 'render_dpd_statuses_tab' );
$render->setAccessible( true );
ob_start();
$render->invoke( $page, $service );
$html = ob_get_clean() ?: '';

dpd_status_assert( str_contains( $html, 'EventCode' ) && str_contains( $html, 'DPD marker/code name' ), 'Admin tab "Статусы DPD" must render EventCode/EventName/marker table.' );
dpd_status_assert( ! str_contains( $html, 'ParamName / параметры' ) && ! str_contains( $html, 'parameters' ), 'Admin tab must not render ParamName/parameters column.' );
dpd_status_assert( str_contains( $html, 'dpd_status_mapping[3305]' ) && str_contains( $html, 'Заказ выдан на ПВЗ' ), 'Admin tab must render DPD EventCode rows.' );
dpd_status_assert( str_contains( $html, '<code>OfferCreate</code>' ), 'Admin tab must render DPD marker/code name when present.' );
foreach ( DeliveryStatus::all() as $status ) {
	dpd_status_assert( str_contains( $html, 'value="' . $status . '"' ), 'DPD status select must contain universal status: ' . $status );
}

$_POST = array(
	'wdc_delivery_services_action' => 'save_dpd_statuses',
	'service_key' => DpdSettings::SERVICE_KEY,
	'id' => '1',
	DpdStatusMapping::MAPPING_KEY => array( '3305' => DeliveryStatus::PENDING_CREATION_IN_CARRIER ),
);
try {
	$page->handle_actions();
} catch ( DpdStatusRedirectException ) {
}
dpd_status_assert( DeliveryStatus::PENDING_CREATION_IN_CARRIER === $mapping->resolve( '3305' ), 'Admin save must persist DPD status mapping.' );

$_POST = array(
	'wdc_delivery_services_action' => 'save_dpd_statuses',
	'service_key' => DpdSettings::SERVICE_KEY,
	'id' => '1',
	'dpd_statuses_reset' => '1',
	DpdStatusMapping::MAPPING_KEY => array( '3305' => DeliveryStatus::PENDING_CREATION_IN_CARRIER ),
);
try {
	$page->handle_actions();
} catch ( DpdStatusRedirectException ) {
}
dpd_status_assert( DeliveryStatus::DELIVERED === $mapping->resolve( '3305' ), 'Admin reset must restore updated DPD default mapping.' );

$cdek_mapping = new CdekStatusMappingService( $settings );
dpd_status_assert( DeliveryStatus::DELIVERED === $cdek_mapping->universal_status_for( 'DELIVERED' ) && DeliveryStatus::CANCELLED === $cdek_mapping->universal_status_for( 'REMOVED' ), 'CDEK status mapping must remain intact.' );


echo "DPD status mapping smoke passed\n";
