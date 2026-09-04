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
use WallsShop\WDC\Carriers\Manual\ManualDeliverySettings;
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

$carrier = new ManualDeliveryCarrier( $services, $manual_settings );
wdc_manual_assert( ManualDeliverySettings::CARRIER_KEY === $carrier->get_identity()->key && 'manual' === $carrier->get_identity()->type, 'Manual runtime carrier identity must be stable.' );

$request_for = static function ( string $service_key, string $country = 'RU' ): QuoteRequest {
	$item = new PackageItem( 'SKU', 'Item', 1, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), 1000, 10, 10, 10 );
	return new QuoteRequest(
		$country,
		new Address( country_code: $country, city: 'Новосибирск', raw_address: 'Новосибирск' ),
		Package::from_items( array( $item ), 0, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ) ),
		'',
		Money::from_rubles( 1000 ),
		'2026-09-04',
		array( 'service_key' => $service_key )
	);
};

$quote = $carrier->quote( $request_for( 'manual_nsk_courier' ) );
wdc_manual_assert( $quote->success && 1 === count( $quote->rates), 'Manual flat runtime must return one rate for an active manual service.' );
wdc_manual_assert( 35025 === $quote->rates[0]->price->get_kopecks() && DeliveryType::COURIER === $quote->rates[0]->delivery_type, 'Manual flat price must use integer kopecks and canonical courier type.' );
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
	null,
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

wdc_manual_assert( $manager->service_available_for_country( $services->find_by_service_key( 'manual_nsk_courier' ), 'RU' ), 'Selected-country manual service must be available for selected country.' );
wdc_manual_assert( ! $manager->service_available_for_country( $services->find_by_service_key( 'manual_nsk_courier' ), 'KZ' ), 'Selected-country manual service must not be available for an unselected country.' );
$all_id = $services->create_service( array( 'service_key' => 'manual_all', 'carrier_key' => ManualDeliverySettings::CARRIER_KEY, 'service_type' => DeliveryService::TYPE_MANUAL, 'title' => 'All', 'enabled' => 1, 'availability_mode' => DeliveryService::AVAILABILITY_ALL_COUNTRIES, 'deleted' => 0 ) );
$except_id = $services->create_service( array( 'service_key' => 'manual_except', 'carrier_key' => ManualDeliverySettings::CARRIER_KEY, 'service_type' => DeliveryService::TYPE_MANUAL, 'title' => 'Except', 'enabled' => 1, 'availability_mode' => DeliveryService::AVAILABILITY_ALL_EXCEPT_SELECTED, 'deleted' => 0 ) );
$countries->replace_countries( $except_id, array( 'KZ' ) );
wdc_manual_assert( $manager->service_available_for_country( $services->find_by_service_key( 'manual_all' ), 'AM' ), 'Manual all-countries mode must use shared availability infrastructure.' );
wdc_manual_assert( ! $manager->service_available_for_country( $services->find_by_service_key( 'manual_except' ), 'KZ' ) && $manager->service_available_for_country( $services->find_by_service_key( 'manual_except' ), 'RU' ), 'Manual all-except-selected mode must use shared availability infrastructure.' );

$root = dirname( __DIR__, 2 );
$shipment_creation = (string) file_get_contents( $root . '/src/Shipments/Application/ShipmentCreationService.php' );
$shipments_metabox = (string) file_get_contents( $root . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
$shipment_js = (string) file_get_contents( $root . '/assets/admin/shipments-admin.js' );
$plugin = (string) file_get_contents( $root . '/src/Core/Plugin.php' );
wdc_manual_assert( ! str_contains( $shipment_creation, 'ManualDelivery' ) && ! str_contains( $shipment_creation, "carrier_key' => 'manual" ), 'Manual delivery foundation must not add a ShipmentCreationService branch.' );
wdc_manual_assert( ! str_contains( $shipments_metabox, 'ManualDelivery' ) && ! str_contains( $shipments_metabox, "carrier_key' => 'manual" ), 'Manual delivery foundation must not add an OrderShipmentsMetabox branch.' );
wdc_manual_assert( ! str_contains( $shipment_js, 'ManualDelivery' ) && ! str_contains( $shipment_js, "manual-delivery" ), 'Manual delivery foundation must not add generic shipment JS logic.' );
wdc_manual_assert( ! str_contains( $plugin, 'ManualDeliveryShipmentDocumentProvider' ) && ! str_contains( $plugin, 'ManualDeliveryShipmentModalExtension' ) && ! str_contains( $plugin, 'ManualDeliveryShipmentPersistenceMapper' ), 'Manual delivery foundation must not register shipment document/modal/persistence extensions.' );
wdc_manual_assert( str_contains( $plugin, 'ManualDeliveryCarrier::class' ) && str_contains( $plugin, '$registry->register( $this->container->get( ManualDeliveryCarrier::class ) );' ), 'Manual runtime carrier must be registered only through CarrierRegistry.' );

echo "Manual delivery foundation smoke test passed.\n";
