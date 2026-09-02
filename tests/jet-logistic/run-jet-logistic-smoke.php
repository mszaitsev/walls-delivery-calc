<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
define( 'WDC_SECRET_KEY', 'jet-logistic-smoke-key' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\JetLogistic\Api\JetLogisticApiClient;
use WallsShop\WDC\Carriers\JetLogistic\Api\JetLogisticApiDiagnosticService;
use WallsShop\WDC\Carriers\JetLogistic\Api\JetLogisticApiException;
use WallsShop\WDC\Carriers\JetLogistic\Api\JetLogisticHttpClientInterface;
use WallsShop\WDC\Carriers\JetLogistic\Api\WpJetLogisticHttpClient;
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
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusEventResolver;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusMapper;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusMappingRepository;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusService;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Carriers\Runtime\JetLogisticCarrier;
use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Calendar\Services\CalendarService;
use WallsShop\WDC\Calendar\Services\DeliveryDateCalculator;
use WallsShop\WDC\Calendar\Services\DeliveryDateFormatter;
use WallsShop\WDC\Calendar\Services\TimezoneService;
use WallsShop\WDC\Checkout\Runtime\CarrierExecutionGuard;
use WallsShop\WDC\Checkout\Runtime\CheckoutLogger;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Runtime\DeliveryLeadTimeNormalizer;
use WallsShop\WDC\Checkout\Runtime\FallbackRateFactory;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationAjax;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationSearch;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\WooCommercePackageMapper;
use WallsShop\WDC\Carriers\JetLogistic\Admin\JetLogisticGeographyAdminPage;
use WallsShop\WDC\Carriers\JetLogistic\Admin\JetLogisticStatusAdminPage;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\Admin\DeliveryServicesAdminPage;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceManager;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRegistry;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Database\MigrationManager;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Core\Plugin;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\Storage\PlaceRegionMatchResult;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\Storage\RuleRepository;
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
function sanitize_key( mixed $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $value ) ?? (string) $value ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function esc_attr( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_html__( string $text, string $domain = 'default' ): string { return $text; }
function __( string $text, string $domain = 'default' ): string { return $text; }
function selected( mixed $selected, mixed $current, bool $display = true ): string { $result = (string) $selected === (string) $current ? ' selected="selected"' : ''; if ( $display ) { echo $result; } return $result; }
function submit_button( string $text = 'Save', string $type = 'primary', string $name = 'submit', bool $wrap = true ): void { echo '<button class="button button-' . esc_attr( $type ) . '" name="' . esc_attr( $name ) . '">' . esc_html( $text ) . '</button>'; }
function wp_nonce_field( string $action ): void { echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( $action ) . '">'; }
function admin_url( string $path = '' ): string { return 'https://example.test/wp-admin/' . ltrim( $path, '/\\' ); }
function dbDelta( string $sql ): void { $GLOBALS['wdc_db_delta'][] = $sql; }
function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): bool { $GLOBALS['wdc_actions'][] = array( $hook, $callback, $priority, $accepted_args ); return true; }
function add_submenu_page( mixed ...$args ): string { $GLOBALS['wdc_submenu_pages'][] = $args; return (string) ( $args[4] ?? '' ); }
function wp_remote_get( string $url, array $args = array() ): mixed { $GLOBALS['wdc_remote_get_requests'][] = array( 'url' => $url, 'args' => $args ); return array_shift( $GLOBALS['wdc_remote_get_responses'] ); }
function wp_remote_post( string $url, array $args = array() ): mixed { $GLOBALS['wdc_remote_post_requests'][] = array( 'url' => $url, 'args' => $args ); return array_shift( $GLOBALS['wdc_remote_post_responses'] ); }
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) ( is_array( $response ) ? ( $response['status'] ?? 0 ) : 0 ); }
function wp_remote_retrieve_body( mixed $response ): string { return (string) ( is_array( $response ) ? ( $response['body'] ?? '' ) : '' ); }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
final class WP_Error {
	public function __construct( private string $code = '' ) {}
	public function get_error_code(): string { return $this->code; }
}
final class JetSmokeSession {
	/** @var array<string,mixed> */
	private array $data = array();
	public function set( string $key, mixed $value ): void { $this->data[ $key ] = $value; }
	public function get( string $key, mixed $default = null ): mixed { return $this->data[ $key ] ?? $default; }
}
final class JetSmokeWooCommerce {
	public JetSmokeSession $session;
	public function __construct() { $this->session = new JetSmokeSession(); }
}
function WC(): JetSmokeWooCommerce {
	static $wc = null;
	if ( null === $wc ) {
		$wc = new JetSmokeWooCommerce();
	}

	return $wc;
}
function wc_get_logger(): object {
	return new class {
		public function log( string $level, string $message, array $context = array() ): void {
			$GLOBALS['wdc_wc_logs'][] = array( 'level' => $level, 'message' => $message, 'context' => $context );
		}
	};
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public string $last_error = '';
		public array $jet_cities = array();
		public array $jet_overrides = array();
		public array $jet_statuses = array();
		public array $jet_status_columns = array( 'active' => true, 'last_seen' => true, 'occurrence_count' => true );
		public array $jet_status_indexes = array( 'active_status' => true, 'last_seen' => true, 'normalized_external_status' => true );
		public array $locations = array();
		public array $services = array();
		public array $countries = array();
		public array $jet_update_fail_sources = array();
		public int $location_batch_query_calls = 0;
		public int $location_place_name_batch_query_calls = 0;
		public int $location_single_lookup_calls = 0;
		public int $location_find_by_id_calls = 0;
		public int $location_find_many_by_ids_calls = 0;
		public int $location_find_map_by_ids_calls = 0;
		public array $location_find_map_by_ids_last_ids = array();
		public int $override_batch_query_calls = 0;
		public int $override_single_lookup_calls = 0;
		public int $snapshot_bulk_upsert_calls = 0;
		public int $snapshot_single_replace_calls = 0;
		public int $status_mapping_insert_calls = 0;
		public int $status_mapping_update_calls = 0;
		public int $status_mapping_delete_calls = 0;
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
			if ( str_contains( $table, 'wdc_jet_logistic_status_mappings' ) ) {
				++$this->status_mapping_insert_calls;
				$key = (string) $data['normalized_external_status'];
				if ( isset( $this->jet_statuses[ $key ] ) ) {
					$this->last_error = 'Duplicate entry';
					return false;
				}
				$this->jet_statuses[ $key ] = array_merge( array( 'id' => ++$this->insert_id ), $data );
				$this->last_error = '';
				return true;
			}
			if ( str_contains( $table, 'wdc_delivery_service_countries' ) ) {
				$this->countries[] = $data;
			}
			return true;
		}
		public function update( string $table, array $data, array $where, array $formats = array(), array $where_formats = array() ): int|bool {
			if ( str_contains( $table, 'wdc_jet_logistic_status_mappings' ) ) {
				++$this->status_mapping_update_calls;
				foreach ( $this->jet_statuses as $key => $row ) {
					if ( (int) ( $row['id'] ?? 0 ) === (int) ( $where['id'] ?? 0 ) ) {
						$new_key = (string) ( $data['normalized_external_status'] ?? $key );
						if ( $new_key !== $key && isset( $this->jet_statuses[ $new_key ] ) ) {
							$this->last_error = 'Duplicate entry';
							return false;
						}
						unset( $this->jet_statuses[ $key ] );
						$this->jet_statuses[ $new_key ] = array_merge( $row, $data );
						$this->last_error = '';
						return 1;
					}
				}
				return false;
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
			if ( str_contains( $table, 'wdc_jet_logistic_status_mappings' ) ) {
				++$this->status_mapping_delete_calls;
				foreach ( $this->jet_statuses as $key => $row ) {
					if ( (int) ( $row['id'] ?? 0 ) === (int) ( $where['id'] ?? 0 ) ) {
						unset( $this->jet_statuses[ $key ] );
						return true;
					}
				}
				return false;
			}
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
			if ( str_contains( $query, 'wdc_delivery_service_countries' ) && str_starts_with( strtoupper( trim( $query ) ), 'DELETE ' ) ) {
				$this->apply_country_delete_query( $query );
			}
			if ( str_contains( $query, 'wdc_delivery_service_countries' ) && str_starts_with( strtoupper( trim( $query ) ), 'INSERT ' ) ) {
				$this->apply_country_upsert_query( $query );
			}
			if ( preg_match( '/DROP INDEX ([a-z_]+) ON/', $query, $m ) ) {
				unset( $this->jet_status_indexes[ $m[1] ] );
			}
			if ( preg_match( '/DROP COLUMN ([a-z_]+)/', $query, $m ) ) {
				unset( $this->jet_status_columns[ $m[1] ] );
			}
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
		private function apply_country_delete_query( string $query ): void {
			if ( ! preg_match( '/service_id = ([0-9]+)/', $query, $service_match ) ) {
				return;
			}
			$service_id = (int) $service_match[1];
			$countries = array();
			if ( preg_match( '/country_code IN \\(([^)]+)\\)/', $query, $country_match ) ) {
				preg_match_all( "/'([^']+)'/", $country_match[1], $matches );
				$countries = array_map( 'strval', $matches[1] ?? array() );
			}
			$this->countries = array_values(
				array_filter(
					$this->countries,
					static function ( array $row ) use ( $service_id, $countries ): bool {
						if ( (int) ( $row['service_id'] ?? 0 ) !== $service_id ) {
							return true;
						}
						if ( array() === $countries ) {
							return false;
						}
						return ! in_array( (string) ( $row['country_code'] ?? '' ), $countries, true );
					}
				)
			);
		}
		private function apply_country_upsert_query( string $query ): void {
			if ( ! preg_match_all( "/\\(([0-9]+), '([^']+)', '([^']+)'\\)/", $query, $matches, PREG_SET_ORDER ) ) {
				return;
			}
			foreach ( $matches as $match ) {
				$service_id = (int) $match[1];
				$country = (string) $match[2];
				foreach ( $this->countries as $row ) {
					if ( (int) ( $row['service_id'] ?? 0 ) === $service_id && (string) ( $row['country_code'] ?? '' ) === $country ) {
						continue 2;
					}
				}
				$this->countries[] = array(
					'id' => ++$this->insert_id,
					'service_id' => $service_id,
					'country_code' => $country,
					'created_at' => (string) $match[3],
				);
			}
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
			if ( preg_match( '/WHERE id = (\d+)/', $query, $m ) && str_contains( $query, 'wdc_jet_logistic_status_mappings' ) ) {
				foreach ( $this->jet_statuses as $row ) {
					if ( (int) ( $row['id'] ?? 0 ) === (int) $m[1] ) {
						return $row;
					}
				}
				return null;
			}
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
			if ( str_contains( $query, 'SHOW COLUMNS FROM' ) && str_contains( $query, 'wdc_jet_logistic_status_mappings' ) ) {
				return preg_match( "/LIKE '([^']+)'/", $query, $m ) && ! empty( $this->jet_status_columns[ $m[1] ] ) ? array( array( 'Field' => $m[1] ) ) : array();
			}
			if ( str_contains( $query, 'SHOW INDEX FROM' ) && str_contains( $query, 'wdc_jet_logistic_status_mappings' ) ) {
				return preg_match( "/Key_name = '([^']+)'/", $query, $m ) && ! empty( $this->jet_status_indexes[ $m[1] ] ) ? array( array( 'Key_name' => $m[1] ) ) : array();
			}
			if ( str_contains( $query, 'wdc_jet_logistic_location_overrides' ) ) {
				if ( preg_match_all( "/'([^']+)'/", $query, $matches ) ) {
					return array_values( array_intersect_key( $this->jet_overrides, array_flip( $matches[1] ) ) );
				}
				return array_values( $this->jet_overrides );
			}
			if ( str_contains( $query, 'wdc_delivery_services' ) ) {
				$rows = array_values( array_filter( $this->services, static fn( array $row ): bool => 0 === (int) ( $row['deleted'] ?? 0 ) ) );
				usort( $rows, static fn( array $a, array $b ): int => ( (int) ( $a['sort_order'] ?? 0 ) <=> (int) ( $b['sort_order'] ?? 0 ) ) ?: ( (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 ) ) );
				return $rows;
			}
			if ( str_contains( $query, 'wdc_rules' ) || str_contains( $query, 'wdc_rule_conditions' ) || str_contains( $query, 'wdc_delivery_service_settings' ) ) {
				return array();
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
$GLOBALS['wdc_remote_post_responses'] = array();
$GLOBALS['wdc_remote_post_requests'] = array();
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
jet_assert( str_contains( $delivery_admin_source, "'save_jet_settings'" ) && str_contains( $delivery_admin_source, "'check_jet_connection'" ) && str_contains( $delivery_admin_source, "'import_jet_geography_remote'" ) && str_contains( $delivery_admin_source, "'import_jet_geography_csv'" ) && str_contains( $delivery_admin_source, "'save_jet_geography_override'" ) && str_contains( $delivery_admin_source, "'create_jet_status_mapping'" ) && str_contains( $delivery_admin_source, "'update_jet_status_mapping'" ) && str_contains( $delivery_admin_source, "'delete_jet_status_mapping'" ) && str_contains( $delivery_admin_source, "'check_jet_tracking'" ) && str_contains( $delivery_admin_source, "check_admin_referer( 'wdc_delivery_services' )" ) && str_contains( $delivery_admin_source, 'current_user_can( AdminMenu::CAPABILITY )' ), 'Jet POST actions must be handled by the shared delivery services action pipeline.' );
jet_assert( str_contains( $delivery_admin_source, 'jet_logistic_redirect_url( $action, $tab )' ) && str_contains( $delivery_admin_source, "'create_jet_status_mapping', 'update_jet_status_mapping', 'delete_jet_status_mapping', 'check_jet_tracking' => 'jet_statuses'" ) && str_contains( $delivery_admin_source, "'save_jet_geography_override' === \$action" ), 'Jet POST actions must redirect back to their embedded tabs and preserve Jet geography pagination state for manual overrides.' );
jet_assert( str_contains( $geography_admin_source, "JetLogisticCitiesCsvClient::DEFAULT_URL" ) && ! str_contains( $geography_admin_source, "\$_POST['url'" ) && ! str_contains( $geography_admin_source, 'wp_remote_get' ), 'Jet remote geography import must use the fixed client URL and not read arbitrary POST URLs.' );
jet_assert( str_contains( $delivery_admin_source, 'set_transient( $this->jet_admin_notice_key()' ) && str_contains( $delivery_admin_source, 'get_transient( $key )' ) && str_contains( $delivery_admin_source, 'delete_transient( $key )' ), 'Jet admin actions must use one-shot flash notices.' );
jet_assert( ! str_contains( $plugin_source, 'JetLogisticGeographyAdminPage::class )->register()' ) && ! str_contains( $plugin_source, 'JetLogisticStatusAdminPage::class )->register()' ), 'Plugin hooks must not register standalone Jet admin pages.' );
jet_assert( str_contains( $delivery_admin_source, "page=' . self::MENU_SLUG . '&service=' . rawurlencode( \$service->service_key ) . '&tab=' . rawurlencode( \$tab_key )" ) && str_contains( $delivery_admin_source, "http_build_query( array( 'page' => self::MENU_SLUG, 'service' => \$service_key, 'tab' => \$tab )" ), 'Jet tab URLs must use the delivery services service-tab URL helpers.' );
jet_assert( str_contains( $plugin_source, 'use WallsShop\\WDC\\Carriers\\JetLogistic\\Geography\\JetLogisticCitiesCsvClient;' ), 'Plugin DI must import JetLogisticCitiesCsvClient from the Jet geography namespace.' );
$render_embedded_start = strpos( $geography_admin_source, 'public function render_embedded' );
$render_notice_start = strpos( $geography_admin_source, 'private function render_notice', $render_embedded_start );
$render_embedded_source = false !== $render_embedded_start && false !== $render_notice_start ? substr( $geography_admin_source, $render_embedded_start, $render_notice_start - $render_embedded_start ) : '';
jet_assert( str_contains( $render_embedded_source, 'admin_page( $pagination_request' ) && str_contains( $render_embedded_source, '<th class="wdc-row-number">№</th>' ) && str_contains( $render_embedded_source, 'Сопоставленный населённый пункт' ) && str_contains( $render_embedded_source, '( ( $page - 1 ) * $per_page ) + $index + 1' ), 'Jet geography admin table must use paginated rows, continuous row numbers and matched location display names.' );
jet_assert( str_contains( $geography_admin_source, 'render_pagination' ) && str_contains( $geography_admin_source, 'jet_page' ) && str_contains( $geography_admin_source, 'jet_per_page' ) && str_contains( $geography_admin_source, 'location_display_names_for_rows' ) && str_contains( $geography_admin_source, 'find_map_by_ids( $location_ids )' ) && ! str_contains( $render_embedded_source, 'find_by_id(' ), 'Jet geography admin table must render pagination, preserve page state, batch-load display names and avoid per-row find_by_id calls.' );
jet_assert( str_contains( (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Geography/JetLogisticGeographyRepository.php' ), 'origin_by_source_identity' ) && str_contains( (string) file_get_contents( $root . '/src/Carriers/Runtime/JetLogisticCarrier.php' ), 'origin_by_source_identity' ), 'Jet origin resolution must use a focused RU origin contract instead of active destination rows.' );
jet_assert( str_contains( $plugin_source, 'JetLogisticStatusEventResolver::class' ) && str_contains( $plugin_source, 'JetLogisticQuoteResponseParser::class' ) && str_contains( (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Api/JetLogisticApiDiagnosticService.php' ), 'quote_parser->parse' ), 'Jet diagnostics must reuse production quote parser and shared status event resolver.' );
jet_assert( ! str_contains( (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Geography/JetLogisticGeographyRepository.php' ), 'return (bool) $this->wpdb->update' ), 'Jet manual override snapshot update must not cast wpdb update result to bool.' );
foreach ( array( 'download failed', 'is empty', 'response is too large', 'returned HTML', 'upload failed', 'has no rows', 'operation completed', 'operation failed', 'component is unavailable', 'Unknown Jet Logistic admin action' ) as $english_message ) {
	jet_assert( ! str_contains( $geography_admin_source . $status_admin_source . $delivery_admin_source . (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Geography/JetLogisticCitiesCsvClient.php' ) . (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Geography/JetLogisticGeographyImportService.php' ), $english_message ), 'Jet admin user-facing messages must be Russian: ' . $english_message );
}
foreach ( array( 'География Jet Logistic успешно импортирована.', 'Ручное сопоставление Jet Logistic применено.', 'Настройки Jet Logistic сохранены.', 'Сопоставление статуса Jet Logistic сохранено.', 'Не удалось скачать cities.csv Jet Logistic', 'Строк прочитано', 'Уникальных строк', 'Дубликатов', 'Сопоставлено', 'Требует уточнения', 'Не сопоставлено', 'Пропущено', 'Некорректных строк' ) as $russian_message ) {
	jet_assert( str_contains( $geography_admin_source . $status_admin_source . $delivery_admin_source . (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Geography/JetLogisticCitiesCsvClient.php' ) . (string) file_get_contents( $root . '/src/Carriers/JetLogistic/Geography/JetLogisticGeographyImportService.php' ), $russian_message ), 'Jet admin must expose Russian message or label: ' . $russian_message );
}

$plugin = new Plugin( new PluginEnvironment( $root . '/walls-delivery-calc.php', $root, 'https://example.test/wp-content/plugins/walls-delivery-calc/', '0.129.16' ) );
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
jet_assert( 3 === count( $GLOBALS['wdc_db_delta'] ) && 0 === count( $GLOBALS['wpdb']->jet_statuses ), 'Jet migration 0045 must create status schema without restoring default mappings on schema creation.' );

$repository_root = dirname( __DIR__, 2 ) . '/src/Carriers/JetLogistic';
jet_assert( str_contains( (string) file_get_contents( $repository_root . '/Geography/JetLogisticGeographyRepository.php' ), '\\dbDelta(' ), 'Jet geography repository must call global dbDelta.' );
jet_assert( str_contains( (string) file_get_contents( $repository_root . '/Geography/JetLogisticGeographyOverrideRepository.php' ), '\\dbDelta(' ), 'Jet geography override repository must call global dbDelta.' );
jet_assert( str_contains( (string) file_get_contents( $repository_root . '/Status/JetLogisticStatusMappingRepository.php' ), '\\dbDelta(' ), 'Jet status mapping repository must call global dbDelta.' );
$migration_0046_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/database/migrations/0046_add_import_token_to_jet_logistic_cities.php' );
$migration_0046 = require dirname( __DIR__, 2 ) . '/database/migrations/0046_add_import_token_to_jet_logistic_cities.php';
jet_assert( is_callable( $migration_0046 ), 'Jet migration 0046 must return a callable.' );
jet_assert( str_contains( $migration_0046_source, 'import_token' ), 'Jet migration 0046 must add import_token column.' );
jet_assert( str_contains( $migration_0046_source, 'KEY import_token' ), 'Jet migration 0046 must add import_token index.' );
$migration_0047_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/database/migrations/0047_simplify_jet_logistic_status_mappings.php' );
$migration_0047 = require dirname( __DIR__, 2 ) . '/database/migrations/0047_simplify_jet_logistic_status_mappings.php';
jet_assert( is_callable( $migration_0047 ), 'Jet migration 0047 must return a callable.' );
$GLOBALS['wpdb']->jet_statuses = array(
	'доставка груза на склад' => array( 'id' => 9001, 'external_status' => 'Доставка груза на склад', 'normalized_external_status' => 'доставка груза на склад', 'universal_status' => DeliveryStatus::READY_FOR_PICKUP, 'active' => 1, 'last_seen' => '2026-07-28 10:00:00', 'occurrence_count' => 5 ),
);
$migration_0047();
jet_assert( empty( $GLOBALS['wpdb']->jet_statuses['доставка груза на склад'] ) && ! empty( $GLOBALS['wpdb']->jet_statuses['доставка груза на склад выдачи'] ) && ! empty( $GLOBALS['wpdb']->jet_statuses['груз выдан'] ), 'Jet migration 0047 must delete broad status default and insert precise defaults.' );
jet_assert( empty( $GLOBALS['wpdb']->jet_status_columns['active'] ) && empty( $GLOBALS['wpdb']->jet_status_columns['last_seen'] ) && empty( $GLOBALS['wpdb']->jet_status_columns['occurrence_count'] ) && empty( $GLOBALS['wpdb']->jet_status_indexes['active_status'] ) && empty( $GLOBALS['wpdb']->jet_status_indexes['last_seen'] ), 'Jet migration 0047 must drop obsolete active/last_seen/occurrence_count columns and indexes idempotently.' );
jet_assert( str_contains( $migration_0047_source, '0047' ) || str_contains( $migration_0047_source, 'active_status' ), 'Jet migration 0047 source must be present for migration registration by filename.' );

$migration_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-jet-migration-' . str_replace( '.', '', uniqid( '', true ) );
mkdir( $migration_dir );
$migration_file = $migration_dir . DIRECTORY_SEPARATOR . '0001_jet_fake_migration.php';
$GLOBALS['wdc_options'] = array();
file_put_contents( $migration_file, "<?php\nreturn static function (): void { throw new RuntimeException('jet fake failure'); };\n" );
$failed = false;
try {
	( new MigrationManager( '0.129.16-test', $migration_dir ) )->run();
} catch ( RuntimeException ) {
	$failed = true;
}
jet_assert( $failed && ! in_array( '0001_jet_fake_migration.php', (array) get_option( 'wdc_applied_migrations', array() ), true ), 'Failed migration callback must not be marked as applied.' );
file_put_contents( $migration_file, "<?php\nreturn static function (): void { update_option('wdc_jet_fake_migration_runs', (int) get_option('wdc_jet_fake_migration_runs', 0) + 1, false); };\n" );
( new MigrationManager( '0.129.16-test', $migration_dir ) )->run();
jet_assert( in_array( '0001_jet_fake_migration.php', (array) get_option( 'wdc_applied_migrations', array() ), true ) && '0.129.16-test' === get_option( 'wdc_db_version', '' ), 'Successful migration callback must be marked as applied and update db version.' );
( new MigrationManager( '0.129.16-test', $migration_dir ) )->run();
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
$credentials->save_access_token( 'jet-test-token' );
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
$credentials->save_access_token( 'jet-test-token' );
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

$pagination_cities_backup = $GLOBALS['wpdb']->jet_cities;
$pagination_locations_backup = $GLOBALS['wpdb']->locations;
$pagination_get_backup = $_GET;
$GLOBALS['wpdb']->jet_cities = array();
$GLOBALS['wpdb']->locations = array();
for ( $i = 1; $i <= 250; ++$i ) {
	$identity = sprintf( 'page-%03d', $i );
	$GLOBALS['wpdb']->jet_cities[ $identity ] = array(
		'id' => $i,
		'source_identity' => $identity,
		'source_city' => sprintf( 'City %03d', $i ),
		'source_region' => 'Page Region',
		'normalized_city' => sprintf( 'city %03d', $i ),
		'normalized_region' => 'page region',
		'country_code' => 'KZ',
		'location_id' => $i,
		'match_status' => 0 === $i % 5 ? 'ambiguous' : 'matched',
		'match_source' => 'manual_override',
		'active' => 1,
	);
	$GLOBALS['wpdb']->locations[] = array( 'id' => $i, 'country_code' => 'KZ', 'region_name' => 'Page Region', 'place_name' => sprintf( 'City %03d', $i ), 'display_name' => sprintf( 'Display %03d', $i ), 'active' => 1 );
}
$admin_page_1 = $geo->admin_page( 1, 100 );
$admin_page_2 = $geo->admin_page( 2, 100 );
$admin_page_3 = $geo->admin_page( 3, 100 );
$admin_page_invalid = $geo->admin_page( 99, 100 );
jet_assert( 100 === count( $admin_page_1['items'] ) && 250 === (int) $admin_page_1['total'] && 1 === (int) $admin_page_1['page'] && 100 === (int) $admin_page_1['per_page'] && 3 === (int) $admin_page_1['total_pages'], 'Jet geography admin_page page 1 must return the first 100 of 250 rows.' );
jet_assert( 100 === count( $admin_page_2['items'] ) && 'page-101' === (string) $admin_page_2['items'][0]['source_identity'] && 101 === ( ( (int) $admin_page_2['page'] - 1 ) * (int) $admin_page_2['per_page'] ) + 1, 'Jet geography admin_page page 2 must start at row number 101.' );
jet_assert( 50 === count( $admin_page_3['items'] ) && 'page-250' === (string) $admin_page_3['items'][49]['source_identity'] && 250 === ( ( (int) $admin_page_3['page'] - 1 ) * (int) $admin_page_3['per_page'] ) + 50, 'Jet geography admin_page page 3 must end at row number 250.' );
jet_assert( 3 === (int) $admin_page_invalid['page'] && 50 === count( $admin_page_invalid['items'] ), 'Jet geography admin_page must clamp invalid high pages to the last page.' );
jet_assert( 10 === (int) $geo->admin_page( 1, 25 )['total_pages'] && 5 === (int) $geo->admin_page( 1, 50 )['total_pages'] && 3 === (int) $geo->admin_page( 1, 100 )['total_pages'] && 2 === (int) $geo->admin_page( 1, 200 )['total_pages'] && 100 === (int) $geo->admin_page( 1, 999 )['per_page'], 'Jet geography admin_page must support only 25, 50, 100 and 200 rows per page and normalize invalid sizes to 100.' );

$_GET = array( 'jet_page' => '2', 'jet_per_page' => '100' );
$GLOBALS['wpdb']->location_find_by_id_calls = 0;
$GLOBALS['wpdb']->location_find_map_by_ids_calls = 0;
$GLOBALS['wpdb']->location_find_map_by_ids_last_ids = array();
ob_start();
$jet_geo_admin->render_embedded( new DeliveryService( 501, JetLogisticSettings::SERVICE_KEY, JetLogisticSettings::CARRIER_KEY, DeliveryService::TYPE_API, 'Jet Logistic' ), array() );
$admin_html = (string) ob_get_clean();
$decoded_admin_html = html_entity_decode( $admin_html, ENT_QUOTES, 'UTF-8' );
jet_assert( str_contains( $decoded_admin_html, '<td class="wdc-row-number">101</td>' ) && str_contains( $decoded_admin_html, '<td class="wdc-row-number">200</td>' ) && ! str_contains( $decoded_admin_html, '<td class="wdc-row-number">1</td>' ), 'Jet geography admin HTML page 2 must show continuous row numbers 101-200 instead of restarting at 1.' );
jet_assert( str_contains( $decoded_admin_html, 'check_jet_connection' ) && str_contains( $decoded_admin_html, 'Проверить подключение' ), 'Jet geography settings tab must expose safe connection diagnostics.' );
jet_assert( str_contains( $decoded_admin_html, 'Всего: 250' ) && str_contains( $decoded_admin_html, 'Страница 2 из 3' ) && str_contains( $decoded_admin_html, 'Показаны: 101' ) && str_contains( $decoded_admin_html, '200' ), 'Jet geography admin HTML must show total, visible range and current page metadata.' );
jet_assert( str_contains( $decoded_admin_html, 'jet_page=1' ) && str_contains( $decoded_admin_html, 'jet_page=3' ) && str_contains( $decoded_admin_html, 'jet_per_page=100' ) && str_contains( $decoded_admin_html, 'page=wdc-delivery-services' ) && str_contains( $decoded_admin_html, 'service=jet_logistic' ), 'Jet geography pagination links must preserve service page, Jet service and per-page state.' );
jet_assert( str_contains( $decoded_admin_html, 'Display 101' ) && 1 === $GLOBALS['wpdb']->location_find_map_by_ids_calls && 0 === $GLOBALS['wpdb']->location_find_by_id_calls && 101 === min( $GLOBALS['wpdb']->location_find_map_by_ids_last_ids ) && 200 === max( $GLOBALS['wpdb']->location_find_map_by_ids_last_ids ), 'Jet geography admin table must batch-load display names only for the current page IDs.' );
$_POST = array( 'jet_page' => '3', 'jet_per_page' => '50' );
$redirect_reflection = new ReflectionMethod( DeliveryServicesAdminPage::class, 'jet_logistic_redirect_url' );
$redirect_reflection->setAccessible( true );
$delivery_admin_instance = ( new ReflectionClass( DeliveryServicesAdminPage::class ) )->newInstanceWithoutConstructor();
$override_redirect_url = $redirect_reflection->invoke( $delivery_admin_instance, 'save_jet_geography_override', 'jet_geography' );
$import_redirect_url = $redirect_reflection->invoke( $delivery_admin_instance, 'import_jet_geography_csv', 'jet_geography' );
jet_assert( str_contains( $override_redirect_url, 'jet_page=3' ) && str_contains( $override_redirect_url, 'jet_per_page=50' ) && str_contains( $import_redirect_url, 'jet_page=1' ) && str_contains( $import_redirect_url, 'jet_per_page=50' ), 'Jet geography redirects must preserve current page for manual overrides and reset imports to page 1 while keeping per-page size.' );
$_POST = array();
$_GET = $pagination_get_backup;
$GLOBALS['wpdb']->jet_cities = $pagination_cities_backup;
$GLOBALS['wpdb']->locations = $pagination_locations_backup;

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
	array( 'id' => 184506, 'country_code' => 'KZ', 'region_name' => 'Акмолинская', 'place_name' => 'Атбасар', 'place_type' => 'п', 'display_name' => 'Акмолинская обл., п Атбасар', 'active' => 1 ),
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

$GLOBALS['wpdb']->locations = array(
	array( 'id' => 1, 'country_code' => 'RU', 'region_name' => 'Новосибирская', 'place_name' => 'Новосибирск', 'place_type' => 'г', 'display_name' => 'Новосибирская обл., г Новосибирск', 'active' => 1 ),
	array( 'id' => 10, 'country_code' => 'KZ', 'region_name' => 'Астана', 'place_name' => 'Astana', 'place_type' => 'г', 'display_name' => 'Астана, г Astana', 'active' => 1 ),
	array( 'id' => 77, 'country_code' => 'KZ', 'region_name' => 'Manual Region', 'city_name' => 'Manual Target', 'place_name' => 'Manual Target', 'display_name' => 'Manual Region, Manual Target', 'active' => 1 ),
	array( 'id' => 162695, 'country_code' => 'KZ', 'region_name' => 'Алматинская', 'place_name' => 'Алматы', 'place_type' => 'г', 'display_name' => 'Алматинская обл., г Алматы', 'active' => 1 ),
	array( 'id' => 184506, 'country_code' => 'KZ', 'region_name' => 'Акмолинская', 'place_name' => 'Атбасар', 'place_type' => 'п', 'display_name' => 'Акмолинская обл., п Атбасар', 'active' => 1 ),
);

$geo->replace_snapshot(
	array(
		array( 'source_identity' => 'origin', 'source_city' => 'Новосибирск', 'source_region' => 'Новосибирская Область', 'normalized_city' => 'новосибирск', 'normalized_region' => 'новосибирская', 'country_code' => 'RU', 'location_id' => 1, 'match_status' => 'ignored', 'match_source' => 'country_ru_inferred_by_region', 'active' => 0 ),
		array( 'source_identity' => 'dest', 'source_city' => 'Astana', 'source_region' => '', 'normalized_city' => 'astana', 'normalized_region' => '', 'country_code' => 'KZ', 'location_id' => 10, 'match_status' => 'matched', 'match_source' => 'manual', 'active' => 1 ),
		array( 'source_identity' => 'almaty', 'source_city' => 'Алматы', 'source_region' => 'Алма-Ата', 'normalized_city' => 'алматы', 'normalized_region' => 'алматинская', 'country_code' => 'KZ', 'location_id' => 162695, 'match_status' => 'matched', 'match_source' => 'exact_name_region_inferred_country', 'active' => 1 ),
		array( 'source_identity' => 'atbasar', 'source_city' => 'Атбасар', 'source_region' => 'Акмолинская область', 'normalized_city' => 'атбасар', 'normalized_region' => 'акмолинская', 'country_code' => 'KZ', 'location_id' => 184506, 'match_status' => 'matched', 'match_source' => 'exact_name_region_inferred_country', 'active' => 1 ),
		array( 'source_identity' => 'manual-target', 'source_city' => 'Manual Target', 'source_region' => '', 'normalized_city' => 'manual target', 'normalized_region' => '', 'country_code' => 'KZ', 'location_id' => 77, 'match_status' => 'matched', 'match_source' => 'manual_override', 'active' => 1 ),
	)
);
jet_assert( 1 === count( $geo->origin_options() ) && 'Новосибирск' === (string) ( $geo->origin_options()[0]['source_city'] ?? '' ) && array() !== $geo->origin_by_source_identity( 'origin' ), 'Jet RU ignored geography row must be available as a configured origin.' );
jet_assert( array() === $geo->active_for_location( 1 ), 'Jet RU origin row must not become an active RU destination mapping.' );
jet_assert( 'алматы опт' === $normalizer->normalize( 'Алматы ОПТ' ) && 'алматы' === $normalizer->normalize_api_city( 'Алматы ОПТ' ), 'Jet API city normalization must strip only terminal OPT suffix without changing persisted geography normalization.' );
jet_assert( $normalizer->api_city_matches( 'Алматы', 'Алматы' ) && $normalizer->api_city_matches( 'Алматы', 'Алматы ОПТ' ) && $normalizer->api_city_matches( 'Алматы', 'Алматы 2 нижний город (аэропорт, СВХ) ОПТ' ) && $normalizer->api_city_matches( 'Минск', 'Минск ОПТ' ) && $normalizer->api_city_matches( 'Атбасар', 'Атбасар район 1 ОПТ' ), 'Jet API city matching must allow exact and city-prefix provider-zone responses with a safe boundary.' );
jet_assert( ! $normalizer->api_city_matches( 'Алматы', 'Алматинская область' ) && ! $normalizer->api_city_matches( 'Алматы', 'Алматытау' ) && ! $normalizer->api_city_matches( 'Астана', 'Астанай' ) && ! $normalizer->api_city_matches( 'Алматы', 'Астана ОПТ' ), 'Jet API city matching must reject false prefixes and real destination mismatches.' );
$checkout_search = new CheckoutLocationSearch( new LocationSearchService( new LocationRepository( $GLOBALS['wpdb'] ) ) );
$atbasar_search = $checkout_search->search_for_picker( 'поселок Атбасар', 100, 10, '', 'KZ' );
$atbasar_search_ids = array_map( static fn( object $location ): int => (int) $location->id, $atbasar_search['items'] ?? array() );
$atbasar_ajax = ( new CheckoutLocationAjax( $checkout_search, new SettingsRepository() ) )->payload( 'поселок Атбасар', '', 'KZ' );
$atbasar_ajax_ids = array();
foreach ( $atbasar_ajax['groups'] ?? array() as $group ) {
	foreach ( $group['items'] ?? array() as $item ) {
		$atbasar_ajax_ids[] = (int) ( $item['id'] ?? 0 );
	}
}
jet_assert( in_array( 184506, $atbasar_search_ids, true ) && in_array( 184506, $atbasar_ajax_ids, true ), 'Checkout location picker must return selectable KZ поселок Атбасар settlement with canonical location_id=184506.' );
$atbasar_resolved = $checkout_search->resolve_checkout_fields( '', 'поселок Атбасар', 'KZ' );
jet_assert( 'resolved' === (string) ( $atbasar_resolved['status'] ?? '' ) && $atbasar_resolved['location'] instanceof \WallsShop\WDC\Locations\ValueObjects\Location && 184506 === (int) $atbasar_resolved['location']->id, 'Checkout resolver must resolve explicit поселок Атбасар to canonical location_id=184506 before Jet carrier runtime.' );
$checkout_session = new CheckoutSessionManager();
$checkout_session->save_selected_city( array( 'id' => 184506, 'country_code' => 'KZ', 'city_name' => 'Атбасар', 'settlement_name' => 'Атбасар', 'place_name' => 'Атбасар', 'place_type' => 'п', 'place_level' => 5, 'region_name' => 'Акмолинская', 'display_name' => 'Акмолинская обл., п Атбасар', 'active' => true ) );
$checkout_session->save_city_context( array( 'location_id' => '184506', 'country_code' => 'KZ', 'city_name' => 'Атбасар', 'settlement_name' => 'Атбасар', 'region_name' => 'Акмолинская', 'display_name' => 'Акмолинская обл., п Атбасар', 'source' => 'local_db' ) );
$GLOBALS['wdc_wc_logs'] = array();
$atbasar_request = ( new WooCommercePackageMapper( null, $checkout_session, null, new LocationRepository( $GLOBALS['wpdb'] ), null, new CheckoutLogger( new Logger() ) ) )->map(
	array(
		'destination' => array( 'country' => 'KZ', 'city' => 'Атбасар' ),
		'contents_cost' => 1000,
		'contents_weight' => 1,
		'contents' => array(),
	)
);
$checkout_context_log = array_values( array_filter( $GLOBALS['wdc_wc_logs'], static fn( array $log ): bool => 'debug' === (string) ( $log['level'] ?? '' ) && 'Checkout quote request location context resolved.' === (string) ( $log['message'] ?? '' ) ) )[0] ?? array();
jet_assert( 184506 === (int) ( $atbasar_request->customer_context['selected_location_id'] ?? 0 ) && '184506' === (string) ( $checkout_context_log['context']['resolved_location_id'] ?? '' ) && 'п' === (string) ( $checkout_context_log['context']['resolved_place_type'] ?? '' ), 'WooCommercePackageMapper must carry selected KZ Атбасар location_id into QuoteRequest customer_context and log safe generic location diagnostics.' );
jet_assert( 'Атбасар' === (string) ( $geo->active_for_location( 184506 )['source_city'] ?? '' ), 'Jet geography repository must resolve mapped Атбасар destination by canonical location_id=184506.' );

$http = new JetFakeHttp(
	array(
		array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'price_zabor' => 999, 'price_terminal' => 1000, 'price_delivery' => 300, 'price_dop' => 50, 'city_from' => 'Новосибирск', 'city_terminal_from' => 'Новосибирск', 'city_to' => 'Astana', 'city_terminal_to' => 'Karaganda', 'day_from' => 3, 'day_to' => 5 ) ), JSON_UNESCAPED_UNICODE ) ),
	)
);
$api = new JetLogisticApiClient( $http, $settings, $credentials );
$GLOBALS['wdc_wc_logs'] = array();
$carrier = new JetLogisticCarrier( $settings, $api, new JetLogisticQuoteRequestBuilder( $credentials ), new JetLogisticQuoteResponseParser(), $geo, $normalizer, new Logger() );
jet_assert( ! $carrier->supports_country( 'RU' ), 'Jet carrier must keep RU disabled as a destination country.' );
$package = Package::from_items( array( new PackageItem( 'A', 'Товар', 1, Money::from_rubles( 21000 ), Money::from_rubles( 19500 ), 2000, 100, 50, 40 ) ), 0, Money::from_rubles( 19500 ), Money::from_rubles( 19500 ) );
$quote = $carrier->quote( new QuoteRequest( 'KZ', new Address( country_code: 'KZ', city: 'Астана' ), $package, 'card', Money::from_rubles( 19500 ), '2026-07-28', array( 'location_id' => 10 ) ) );
jet_assert( $quote->success && 2 === count( $quote->rates ) && 1 === count( $http->requests ), 'Jet quote must use one API call and return two rates.' );
jet_assert( 105000 === $quote->rates[0]->price->get_kopecks() && 135000 === $quote->rates[1]->price->get_kopecks() && 1000 === (int) ( $quote->rates[0]->meta['jet_price_terminal_rub'] ?? 0 ) && 300 === (int) ( $quote->rates[0]->meta['jet_price_delivery_rub'] ?? 0 ) && 50 === (int) ( $quote->rates[0]->meta['jet_price_dop_rub'] ?? 0 ) && 999 === (int) ( $quote->rates[0]->meta['jet_price_zabor_rub'] ?? 0 ), 'Jet rates must expose price components and keep current pickup=terminal+dop and courier=terminal+delivery+dop formula.' );
$quote_debug = array_values( array_filter( $GLOBALS['wdc_wc_logs'], static fn( array $log ): bool => 'debug' === (string) ( $log['level'] ?? '' ) && 'Jet Logistic quote calculated.' === (string) ( $log['message'] ?? '' ) ) )[0] ?? array();
jet_assert( '1000' === (string) ( $quote_debug['context']['response_price_terminal'] ?? '' ) && '300' === (string) ( $quote_debug['context']['response_price_delivery'] ?? '' ) && '50' === (string) ( $quote_debug['context']['response_price_dop'] ?? '' ) && '1050' === (string) ( $quote_debug['context']['calculated_pickup_rub'] ?? '' ) && '1350' === (string) ( $quote_debug['context']['calculated_courier_rub'] ?? '' ) && ! str_contains( wp_json_encode( $quote_debug, JSON_UNESCAPED_UNICODE ) ?: '', 'jet-test-token' ), 'Jet successful quote diagnostics must log safe request/response price components without token or raw response.' );
jet_assert( 'Новосибирск' === (string) $http->requests[0]['payload']['cityfrom'], 'Jet quote must send configured RU Jet source city as cityfrom.' );
jet_assert( DeliveryType::PICKUP === $quote->rates[0]->delivery_type && false === $quote->rates[0]->requires_pickup_point, 'Jet pickup rate must not require a concrete pickup point.' );
jet_assert( str_contains( $quote->rates[0]->title, 'Karaganda' ) && str_contains( $quote->rates[0]->comments[0] ?? '', 'Karaganda' ), 'Jet non-local terminal city must be in pickup title and comment.' );
jet_assert( '[redacted]' === (string) ( $quote->raw_reference['jet_request']['access_token'] ?? '' ) && 'jet-test-token' === (string) $http->requests[0]['payload']['access_token'], 'Jet token must be sent to API but redacted from diagnostics.' );
jet_assert( ! str_contains( (string) $http->requests[0]['url'], 'jet-test-token' ) && ! str_contains( (string) $http->requests[0]['url'], 'access_token' ), 'Jet access_token must not be sent in the API URL.' );
jet_assert( 19500 === (int) $http->requests[0]['payload']['cost'] && 0 === (int) $http->requests[0]['payload']['dops']['D_SDOC'], 'Jet cost and D_SDOC must use discounted package goods cost below threshold.' );

$almaty_http = new JetFakeHttp(
	array(
		array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'price_zabor' => '0', 'price_terminal' => '1200', 'price_delivery' => '700', 'price_dop' => '0', 'city_from' => 'Новосибирск', 'city_terminal_from' => 'Новосибирск', 'city_terminal_to' => 'Алматы', 'city_to' => 'Алматы 2 нижний город (аэропорт, СВХ) ОПТ', 'day_from' => '3', 'day_to' => '5', 'valuta' => 1, 'valuta_name' => 'руб' ) ), JSON_UNESCAPED_UNICODE ) ),
	)
);
$almaty_carrier = new JetLogisticCarrier( $settings, new JetLogisticApiClient( $almaty_http, $settings, $credentials ), new JetLogisticQuoteRequestBuilder( $credentials ), new JetLogisticQuoteResponseParser(), $geo, $normalizer, new Logger() );
$almaty_quote = $almaty_carrier->quote( new QuoteRequest( 'KZ', new Address( country_code: 'KZ', city: 'Алматы' ), $package, 'card', Money::from_rubles( 19500 ), '2026-07-28', array( 'selected_location_id' => 162695 ) ) );
jet_assert( $almaty_quote->success && 2 === count( $almaty_quote->rates ) && 1 === count( $almaty_http->requests ) && 'Алматы' === (string) $almaty_http->requests[0]['payload']['cityto'] && 'Алматы 2 нижний город (аэропорт, СВХ) ОПТ' === (string) ( $almaty_quote->rates[0]->meta['jet_city_to'] ?? '' ) && 'yes' === (string) ( $almaty_quote->rates[0]->meta['jet_local_terminal'] ?? '' ) && 'Джет Логистик до склада выдачи' === $almaty_quote->rates[0]->title, 'Jet quote must accept production Алматы provider-zone city_to and treat terminal Алматы as local.' );

$remote_terminal_http = new JetFakeHttp(
	array(
		array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'price_zabor' => '0', 'price_terminal' => '1300', 'price_delivery' => '800', 'price_dop' => '0', 'city_from' => 'Новосибирск', 'city_terminal_from' => 'Новосибирск', 'city_terminal_to' => 'Астана', 'city_to' => 'Атбасар 1 район ОПТ', 'day_from' => '3', 'day_to' => '5', 'valuta' => 1, 'valuta_name' => 'руб' ) ), JSON_UNESCAPED_UNICODE ) ),
	)
);
$remote_terminal_quote = ( new JetLogisticCarrier( $settings, new JetLogisticApiClient( $remote_terminal_http, $settings, $credentials ), new JetLogisticQuoteRequestBuilder( $credentials ), new JetLogisticQuoteResponseParser(), $geo, $normalizer, new Logger() ) )->quote( new QuoteRequest( 'KZ', new Address( country_code: 'KZ', city: 'Атбасар' ), $package, 'card', Money::from_rubles( 19500 ), '2026-07-28', array( 'selected_location_id' => 184506 ) ) );
jet_assert( $remote_terminal_quote->success && 'no' === (string) ( $remote_terminal_quote->rates[0]->meta['jet_local_terminal'] ?? '' ) && str_contains( $remote_terminal_quote->rates[0]->title, 'Астана' ) && str_contains( $remote_terminal_quote->rates[0]->comments[0] ?? '', 'Астана' ), 'Jet quote must accept Атбасар provider-zone destination while keeping remote terminal Астана in pickup title.' );

$GLOBALS['wdc_wc_logs'] = array();
$missing_location_http = new JetFakeHttp( array() );
$missing_location_quote = ( new JetLogisticCarrier( $settings, new JetLogisticApiClient( $missing_location_http, $settings, $credentials ), new JetLogisticQuoteRequestBuilder( $credentials ), new JetLogisticQuoteResponseParser(), $geo, $normalizer, new Logger() ) )->quote( new QuoteRequest( 'KZ', new Address( country_code: 'KZ', city: 'Алматы' ), $package, 'card', Money::from_rubles( 19500 ), '2026-07-28', array() ) );
$missing_location_debug = array_values( array_filter( $GLOBALS['wdc_wc_logs'], static fn( array $log ): bool => 'debug' === (string) ( $log['level'] ?? '' ) && 'Jet Logistic quote precondition is incomplete.' === (string) ( $log['message'] ?? '' ) ) )[0] ?? array();
jet_assert( ! $missing_location_quote->success && 'jet_destination_location_missing' === $missing_location_quote->error_code && 0 === count( $missing_location_http->requests ) && array() === array_filter( $GLOBALS['wdc_wc_logs'], static fn( array $log ): bool => 'warning' === (string) $log['level'] ) && 'KZ' === (string) ( $missing_location_debug['context']['country_code'] ?? '' ) && 'Алматы' === (string) ( $missing_location_debug['context']['destination_text'] ?? '' ) && '' === (string) ( $missing_location_debug['context']['selected_location_id'] ?? '' ), 'Jet transient checkout without canonical location_id must not call API, log a warning, or lose safe missing-location context.' );

$GLOBALS['wdc_wc_logs'] = array();
$mismatch_http = new JetFakeHttp(
	array(
		array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'price_zabor' => '0', 'price_terminal' => '1200', 'price_delivery' => '700', 'price_dop' => '0', 'city_from' => 'Новосибирск', 'city_terminal_from' => 'Новосибирск', 'city_terminal_to' => 'Астана', 'city_to' => 'Астана ОПТ', 'day_from' => '3', 'day_to' => '5', 'valuta' => 1, 'valuta_name' => 'руб' ) ), JSON_UNESCAPED_UNICODE ) ),
	)
);
$mismatch_quote = ( new JetLogisticCarrier( $settings, new JetLogisticApiClient( $mismatch_http, $settings, $credentials ), new JetLogisticQuoteRequestBuilder( $credentials ), new JetLogisticQuoteResponseParser(), $geo, $normalizer, new Logger() ) )->quote( new QuoteRequest( 'KZ', new Address( country_code: 'KZ', city: 'Алматы' ), $package, 'card', Money::from_rubles( 19500 ), '2026-07-28', array( 'selected_location_id' => 162695 ) ) );
$mismatch_warning = $GLOBALS['wdc_wc_logs'][0] ?? array();
jet_assert( ! $mismatch_quote->success && 'jet_destination_city_mismatch' === $mismatch_quote->error_code && 'warning' === (string) ( $mismatch_warning['level'] ?? '' ) && 'Алматы' === (string) ( $mismatch_warning['context']['requested_city'] ?? '' ) && 'Астана ОПТ' === (string) ( $mismatch_warning['context']['response_city_to'] ?? '' ) && 'алматы' === (string) ( $mismatch_warning['context']['normalized_requested_city'] ?? '' ) && 'астана' === (string) ( $mismatch_warning['context']['normalized_response_city'] ?? '' ), 'Jet true destination mismatch must fail closed and log safe city comparison context.' );

$GLOBALS['wpdb']->countries[] = array( 'service_id' => 501, 'country_code' => 'KZ' );
$GLOBALS['wpdb']->countries[] = array( 'service_id' => 501, 'country_code' => 'BY' );
$service_repo = new DeliveryServiceRepository( $GLOBALS['wpdb'] );
$country_repo = new DeliveryServiceCountryRepository( $GLOBALS['wpdb'] );
$service_manager = new DeliveryServiceManager( $service_repo, $country_repo, new RuleRepository( $GLOBALS['wpdb'] ), ( new ReflectionClass( RussianPostCountryDirectory::class ) )->newInstanceWithoutConstructor(), new DeliveryServiceSettingsRepository( $GLOBALS['wpdb'] ) );
$jet_service = $service_repo->find_by_service_key( JetLogisticSettings::SERVICE_KEY );
jet_assert( $jet_service instanceof DeliveryService && $service_manager->service_available_for_country( $jet_service, 'KZ' ) && $service_manager->service_available_for_country( $jet_service, 'BY' ) && ! $service_manager->service_available_for_country( $jet_service, 'RU' ), 'Jet service availability must allow BY/KZ and keep RU destination disabled.' );
$orchestrator_http = new JetFakeHttp(
	array(
		array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'price_zabor' => '0', 'price_terminal' => '1200', 'price_delivery' => '700', 'price_dop' => '0', 'city_from' => 'Новосибирск', 'city_terminal_from' => 'Новосибирск', 'city_terminal_to' => 'Алматы', 'city_to' => 'Алматы 2 нижний город (аэропорт, СВХ) ОПТ', 'day_from' => '', 'day_to' => '', 'valuta' => 1, 'valuta_name' => 'руб' ) ), JSON_UNESCAPED_UNICODE ) ),
	)
);
$orchestrator_carrier = new JetLogisticCarrier( $settings, new JetLogisticApiClient( $orchestrator_http, $settings, $credentials ), new JetLogisticQuoteRequestBuilder( $credentials ), new JetLogisticQuoteResponseParser(), $geo, $normalizer );
$carrier_registry = new CarrierRegistry();
$carrier_registry->register( $orchestrator_carrier );
$formatter = new DeliveryDateFormatter();
$core_settings = new SettingsRepository();
$core_settings->set( SettingsRepository::SHOP_PROCESSING_WORKING_DAYS_KEY, 0 );
$timezone = new TimezoneService();
$calendar = new CalendarService( ( new ReflectionClass( \WallsShop\WDC\Calendar\Storage\CalendarRepository::class ) )->newInstanceWithoutConstructor(), new \WallsShop\WDC\Calendar\Services\YearGenerator(), $core_settings, $timezone );
$orchestrator = new CheckoutOrchestrator(
	$carrier_registry,
	new RuleAppliedRateBuilder( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) ),
	new RateSorter(),
	new FallbackRateFactory(),
	new CarrierExecutionGuard( new CheckoutLogger() ),
	new CheckoutLogger(),
	new DeliveryLeadTimeNormalizer( $core_settings, new DeliveryServiceSettingsRepository( $GLOBALS['wpdb'] ), new DeliveryDateCalculator( $calendar, $timezone, $formatter ), $formatter ),
	null,
	new DeliveryServiceRegistry( $service_repo, $carrier_registry ),
	$service_manager
);
$orchestrator_rates = $orchestrator->calculate_rates( new QuoteRequest( 'KZ', new Address( country_code: 'KZ', city: 'Алматы' ), $package, 'card', Money::from_rubles( 19500 ), '2026-07-28', array( 'selected_location_id' => 162695 ) ) );
$orchestrator_rate_ids = array_map( static fn( object $rate ): string => (string) $rate->rate_id, $orchestrator_rates );
jet_assert( in_array( JetLogisticSettings::PICKUP_RATE_KEY, $orchestrator_rate_ids, true ) && in_array( JetLogisticSettings::COURIER_RATE_KEY, $orchestrator_rate_ids, true ) && 1 === count( $orchestrator_http->requests ) && 'Алматы' === (string) ( $orchestrator_http->requests[0]['payload']['cityto'] ?? '' ), 'CheckoutOrchestrator must return Jet pickup/courier rates for mapped KZ Алматы with one calculator call.' );

$orchestrator_atbasar_http = new JetFakeHttp(
	array(
		array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'price_zabor' => '0', 'price_terminal' => '1300', 'price_delivery' => '800', 'price_dop' => '50', 'city_from' => 'Новосибирск', 'city_terminal_from' => 'Новосибирск', 'city_terminal_to' => 'Астана', 'city_to' => 'Атбасар 1 район ОПТ', 'day_from' => '', 'day_to' => '', 'valuta' => 1, 'valuta_name' => 'руб' ) ), JSON_UNESCAPED_UNICODE ) ),
	)
);
$orchestrator_atbasar_carrier = new JetLogisticCarrier( $settings, new JetLogisticApiClient( $orchestrator_atbasar_http, $settings, $credentials ), new JetLogisticQuoteRequestBuilder( $credentials ), new JetLogisticQuoteResponseParser(), $geo, $normalizer );
$atbasar_carrier_registry = new CarrierRegistry();
$atbasar_carrier_registry->register( $orchestrator_atbasar_carrier );
$orchestrator_atbasar = new CheckoutOrchestrator(
	$atbasar_carrier_registry,
	new RuleAppliedRateBuilder( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) ),
	new RateSorter(),
	new FallbackRateFactory(),
	new CarrierExecutionGuard( new CheckoutLogger() ),
	new CheckoutLogger(),
	new DeliveryLeadTimeNormalizer( $core_settings, new DeliveryServiceSettingsRepository( $GLOBALS['wpdb'] ), new DeliveryDateCalculator( $calendar, $timezone, $formatter ), $formatter ),
	null,
	new DeliveryServiceRegistry( $service_repo, $atbasar_carrier_registry ),
	$service_manager
);
$orchestrator_atbasar_rates = $orchestrator_atbasar->calculate_rates( new QuoteRequest( 'KZ', new Address( country_code: 'KZ', city: 'Атбасар' ), $package, 'card', Money::from_rubles( 19500 ), '2026-07-28', array( 'selected_location_id' => 184506 ) ) );
$orchestrator_atbasar_rate_ids = array_map( static fn( object $rate ): string => (string) $rate->rate_id, $orchestrator_atbasar_rates );
jet_assert( in_array( JetLogisticSettings::PICKUP_RATE_KEY, $orchestrator_atbasar_rate_ids, true ) && in_array( JetLogisticSettings::COURIER_RATE_KEY, $orchestrator_atbasar_rate_ids, true ) && 1 === count( $orchestrator_atbasar_http->requests ) && 'Атбасар' === (string) ( $orchestrator_atbasar_http->requests[0]['payload']['cityto'] ?? '' ) && str_contains( $orchestrator_atbasar_rates[0]->title, 'Астана' ) && 'no' === (string) ( $orchestrator_atbasar_rates[0]->meta['jet_local_terminal'] ?? '' ), 'CheckoutOrchestrator must return Jet pickup/courier rates for mapped KZ Атбасар with canonical location_id and remote terminal Астана.' );

$orchestrator_mismatch_http = new JetFakeHttp(
	array(
		array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'price_zabor' => '0', 'price_terminal' => '1200', 'price_delivery' => '700', 'price_dop' => '0', 'city_from' => 'Новосибирск', 'city_terminal_from' => 'Новосибирск', 'city_terminal_to' => 'Астана', 'city_to' => 'Астана ОПТ', 'day_from' => '', 'day_to' => '', 'valuta' => 1, 'valuta_name' => 'руб' ) ), JSON_UNESCAPED_UNICODE ) ),
	)
);
$orchestrator_mismatch_carrier = new JetLogisticCarrier( $settings, new JetLogisticApiClient( $orchestrator_mismatch_http, $settings, $credentials ), new JetLogisticQuoteRequestBuilder( $credentials ), new JetLogisticQuoteResponseParser(), $geo, $normalizer );
$mismatch_carrier_registry = new CarrierRegistry();
$mismatch_carrier_registry->register( $orchestrator_mismatch_carrier );
$orchestrator_mismatch = new CheckoutOrchestrator(
	$mismatch_carrier_registry,
	new RuleAppliedRateBuilder( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) ),
	new RateSorter(),
	new FallbackRateFactory(),
	new CarrierExecutionGuard( new CheckoutLogger() ),
	new CheckoutLogger(),
	new DeliveryLeadTimeNormalizer( $core_settings, new DeliveryServiceSettingsRepository( $GLOBALS['wpdb'] ), new DeliveryDateCalculator( $calendar, $timezone, $formatter ), $formatter ),
	null,
	new DeliveryServiceRegistry( $service_repo, $mismatch_carrier_registry ),
	$service_manager
);
$orchestrator_mismatch_rates = $orchestrator_mismatch->calculate_rates( new QuoteRequest( 'KZ', new Address( country_code: 'KZ', city: 'Алматы' ), $package, 'card', Money::from_rubles( 19500 ), '2026-07-28', array( 'selected_location_id' => 162695 ) ) );
$orchestrator_mismatch_rate_ids = array_map( static fn( object $rate ): string => (string) $rate->rate_id, $orchestrator_mismatch_rates );
jet_assert( ! in_array( JetLogisticSettings::PICKUP_RATE_KEY, $orchestrator_mismatch_rate_ids, true ) && ! in_array( JetLogisticSettings::COURIER_RATE_KEY, $orchestrator_mismatch_rate_ids, true ) && 1 === count( $orchestrator_mismatch_http->requests ), 'CheckoutOrchestrator must return zero Jet rates when API city_to is a real destination mismatch.' );

$payload = ( new JetLogisticQuoteRequestBuilder( $credentials ) )->build(
	new QuoteRequest( 'KZ', new Address( country_code: 'KZ' ), Package::from_items( array( new PackageItem( 'B', 'Товар', 1, Money::from_rubles( 25000 ), Money::from_rubles( 20000 ), 1000, 10, 10, 10 ) ), 0, Money::from_rubles( 20000 ), Money::from_rubles( 20000 ) ), 'card', Money::from_rubles( 20000 ), '2026-07-28' ),
	array( 'source_city' => 'Алматы' ),
	array( 'source_city' => 'Астана' )
);
jet_assert( 20000 === (int) $payload['cost'] && 1 === (int) $payload['dops']['D_SDOC'] && 'ТЕКСТИЛЬ' === $payload['naimenovanie'], 'Jet D_SDOC threshold and fixed cargo name must be applied.' );
$threshold_payload = ( new JetLogisticQuoteRequestBuilder( $credentials ) )->build(
	new QuoteRequest( 'KZ', new Address( country_code: 'KZ' ), Package::from_items( array( new PackageItem( 'C', 'Товар', 1, Money::from_rubles( 25000 ), Money::from_rubles( 25000 ), 1000, 10, 10, 10 ) ), 0, Money::from_rubles( 25000 ), Money::from_rubles( 25000 ) ), 'card', Money::from_rubles( 25000 ), '2026-07-28' ),
	array( 'source_city' => 'Алматы' ),
	array( 'source_city' => 'Астана' )
);
jet_assert( 25000 === (int) $threshold_payload['cost'] && 1 === (int) $threshold_payload['dops']['D_SDOC'], 'Jet D_SDOC must stay enabled above threshold using discounted package total.' );
$numeric_string_result = ( new JetLogisticQuoteResponseParser() )->parse( array( 'price_zabor' => '999.50', 'price_terminal' => '1000', 'price_delivery' => 500, 'price_dop' => '100', 'city_to' => 'Астана', 'city_terminal_to' => 'Астана', 'valuta' => 'RUB' ) );
jet_assert( 1000 === $numeric_string_result->price_terminal && 500 === $numeric_string_result->price_delivery && 100 === $numeric_string_result->price_dop, 'Jet quote parser must accept numeric strings from the real API shape.' );
$real_currencyless_result = ( new JetLogisticQuoteResponseParser() )->parse( array( 'price_zabor' => '0', 'price_terminal' => '1000', 'price_delivery' => '500', 'price_dop' => '0', 'city_from' => 'Новосибирск', 'city_terminal_from' => 'Новосибирск', 'city_terminal_to' => 'Астана', 'city_to' => 'Астана', 'day_from' => 2, 'day_to' => 4 ) );
jet_assert( 'RUB' === $real_currencyless_result->valuta && 'RUB' === $real_currencyless_result->valuta_name && 'profile' === $real_currencyless_result->currency_source, 'Jet quote parser must accept real calc_transport response shape without currency fields as RUB from Jet profile.' );
$parser = new JetLogisticQuoteResponseParser();
$currency_case_a = $parser->parse( array( 'price_zabor' => '0', 'price_terminal' => '1000', 'price_delivery' => '500', 'price_dop' => '0', 'city_terminal_to' => 'Астана', 'city_to' => 'Астана', 'valuta' => '1', 'valuta_name' => 'RUB' ) );
jet_assert( 'response_name' === $currency_case_a->currency_source && '1' === $currency_case_a->valuta && 'RUB' === $currency_case_a->valuta_name, 'Jet quote parser must trust valuta_name=RUB and preserve numeric valuta as provider metadata.' );
$currency_case_b = $parser->parse( array( 'price_zabor' => '0', 'price_terminal' => '1000', 'price_delivery' => '500', 'price_dop' => '0', 'city_terminal_to' => 'Астана', 'city_to' => 'Астана', 'valuta' => '643', 'valuta_name' => 'РУБ' ) );
jet_assert( 'response_name' === $currency_case_b->currency_source, 'Jet quote parser must accept valuta_name=РУБ with numeric provider valuta.' );
$currency_case_c = $parser->parse( array( 'price_zabor' => '0', 'price_terminal' => '1000', 'price_delivery' => '500', 'price_dop' => '0', 'city_terminal_to' => 'Астана', 'city_to' => 'Астана', 'valuta' => 'KZT', 'valuta_name' => 'RUB' ) );
jet_assert( 'response_name' === $currency_case_c->currency_source, 'Jet quote parser must treat valuta_name as authoritative when valuta looks like provider metadata.' );
$currency_case_d = $parser->parse( array( 'price_zabor' => '0', 'price_terminal' => '1000', 'price_delivery' => '500', 'price_dop' => '0', 'city_terminal_to' => 'Астана', 'city_to' => 'Астана', 'valuta' => '', 'valuta_name' => 'RUB' ) );
jet_assert( 'response_name' === $currency_case_d->currency_source, 'Jet quote parser must accept RUB from valuta_name when valuta is empty.' );
$currency_case_e = $parser->parse( array( 'price_zabor' => '0', 'price_terminal' => '1000', 'price_delivery' => '500', 'price_dop' => '0', 'city_terminal_to' => 'Астана', 'city_to' => 'Астана', 'valuta' => 'RUR', 'valuta_name' => '' ) );
jet_assert( 'response_code' === $currency_case_e->currency_source, 'Jet quote parser must accept textual RUB/RUR/РУБ valuta when valuta_name is absent.' );
$currency_case_f = $parser->parse( array( 'price_zabor' => '0', 'price_terminal' => '1000', 'price_delivery' => '500', 'price_dop' => '0', 'city_terminal_to' => 'Астана', 'city_to' => 'Астана', 'valuta' => '1', 'valuta_name' => '' ) );
jet_assert( 'profile' === $currency_case_f->currency_source, 'Jet quote parser must treat numeric valuta without valuta_name as provider ID and fall back to profile RUB.' );
$non_rub_thrown = false;
try {
	$parser->parse( array( 'price_zabor' => '0', 'price_terminal' => '1000', 'price_delivery' => '500', 'price_dop' => '0', 'city_terminal_to' => 'Астана', 'city_to' => 'Астана', 'valuta' => 'KZT' ) );
} catch ( JetLogisticApiException $exception ) {
	$non_rub_thrown = 'jet_currency_not_rub' === $exception->error_code() && 'KZT' === (string) ( $exception->context()['valuta'] ?? '' );
}
jet_assert( $non_rub_thrown, 'Jet quote parser must fail closed when API explicitly returns a non-RUB currency.' );
$non_rub_name_thrown = false;
try {
	$parser->parse( array( 'price_zabor' => '0', 'price_terminal' => '1000', 'price_delivery' => '500', 'price_dop' => '0', 'city_terminal_to' => 'Астана', 'city_to' => 'Астана', 'valuta' => '', 'valuta_name' => 'KZT' ) );
} catch ( JetLogisticApiException $exception ) {
	$non_rub_name_thrown = 'jet_currency_not_rub' === $exception->error_code() && 'KZT' === (string) ( $exception->context()['valuta_name'] ?? '' );
}
jet_assert( $non_rub_name_thrown, 'Jet quote parser must fail closed when authoritative valuta_name is non-RUB.' );
foreach ( array( 'garbage', -1, '' ) as $bad_price ) {
	$thrown = false;
	try {
		( new JetLogisticQuoteResponseParser() )->parse( array( 'price_zabor' => 0, 'price_terminal' => $bad_price, 'price_delivery' => 0, 'price_dop' => 0, 'city_to' => 'Астана', 'city_terminal_to' => 'Астана', 'valuta' => 'RUB' ) );
	} catch ( JetLogisticApiException $exception ) {
		$thrown = 'jet_invalid_response' === $exception->error_code();
	}
	jet_assert( $thrown, 'Jet quote parser must reject malformed, negative, or missing price values instead of converting them to zero.' );
}

$GLOBALS['wdc_remote_post_responses'] = array( array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array() ), JSON_UNESCAPED_UNICODE ) ) );
( new WpJetLogisticHttpClient() )->post_json( JetLogisticApiClient::BASE_URL . JetLogisticApiClient::METHOD_CALC_TRANSPORT, array( 'access_token' => 'jet-test-token' ), 15 );
$wp_post_request = $GLOBALS['wdc_remote_post_requests'][0] ?? array();
jet_assert( ! str_contains( (string) ( $wp_post_request['url'] ?? '' ), 'jet-test-token' ) && true === ( $wp_post_request['args']['sslverify'] ?? false ) && 0 === (int) ( $wp_post_request['args']['redirection'] ?? -1 ) && str_contains( (string) ( $wp_post_request['args']['body'] ?? '' ), 'access_token' ), 'Jet WordPress HTTP client must send token in JSON body, keep it out of URL, verify TLS, and avoid automatic redirects.' );
$GLOBALS['wdc_remote_post_responses'] = array( new WP_Error( 'http_request_timeout' ) );
$timeout_thrown = false;
try {
	( new WpJetLogisticHttpClient() )->post_json( JetLogisticApiClient::BASE_URL . JetLogisticApiClient::METHOD_CALC_TRANSPORT, array( 'access_token' => 'jet-test-token' ), 15 );
} catch ( JetLogisticApiException $exception ) {
	$timeout_thrown = 'jet_http_timeout' === $exception->error_code() && ! str_contains( $exception->getMessage(), 'jet-test-token' );
}
jet_assert( $timeout_thrown, 'Jet HTTP timeout must be classified safely without exposing token.' );

$GLOBALS['wpdb']->jet_statuses = array();
$status_repo = new JetLogisticStatusMappingRepository( $GLOBALS['wpdb'] );
$status_repo->ensure_default_mappings();
jet_assert( empty( $GLOBALS['wpdb']->jet_statuses['доставка груза на склад'] ) && DeliveryStatus::READY_FOR_PICKUP === $status_repo->map( 'Доставка груза на склад выдачи-Астана-(Столица Республики Казахстан)' ) && '' === $status_repo->map( 'Доставка груза на склад приемки-Новосибирск-(Новосибирская Область)' ) && '' === $status_repo->map( 'Отправка груза со склада приемки-Новосибирск-(Новосибирская Область)' ), 'Jet status defaults must remove the broad warehouse rule and map only precise pickup warehouse delivery phrases.' );
jet_assert( DeliveryStatus::DELIVERED === $status_repo->map( 'Груз выдан' ) && DeliveryStatus::DELIVERED === $status_repo->map( 'Груз выдан : 26 июня 2026 г.' ) && DeliveryStatus::DELIVERED === $status_repo->map( 'ГРУЗ ВЫДАН: 26 июня 2026 г.' ) && DeliveryStatus::DELIVERED === $status_repo->map( '  Груз   выдан : 26 июня 2026 г.' ), 'Jet status mapping must use normalized literal substring matching.' );
$status_repo->create_mapping( 'склад', DeliveryStatus::IN_TRANSIT );
jet_assert( DeliveryStatus::READY_FOR_PICKUP === $status_repo->map( 'Доставка груза на склад выдачи-Астана' ), 'Jet status mapping must apply the longest matching phrase before shorter substring rules.' );
$status_count_before_unknown = count( $GLOBALS['wpdb']->jet_statuses );
$GLOBALS['wpdb']->status_mapping_insert_calls = 0;
$GLOBALS['wpdb']->status_mapping_update_calls = 0;
jet_assert( '' === ( new JetLogisticStatusMapper( $status_repo ) )->map( 'Совершенно неизвестное событие Jet' ) && $status_count_before_unknown === count( $GLOBALS['wpdb']->jet_statuses ) && 0 === $GLOBALS['wpdb']->status_mapping_insert_calls && 0 === $GLOBALS['wpdb']->status_mapping_update_calls, 'Jet status mapper must not observe, write, or create unknown incoming status messages.' );

$status_admin = new JetLogisticStatusAdminPage( $status_repo );
$create_result = $status_admin->create_mapping_from_post( array( 'external_status' => 'Передан на доставку', 'universal_status' => DeliveryStatus::IN_TRANSIT ) );
$created_row = $status_repo->find_by_normalized_status( 'Передан на доставку' );
$created_id = (int) ( $created_row['id'] ?? 0 );
jet_assert( ! empty( $create_result['success'] ) && $created_id > 0 && DeliveryStatus::IN_TRANSIT === $status_repo->map( 'Передан на доставку курьеру' ), 'Jet status admin must create a new substring mapping.' );
$update_status_result = $status_admin->update_mapping_from_post( array( 'mapping_id' => $created_id, 'external_status' => 'Передан на доставку', 'universal_status' => DeliveryStatus::HANDED_TO_COURIER ) );
jet_assert( ! empty( $update_status_result['success'] ) && $created_id === (int) ( $status_repo->find_by_normalized_status( 'Передан на доставку' )['id'] ?? 0 ) && DeliveryStatus::HANDED_TO_COURIER === $status_repo->map( 'Передан на доставку курьеру' ), 'Jet status admin update must preserve ID and change universal status.' );
$update_phrase_result = $status_admin->update_mapping_from_post( array( 'mapping_id' => $created_id, 'external_status' => 'Передан курьеру', 'universal_status' => DeliveryStatus::HANDED_TO_COURIER ) );
jet_assert( ! empty( $update_phrase_result['success'] ) && '' === $status_repo->map( 'Передан на доставку курьеру' ) && DeliveryStatus::HANDED_TO_COURIER === $status_repo->map( 'Передан курьеру сегодня' ), 'Jet status admin update must change the phrase without leaving the old pattern active.' );
$duplicate_result = $status_admin->update_mapping_from_post( array( 'mapping_id' => $created_id, 'external_status' => 'Груз выдан', 'universal_status' => DeliveryStatus::DELIVERED ) );
jet_assert( empty( $duplicate_result['success'] ) && str_contains( (string) $duplicate_result['message'], 'уже существует' ) && DeliveryStatus::HANDED_TO_COURIER === $status_repo->map( 'Передан курьеру сегодня' ) && DeliveryStatus::DELIVERED === $status_repo->map( 'Груз выдан : 26 июня 2026 г.' ), 'Jet status admin must reject duplicate normalized phrases without damaging existing mappings.' );
$delete_result = $status_admin->delete_mapping_from_post( array( 'mapping_id' => $created_id ) );
jet_assert( ! empty( $delete_result['success'] ) && '' === $status_repo->map( 'Передан курьеру сегодня' ), 'Jet status admin must delete mappings through POST-only action handlers.' );
$status_repo->delete_mapping( (int) ( $status_repo->find_by_normalized_status( 'Груз выдан' )['id'] ?? 0 ) );
$status_repo->create_schema();
jet_assert( array() === $status_repo->find_by_normalized_status( 'Груз выдан' ), 'Jet status create_schema must not restore deleted default mappings.' );
$status_repo->ensure_default_mappings();

ob_start();
$status_admin->render_embedded( new DeliveryService( 501, JetLogisticSettings::SERVICE_KEY, JetLogisticSettings::CARRIER_KEY, DeliveryService::TYPE_API, 'Jet Logistic' ), array() );
$status_admin_html = (string) ob_get_clean();
jet_assert( str_contains( $status_admin_html, 'Фраза в статусе Jet' ) && str_contains( $status_admin_html, 'Универсальный статус' ) && str_contains( $status_admin_html, 'Действия' ) && str_contains( $status_admin_html, 'Сохранить' ) && str_contains( $status_admin_html, 'Удалить' ) && ! str_contains( $status_admin_html, 'Активно' ) && ! str_contains( $status_admin_html, 'Последнее событие' ) && ! str_contains( $status_admin_html, 'Количество' ) && ! str_contains( $status_admin_html, 'name="active"' ), 'Jet status admin HTML must expose phrase CRUD controls and remove active/last_seen/occurrence_count UI.' );
jet_assert( str_contains( $status_admin_html, 'check_jet_tracking' ) && str_contains( $status_admin_html, 'Номер груза Jet' ) && str_contains( $status_admin_html, 'Проверить статус' ), 'Jet status admin HTML must expose safe tracking diagnostics.' );

$short_warehouse_mapping = $status_repo->find_by_normalized_status( 'склад' );
if ( array() !== $short_warehouse_mapping ) {
	$status_repo->delete_mapping( (int) $short_warehouse_mapping['id'] );
}
$status_repo->create_mapping( 'Доставка груза на склад приемки', DeliveryStatus::IN_TRANSIT );
$status_repo->create_mapping( 'Отправка груза со склада приемки', DeliveryStatus::IN_TRANSIT );

$status_http = new JetFakeHttp( array( array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'logs' => array( array( 'date' => '2026-07-28 10:00:00', 'message' => 'Неизвестно' ), array( 'date' => '2026-07-27 10:00:00', 'message' => 'Груз выдан' ), array( 'date' => '2026-07-27 10:00:00', 'message' => 'Груз выдан' ) ) ) ), JSON_UNESCAPED_UNICODE ) ) ) );
$status_service = new JetLogisticStatusService( new JetLogisticApiClient( $status_http, $settings, $credentials ), new JetLogisticStatusMapper( $status_repo ) );
$status = $status_service->update( array( 'tracking_number' => 'JET-1', 'universal_status_code' => DeliveryStatus::IN_TRANSIT ) );
jet_assert( DeliveryStatus::DELIVERED === $status['shipment_patch']['universal_status_code'] && 'Груз выдан' === (string) $status['shipment_patch']['carrier_status_message'] && 2 === count( $status['shipment_patch']['status_events'] ), 'Unknown latest Jet log line must not override the latest mapped operational status event.' );
jet_assert( 'jet-test-token' === (string) ( $status_http->requests[0]['payload']['access_token'] ?? '' ) && ! str_contains( (string) $status_http->requests[0]['url'], 'jet-test-token' ), 'Jet tracking API must send access_token in request body and not in URL.' );
$unknown_only_status = ( new JetLogisticStatusService( new JetLogisticApiClient( new JetFakeHttp( array( array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'logs' => array( array( 'date' => '2026-07-28 10:00:00', 'message' => 'Передача документов оператору' ) ) ) ), JSON_UNESCAPED_UNICODE ) ) ) ), $settings, $credentials ), new JetLogisticStatusMapper( $status_repo ) ) )->update( array( 'tracking_number' => 'JET-UNKNOWN', 'universal_status_code' => DeliveryStatus::IN_TRANSIT ) );
jet_assert( DeliveryStatus::IN_TRANSIT === $unknown_only_status['shipment_patch']['universal_status_code'] && '' === (string) $unknown_only_status['shipment_patch']['carrier_status_message'], 'Jet unknown-only status response must preserve current universal status and not choose informational text as current status.' );
$delivered_status_http = new JetFakeHttp( array( array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'logs' => array( array( 'date' => '2026-06-26 00:00:00', 'message' => 'Груз выдан : 26 июня 2026 г.' ) ) ) ), JSON_UNESCAPED_UNICODE ) ) ) );
$delivered_status = ( new JetLogisticStatusService( new JetLogisticApiClient( $delivered_status_http, $settings, $credentials ), new JetLogisticStatusMapper( $status_repo ) ) )->update( array( 'tracking_number' => 'JET-2', 'universal_status_code' => DeliveryStatus::IN_TRANSIT ) );
jet_assert( DeliveryStatus::DELIVERED === $delivered_status['shipment_patch']['universal_status_code'] && 'Груз выдан : 26 июня 2026 г.' === (string) $delivered_status['shipment_patch']['carrier_status_message'], 'Jet status service must map substring only from latest message while preserving the full carrier status message.' );
$chronology_status = ( new JetLogisticStatusService( new JetLogisticApiClient( new JetFakeHttp( array( array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'logs' => array( array( 'date' => '31.08.2026', 'message' => 'Отправка груза со склада приемки-Новосибирск-(Новосибирская Область)' ), array( 'date' => '01.09.2026', 'message' => 'Доставка груза на склад выдачи-Астана-(Столица Республики Казахстан)' ) ) ) ), JSON_UNESCAPED_UNICODE ) ) ) ), $settings, $credentials ), new JetLogisticStatusMapper( $status_repo ) ) )->update( array( 'tracking_number' => 'JET-CHRONO', 'universal_status_code' => DeliveryStatus::IN_TRANSIT ) );
jet_assert( DeliveryStatus::READY_FOR_PICKUP === $chronology_status['shipment_patch']['universal_status_code'] && '01.09.2026' === (string) $chronology_status['shipment_patch']['carrier_status_date'], 'Jet status service must parse dd.mm.YYYY dates chronologically instead of sorting date strings.' );
$expected_arrival_status = ( new JetLogisticStatusService( new JetLogisticApiClient( new JetFakeHttp( array( array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'logs' => array( array( 'date' => '26.08.2026', 'message' => 'Доставка груза на склад приемки-Новосибирск-(Новосибирская Область)' ), array( 'date' => '27.08.2026', 'message' => 'Отправка груза со склада приемки-Новосибирск-(Новосибирская Область)' ), array( 'date' => '', 'message' => 'Ожидаемая дата прибытия на склад выдачи Астана-(Столица Республики Казахстан) : 29 августа 2026 г.' ), array( 'date' => '', 'message' => 'Стоимость транспортно-экспедиционных услуг : 8 744,00 КЗТ' ) ) ) ), JSON_UNESCAPED_UNICODE ) ) ) ), $settings, $credentials ), new JetLogisticStatusMapper( $status_repo ) ) )->update( array( 'tracking_number' => 'JET-EXPECTED', 'universal_status_code' => DeliveryStatus::IN_TRANSIT ) );
jet_assert( DeliveryStatus::IN_TRANSIT === $expected_arrival_status['shipment_patch']['universal_status_code'] && str_contains( (string) $expected_arrival_status['shipment_patch']['carrier_status_message'], 'Отправка груза со склада приемки' ) && '27.08.2026' === (string) $expected_arrival_status['shipment_patch']['carrier_status_date'], 'Jet expected-arrival and cost informational lines must not become current status events.' );
$production_text_status = ( new JetLogisticStatusService( new JetLogisticApiClient( new JetFakeHttp( array( array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => "Код груза 007483827165, вид груза ШНУРЫ\n\n26.08.2026:\nДоставка груза на склад приемки-Новосибирск-(Новосибирская Область)\n\n27.08.2026:\nОтправка груза со склада приемки-Новосибирск-(Новосибирская Область)\n\nОжидаемая дата прибытия на склад выдачи Астана-(Столица Республики Казахстан) : 29 августа 2026 г.\n\nСтоимость транспортно-экспедиционных услуг : 8 744,00 КЗТ" ), JSON_UNESCAPED_UNICODE ) ) ) ), $settings, $credentials ), new JetLogisticStatusMapper( $status_repo ) ) )->update( array( 'tracking_number' => 'JET-TEXT', 'universal_status_code' => DeliveryStatus::IN_TRANSIT ) );
jet_assert( DeliveryStatus::IN_TRANSIT === $production_text_status['shipment_patch']['universal_status_code'] && str_contains( (string) $production_text_status['shipment_patch']['carrier_status_message'], 'Отправка груза со склада приемки' ) && '27.08.2026' === (string) $production_text_status['shipment_patch']['carrier_status_date'], 'Jet status API client must parse production text logs into dated events before current status resolution.' );
$many_logs = array();
for ( $i = 0; $i < 9; ++$i ) {
	$many_logs[] = array( 'date' => '2026-08-' . str_pad( (string) ( 10 + $i ), 2, '0', STR_PAD_LEFT ), 'message' => 'Информационная строка ' . $i );
}
$many_logs[] = array( 'date' => '2026-09-01', 'message' => 'Груз выдан : 1 сентября 2026 г.' );
$many_status = ( new JetLogisticStatusService( new JetLogisticApiClient( new JetFakeHttp( array( array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'logs' => $many_logs ) ), JSON_UNESCAPED_UNICODE ) ) ) ), $settings, $credentials ), new JetLogisticStatusMapper( $status_repo ) ) )->update( array( 'tracking_number' => 'JET-MANY', 'universal_status_code' => DeliveryStatus::IN_TRANSIT ) );
jet_assert( DeliveryStatus::DELIVERED === $many_status['shipment_patch']['universal_status_code'] && count( $many_status['shipment_patch']['status_events'] ) <= 5, 'Jet current status must be resolved across all logs before storing at most five compact events.' );

$diagnostic_http = new JetFakeHttp(
	array(
		array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'price_terminal' => '1000', 'price_delivery' => '500', 'price_dop' => '0', 'price_zabor' => '100', 'city_to' => 'Astana', 'city_terminal_to' => 'Astana', 'valuta' => '1', 'valuta_name' => 'RUB' ) ), JSON_UNESCAPED_UNICODE ) ),
		array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'logs' => array( array( 'date' => '2026-06-26 00:00:00', 'message' => 'Груз выдан : 26 июня 2026 г.' ), array( 'date' => '2026-06-25 00:00:00', 'message' => 'Доставка груза на склад выдачи-Астана-(Столица Республики Казахстан)' ) ) ) ), JSON_UNESCAPED_UNICODE ) ),
	)
);
$diagnostics = new JetLogisticApiDiagnosticService( $credentials, $settings, new JetLogisticApiClient( $diagnostic_http, $settings, $credentials ), $geo, $status_repo );
$connection_check = $diagnostics->check_connection();
jet_assert( ! empty( $connection_check['success'] ) && 'calc_transport' === (string) $connection_check['endpoint'] && 'Токен задан' === (string) $connection_check['token_state'] && '1' === (string) ( $connection_check['details']['valuta'] ?? '' ) && 'RUB' === (string) ( $connection_check['details']['valuta_name'] ?? '' ) && ! str_contains( wp_json_encode( $connection_check, JSON_UNESCAPED_UNICODE ), 'jet-test-token' ), 'Jet connection diagnostic must use calculator endpoint safely, expose safe currency fields, and redact token.' );
$diagnostic_write_counts_before = array( $GLOBALS['wpdb']->status_mapping_insert_calls, $GLOBALS['wpdb']->status_mapping_update_calls, $GLOBALS['wpdb']->status_mapping_delete_calls );
$tracking_check = $diagnostics->check_tracking( 'JET-DIAG-1' );
jet_assert( ! empty( $tracking_check['success'] ) && 2 === count( $tracking_check['events'] ?? array() ) && str_contains( (string) ( $tracking_check['details']['event_1'] ?? '' ), 'Груз выдан' ) && str_contains( (string) ( $tracking_check['details']['event_1'] ?? '' ), DeliveryStatus::DELIVERED ) && $diagnostic_write_counts_before === array( $GLOBALS['wpdb']->status_mapping_insert_calls, $GLOBALS['wpdb']->status_mapping_update_calls, $GLOBALS['wpdb']->status_mapping_delete_calls ) && ! str_contains( wp_json_encode( $tracking_check, JSON_UNESCAPED_UNICODE ), 'jet-test-token' ), 'Jet tracking diagnostic must show compact mapped events without destructive writes or token exposure.' );
$credentials->clear_access_token();
$missing_token_check = ( new JetLogisticApiDiagnosticService( $credentials, $settings, new JetLogisticApiClient( new JetFakeHttp( array() ), $settings, $credentials ), $geo, $status_repo ) )->check_connection();
$credentials->save_access_token( 'jet-test-token' );
jet_assert( empty( $missing_token_check['success'] ) && 'jet_token_missing' === (string) $missing_token_check['code'] && 'Токен не задан' === (string) $missing_token_check['token_state'], 'Jet connection diagnostic must classify missing token before any API request.' );
foreach ( array( 401 => 'jet_http_401', 403 => 'jet_http_403' ) as $http_status => $expected_code ) {
	$error_result = ( new JetLogisticApiDiagnosticService( $credentials, $settings, new JetLogisticApiClient( new JetFakeHttp( array( array( 'status' => $http_status, 'body' => '{}' ) ) ), $settings, $credentials ), $geo, $status_repo ) )->check_connection();
	jet_assert( empty( $error_result['success'] ) && $expected_code === (string) $error_result['code'] && ! str_contains( wp_json_encode( $error_result, JSON_UNESCAPED_UNICODE ), 'jet-test-token' ), 'Jet connection diagnostic must classify HTTP auth failures safely.' );
}
$invalid_json_result = ( new JetLogisticApiDiagnosticService( $credentials, $settings, new JetLogisticApiClient( new JetFakeHttp( array( array( 'status' => 200, 'body' => '<html></html>' ) ) ), $settings, $credentials ), $geo, $status_repo ) )->check_connection();
$api_error_result = ( new JetLogisticApiDiagnosticService( $credentials, $settings, new JetLogisticApiClient( new JetFakeHttp( array( array( 'status' => 200, 'body' => json_encode( array( 'success' => false, 'error' => 'bad token jet-test-token' ), JSON_UNESCAPED_UNICODE ) ) ) ), $settings, $credentials ), $geo, $status_repo ) )->check_connection();
jet_assert( empty( $invalid_json_result['success'] ) && 'jet_invalid_json' === (string) $invalid_json_result['code'] && empty( $api_error_result['success'] ) && 'jet_api_error' === (string) $api_error_result['code'] && ! str_contains( wp_json_encode( $api_error_result, JSON_UNESCAPED_UNICODE ), 'jet-test-token' ), 'Jet diagnostics must classify invalid JSON and API-level errors without exposing raw response bodies.' );
$currency_error_result = ( new JetLogisticApiDiagnosticService( $credentials, $settings, new JetLogisticApiClient( new JetFakeHttp( array( array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'price_terminal' => '1000', 'price_delivery' => '500', 'price_dop' => '0', 'price_zabor' => '100', 'city_to' => 'Astana', 'city_terminal_to' => 'Astana', 'valuta' => '1', 'valuta_name' => 'KZT' ) ), JSON_UNESCAPED_UNICODE ) ) ) ), $settings, $credentials ), $geo, $status_repo ) )->check_connection();
jet_assert( empty( $currency_error_result['success'] ) && 'jet_currency_not_rub' === (string) $currency_error_result['code'] && '1' === (string) ( $currency_error_result['details']['valuta'] ?? '' ) && 'KZT' === (string) ( $currency_error_result['details']['valuta_name'] ?? '' ), 'Jet connection diagnostic must keep safe valuta/valuta_name evidence when production parser rejects currency.' );

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
