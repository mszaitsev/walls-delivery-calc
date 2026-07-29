<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
define( 'WDC_SECRET_KEY', 'jet-logistic-smoke-key' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\JetLogistic\Api\JetLogisticApiClient;
use WallsShop\WDC\Carriers\JetLogistic\Api\JetLogisticHttpClientInterface;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticCitiesCsvClient;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticCitiesCsvParser;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticCityNameNormalizer;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticCountrySyncService;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyOverrideRepository;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyRepository;
use WallsShop\WDC\Carriers\JetLogistic\JetLogisticCredentials;
use WallsShop\WDC\Carriers\JetLogistic\JetLogisticSettings;
use WallsShop\WDC\Carriers\JetLogistic\Quote\JetLogisticQuoteRequestBuilder;
use WallsShop\WDC\Carriers\JetLogistic\Quote\JetLogisticQuoteResponseParser;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusMapper;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusMappingRepository;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusService;
use WallsShop\WDC\Carriers\Runtime\JetLogisticCarrier;
use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\JetLogistic\Admin\JetLogisticGeographyAdminPage;
use WallsShop\WDC\Carriers\JetLogistic\Admin\JetLogisticStatusAdminPage;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\Admin\DeliveryServicesAdminPage;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Database\MigrationManager;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Core\Plugin;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Shipments\JetLogistic\JetLogisticShipmentAdapter;
use WallsShop\WDC\Shipments\JetLogistic\JetLogisticShipmentService;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostResolver;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function jet_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function current_time( string $type ): string { return '2026-07-28 12:00:00'; }
function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . '/'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['wdc_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_options'][ $option ] = $value; return true; }
function wp_salt( string $scheme = 'auth' ): string { return 'jet-salt-' . $scheme; }
function sanitize_text_field( mixed $value ): string { return trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $value ) ?? (string) $value ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function esc_attr( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( string $text, string $domain = 'default' ): string { return $text; }
function __( string $text, string $domain = 'default' ): string { return $text; }
function selected( mixed $selected, mixed $current, bool $display = true ): string { $result = (string) $selected === (string) $current ? ' selected="selected"' : ''; if ( $display ) { echo $result; } return $result; }
function submit_button( string $text = 'Save', string $type = 'primary', string $name = 'submit', bool $wrap = true ): void { echo '<button class="button button-' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '">' . esc_html( $text ) . '</button>'; }
function dbDelta( string $sql ): void { $GLOBALS['wdc_db_delta'][] = $sql; }
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool { $GLOBALS['wdc_actions'][] = array( $hook, $callback, $priority, $accepted_args ); return true; }
function add_submenu_page( mixed ...$args ): string { $GLOBALS['wdc_submenu_pages'][] = $args; return (string) ( $args[4] ?? '' ); }
function wp_remote_get( string $url, array $args = array() ): mixed { $GLOBALS['wdc_remote_get_requests'][] = array( 'url' => $url, 'args' => $args ); return array_shift( $GLOBALS['wdc_remote_get_responses'] ); }
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) ( is_array( $response ) ? ( $response['status'] ?? 0 ) : 0 ); }
function wp_remote_retrieve_body( mixed $response ): string { return (string) ( is_array( $response ) ? ( $response['body'] ?? '' ) : '' ); }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
final class WP_Error {}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public array $jet_cities = array();
		public array $jet_overrides = array();
		public array $jet_statuses = array();
		public array $locations = array();
		public array $services = array();
		public array $countries = array();
		public array $jet_update_fail_sources = array();
		public function get_charset_collate(): string { return 'DEFAULT CHARSET=utf8mb4'; }
		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[dsf]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}
			return $query;
		}
		public function replace( string $table, array $data, array $formats = array() ): bool {
			if ( str_contains( $table, 'wdc_jet_logistic_cities' ) ) {
				$key = (string) $data['source_identity'];
				$this->jet_cities[ $key ] = $data;
				$this->jet_cities[ $key ]['id'] = $this->jet_cities[ $key ]['id'] ?? ++$this->insert_id;
				return true;
			}
			if ( str_contains( $table, 'wdc_jet_logistic_status_mappings' ) ) {
				$key = (string) $data['normalized_external_status'];
				$this->jet_statuses[ $key ] = array_merge( $this->jet_statuses[ $key ] ?? array( 'id' => ++$this->insert_id ), $data );
				return true;
			}
			if ( str_contains( $table, 'wdc_jet_logistic_location_overrides' ) ) {
				$key = (string) $data['source_identity'];
				$this->jet_overrides[ $key ] = array_merge( $this->jet_overrides[ $key ] ?? array( 'id' => ++$this->insert_id ), $data );
				return true;
			}
			return true;
		}
		public function insert( string $table, array $data, array $formats = array() ): bool {
			if ( str_contains( $table, 'wdc_delivery_service_countries' ) ) {
				$this->countries[] = $data;
			}
			return true;
		}
		public function update( string $table, array $data, array $where, array $formats = array(), array $where_formats = array() ): int|bool {
			if ( str_contains( $table, 'wdc_jet_logistic_status_mappings' ) ) {
				foreach ( $this->jet_statuses as $key => $row ) {
					if ( (int) ( $row['id'] ?? 0 ) === (int) ( $where['id'] ?? 0 ) ) {
						$this->jet_statuses[ $key ] = array_merge( $row, $data );
					}
				}
			}
			if ( str_contains( $table, 'wdc_jet_logistic_cities' ) ) {
				$key = (string) ( $where['source_identity'] ?? '' );
				if ( in_array( $key, $this->jet_update_fail_sources, true ) ) {
					return false;
				}
				if ( isset( $this->jet_cities[ $key ] ) ) {
					$changed = false;
					foreach ( $data as $column => $value ) {
						if ( (string) ( $this->jet_cities[ $key ][ $column ] ?? '' ) !== (string) $value ) {
							$changed = true;
						}
					}
					$this->jet_cities[ $key ] = array_merge( $this->jet_cities[ $key ], $data );
					return $changed ? 1 : 0;
				}
				return false;
			}
			return true;
		}
		public function delete( string $table, array $where, array $formats = array() ): bool {
			if ( str_contains( $table, 'wdc_jet_logistic_location_overrides' ) ) {
				unset( $this->jet_overrides[ (string) ( $where['source_identity'] ?? '' ) ] );
			}
			if ( str_contains( $table, 'wdc_delivery_service_countries' ) ) {
				$this->countries = array_values( array_filter( $this->countries, static fn( array $row ): bool => (int) ( $row['service_id'] ?? 0 ) !== (int) ( $where['service_id'] ?? 0 ) ) );
			}
			return true;
		}
		public function query( string $query ): int|bool {
			if ( str_contains( $query, 'UPDATE wp_wdc_jet_logistic_cities SET active = 0' ) ) {
				foreach ( $this->jet_cities as $key => $row ) {
					if ( ! str_contains( $query, "'" . $key . "'" ) ) {
						$this->jet_cities[ $key ]['active'] = 0;
					}
				}
			}
			return 1;
		}
		public function get_row( string $query, string $output = ARRAY_A ): ?array {
			if ( preg_match( "/source_identity = '([^']+)'/", $query, $m ) && str_contains( $query, 'wdc_jet_logistic_cities' ) ) {
				return $this->jet_cities[ $m[1] ] ?? null;
			}
			if ( preg_match( "/service_key = '([^']+)'/", $query, $m ) && str_contains( $query, 'wdc_delivery_services' ) ) {
				foreach ( $this->services as $row ) {
					if ( (string) ( $row['service_key'] ?? '' ) === $m[1] && 0 === (int) ( $row['deleted'] ?? 0 ) ) {
						return $row;
					}
				}
			}
			if ( preg_match( '/location_id = (\d+)/', $query, $m ) ) {
				foreach ( $this->jet_cities as $row ) {
					if ( 1 === (int) ( $row['active'] ?? 1 ) && (int) ( $row['location_id'] ?? 0 ) === (int) $m[1] && 'matched' === (string) ( $row['match_status'] ?? '' ) ) {
						return $row;
					}
				}
			}
			if ( preg_match( "/normalized_external_status = '([^']+)'/", $query, $m ) ) {
				return $this->jet_statuses[ $m[1] ] ?? null;
			}
			if ( preg_match( "/source_identity = '([^']+)'/", $query, $m ) && str_contains( $query, 'wdc_jet_logistic_location_overrides' ) ) {
				return $this->jet_overrides[ $m[1] ] ?? null;
			}
			return null;
		}
		public function get_results( string $query, string $output = ARRAY_A ): array { return array_values( $this->jet_cities ); }
		public function get_col( string $query ): array {
			if ( preg_match( '/service_id = (\d+)/', $query, $m ) && str_contains( $query, 'wdc_delivery_service_countries' ) ) {
				return array_values( array_map( static fn( array $row ): string => (string) ( $row['country_code'] ?? '' ), array_filter( $this->countries, static fn( array $row ): bool => (int) ( $row['service_id'] ?? 0 ) === (int) $m[1] ) ) );
			}
			if ( str_contains( $query, 'DISTINCT country_code' ) ) {
				return array_values( array_unique( array_map( static fn( array $row ): string => (string) $row['country_code'], array_filter( $this->jet_cities, static fn( array $row ): bool => 1 === (int) ( $row['active'] ?? 1 ) && 'matched' === (string) ( $row['match_status'] ?? '' ) && 'RU' !== (string) ( $row['country_code'] ?? '' ) ) ) ) );
			}
			return array();
		}
	}
}

final class JetFakeHttp implements JetLogisticHttpClientInterface {
	public array $requests = array();
	public function __construct( private array $responses ) {}
	public function post_json( string $url, array $payload, int $timeout ): array {
		$this->requests[] = array( 'url' => $url, 'payload' => $payload, 'timeout' => $timeout );
		return array_shift( $this->responses ) ?? array( 'status' => 200, 'body' => '{"success":true,"result":{}}' );
	}
}
final class JetFakeOrder {
	public array $meta = array();
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? array(); }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function save(): void {}
}

$GLOBALS['wdc_options'] = array();
$GLOBALS['wdc_actions'] = array();
$GLOBALS['wdc_submenu_pages'] = array();
$GLOBALS['wdc_remote_get_responses'] = array();
$GLOBALS['wdc_remote_get_requests'] = array();
$GLOBALS['wpdb'] = new wpdb();

$root = dirname( __DIR__, 2 );
$geography_admin_source = (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Admin/JetLogisticGeographyAdminPage.php' );
$status_admin_source = (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Admin/JetLogisticStatusAdminPage.php' );
$delivery_admin_source = (string) file_get_contents( $root . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$plugin_source = (string) file_get_contents( $root . '/src/Core/Plugin.php' );
foreach ( array( $geography_admin_source, $status_admin_source ) as $source ) {
	jet_assert( ! str_contains( $source, 'add_menu_page' ) && ! str_contains( $source, 'add_submenu_page' ) && ! str_contains( $source, "add_action( 'admin_menu'" ) && ! str_contains( $source, 'AdminMenu::MENU_SLUG' ), 'Jet admin embedded components must not register WordPress menu pages.' );
}
jet_assert( ! str_contains( $geography_admin_source . $status_admin_source . $delivery_admin_source . $plugin_source, 'wdc-jet-logistic-geography' ) && ! str_contains( $geography_admin_source . $status_admin_source . $delivery_admin_source . $plugin_source, 'wdc-jet-logistic-statuses' ), 'Production code must not keep standalone Jet submenu slugs.' );
jet_assert( str_contains( $delivery_admin_source, "JetLogisticSettings::SERVICE_KEY === \$service->service_key" ) && str_contains( $delivery_admin_source, "\$tabs['jet_geography']" ) && str_contains( $delivery_admin_source, "\$tabs['jet_statuses']" ), 'Jet tabs must be scoped to the Jet Logistic delivery service.' );
jet_assert( str_contains( $delivery_admin_source, "'jet_geography' => \$this->render_jet_geography_tab( \$service )" ) && str_contains( $delivery_admin_source, "'jet_statuses' => \$this->render_jet_statuses_tab( \$service )" ), 'Jet tabs must delegate to embedded renderers.' );
jet_assert( str_contains( $delivery_admin_source, "'save_jet_settings'" ) && str_contains( $delivery_admin_source, "'import_jet_geography_remote'" ) && str_contains( $delivery_admin_source, "'import_jet_geography_csv'" ) && str_contains( $delivery_admin_source, "'save_jet_geography_override'" ) && str_contains( $delivery_admin_source, "'save_jet_status_mapping'" ) && str_contains( $delivery_admin_source, "check_admin_referer( 'wdc_delivery_services' )" ) && str_contains( $delivery_admin_source, 'current_user_can( AdminMenu::CAPABILITY )' ), 'Jet POST actions must be handled by the shared delivery services action pipeline.' );
jet_assert( str_contains( $delivery_admin_source, "service_tab_url_by_key( JetLogisticSettings::SERVICE_KEY, \$tab )" ) && str_contains( $delivery_admin_source, "'save_jet_status_mapping' => 'jet_statuses'" ), 'Jet POST actions must redirect back to their embedded tabs.' );
jet_assert( str_contains( $geography_admin_source, "JetLogisticCitiesCsvClient::DEFAULT_URL" ) && ! str_contains( $geography_admin_source, "\$_POST['url'" ) && ! str_contains( $geography_admin_source, 'wp_remote_get' ), 'Jet remote geography import must use the fixed client URL and not read arbitrary POST URLs.' );
jet_assert( str_contains( $delivery_admin_source, 'set_transient( $this->jet_admin_notice_key()' ) && str_contains( $delivery_admin_source, 'get_transient( $key )' ) && str_contains( $delivery_admin_source, 'delete_transient( $key )' ), 'Jet admin actions must use one-shot flash notices.' );
jet_assert( ! str_contains( $plugin_source, 'JetLogisticGeographyAdminPage::class )->register()' ) && ! str_contains( $plugin_source, 'JetLogisticStatusAdminPage::class )->register()' ), 'Plugin hooks must not register standalone Jet admin pages.' );
jet_assert( str_contains( $delivery_admin_source, "page=' . self::MENU_SLUG . '&service=' . rawurlencode( \$service->service_key ) . '&tab=' . rawurlencode( \$tab_key )" ) && str_contains( $delivery_admin_source, "http_build_query( array( 'page' => self::MENU_SLUG, 'service' => \$service_key, 'tab' => \$tab )" ), 'Jet tab URLs must use the delivery services service-tab URL helpers.' );
jet_assert( str_contains( $plugin_source, 'use WallsShop\\WDC\\Carriers\\JetLogistic\\Geography\\JetLogisticCitiesCsvClient;' ), 'Plugin DI must import JetLogisticCitiesCsvClient from the Jet geography namespace.' );
jet_assert( ! str_contains( (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Geography/JetLogisticGeographyRepository.php' ), 'return (bool) $this->wpdb->update' ), 'Jet manual override snapshot update must not cast wpdb update result to bool.' );
foreach ( array( 'download failed', 'is empty', 'response is too large', 'returned HTML', 'upload failed', 'has no rows', 'operation completed', 'operation failed', 'component is unavailable', 'Unknown Jet Logistic admin action' ) as $english_message ) {
	jet_assert( ! str_contains( $geography_admin_source . $status_admin_source . $delivery_admin_source . (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Geography/JetLogisticCitiesCsvClient.php' ) . (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Geography/JetLogisticGeographyImportService.php' ), $english_message ), 'Jet admin user-facing messages must be Russian: ' . $english_message );
}
foreach ( array( 'География Jet Logistic успешно импортирована.', 'Ручное сопоставление Jet Logistic применено.', 'Настройки Jet Logistic сохранены.', 'Сопоставление статуса Jet Logistic сохранено.', 'Не удалось скачать cities.csv Jet Logistic', 'Строк импортировано', 'Сопоставлено', 'Требует уточнения', 'Не сопоставлено', 'Пропущено', 'Некорректных строк' ) as $russian_message ) {
	jet_assert( str_contains( $geography_admin_source . $status_admin_source . $delivery_admin_source . (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Geography/JetLogisticCitiesCsvClient.php' ) . (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Geography/JetLogisticGeographyImportService.php' ), $russian_message ), 'Jet admin must expose Russian message or label: ' . $russian_message );
}

$plugin = new Plugin( new PluginEnvironment( $root . '/walls-delivery-calc.php', $root, 'https://example.test/wp-content/plugins/walls-delivery-calc/', '0.129.7' ) );
$register_services = new ReflectionMethod( Plugin::class, 'register_services' );
$register_services->setAccessible( true );
$register_services->invoke( $plugin );
$container = $plugin->container();
jet_assert( $container->get( JetLogisticCitiesCsvClient::class ) instanceof JetLogisticCitiesCsvClient, 'Plugin container must resolve JetLogisticCitiesCsvClient without Core namespace fallback.' );
jet_assert( $container->get( JetLogisticGeographyAdminPage::class ) instanceof JetLogisticGeographyAdminPage, 'Plugin container must resolve Jet geography admin page without missing Jet imports.' );
jet_assert( $container->get( DeliveryServicesAdminPage::class ) instanceof DeliveryServicesAdminPage, 'Plugin container must resolve delivery services admin page with embedded Jet dependencies.' );

$csv_client = new JetLogisticCitiesCsvClient();
$GLOBALS['wdc_remote_get_responses'] = array( array( 'status' => 200, 'body' => "city;region;country_code\nAstana;;KZ\n" ) );
jet_assert( str_contains( $csv_client->fetch( JetLogisticCitiesCsvClient::DEFAULT_URL ), 'Astana' ) && JetLogisticCitiesCsvClient::DEFAULT_URL === $GLOBALS['wdc_remote_get_requests'][0]['url'], 'Jet cities CSV client must fetch the fixed official URL and return CSV body.' );
foreach (
	array(
		array( new WP_Error(), 'WP_Error' ),
		array( array( 'status' => 403, 'body' => 'forbidden' ), 'non-200' ),
		array( array( 'status' => 200, 'body' => '' ), 'empty body' ),
		array( array( 'status' => 200, 'body' => '<!doctype html><html></html>' ), 'html body' ),
		array( array( 'status' => 200, 'body' => str_repeat( 'x', 20971521 ) ), 'oversized body' ),
	) as $case
) {
	$GLOBALS['wdc_remote_get_responses'] = array( $case[0] );
	$failed = false;
	$message = '';
	try {
		$csv_client->fetch( JetLogisticCitiesCsvClient::DEFAULT_URL );
	} catch ( RuntimeException $exception ) {
		$failed = true;
		$message = $exception->getMessage();
	}
	jet_assert( $failed && ! preg_match( '/download failed|is empty|response is too large|returned HTML/', $message ), 'Jet cities CSV client must reject ' . $case[1] . ' with a Russian safe message.' );
}

$GLOBALS['wdc_db_delta'] = array();
$migration_0044 = require dirname( __DIR__, 2 ) . '/database/migrations/0044_create_jet_logistic_geography_tables.php';
jet_assert( is_callable( $migration_0044 ) && empty( $GLOBALS['wdc_db_delta'] ), 'Jet migration 0044 must return a callable and not execute schema on require.' );
$migration_0044();
jet_assert( function_exists( 'dbDelta' ) && 2 === count( $GLOBALS['wdc_db_delta'] ), 'Jet migration 0044 must create geography schemas only after explicit callback execution.' );
$migration_0045 = require dirname( __DIR__, 2 ) . '/database/migrations/0045_create_jet_logistic_status_mappings.php';
jet_assert( is_callable( $migration_0045 ) && 2 === count( $GLOBALS['wdc_db_delta'] ), 'Jet migration 0045 must return a callable and not execute schema on require.' );
$migration_0045();
jet_assert( 3 === count( $GLOBALS['wdc_db_delta'] ) && count( $GLOBALS['wpdb']->jet_statuses ) >= 2, 'Jet migration 0045 must create status schema and seed default mappings after explicit callback execution.' );

$repository_root = dirname( __DIR__, 2 ) . '/src/Carriers/JetLogistic';
jet_assert( str_contains( (string) file_get_contents( $repository_root . '/Geography/JetLogisticGeographyRepository.php' ), '\\dbDelta(' ), 'Jet geography repository must call global dbDelta.' );
jet_assert( str_contains( (string) file_get_contents( $repository_root . '/Geography/JetLogisticGeographyOverrideRepository.php' ), '\\dbDelta(' ), 'Jet geography override repository must call global dbDelta.' );
jet_assert( str_contains( (string) file_get_contents( $repository_root . '/Status/JetLogisticStatusMappingRepository.php' ), '\\dbDelta(' ), 'Jet status mapping repository must call global dbDelta.' );

$migration_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-jet-migration-' . str_replace( '.', '', uniqid( '', true ) );
mkdir( $migration_dir );
$migration_file = $migration_dir . DIRECTORY_SEPARATOR . '0001_jet_fake_migration.php';
$GLOBALS['wdc_options'] = array();
file_put_contents( $migration_file, "<?php\nreturn static function (): void { throw new RuntimeException('jet fake failure'); };\n" );
$failed = false;
try {
	( new MigrationManager( '0.129.7-test', $migration_dir ) )->run();
} catch ( RuntimeException ) {
	$failed = true;
}
jet_assert( $failed && ! in_array( '0001_jet_fake_migration.php', (array) get_option( 'wdc_applied_migrations', array() ), true ), 'Failed migration callback must not be marked as applied.' );
file_put_contents( $migration_file, "<?php\nreturn static function (): void { update_option('wdc_jet_fake_migration_runs', (int) get_option('wdc_jet_fake_migration_runs', 0) + 1, false); };\n" );
( new MigrationManager( '0.129.7-test', $migration_dir ) )->run();
jet_assert( in_array( '0001_jet_fake_migration.php', (array) get_option( 'wdc_applied_migrations', array() ), true ) && '0.129.7-test' === get_option( 'wdc_db_version', '' ), 'Successful migration callback must be marked as applied and update db version.' );
( new MigrationManager( '0.129.7-test', $migration_dir ) )->run();
jet_assert( 1 === (int) get_option( 'wdc_jet_fake_migration_runs', 0 ), 'Applied migration must not run again on repeated MigrationManager run.' );
unlink( $migration_file );
rmdir( $migration_dir );
$GLOBALS['wdc_options'] = array();
$GLOBALS['wpdb']->jet_statuses = array();

$normalizer = new JetLogisticCityNameNormalizer();
jet_assert( $normalizer->normalize( ' г. АСТАНА  ' ) === $normalizer->normalize( 'Астана' ), 'Jet city normalizer must trim prefixes, case and spaces.' );
$parser = new JetLogisticCitiesCsvParser( $normalizer );
$parsed = $parser->parse( "city;region;country_code\nАстана;;KZ\nМосква;;RU\n" );
jet_assert( 2 === count( $parsed ) && 'KZ' === $parsed[0]['country_code'] && 'RU' === $parsed[1]['country_code'], 'Jet CSV parser must parse country and rows without region.' );

$settings_repo = new SettingsRepository();
$credentials = new JetLogisticCredentials( $settings_repo, new EncryptionService() );
$credentials->save_access_token( 'secret-token' );
$settings_repo->set( JetLogisticSettings::ORIGIN_SOURCE_IDENTITY_KEY, 'origin' );
$settings = new JetLogisticSettings( $settings_repo );
$geo = new JetLogisticGeographyRepository( $GLOBALS['wpdb'] );
$geo->replace_snapshot(
	array(
		array( 'source_identity' => 'origin', 'source_city' => 'Алматы', 'source_region' => '', 'normalized_city' => 'алматы', 'normalized_region' => '', 'country_code' => 'KZ', 'location_id' => 1, 'match_status' => 'matched', 'match_source' => 'manual', 'active' => 1 ),
		array( 'source_identity' => 'dest', 'source_city' => 'Астана', 'source_region' => '', 'normalized_city' => 'астана', 'normalized_region' => '', 'country_code' => 'KZ', 'location_id' => 10, 'match_status' => 'matched', 'match_source' => 'manual', 'active' => 1 ),
	)
);
$geo->replace_snapshot(
	array(
		array( 'source_identity' => 'origin', 'source_city' => 'Almaty', 'source_region' => '', 'normalized_city' => 'almaty', 'normalized_region' => '', 'country_code' => 'KZ', 'location_id' => 1, 'match_status' => 'matched', 'match_source' => 'manual', 'active' => 1 ),
		array( 'source_identity' => 'dest', 'source_city' => 'Astana', 'source_region' => '', 'normalized_city' => 'astana', 'normalized_region' => '', 'country_code' => 'KZ', 'location_id' => 10, 'match_status' => 'matched', 'match_source' => 'manual', 'active' => 1 ),
		array( 'source_identity' => 'manual-target', 'source_city' => 'Manual Target', 'source_region' => '', 'normalized_city' => 'manual target', 'normalized_region' => '', 'country_code' => 'KZ', 'location_id' => 0, 'match_status' => 'unmatched', 'match_source' => '', 'active' => 1 ),
		array( 'source_identity' => 'rollback-target', 'source_city' => 'Rollback Target', 'source_region' => '', 'normalized_city' => 'rollback target', 'normalized_region' => '', 'country_code' => 'KZ', 'location_id' => 77, 'match_status' => 'matched', 'match_source' => 'manual_override', 'active' => 1 ),
		array( 'source_identity' => 'ru-target', 'source_city' => 'Ru Target', 'source_region' => '', 'normalized_city' => 'ru target', 'normalized_region' => '', 'country_code' => 'RU', 'location_id' => 0, 'match_status' => 'unmatched', 'match_source' => '', 'active' => 1 ),
	)
);
$GLOBALS['wpdb']->services[] = array( 'id' => 501, 'service_key' => JetLogisticSettings::SERVICE_KEY, 'carrier_key' => JetLogisticSettings::CARRIER_KEY, 'service_type' => DeliveryService::TYPE_API, 'title' => 'Jet Logistic', 'enabled' => 1, 'deleted' => 0 );
$GLOBALS['wpdb']->countries[] = array( 'service_id' => 501, 'country_code' => 'US' );
$GLOBALS['wpdb']->locations[] = array( 'id' => 77, 'country_code' => 'KZ', 'city_name' => 'Manual Target', 'place_name' => 'Manual Target', 'active' => 1 );
$GLOBALS['wpdb']->locations[] = array( 'id' => 88, 'country_code' => 'KG', 'city_name' => 'Rollback Target', 'place_name' => 'Rollback Target', 'active' => 1 );
$GLOBALS['wpdb']->locations[] = array( 'id' => 99, 'country_code' => 'RU', 'city_name' => 'Ru Target', 'place_name' => 'Ru Target', 'active' => 1 );
$country_sync = new JetLogisticCountrySyncService( $geo, new DeliveryServiceRepository( $GLOBALS['wpdb'] ), new DeliveryServiceCountryRepository( $GLOBALS['wpdb'] ), $settings_repo );
$jet_geo_admin = ( new ReflectionClass( JetLogisticGeographyAdminPage::class ) )->newInstanceWithoutConstructor();
$override_repo = new JetLogisticGeographyOverrideRepository( $GLOBALS['wpdb'] );
foreach ( array( 'overrides' => $override_repo, 'geography' => $geo, 'country_sync' => $country_sync, 'locations' => new LocationRepository( $GLOBALS['wpdb'] ), 'settings' => $settings, 'credentials' => $credentials ) as $property => $value ) {
	$ref = new ReflectionProperty( JetLogisticGeographyAdminPage::class, $property );
	$ref->setAccessible( true );
	$ref->setValue( $jet_geo_admin, $value );
}
$keep_token = $credentials->access_token();
$jet_geo_admin->save_settings_from_post( array( 'jet_logistic_access_token' => '' ) );
jet_assert( $keep_token === $credentials->access_token() && $credentials->has_access_token(), 'Empty Jet token field must keep the existing token.' );
$jet_geo_admin->save_settings_from_post( array( 'jet_logistic_access_token' => 'new-secret' ) );
jet_assert( 'new-secret' === $credentials->access_token() && $credentials->has_access_token(), 'Non-empty Jet token field must replace the existing token.' );
$jet_geo_admin->save_settings_from_post( array( 'jet_logistic_access_token' => 'ignored-secret', 'jet_logistic_clear_access_token' => '1' ) );
jet_assert( '' === $credentials->access_token() && ! $credentials->has_access_token(), 'Jet clear token checkbox must remove the token and win over a new token value.' );
$credentials->save_access_token( 'secret-token' );
$override = $jet_geo_admin->save_override_from_post( array( 'source_identity' => 'manual-target', 'location_id' => 77 ) );
$manual_row = $geo->active_for_location( 77 );
jet_assert( ! empty( $override['success'] ) && ! empty( $GLOBALS['wpdb']->jet_overrides['manual-target'] ) && 'matched' === (string) ( $manual_row['match_status'] ?? '' ) && 'manual_override' === (string) ( $manual_row['match_source'] ?? '' ) && 'KZ' === (string) ( $manual_row['country_code'] ?? '' ), 'Jet manual override must save override and immediately update the active geography snapshot.' );
$second_override = $jet_geo_admin->save_override_from_post( array( 'source_identity' => 'manual-target', 'location_id' => 77 ) );
$manual_row_after_second_save = $geo->active_for_location( 77 );
jet_assert( ! empty( $second_override['success'] ) && ! empty( $GLOBALS['wpdb']->jet_overrides['manual-target'] ) && 'matched' === (string) ( $manual_row_after_second_save['match_status'] ?? '' ) && 'manual_override' === (string) ( $manual_row_after_second_save['match_source'] ?? '' ), 'Jet repeated manual override save must treat wpdb update result 0 as success and keep persistent override.' );
$enabled_countries = ( new DeliveryServiceCountryRepository( $GLOBALS['wpdb'] ) )->countries( 501 );
jet_assert( in_array( 'US', $enabled_countries, true ) && in_array( 'KZ', $enabled_countries, true ) && 1 === count( array_keys( $enabled_countries, 'KZ', true ) ), 'Jet manual override must add the location country without removing existing service countries or creating duplicates.' );
$matcher = new \WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyMatcher( new LocationRepository( $GLOBALS['wpdb'] ), $override_repo );
$import_service = new \WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyImportService( $parser, $matcher, $geo, $country_sync );
$override_repo->save( $normalizer->identity( 'Manual Target', '', 'KZ' ), 77, 'KZ' );
$import_result = $import_service->import_csv( "city;region;country_code\nManual Target;;KZ\n" );
$manual_row_after_import = $geo->active_for_location( 77 );
jet_assert( ! empty( $import_result['success'] ) && 'manual_override' === (string) ( $manual_row_after_import['match_source'] ?? '' ), 'Jet CSV import must reapply persistent manual override after repeated imports.' );
$override_repo->save( 'rollback-target', 77, 'KZ' );
$GLOBALS['wpdb']->jet_update_fail_sources[] = 'rollback-target';
$rollback = $jet_geo_admin->save_override_from_post( array( 'source_identity' => 'rollback-target', 'location_id' => 88 ) );
jet_assert( empty( $rollback['success'] ) && 77 === (int) ( $GLOBALS['wpdb']->jet_overrides['rollback-target']['location_id'] ?? 0 ) && 'KZ' === (string) ( $GLOBALS['wpdb']->jet_overrides['rollback-target']['country_code'] ?? '' ), 'Jet failed snapshot apply must restore an existing previous manual override instead of deleting it.' );
$GLOBALS['wpdb']->jet_update_fail_sources = array();
$ru_override = $jet_geo_admin->save_override_from_post( array( 'source_identity' => 'ru-target', 'location_id' => 99 ) );
$enabled_countries_after_ru = ( new DeliveryServiceCountryRepository( $GLOBALS['wpdb'] ) )->countries( 501 );
jet_assert( ! empty( $ru_override['success'] ) && ! in_array( 'RU', $enabled_countries_after_ru, true ), 'Jet manual override country sync must not enable RU.' );
$missing_override = $jet_geo_admin->save_override_from_post( array( 'source_identity' => 'missing-row', 'location_id' => 77 ) );
$invalid_override = $jet_geo_admin->save_override_from_post( array( 'source_identity' => 'manual-target', 'location_id' => 999 ) );
jet_assert( empty( $missing_override['success'] ) && empty( $GLOBALS['wpdb']->jet_overrides['missing-row'] ) && empty( $invalid_override['success'] ), 'Jet manual override must reject missing source identity and invalid location without orphan override.' );
$geo->replace_snapshot(
	array(
		array( 'source_identity' => 'origin', 'source_city' => 'Almaty', 'source_region' => '', 'normalized_city' => 'almaty', 'normalized_region' => '', 'country_code' => 'KZ', 'location_id' => 1, 'match_status' => 'matched', 'match_source' => 'manual', 'active' => 1 ),
		array( 'source_identity' => 'dest', 'source_city' => 'Astana', 'source_region' => '', 'normalized_city' => 'astana', 'normalized_region' => '', 'country_code' => 'KZ', 'location_id' => 10, 'match_status' => 'matched', 'match_source' => 'manual', 'active' => 1 ),
		array( 'source_identity' => 'manual-target', 'source_city' => 'Manual Target', 'source_region' => '', 'normalized_city' => 'manual target', 'normalized_region' => '', 'country_code' => 'KZ', 'location_id' => 77, 'match_status' => 'matched', 'match_source' => 'manual_override', 'active' => 1 ),
	)
);

$http = new JetFakeHttp(
	array(
		array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'price_zabor' => 999, 'price_terminal' => 1000, 'price_delivery' => 500, 'price_dop' => 100, 'city_to' => 'Astana', 'city_terminal_to' => 'Karaganda', 'day_from' => 3, 'day_to' => 5, 'valuta' => 'RUB' ) ), JSON_UNESCAPED_UNICODE ) ),
	)
);
$api = new JetLogisticApiClient( $http, $settings );
$carrier = new JetLogisticCarrier( $settings, $api, new JetLogisticQuoteRequestBuilder( $credentials ), new JetLogisticQuoteResponseParser(), $geo, $normalizer );
$package = Package::from_items( array( new PackageItem( 'A', 'Товар', 1, Money::from_rubles( 21000 ), Money::from_rubles( 19500 ), 2000, 100, 50, 40 ) ), 0, Money::from_rubles( 19500 ), Money::from_rubles( 19500 ) );
$quote = $carrier->quote( new QuoteRequest( 'KZ', new Address( country_code: 'KZ', city: 'Астана' ), $package, 'card', Money::from_rubles( 19500 ), '2026-07-28', array( 'location_id' => 10 ) ) );
jet_assert( $quote->success && 2 === count( $quote->rates ) && 1 === count( $http->requests ), 'Jet quote must use one API call and return two rates.' );
jet_assert( 110000 === $quote->rates[0]->price->get_kopecks() && 160000 === $quote->rates[1]->price->get_kopecks(), 'Jet rates must ignore pickup price_zabor and calculate terminal/delivery/dop sums.' );
jet_assert( DeliveryType::PICKUP === $quote->rates[0]->delivery_type && false === $quote->rates[0]->requires_pickup_point, 'Jet pickup rate must not require a concrete pickup point.' );
jet_assert( str_contains( $quote->rates[0]->title, 'Karaganda' ) && str_contains( $quote->rates[0]->comments[0] ?? '', 'Karaganda' ), 'Jet non-local terminal city must be in pickup title and comment.' );
jet_assert( '[redacted]' === (string) ( $quote->raw_reference['jet_request']['access_token'] ?? '' ) && 'secret-token' === (string) $http->requests[0]['payload']['access_token'], 'Jet token must be sent to API but redacted from diagnostics.' );
jet_assert( 19500 === (int) $http->requests[0]['payload']['cost'] && 0 === (int) $http->requests[0]['payload']['dops']['D_SDOC'], 'Jet cost and D_SDOC must use discounted package goods cost below threshold.' );

$payload = ( new JetLogisticQuoteRequestBuilder( $credentials ) )->build(
	new QuoteRequest( 'KZ', new Address( country_code: 'KZ' ), Package::from_items( array( new PackageItem( 'B', 'Товар', 1, Money::from_rubles( 25000 ), Money::from_rubles( 20000 ), 1000, 10, 10, 10 ) ), 0, Money::from_rubles( 20000 ), Money::from_rubles( 20000 ) ), 'card', Money::from_rubles( 20000 ), '2026-07-28' ),
	array( 'source_city' => 'Алматы' ),
	array( 'source_city' => 'Астана' )
);
jet_assert( 20000 === (int) $payload['cost'] && 1 === (int) $payload['dops']['D_SDOC'] && 'ТЕКСТИЛЬ' === $payload['naimenovanie'], 'Jet D_SDOC threshold and fixed cargo name must be applied.' );

$GLOBALS['wpdb']->jet_statuses = array();
$status_repo = new JetLogisticStatusMappingRepository( $GLOBALS['wpdb'] );
$status_repo->ensure_default_mappings();
$status_http = new JetFakeHttp( array( array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'logs' => array( array( 'date' => '2026-07-28 10:00:00', 'message' => 'Неизвестно' ), array( 'date' => '2026-07-27 10:00:00', 'message' => 'Груз выдан' ), array( 'date' => '2026-07-27 10:00:00', 'message' => 'Груз выдан' ) ) ) ), JSON_UNESCAPED_UNICODE ) ) ) );
$status_service = new JetLogisticStatusService( new JetLogisticApiClient( $status_http, $settings ), new JetLogisticStatusMapper( $status_repo ) );
$status = $status_service->update( array( 'tracking_number' => 'JET-1', 'universal_status_code' => DeliveryStatus::IN_TRANSIT ) );
jet_assert( DeliveryStatus::IN_TRANSIT === $status['shipment_patch']['universal_status_code'] && 2 === count( $status['shipment_patch']['status_events'] ), 'Unknown latest Jet status must preserve current universal status and deduplicate compact events.' );

$order = new JetFakeOrder();
$actual_cost_resolver = ( new ReflectionClass( ShipmentActualCostResolver::class ) )->newInstanceWithoutConstructor();
$shipment_service = new JetLogisticShipmentService( new OrderShipmentRepository(), $status_service );
$adapter = new JetLogisticShipmentAdapter( $shipment_service, $actual_cost_resolver );
$attached = $adapter->attach_manual( $order, array( 'tracking_number' => 'JET-777' ) );
$stored = $order->meta[ OrderShipmentRepository::META_KEY ][ JetLogisticSettings::CARRIER_KEY ] ?? array();
jet_assert( ! empty( $attached['success'] ) && 'JET-777' === $stored['tracking_number'] && DeliveryStatus::IN_TRANSIT === $stored['universal_status_code'] && true === $stored['attached_manually'], 'Jet manual attach must store tracking number and initial in_transit status.' );
jet_assert( ! $adapter->create( new \WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest( 1, JetLogisticSettings::CARRIER_KEY, DeliveryType::COURIER, '', new Address(), null, array(), Money::from_rubles( 0 ) ) )->success, 'Jet API shipment creation must be unsupported.' );
$adapter->remove_from_order( $order );
jet_assert( empty( $order->meta[ OrderShipmentRepository::META_KEY ][ JetLogisticSettings::CARRIER_KEY ] ?? array() ), 'Jet local remove must delete only local shipment record.' );

echo "Jet Logistic smoke passed.\n";
