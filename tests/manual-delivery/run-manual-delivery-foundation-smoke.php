<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function wdc_manual_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-09-04 12:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', (string) $value ) ?? '' ); }
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
		public array $manual_regions = array();
		/** @var array<int,array<string,mixed>> */
		public array $manual_locations = array();
		/** @var array<int,array<string,mixed>> */
		public array $manual_weight_ranges = array();
		/** @var array<int,array<string,mixed>> */
		public array $locations = array();
		/** @var array<int,array<string,mixed>> */
		public array $rules = array();
		/** @var array<int,array<string,mixed>> */
		public array $conditions = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[sdf]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function insert( string $table, array $data, array $format = array() ): bool {
			$data['id'] = ++$this->insert_id;
			$this->rows_for_table( $table )[] = $data;
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

		public function query( string $query ): bool {
			if ( str_contains( $query, 'wdc_delivery_service_countries' ) && str_starts_with( strtoupper( trim( $query ) ), 'DELETE ' ) ) {
				$this->countries = array();
			}
			if ( str_contains( $query, 'wdc_delivery_service_countries' ) && preg_match_all( "/\\(([0-9]+), '([^']+)', '([^']+)'\\)/", $query, $matches, PREG_SET_ORDER ) ) {
				foreach ( $matches as $match ) {
					$this->countries[] = array( 'id' => ++$this->insert_id, 'service_id' => (int) $match[1], 'country_code' => $match[2], 'created_at' => $match[3] );
				}
			}
			if ( str_contains( $query, 'wdc_manual_delivery_regions' ) && preg_match( "/VALUES \\(([0-9]+), '([^']+)', '([^']+)', '([^']+)'\\)/", $query, $match ) ) {
				foreach ( $this->manual_regions as $row ) {
					if ( (int) $row['service_id'] === (int) $match[1] && (string) ( $row['country_code'] ?? 'RU' ) === $match[2] && $row['region_name'] === $match[3] ) {
						return true;
					}
				}
				$this->manual_regions[] = array( 'id' => ++$this->insert_id, 'service_id' => (int) $match[1], 'country_code' => $match[2], 'region_name' => $match[3], 'created_at' => $match[4] );
			}
			if ( str_contains( $query, 'wdc_manual_delivery_locations' ) && preg_match( "/VALUES \\(([0-9]+), '([^']+)', '([^']+)', '([^']+)', '([^']+)'\\)/", $query, $match ) ) {
				foreach ( $this->manual_locations as $row ) {
					if ( (int) $row['service_id'] === (int) $match[1] && (string) ( $row['country_code'] ?? 'RU' ) === $match[2] && $row['location_name'] === $match[3] && $row['region_name'] === $match[4] ) {
						return true;
					}
				}
				$this->manual_locations[] = array( 'id' => ++$this->insert_id, 'service_id' => (int) $match[1], 'country_code' => $match[2], 'location_name' => $match[3], 'region_name' => $match[4], 'created_at' => $match[5] );
			}
			return true;
		}

		public function get_row( string $query, mixed $output = null ): ?array {
			if ( str_contains( $query, 'wdc_delivery_services' ) && preg_match( '/WHERE id = ([0-9]+)/', $query, $matches ) ) {
				foreach ( $this->services as $row ) {
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
			if ( str_contains( $query, 'wdc_rules' ) ) {
				$rows = $this->rules;
				if ( str_contains( $query, 'enabled = 1' ) ) {
					$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => ! empty( $row['enabled'] ) ) );
				}
				if ( preg_match( "/target_type = '([^']+)'/", $query, $matches ) ) {
					$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (string) $row['target_type'] === $matches[1] ) );
				}
				if ( preg_match( "/target_value = '([^']*)'/", $query, $matches ) ) {
					$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (string) $row['target_value'] === $matches[1] ) );
				}
				usort( $rows, static fn ( array $left, array $right ): int => ( (int) $left['priority'] <=> (int) $right['priority'] ) ?: ( (int) $left['id'] <=> (int) $right['id'] ) );
				return $rows;
			}
			if ( str_contains( $query, 'wdc_manual_delivery_locations' ) && preg_match( '/service_id = ([0-9]+)/', $query, $matches ) ) {
				$country = preg_match( "/country_code = '([^']+)'/", $query, $country_match ) ? $country_match[1] : '';
				$rows = array_values( array_filter( $this->manual_locations, static fn ( array $row ): bool => (int) $row['service_id'] === (int) $matches[1] && ( '' === $country || (string) ( $row['country_code'] ?? 'RU' ) === $country ) ) );
				usort( $rows, static fn ( array $left, array $right ): int => strcmp( (string) ( $left['country_code'] ?? 'RU' ), (string) ( $right['country_code'] ?? 'RU' ) ) ?: strcmp( (string) $left['region_name'], (string) $right['region_name'] ) ?: strcmp( (string) $left['location_name'], (string) $right['location_name'] ) );
				return $rows;
			}
			if ( str_contains( $query, 'wdc_manual_delivery_regions' ) && preg_match( '/service_id = ([0-9]+)/', $query, $matches ) ) {
				$country = preg_match( "/country_code = '([^']+)'/", $query, $country_match ) ? $country_match[1] : '';
				$rows = array_values( array_filter( $this->manual_regions, static fn ( array $row ): bool => (int) $row['service_id'] === (int) $matches[1] && ( '' === $country || (string) ( $row['country_code'] ?? 'RU' ) === $country ) ) );
				usort( $rows, static fn ( array $left, array $right ): int => strcmp( (string) ( $left['country_code'] ?? 'RU' ), (string) ( $right['country_code'] ?? 'RU' ) ) ?: strcmp( (string) $left['region_name'], (string) $right['region_name'] ) );
				return $rows;
			}
			if ( str_contains( $query, 'wdc_manual_delivery_weight_ranges' ) && preg_match( '/service_id = ([0-9]+)/', $query, $matches ) ) {
				$rows = array_values( array_filter( $this->manual_weight_ranges, static fn ( array $row ): bool => (int) $row['service_id'] === (int) $matches[1] ) );
				usort( $rows, static fn ( array $left, array $right ): int => ( (int) $left['from_weight_g'] <=> (int) $right['from_weight_g'] ) ?: ( (int) $left['to_weight_g'] <=> (int) $right['to_weight_g'] ) );
				return $rows;
			}
			return array();
		}

		public function get_col( string $query ): array {
			if ( str_contains( $query, 'wdc_manual_delivery_regions' ) && preg_match( '/service_id = ([0-9]+)/', $query, $matches ) ) {
				$rows = array_values( array_map( static fn ( array $row ): string => $row['region_name'], array_filter( $this->manual_regions, static fn ( array $row ): bool => (int) $row['service_id'] === (int) $matches[1] ) ) );
				sort( $rows );
				return $rows;
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
			if ( str_contains( $table, 'wdc_manual_delivery_regions' ) ) {
				return $this->manual_regions;
			}
			if ( str_contains( $table, 'wdc_manual_delivery_locations' ) ) {
				return $this->manual_locations;
			}
			if ( str_contains( $table, 'wdc_manual_delivery_weight_ranges' ) ) {
				return $this->manual_weight_ranges;
			}
			if ( str_contains( $table, 'wdc_rules' ) ) {
				return $this->rules;
			}
			return $this->services;
		}
	}
}

use WallsShop\WDC\Calendar\Services\CalendarService;
use WallsShop\WDC\Calendar\Services\DeliveryDateCalculator;
use WallsShop\WDC\Calendar\Services\DeliveryDateFormatter;
use WallsShop\WDC\Calendar\Services\TimezoneService;
use WallsShop\WDC\Calendar\Services\YearGenerator;
use WallsShop\WDC\Calendar\Storage\CalendarRepository;
use WallsShop\WDC\Carriers\Manual\ManualDeliveryGeographyMatcher;
use WallsShop\WDC\Carriers\Manual\ManualDeliveryGeographyRepository;
use WallsShop\WDC\Carriers\Manual\ManualDeliveryPricingCalculator;
use WallsShop\WDC\Carriers\Manual\ManualDeliveryPricingService;
use WallsShop\WDC\Carriers\Manual\ManualDeliverySettings;
use WallsShop\WDC\Carriers\Manual\ManualDeliveryWeightRange;
use WallsShop\WDC\Carriers\Manual\ManualDeliveryWeightRangeRepository;
use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Carriers\Runtime\ManualDeliveryCarrier;
use WallsShop\WDC\Checkout\Comments\DeliveryCustomerCommentSnapshotBuilder;
use WallsShop\WDC\Checkout\Comments\DeliveryCustomerCommentNormalizer;
use WallsShop\WDC\Checkout\Runtime\CarrierExecutionGuard;
use WallsShop\WDC\Checkout\Runtime\CheckoutLogger;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Runtime\DeliveryLeadTimeNormalizer;
use WallsShop\WDC\Checkout\Runtime\FallbackRateFactory;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\Application\DeliveryServiceKeyRenameService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceManager;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRegistry;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;
use WallsShop\WDC\Orders\Application\OrderQuoteRequestMapper;
use WallsShop\WDC\Packaging\PackagingWeightCalculator;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\Storage\RuleRepository;
use WallsShop\WDC\Rules\ValueObjects\RuleActionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationBases;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationTypes;

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wdc_options']['wdc_core_settings'] = array( SettingsRepository::SHOP_PROCESSING_WORKING_DAYS_KEY => 0 );

$services = new DeliveryServiceRepository( $GLOBALS['wpdb'] );
$settings_repo = new DeliveryServiceSettingsRepository( $GLOBALS['wpdb'] );
$manual_settings = new ManualDeliverySettings( $settings_repo );
$manual_geography = new ManualDeliveryGeographyRepository( $GLOBALS['wpdb'] );
$manual_matcher = new ManualDeliveryGeographyMatcher( $manual_geography );
$manual_weight_ranges = new ManualDeliveryWeightRangeRepository( $GLOBALS['wpdb'] );
$manual_pricing_calculator = new ManualDeliveryPricingCalculator();
$manual_pricing_service = new ManualDeliveryPricingService( $manual_settings, $manual_weight_ranges, $manual_pricing_calculator );
$countries = new DeliveryServiceCountryRepository( $GLOBALS['wpdb'] );
$rules = new RuleRepository( $GLOBALS['wpdb'] );
$rp_directory = ( new ReflectionClass( \WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory::class ) )->newInstanceWithoutConstructor();
$manager = new DeliveryServiceManager( $services, $countries, $rules, $rp_directory, $settings_repo );

$create_manual = static function ( string $key, string $title, string $price, array $country_list, bool $enabled = true ) use ( $services, $manual_settings, $countries ): DeliveryService {
	$id = $services->create_service(
		array(
			'service_key' => $key,
			'carrier_key' => ManualDeliverySettings::CARRIER_KEY,
			'service_type' => DeliveryService::TYPE_MANUAL,
			'title' => $title,
			'enabled' => $enabled ? 1 : 0,
			'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES,
			'use_default_rules_when_no_service_rules' => 1,
			'round_up_to_ruble' => 1,
			'minimum_price_rub' => 1,
			'courier_customer_comment' => 'Комментарий ' . $title,
			'sort_order' => 100,
			'deleted' => 0,
		)
	);
	$manual_settings->save_flat_pricing( $id, $price );
	$manual_settings->save_delivery_days( $id, '1', '2' );
	$countries->replace_countries( $id, $country_list );

	$service = $services->find_by_service_key( $key );
	wdc_manual_assert( $service instanceof DeliveryService, 'Manual service must be persisted.' );

	return $service;
};

$nsk = $create_manual( 'manual_nsk_courier', 'Курьер НСК', '350.25', array( 'RU' ) );
$pickup = $create_manual( 'manual_pickup_store', 'Самовывоз магазин', '0', array( 'RU', 'KZ' ) );
$builtin = $services->ensure_cdek_service();
wdc_manual_assert( DeliveryService::TYPE_API === $builtin->service_type && 'cdek' === $builtin->carrier_key, 'Builtin/API service must remain API-owned.' );
wdc_manual_assert( ManualDeliverySettings::CARRIER_KEY === $nsk->carrier_key && ManualDeliverySettings::CARRIER_KEY === $pickup->carrier_key, 'Manual services must use one technical carrier key.' );
wdc_manual_assert( $nsk->service_key !== $pickup->service_key, 'Manual service keys must stay unique.' );

$services->update_service( (int) $nsk->id, array( 'title' => 'Курьер Новосибирск', 'enabled' => 0 ) );
$updated_nsk = $services->find_by_service_key( 'manual_nsk_courier' );
$unchanged_pickup = $services->find_by_service_key( 'manual_pickup_store' );
wdc_manual_assert( $updated_nsk instanceof DeliveryService && ! $updated_nsk->enabled && 'Курьер Новосибирск' === $updated_nsk->title, 'Manual service edit/toggle must affect the selected service.' );
wdc_manual_assert( $unchanged_pickup instanceof DeliveryService && $unchanged_pickup->enabled && 'Самовывоз магазин' === $unchanged_pickup->title, 'Editing one manual service must not mutate another.' );
$services->update_service( (int) $nsk->id, array( 'enabled' => 1 ) );
$services->soft_delete_service( (int) $pickup->id );
wdc_manual_assert( null === $services->find_by_service_key( 'manual_pickup_store' ), 'Manual service soft delete must hide service from runtime lookup.' );

$carrier = new ManualDeliveryCarrier( $services, $manual_settings, $manual_matcher, $manual_pricing_service );
wdc_manual_assert( ManualDeliverySettings::CARRIER_KEY === $carrier->get_identity()->key && 'manual' === $carrier->get_identity()->type, 'Manual runtime carrier identity must be stable.' );

$request_for = static function ( string $service_key, string $country = 'RU', string $city = 'Новосибирск', string $region = 'Новосибирская область', array $context = array(), int $weight_g = 1000 ): QuoteRequest {
	$item = new PackageItem( 'SKU', 'Item', 1, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), max( 0, $weight_g ), 10, 10, 10 );
	$context = array_merge(
		array(
			'service_key' => $service_key,
			'region_name' => $region,
			'city_name' => $city,
			'place_name' => $city,
		),
		$context
	);
	return new QuoteRequest(
		$country,
		new Address( country_code: $country, city: $city, region_name: $region, raw_address: $city ),
		Package::from_items( array( $item ), 0, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ) ),
		'',
		Money::from_rubles( 1000 ),
		'2026-09-04',
		$context
	);
};

$quote = $carrier->quote( $request_for( 'manual_nsk_courier' ) );
wdc_manual_assert( $quote->success && 1 === count( $quote->rates), 'Manual flat runtime must return one rate for an active manual service.' );
wdc_manual_assert( 35025 === $quote->rates[0]->price->get_kopecks() && DeliveryType::COURIER === $quote->rates[0]->delivery_type, 'Manual flat price must use integer kopecks and canonical courier type.' );
wdc_manual_assert( $carrier->quote( $request_for( 'manual_nsk_courier', 'RU', 'Новосибирск', 'Новосибирская область', array(), 0 ) )->success, 'Manual flat pricing must stay available when chargeable weight is zero.' );
wdc_manual_assert( ! $carrier->quote( $request_for( '' ) )->success, 'Manual runtime must fail closed without service_key.' );
wdc_manual_assert( ! $carrier->quote( $request_for( 'unknown_manual' ) )->success, 'Manual runtime must fail closed for unknown service_key.' );
$wrong_id = $services->create_service( array( 'service_key' => 'wrong_owner', 'carrier_key' => 'cdek', 'service_type' => DeliveryService::TYPE_MANUAL, 'title' => 'Wrong', 'enabled' => 1, 'deleted' => 0 ) );
$manual_settings->save_flat_pricing( $wrong_id, '100' );
wdc_manual_assert( ! $carrier->quote( $request_for( 'wrong_owner' ) )->success, 'Manual runtime must fail closed for wrong carrier ownership.' );
$legacy_id = $services->create_service( array( 'service_key' => 'legacy_fixed_manual', 'carrier_key' => ManualDeliverySettings::CARRIER_KEY, 'service_type' => DeliveryService::TYPE_FIXED, 'title' => 'Legacy', 'enabled' => 1, 'deleted' => 0 ) );
$manual_settings->save_flat_pricing( $legacy_id, '100' );
wdc_manual_assert( ! $carrier->quote( $request_for( 'legacy_fixed_manual' ) )->success, 'Legacy fixed type must not quote without explicit manual normalization.' );
$missing_pricing_id = $services->create_service( array( 'service_key' => 'manual_missing_pricing', 'carrier_key' => ManualDeliverySettings::CARRIER_KEY, 'service_type' => DeliveryService::TYPE_MANUAL, 'title' => 'Missing pricing', 'enabled' => 1, 'deleted' => 0 ) );
wdc_manual_assert( $missing_pricing_id > 0 && ! $carrier->quote( $request_for( 'manual_missing_pricing' ) )->success, 'Manual runtime must fail closed when pricing config is absent.' );

$per_kg_id = $services->create_service( array( 'service_key' => 'manual_per_kg', 'carrier_key' => ManualDeliverySettings::CARRIER_KEY, 'service_type' => DeliveryService::TYPE_MANUAL, 'title' => 'Per kg', 'enabled' => 1, 'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES, 'deleted' => 0 ) );
$countries->replace_countries( $per_kg_id, array( 'RU' ) );
$manual_settings->save_pricing( $per_kg_id, array( 'pricing_mode' => ManualDeliverySettings::PRICING_MODE_PER_KG, 'price_per_kg_rub' => '150', 'minimum_price_rub' => '500', 'billing_weight_step_g' => ManualDeliverySettings::BILLING_STEP_1_KG ) );
$per_kg_quote = $carrier->quote( $request_for( 'manual_per_kg', 'RU', 'Новосибирск', 'Новосибирская область', array(), 2400 ) );
wdc_manual_assert( $per_kg_quote->success && 50000 === $per_kg_quote->rates[0]->price->get_kopecks() && 3000 === (int) $per_kg_quote->rates[0]->meta['manual_billing_weight_g'], 'Manual per-kg pricing must round billing weight up by step and apply tariff minimum before rules.' );
$manual_settings->save_pricing( $per_kg_id, array( 'pricing_mode' => ManualDeliverySettings::PRICING_MODE_PER_KG, 'price_per_kg_rub' => '150', 'minimum_price_rub' => '', 'billing_weight_step_g' => ManualDeliverySettings::BILLING_STEP_1_KG ) );
wdc_manual_assert( 45000 === $carrier->quote( $request_for( 'manual_per_kg', 'RU', 'Новосибирск', 'Новосибирская область', array(), 2400 ) )->rates[0]->price->get_kopecks(), 'Manual per-kg pricing without tariff minimum must use rounded billing weight times price per 1000 g.' );
$manual_settings->save_pricing( $per_kg_id, array( 'pricing_mode' => ManualDeliverySettings::PRICING_MODE_PER_KG, 'price_per_kg_rub' => '150', 'minimum_price_rub' => '', 'billing_weight_step_g' => ManualDeliverySettings::BILLING_STEP_500_G ) );
wdc_manual_assert( 22500 === $carrier->quote( $request_for( 'manual_per_kg', 'RU', 'Новосибирск', 'Новосибирская область', array(), 1201 ) )->rates[0]->price->get_kopecks(), 'Manual per-kg pricing must keep price_per_kg as price per 1000 g when billing step is 500 g.' );
wdc_manual_assert( array( 1000, 1000, 2000 ) === array( $manual_pricing_calculator->billing_weight_g( 999, 1000 ), $manual_pricing_calculator->billing_weight_g( 1000, 1000 ), $manual_pricing_calculator->billing_weight_g( 1001, 1000 ) ), 'Manual billing step 1000 g boundaries must round up with integer arithmetic.' );
wdc_manual_assert( array( 500, 500, 1000 ) === array( $manual_pricing_calculator->billing_weight_g( 499, 500 ), $manual_pricing_calculator->billing_weight_g( 500, 500 ), $manual_pricing_calculator->billing_weight_g( 501, 500 ) ), 'Manual billing step 500 g boundaries must round up with integer arithmetic.' );
wdc_manual_assert( ! $carrier->quote( $request_for( 'manual_per_kg', 'RU', 'Новосибирск', 'Новосибирская область', array(), 0 ) )->success, 'Manual per-kg pricing must fail closed when chargeable weight is zero.' );
$manual_settings->save_pricing( $per_kg_id, array( 'pricing_mode' => ManualDeliverySettings::PRICING_MODE_FLAT, 'flat_price_rub' => '321.45' ) );
wdc_manual_assert( ManualDeliverySettings::PRICING_MODE_FLAT === $manual_settings->pricing( $per_kg_id )['pricing_mode'] && 32145 === $manual_settings->pricing( $per_kg_id )['flat_price_kopecks'], 'Manual flat save must not require a billing step field.' );
$manual_settings->save_pricing( $per_kg_id, array( 'pricing_mode' => ManualDeliverySettings::PRICING_MODE_PER_KG, 'price_per_kg_rub' => '150', 'minimum_price_rub' => '', 'billing_weight_step_g' => ManualDeliverySettings::BILLING_STEP_500_G ) );
$manual_settings->save_pricing( $per_kg_id, array( 'pricing_mode' => ManualDeliverySettings::PRICING_MODE_FLAT, 'flat_price_rub' => '322', 'billing_weight_step_g' => 0 ) );
wdc_manual_assert( ManualDeliverySettings::PRICING_MODE_FLAT === $manual_settings->pricing( $per_kg_id )['pricing_mode'] && 32200 === $manual_settings->pricing( $per_kg_id )['flat_price_kopecks'], 'Manual flat save after per-kg must accept hidden zero billing step and persist the new flat price.' );
try {
	$manual_settings->save_pricing( $per_kg_id, array( 'pricing_mode' => ManualDeliverySettings::PRICING_MODE_PER_KG, 'price_per_kg_rub' => '150', 'billing_weight_step_g' => 250 ) );
	wdc_manual_assert( false, 'Manual active per-kg mode must reject unsupported positive billing step.' );
} catch ( InvalidArgumentException ) {
	wdc_manual_assert( ManualDeliverySettings::PRICING_MODE_FLAT === $manual_settings->pricing( $per_kg_id )['pricing_mode'], 'Rejected per-kg billing step must not partially switch the active pricing mode.' );
}
$manual_settings->save_pricing( $per_kg_id, array( 'pricing_mode' => ManualDeliverySettings::PRICING_MODE_PER_KG, 'price_per_kg_rub' => '150', 'minimum_price_rub' => '', 'billing_weight_step_g' => ManualDeliverySettings::BILLING_STEP_1_KG ) );

$ranges_id = $services->create_service( array( 'service_key' => 'manual_ranges', 'carrier_key' => ManualDeliverySettings::CARRIER_KEY, 'service_type' => DeliveryService::TYPE_MANUAL, 'title' => 'Ranges', 'enabled' => 1, 'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES, 'deleted' => 0 ) );
$countries->replace_countries( $ranges_id, array( 'RU' ) );
$manual_settings->save_pricing( $ranges_id, array( 'pricing_mode' => ManualDeliverySettings::PRICING_MODE_WEIGHT_RANGES, 'billing_weight_step_g' => ManualDeliverySettings::BILLING_STEP_NONE_G, 'flat_price_rub' => '999', 'price_per_kg_rub' => '150' ) );
$manual_weight_ranges->replace_ranges( $ranges_id, array( new ManualDeliveryWeightRange( 0, 2000, 35000, 1 ), new ManualDeliveryWeightRange( 2000, 5000, 50000, 2 ), new ManualDeliveryWeightRange( 5000, 10000, 75000, 3 ) ) );
foreach ( array( 1 => 35000, 1999 => 35000, 2000 => 35000, 2001 => 50000, 5000 => 50000, 5001 => 75000, 10000 => 75000 ) as $weight => $expected_kopecks ) {
	$range_quote = $carrier->quote( $request_for( 'manual_ranges', 'RU', 'Новосибирск', 'Новосибирская область', array(), (int) $weight ) );
	wdc_manual_assert( $range_quote->success && $expected_kopecks === $range_quote->rates[0]->price->get_kopecks(), 'Manual weight ranges must use upper-inclusive boundaries.' );
}
wdc_manual_assert( ! $carrier->quote( $request_for( 'manual_ranges', 'RU', 'Новосибирск', 'Новосибирская область', array(), 10001 ) )->success, 'Manual weight ranges must fail closed when weight is outside configured ranges.' );
wdc_manual_assert( ! $carrier->quote( $request_for( 'manual_ranges', 'RU', 'Новосибирск', 'Новосибирская область', array(), 0 ) )->success, 'Manual weight ranges must fail closed when chargeable weight is zero.' );
$manual_weight_ranges->replace_ranges( $ranges_id, array( new ManualDeliveryWeightRange( 0, 2000, 35000, 1 ), new ManualDeliveryWeightRange( 5000, 10000, 75000, 2 ) ) );
wdc_manual_assert( ! $carrier->quote( $request_for( 'manual_ranges', 'RU', 'Новосибирск', 'Новосибирская область', array(), 3000 ) )->success, 'Manual weight range gaps must make the tariff unavailable for that billing weight.' );
$manual_weight_ranges->replace_ranges( $ranges_id, array( new ManualDeliveryWeightRange( 0, 2000, 35000, 1 ), new ManualDeliveryWeightRange( 2000, 5000, 50000, 2 ) ) );
$manual_settings->save_pricing( $ranges_id, array( 'pricing_mode' => ManualDeliverySettings::PRICING_MODE_WEIGHT_RANGES, 'billing_weight_step_g' => ManualDeliverySettings::BILLING_STEP_500_G ) );
wdc_manual_assert( 35000 === $carrier->quote( $request_for( 'manual_ranges', 'RU', 'Новосибирск', 'Новосибирская область', array(), 1901 ) )->rates[0]->price->get_kopecks() && 50000 === $carrier->quote( $request_for( 'manual_ranges', 'RU', 'Новосибирск', 'Новосибирская область', array(), 2001 ) )->rates[0]->price->get_kopecks(), 'Manual weight ranges must apply billing step before range matching.' );
$manual_settings->save_pricing( $ranges_id, array( 'pricing_mode' => ManualDeliverySettings::PRICING_MODE_FLAT, 'flat_price_rub' => '333', 'billing_weight_step_g' => 0 ) );
wdc_manual_assert( ManualDeliverySettings::PRICING_MODE_FLAT === $manual_settings->pricing( $ranges_id )['pricing_mode'] && 33300 === $manual_settings->pricing( $ranges_id )['flat_price_kopecks'], 'Manual flat save after weight ranges must accept stale hidden billing step and persist the new flat price.' );
try {
	$manual_settings->save_pricing( $ranges_id, array( 'pricing_mode' => ManualDeliverySettings::PRICING_MODE_WEIGHT_RANGES, 'billing_weight_step_g' => 250 ) );
	wdc_manual_assert( false, 'Manual active weight-ranges mode must reject unsupported positive billing step.' );
} catch ( InvalidArgumentException ) {
	wdc_manual_assert( ManualDeliverySettings::PRICING_MODE_FLAT === $manual_settings->pricing( $ranges_id )['pricing_mode'], 'Rejected ranges billing step must not partially switch the active pricing mode.' );
}
$manual_settings->save_pricing( $ranges_id, array( 'pricing_mode' => ManualDeliverySettings::PRICING_MODE_WEIGHT_RANGES, 'billing_weight_step_g' => ManualDeliverySettings::BILLING_STEP_NONE_G ) );
$before_invalid_ranges = $manual_weight_ranges->ranges( $ranges_id );
foreach ( array(
	array( new ManualDeliveryWeightRange( 0, 2000, 10000 ), new ManualDeliveryWeightRange( 1000, 3000, 20000 ) ),
	array( new ManualDeliveryWeightRange( 0, 2000, 10000 ), new ManualDeliveryWeightRange( 0, 2000, 20000 ) ),
	array( new ManualDeliveryWeightRange( 2000, 2000, 10000 ) ),
	array( new ManualDeliveryWeightRange( -1, 2000, 10000 ) ),
	array( new ManualDeliveryWeightRange( 0, 2000, -1 ) ),
) as $invalid_ranges ) {
	try {
		$manual_weight_ranges->replace_ranges( $ranges_id, $invalid_ranges );
		wdc_manual_assert( false, 'Invalid manual weight range config must be rejected.' );
	} catch ( InvalidArgumentException ) {
		wdc_manual_assert( $before_invalid_ranges == $manual_weight_ranges->ranges( $ranges_id ), 'Rejected manual weight ranges must leave the previous valid config intact.' );
	}
}

$services->update_service( (int) $nsk->id, array( 'enabled' => 0 ) );
wdc_manual_assert( ! $carrier->quote( $request_for( 'manual_nsk_courier' ) )->success, 'Manual runtime must fail closed for disabled service.' );
$services->update_service( (int) $nsk->id, array( 'enabled' => 1 ) );

$GLOBALS['wpdb']->rules[] = array( 'id' => 1, 'name' => 'Default add 50', 'enabled' => 1, 'priority' => 10, 'target_type' => RuleRepository::TARGET_DEFAULT, 'target_value' => '', 'action_type' => RuleActionTypes::CHANGE_PRICE, 'operation_type' => RuleOperationTypes::INCREASE, 'operation_value' => 50, 'operation_base' => RuleOperationBases::RUBLES, 'operation_text' => '', 'promo_shipping' => 0, 'stop_processing' => 0, 'condition_group_logic' => array(), 'condition_group_expression' => 'condition_1' );
$GLOBALS['wpdb']->rules[] = array( 'id' => 2, 'name' => 'Own add 25', 'enabled' => 1, 'priority' => 10, 'target_type' => RuleRepository::TARGET_SERVICE, 'target_value' => 'manual_nsk_courier', 'action_type' => RuleActionTypes::CHANGE_PRICE, 'operation_type' => RuleOperationTypes::INCREASE, 'operation_value' => 25, 'operation_base' => RuleOperationBases::RUBLES, 'operation_text' => '', 'promo_shipping' => 0, 'stop_processing' => 0, 'condition_group_logic' => array(), 'condition_group_expression' => 'condition_1' );
wdc_manual_assert( 'service' === $manager->rules_for_service( $services->find_by_service_key( 'manual_nsk_courier' ) )['source'], 'Own manual service rules must win when present.' );
$no_own = $create_manual( 'manual_default_fallback', 'Fallback manual', '100', array( 'RU' ) );
wdc_manual_assert( 'default' === $manager->rules_for_service( $no_own )['source'], 'Default rules must apply only when own service rules are absent and fallback is enabled.' );
$services->update_service( (int) $no_own->id, array( 'use_default_rules_when_no_service_rules' => 0 ) );
wdc_manual_assert( 'none' === $manager->rules_for_service( $services->find_by_service_key( 'manual_default_fallback' ) )['source'], 'Rules must not apply when fallback is disabled and own rules are absent.' );

$registry = new CarrierRegistry();
$registry->register( $carrier );
$service_registry = new DeliveryServiceRegistry( $services, $registry );
$packaging_calculator = new PackagingWeightCalculator( new SettingsRepository() );
$lead_time = new DeliveryLeadTimeNormalizer(
	new SettingsRepository(),
	$settings_repo,
	new DeliveryDateCalculator( new CalendarService( new CalendarRepository(), new YearGenerator(), new SettingsRepository(), new TimezoneService() ), new TimezoneService(), new DeliveryDateFormatter() ),
	new DeliveryDateFormatter()
);
$orchestrator = new CheckoutOrchestrator(
	$registry,
	new RuleAppliedRateBuilder( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) ),
	new RateSorter(),
	new FallbackRateFactory(),
	new CarrierExecutionGuard( new CheckoutLogger( null ) ),
	new CheckoutLogger( null ),
	$lead_time,
	null,
	$service_registry,
	$manager,
	$packaging_calculator,
	null,
	new DeliveryCustomerCommentSnapshotBuilder( new DeliveryCustomerCommentNormalizer() )
);
$result = $orchestrator->calculate( $request_for( 'ignored', 'RU' ) );
$manual_rates = array_values( array_filter( $result->rates, static fn ( $rate ): bool => ManualDeliverySettings::CARRIER_KEY === $rate->carrier_key ) );
wdc_manual_assert( array() !== $manual_rates, 'Manual services must enter checkout through DeliveryServiceRegistry and CheckoutOrchestrator.' );
$nsk_rate = array_values( array_filter( $manual_rates, static fn ( $rate ): bool => 'manual_nsk_courier' === $rate->service_key ) )[0] ?? null;
wdc_manual_assert( null !== $nsk_rate && 37600 === $nsk_rate->price->get_kopecks() && ! empty( $nsk_rate->meta['round_up_applied'] ), 'Manual base rate must pass through Rule Engine before service post-processing.' );
wdc_manual_assert( 35025 === (int) round( (float) $nsk_rate->meta['original_price_rub'] * 100 ) && 376.0 === (float) $nsk_rate->meta['final_price_rub'], 'Manual rate must preserve base/original and final price metadata.' );
wdc_manual_assert( 'Комментарий Курьер НСК' === (string) ( $nsk_rate->meta['customer_comments'][0]['text'] ?? '' ), 'Manual service customer comment must use the canonical comment pipeline.' );
wdc_manual_assert( 'manual_pricing' === ManualDeliverySettings::PRICING_SETTING_KEY && ! str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Rules/Services/RuleEngine.php' ), 'manual_rules_enabled' ), 'Manual delivery must not add a manual-specific rule-engine branch.' );
$per_kg_rate = array_values( array_filter( $manual_rates, static fn ( $rate ): bool => 'manual_per_kg' === $rate->service_key ) )[0] ?? null;
$range_rate = array_values( array_filter( $manual_rates, static fn ( $rate ): bool => 'manual_ranges' === $rate->service_key ) )[0] ?? null;
wdc_manual_assert( null !== $per_kg_rate && 20000 === $per_kg_rate->price->get_kopecks() && 150.0 === (float) $per_kg_rate->meta['api_base_price_rub'] && 150.0 === (float) $per_kg_rate->meta['original_price_rub'], 'Manual per-kg base price must pass through default rules and existing post-processing without a manual Rule Engine branch.' );
wdc_manual_assert( null !== $range_rate && 40000 === $range_rate->price->get_kopecks() && 350.0 === (float) $range_rate->meta['api_base_price_rub'] && ManualDeliverySettings::PRICING_MODE_WEIGHT_RANGES === (string) $range_rate->meta['manual_pricing_mode'], 'Manual weight range base price must pass through default rules and existing post-processing.' );

wdc_manual_assert( $manager->service_available_for_country( $services->find_by_service_key( 'manual_nsk_courier' ), 'RU' ), 'Selected-country manual service must be available for selected country.' );
wdc_manual_assert( ! $manager->service_available_for_country( $services->find_by_service_key( 'manual_nsk_courier' ), 'KZ' ), 'Selected-country manual service must not be available for an unselected country.' );
$all_id = $services->create_service( array( 'service_key' => 'manual_all', 'carrier_key' => ManualDeliverySettings::CARRIER_KEY, 'service_type' => DeliveryService::TYPE_MANUAL, 'title' => 'All', 'enabled' => 1, 'availability_mode' => DeliveryService::AVAILABILITY_ALL_COUNTRIES, 'deleted' => 0 ) );
$except_id = $services->create_service( array( 'service_key' => 'manual_except', 'carrier_key' => ManualDeliverySettings::CARRIER_KEY, 'service_type' => DeliveryService::TYPE_MANUAL, 'title' => 'Except', 'enabled' => 1, 'availability_mode' => DeliveryService::AVAILABILITY_ALL_EXCEPT_SELECTED, 'deleted' => 0 ) );
$countries->replace_countries( $except_id, array( 'KZ' ) );
wdc_manual_assert( $manager->service_available_for_country( $services->find_by_service_key( 'manual_all' ), 'AM' ), 'Manual all-countries mode must use shared availability infrastructure.' );
wdc_manual_assert( ! $manager->service_available_for_country( $services->find_by_service_key( 'manual_except' ), 'KZ' ) && $manager->service_available_for_country( $services->find_by_service_key( 'manual_except' ), 'RU' ), 'Manual all-except-selected mode must use shared availability infrastructure.' );

$GLOBALS['wdc_options']['wdc_core_settings'][ PackagingWeightCalculator::SETTINGS_KEY ] = array( array( 'cart_weight_from_g' => 1, 'cart_weight_to_g' => 2000, 'packaging_weight_g' => 500 ) );
$pack_no_id = $services->create_service( array( 'service_key' => 'manual_packaging_no', 'carrier_key' => ManualDeliverySettings::CARRIER_KEY, 'service_type' => DeliveryService::TYPE_MANUAL, 'title' => 'No packaging', 'enabled' => 1, 'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES, 'use_default_rules_when_no_service_rules' => 0, 'round_up_to_ruble' => 0, 'minimum_price_rub' => 0, 'include_packaging_weight' => 0, 'deleted' => 0 ) );
$pack_yes_id = $services->create_service( array( 'service_key' => 'manual_packaging_yes', 'carrier_key' => ManualDeliverySettings::CARRIER_KEY, 'service_type' => DeliveryService::TYPE_MANUAL, 'title' => 'With packaging', 'enabled' => 1, 'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES, 'use_default_rules_when_no_service_rules' => 0, 'round_up_to_ruble' => 0, 'minimum_price_rub' => 0, 'include_packaging_weight' => 1, 'deleted' => 0 ) );
$countries->replace_countries( $pack_no_id, array( 'RU' ) );
$countries->replace_countries( $pack_yes_id, array( 'RU' ) );
$manual_settings->save_pricing( $pack_no_id, array( 'pricing_mode' => ManualDeliverySettings::PRICING_MODE_PER_KG, 'price_per_kg_rub' => '150', 'billing_weight_step_g' => ManualDeliverySettings::BILLING_STEP_1_KG ) );
$manual_settings->save_pricing( $pack_yes_id, array( 'pricing_mode' => ManualDeliverySettings::PRICING_MODE_PER_KG, 'price_per_kg_rub' => '150', 'billing_weight_step_g' => ManualDeliverySettings::BILLING_STEP_1_KG ) );
$packaging_result = $orchestrator->calculate( $request_for( 'ignored', 'RU', 'Новосибирск', 'Новосибирская область', array(), 1000 ) );
$packaging_rates = array_column( array_map( static fn ( $rate ): array => array( 'key' => $rate->service_key, 'rate' => $rate ), $packaging_result->rates ), 'rate', 'key' );
wdc_manual_assert( 15000 === $packaging_rates['manual_packaging_no']->price->get_kopecks() && 1000 === (int) $packaging_rates['manual_packaging_no']->meta['manual_chargeable_weight_g'], 'Manual per-kg pricing must respect include_packaging_weight=false through the existing packaging pipeline.' );
wdc_manual_assert( 30000 === $packaging_rates['manual_packaging_yes']->price->get_kopecks() && 1500 === (int) $packaging_rates['manual_packaging_yes']->meta['manual_chargeable_weight_g'], 'Manual per-kg pricing must use existing packaging-aware package weight when include_packaging_weight=true.' );
$GLOBALS['wdc_options']['wdc_core_settings'][ PackagingWeightCalculator::SETTINGS_KEY ] = array();

$GLOBALS['wpdb']->locations = array(
	array( 'id' => 10, 'country_code' => 'RU', 'region_name' => 'Новосибирская область', 'city_name' => 'Новосибирск', 'settlement_name' => '', 'place_name' => 'Новосибирск', 'display_name' => 'Новосибирск — Новосибирская область', 'searchable_text' => Location::normalize_search_text( 'Новосибирск Новосибирская область' ), 'active' => 1 ),
	array( 'id' => 11, 'country_code' => 'RU', 'region_name' => 'Алтайский край', 'city_name' => 'Барнаул', 'settlement_name' => '', 'place_name' => 'Барнаул', 'display_name' => 'Барнаул — Алтайский край', 'searchable_text' => Location::normalize_search_text( 'Барнаул Алтайский край' ), 'active' => 1 ),
	array( 'id' => 12, 'country_code' => 'RU', 'region_name' => 'Московская область', 'city_name' => 'Советский', 'settlement_name' => '', 'place_name' => 'Советский', 'display_name' => 'Советский — Московская область', 'searchable_text' => Location::normalize_search_text( 'Советский Московская область' ), 'active' => 1 ),
	array( 'id' => 13, 'country_code' => 'RU', 'region_name' => 'Ханты-Мансийский автономный округ — Югра', 'city_name' => 'Советский', 'settlement_name' => '', 'place_name' => 'Советский', 'display_name' => 'Советский — Ханты-Мансийский автономный округ — Югра', 'searchable_text' => Location::normalize_search_text( 'Советский Ханты-Мансийский автономный округ Югра' ), 'active' => 1 ),
	array( 'id' => 14, 'country_code' => 'KZ', 'region_name' => 'Алматы', 'city_name' => 'Алматы', 'settlement_name' => '', 'place_name' => 'Алматы', 'display_name' => 'Алматы', 'searchable_text' => Location::normalize_search_text( 'Алматы' ), 'active' => 1 ),
);

$location_repo = new LocationRepository( $GLOBALS['wpdb'] );
$known_regions = $location_repo->unique_active_region_names( 'ново', 20, 'RU' );
wdc_manual_assert( array( array( 'country_code' => 'RU', 'region_name' => 'Новосибирская область' ) ) === $known_regions, 'Manual admin region search must use known active country-aware region_name values.' );
$soviet_locations = $location_repo->search_active_locations_for_manual_delivery( 'Советский', 20, 'RU' );
$soviet_regions = array_values( array_unique( array_map( static fn ( Location $location ): string => $location->region_name, $soviet_locations ) ) );
sort( $soviet_regions, SORT_STRING );
wdc_manual_assert( array( 'Московская область', 'Ханты-Мансийский автономный округ — Югра' ) === $soviet_regions, 'Manual admin location search must distinguish same place names by textual region_name.' );

$manual_geography->replace_regions( (int) $nsk->id, array( 'Новосибирская область', 'Алтайский край', 'Новосибирская область' ) );
wdc_manual_assert( array( array( 'country_code' => 'RU', 'region_name' => 'Алтайский край' ), array( 'country_code' => 'RU', 'region_name' => 'Новосибирская область' ) ) === $manual_geography->regions( (int) $nsk->id ), 'Manual region repository must save multiple regions duplicate-safe.' );
$manual_geography->replace_regions( (int) $pickup->id, array( 'Московская область' ) );
$manual_geography->replace_regions( (int) $nsk->id, array( 'Новосибирская область' ) );
wdc_manual_assert( array( array( 'country_code' => 'RU', 'region_name' => 'Новосибирская область' ) ) === $manual_geography->regions( (int) $nsk->id ) && array( array( 'country_code' => 'RU', 'region_name' => 'Московская область' ) ) === $manual_geography->regions( (int) $pickup->id ), 'Manual region replace semantics must remove stale rows while keeping service isolation.' );

$manual_geography->replace_locations(
	(int) $nsk->id,
	array(
		array( 'location_name' => 'Советский', 'region_name' => 'Московская область' ),
		array( 'location_name' => 'Советский', 'region_name' => 'Ханты-Мансийский автономный округ — Югра' ),
		array( 'location_name' => 'Советский', 'region_name' => 'Московская область' ),
	)
);
$stored_locations = $manual_geography->locations( (int) $nsk->id );
wdc_manual_assert( 2 === count( $stored_locations ) && $stored_locations[0]['location_name'] === $stored_locations[1]['location_name'] && $stored_locations[0]['region_name'] !== $stored_locations[1]['region_name'], 'Manual location repository must store location_name plus region_name identity and allow same names in different regions.' );
$manual_geography->replace_locations( (int) $pickup->id, array( array( 'location_name' => 'Барнаул', 'region_name' => 'Алтайский край' ) ) );
$manual_geography->replace_locations( (int) $nsk->id, array( array( 'location_name' => 'Советский', 'region_name' => 'Ханты-Мансийский автономный округ — Югра' ) ) );
wdc_manual_assert( 1 === count( $manual_geography->locations( (int) $nsk->id ) ) && 1 === count( $manual_geography->locations( (int) $pickup->id ) ), 'Manual location replace/delete semantics must be service-scoped.' );
$manual_geography->clear( (int) $pickup->id );
wdc_manual_assert( array() === $manual_geography->regions( (int) $pickup->id ) && array() === $manual_geography->locations( (int) $pickup->id ), 'Manual geography clear must delete both regions and locations.' );

$geo_all = $create_manual( 'manual_geo_all_ru', 'Вся РФ', '101', array( 'RU' ) );
$geo_region = $create_manual( 'manual_geo_nsk_region', 'Регион НСК', '202', array( 'RU' ) );
$geo_city = $create_manual( 'manual_geo_barnaul_city', 'Барнаул', '303', array( 'RU' ) );
$geo_or = $create_manual( 'manual_geo_region_or_city', 'Регион или город', '404', array( 'RU' ) );
$geo_kz = $create_manual( 'manual_geo_kz', 'Казахстан', '505', array( 'KZ' ) );
$manual_geography->replace_regions( (int) $geo_region->id, array( 'Новосибирская область' ) );
$manual_geography->replace_locations( (int) $geo_city->id, array( array( 'location_name' => 'Барнаул', 'region_name' => 'Алтайский край' ) ) );
$manual_geography->replace_regions( (int) $geo_or->id, array( 'Новосибирская область' ) );
$manual_geography->replace_locations( (int) $geo_or->id, array( array( 'location_name' => 'Барнаул', 'region_name' => 'Алтайский край' ) ) );
$manual_geography->replace_regions( (int) $geo_kz->id, array( array( 'country_code' => 'RU', 'region_name' => 'Новосибирская область' ) ) );

wdc_manual_assert( $manual_matcher->match( $geo_all, $request_for( 'manual_geo_all_ru', 'RU', 'Омск', 'Омская область' ) )['available'], 'Manual matcher must allow RU destinations when no region/city restrictions exist.' );
wdc_manual_assert( $manual_matcher->match( $geo_region, $request_for( 'manual_geo_nsk_region', 'RU', 'Бердск', 'Новосибирская область' ) )['available'], 'Manual matcher must allow a matching selected region.' );
wdc_manual_assert( ! $manual_matcher->match( $geo_region, $request_for( 'manual_geo_nsk_region', 'RU', 'Омск', 'Омская область' ) )['available'], 'Manual matcher must reject non-matching selected regions.' );
wdc_manual_assert( $manual_matcher->match( $geo_city, $request_for( 'manual_geo_barnaul_city', 'RU', 'Барнаул', 'Алтайский край' ) )['available'], 'Manual matcher must allow a matching city textual pair.' );
wdc_manual_assert( ! $manual_matcher->match( $geo_city, $request_for( 'manual_geo_barnaul_city', 'RU', 'Барнаул', 'Новосибирская область' ) )['available'], 'Manual matcher must reject the same city name with a different region.' );
wdc_manual_assert( $manual_matcher->match( $geo_or, $request_for( 'manual_geo_region_or_city', 'RU', 'Бердск', 'Новосибирская область' ) )['available'] && $manual_matcher->match( $geo_or, $request_for( 'manual_geo_region_or_city', 'RU', 'Барнаул', 'Алтайский край' ) )['available'], 'Manual matcher must use OR semantics for selected regions and explicit cities.' );
wdc_manual_assert( $manual_matcher->match( $geo_kz, $request_for( 'manual_geo_kz', 'KZ', 'Алматы', 'Алматы' ) )['available'], 'Manual matcher must not apply RU region restrictions to non-RU destinations.' );
wdc_manual_assert( ! $manual_matcher->match( $geo_region, $request_for( 'manual_geo_nsk_region', 'RU', '', '', array( 'region_name' => '', 'city_name' => '', 'place_name' => '' ) ) )['available'], 'Manual matcher must fail closed for restricted RU services when trusted region/location identity is missing.' );
wdc_manual_assert( $manual_matcher->match( $geo_region, $request_for( 'manual_geo_nsk_region', 'RU', '', 'Новосибирская область', array( 'region_name' => 'Новосибирская область', 'city_name' => '', 'place_name' => '' ) ) )['available'], 'Manual region-only matching must not require trusted city identity.' );

$geo_countries = $create_manual( 'manual_geo_countries', 'Страны', '606', array( 'RU', 'KZ' ) );
$manual_geography->replace_regions(
	(int) $geo_countries->id,
	array(
		array( 'country_code' => 'RU', 'region_name' => 'Новосибирская область' ),
		array( 'country_code' => 'KZ', 'region_name' => 'Алматы' ),
	)
);
wdc_manual_assert( $manual_matcher->match( $geo_countries, $request_for( 'manual_geo_countries', 'RU', 'Бердск', 'Новосибирская область' ) )['available'], 'RU country-scoped region restriction must match RU destination.' );
wdc_manual_assert( ! $manual_matcher->match( $geo_countries, $request_for( 'manual_geo_countries', 'RU', 'Москва', 'Москва' ) )['available'], 'RU country-scoped region restriction must reject other RU regions.' );
wdc_manual_assert( $manual_matcher->match( $geo_countries, $request_for( 'manual_geo_countries', 'KZ', 'Алматы', 'Алматы' ) )['available'], 'KZ country-scoped region restriction must match KZ destination.' );
wdc_manual_assert( ! $manual_matcher->match( $geo_countries, $request_for( 'manual_geo_countries', 'KZ', 'Астана', 'Астана' ) )['available'], 'KZ country-scoped region restriction must reject other KZ regions.' );

$geo_city_countries = $create_manual( 'manual_geo_city_countries', 'Города стран', '707', array( 'RU', 'KZ' ) );
$manual_geography->replace_locations(
	(int) $geo_city_countries->id,
	array(
		array( 'country_code' => 'RU', 'location_name' => 'Новосибирск', 'region_name' => 'Новосибирская область' ),
		array( 'country_code' => 'KZ', 'location_name' => 'Алматы', 'region_name' => 'Алматы' ),
	)
);
wdc_manual_assert( $manual_matcher->match( $geo_city_countries, $request_for( 'manual_geo_city_countries', 'RU', 'Новосибирск', 'Новосибирская область' ) )['available'], 'RU country-scoped city restriction must match RU city pair.' );
wdc_manual_assert( ! $manual_matcher->match( $geo_city_countries, $request_for( 'manual_geo_city_countries', 'RU', 'Москва', 'Москва' ) )['available'], 'RU country-scoped city restriction must reject other RU cities.' );
wdc_manual_assert( $manual_matcher->match( $geo_city_countries, $request_for( 'manual_geo_city_countries', 'KZ', 'Алматы', 'Алматы' ) )['available'], 'KZ country-scoped city restriction must match KZ city pair.' );
wdc_manual_assert( ! $manual_matcher->match( $geo_city_countries, $request_for( 'manual_geo_city_countries', 'KZ', 'Астана', 'Астана' ) )['available'], 'KZ country-scoped city restriction must reject other KZ cities.' );

$same_name = $create_manual( 'manual_geo_same_name', 'Same names', '808', array( 'RU', 'KZ' ) );
$manual_geography->replace_locations(
	(int) $same_name->id,
	array(
		array( 'country_code' => 'RU', 'location_name' => 'Алматы', 'region_name' => 'Алматы' ),
		array( 'country_code' => 'KZ', 'location_name' => 'Алматы', 'region_name' => 'Алматы' ),
	)
);
wdc_manual_assert( 2 === count( $manual_geography->locations( (int) $same_name->id ) ), 'Manual location identity must distinguish same location and region names across countries.' );

$ru_only = $create_manual( 'manual_geo_ru_boundary', 'RU only', '909', array( 'RU' ) );
$manual_geography->replace_locations( (int) $ru_only->id, array( array( 'country_code' => 'KZ', 'location_name' => 'Алматы', 'region_name' => 'Алматы' ) ) );
wdc_manual_assert( ! $manager->service_available_for_country( $ru_only, 'KZ' ), 'Country availability must remain the authoritative upper boundary before manual geography.' );

$rename = $create_manual( 'manual_old', 'Rename', '111', array( 'RU' ) );
$manual_geography->replace_locations( (int) $rename->id, array( array( 'country_code' => 'RU', 'location_name' => 'Новосибирск', 'region_name' => 'Новосибирская область' ) ) );
$GLOBALS['wpdb']->rules[] = array( 'id' => 20, 'name' => 'Rename own rule', 'enabled' => 1, 'priority' => 10, 'target_type' => RuleRepository::TARGET_SERVICE, 'target_value' => 'manual_old', 'action_type' => RuleActionTypes::CHANGE_PRICE, 'operation_type' => RuleOperationTypes::INCREASE, 'operation_value' => 10, 'operation_base' => RuleOperationBases::RUBLES, 'operation_text' => '', 'promo_shipping' => 0, 'stop_processing' => 0, 'condition_group_logic' => array(), 'condition_group_expression' => 'condition_1' );
$rename_service = new DeliveryServiceKeyRenameService( $services, $rules );
$renamed = $rename_service->rename_manual_service( (int) $rename->id, 'manual_new' );
wdc_manual_assert( (int) $renamed->id === (int) $rename->id && null === $services->find_by_service_key( 'manual_old' ) && $services->find_by_service_key( 'manual_new' ) instanceof DeliveryService, 'Manual service key rename must update the same service row by immutable service_id.' );
wdc_manual_assert( array() !== $manual_geography->locations( (int) $renamed->id, 'RU' ) && array( 'RU' ) === $countries->countries( (int) $renamed->id ), 'Manual geography and countries must survive service_key rename because they are service_id scoped.' );
wdc_manual_assert( array() === $rules->get_all_rules_for_target( RuleRepository::TARGET_SERVICE, 'manual_old' ) && array() !== $rules->get_all_rules_for_target( RuleRepository::TARGET_SERVICE, 'manual_new' ), 'Manual service key rename must re-key current service rules.' );
wdc_manual_assert( $carrier->quote( $request_for( 'manual_new', 'RU', 'Новосибирск', 'Новосибирская область' ) )->success, 'Manual checkout quote must use the persisted new service_key after rename.' );
try {
	$rename_service->rename_manual_service( (int) $renamed->id, 'manual_geo_all_ru' );
	wdc_manual_assert( false, 'Duplicate manual service key rename must be rejected.' );
} catch ( InvalidArgumentException ) {
	wdc_manual_assert( $services->find_by_service_key( 'manual_new' ) instanceof DeliveryService && $services->find_by_service_key( 'manual_geo_all_ru' ) instanceof DeliveryService, 'Duplicate rename rejection must avoid partial migration.' );
}

$nsk_result = $orchestrator->calculate( $request_for( 'ignored', 'RU', 'Бердск', 'Новосибирская область' ) );
$nsk_keys = array_values( array_map( static fn ( $rate ): string => $rate->service_key, array_filter( $nsk_result->rates, static fn ( $rate ): bool => ManualDeliverySettings::CARRIER_KEY === $rate->carrier_key ) ) );
wdc_manual_assert( in_array( 'manual_geo_all_ru', $nsk_keys, true ) && in_array( 'manual_geo_nsk_region', $nsk_keys, true ) && ! in_array( 'manual_geo_barnaul_city', $nsk_keys, true ), 'Checkout runtime must return only manual services available for the destination region.' );
$barnaul_result = $orchestrator->calculate( $request_for( 'ignored', 'RU', 'Барнаул', 'Алтайский край' ) );
$barnaul_keys = array_values( array_map( static fn ( $rate ): string => $rate->service_key, array_filter( $barnaul_result->rates, static fn ( $rate ): bool => ManualDeliverySettings::CARRIER_KEY === $rate->carrier_key ) ) );
wdc_manual_assert( in_array( 'manual_geo_all_ru', $barnaul_keys, true ) && in_array( 'manual_geo_barnaul_city', $barnaul_keys, true ) && ! in_array( 'manual_geo_nsk_region', $barnaul_keys, true ), 'Checkout runtime must return only manual services available for the destination city pair.' );

$order_mapper = new OrderQuoteRequestMapper( $location_repo );
$order = new class {
	public function get_items(): array { return array(); }
	public function get_subtotal(): float { return 1000.0; }
	public function get_payment_method(): string { return ''; }
	public function get_shipping_country(): string { return 'RU'; }
	public function get_billing_country(): string { return 'RU'; }
	public function get_shipping_city(): string { return ''; }
	public function get_billing_city(): string { return ''; }
	public function get_shipping_postcode(): string { return ''; }
	public function get_billing_postcode(): string { return ''; }
	public function get_shipping_address_1(): string { return ''; }
	public function get_billing_address_1(): string { return ''; }
	public function get_shipping_address_2(): string { return ''; }
	public function get_billing_address_2(): string { return ''; }
	public function get_shipping_state(): string { return ''; }
	public function get_billing_state(): string { return ''; }
	public function get_item_count(): int { return 1; }
	public function get_id(): int { return 1502; }
	public function get_billing_phone(): string { return ''; }
	public function get_meta( string $key, bool $single = true ): mixed { return ''; }
};
$order_request = $order_mapper->map(
	$order,
	array(
		'id' => 10,
		'display_name' => 'Новосибирская область, г Новосибирск',
	)
);
wdc_manual_assert( 'Новосибирск' === (string) ( $order_request->customer_context['place_name'] ?? '' ) && 'Новосибирская область' === (string) ( $order_request->customer_context['region_name'] ?? '' ), 'OrderQuoteRequestMapper must resolve selected location id to canonical resolved_place_name and region_name instead of using presentation label.' );
wdc_manual_assert( $carrier->quote( new QuoteRequest( 'RU', $order_request->destination, $order_request->package, '', $order_request->order_total, $order_request->calculation_date, array_merge( $order_request->customer_context, array( 'service_key' => 'manual_new' ) ) ) )->success, 'Manual carrier must return the same city-restricted rate for order-admin recalculation as for checkout.' );

$root = dirname( __DIR__, 2 );
$shipment_creation = (string) file_get_contents( $root . '/src/Shipments/Application/ShipmentCreationService.php' );
$shipments_metabox = (string) file_get_contents( $root . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
$shipment_js = (string) file_get_contents( $root . '/assets/admin/shipments-admin.js' );
$plugin = (string) file_get_contents( $root . '/src/Core/Plugin.php' );
$checkout_orchestrator_source = (string) file_get_contents( $root . '/src/Checkout/Runtime/CheckoutOrchestrator.php' );
$order_mapper_source = (string) file_get_contents( $root . '/src/Orders/Application/OrderQuoteRequestMapper.php' );
$admin_source = (string) file_get_contents( $root . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
$manual_geo_source = (string) file_get_contents( $root . '/src/Carriers/Manual/ManualDeliveryGeographyRepository.php' ) . (string) file_get_contents( $root . '/src/Carriers/Manual/ManualDeliveryGeographyMatcher.php' );
$manual_pricing_source = (string) file_get_contents( $root . '/src/Carriers/Manual/ManualDeliveryPricingCalculator.php' ) . (string) file_get_contents( $root . '/src/Carriers/Manual/ManualDeliveryPricingService.php' ) . (string) file_get_contents( $root . '/src/Carriers/Manual/ManualDeliveryWeightRangeRepository.php' );
$migration_0061_source = (string) file_get_contents( $root . '/database/migrations/0061_make_manual_delivery_geography_country_aware.php' );
$migration_0062_source = (string) file_get_contents( $root . '/database/migrations/0062_create_manual_delivery_weight_ranges.php' );
wdc_manual_assert( ! str_contains( $shipment_creation, 'ManualDelivery' ) && ! str_contains( $shipment_creation, "carrier_key' => 'manual" ), 'Manual delivery foundation must not add a ShipmentCreationService branch.' );
wdc_manual_assert( ! str_contains( $shipments_metabox, 'ManualDelivery' ) && ! str_contains( $shipments_metabox, "carrier_key' => 'manual" ), 'Manual delivery foundation must not add an OrderShipmentsMetabox branch.' );
wdc_manual_assert( ! str_contains( $shipment_js, 'ManualDelivery' ) && ! str_contains( $shipment_js, "manual-delivery" ), 'Manual delivery foundation must not add generic shipment JS logic.' );
wdc_manual_assert( ! str_contains( $plugin, 'ManualDeliveryShipmentDocumentProvider' ) && ! str_contains( $plugin, 'ManualDeliveryShipmentModalExtension' ) && ! str_contains( $plugin, 'ManualDeliveryShipmentPersistenceMapper' ), 'Manual delivery foundation must not register shipment document/modal/persistence extensions.' );
wdc_manual_assert( str_contains( $plugin, 'ManualDeliveryCarrier::class' ) && str_contains( $plugin, '$registry->register( $this->container->get( ManualDeliveryCarrier::class ) );' ), 'Manual runtime carrier must be registered only through CarrierRegistry.' );
wdc_manual_assert( ! str_contains( $checkout_orchestrator_source, 'ManualDeliveryGeography' ) && ! str_contains( $checkout_orchestrator_source, 'ManualDeliveryPricing' ) && ! str_contains( $checkout_orchestrator_source, 'wdc_manual_delivery_' ), 'CheckoutOrchestrator must not contain manual geography/pricing SQL or carrier-specific branches.' );
wdc_manual_assert( ! str_contains( $manual_geo_source, 'location_id' ) && ! str_contains( $manual_geo_source, 'wp_wdc_locations.id' ), 'Manual geography must not depend on permanent location IDs.' );
wdc_manual_assert( str_contains( $manual_pricing_source, 'billing_weight_g' ) && str_contains( $manual_pricing_source, 'price_per_kg_kopecks * $billing_weight_g' ) && ! str_contains( $manual_pricing_source, 'zone_id' ) && ! str_contains( $manual_pricing_source, 'dbDelta' ), 'Manual pricing must be carrier-owned, integer based, zone-free, and must not create runtime schema.' );
wdc_manual_assert( str_contains( $admin_source, "wp_ajax_wdc_manual_delivery_region_search" ) && str_contains( $admin_source, "wp_ajax_wdc_manual_delivery_location_search" ) && str_contains( $admin_source, "current_user_can( AdminMenu::CAPABILITY )" ) && str_contains( $admin_source, "check_ajax_referer( 'wdc_manual_delivery_geography', 'nonce', false )" ), 'Manual geography admin search must use capability and nonce protected AJAX.' );
wdc_manual_assert( str_contains( $admin_source, 'manual_pricing_mode_options' ) && str_contains( $admin_source, 'manual_weight_ranges_from_post' ) && str_contains( $admin_source, 'manual_delivery_weight_ranges->validate_ranges' ) && str_contains( $admin_source, 'wdc_manual_pricing_notice' ), 'Manual pricing admin UI/save must expose typed modes and validate ranges before replacing stored rows.' );
wdc_manual_assert( str_contains( $admin_source, 'resolve_active_by_place_and_region' ) && str_contains( $admin_source, "'country_code' => strtoupper( trim( \$canonical->country_code ) )" ) && str_contains( $admin_source, "'location_name' => \$canonical->resolved_place_name()" ) && str_contains( $admin_source, "'region_name' => \$canonical->region_name" ) && str_contains( $admin_source, 'name="manual_locations[]"' ) && ! str_contains( $admin_source, 'name="manual_location_ids[]"' ), 'Manual geography admin save must canonicalize locations server-side as country_code plus location_name plus region_name, not location ID.' );
wdc_manual_assert( str_contains( $admin_source, 'save_manual_delivery_geography' ) && substr_count( $admin_source, 'clear_delivery_quote_cache();' ) >= 4, 'Manual geography saves must invalidate the shared delivery quote cache.' );
wdc_manual_assert( str_contains( $order_mapper_source, 'canonical_location' ) && str_contains( $order_mapper_source, 'resolved_place_name()' ) && str_contains( $order_mapper_source, "'place_name'" ) && str_contains( $order_mapper_source, '$address->settlement ?: $address->city' ), 'Order-admin quote mapping must build trusted region_name and place_name from canonical Location when a location id is selected.' );
wdc_manual_assert( str_contains( $migration_0061_source, 'country_code' ) && str_contains( $migration_0061_source, "country_code = 'RU'" ) && str_contains( $migration_0061_source, 'ux_manual_region_country' ) && str_contains( $migration_0061_source, 'ux_manual_location_country' ), 'Migration 0061 must add/backfill country-aware manual geography identity and unique indexes.' );
wdc_manual_assert( str_contains( $migration_0062_source, 'wdc_manual_delivery_weight_ranges' ) && str_contains( $migration_0062_source, 'ux_manual_weight_range' ) && str_contains( $migration_0062_source, 'from_weight_g' ) && str_contains( $migration_0062_source, 'price_kopecks' ), 'Migration 0062 must create manual delivery weight ranges with grams, kopecks, and unique range identity.' );

echo "Manual delivery foundation smoke test passed.\n";
