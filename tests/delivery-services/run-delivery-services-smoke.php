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
function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['wdc_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool $autoload = true ): bool { $GLOBALS['wdc_options'][ $option ] = $value; return true; }

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
		public array $conditions = array();
		/** @var array<int,array<string,mixed>> */
		public array $queries = array();
		private int $condition_insert_id = 0;

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
			} elseif ( str_contains( $table, 'wdc_rule_conditions' ) ) {
				$data['id'] = ++$this->condition_insert_id;
				$this->conditions[] = $data;
			} elseif ( str_contains( $table, 'wdc_rules' ) ) {
				$this->rules[] = $data;
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
		public function replace( string $table, array $data, array $format = array() ): bool {
			$rows =& $this->rows_for_table( $table );
			foreach ( $rows as $index => $row ) {
				if (
					str_contains( $table, 'wdc_delivery_service_settings' )
					&& (int) ( $row['service_id'] ?? 0 ) === (int) ( $data['service_id'] ?? 0 )
					&& (string) ( $row['setting_key'] ?? '' ) === (string) ( $data['setting_key'] ?? '' )
				) {
					$rows[ $index ] = array_merge( $row, $data );
					return true;
				}
				if (
					str_contains( $table, 'wdc_delivery_service_countries' )
					&& (int) ( $row['service_id'] ?? 0 ) === (int) ( $data['service_id'] ?? 0 )
					&& (string) ( $row['country_code'] ?? '' ) === (string) ( $data['country_code'] ?? '' )
				) {
					$rows[ $index ] = array_merge( $row, $data );
					return true;
				}
			}
			return $this->insert( $table, $data, $format );
		}
		public function get_row( string $query, mixed $output = null ): ?array {
			if ( str_contains( $query, 'wdc_delivery_services' ) && preg_match( '/WHERE id = ([0-9]+)/', $query, $matches ) ) {
				foreach ( $this->services as $row ) {
					if ( (int) $row['id'] === (int) $matches[1] ) {
						return $row;
					}
				}
			}
			if ( str_contains( $query, 'wdc_rules' ) && preg_match( '/WHERE id = ([0-9]+)/', $query, $matches ) ) {
				foreach ( $this->rules as $row ) {
					if ( (int) $row['id'] === (int) $matches[1] ) {
						return $row;
					}
				}
			}
			if ( str_contains( $query, 'wdc_delivery_services' ) && preg_match( "/service_key = '([^']+)'/", $query, $matches ) ) {
				foreach ( $this->services as $row ) {
					if ( $row['service_key'] === $matches[1] && ( str_contains( $query, 'ORDER BY deleted ASC' ) || empty( $row['deleted'] ) ) ) {
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
			if ( preg_match( "/SHOW TABLES LIKE '([^']+)'/", $query, $matches ) ) {
				return $matches[1];
			}
			if ( str_contains( $query, 'wdc_delivery_services' ) && preg_match( "/service_key = '([^']+)'/", $query, $matches ) ) {
				foreach ( $this->services as $row ) {
					if ( (string) ( $row['service_key'] ?? '' ) === $matches[1] && ( ! str_contains( $query, 'deleted = 0' ) || empty( $row['deleted'] ) ) ) {
						return (int) ( $row['id'] ?? 0 );
					}
				}
			}
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
			if ( str_contains( $query, 'wdc_rule_conditions' ) ) {
				preg_match( '/rule_id = ([0-9]+)/', $query, $matches );
				$rule_id = (int) ( $matches[1] ?? 0 );
				$rows = array_values( array_filter( $this->conditions, static fn ( array $row ): bool => (int) $row['rule_id'] === $rule_id ) );
				usort( $rows, static fn ( array $a, array $b ): int => ( (int) $a['condition_group'] <=> (int) $b['condition_group'] ) ?: ( (int) $a['id'] <=> (int) $b['id'] ) );
				return $rows;
			}
			if ( str_contains( $query, 'wdc_delivery_services' ) ) {
				$rows = array_values( array_filter( $this->services, static fn ( array $row ): bool => empty( $row['deleted'] ) ) );
				if ( preg_match( "/service_key = '([^']+)'/", $query, $matches ) ) {
					$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (string) $row['service_key'] === $matches[1] ) );
				}
				return $rows;
			}
			if ( str_contains( $query, 'wdc_delivery_service_settings' ) && preg_match( '/service_id = ([0-9]+)/', $query, $matches ) ) {
				return array_values( array_filter( $this->settings, static fn ( array $row ): bool => (int) $row['service_id'] === (int) $matches[1] ) );
			}
			if ( str_contains( $query, 'wdc_rules' ) ) {
				$rows = $this->rules;
				if ( str_contains( $query, 'enabled = 1' ) ) {
					$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (int) ( $row['enabled'] ?? 0 ) === 1 ) );
				}
				if ( preg_match( "/target_type = '([^']+)'/", $query, $matches ) ) {
					$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => $row['target_type'] === $matches[1] ) );
				}
				if ( preg_match( "/target_value = '([^']*)'/", $query, $matches ) ) {
					$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => $row['target_value'] === $matches[1] ) );
				}
				usort( $rows, static fn ( array $a, array $b ): int => ( (int) $a['priority'] <=> (int) $b['priority'] ) ?: ( (int) $a['id'] <=> (int) $b['id'] ) );
				return $rows;
			}
			return array();
		}
		public function get_col( string $query ): array {
			if ( str_contains( $query, 'wdc_rules' ) ) {
				$rows = $this->rules;
				if ( preg_match( "/target_type = '([^']+)'/", $query, $matches ) ) {
					$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (string) ( $row['target_type'] ?? '' ) === $matches[1] ) );
				}
				if ( preg_match( "/target_value = '([^']+)'/", $query, $matches ) ) {
					$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (string) ( $row['target_value'] ?? '' ) === $matches[1] ) );
				}
				return array_values( array_map( static fn ( array $row ): int => (int) $row['id'], $rows ) );
			}
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
			if ( str_contains( $table, 'wdc_rule_conditions' ) ) {
				return $this->conditions;
			}
			if ( str_contains( $table, 'wdc_rules' ) ) {
				return $this->rules;
			}
			return $this->services;
		}
	}
}

function dbDelta( string $sql ): void { $GLOBALS['wdc_db_delta'][] = $sql; }

use WallsShop\WDC\Carriers\RussianPost\Admin\RussianPostPickupDiagnosticsTab;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostSettings;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\Admin\DeliveryServicesAdminPage;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceManager;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Database\MigrationManager;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleCondition;
use WallsShop\WDC\Rules\Storage\RuleRepository;
use WallsShop\WDC\Rules\ValueObjects\RuleConditionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperators;
use WallsShop\WDC\Rules\ValueObjects\RuleActionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationBases;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationTypes;

$GLOBALS['wpdb'] = new wpdb();
$migration = require dirname( __DIR__, 2 ) . '/database/migrations/0018_create_delivery_services_tables.php';
$migration();
$migration_0019 = require dirname( __DIR__, 2 ) . '/database/migrations/0019_add_delivery_service_include_packaging_weight.php';
$migration_0019();
$migration_0020 = require dirname( __DIR__, 2 ) . '/database/migrations/0020_add_delivery_service_customer_comments.php';
$migration_0020();
wdc_ds_assert( count( $GLOBALS['wdc_db_delta'] ?? array() ) === 3, 'Delivery services migration must create three tables.' );

$services = new DeliveryServiceRepository( $GLOBALS['wpdb'] );
$settings = new DeliveryServiceSettingsRepository( $GLOBALS['wpdb'] );
$countries = new DeliveryServiceCountryRepository( $GLOBALS['wpdb'] );

$rp = $services->ensure_russian_post_service();
wdc_ds_assert( RussianPostSettings::SERVICE_KEY === $rp->service_key, 'Russian Post service must be auto-created.' );
wdc_ds_assert( DeliveryService::AVAILABILITY_CARRIER_DIRECTORY === $rp->availability_mode, 'Russian Post service must use carrier_directory availability.' );
wdc_ds_assert( true === $rp->include_packaging_weight && DeliveryService::PACKAGING_WEIGHT_TOTAL_WEIGHT === $rp->packaging_weight_mode, 'Russian Post service must default to total_weight packaging.' );
wdc_ds_assert( array() === $GLOBALS['wpdb']->rules, 'Russian Post bootstrap must not auto-create rules.' );
$services->ensure_russian_post_service();
$rp_rows = array_values( array_filter( $GLOBALS['wpdb']->services, static fn ( array $row ): bool => RussianPostSettings::SERVICE_KEY === (string) $row['service_key'] && empty( $row['deleted'] ) ) );
wdc_ds_assert( 1 === count( $rp_rows ), 'Repeated Russian Post bootstrap must not create duplicate services.' );
$services->soft_delete_service( (int) $rp->id );
wdc_ds_assert( $services->find_by_service_key( RussianPostSettings::SERVICE_KEY ) instanceof DeliveryService, 'Predefined Russian Post service cannot be deleted.' );
$domestic_service = $services->ensure_russian_post_domestic_service();
$services->ensure_russian_post_domestic_service();
$domestic_rows = array_values( array_filter( $GLOBALS['wpdb']->services, static fn ( array $row ): bool => RussianPostDomesticSettings::SERVICE_KEY === (string) $row['service_key'] && empty( $row['deleted'] ) ) );
$legacy_domestic_rows = array_values( array_filter( $GLOBALS['wpdb']->services, static fn ( array $row ): bool => in_array( (string) $row['service_key'], array( 'russian_post_domestic_pickup', 'russian_post_domestic_courier' ), true ) && empty( $row['deleted'] ) ) );
wdc_ds_assert( 1 === count( $domestic_rows ) && array() === $legacy_domestic_rows, 'Repeated domestic bootstrap must create one unified domestic service and no legacy pickup/courier services.' );
$cdek = $services->ensure_cdek_service();
wdc_ds_assert( CdekSettings::SERVICE_KEY === $cdek->service_key && CdekSettings::CARRIER_KEY === $cdek->carrier_key && ! $cdek->enabled, 'CDEK predefined service must be disabled by default.' );

$run_cdek_eaeu_migration = static function ( array $seed_countries, bool $reset_applied = true ) use ( $countries, $cdek ): array {
	if ( $reset_applied ) {
		unset( $GLOBALS['wdc_options']['wdc_applied_migrations'], $GLOBALS['wdc_options']['wdc_db_version'] );
	}
	$countries->replace_countries( (int) $cdek->id, $seed_countries );
	$migration_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wdc-cdek-0042-' . uniqid( '', true );
	if ( ! mkdir( $migration_dir ) && ! is_dir( $migration_dir ) ) {
		throw new RuntimeException( 'Unable to create temp migration directory.' );
	}
	$migration_file = $migration_dir . DIRECTORY_SEPARATOR . '0042_seed_cdek_eaeu_countries.php';
	copy( dirname( __DIR__, 2 ) . '/database/migrations/0042_seed_cdek_eaeu_countries.php', $migration_file );
	( new MigrationManager( '0.128.13-test', $migration_dir ) )->run();
	@unlink( $migration_file );
	@rmdir( $migration_dir );

	return $countries->countries( (int) $cdek->id );
};

$default_cdek_countries = array( 'RU', 'AM', 'BY', 'KZ', 'KG' );
wdc_ds_assert( $default_cdek_countries === $run_cdek_eaeu_migration( array() ), 'CDEK 0042 migration must seed empty countries through MigrationManager without ArgumentCountError.' );
wdc_ds_assert( in_array( '0042_seed_cdek_eaeu_countries.php', (array) get_option( 'wdc_applied_migrations', array() ), true ), 'CDEK 0042 migration must be marked as applied.' );
wdc_ds_assert( $default_cdek_countries === $run_cdek_eaeu_migration( array( 'RU' ) ), 'CDEK 0042 migration must expand RU-only countries to EAEU defaults.' );
wdc_ds_assert( array( 'RU', 'BY' ) === $run_cdek_eaeu_migration( array( 'RU', 'BY' ) ), 'CDEK 0042 migration must preserve custom country selection.' );
unset( $GLOBALS['wdc_options']['wdc_applied_migrations'], $GLOBALS['wdc_options']['wdc_db_version'] );
$countries->replace_countries( (int) $cdek->id, array() );
$run_cdek_eaeu_migration( array(), false );
$countries->delete_countries( (int) $cdek->id );
$after_admin_empty = $run_cdek_eaeu_migration( array(), false );
wdc_ds_assert( array() === $after_admin_empty, 'CDEK 0042 migration must not reseed an admin-empty country selection after it is already applied.' );
$yandex = $services->ensure_yandex_delivery_service();
$yandex_settings = new YandexDeliverySettings( new SettingsRepository(), new EncryptionService(), $services, $settings );
wdc_ds_assert( YandexDeliverySettings::DEFAULT_PICKUP_METHOD_TITLE === $yandex_settings->pickup_method_title(), 'Yandex Delivery pickup method title must use default when service setting is absent.' );
wdc_ds_assert( YandexDeliverySettings::DEFAULT_COURIER_METHOD_TITLE === $yandex_settings->courier_method_title(), 'Yandex Delivery courier method title must use default when service setting is absent.' );
$settings->set_setting( (int) $yandex->id, YandexDeliverySettings::PICKUP_METHOD_TITLE_KEY, 'Яндекс кастом ПВЗ', 'string' );
$settings->set_setting( (int) $yandex->id, YandexDeliverySettings::COURIER_METHOD_TITLE_KEY, 'Яндекс кастом дверь', 'string' );
wdc_ds_assert( 'Яндекс кастом ПВЗ' === $yandex_settings->pickup_method_title(), 'Yandex Delivery pickup method title must return saved custom value.' );
wdc_ds_assert( 'Яндекс кастом дверь' === $yandex_settings->courier_method_title(), 'Yandex Delivery courier method title must return saved custom value.' );
$domestic = $services->find_by_service_key( RussianPostDomesticSettings::SERVICE_KEY );
wdc_ds_assert( $domestic instanceof DeliveryService && RussianPostDomesticSettings::TITLE === $domestic->title && RussianPostDomesticSettings::CARRIER_KEY === $domestic->carrier_key, 'Unified domestic predefined service must have canonical title and carrier key.' );
$services->update_service( (int) $domestic->id, array( 'title' => 'Custom domestic title' ) );
$services->ensure_russian_post_domestic_service();
$custom_title_domestic = $services->find_by_service_key( RussianPostDomesticSettings::SERVICE_KEY );
wdc_ds_assert( $custom_title_domestic instanceof DeliveryService && 'Custom domestic title' === $custom_title_domestic->title, 'Domestic bootstrap must not overwrite an admin-customized predefined title.' );
$services->soft_delete_service( (int) $domestic_service->id );
wdc_ds_assert( $services->find_by_service_key( $domestic_service->service_key ) instanceof DeliveryService, 'Predefined domestic service cannot be deleted: ' . $domestic_service->service_key );
$services->update_service( (int) $domestic_service->id, array( 'enabled' => 0 ) );
$services->ensure_russian_post_domestic_service();
$disabled_domestic = $services->find_by_service_key( RussianPostDomesticSettings::SERVICE_KEY );
wdc_ds_assert( $disabled_domestic instanceof DeliveryService && ! $disabled_domestic->enabled, 'Domestic bootstrap must preserve an intentionally disabled predefined service.' );
$GLOBALS['wpdb']->services[] = array(
	'id' => ++$GLOBALS['wpdb']->insert_id,
	'service_key' => 'russian_post_domestic_soft_deleted_test',
	'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
	'service_type' => DeliveryService::TYPE_API,
	'title' => 'Soft deleted predefined',
	'enabled' => 0,
	'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES,
	'use_default_rules_when_no_service_rules' => 1,
	'round_up_to_ruble' => 1,
	'minimum_price_rub' => 1.0,
	'include_packaging_weight' => 1,
	'packaging_weight_mode' => DeliveryService::PACKAGING_WEIGHT_TOTAL_WEIGHT,
	'pickup_customer_comment' => '',
	'courier_customer_comment' => '',
	'sort_order' => 40,
	'deleted' => 1,
	'created_at' => current_time( 'mysql' ),
	'updated_at' => current_time( 'mysql' ),
);
$ensure_builtin = ( new ReflectionClass( DeliveryServiceRepository::class ) )->getMethod( 'ensure_builtin_service' );
$ensure_builtin->setAccessible( true );
$reactivated = $ensure_builtin->invoke( $services, 'russian_post_domestic_soft_deleted_test', RussianPostDomesticSettings::CARRIER_KEY, 'Soft deleted predefined', 40 );
wdc_ds_assert( $reactivated instanceof DeliveryService && $services->find_by_service_key( 'russian_post_domestic_soft_deleted_test' ) instanceof DeliveryService, 'Soft-deleted predefined bootstrap row must be reactivated.' );
$reactivated_row = $services->find_by_service_key( 'russian_post_domestic_soft_deleted_test' );
wdc_ds_assert( $reactivated_row instanceof DeliveryService && ! $reactivated_row->enabled, 'Soft-deleted predefined bootstrap row must not be forced enabled.' );

$custom_id = $services->create_service( array( 'service_key' => 'fixed_test', 'service_type' => DeliveryService::TYPE_FIXED, 'title' => 'Fixed', 'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES ) );
$services->update_service( $custom_id, array( 'enabled' => 0, 'minimum_price_rub' => '10,5' ) );
$custom = $services->find_by_service_key( 'fixed_test' );
wdc_ds_assert( $custom instanceof DeliveryService && ! $custom->enabled && 10.5 === $custom->minimum_price_rub, 'Service CRUD must update enabled and decimal fields.' );
$services->update_service( $custom_id, array( 'minimum_price_rub' => '-5,25' ) );
$custom = $services->find_by_service_key( 'fixed_test' );
wdc_ds_assert( $custom instanceof DeliveryService && 0.0 === $custom->minimum_price_rub, 'minimum_price_rub must clamp negative values to zero.' );
$services->update_service( $custom_id, array( 'pickup_customer_comment' => "ПВЗ\nкомментарий", 'courier_customer_comment' => 'Курьерский комментарий' ) );
$custom = $services->find_by_service_key( 'fixed_test' );
wdc_ds_assert( $custom instanceof DeliveryService && "ПВЗ\nкомментарий" === $custom->pickup_customer_comment && 'Курьерский комментарий' === $custom->courier_customer_comment, 'Service customer comments must save and load.' );
$services->update_service( $custom_id, array( 'pickup_customer_comment' => '', 'courier_customer_comment' => '' ) );
$custom = $services->find_by_service_key( 'fixed_test' );
wdc_ds_assert( $custom instanceof DeliveryService && '' === $custom->pickup_customer_comment && '' === $custom->courier_customer_comment, 'Service customer comments may be empty.' );

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
$manager->ensure_builtin_services();
$unified_domestic_service = $services->find_by_service_key( RussianPostDomesticSettings::SERVICE_KEY );
wdc_ds_assert( $unified_domestic_service instanceof DeliveryService && in_array( 'RU', $countries->countries( (int) $unified_domestic_service->id ), true ) && $manager->service_available_for_country( $unified_domestic_service, 'RU' ), 'Unified domestic service must bootstrap RU availability.' );
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
$rp_service = $services->find_by_service_key( RussianPostSettings::SERVICE_KEY );
wdc_ds_assert( $rp_service instanceof DeliveryService && $manager->service_available_for_country( $rp_service, 'PL' ), 'Russian Post carrier_directory service must stay available so carrier can return disabled-country fallback.' );
wdc_ds_assert( $rp_service instanceof DeliveryService && ! $manager->service_available_for_country( $rp_service, 'RU' ), 'Russian Post international service must still reject RU.' );

$orchestrator_reflection = new ReflectionClass( CheckoutOrchestrator::class );
$orchestrator = $orchestrator_reflection->newInstanceWithoutConstructor();
$rate_for_service = $orchestrator_reflection->getMethod( 'rate_for_service' );
$rate_for_service->setAccessible( true );
$comment_service = DeliveryService::from_array(
	array(
		'service_key' => 'commented',
		'carrier_key' => 'carrier',
		'title' => 'Commented',
		'pickup_customer_comment' => 'Комментарий ПВЗ',
		'courier_customer_comment' => 'Комментарий курьера',
	)
);
$pickup_rate = $rate_for_service->invoke( $orchestrator, new DeliveryRate( 'rate', 'carrier', 'Carrier', 'svc', 'Svc', 'tariff', 'Tariff', DeliveryType::PICKUP, 'Svc', Money::from_rubles( 100 ), null, null, DateRange::range( null, null ), '', '', array( 'Комментарий правила' ) ), $comment_service );
wdc_ds_assert( array( 'Комментарий ПВЗ', 'Комментарий правила' ) === $pickup_rate->comments && 'yes' === $pickup_rate->meta['service_customer_comment_applied'] && DeliveryType::PICKUP === $pickup_rate->meta['service_customer_comment_type'], 'Pickup service customer comment must be prepended before rule comments.' );
wdc_ds_assert( ! in_array( 'Комментарий курьера', $pickup_rate->comments, true ), 'Courier customer comment must not appear on pickup rates.' );
$courier_rate = $rate_for_service->invoke( $orchestrator, new DeliveryRate( 'rate', 'carrier', 'Carrier', 'svc', 'Svc', 'tariff', 'Tariff', DeliveryType::COURIER, 'Svc', Money::from_rubles( 100 ), null, null, DateRange::range( null, null ) ), $comment_service );
wdc_ds_assert( array( 'Комментарий курьера' ) === $courier_rate->comments && DeliveryType::COURIER === $courier_rate->meta['service_customer_comment_type'], 'Courier service customer comment must apply only to courier rates.' );
wdc_ds_assert( ! in_array( 'Комментарий ПВЗ', $courier_rate->comments, true ), 'Pickup customer comment must not appear on courier rates.' );
$fallback_rate = $rate_for_service->invoke( $orchestrator, new DeliveryRate( 'rate', 'carrier', 'Carrier', 'svc', 'Svc', 'fallback', 'Fallback', DeliveryType::PICKUP, 'fallback text', Money::from_rubles( 0 ), null, null, DateRange::range( null, null ), '', '', array(), false, '', false, false, array( 'fallback' => true, 'skip_service_post_processing' => true ) ), $comment_service );
wdc_ds_assert( 'fallback text' === $fallback_rate->title && array() === $fallback_rate->comments && 'no' === $fallback_rate->meta['service_customer_comment_applied'], 'Fallback rate must keep fallback_text as title without service customer comments.' );
$processed_fallback = $manager->post_process_rate(
	new DeliveryRate( 'rate', 'carrier', 'Carrier', 'fixed_test', 'Fixed', 'fallback', 'Fallback', DeliveryType::PICKUP, 'fallback text', Money::from_rubles( 0 ), null, null, DateRange::range( null, null ), '', '', array(), false, '', false, false, array( 'fallback' => true, 'skip_service_post_processing' => true ) ),
	$services->find_by_service_key( 'fixed_test' )
);
wdc_ds_assert( 0 === $processed_fallback->price->get_kopecks() && empty( $processed_fallback->meta['minimum_price_applied'] ) && empty( $processed_fallback->meta['round_up_applied'] ), 'Service post-processing must not change fallback price.' );
$countries->delete_countries( $custom_id );
wdc_ds_assert( array() === $countries->countries( $custom_id ), 'Country repository must delete countries.' );

$GLOBALS['wpdb']->rules[] = array( 'id' => 1, 'name' => 'Service rule', 'enabled' => 1, 'priority' => 10, 'target_type' => RuleRepository::TARGET_SERVICE, 'target_value' => 'fixed_test', 'action_type' => RuleActionTypes::CHANGE_PRICE, 'operation_type' => RuleOperationTypes::MULTIPLY, 'operation_value' => 2, 'operation_base' => RuleOperationBases::RUBLES, 'operation_text' => '', 'promo_shipping' => 0, 'stop_processing' => 0, 'condition_group_logic' => '[]', 'condition_group_expression' => Rule::DEFAULT_GROUP_EXPRESSION );
$GLOBALS['wpdb']->rules[] = array( 'id' => 2, 'name' => 'Default rule', 'enabled' => 1, 'priority' => 20, 'target_type' => RuleRepository::TARGET_DEFAULT, 'target_value' => '', 'action_type' => RuleActionTypes::CHANGE_PRICE, 'operation_type' => RuleOperationTypes::DECREASE, 'operation_value' => 100, 'operation_base' => RuleOperationBases::RUBLES, 'operation_text' => '', 'promo_shipping' => 0, 'stop_processing' => 0, 'condition_group_logic' => '[]', 'condition_group_expression' => Rule::DEFAULT_GROUP_EXPRESSION );
$GLOBALS['wpdb']->rules[] = array( 'id' => 3, 'name' => 'Disabled service rule', 'enabled' => 0, 'priority' => 10, 'target_type' => RuleRepository::TARGET_SERVICE, 'target_value' => 'disabled_only_service', 'action_type' => RuleActionTypes::CHANGE_PRICE, 'operation_type' => RuleOperationTypes::MULTIPLY, 'operation_value' => 2, 'operation_base' => RuleOperationBases::RUBLES, 'operation_text' => '', 'promo_shipping' => 0, 'stop_processing' => 0, 'condition_group_logic' => '[]', 'condition_group_expression' => Rule::DEFAULT_GROUP_EXPRESSION );
$GLOBALS['wpdb']->conditions[] = array( 'id' => 1, 'rule_id' => 2, 'condition_group' => 1, 'condition_type' => RuleConditionTypes::COUNTRY, 'operator' => RuleOperators::EQ, 'value_text' => 'RU', 'value_number' => null, 'value_json' => '{}' );
$rule_repo = new RuleRepository( $GLOBALS['wpdb'] );
$service_rules = $rule_repo->get_rules_for_service_with_default_fallback( 'fixed_test' );
wdc_ds_assert( 'service' === $service_rules['source'] && 1 === count( $service_rules['rules'] ), 'Service rules must override defaults.' );
$disabled_only_rules = $rule_repo->get_rules_for_service_with_default_fallback( 'disabled_only_service', true );
wdc_ds_assert( 'default' === $disabled_only_rules['source'] && 1 === count( $disabled_only_rules['rules'] ) && 2 === $disabled_only_rules['rules'][0]->id, 'Only disabled service rules must fall back to defaults when fallback is enabled.' );
$no_fallback_rules = $rule_repo->get_rules_for_service_with_default_fallback( 'disabled_only_service', false );
wdc_ds_assert( 'none' === $no_fallback_rules['source'] && array() === $no_fallback_rules['rules'], 'Disabled service rules must not count as own rules when fallback is disabled.' );

$admin_reflection = new ReflectionClass( DeliveryServicesAdminPage::class );
$admin_page = $admin_reflection->newInstanceWithoutConstructor();
$rules_property = $admin_reflection->getProperty( 'rules' );
$rules_property->setAccessible( true );
$rules_property->setValue( $admin_page, $rule_repo );
$copy_method = $admin_reflection->getMethod( 'copy_default_rules_to_service' );
$copy_method->setAccessible( true );
$copy_method->invoke( $admin_page, $services->find_by_service_key( 'fixed_test' ) );
$copied_rules = $rule_repo->get_all_rules_for_target( RuleRepository::TARGET_SERVICE, 'fixed_test' );
wdc_ds_assert( 2 === count( $copied_rules ), 'Copy default rules must append service rules without deleting existing service rules.' );
$copied_rule = $copied_rules[1];
wdc_ds_assert( 2 !== $copied_rule->id && RuleRepository::TARGET_SERVICE === $copied_rule->target_type && 'fixed_test' === $copied_rule->target_value, 'Copied rule must get a new id and belong to the service target.' );
wdc_ds_assert( RuleOperationTypes::DECREASE === $copied_rule->operation_type && 100.0 === $copied_rule->operation_value, 'Copied rule must preserve operation.' );
wdc_ds_assert( 1 === count( $copied_rule->conditions ) && RuleConditionTypes::COUNTRY === $copied_rule->conditions[0]->condition_type && 'RU' === $copied_rule->conditions[0]->value_text, 'Copied rule must preserve conditions.' );
wdc_ds_assert( 1 === count( $rule_repo->get_all_rules_for_target( RuleRepository::TARGET_DEFAULT, '' ) ), 'Copy default rules must leave default rules unchanged.' );

$old_pickup_service_id = ++$GLOBALS['wpdb']->insert_id;
$old_courier_service_id = ++$GLOBALS['wpdb']->insert_id;
$GLOBALS['wpdb']->services[] = array(
	'id' => $old_pickup_service_id,
	'service_key' => 'russian_post_domestic_pickup',
	'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
	'service_type' => DeliveryService::TYPE_API,
	'title' => 'Почта России до ПВЗ / ОПС',
	'enabled' => 1,
	'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES,
	'use_default_rules_when_no_service_rules' => 1,
	'round_up_to_ruble' => 1,
	'minimum_price_rub' => 1.0,
	'include_packaging_weight' => 1,
	'packaging_weight_mode' => DeliveryService::PACKAGING_WEIGHT_TOTAL_WEIGHT,
	'pickup_customer_comment' => 'legacy pickup',
	'courier_customer_comment' => '',
	'sort_order' => 20,
	'deleted' => 0,
	'created_at' => current_time( 'mysql' ),
	'updated_at' => current_time( 'mysql' ),
);
$GLOBALS['wpdb']->services[] = array(
	'id' => $old_courier_service_id,
	'service_key' => 'russian_post_domestic_courier',
	'carrier_key' => RussianPostDomesticSettings::CARRIER_KEY,
	'service_type' => DeliveryService::TYPE_API,
	'title' => 'Почта России курьером',
	'enabled' => 1,
	'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES,
	'use_default_rules_when_no_service_rules' => 1,
	'round_up_to_ruble' => 1,
	'minimum_price_rub' => 1.0,
	'include_packaging_weight' => 1,
	'packaging_weight_mode' => DeliveryService::PACKAGING_WEIGHT_TOTAL_WEIGHT,
	'pickup_customer_comment' => '',
	'courier_customer_comment' => 'legacy courier',
	'sort_order' => 21,
	'deleted' => 0,
	'created_at' => current_time( 'mysql' ),
	'updated_at' => current_time( 'mysql' ),
);
$GLOBALS['wpdb']->settings[] = array(
	'id' => ++$GLOBALS['wpdb']->insert_id,
	'service_id' => $old_pickup_service_id,
	'setting_key' => 'tariff_variants',
	'setting_value' => wp_json_encode(
		array(
			array(
				'object_code' => '23030',
				'delivery_type' => DeliveryType::PICKUP,
				'enabled' => true,
				'is_ecom' => true,
				'requires_declared_value' => true,
				'title' => 'Pickup legacy tariff',
			),
		),
		JSON_UNESCAPED_UNICODE
	),
	'value_format' => 'json',
	'autoload' => 0,
	'updated_at' => current_time( 'mysql' ),
);
$GLOBALS['wpdb']->settings[] = array(
	'id' => ++$GLOBALS['wpdb']->insert_id,
	'service_id' => $old_pickup_service_id,
	'setting_key' => 'russian_post_point_type_ops_enabled',
	'setting_value' => '1',
	'value_format' => 'bool',
	'autoload' => 0,
	'updated_at' => current_time( 'mysql' ),
);
$GLOBALS['wpdb']->settings[] = array(
	'id' => ++$GLOBALS['wpdb']->insert_id,
	'service_id' => $old_courier_service_id,
	'setting_key' => 'tariff_variants',
	'setting_value' => wp_json_encode(
		array(
			array(
				'object_code' => '24030',
				'delivery_type' => DeliveryType::COURIER,
				'enabled' => false,
				'is_ecom' => false,
				'requires_declared_value' => false,
				'title' => 'Courier legacy tariff',
			),
		),
		JSON_UNESCAPED_UNICODE
	),
	'value_format' => 'json',
	'autoload' => 0,
	'updated_at' => current_time( 'mysql' ),
);
$GLOBALS['wpdb']->settings[] = array(
	'id' => ++$GLOBALS['wpdb']->insert_id,
	'service_id' => $old_courier_service_id,
	'setting_key' => 'shelf_life_days_default',
	'setting_value' => '15',
	'value_format' => 'int',
	'autoload' => 0,
	'updated_at' => current_time( 'mysql' ),
);
$GLOBALS['wpdb']->countries[] = array( 'id' => ++$GLOBALS['wpdb']->insert_id, 'service_id' => $old_pickup_service_id, 'country_code' => 'RU', 'created_at' => current_time( 'mysql' ) );
$GLOBALS['wpdb']->countries[] = array( 'id' => ++$GLOBALS['wpdb']->insert_id, 'service_id' => $old_courier_service_id, 'country_code' => 'RU', 'created_at' => current_time( 'mysql' ) );
$GLOBALS['wpdb']->rules[] = array( 'id' => 501, 'name' => 'Legacy pickup rule', 'enabled' => 1, 'priority' => 1, 'target_type' => RuleRepository::TARGET_SERVICE, 'target_value' => 'russian_post_domestic_pickup', 'action_type' => RuleActionTypes::CHANGE_PRICE, 'operation_type' => RuleOperationTypes::INCREASE, 'operation_value' => 1, 'operation_base' => RuleOperationBases::RUBLES, 'operation_text' => '', 'promo_shipping' => 0, 'stop_processing' => 0, 'condition_group_logic' => '[]', 'condition_group_expression' => Rule::DEFAULT_GROUP_EXPRESSION );
$GLOBALS['wpdb']->rules[] = array( 'id' => 502, 'name' => 'Legacy courier rule', 'enabled' => 1, 'priority' => 1, 'target_type' => RuleRepository::TARGET_SERVICE, 'target_value' => 'russian_post_domestic_courier', 'action_type' => RuleActionTypes::CHANGE_PRICE, 'operation_type' => RuleOperationTypes::INCREASE, 'operation_value' => 1, 'operation_base' => RuleOperationBases::RUBLES, 'operation_text' => '', 'promo_shipping' => 0, 'stop_processing' => 0, 'condition_group_logic' => '[]', 'condition_group_expression' => Rule::DEFAULT_GROUP_EXPRESSION );
$GLOBALS['wpdb']->conditions[] = array( 'id' => 501, 'rule_id' => 501, 'condition_group' => 1, 'condition_type' => RuleConditionTypes::COUNTRY, 'operator' => RuleOperators::EQ, 'value_text' => 'RU', 'value_number' => null, 'value_json' => '{}' );
$GLOBALS['wpdb']->conditions[] = array( 'id' => 502, 'rule_id' => 502, 'condition_group' => 1, 'condition_type' => RuleConditionTypes::COUNTRY, 'operator' => RuleOperators::EQ, 'value_text' => 'RU', 'value_number' => null, 'value_json' => '{}' );
$GLOBALS['wdc_options']['wdc_core_settings'] = array(
	'russian_post_otpravka_access_token' => 'token-from-core',
	'russian_post_tracking_login' => 'tracking-login',
);

$migration_0026 = require dirname( __DIR__, 2 ) . '/database/migrations/0026_unify_russian_post_domestic_service.php';
$migration_0026();
$migration_0026();

$legacy_after_migration = array_values( array_filter( $GLOBALS['wpdb']->services, static fn ( array $row ): bool => in_array( (string) $row['service_key'], array( 'russian_post_domestic_pickup', 'russian_post_domestic_courier' ), true ) ) );
wdc_ds_assert( array() === $legacy_after_migration, 'Migration 0026 must physically delete legacy Russian Post domestic service rows.' );
wdc_ds_assert( array() === array_values( array_filter( $GLOBALS['wpdb']->settings, static fn ( array $row ): bool => in_array( (int) $row['service_id'], array( $old_pickup_service_id, $old_courier_service_id ), true ) ) ), 'Migration 0026 must physically delete legacy Russian Post domestic settings rows.' );
wdc_ds_assert( array() === array_values( array_filter( $GLOBALS['wpdb']->countries, static fn ( array $row ): bool => in_array( (int) $row['service_id'], array( $old_pickup_service_id, $old_courier_service_id ), true ) ) ), 'Migration 0026 must physically delete legacy Russian Post domestic country rows.' );
wdc_ds_assert( array() === array_values( array_filter( $GLOBALS['wpdb']->rules, static fn ( array $row ): bool => in_array( (string) $row['target_value'], array( 'russian_post_domestic_pickup', 'russian_post_domestic_courier' ), true ) ) ), 'Migration 0026 must delete rule bindings for legacy Russian Post domestic services.' );
wdc_ds_assert( array() === array_values( array_filter( $GLOBALS['wpdb']->conditions, static fn ( array $row ): bool => in_array( (int) $row['rule_id'], array( 501, 502 ), true ) ) ), 'Migration 0026 must delete rule conditions for legacy Russian Post domestic services.' );

$migrated_domestic = $services->find_by_service_key( RussianPostDomesticSettings::SERVICE_KEY );
wdc_ds_assert( $migrated_domestic instanceof DeliveryService, 'Migration 0026 must keep unified Russian Post domestic service.' );
$migrated_settings = $settings->all_settings( (int) $migrated_domestic->id );
$migrated_tariffs = is_array( $migrated_settings['tariff_variants'] ?? null ) ? $migrated_settings['tariff_variants'] : array();
$migrated_tariff_keys = array_map( static fn ( array $variant ): string => (string) ( $variant['delivery_type'] ?? '' ) . ':' . (string) ( $variant['object_code'] ?? '' ), $migrated_tariffs );
wdc_ds_assert( in_array( 'pickup:23030', $migrated_tariff_keys, true ) && in_array( 'courier:24030', $migrated_tariff_keys, true ), 'Migration 0026 must merge pickup and courier tariff variants into unified service settings.' );
wdc_ds_assert( '1' === (string) ( $migrated_settings['russian_post_domestic_point_type_ops_enabled'] ?? '' ), 'Migration 0026 must migrate Russian Post point type settings to unified keys.' );
wdc_ds_assert( '15' === (string) ( $migrated_settings['shelf_life_days_default'] ?? '' ), 'Migration 0026 must preserve shipment settings in unified service settings.' );
wdc_ds_assert( 'token-from-core' === (string) ( $migrated_settings['russian_post_otpravka_access_token'] ?? '' ) && 'tracking-login' === (string) ( $migrated_settings['russian_post_tracking_login'] ?? '' ), 'Migration 0026 must copy Russian Post credentials into unified service settings.' );
wdc_ds_assert( in_array( 'RU', $countries->countries( (int) $migrated_domestic->id ), true ), 'Migration 0026 must keep RU country on unified Russian Post domestic service.' );
wdc_ds_assert( null === $services->find_by_service_key( 'russian_post_domestic_pickup' ) && null === $services->find_by_service_key( 'russian_post_domestic_courier' ), 'Runtime repository must not find legacy Russian Post domestic service keys after migration 0026.' );

$delivery_admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'render_main_tab' ) && ! str_contains( $delivery_admin_source, 'render_availability_tab' ) && str_contains( $delivery_admin_source, 'render_calculation_tab' ), 'Delivery service admin must render availability fields inside main tab and not expose a separate availability tab.' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'RussianPostPickupDiagnosticsTab::TAB_KEY' ) && str_contains( $delivery_admin_source, 'Диагностика базы ПВЗ' ) && str_contains( $delivery_admin_source, 'render_russian_post_pickup_diagnostics_tab' ), 'Russian Post domestic admin must expose the pickup diagnostics tab and delegate rendering to the specialized tab component.' );
$domestic_tabs_source = substr( $delivery_admin_source, (int) strpos( $delivery_admin_source, 'if ( $this->is_domestic_service( $service ) )' ), 700 );
$cdek_tabs_source = substr( $delivery_admin_source, (int) strpos( $delivery_admin_source, 'if ( $this->is_cdek_service( $service ) )' ), 450 );
$yandex_tabs_source = substr( $delivery_admin_source, (int) strpos( $delivery_admin_source, 'if ( $this->is_yandex_delivery_service( $service ) )' ), 450 );
wdc_ds_assert( str_contains( $domestic_tabs_source, 'RussianPostPickupDiagnosticsTab::TAB_KEY' ) && ! str_contains( $cdek_tabs_source, 'RussianPostPickupDiagnosticsTab::TAB_KEY' ) && ! str_contains( $yandex_tabs_source, 'RussianPostPickupDiagnosticsTab::TAB_KEY' ), 'Pickup diagnostics tab must be added only for Russian Post domestic service tabs.' );
wdc_ds_assert( str_contains( $delivery_admin_source, "'save_availability' => 'main'" ) && str_contains( $delivery_admin_source, 'sanitize_availability_data' ), 'Legacy availability save action must redirect to main while preserving backend save handling.' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'render_embedded_for_context' ), 'Service rules tab must use embedded reusable rules UI.' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'render_russian_post_countries_tab' ) && str_contains( $delivery_admin_source, 'Страны Почты России' ), 'Russian Post countries must be embedded as a service tab.' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'save_russian_post_settings' ) && str_contains( $delivery_admin_source, 'DeliveryServiceSettingsRepository' ), 'Russian Post calculation settings must save to delivery service settings storage.' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'save_russian_post_domestic_api_settings' ) && str_contains( $delivery_admin_source, 'Tariff API endpoint' ) && str_contains( $delivery_admin_source, 'Tariff API token, если выдан Почтой' ), 'Domestic tariff API endpoint/token must save from API / Credentials because the client uses Authorization when token is configured.' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'Индекс отправки для расчета доставки' ) && str_contains( $delivery_admin_source, 'Индекс возврата для расчета доставки' ) && ! str_contains( $delivery_admin_source, 'Индексы отделений для отправки' ), 'Domestic calculation index labels must clarify tariff calculation usage.' );
wdc_ds_assert( str_contains( $delivery_admin_source, "'default_from_postcode' => array( 'value' => \$string( 'rp_default_from_postcode'" ) && strpos( $delivery_admin_source, 'POSTOFFICE_CODES_KEY' ) < strpos( $delivery_admin_source, 'rp_default_from_postcode' ), 'default_from_postcode must save from API / Credentials after postoffice codes.' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'pickup_method_title' ) && str_contains( $delivery_admin_source, 'courier_method_title' ) && str_contains( $delivery_admin_source, 'Название варианта до ПВЗ / ОПС' ), 'Domestic pickup/courier method titles must be configurable on the main tab.' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'save_cdek_main_settings' ) && str_contains( $delivery_admin_source, 'sanitize_cdek_main_settings_from_post' ) && str_contains( $delivery_admin_source, 'Название варианта до пункта выдачи' ), 'CDEK pickup/courier method titles must be configurable on the main tab.' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'save_yandex_delivery_main_settings' ) && str_contains( $delivery_admin_source, 'sanitize_yandex_delivery_main_settings_from_post' ) && str_contains( $delivery_admin_source, 'YandexDeliverySettings::PICKUP_METHOD_TITLE_KEY' ) && str_contains( $delivery_admin_source, 'YandexDeliverySettings::COURIER_METHOD_TITLE_KEY' ) && str_contains( $delivery_admin_source, 'YandexDeliverySettings::DEFAULT_PICKUP_METHOD_TITLE' ) && str_contains( $delivery_admin_source, 'YandexDeliverySettings::DEFAULT_COURIER_METHOD_TITLE' ), 'Yandex Delivery pickup/courier method titles must be configurable on the main tab.' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'CdekSettings::DEFAULT_PICKUP_METHOD_TITLE' ) && str_contains( $delivery_admin_source, 'CdekSettings::DEFAULT_COURIER_METHOD_TITLE' ), 'CDEK method title defaults must come from CdekSettings.' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'simulate_service_rules' ) && str_contains( $delivery_admin_source, 'QuoteRequest' ) && str_contains( $delivery_admin_source, 'RussianPostInternationalCarrier' ), 'Russian Post service rules simulation must call the real carrier quote flow.' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'include_packaging_weight' ) && str_contains( $delivery_admin_source, 'packaging_weight_mode' ) && ! str_contains( $delivery_admin_source, 'rp_packaging_tiers' ), 'Delivery service calculation tab must expose packaging controls and not Russian Post packaging tiers.' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'tariff_admin_comment' ) && str_contains( $delivery_admin_source, 'admin_comment' ), 'Domestic tariffs tab must save an internal admin comment.' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'Прибавлять к общему весу посылки' ) && str_contains( $delivery_admin_source, 'Добавлять отдельной строкой «Упаковка»' ), 'Packaging mode select must render Russian labels while storing technical values.' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'pickup_customer_comment' ) && str_contains( $delivery_admin_source, 'courier_customer_comment' ) && str_contains( $delivery_admin_source, 'Комментарий для покупателя — доставка до ПВЗ' ) && str_contains( $delivery_admin_source, 'Комментарий для покупателя — курьерская доставка' ), 'Delivery service calculation tab must expose pickup/courier customer comments.' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'Справочник перевозчика' ) && str_contains( $delivery_admin_source, 'Только выбранные страны' ) && str_contains( $delivery_admin_source, 'Все страны, кроме выбранных' ) && str_contains( $delivery_admin_source, 'AVAILABILITY_CARRIER_DIRECTORY' ), 'Availability select must render Russian labels while storing technical values.' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'Минимальная цена, руб.' ) && str_contains( $delivery_admin_source, 'Ставка НДС' ), 'Calculation tab must render translated labels.' );
wdc_ds_assert( ! str_contains( $delivery_admin_source, 'Minimum price RUB' ) && ! str_contains( $delivery_admin_source, 'VAT rate' ), 'Calculation tab must not render old English labels.' );

$settings_page_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Admin/SettingsAdminPage.php' );
wdc_ds_assert( ! str_contains( $settings_page_source, 'russian_post_worldwide_parcel[' ), 'Platform settings page must not render Russian Post service-specific fields.' );

$rules_admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Rules/Admin/RulesAdminPage.php' );
preg_match( '/private function render_service_simulation_form\(\): void \{(?P<body>.*?)private function render_service_simulation/s', $rules_admin_source, $service_simulation_match );
$service_simulation_body = (string) ( $service_simulation_match['body'] ?? '' );
wdc_ds_assert( str_contains( $service_simulation_body, 'postal_code' ) && str_contains( $service_simulation_body, 'Почтовый индекс назначения' ), 'Domestic service simulation form must expose destination postcode.' );
wdc_ds_assert( str_contains( $service_simulation_body, 'name="simulation[location_fias_id]"' ) && str_contains( $service_simulation_body, 'name="simulation[city]"' ) && str_contains( $service_simulation_body, 'simulation[country]' ) && str_contains( $service_simulation_body, 'simulation[selected_location_id]' ) && str_contains( $service_simulation_body, 'simulation[delivery_type]' ) && str_contains( $service_simulation_body, 'simulation[length_cm]' ), 'Service simulation form must expose carrier-aware destination, delivery type and package dimensions.' );
wdc_ds_assert( str_contains( $delivery_admin_source, 'simulate_runtime_carrier_service_rules' ) && str_contains( $delivery_admin_source, 'DpdQuoteCarrier' ) && str_contains( $delivery_admin_source, 'YandexDeliveryCarrier' ), 'DPD and Yandex Delivery service simulations must use the shared runtime carrier quote runner.' );

$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
wdc_ds_assert( ! str_contains( $plugin_source, 'RussianPostCountriesAdminPage::class )->register()' ), 'Russian Post countries submenu must not be registered separately.' );
wdc_ds_assert( str_contains( $plugin_source, 'RussianPostPickupDiagnosticsTab::class' ) && str_contains( $plugin_source, 'RussianPostPickupDiagnosticsService::class' ) && ! str_contains( $plugin_source, 'Pickup' . 'AdminPage' ), 'Composition root must wire pickup diagnostics as a service tab and not as a standalone admin page.' );

$countries_admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/RussianPost/Admin/RussianPostCountriesAdminPage.php' );
wdc_ds_assert( str_contains( $countries_admin_source, 'render_embedded( string $return_url )' ) && str_contains( $countries_admin_source, 'wdc-rp-countries-admin' ), 'Russian Post countries admin must support embedded rendering without a WordPress wrap.' );
wdc_ds_assert( str_contains( $countries_admin_source, 'hidden_return_field' ) && str_contains( $countries_admin_source, 'redirect_after_post' ) && str_contains( $countries_admin_source, 'wdc_rp_return_url' ), 'Russian Post countries actions must redirect back to the service tab.' );
wdc_ds_assert( ! str_contains( $countries_admin_source, 'PAGE_SLUG' ) && ! str_contains( $countries_admin_source, 'function register(' ) && ! str_contains( $countries_admin_source, 'function add_menu_page(' ) && ! str_contains( $countries_admin_source, 'function render_page(' ) && ! str_contains( $countries_admin_source, 'wdc-russian-post-countries' ), 'Russian Post countries admin must not expose a standalone admin page surface.' );
wdc_ds_assert( str_contains( $countries_admin_source, 'render_body( string $message )' ) && ! str_contains( $countries_admin_source, '$wrap' ) && ! str_contains( $countries_admin_source, '<div class="wrap"' ), 'Russian Post countries admin must not keep dead standalone wrap rendering.' );

echo "Delivery services smoke test passed.\n";
