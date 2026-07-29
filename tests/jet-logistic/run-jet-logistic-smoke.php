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
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticRegionNameNormalizer;
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
use WallsShop\WDC\Locations\Storage\PlaceRegionMatchResult;
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
		public int $location_batch_query_calls = 0;
		public int $location_place_name_batch_query_calls = 0;
		public int $location_single_lookup_calls = 0;
		public int $location_find_by_id_calls = 0;
		public int $location_find_many_by_ids_calls = 0;
		public int $override_batch_query_calls = 0;
		public int $override_single_lookup_calls = 0;
		public int $snapshot_bulk_upsert_calls = 0;
		public int $snapshot_single_replace_calls = 0;
		public bool $jet_import_lock_busy = false;
		public bool $jet_import_lock_acquired = false;
		public bool $jet_fail_next_snapshot_bulk = false;
		public bool $jet_rollback_snapshot_after_write = false;
		public array $queries = array();
		public function get_charset_collate(): string { return 'DEFAULT CHARSET=utf8mb4'; }
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[dsf]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}
			return $query;
		}
		public function replace( string $table, array $data, array $formats = array() ): bool {
			if ( str_contains( $table, 'wdc_jet_logistic_cities' ) ) {
				++$this->snapshot_single_replace_calls;
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
			$this->queries[] = $query;
			if ( 'START TRANSACTION' === trim( $query ) || 'COMMIT' === trim( $query ) || 'ROLLBACK' === trim( $query ) ) {
				return true;
			}
			if ( str_contains( $query, 'UPDATE wp_wdc_jet_logistic_cities SET active = 0' ) ) {
				foreach ( $this->jet_cities as $key => $row ) {
					if ( ! str_contains( $query, "'" . $key . "'" ) ) {
						$this->jet_cities[ $key ]['active'] = 0;
					}
				}
			}
			return 1;
		}
		public function get_var( string $query ): mixed {
			if ( str_contains( $query, 'GET_LOCK' ) ) {
				if ( $this->jet_import_lock_busy ) {
					return '0';
				}
				$this->jet_import_lock_acquired = true;
				return '1';
			}
			if ( str_contains( $query, 'RELEASE_LOCK' ) ) {
				$this->jet_import_lock_acquired = false;
				return '1';
			}
			return null;
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
		public function get_results( string $query, string $output = ARRAY_A ): array {
			if ( str_contains( $query, 'wdc_jet_logistic_location_overrides' ) ) {
				if ( preg_match_all( "/'([^']+)'/", $query, $matches ) ) {
					return array_values( array_intersect_key( $this->jet_overrides, array_flip( $matches[1] ) ) );
				}
				return array_values( $this->jet_overrides );
			}
			return array_values( $this->jet_cities );
		}
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
$render_embedded_start = strpos( $geography_admin_source, 'public function render_embedded' );
$render_notice_start = strpos( $geography_admin_source, 'private function render_notice', $render_embedded_start );
$render_embedded_source = false !== $render_embedded_start && false !== $render_notice_start ? substr( $geography_admin_source, $render_embedded_start, $render_notice_start - $render_embedded_start ) : '';
jet_assert( str_contains( $render_embedded_source, '<th class="wdc-row-number">№</th>' ) && str_contains( $render_embedded_source, 'Сопоставленный населённый пункт' ) && str_contains( $render_embedded_source, '$index + 1' ), 'Jet geography admin table must show row numbers and matched location display names.' );
jet_assert( str_contains( $geography_admin_source, 'location_display_names_for_rows' ) && str_contains( $geography_admin_source, 'find_map_by_ids( $location_ids )' ) && ! str_contains( $render_embedded_source, 'find_by_id(' ), 'Jet geography admin table must batch-load display names and avoid per-row find_by_id calls.' );
jet_assert( ! str_contains( (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Geography/JetLogisticGeographyRepository.php' ), 'return (bool) $this->wpdb->update' ), 'Jet manual override snapshot update must not cast wpdb update result to bool.' );
foreach ( array( 'download failed', 'is empty', 'response is too large', 'returned HTML', 'upload failed', 'has no rows', 'operation completed', 'operation failed', 'component is unavailable', 'Unknown Jet Logistic admin action' ) as $english_message ) {
	jet_assert( ! str_contains( $geography_admin_source . $status_admin_source . $delivery_admin_source . (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Geography/JetLogisticCitiesCsvClient.php' ) . (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Geography/JetLogisticGeographyImportService.php' ), $english_message ), 'Jet admin user-facing messages must be Russian: ' . $english_message );
}
foreach ( array( 'География Jet Logistic успешно импортирована.', 'Ручное сопоставление Jet Logistic применено.', 'Настройки Jet Logistic сохранены.', 'Сопоставление статуса Jet Logistic сохранено.', 'Не удалось скачать cities.csv Jet Logistic', 'Строк прочитано', 'Уникальных строк', 'Дубликатов', 'Сопоставлено', 'Требует уточнения', 'Не сопоставлено', 'Пропущено', 'Некорректных строк' ) as $russian_message ) {
	jet_assert( str_contains( $geography_admin_source . $status_admin_source . $delivery_admin_source . (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Geography/JetLogisticCitiesCsvClient.php' ) . (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Geography/JetLogisticGeographyImportService.php' ), $russian_message ), 'Jet admin must expose Russian message or label: ' . $russian_message );
}

$plugin = new Plugin( new PluginEnvironment( $root . '/walls-delivery-calc.php', $root, 'https://example.test/wp-content/plugins/walls-delivery-calc/', '0.129.14' ) );
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
$migration_0046_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/database/migrations/0046_add_import_token_to_jet_logistic_cities.php' );
$migration_0046 = require dirname( __DIR__, 2 ) . '/database/migrations/0046_add_import_token_to_jet_logistic_cities.php';
jet_assert( is_callable( $migration_0046 ), 'Jet migration 0046 must return a callable.' );
jet_assert( str_contains( $migration_0046_source, 'import_token' ), 'Jet migration 0046 must add import_token column.' );
jet_assert( str_contains( $migration_0046_source, 'KEY import_token' ), 'Jet migration 0046 must add import_token index.' );

$migration_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-jet-migration-' . str_replace( '.', '', uniqid( '', true ) );
mkdir( $migration_dir );
$migration_file = $migration_dir . DIRECTORY_SEPARATOR . '0001_jet_fake_migration.php';
$GLOBALS['wdc_options'] = array();
file_put_contents( $migration_file, "<?php\nreturn static function (): void { throw new RuntimeException('jet fake failure'); };\n" );
$failed = false;
try {
	( new MigrationManager( '0.129.14-test', $migration_dir ) )->run();
} catch ( RuntimeException ) {
	$failed = true;
}
jet_assert( $failed && ! in_array( '0001_jet_fake_migration.php', (array) get_option( 'wdc_applied_migrations', array() ), true ), 'Failed migration callback must not be marked as applied.' );
file_put_contents( $migration_file, "<?php\nreturn static function (): void { update_option('wdc_jet_fake_migration_runs', (int) get_option('wdc_jet_fake_migration_runs', 0) + 1, false); };\n" );
( new MigrationManager( '0.129.14-test', $migration_dir ) )->run();
jet_assert( in_array( '0001_jet_fake_migration.php', (array) get_option( 'wdc_applied_migrations', array() ), true ) && '0.129.14-test' === get_option( 'wdc_db_version', '' ), 'Successful migration callback must be marked as applied and update db version.' );
( new MigrationManager( '0.129.14-test', $migration_dir ) )->run();
jet_assert( 1 === (int) get_option( 'wdc_jet_fake_migration_runs', 0 ), 'Applied migration must not run again on repeated MigrationManager run.' );
unlink( $migration_file );
rmdir( $migration_dir );
$GLOBALS['wdc_options'] = array();
$GLOBALS['wpdb']->jet_statuses = array();

$normalizer = new JetLogisticCityNameNormalizer();
$region_normalizer = new JetLogisticRegionNameNormalizer();
jet_assert( $normalizer->normalize( ' г. АСТАНА  ' ) === $normalizer->normalize( 'Астана' ), 'Jet city normalizer must trim prefixes, case and spaces.' );
$parser = new JetLogisticCitiesCsvParser( $normalizer, $region_normalizer );
$parsed = $parser->parse( "city;region;country_code\nАстана;;KZ\nМосква;;RU\n" );
jet_assert( 2 === count( $parsed ) && 'KZ' === $parsed[0]['country_code'] && 'RU' === $parsed[1]['country_code'], 'Jet CSV parser must parse country and rows without region.' );
$combined = $parser->parse( "city\nАксу-(Павлодарская область)\n8 Марта п.-(Новосибирская Область)\nАзово с.-(Омская область)\n" );
jet_assert( 'Аксу' === (string) $combined[0]['source_city'] && 'Павлодарская область' === (string) $combined[0]['source_region'] && '' === (string) $combined[0]['country_code'], 'Jet CSV parser must split city-region combined values without defaulting country to RU.' );
jet_assert( '8 Марта' === (string) $combined[1]['source_city'] && 'п' === (string) $combined[1]['source_place_type'] && 'Новосибирская Область' === (string) $combined[1]['source_region'], 'Jet CSV parser must split trailing settlement type from 8 Марта п.' );
jet_assert( 'Азово' === (string) $combined[2]['source_city'] && 'с' === (string) $combined[2]['source_place_type'] && 'Омская область' === (string) $combined[2]['source_region'] && '' === (string) $parser->parse( "city\nАксу-(Павлодарская область)\n" )[0]['source_place_type'], 'Jet CSV parser must split source place types and keep type empty when source has none.' );
$typed_identity_rows = $parser->parse( "city\n8 \u{041C}\u{0430}\u{0440}\u{0442}\u{0430} \u{043F}.-(\u{041D}\u{043E}\u{0432}\u{043E}\u{0441}\u{0438}\u{0431}\u{0438}\u{0440}\u{0441}\u{043A}\u{0430}\u{044F} \u{043E}\u{0431}\u{043B}\u{0430}\u{0441}\u{0442}\u{044C})\n8 \u{041C}\u{0430}\u{0440}\u{0442}\u{0430} \u{0441}.-(\u{041D}\u{043E}\u{0432}\u{043E}\u{0441}\u{0438}\u{0431}\u{0438}\u{0440}\u{0441}\u{043A}\u{0430}\u{044F} \u{043E}\u{0431}\u{043B}\u{0430}\u{0441}\u{0442}\u{044C})\n" );
jet_assert( (string) $typed_identity_rows[0]['source_identity'] !== (string) $typed_identity_rows[1]['source_identity'] && (string) $typed_identity_rows[0]['legacy_source_identity'] === (string) $typed_identity_rows[1]['legacy_source_identity'], 'Jet source identity must include source_place_type while keeping the previous untyped legacy identity available.' );
jet_assert( $region_normalizer->normalize( 'Павлодарская область' ) === $region_normalizer->normalize( 'Павлодарская' ) && 'хакасия' === $region_normalizer->normalize( 'Хакасия Республика' ) && $region_normalizer->normalize( 'Хакасия Республика' ) === $region_normalizer->normalize( 'Республика Хакасия' ) && 'алматинская' === $region_normalizer->normalize( 'Алма-Ата' ) && 'новосибирская' === $region_normalizer->normalize( 'Новосибирская Область' ) && 'мангистауская' === $region_normalizer->normalize( 'Мангистауская область' ), 'Jet region normalizer must strip administrative words and apply controlled aliases.' );

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
$GLOBALS['wpdb']->locations[] = array( 'id' => 77, 'country_code' => 'KZ', 'region_name' => 'Manual Region', 'city_name' => 'Manual Target', 'place_name' => 'Manual Target', 'active' => 1 );
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
$matcher = new \WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyMatcher( new LocationRepository( $GLOBALS['wpdb'] ), $override_repo, $region_normalizer );
$import_service = new \WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyImportService( $parser, $matcher, $geo, $country_sync );
$manual_target_identity = (string) $parser->parse( "city;region;country_code\nManual Target;Manual Region;KZ\n" )[0]['source_identity'];
$override_repo->save( $manual_target_identity, 77, 'KZ' );
$import_result = $import_service->import_csv( "city;region;country_code\nManual Target;Manual Region;KZ\n" );
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

$GLOBALS['wpdb']->locations = array(
	array( 'id' => 184501, 'country_code' => 'RU', 'region_name' => 'Карачаево-Черкесская', 'place_name' => 'Аксу', 'place_type' => 'с', 'active' => 1 ),
	array( 'id' => 184502, 'country_code' => 'RU', 'region_name' => 'Татарстан', 'place_name' => 'Аксу', 'place_type' => 'с', 'active' => 1 ),
	array( 'id' => 184503, 'country_code' => 'KZ', 'region_name' => 'Акмолинская', 'place_name' => 'Аксу', 'place_type' => 'с', 'active' => 1 ),
	array( 'id' => 184504, 'country_code' => 'KZ', 'region_name' => 'Туркестанская', 'place_name' => 'Аксу', 'place_type' => 'с', 'active' => 1 ),
	array( 'id' => 184516, 'country_code' => 'KZ', 'region_name' => 'Павлодарская', 'place_name' => 'Аксу', 'place_type' => 'г', 'active' => 1 ),
	array( 'id' => 184505, 'country_code' => 'KZ', 'region_name' => 'Западно-Казахстанская', 'place_name' => 'Аксу', 'place_type' => 'с', 'active' => 1 ),
	array( 'id' => 184601, 'country_code' => 'KZ', 'region_name' => 'Алматинская', 'place_name' => 'Аксу', 'place_type' => 'с', 'active' => 1 ),
	array( 'id' => 184602, 'country_code' => 'KZ', 'region_name' => 'Алматинская', 'place_name' => 'Аксу', 'place_type' => 'аул', 'active' => 1 ),
	array( 'id' => 163568, 'country_code' => 'KZ', 'region_name' => 'Мангистауская', 'place_name' => 'Актау', 'place_type' => 'г', 'active' => 1 ),
	array( 'id' => 163569, 'country_code' => 'KZ', 'region_name' => 'Карагандинская', 'place_name' => 'Актау', 'place_type' => 'п', 'active' => 1 ),
	array( 'id' => 162695, 'country_code' => 'KZ', 'region_name' => 'Алматинская', 'place_name' => 'Алматы', 'place_type' => 'г', 'active' => 1 ),
	array( 'id' => 190001, 'country_code' => 'RU', 'region_name' => 'Хакасия', 'place_name' => 'Абакан', 'place_type' => 'г', 'active' => 1 ),
	array( 'id' => 191001, 'country_code' => 'RU', 'region_name' => 'Новосибирская', 'place_name' => '8 Марта', 'place_type' => 'п', 'active' => 1 ),
	array( 'id' => 191002, 'country_code' => 'RU', 'region_name' => 'Новосибирская', 'place_name' => '8 Марта', 'place_type' => 'с', 'active' => 1 ),
	array( 'id' => 192001, 'country_code' => 'RU', 'region_name' => 'Омская', 'place_name' => 'Азово', 'place_type' => 'с', 'active' => 1 ),
	array( 'id' => 192002, 'country_code' => 'RU', 'region_name' => 'Омская', 'place_name' => 'Азово', 'place_type' => 'д', 'active' => 1 ),
);
$GLOBALS['wpdb']->jet_overrides = array();
$cross_matcher = new \WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyMatcher( new LocationRepository( $GLOBALS['wpdb'] ), new JetLogisticGeographyOverrideRepository( $GLOBALS['wpdb'] ), $region_normalizer );
$location_backup = $GLOBALS['wpdb']->locations;
$GLOBALS['wpdb']->locations = array(
	array( 'id' => 121986, 'country_code' => 'RU', 'region_name' => 'Свердловская', 'city_name' => 'Кировград', 'settlement_name' => 'Кировград', 'place_name' => 'Кировград', 'place_type' => 'г', 'city_type' => 'г', 'place_level' => 5, 'display_name' => 'Свердловская область, г Кировград', 'active' => 1 ),
	array( 'id' => 121949, 'country_code' => 'RU', 'region_name' => 'Свердловская', 'city_name' => 'Кировград', 'settlement_name' => 'Тепловая', 'place_name' => 'Тепловая', 'place_type' => 'п', 'city_type' => 'г', 'place_level' => 6, 'display_name' => 'Свердловская область, г Кировград, поселок Тепловая', 'active' => 1 ),
	array( 'id' => 122161, 'country_code' => 'RU', 'region_name' => 'Свердловская', 'city_name' => 'Кировград', 'settlement_name' => 'Нейво-Рудянка', 'place_name' => 'Нейво-Рудянка', 'place_type' => 'п', 'city_type' => 'г', 'place_level' => 6, 'display_name' => 'Свердловская область, г Кировград, поселок Нейво-Рудянка', 'active' => 1 ),
	array( 'id' => 500001, 'country_code' => 'KZ', 'region_name' => 'Тестовая', 'city_name' => 'Город Только City Name', 'settlement_name' => '', 'place_name' => '', 'city_type' => 'г', 'place_level' => 5, 'display_name' => 'Тестовая, г Город Только City Name', 'active' => 1 ),
);
$kirovgrad_matcher = new \WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyMatcher( new LocationRepository( $GLOBALS['wpdb'] ), new JetLogisticGeographyOverrideRepository( $GLOBALS['wpdb'] ), $region_normalizer );
$kirovgrad = $kirovgrad_matcher->match( $parser->parse( "city\nКировград-(Свердловская Область)\n" )[0] );
jet_assert( 'ignored' === (string) $kirovgrad['match_status'] && 'country_ru_inferred_by_region' === (string) $kirovgrad['match_source'] && 121986 === (int) $kirovgrad['location_id'] && 'RU' === (string) $kirovgrad['country_code'] && 0 === (int) $kirovgrad['active'], 'Jet matching must not treat child parent-only city_name values as direct matches for Kirovgrad.' );
$teplovaya = $kirovgrad_matcher->match( $parser->parse( "city\nТепловая п.-(Свердловская область)\n" )[0] );
jet_assert( 'ignored' === (string) $teplovaya['match_status'] && 121949 === (int) $teplovaya['location_id'], 'Jet matching must still match child locality by its own direct place_name.' );
$city_name_only = $kirovgrad_matcher->match( $parser->parse( "city\nГород Только City Name-(Тестовая область)\n" )[0] );
jet_assert( 'matched' === (string) $city_name_only['match_status'] && 500001 === (int) $city_name_only['location_id'] && 'KZ' === (string) $city_name_only['country_code'], 'Jet matching must keep support for locations whose direct name exists only in city_name.' );
$GLOBALS['wpdb']->locations = $location_backup;
$aksu = $cross_matcher->match( $parser->parse( "city\nАксу-(Павлодарская область)\n" )[0] );
jet_assert( 'matched' === (string) $aksu['match_status'] && 'exact_name_region_inferred_country' === (string) $aksu['match_source'] && 184516 === (int) $aksu['location_id'] && 'KZ' === (string) $aksu['country_code'], 'Jet matching must infer KZ Аксу only from Павлодарская region and never use city-only fallback.' );
$missing_region = $cross_matcher->match( array( 'source_identity' => 'missing-region', 'source_city' => 'Аксу', 'source_region' => '', 'country_code' => '' ) );
jet_assert( 'unmatched' === (string) $missing_region['match_status'] && 'missing_region' === (string) $missing_region['match_source'] && empty( $missing_region['location_id'] ), 'Jet matching must not choose any city when source region is missing.' );
$ambiguous_aksu = $cross_matcher->match( $parser->parse( "city\nАксу-(Алма-Ата)\n" )[0] );
jet_assert( 'ambiguous' === (string) $ambiguous_aksu['match_status'] && 'exact_name_region_multiple' === (string) $ambiguous_aksu['match_source'], 'Jet matching must leave multiple candidates in the same region ambiguous.' );
$aktau = $cross_matcher->match( $parser->parse( "city\nАктау-(Мангистауская область)\n" )[0] );
jet_assert( 'matched' === (string) $aktau['match_status'] && 163568 === (int) $aktau['location_id'] && 'KZ' === (string) $aktau['country_code'], 'Jet matching must choose Актау in Мангистауская region only.' );
$almaty = $cross_matcher->match( $parser->parse( "city\nАлматы-(Алма-Ата)\n" )[0] );
jet_assert( 'matched' === (string) $almaty['match_status'] && 162695 === (int) $almaty['location_id'] && 'KZ' === (string) $almaty['country_code'], 'Jet matching must apply Алма-Ата region alias to Алматинская.' );
$abakan = $cross_matcher->match( $parser->parse( "city\nАбакан-(Хакасия Республика)\n" )[0] );
jet_assert( 'ignored' === (string) $abakan['match_status'] && 'country_ru_inferred_by_region' === (string) $abakan['match_source'] && 0 === (int) $abakan['active'] && 'RU' === (string) $abakan['country_code'], 'Jet matching must infer RU by city+region and ignore it only after lookup.' );
$eight_marta = $cross_matcher->match( $parser->parse( "city\n8 Марта п.-(Новосибирская область)\n" )[0] );
jet_assert( 'ignored' === (string) $eight_marta['match_status'] && 191001 === (int) $eight_marta['location_id'] && 'п' === (string) $parser->parse( "city\n8 Марта п.-(Новосибирская область)\n" )[0]['source_place_type'], 'Jet matching must prefer source place_type and select 8 Марта п. over same-name village.' );
$eight_marta_without_type = $cross_matcher->match( $parser->parse( "city\n8 Марта-(Новосибирская область)\n" )[0] );
jet_assert( 'ambiguous' === (string) $eight_marta_without_type['match_status'] && 'exact_name_region_multiple' === (string) $eight_marta_without_type['match_source'], 'Jet matching without source place_type must leave same city+region candidates ambiguous.' );
$azovo = $cross_matcher->match( $parser->parse( "city\nАзово с.-(Омская область)\n" )[0] );
jet_assert( 'ignored' === (string) $azovo['match_status'] && 192001 === (int) $azovo['location_id'] && 'с' === (string) $parser->parse( "city\nАзово с.-(Омская область)\n" )[0]['source_place_type'], 'Jet matching must use source place_type for Азово с. before falling back to city+region.' );
$location_backup = $GLOBALS['wpdb']->locations;
$GLOBALS['wpdb']->locations = array(
	array( 'id' => 192002, 'country_code' => 'RU', 'region_name' => 'Омская', 'place_name' => 'Азово', 'place_type' => 'д', 'active' => 1 ),
);
$type_mismatch_matcher = new \WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyMatcher( new LocationRepository( $GLOBALS['wpdb'] ), new JetLogisticGeographyOverrideRepository( $GLOBALS['wpdb'] ), $region_normalizer );
$type_mismatch = $type_mismatch_matcher->match( $parser->parse( "city\nАзово с.-(Омская область)\n" )[0] );
jet_assert( 'unmatched' === (string) $type_mismatch['match_status'] && 'place_type_mismatch' === (string) $type_mismatch['match_source'] && empty( $type_mismatch['location_id'] ), 'Jet matching must not fallback from source Азово с. to location Азово д.' );
$not_found = $type_mismatch_matcher->match( $parser->parse( "city\n\u{041D}\u{0435}\u{0441}\u{0443}\u{0449}\u{0435}\u{0441}\u{0442}\u{0432}\u{0443}\u{044E}\u{0449}\u{0438}\u{0439} \u{0441}.-(\u{041D}\u{0435}\u{0441}\u{0443}\u{0449}\u{0435}\u{0441}\u{0442}\u{0432}\u{0443}\u{044E}\u{0449}\u{0430}\u{044F} \u{043E}\u{0431}\u{043B}\u{0430}\u{0441}\u{0442}\u{044C})\n" )[0] );
jet_assert( 'unmatched' === (string) $not_found['match_status'] && 'exact_name_region_not_found' === (string) $not_found['match_source'], 'Jet matching must report exact_name_region_not_found when city+region has no candidates at all.' );
$GLOBALS['wpdb']->locations = array(
	array( 'id' => 192003, 'country_code' => 'RU', 'region_name' => 'Омская', 'place_name' => 'Азово', 'place_type' => '', 'active' => 1 ),
);
$empty_type_fallback_matcher = new \WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyMatcher( new LocationRepository( $GLOBALS['wpdb'] ), new JetLogisticGeographyOverrideRepository( $GLOBALS['wpdb'] ), $region_normalizer );
$empty_type_fallback = $empty_type_fallback_matcher->match( $parser->parse( "city\nАзово с.-(Омская область)\n" )[0] );
jet_assert( 'ignored' === (string) $empty_type_fallback['match_status'] && 192003 === (int) $empty_type_fallback['location_id'], 'Jet matching may fallback from typed source to a location row with an empty place_type.' );
$empty_type_resolution = ( new LocationRepository( $GLOBALS['wpdb'] ) )->resolve_active_by_place_and_region( 'Азово', $region_normalizer->normalize( 'Омская область' ), 'с' );
jet_assert( PlaceRegionMatchResult::EMPTY_TYPE_FALLBACK === $empty_type_resolution->resolution && 1 === count( $empty_type_resolution->matches ), 'LocationRepository must expose empty_type_fallback resolution for typed source and empty candidate type.' );
$GLOBALS['wpdb']->locations = array(
	array( 'id' => 192004, 'country_code' => 'RU', 'region_name' => 'Тестовая', 'place_name' => 'Азово', 'place_type' => 'с', 'active' => 1 ),
	array( 'id' => 192005, 'country_code' => 'KZ', 'region_name' => 'Тестовая', 'place_name' => 'Азово', 'place_type' => 'д', 'active' => 1 ),
);
$country_scope_matcher = new \WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyMatcher( new LocationRepository( $GLOBALS['wpdb'] ), new JetLogisticGeographyOverrideRepository( $GLOBALS['wpdb'] ), $region_normalizer );
$country_scoped_mismatch = $country_scope_matcher->match( $parser->parse( "city;region;country_code\nАзово с.;Тестовая область;KZ\n" )[0] );
jet_assert( 'unmatched' === (string) $country_scoped_mismatch['match_status'] && 'place_type_mismatch' === (string) $country_scoped_mismatch['match_source'], 'Jet explicit country scope must ignore same-type candidates from another country and diagnose mismatch inside requested country.' );
$GLOBALS['wpdb']->locations = $location_backup;
$duplicate_result = ( new \WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyImportService( $parser, $cross_matcher, $geo, $country_sync ) )->import_csv( "city\nАктау-(Мангистауская область)\nАктау-(Мангистауская область)\n" );
jet_assert( 2 === (int) $duplicate_result['rows_read'] && 1 === (int) $duplicate_result['rows_unique'] && 1 === (int) $duplicate_result['duplicates'], 'Jet import result must report read rows, unique rows and duplicates after source identity deduplication.' );
$typed_import_service = new \WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyImportService( $parser, $cross_matcher, $geo, $country_sync );
$source_fingerprint = new ReflectionMethod( \WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyImportService::class, 'source_fingerprint' );
$source_fingerprint->setAccessible( true );
jet_assert( $source_fingerprint->invoke( $typed_import_service, $typed_identity_rows[0] ) !== $source_fingerprint->invoke( $typed_import_service, $typed_identity_rows[1] ), 'Jet duplicate fingerprint must include source_place_type.' );
$classify_legacy_identities = new ReflectionMethod( \WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyImportService::class, 'classify_legacy_identities' );
$classify_legacy_identities->setAccessible( true );
$with_legacy_migration_metadata = new ReflectionMethod( \WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyImportService::class, 'with_legacy_migration_metadata' );
$with_legacy_migration_metadata->setAccessible( true );
$legacy_identity_context = $classify_legacy_identities->invoke( $typed_import_service, $typed_identity_rows );
$typed_p_with_legacy_context = $with_legacy_migration_metadata->invoke( $typed_import_service, $typed_identity_rows[0], $legacy_identity_context );
$typed_s_with_legacy_context = $with_legacy_migration_metadata->invoke( $typed_import_service, $typed_identity_rows[1], $legacy_identity_context );
jet_assert( 1 === count( $legacy_identity_context['conflicts'] ) && empty( $typed_p_with_legacy_context['legacy_override_migration_allowed'] ) && empty( $typed_s_with_legacy_context['legacy_override_migration_allowed'] ), 'Jet import metadata must deny legacy override migration when one legacy identity maps to multiple typed source identities.' );
$typed_duplicate_result = $typed_import_service->import_csv( "city\n8 \u{041C}\u{0430}\u{0440}\u{0442}\u{0430} \u{043F}.-(\u{041D}\u{043E}\u{0432}\u{043E}\u{0441}\u{0438}\u{0431}\u{0438}\u{0440}\u{0441}\u{043A}\u{0430}\u{044F} \u{043E}\u{0431}\u{043B}\u{0430}\u{0441}\u{0442}\u{044C})\n8 \u{041C}\u{0430}\u{0440}\u{0442}\u{0430} \u{0441}.-(\u{041D}\u{043E}\u{0432}\u{043E}\u{0441}\u{0438}\u{0431}\u{0438}\u{0440}\u{0441}\u{043A}\u{0430}\u{044F} \u{043E}\u{0431}\u{043B}\u{0430}\u{0441}\u{0442}\u{044C})\n" );
jet_assert( 2 === (int) $typed_duplicate_result['rows_read'] && 2 === (int) $typed_duplicate_result['rows_unique'] && 0 === (int) $typed_duplicate_result['duplicates'], 'Jet import must not deduplicate source rows that differ by source_place_type.' );
$eight_marta_p_identity = (string) $typed_identity_rows[0]['source_identity'];
$eight_marta_s_identity = (string) $typed_identity_rows[1]['source_identity'];
$eight_marta_legacy_identity = (string) $typed_identity_rows[0]['legacy_source_identity'];
$override_repo->save( $eight_marta_legacy_identity, 191001, 'RU' );
$legacy_conflict_result = $typed_import_service->import_csv( "city\n8 \u{041C}\u{0430}\u{0440}\u{0442}\u{0430} \u{043F}.-(\u{041D}\u{043E}\u{0432}\u{043E}\u{0441}\u{0438}\u{0431}\u{0438}\u{0440}\u{0441}\u{043A}\u{0430}\u{044F} \u{043E}\u{0431}\u{043B}\u{0430}\u{0441}\u{0442}\u{044C})\n8 \u{041C}\u{0430}\u{0440}\u{0442}\u{0430} \u{0441}.-(\u{041D}\u{043E}\u{0432}\u{043E}\u{0441}\u{0438}\u{0431}\u{0438}\u{0440}\u{0441}\u{043A}\u{0430}\u{044F} \u{043E}\u{0431}\u{043B}\u{0430}\u{0441}\u{0442}\u{044C})\n" );
jet_assert( 1 === (int) $legacy_conflict_result['legacy_identity_conflicts'], 'Jet import must detect conflicting legacy identities and disallow migration for both typed rows.' );
jet_assert( ! empty( $GLOBALS['wpdb']->jet_overrides[ $eight_marta_legacy_identity ] ) && empty( $GLOBALS['wpdb']->jet_overrides[ $eight_marta_p_identity ] ) && empty( $GLOBALS['wpdb']->jet_overrides[ $eight_marta_s_identity ] ) && 191001 === (int) ( $GLOBALS['wpdb']->jet_cities[ $eight_marta_p_identity ]['location_id'] ?? 0 ) && 191002 === (int) ( $GLOBALS['wpdb']->jet_cities[ $eight_marta_s_identity ]['location_id'] ?? 0 ), 'Jet conflicting legacy override must stay on legacy key while typed rows use automatic matching.' );
$legacy_conflict_reversed = $typed_import_service->import_csv( "city\n8 \u{041C}\u{0430}\u{0440}\u{0442}\u{0430} \u{0441}.-(\u{041D}\u{043E}\u{0432}\u{043E}\u{0441}\u{0438}\u{0431}\u{0438}\u{0440}\u{0441}\u{043A}\u{0430}\u{044F} \u{043E}\u{0431}\u{043B}\u{0430}\u{0441}\u{0442}\u{044C})\n8 \u{041C}\u{0430}\u{0440}\u{0442}\u{0430} \u{043F}.-(\u{041D}\u{043E}\u{0432}\u{043E}\u{0441}\u{0438}\u{0431}\u{0438}\u{0440}\u{0441}\u{043A}\u{0430}\u{044F} \u{043E}\u{0431}\u{043B}\u{0430}\u{0441}\u{0442}\u{044C})\n" );
jet_assert( 1 === (int) $legacy_conflict_reversed['legacy_identity_conflicts'] && ! empty( $GLOBALS['wpdb']->jet_overrides[ $eight_marta_legacy_identity ] ) && empty( $GLOBALS['wpdb']->jet_overrides[ $eight_marta_p_identity ] ) && empty( $GLOBALS['wpdb']->jet_overrides[ $eight_marta_s_identity ] ) && 191001 === (int) ( $GLOBALS['wpdb']->jet_cities[ $eight_marta_p_identity ]['location_id'] ?? 0 ) && 191002 === (int) ( $GLOBALS['wpdb']->jet_cities[ $eight_marta_s_identity ]['location_id'] ?? 0 ), 'Jet conflicting legacy override handling must not depend on CSV row order.' );
$GLOBALS['wpdb']->jet_overrides = array();
$GLOBALS['wpdb']->jet_cities = array();
$override_repo->save( $eight_marta_legacy_identity, 191001, 'RU' );
$safe_legacy_result = $typed_import_service->import_csv( "city\n8 \u{041C}\u{0430}\u{0440}\u{0442}\u{0430} \u{043F}.-(\u{041D}\u{043E}\u{0432}\u{043E}\u{0441}\u{0438}\u{0431}\u{0438}\u{0440}\u{0441}\u{043A}\u{0430}\u{044F} \u{043E}\u{0431}\u{043B}\u{0430}\u{0441}\u{0442}\u{044C})\n" );
jet_assert( 0 === (int) $safe_legacy_result['legacy_identity_conflicts'], 'Jet safe legacy migration must not report legacy identity conflicts.' );
jet_assert( empty( $GLOBALS['wpdb']->jet_overrides[ $eight_marta_legacy_identity ] ) && ! empty( $GLOBALS['wpdb']->jet_overrides[ $eight_marta_p_identity ] ), 'Jet safe legacy migration must move override from legacy identity to typed identity.' );
jet_assert( 'manual_override' === (string) ( $GLOBALS['wpdb']->jet_cities[ $eight_marta_p_identity ]['match_source'] ?? '' ), 'Jet safe legacy migration must apply the migrated override to the typed row.' );

$GLOBALS['wpdb']->locations = array(
	array( 'id' => 210001, 'country_code' => 'KZ', 'region_name' => "\u{0410}\u{043B}\u{043C}\u{0430}\u{0442}\u{0438}\u{043D}\u{0441}\u{043A}\u{0430}\u{044F}", 'place_name' => "\u{0411}\u{0435}\u{0440}\u{0435}\u{0437}\u{043E}\u{0432}\u{043A}\u{0430}", 'place_type' => "\u{043F}", 'active' => 1 ),
	array( 'id' => 210002, 'country_code' => 'KZ', 'region_name' => '', 'joined_region_name' => "\u{0416}\u{0435}\u{0442}\u{044B}\u{0441}\u{0443}", 'place_name' => '', 'settlement_name' => "\u{0422}\u{0435}\u{043A}\u{0435}\u{043B}\u{0438}", 'place_type' => "\u{0433}", 'active' => 1 ),
	array( 'id' => 210003, 'country_code' => 'KZ', 'region_name' => "\u{0410}\u{0431}\u{0430}\u{0439}\u{0441}\u{043A}\u{0430}\u{044F}", 'place_name' => '', 'city_name' => "\u{0410}\u{0431}\u{0430}\u{0439}", 'place_type' => "\u{0433}", 'active' => 1 ),
);
$batch_location_repository = new LocationRepository( $GLOBALS['wpdb'] );
$batch_correctness = $batch_location_repository->resolve_active_place_region_batch(
	array(
		array( 'source_city' => "\u{0411}\u{0435}\u{0440}\u{0451}\u{0437}\u{043E}\u{0432}\u{043A}\u{0430}", 'normalized_region' => $region_normalizer->normalize( "\u{0410}\u{043B}\u{043C}\u{0430}\u{0442}\u{0438}\u{043D}\u{0441}\u{043A}\u{0430}\u{044F}" ), 'source_place_type' => "\u{043F}", 'country_code' => 'KZ' ),
		array( 'source_city' => "\u{0422}\u{0435}\u{043A}\u{0435}\u{043B}\u{0438}", 'normalized_region' => $region_normalizer->normalize( "\u{0416}\u{0435}\u{0442}\u{044B}\u{0441}\u{0443}" ), 'source_place_type' => "\u{0433}", 'country_code' => 'KZ' ),
		array( 'source_city' => "\u{0410}\u{0431}\u{0430}\u{0439}", 'normalized_region' => $region_normalizer->normalize( "\u{0410}\u{0431}\u{0430}\u{0439}\u{0441}\u{043A}\u{0430}\u{044F}" ), 'source_place_type' => "\u{0433}", 'country_code' => 'KZ' ),
	)
);
$berezovka_key = $batch_location_repository->place_region_request_key( "\u{0411}\u{0435}\u{0440}\u{0451}\u{0437}\u{043E}\u{0432}\u{043A}\u{0430}", $region_normalizer->normalize( "\u{0410}\u{043B}\u{043C}\u{0430}\u{0442}\u{0438}\u{043D}\u{0441}\u{043A}\u{0430}\u{044F}" ), "\u{043F}", 'KZ' );
$tekeli_key = $batch_location_repository->place_region_request_key( "\u{0422}\u{0435}\u{043A}\u{0435}\u{043B}\u{0438}", $region_normalizer->normalize( "\u{0416}\u{0435}\u{0442}\u{044B}\u{0441}\u{0443}" ), "\u{0433}", 'KZ' );
$abai_key = $batch_location_repository->place_region_request_key( "\u{0410}\u{0431}\u{0430}\u{0439}", $region_normalizer->normalize( "\u{0410}\u{0431}\u{0430}\u{0439}\u{0441}\u{043A}\u{0430}\u{044F}" ), "\u{0433}", 'KZ' );
jet_assert( 210001 === (int) ( $batch_correctness[ $berezovka_key ]->matches[0]->id ?? 0 ) && 210002 === (int) ( $batch_correctness[ $tekeli_key ]->matches[0]->id ?? 0 ) && 210003 === (int) ( $batch_correctness[ $abai_key ]->matches[0]->id ?? 0 ), 'Jet place-name-first batch lookup must preserve ye/e normalization, joined regions, settlement_name and city_name matching.' );

$GLOBALS['wpdb']->locations = array(
	array( 'id' => 220001, 'country_code' => 'KZ', 'region_name' => "\u{0418}\u{043D}\u{0432}\u{0430}\u{043B}\u{0438}\u{0434}", 'place_name' => "\u{041E}\u{0432}\u{0435}\u{0440}\u{0440}\u{0430}\u{0439}\u{0434}", 'place_type' => "\u{043F}", 'active' => 1 ),
);
$GLOBALS['wpdb']->jet_overrides = array();
$GLOBALS['wpdb']->jet_cities = array();
$GLOBALS['wpdb']->location_find_by_id_calls = 0;
$GLOBALS['wpdb']->location_find_many_by_ids_calls = 0;
$invalid_override_identity = (string) $parser->parse( "city\n\u{041E}\u{0432}\u{0435}\u{0440}\u{0440}\u{0430}\u{0439}\u{0434} \u{043F}.-(\u{0418}\u{043D}\u{0432}\u{0430}\u{043B}\u{0438}\u{0434} \u{043E}\u{0431}\u{043B}.)\n" )[0]['source_identity'];
$GLOBALS['wpdb']->jet_overrides[ $invalid_override_identity ] = array( 'source_identity' => $invalid_override_identity, 'location_id' => 999999, 'country_code' => 'KZ' );
$invalid_override_result = $typed_import_service->import_csv( "city\n\u{041E}\u{0432}\u{0435}\u{0440}\u{0440}\u{0430}\u{0439}\u{0434} \u{043F}.-(\u{0418}\u{043D}\u{0432}\u{0430}\u{043B}\u{0438}\u{0434} \u{043E}\u{0431}\u{043B}.)\n" );
jet_assert( ! empty( $invalid_override_result['success'] ) && 0 === $GLOBALS['wpdb']->location_find_by_id_calls && 1 === $GLOBALS['wpdb']->location_find_many_by_ids_calls && 'exact_name_region_inferred_country' === (string) ( $GLOBALS['wpdb']->jet_cities[ $invalid_override_identity ]['match_source'] ?? '' ) && 220001 === (int) ( $GLOBALS['wpdb']->jet_cities[ $invalid_override_identity ]['location_id'] ?? 0 ), 'Jet invalid manual override locations must be batch-loaded and fall back to automatic matching without find_by_id calls.' );

$GLOBALS['wpdb']->locations = array();
$large_csv = "city\n";
for ( $i = 0; $i < 600; ++$i ) {
	$region_index = $i % 10;
	$city = 'Batch ' . $i;
	$region = 'Batch Region ' . $region_index;
	$large_csv .= $city . " \u{043F}.-(" . $region . ")\n";
	$GLOBALS['wpdb']->locations[] = array( 'id' => 300000 + $i, 'country_code' => 'KZ', 'region_name' => $region, 'place_name' => $city, 'place_type' => "\u{043F}", 'active' => 1 );
}
$GLOBALS['wpdb']->jet_cities = array();
$GLOBALS['wpdb']->jet_overrides = array();
$GLOBALS['wpdb']->location_batch_query_calls = 0;
$GLOBALS['wpdb']->location_place_name_batch_query_calls = 0;
$GLOBALS['wpdb']->location_single_lookup_calls = 0;
$GLOBALS['wpdb']->location_find_by_id_calls = 0;
$GLOBALS['wpdb']->location_find_many_by_ids_calls = 0;
$GLOBALS['wpdb']->override_batch_query_calls = 0;
$GLOBALS['wpdb']->override_single_lookup_calls = 0;
$GLOBALS['wpdb']->snapshot_bulk_upsert_calls = 0;
$GLOBALS['wpdb']->snapshot_single_replace_calls = 0;
$large_first_identity = (string) $parser->parse( "city\nBatch 0 \u{043F}.-(Batch Region 0)\n" )[0]['source_identity'];
$GLOBALS['wpdb']->jet_overrides[ $large_first_identity ] = array( 'source_identity' => $large_first_identity, 'location_id' => 300000, 'country_code' => 'KZ' );
$large_import_result = $typed_import_service->import_csv( $large_csv );
jet_assert( ! empty( $large_import_result['success'] ) && 600 === (int) $large_import_result['rows_unique'] && 600 === count( array_filter( $GLOBALS['wpdb']->jet_cities, static fn( array $row ): bool => 1 === (int) ( $row['active'] ?? 0 ) ) ), 'Jet large geography import must save all active unique rows.' );
jet_assert( 0 === $GLOBALS['wpdb']->location_single_lookup_calls && 0 === $GLOBALS['wpdb']->location_find_by_id_calls && 0 === $GLOBALS['wpdb']->override_single_lookup_calls && 0 === $GLOBALS['wpdb']->snapshot_single_replace_calls, 'Jet large geography import must not use per-row location, override, or snapshot lookups.' );
jet_assert( 1 === $GLOBALS['wpdb']->location_find_many_by_ids_calls && $GLOBALS['wpdb']->location_place_name_batch_query_calls <= 4 && $GLOBALS['wpdb']->override_batch_query_calls <= 4 && $GLOBALS['wpdb']->snapshot_bulk_upsert_calls <= 10, 'Jet large geography import query counts must be bounded by place-name chunks, not source rows or regions: ' . wp_json_encode( array( 'location_place_name_batch' => $GLOBALS['wpdb']->location_place_name_batch_query_calls, 'location_find_many_by_ids' => $GLOBALS['wpdb']->location_find_many_by_ids_calls, 'override_batch' => $GLOBALS['wpdb']->override_batch_query_calls, 'snapshot_bulk' => $GLOBALS['wpdb']->snapshot_bulk_upsert_calls ), JSON_UNESCAPED_UNICODE ) );
$region_independent_csv = "city\n";
$GLOBALS['wpdb']->locations = array();
for ( $i = 0; $i < 600; ++$i ) {
	$city = 'Region Independent ' . $i;
	$region = 'Region Independent ' . ( $i % 100 );
	$region_independent_csv .= $city . " \u{043F}.-(" . $region . ")\n";
	$GLOBALS['wpdb']->locations[] = array( 'id' => 400000 + $i, 'country_code' => 'KZ', 'region_name' => $region, 'place_name' => $city, 'place_type' => "\u{043F}", 'active' => 1 );
}
$GLOBALS['wpdb']->jet_overrides = array();
$GLOBALS['wpdb']->location_place_name_batch_query_calls = 0;
$region_independent_result = $typed_import_service->import_csv( $region_independent_csv );
jet_assert( ! empty( $region_independent_result['success'] ) && $GLOBALS['wpdb']->location_place_name_batch_query_calls <= 4, 'Jet place-name batch query count must not grow with 100 unique regions when unique place-name count is unchanged.' );
$repository_source = (string) file_get_contents( $root . '/src/Locations/Storage/LocationRepository.php' );
$batch_method_start = strpos( $repository_source, 'public function resolve_active_place_region_batch' );
$batch_method_end = strpos( $repository_source, 'public function place_region_request_key', $batch_method_start );
$batch_loader_start = strpos( $repository_source, 'private function active_location_rows_by_place_names' );
$batch_loader_end = strpos( $repository_source, 'private function index_locations_by_normalized_place', $batch_loader_start );
$batch_source = substr( $repository_source, $batch_method_start, $batch_method_end - $batch_method_start ) . substr( $repository_source, $batch_loader_start, $batch_loader_end - $batch_loader_start );
foreach ( array( 'searchable_text', 'CONCAT_WS', ' LIKE ', 'REPLACE(LOWER', 'LOWER(l.place_name)', 'LOWER(l.settlement_name)', 'LOWER(l.city_name)', 'active_place_region_candidates_for_group' ) as $forbidden_batch_sql ) {
	jet_assert( ! str_contains( $batch_source, $forbidden_batch_sql ), 'Jet location batch path must not contain non-indexable SQL fragment: ' . $forbidden_batch_sql );
}
jet_assert( str_contains( $batch_source, 'l.place_name IN' ) && str_contains( $batch_source, 'l.settlement_name IN' ) && str_contains( $batch_source, 'l.city_name IN' ), 'Jet location batch path must use exact IN predicates on place_name, settlement_name and city_name.' );
$GLOBALS['wpdb']->jet_import_lock_busy = true;
$locked_import_result = $typed_import_service->import_csv( $large_csv );
jet_assert( empty( $locked_import_result['success'] ) && 'import_already_running' === (string) ( $locked_import_result['code'] ?? '' ), 'Jet geography import must fail fast when the named import lock is already held.' );
$GLOBALS['wpdb']->jet_import_lock_busy = false;
$snapshot_before_failure = $GLOBALS['wpdb']->jet_cities;
$GLOBALS['wpdb']->jet_rollback_snapshot_after_write = true;
$failed_snapshot_result = $typed_import_service->import_csv( "city\nRollback New \u{043F}.-(Batch Region 1)\n" );
jet_assert( empty( $failed_snapshot_result['success'] ) && 'import_failed' === (string) ( $failed_snapshot_result['code'] ?? '' ) && $snapshot_before_failure === $GLOBALS['wpdb']->jet_cities && false === $GLOBALS['wpdb']->jet_import_lock_acquired, 'Jet geography import must roll back snapshot changes and release lock on snapshot failure.' );
$GLOBALS['wpdb']->jet_rollback_snapshot_after_write = false;

$GLOBALS['wpdb']->jet_cities = array();
$geo->replace_snapshot(
	array(
		array( 'source_identity' => 'token-a', 'source_city' => 'Token A', 'source_region' => 'Token Region', 'normalized_city' => 'token a', 'normalized_region' => 'token region', 'country_code' => 'KZ', 'location_id' => 1, 'match_status' => 'matched', 'match_source' => 'manual', 'active' => 1 ),
	)
);
$first_import_token = (string) ( $GLOBALS['wpdb']->jet_cities['token-a']['import_token'] ?? '' );
$geo->replace_snapshot(
	array(
		array( 'source_identity' => 'token-b', 'source_city' => 'Token B', 'source_region' => 'Token Region', 'normalized_city' => 'token b', 'normalized_region' => 'token region', 'country_code' => 'KZ', 'location_id' => 2, 'match_status' => 'matched', 'match_source' => 'manual', 'active' => 1 ),
	)
);
$second_import_token = (string) ( $GLOBALS['wpdb']->jet_cities['token-b']['import_token'] ?? '' );
jet_assert( '' !== $first_import_token && '' !== $second_import_token && $first_import_token !== $second_import_token && 0 === (int) ( $GLOBALS['wpdb']->jet_cities['token-a']['active'] ?? 1 ) && 1 === (int) ( $GLOBALS['wpdb']->jet_cities['token-b']['active'] ?? 0 ), 'Jet snapshot import_token must be unique across same-second imports and deactivate stale rows by token.' );

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
