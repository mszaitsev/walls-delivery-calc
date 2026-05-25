<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function wdc_ds_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-05-25 12:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		/** @var array<int,array<string,mixed>> */
		public array $services = array();
		/** @var array<int,array<string,mixed>> */
		public array $settings = array();
		/** @var array<int,array<string,mixed>> */
		public array $countries = array();
		/** @var array<int,array<string,mixed>> */
		public array $rules = array();
		/** @var array<int,array<string,mixed>> */
		public array $queries = array();

		public function get_charset_collate(): string { return 'DEFAULT CHARSET=utf8mb4'; }
		public function query( string $query ): bool { $this->queries[] = array( 'query' => $query ); return true; }
		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sd]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}
			return $query;
		}
		public function insert( string $table, array $data, array $format = array() ): bool {
			$data['id'] = ++$this->insert_id;
			if ( str_contains( $table, 'wdc_delivery_service_settings' ) ) {
				$this->settings[] = $data;
			} elseif ( str_contains( $table, 'wdc_delivery_service_countries' ) ) {
				$this->countries[] = $data;
			} elseif ( str_contains( $table, 'wdc_delivery_services' ) ) {
				$this->services[] = $data;
			}
			return true;
		}
		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			$rows =& $this->rows_for_table( $table );
			foreach ( $rows as $index => $row ) {
				$matches = true;
				foreach ( $where as $key => $value ) {
					$matches = $matches && (string) ( $row[ $key ] ?? '' ) === (string) $value;
				}
				if ( $matches ) {
					$rows[ $index ] = array_merge( $row, $data );
				}
			}
			return true;
		}
		public function delete( string $table, array $where, array $format = array() ): bool {
			$rows =& $this->rows_for_table( $table );
			$rows = array_values(
				array_filter(
					$rows,
					static function ( array $row ) use ( $where ): bool {
						foreach ( $where as $key => $value ) {
							if ( (string) ( $row[ $key ] ?? '' ) !== (string) $value ) {
								return true;
							}
						}
						return false;
					}
				)
			);
			return true;
		}
		public function get_row( string $query, mixed $output = null ): ?array {
			if ( str_contains( $query, 'wdc_delivery_services' ) && preg_match( "/service_key = '([^']+)'/", $query, $matches ) ) {
				foreach ( $this->services as $row ) {
					if ( $row['service_key'] === $matches[1] && empty( $row['deleted'] ) ) {
						return $row;
					}
				}
			}
			if ( str_contains( $query, 'wdc_delivery_service_settings' ) && preg_match( "/service_id = ([0-9]+).*setting_key = '([^']+)'/", $query, $matches ) ) {
				foreach ( $this->settings as $row ) {
					if ( (int) $row['service_id'] === (int) $matches[1] && $row['setting_key'] === $matches[2] ) {
						return $row;
					}
				}
			}
			return null;
		}
		public function get_var( string $query ): mixed {
			if ( str_contains( $query, 'wdc_delivery_service_settings' ) && preg_match( "/service_id = ([0-9]+).*setting_key = '([^']+)'/", $query, $matches ) ) {
				foreach ( $this->settings as $row ) {
					if ( (int) $row['service_id'] === (int) $matches[1] && $row['setting_key'] === $matches[2] ) {
						return $row['id'];
					}
				}
			}
			return null;
		}
		public function get_results( string $query, mixed $output = null ): array {
			if ( str_contains( $query, 'wdc_delivery_services' ) ) {
				return array_values( array_filter( $this->services, static fn ( array $row ): bool => empty( $row['deleted'] ) ) );
			}
			if ( str_contains( $query, 'wdc_delivery_service_settings' ) && preg_match( '/service_id = ([0-9]+)/', $query, $matches ) ) {
				return array_values( array_filter( $this->settings, static fn ( array $row ): bool => (int) $row['service_id'] === (int) $matches[1] ) );
			}
			if ( str_contains( $query, 'wdc_rules' ) ) {
				$rows = $this->rules;
				if ( preg_match( "/target_type = '([^']+)'/", $query, $matches ) ) {
					$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => $row['target_type'] === $matches[1] ) );
				}
				if ( preg_match( "/target_value = '([^']+)'/", $query, $matches ) ) {
					$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => $row['target_value'] === $matches[1] ) );
				}
				return $rows;
			}
			return array();
		}
		public function get_col( string $query ): array {
			if ( preg_match( '/service_id = ([0-9]+)/', $query, $matches ) ) {
				return array_values( array_map( static fn ( array $row ): string => $row['country_code'], array_filter( $this->countries, static fn ( array $row ): bool => (int) $row['service_id'] === (int) $matches[1] ) ) );
			}
			return array();
		}
		private function &rows_for_table( string $table ): array {
			if ( str_contains( $table, 'wdc_delivery_service_settings' ) ) {
				return $this->settings;
			}
			if ( str_contains( $table, 'wdc_delivery_service_countries' ) ) {
				return $this->countries;
			}
			return $this->services;
		}
	}
}

function dbDelta( string $sql ): void { $GLOBALS['wdc_db_delta'][] = $sql; }

use WallsShop\WDC\Carriers\RussianPost\RussianPostSettings;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceManager;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Storage\RuleRepository;
use WallsShop\WDC\Rules\ValueObjects\RuleActionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationBases;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationTypes;

$GLOBALS['wpdb'] = new wpdb();
$migration = require dirname( __DIR__, 2 ) . '/database/migrations/0018_create_delivery_services_tables.php';
$migration();
wdc_ds_assert( count( $GLOBALS['wdc_db_delta'] ?? array() ) === 3, 'Delivery services migration must create three tables.' );

$services = new DeliveryServiceRepository( $GLOBALS['wpdb'] );
$settings = new DeliveryServiceSettingsRepository( $GLOBALS['wpdb'] );
$countries = new DeliveryServiceCountryRepository( $GLOBALS['wpdb'] );

$rp = $services->ensure_russian_post_service();
wdc_ds_assert( RussianPostSettings::SERVICE_KEY === $rp->service_key, 'Russian Post service must be auto-created.' );
wdc_ds_assert( DeliveryService::AVAILABILITY_CARRIER_DIRECTORY === $rp->availability_mode, 'Russian Post service must use carrier_directory availability.' );
wdc_ds_assert( array() === $GLOBALS['wpdb']->rules, 'Russian Post bootstrap must not auto-create rules.' );

$custom_id = $services->create_service( array( 'service_key' => 'fixed_test', 'service_type' => DeliveryService::TYPE_FIXED, 'title' => 'Fixed', 'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES ) );
$services->update_service( $custom_id, array( 'enabled' => 0, 'minimum_price_rub' => '10,5' ) );
$custom = $services->find_by_service_key( 'fixed_test' );
wdc_ds_assert( $custom instanceof DeliveryService && ! $custom->enabled && 10.5 === $custom->minimum_price_rub, 'Service CRUD must update enabled and decimal fields.' );

$settings->set_setting( $custom_id, 'endpoint', 'https://example.test', 'string' );
$settings->set_setting( $custom_id, 'limits', array( 'max_weight_g' => 1000 ) );
wdc_ds_assert( 'https://example.test' === $settings->get_setting( $custom_id, 'endpoint' ), 'Settings repository must read string values.' );
wdc_ds_assert( 1000 === $settings->all_settings( $custom_id )['limits']['max_weight_g'], 'Settings repository must read JSON values.' );
$settings->delete_setting( $custom_id, 'endpoint' );
wdc_ds_assert( null === $settings->get_setting( $custom_id, 'endpoint' ), 'Settings repository must delete values.' );

$countries->replace_countries( $custom_id, array( 'us', 'DE', 'bad', 'US' ) );
wdc_ds_assert( array( 'US', 'DE' ) === $countries->countries( $custom_id ), 'Country repository must normalize and de-duplicate country codes.' );
wdc_ds_assert( in_array( 'US', $countries->countries( $custom_id ), true ) && in_array( 'DE', $countries->countries( $custom_id ), true ), 'Country repository must keep valid countries.' );

$directory = ( new ReflectionClass( WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory::class ) )->newInstanceWithoutConstructor();
$manager = new DeliveryServiceManager( $services, $countries, new RuleRepository( $GLOBALS['wpdb'] ), $directory );
wdc_ds_assert( $manager->service_available_for_country( $services->find_by_service_key( 'fixed_test' ), 'US' ), 'selected_countries availability must allow listed country.' );
wdc_ds_assert( ! $manager->service_available_for_country( $services->find_by_service_key( 'fixed_test' ), 'FR' ), 'selected_countries availability must reject unlisted country.' );
$services->update_service( $custom_id, array( 'availability_mode' => DeliveryService::AVAILABILITY_ALL_COUNTRIES ) );
wdc_ds_assert( $manager->service_available_for_country( $services->find_by_service_key( 'fixed_test' ), 'FR' ), 'all_countries availability must allow any country.' );
$services->update_service( $custom_id, array( 'availability_mode' => DeliveryService::AVAILABILITY_ALL_EXCEPT_SELECTED ) );
wdc_ds_assert( ! $manager->service_available_for_country( $services->find_by_service_key( 'fixed_test' ), 'US' ) && $manager->service_available_for_country( $services->find_by_service_key( 'fixed_test' ), 'FR' ), 'all_except_selected availability must reject listed countries only.' );

$services->update_service( $custom_id, array( 'minimum_price_rub' => 10, 'round_up_to_ruble' => 1 ) );
$processed = $manager->post_process_rate(
	new DeliveryRate( 'rate', 'carrier', 'Carrier', 'fixed_test', 'Fixed', 'tariff', 'Tariff', 'courier', 'Fixed', Money::from_rubles( 9.1 ), null, null, DateRange::range( null, null ) ),
	$services->find_by_service_key( 'fixed_test' )
);
wdc_ds_assert( 1000 === $processed->price->get_kopecks() && ! empty( $processed->meta['minimum_price_applied'] ), 'minimum_price_rub must apply after rules.' );
$processed = $manager->post_process_rate(
	new DeliveryRate( 'rate', 'carrier', 'Carrier', 'fixed_test', 'Fixed', 'tariff', 'Tariff', 'courier', 'Fixed', Money::from_rubles( 10.01 ), null, null, DateRange::range( null, null ) ),
	$services->find_by_service_key( 'fixed_test' )
);
wdc_ds_assert( 1100 === $processed->price->get_kopecks() && ! empty( $processed->meta['round_up_applied'] ), 'round_up_to_ruble must round up after rules.' );
$processed_zero = $manager->post_process_rate(
	new DeliveryRate( 'rate', 'carrier', 'Carrier', 'fixed_test', 'Fixed', 'tariff', 'Tariff', 'courier', 'Fixed', Money::from_rubles( 0 ), null, null, DateRange::range( null, null ) ),
	$services->find_by_service_key( 'fixed_test' )
);
wdc_ds_assert( 0 === $processed_zero->price->get_kopecks(), 'Fallback zero must stay zero during service post-processing.' );
$countries->delete_countries( $custom_id );
wdc_ds_assert( array() === $countries->countries( $custom_id ), 'Country repository must delete countries.' );

$GLOBALS['wpdb']->rules[] = array( 'id' => 1, 'name' => 'Service rule', 'enabled' => 1, 'priority' => 10, 'target_type' => RuleRepository::TARGET_SERVICE, 'target_value' => 'fixed_test', 'action_type' => RuleActionTypes::CHANGE_PRICE, 'operation_type' => RuleOperationTypes::MULTIPLY, 'operation_value' => 2, 'operation_base' => RuleOperationBases::RUBLES, 'operation_text' => '', 'promo_shipping' => 0, 'stop_processing' => 0, 'condition_group_logic' => '[]', 'condition_group_expression' => Rule::DEFAULT_GROUP_EXPRESSION );
$rule_repo = new RuleRepository( $GLOBALS['wpdb'] );
$service_rules = $rule_repo->get_rules_for_service_with_default_fallback( 'fixed_test' );
wdc_ds_assert( 'service' === $service_rules['source'] && 1 === count( $service_rules['rules'] ), 'Service rules must override defaults.' );

echo "Delivery services smoke test passed.\n";
