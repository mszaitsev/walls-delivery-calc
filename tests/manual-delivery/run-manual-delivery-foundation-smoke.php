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
function sanitize_textarea_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', (string) $value ) ?? '' ); }
function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['wdc_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool $autoload = true ): bool { $GLOBALS['wdc_options'][ $option ] = $value; return true; }
function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . DIRECTORY_SEPARATOR; }
function __( string $text, string $domain = '' ): string { return $text; }
function esc_html( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( mixed $value ): string { return (string) $value; }
function wp_verify_nonce( string $nonce, string $action ): bool { return 'nonce' === $nonce && 'wp_rest' === $action; }

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
		public array $manual_pickup_points = array();
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
			if ( str_contains( $query, 'wdc_manual_delivery_pickup_points' ) && preg_match( '/service_id = ([0-9]+).*code = \'([^\']+)\'/', $query, $matches ) ) {
				foreach ( $this->manual_pickup_points as $row ) {
					if ( (int) $row['service_id'] === (int) $matches[1] && (string) $row['code'] === $matches[2] && ! empty( $row['active'] ) ) {
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
			if ( str_contains( $query, 'wdc_manual_delivery_pickup_points' ) && preg_match( '/service_id = ([0-9]+)/', $query, $matches ) ) {
				$country = preg_match( "/country_code = '([^']+)'/", $query, $country_match ) ? $country_match[1] : '';
				$region = preg_match( "/region_name = '([^']+)'/", $query, $region_match ) ? $region_match[1] : '';
				$location = preg_match( "/location_name = '([^']+)'/", $query, $location_match ) ? $location_match[1] : '';
				$rows = array_values( array_filter( $this->manual_pickup_points, static fn ( array $row ): bool => (int) $row['service_id'] === (int) $matches[1] && ( '' === $country || (string) $row['country_code'] === $country ) && ( '' === $region || (string) $row['region_name'] === $region ) && ( '' === $location || (string) $row['location_name'] === $location ) && ( ! str_contains( $query, 'active = 1' ) || ! empty( $row['active'] ) ) ) );
				usort( $rows, static fn ( array $left, array $right ): int => ( (int) $left['sort_order'] <=> (int) $right['sort_order'] ) ?: ( (int) $left['id'] <=> (int) $right['id'] ) );
				return str_contains( $query, 'LIMIT 1' ) ? array_slice( $rows, 0, 1 ) : $rows;
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
			if ( str_contains( $table, 'wdc_manual_delivery_pickup_points' ) ) {
				return $this->manual_pickup_points;
			}
			if ( str_contains( $table, 'wdc_rules' ) ) {
				return $this->rules;
			}
			return $this->services;
		}
	}
}

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	final class WdcManualSmokeMetaData {
		public function __construct( public string $key, public mixed $value ) {
		}

		/** @return array{key:string,value:mixed} */
		public function get_data(): array {
			return array( 'key' => $this->key, 'value' => $this->value );
		}
	}

	class WC_Shipping_Rate {
		/** @var array<int,WdcManualSmokeMetaData> */
		private array $meta_data = array();

		/**
		 * @param array<string,mixed> $meta_data
		 */
		public function __construct(
			public string $id,
			public string $label,
			public string $cost,
			array $meta_data = array()
		) {
			$index = 100;
			foreach ( $meta_data as $key => $value ) {
				$this->meta_data[ ++$index ] = new WdcManualSmokeMetaData( (string) $key, $value );
			}
		}

		public function get_id(): string {
			return $this->id;
		}

		public function get_label(): string {
			return $this->label;
		}

		public function get_cost(): string {
			return $this->cost;
		}

		public function get_meta( string $key, bool $single = true ): mixed {
			unset( $single );
			foreach ( $this->meta_data as $meta ) {
				if ( $meta->key === $key ) {
					return $meta->value;
				}
			}

			return '';
		}

		/** @return array<int,WdcManualSmokeMetaData> */
		public function get_meta_data(): array {
			return $this->meta_data;
		}
	}

	class WC_Shipping_Method {
		public string $id = '';
		public int $instance_id = 0;
		public string $method_title = '';
		public string $method_description = '';
		public string $enabled = 'yes';
		public string $title = '';
		/** @var array<int,string> */
		public array $supports = array();
		/** @var array<int,array<string,mixed>> */
		public array $rates = array();
		/** @var array<string,WC_Shipping_Rate> */
		public array $rate_objects = array();

		public function add_rate( array $rate ): void {
			$this->rates[] = $rate;
			$id = (string) ( $rate['id'] ?? '' );
			$this->rate_objects[ $id ] = new WC_Shipping_Rate( $id, (string) ( $rate['label'] ?? '' ), (string) ( $rate['cost'] ?? '' ), is_array( $rate['meta_data'] ?? null ) ? $rate['meta_data'] : array() );
		}
	}
}

final class WdcManualSmokeShipping {
	/** @var array<int,array<string,mixed>> */
	private array $packages = array();

	/** @param array<int,array<string,mixed>> $packages */
	public function set_packages( array $packages ): void {
		$this->packages = $packages;
	}

	/** @return array<int,array<string,mixed>> */
	public function get_packages(): array {
		return $this->packages;
	}
}

final class WdcManualSmokeSession {
	/** @var array<string,mixed> */
	private array $data = array();

	public function set( string $key, mixed $value ): void {
		$this->data[ $key ] = $value;
	}

	public function get( string $key, mixed $default = null ): mixed {
		return $this->data[ $key ] ?? $default;
	}

	public function __unset( string $key ): void {
		unset( $this->data[ $key ] );
	}

	public function save_data(): void {
	}
}

final class WdcManualSmokeWooCommerce {
	public WdcManualSmokeSession $session;
	private WdcManualSmokeShipping $shipping;

	public function __construct() {
		$this->session = new WdcManualSmokeSession();
		$this->shipping = new WdcManualSmokeShipping();
	}

	public function shipping(): WdcManualSmokeShipping {
		return $this->shipping;
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC(): WdcManualSmokeWooCommerce {
		static $woocommerce = null;
		if ( null === $woocommerce ) {
			$woocommerce = new WdcManualSmokeWooCommerce();
		}

		return $woocommerce;
	}
}

final class WdcManualZeroWeightProduct {
	public function get_sku(): string {
		return 'ZERO-WEIGHT';
	}

	public function get_name(): string {
		return 'Zero weight physical product';
	}

	public function get_weight(): string {
		return '';
	}

	public function get_length(): int {
		return 0;
	}

	public function get_width(): int {
		return 0;
	}

	public function get_height(): int {
		return 0;
	}

	public function needs_shipping(): bool {
		return true;
	}

	public function is_virtual(): bool {
		return false;
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
use WallsShop\WDC\Carriers\Manual\ManualPickupPointProvider;
use WallsShop\WDC\Carriers\Manual\ManualPickupPointRepository;
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
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\NewShippingMethod;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutRateRenderer;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceSessionBootstrapper;
use WallsShop\WDC\Checkout\WooCommerce\WooCommercePackageMapper;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMapper;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMetaNormalizer;
use WallsShop\WDC\Core\PluginEnvironment;
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
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderRegistry;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\CheckoutPickupPointProviderQueryResolver;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointSelectionQuery;
use WallsShop\WDC\Pickup\Providers\PickupCargoConstraints;
use WallsShop\WDC\Pickup\Rest\PickupPointsRestController;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;

$GLOBALS['wpdb'] = new wpdb();
$GLOBALS['wdc_options']['wdc_core_settings'] = array( SettingsRepository::SHOP_PROCESSING_WORKING_DAYS_KEY => 0 );

$services = new DeliveryServiceRepository( $GLOBALS['wpdb'] );
$settings_repo = new DeliveryServiceSettingsRepository( $GLOBALS['wpdb'] );
$manual_settings = new ManualDeliverySettings( $settings_repo );
$manual_geography = new ManualDeliveryGeographyRepository( $GLOBALS['wpdb'] );
$manual_matcher = new ManualDeliveryGeographyMatcher( $manual_geography );
$manual_weight_ranges = new ManualDeliveryWeightRangeRepository( $GLOBALS['wpdb'] );
$manual_pickup_points = new ManualPickupPointRepository( $GLOBALS['wpdb'] );
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

$carrier = new ManualDeliveryCarrier( $services, $manual_settings, $manual_matcher, $manual_pricing_service, $manual_pickup_points );
wdc_manual_assert( ManualDeliverySettings::CARRIER_KEY === $carrier->get_identity()->key && 'manual' === $carrier->get_identity()->type, 'Manual runtime carrier identity must be stable.' );
wdc_manual_assert( ManualDeliverySettings::DELIVERY_TYPE_COURIER === $manual_settings->delivery_type( (int) $nsk->id )['type'], 'Existing manual services without delivery-type settings must default to courier.' );

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

$manual_settings->save_delivery_type( (int) $nsk->id, ManualDeliverySettings::DELIVERY_TYPE_COURIER );
wdc_manual_assert( DeliveryType::COURIER === $carrier->quote( $request_for( 'manual_nsk_courier' ) )->rates[0]->delivery_type, 'Manual courier delivery type must map to DeliveryType::COURIER.' );
$manual_settings->save_delivery_type( (int) $nsk->id, ManualDeliverySettings::DELIVERY_TYPE_CUSTOM, 'До склада ТК' );
$custom_rate = $carrier->quote( $request_for( 'manual_nsk_courier' ) )->rates[0];
wdc_manual_assert( DeliveryType::UNKNOWN === $custom_rate->delivery_type && ! $custom_rate->requires_pickup_point && 'До склада ТК' === (string) $custom_rate->meta['manual_delivery_type_label'], 'Manual custom delivery type must use neutral DeliveryType::UNKNOWN and preserve custom label.' );
$manual_settings->save_delivery_type( (int) $nsk->id, ManualDeliverySettings::DELIVERY_TYPE_COURIER );

$pickup_a_id = $services->create_service( array( 'service_key' => 'manual_pickup_a', 'carrier_key' => ManualDeliverySettings::CARRIER_KEY, 'service_type' => DeliveryService::TYPE_MANUAL, 'title' => 'Manual pickup A', 'enabled' => 1, 'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES, 'use_default_rules_when_no_service_rules' => 0, 'round_up_to_ruble' => 0, 'minimum_price_rub' => 0, 'deleted' => 0 ) );
$pickup_b_id = $services->create_service( array( 'service_key' => 'manual_pickup_b', 'carrier_key' => ManualDeliverySettings::CARRIER_KEY, 'service_type' => DeliveryService::TYPE_MANUAL, 'title' => 'Manual pickup B', 'enabled' => 1, 'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES, 'use_default_rules_when_no_service_rules' => 0, 'round_up_to_ruble' => 0, 'minimum_price_rub' => 0, 'deleted' => 0 ) );
$manual_settings->save_flat_pricing( $pickup_a_id, '410' );
$manual_settings->save_flat_pricing( $pickup_b_id, '420' );
$manual_settings->save_delivery_type( $pickup_a_id, ManualDeliverySettings::DELIVERY_TYPE_PICKUP );
$manual_settings->save_delivery_type( $pickup_b_id, ManualDeliverySettings::DELIVERY_TYPE_PICKUP );
$countries->replace_countries( $pickup_a_id, array( 'RU' ) );
$countries->replace_countries( $pickup_b_id, array( 'RU' ) );
wdc_manual_assert( ! $carrier->quote( $request_for( 'manual_pickup_a', 'RU', 'Новосибирск', 'Новосибирская область', array( 'location_id' => 10 ) ) )->success, 'Manual pickup service must be unavailable when destination has no active eligible pickup points.' );
$manual_pickup_points->replace_points(
	$pickup_a_id,
	array(
		array( 'code' => 'manual-a-1', 'title' => 'ПВЗ A', 'country_code' => 'RU', 'location_name' => 'Новосибирск', 'region_name' => 'Новосибирская область', 'address' => 'Красный проспект, 1', 'active' => 1 ),
	)
);
$manual_pickup_points->replace_points(
	$pickup_b_id,
	array(
		array( 'code' => 'manual-b-1', 'title' => 'ПВЗ B', 'country_code' => 'RU', 'location_name' => 'Новосибирск', 'region_name' => 'Новосибирская область', 'address' => 'Красный проспект, 2', 'active' => 1 ),
	)
);
$pickup_rate_a = $carrier->quote( $request_for( 'manual_pickup_a', 'RU', 'Новосибирск', 'Новосибирская область', array( 'location_id' => 10 ) ) )->rates[0] ?? null;
wdc_manual_assert( null !== $pickup_rate_a && DeliveryType::PICKUP === $pickup_rate_a->delivery_type && $pickup_rate_a->requires_pickup_point && 'manual:manual_pickup_a:pickup' === (string) $pickup_rate_a->meta['pickup_family'], 'Manual pickup rate must use DeliveryType::PICKUP, require pickup point, and isolate family by service key.' );
$provider = new ManualPickupPointProvider( $services, $manual_pickup_points );
$pickup_provider_registry = new CarrierPickupPointProviderRegistry( array( $provider ) );
wdc_manual_assert( $pickup_provider_registry->has( ManualDeliverySettings::CARRIER_KEY ) && 1 === count( $pickup_provider_registry->all() ), 'Manual pickup provider must be registered once for the manual carrier.' );
$session_manager = new CheckoutSessionManager();
wdc_manual_assert( 'manual:manual_pickup_a:pickup' === $session_manager->normalize_pickup_family( 'manual:manual_pickup_a:pickup' ), 'Checkout pickup family normalization must preserve service-specific manual pickup families.' );
$rate_mapper = new WooCommerceRateMapper();
$wc_rate = $rate_mapper->map( $pickup_rate_a );
$session_manager->save_rates( array( $wc_rate['id'] => array_merge( $wc_rate['meta_data'], array( 'rate_id' => $wc_rate['id'] ) ) ) );
$resolver = new CheckoutPickupPointProviderQueryResolver( $session_manager );
$query = $resolver->resolve( $wc_rate['id'], ManualDeliverySettings::CARRIER_KEY, 'manual:manual_pickup_a:pickup' );
wdc_manual_assert( 'manual_pickup_a' === $query->normalized_service_key() && 'Новосибирск' === $query->location_name && 'Новосибирская область' === $query->region_name, 'Pickup provider query must carry trusted concrete service key and canonical locality from rate metadata.' );
$textual_locator_query = new CarrierPickupPointQuery(
	ManualDeliverySettings::CARRIER_KEY,
	0,
	'RU',
	'',
	null,
	null,
	new PickupCargoConstraints( 1000, 0, 0, 1000, 2 ),
	CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP,
	50,
	50,
	'manual_pickup_a',
	'Новосибирская область',
	'Новосибирск'
);
wdc_manual_assert( array() === $textual_locator_query->validate(), 'CarrierPickupPointQuery must accept country+region+location textual locality as a valid destination locator when location_id is 0.' );
$missing_locator_query = new CarrierPickupPointQuery(
	ManualDeliverySettings::CARRIER_KEY,
	0,
	'RU',
	'',
	null,
	null,
	new PickupCargoConstraints( 1000, 0, 0, 1000, 1 ),
	CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP,
	50,
	50,
	'manual_pickup_a'
);
wdc_manual_assert( array() !== $missing_locator_query->validate(), 'CarrierPickupPointQuery must reject snapshots without any location id, textual locality, fallback address, or coordinates.' );
$textual_snapshot = $wc_rate['meta_data']['rate_meta']['pickup_provider_query'];
$textual_snapshot['location_id'] = 0;
$textual_snapshot['region_name'] = 'Новосибирская область';
$textual_snapshot['location_name'] = 'Новосибирск';
$textual_snapshot['cargo']['places_count'] = 2;
$textual_session_rate = array_merge(
	$wc_rate['meta_data'],
	array(
		'rate_id' => $wc_rate['id'],
		'pickup_provider_query' => $textual_snapshot,
		'rate_meta' => array_merge( $wc_rate['meta_data']['rate_meta'], array( 'pickup_provider_query' => $textual_snapshot ) ),
	)
);
$session_manager->save_rates( array( $wc_rate['id'] => $textual_session_rate, NewShippingMethod::METHOD_ID . ':' . $wc_rate['id'] => $textual_session_rate ) );
$textual_query = $resolver->resolve( NewShippingMethod::METHOD_ID . ':' . $wc_rate['id'], ManualDeliverySettings::CARRIER_KEY, 'manual:manual_pickup_a:pickup' );
wdc_manual_assert( 0 === $textual_query->location_id && 2 === $textual_query->cargo->places_count && 'Новосибирск' === $textual_query->location_name, 'Checkout pickup provider resolver must accept textual locality with location_id=0 and multi-place cargo.' );
$points_a = $provider->search( $query );
wdc_manual_assert( 1 === count( $points_a ) && 'manual-a-1' === $points_a[0]->code, 'Manual provider search must return only points belonging to the selected manual service and locality.' );
wdc_manual_assert( null === $provider->resolve_selection( new CarrierPickupPointSelectionQuery( $query, 'manual-b-1' ) ), 'Manual provider resolve_selection must reject a point code from another manual service.' );
wdc_manual_assert( $provider->resolve_selection( new CarrierPickupPointSelectionQuery( $query, 'manual-a-1' ) ) instanceof \WallsShop\WDC\Domain\Pickup\PickupPoint, 'Manual provider resolve_selection must re-resolve selected point server-side.' );
$_SERVER['HTTP_X_WP_NONCE'] = 'nonce';
$pickup_rest = new PickupPointsRestController(
	new RussianPostPickupPointRepository( $GLOBALS['wpdb'] ),
	null,
	null,
	null,
	null,
	null,
	null,
	null,
	$pickup_provider_registry,
	$resolver,
	null,
	new WooCommerceSessionBootstrapper()
);
$rest_points_without_coords = $pickup_rest->points( array( 'carrier' => 'manual', 'shipping_method_id' => NewShippingMethod::METHOD_ID . ':' . $wc_rate['id'], 'pickup_family' => 'manual:manual_pickup_a:pickup', 'limit' => 50 ) );
wdc_manual_assert( is_array( $rest_points_without_coords ) && 1 === count( $rest_points_without_coords ) && 'manual-a-1' === (string) ( $rest_points_without_coords[0]['point_code'] ?? '' ), 'PickupPointsRestController must return a manual point through resolver/provider when location_id is 0 and textual locality is present.' );
$manual_pickup_points->replace_points(
	$pickup_a_id,
	array(
		array( 'code' => 'manual-a-1', 'title' => 'ПВЗ A', 'country_code' => 'RU', 'location_name' => 'Новосибирск', 'region_name' => 'Новосибирская область', 'address' => 'Красный проспект, 1', 'latitude' => 55.0302, 'longitude' => 82.9204, 'active' => 1 ),
	)
);
$rest_points_with_coords = $pickup_rest->points( array( 'carrier' => 'manual', 'shipping_method_id' => $wc_rate['id'], 'pickup_family' => 'manual:manual_pickup_a:pickup', 'limit' => 50 ) );
wdc_manual_assert( is_array( $rest_points_with_coords ) && 1 === count( $rest_points_with_coords ) && 'manual-a-1' === (string) ( $rest_points_with_coords[0]['point_code'] ?? '' ) && 55.0302 === (float) $rest_points_with_coords[0]['lat'], 'Manual pickup REST must return points with coordinates through the same generic resolver path.' );
$cached_wc_rate = new WC_Shipping_Rate( $wc_rate['id'], (string) $wc_rate['label'], (string) $wc_rate['cost'], $wc_rate['meta_data'] );
WC()->shipping()->set_packages(
	array(
		array(
			'rates' => array(
				NewShippingMethod::METHOD_ID . ':' . $wc_rate['id'] => $cached_wc_rate,
			),
		),
	)
);
wdc_manual_assert( 'manual:manual_pickup_a:pickup' === (string) ( WooCommerceRateMetaNormalizer::meta( $cached_wc_rate )['pickup_family'] ?? '' ), 'Reusable WooCommerce rate meta normalizer must unwrap real WC meta objects for manual pickup family context.' );
WC()->session = new WdcManualSmokeSession();
$empty_wdc_rates_session = new CheckoutSessionManager();
wdc_manual_assert( array() === $empty_wdc_rates_session->rates(), 'Regression setup must start with an empty WDC duplicate rates snapshot.' );
ob_start();
( new CheckoutRateRenderer( $empty_wdc_rates_session ) )->render( $cached_wc_rate, 0 );
$cached_rate_html = (string) ob_get_clean();
wdc_manual_assert( str_contains( $cached_rate_html, 'data-wdc-pickup-checkout' ) && str_contains( $cached_rate_html, 'data-wdc-pickup-open' ) && str_contains( $cached_rate_html, 'Выбрать пункт выдачи' ), 'CheckoutRateRenderer must render selector from the actual cached WC_Shipping_Rate without requiring a prior WDC rates snapshot.' );
$cached_wc_resolver = new CheckoutPickupPointProviderQueryResolver( $empty_wdc_rates_session );
$cached_wc_query = $cached_wc_resolver->resolve( NewShippingMethod::METHOD_ID . ':' . $wc_rate['id'], ManualDeliverySettings::CARRIER_KEY, 'manual:manual_pickup_a:pickup' );
wdc_manual_assert( 'manual_pickup_a' === $cached_wc_query->normalized_service_key() && 'Новосибирск' === $cached_wc_query->location_name, 'Provider resolver must use the authoritative cached WooCommerce rate when WDC rates are empty.' );
$_SERVER['HTTP_X_WP_NONCE'] = 'nonce';
$cached_wc_rest = new PickupPointsRestController(
	new RussianPostPickupPointRepository( $GLOBALS['wpdb'] ),
	null,
	null,
	null,
	null,
	null,
	null,
	null,
	$pickup_provider_registry,
	$cached_wc_resolver,
	null,
	new WooCommerceSessionBootstrapper()
);
$cached_wc_points = $cached_wc_rest->points( array( 'carrier' => 'manual', 'shipping_method_id' => NewShippingMethod::METHOD_ID . ':' . $wc_rate['id'], 'pickup_family' => 'manual:manual_pickup_a:pickup', 'limit' => 50 ) );
wdc_manual_assert( is_array( $cached_wc_points ) && 1 === count( $cached_wc_points ) && 'manual-a-1' === (string) ( $cached_wc_points[0]['point_code'] ?? '' ), 'Pickup REST must return manual points from an authoritative cached WC rate even when wdc_platform_rates is empty.' );
WC()->session = new WdcManualSmokeSession();
$stale_wdc_rates_session = new CheckoutSessionManager();
$stale_wdc_rates_session->save_rates(
	array(
		$wc_rate['id'] => array_merge(
			$wc_rate['meta_data'],
			array(
				'rate_id' => $wc_rate['id'],
				'delivery_type' => DeliveryType::COURIER,
				'requires_pickup_point' => false,
				'pickup_family' => 'manual:manual_pickup_a:courier',
				'rate_meta' => array_merge(
					is_array( $wc_rate['meta_data']['rate_meta'] ?? null ) ? $wc_rate['meta_data']['rate_meta'] : array(),
					array(
						'delivery_type' => DeliveryType::COURIER,
						'requires_pickup_point' => false,
						'pickup_family' => 'manual:manual_pickup_a:courier',
					)
				),
			)
		),
	)
);
$stale_snapshot_rest = new PickupPointsRestController(
	new RussianPostPickupPointRepository( $GLOBALS['wpdb'] ),
	null,
	null,
	null,
	null,
	null,
	null,
	null,
	$pickup_provider_registry,
	new CheckoutPickupPointProviderQueryResolver( $stale_wdc_rates_session ),
	null,
	new WooCommerceSessionBootstrapper()
);
$stale_snapshot_points = $stale_snapshot_rest->points( array( 'carrier' => 'manual', 'shipping_method_id' => $wc_rate['id'], 'pickup_family' => 'manual:manual_pickup_a:pickup', 'limit' => 50 ) );
wdc_manual_assert( is_array( $stale_snapshot_points ) && 1 === count( $stale_snapshot_points ) && 'manual-a-1' === (string) ( $stale_snapshot_points[0]['point_code'] ?? '' ), 'Authoritative WooCommerce pickup rate must win over a stale WDC courier snapshot for the same rate id.' );
$mismatched_context = $stale_snapshot_rest->points( array( 'carrier' => 'manual', 'shipping_method_id' => $wc_rate['id'], 'pickup_family' => 'russian_post:pickup', 'limit' => 50 ) );
wdc_manual_assert( is_array( $mismatched_context ) && 'provider_rate_context_mismatch' === (string) ( $mismatched_context['code'] ?? '' ), 'Browser carrier/family mismatch must still fail closed even when an authoritative WooCommerce rate exists.' );
unset( $_SERVER['HTTP_X_WP_NONCE'] );
$missing_nonce = $cached_wc_rest->points( array( 'carrier' => 'manual', 'shipping_method_id' => $wc_rate['id'], 'pickup_family' => 'manual:manual_pickup_a:pickup', 'limit' => 50 ) );
wdc_manual_assert( is_array( $missing_nonce ) && 'wdc_forbidden' === (string) ( $missing_nonce['code'] ?? '' ), 'Registry-backed pickup REST must keep rejecting missing nonce for manual pickup requests.' );
$_SERVER['HTTP_X_WP_NONCE'] = 'nonce';
WC()->shipping()->set_packages( array() );
$manual_pickup_points->replace_points( $pickup_a_id, array( array( 'code' => 'manual-a-1', 'title' => 'ПВЗ A', 'country_code' => 'RU', 'location_name' => 'Новосибирск', 'region_name' => 'Новосибирская область', 'address' => 'Красный проспект, 1', 'active' => 0 ) ) );
wdc_manual_assert( ! $carrier->quote( $request_for( 'manual_pickup_a', 'RU', 'Новосибирск', 'Новосибирская область', array( 'location_id' => 10 ) ) )->success, 'Manual pickup rate must disappear after deactivating the last eligible pickup point.' );

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
$custom_display_id = $services->create_service( array( 'service_key' => 'manual_custom_display', 'carrier_key' => ManualDeliverySettings::CARRIER_KEY, 'service_type' => DeliveryService::TYPE_MANUAL, 'title' => 'Manual Test', 'enabled' => 1, 'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES, 'use_default_rules_when_no_service_rules' => 0, 'round_up_to_ruble' => 0, 'minimum_price_rub' => 0, 'deleted' => 0 ) );
$countries->replace_countries( $custom_display_id, array( 'RU' ) );
$manual_settings->save_flat_pricing( $custom_display_id, '300' );
$manual_settings->save_delivery_type( $custom_display_id, ManualDeliverySettings::DELIVERY_TYPE_CUSTOM, 'До склада ТК' );

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
$custom_display_rate = array_values( array_filter( $manual_rates, static fn ( $rate ): bool => 'manual_custom_display' === $rate->service_key ) )[0] ?? null;
wdc_manual_assert( null !== $custom_display_rate && DeliveryType::UNKNOWN === $custom_display_rate->delivery_type && ! empty( $custom_display_rate->meta['preserve_rate_title'] ), 'Manual custom delivery type must preserve the carrier-owned display title through service decoration.' );
$custom_display_wc_rate = ( new WooCommerceRateMapper() )->map( $custom_display_rate );
wdc_manual_assert( str_contains( $custom_display_wc_rate['label'], 'Manual Test' ) && str_contains( $custom_display_wc_rate['label'], 'До склада ТК' ), 'Manual custom type label must survive ManualDeliveryCarrier -> CheckoutOrchestrator -> WooCommerceRateMapper presentation flow.' );
wdc_manual_assert( 'manual_pricing' === ManualDeliverySettings::PRICING_SETTING_KEY && ! str_contains( (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Rules/Services/RuleEngine.php' ), 'manual_rules_enabled' ), 'Manual delivery must not add a manual-specific rule-engine branch.' );
$per_kg_rate = array_values( array_filter( $manual_rates, static fn ( $rate ): bool => 'manual_per_kg' === $rate->service_key ) )[0] ?? null;
$range_rate = array_values( array_filter( $manual_rates, static fn ( $rate ): bool => 'manual_ranges' === $rate->service_key ) )[0] ?? null;
$pickup_display_rate = array_values( array_filter( $manual_rates, static fn ( $rate ): bool => 'manual_pickup_b' === $rate->service_key ) )[0] ?? null;
wdc_manual_assert( null !== $pickup_display_rate && 'Manual pickup B' === $pickup_display_rate->title && empty( $pickup_display_rate->meta['preserve_rate_title'] ), 'Manual pickup title must stay on the normal service-title presentation path.' );
$pickup_display_wc_rate = ( new WooCommerceRateMapper() )->map( $pickup_display_rate );
wdc_manual_assert( 'pickup' === (string) $pickup_display_wc_rate['meta_data']['delivery_type'] && ! empty( $pickup_display_wc_rate['meta_data']['requires_pickup_point'] ) && 'manual:manual_pickup_b:pickup' === (string) $pickup_display_wc_rate['meta_data']['pickup_family'] && is_array( $pickup_display_wc_rate['meta_data']['rate_meta']['pickup_provider_query'] ?? null ) && empty( $pickup_display_wc_rate['meta_data']['no_pickup_selection'] ) && empty( $pickup_display_wc_rate['meta_data']['rate_meta']['no_pickup_selection'] ?? false ), 'Manual pickup WooCommerce rate metadata must require pickup selection without suppressing the selector.' );
$renderer = new CheckoutRateRenderer( new CheckoutSessionManager() );
$pickup_method = (object) array( 'id' => $pickup_display_wc_rate['id'], 'meta_data' => $pickup_display_wc_rate['meta_data'] );
ob_start();
$renderer->render( $pickup_method, 0 );
$pickup_html = (string) ob_get_clean();
wdc_manual_assert( str_contains( $pickup_html, 'data-wdc-pickup-open' ) && str_contains( $pickup_html, 'Выбрать пункт выдачи' ), 'CheckoutRateRenderer must render the generic pickup selector button for manual pickup rates without a selection.' );
$suppressed_method = (object) array(
	'id' => 'synthetic:pickup',
	'meta_data' => array_merge(
		$pickup_display_wc_rate['meta_data'],
		array( 'rate_id' => 'synthetic:pickup', 'carrier_key' => 'synthetic', 'service_key' => 'synthetic', 'pickup_family' => 'synthetic:pickup', 'no_pickup_selection' => true )
	),
);
ob_start();
$renderer->render( $suppressed_method, 0 );
$suppressed_html = (string) ob_get_clean();
wdc_manual_assert( ! str_contains( $suppressed_html, 'data-wdc-pickup-open' ), 'Existing no_pickup_selection suppression contract must continue to hide the pickup selector.' );
wdc_manual_assert( null !== $per_kg_rate && 20000 === $per_kg_rate->price->get_kopecks() && 150.0 === (float) $per_kg_rate->meta['api_base_price_rub'] && 150.0 === (float) $per_kg_rate->meta['original_price_rub'], 'Manual per-kg base price must pass through default rules and existing post-processing without a manual Rule Engine branch.' );
wdc_manual_assert( null !== $range_rate && 40000 === $range_rate->price->get_kopecks() && 350.0 === (float) $range_rate->meta['api_base_price_rub'] && ManualDeliverySettings::PRICING_MODE_WEIGHT_RANGES === (string) $range_rate->meta['manual_pricing_mode'], 'Manual weight range base price must pass through default rules and existing post-processing.' );

$zero_flat_id = $services->create_service( array( 'service_key' => 'manual_zero_flat', 'carrier_key' => ManualDeliverySettings::CARRIER_KEY, 'service_type' => DeliveryService::TYPE_MANUAL, 'title' => 'Zero flat', 'enabled' => 1, 'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES, 'use_default_rules_when_no_service_rules' => 0, 'round_up_to_ruble' => 0, 'minimum_price_rub' => 0, 'include_packaging_weight' => 0, 'deleted' => 0 ) );
$zero_per_kg_id = $services->create_service( array( 'service_key' => 'manual_zero_per_kg', 'carrier_key' => ManualDeliverySettings::CARRIER_KEY, 'service_type' => DeliveryService::TYPE_MANUAL, 'title' => 'Zero per kg', 'enabled' => 1, 'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES, 'use_default_rules_when_no_service_rules' => 0, 'round_up_to_ruble' => 0, 'minimum_price_rub' => 0, 'include_packaging_weight' => 0, 'deleted' => 0 ) );
$zero_ranges_id = $services->create_service( array( 'service_key' => 'manual_zero_ranges', 'carrier_key' => ManualDeliverySettings::CARRIER_KEY, 'service_type' => DeliveryService::TYPE_MANUAL, 'title' => 'Zero ranges', 'enabled' => 1, 'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES, 'use_default_rules_when_no_service_rules' => 0, 'round_up_to_ruble' => 0, 'minimum_price_rub' => 0, 'include_packaging_weight' => 0, 'deleted' => 0 ) );
$countries->replace_countries( $zero_flat_id, array( 'RU' ) );
$countries->replace_countries( $zero_per_kg_id, array( 'RU' ) );
$countries->replace_countries( $zero_ranges_id, array( 'RU' ) );
$manual_settings->save_pricing( $zero_flat_id, array( 'pricing_mode' => ManualDeliverySettings::PRICING_MODE_FLAT, 'flat_price_rub' => '400' ) );
$manual_settings->save_pricing( $zero_per_kg_id, array( 'pricing_mode' => ManualDeliverySettings::PRICING_MODE_PER_KG, 'price_per_kg_rub' => '150', 'billing_weight_step_g' => ManualDeliverySettings::BILLING_STEP_1_KG ) );
$manual_settings->save_pricing( $zero_ranges_id, array( 'pricing_mode' => ManualDeliverySettings::PRICING_MODE_WEIGHT_RANGES, 'billing_weight_step_g' => ManualDeliverySettings::BILLING_STEP_NONE_G ) );
$manual_weight_ranges->replace_ranges( $zero_ranges_id, array( new ManualDeliveryWeightRange( 0, 2000, 35000, 1 ) ) );
$zero_wc_package = array(
	'destination' => array( 'country' => 'RU', 'city' => 'Новосибирск', 'state' => 'Новосибирская область', 'postcode' => '630000', 'address_1' => 'Советская', 'address_2' => '1' ),
	'contents_cost' => 400,
	'contents_weight' => 0,
	'contents' => array( array( 'data' => new WdcManualZeroWeightProduct(), 'quantity' => 1, 'line_total' => 400 ) ),
);
$checkout_session_for_zero_package = new CheckoutSessionManager();
$checkout_session_for_zero_package->save_selected_city(
	array(
		'id'           => 10,
		'display_name' => 'Новосибирская область, г Новосибирск',
		'country_code' => 'RU',
		'region_name'  => 'Новосибирская область',
		'place_name'   => 'Новосибирск',
		'city_name'    => 'Новосибирск',
	)
);
$checkout_session_for_zero_package->save_city_context(
	array(
		'location_id'  => 10,
		'display_name' => 'Новосибирская область, г Новосибирск',
		'country_code' => 'RU',
		'region_name'  => 'Новосибирская область',
		'place_name'   => 'Новосибирск',
		'city_name'    => 'Новосибирск',
	)
);
$zero_mapper = new WooCommercePackageMapper( null, $checkout_session_for_zero_package );
$zero_request = $zero_mapper->map( $zero_wc_package );
wdc_manual_assert( 1 === count( $zero_request->package->items ) && 1 === $zero_request->package->get_total_quantity() && 0 === $zero_request->package->weight_g && 0 === $zero_request->package->get_total_weight_g(), 'WooCommerce package mapper must preserve zero-weight physical items without substituting 1 g or dropping the item.' );
$zero_result = $orchestrator->calculate( $zero_request, array(), RateSorter::CHEAPEST, false );
$zero_keys = array_values( array_map( static fn ( $rate ): string => $rate->service_key, array_filter( $zero_result->rates, static fn ( $rate ): bool => ManualDeliverySettings::CARRIER_KEY === $rate->carrier_key && str_starts_with( $rate->service_key, 'manual_zero_' ) ) ) );
wdc_manual_assert( array( 'manual_zero_flat' ) === $zero_keys, 'CheckoutOrchestrator path must keep flat manual service available for a zero-weight physical package while weight-based manual services fail closed.' );
NewShippingMethod::configure(
	$orchestrator,
	$zero_mapper,
	new WooCommerceRateMapper(),
	$checkout_session_for_zero_package,
	$rules,
	new SettingsRepository(),
	new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ), '', '0.152.5' ),
	new \WallsShop\WDC\Infrastructure\Logging\Logger(),
	$manager
);
$zero_method = new NewShippingMethod();
$zero_method->calculate_shipping( $zero_wc_package );
$zero_wc_keys = array_values( array_map( static fn ( array $rate ): string => (string) ( $rate['meta_data']['service_key'] ?? '' ), array_filter( $zero_method->rates, static fn ( array $rate ): bool => ManualDeliverySettings::CARRIER_KEY === (string) ( $rate['meta_data']['carrier_key'] ?? '' ) && str_starts_with( (string) ( $rate['meta_data']['service_key'] ?? '' ), 'manual_zero_' ) ) ) );
wdc_manual_assert( array( 'manual_zero_flat' ) === $zero_wc_keys, 'NewShippingMethod must render only the flat manual rate for a zero-weight physical WooCommerce product.' );
$actual_pickup_rate = $zero_method->rate_objects['manual:manual_pickup_b'] ?? null;
wdc_manual_assert( $actual_pickup_rate instanceof WC_Shipping_Rate, 'NewShippingMethod::calculate_shipping must create an actual WC_Shipping_Rate-like object for manual pickup.' );
wdc_manual_assert( DeliveryType::PICKUP === $actual_pickup_rate->get_meta( 'delivery_type', true ) && true === $actual_pickup_rate->get_meta( 'requires_pickup_point', true ) && 'manual:manual_pickup_b:pickup' === $actual_pickup_rate->get_meta( 'pickup_family', true ) && empty( $actual_pickup_rate->get_meta( 'no_pickup_selection', true ) ), 'Actual WC_Shipping_Rate manual pickup metadata must preserve pickup selector capabilities.' );
$actual_meta_shape = $actual_pickup_rate->get_meta_data();
wdc_manual_assert( array() !== $actual_meta_shape && array_keys( $actual_meta_shape ) !== range( 0, count( $actual_meta_shape ) - 1 ), 'Manual smoke WC_Shipping_Rate stub must expose non-list meta objects like real WooCommerce can.' );
ob_start();
( new CheckoutRateRenderer( new CheckoutSessionManager() ) )->render( $actual_pickup_rate, 0 );
$actual_pickup_html = (string) ob_get_clean();
wdc_manual_assert( str_contains( $actual_pickup_html, 'data-wdc-pickup-checkout' ) && str_contains( $actual_pickup_html, 'data-wdc-pickup-open' ) && str_contains( $actual_pickup_html, 'Выбрать пункт выдачи' ), 'CheckoutRateRenderer must render the pickup selector for the actual WC_Shipping_Rate produced by NewShippingMethod::add_rate.' );
$manual_pickup_points->replace_points( $pickup_b_id, array( array( 'code' => 'manual-b-1', 'title' => 'ПВЗ B', 'country_code' => 'RU', 'location_name' => 'Новосибирск', 'region_name' => 'Новосибирская область', 'address' => 'Красный проспект, 2', 'latitude' => 55.0302, 'longitude' => 82.9204, 'active' => 1 ) ) );
$coords_method = new NewShippingMethod();
$coords_method->calculate_shipping( $zero_wc_package );
$coords_pickup_rate = $coords_method->rate_objects['manual:manual_pickup_b'] ?? null;
wdc_manual_assert( $coords_pickup_rate instanceof WC_Shipping_Rate, 'Manual pickup rate with coordinates must still be produced through NewShippingMethod.' );
ob_start();
( new CheckoutRateRenderer( new CheckoutSessionManager() ) )->render( $coords_pickup_rate, 0 );
$coords_pickup_html = (string) ob_get_clean();
wdc_manual_assert( str_contains( $coords_pickup_html, 'data-wdc-pickup-open' ) && str_contains( $coords_pickup_html, 'Выбрать пункт выдачи' ), 'Manual pickup coordinates must not control whether the selector button is rendered.' );
$manual_pickup_points->replace_points(
	$pickup_b_id,
	array(
		array( 'code' => 'manual-b-1', 'title' => 'ПВЗ B', 'country_code' => 'RU', 'location_name' => 'Тестград', 'region_name' => 'Тестовая область', 'address' => 'Тестовая, 2', 'active' => 1 ),
	)
);
WC()->session = new WdcManualSmokeSession();
$cold_checkout_session = new CheckoutSessionManager();
$cold_checkout_session->save_selected_city(
	array(
		'display_name' => 'Тестовая область, г Тестград',
		'country_code' => 'RU',
		'region_name'  => 'Тестовая область',
		'place_name'   => 'Тестград',
		'city_name'    => 'Тестград',
	)
);
$cold_checkout_session->save_city_context(
	array(
		'location_id'  => 0,
		'display_name' => 'Тестовая область, г Тестград',
		'country_code' => 'RU',
		'region_name'  => 'Тестовая область',
		'place_name'   => 'Тестград',
		'city_name'    => 'Тестград',
	)
);
$cold_wc_package = $zero_wc_package;
$cold_wc_package['destination']['city'] = 'Тестград';
$cold_wc_package['destination']['state'] = 'Тестовая область';
$cold_mapper = new WooCommercePackageMapper( null, $cold_checkout_session );
NewShippingMethod::configure(
	$orchestrator,
	$cold_mapper,
	new WooCommerceRateMapper(),
	$cold_checkout_session,
	$rules,
	new SettingsRepository(),
	new PluginEnvironment( __FILE__, dirname( __DIR__, 2 ), '', '0.152.5' ),
	new \WallsShop\WDC\Infrastructure\Logging\Logger(),
	$manager
);
$cold_method = new NewShippingMethod();
$cold_method->calculate_shipping( $cold_wc_package );
$cold_pickup_rate = $cold_method->rate_objects['manual:manual_pickup_b'] ?? null;
wdc_manual_assert( $cold_pickup_rate instanceof WC_Shipping_Rate, 'Cold first checkout calculation must produce a manual pickup WC rate.' );
$cold_saved_rate = $cold_checkout_session->rates()['manual:manual_pickup_b'] ?? array();
$cold_rate_meta = is_array( $cold_saved_rate['rate_meta'] ?? null ) ? $cold_saved_rate['rate_meta'] : array();
$cold_provider_snapshot = is_array( $cold_rate_meta['pickup_provider_query'] ?? null ) ? $cold_rate_meta['pickup_provider_query'] : array();
wdc_manual_assert( 0 === (int) ( $cold_provider_snapshot['location_id'] ?? -1 ) && 'Тестград' === (string) ( $cold_provider_snapshot['location_name'] ?? '' ) && 'Тестовая область' === (string) ( $cold_provider_snapshot['region_name'] ?? '' ), 'Cold first checkout calculation must save a provider snapshot that relies on textual locality when location_id is 0.' );
ob_start();
( new CheckoutRateRenderer( $cold_checkout_session ) )->render( $cold_pickup_rate, 0 );
$cold_pickup_html = (string) ob_get_clean();
wdc_manual_assert( str_contains( $cold_pickup_html, 'data-wdc-pickup-checkout' ) && str_contains( $cold_pickup_html, 'data-wdc-pickup-open' ) && str_contains( $cold_pickup_html, 'Выбрать пункт выдачи' ), 'Cold first checkout request must render the manual pickup selector before any reload.' );
$cold_rest = new PickupPointsRestController(
	new RussianPostPickupPointRepository( $GLOBALS['wpdb'] ),
	null,
	null,
	null,
	null,
	null,
	null,
	null,
	$pickup_provider_registry,
	new CheckoutPickupPointProviderQueryResolver( $cold_checkout_session ),
	null,
	new WooCommerceSessionBootstrapper()
);
$cold_rest_points = $cold_rest->points( array( 'carrier' => 'manual', 'shipping_method_id' => 'manual:manual_pickup_b', 'pickup_family' => 'manual:manual_pickup_b:pickup', 'limit' => 50 ) );
wdc_manual_assert( is_array( $cold_rest_points ) && 1 === count( $cold_rest_points ) && 'manual-b-1' === (string) ( $cold_rest_points[0]['point_code'] ?? '' ), 'Manual pickup provider context must be available to REST immediately after the first checkout calculation.' );

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
$manual_pickup_source = (string) file_get_contents( $root . '/src/Carriers/Manual/ManualPickupPointRepository.php' ) . (string) file_get_contents( $root . '/src/Carriers/Manual/ManualPickupPointProvider.php' );
$pickup_query_source = (string) file_get_contents( $root . '/src/Pickup/Providers/CarrierPickupPointQuery.php' ) . (string) file_get_contents( $root . '/src/Pickup/Providers/CheckoutPickupPointProviderQueryResolver.php' );
$pickup_rest_source = (string) file_get_contents( $root . '/src/Pickup/Rest/CheckoutPickupPointRestController.php' );
$migration_0061_source = (string) file_get_contents( $root . '/database/migrations/0061_make_manual_delivery_geography_country_aware.php' );
$migration_0062_source = (string) file_get_contents( $root . '/database/migrations/0062_create_manual_delivery_weight_ranges.php' );
$migration_0063_source = (string) file_get_contents( $root . '/database/migrations/0063_create_manual_delivery_pickup_points.php' );
wdc_manual_assert( ! str_contains( $shipment_creation, 'ManualDelivery' ) && ! str_contains( $shipment_creation, "carrier_key' => 'manual" ), 'Manual delivery foundation must not add a ShipmentCreationService branch.' );
wdc_manual_assert( ! str_contains( $shipments_metabox, 'ManualDelivery' ) && ! str_contains( $shipments_metabox, "carrier_key' => 'manual" ), 'Manual delivery foundation must not add an OrderShipmentsMetabox branch.' );
wdc_manual_assert( ! str_contains( $shipment_js, 'ManualDelivery' ) && ! str_contains( $shipment_js, "manual-delivery" ), 'Manual delivery foundation must not add generic shipment JS logic.' );
wdc_manual_assert( ! str_contains( $plugin, 'ManualDeliveryShipmentDocumentProvider' ) && ! str_contains( $plugin, 'ManualDeliveryShipmentModalExtension' ) && ! str_contains( $plugin, 'ManualDeliveryShipmentPersistenceMapper' ), 'Manual delivery foundation must not register shipment document/modal/persistence extensions.' );
wdc_manual_assert( str_contains( $plugin, 'ManualDeliveryCarrier::class' ) && str_contains( $plugin, '$registry->register( $this->container->get( ManualDeliveryCarrier::class ) );' ), 'Manual runtime carrier must be registered only through CarrierRegistry.' );
wdc_manual_assert( str_contains( $plugin, 'ManualPickupPointProvider::class' ) && str_contains( $plugin, '$this->container->get( ManualPickupPointProvider::class )' ) && ! str_contains( $plugin, 'ManualDeliveryPickupRestController' ), 'Manual pickup must register one generic carrier provider and must not add a manual REST endpoint.' );
wdc_manual_assert( ! str_contains( $checkout_orchestrator_source, 'ManualDeliveryGeography' ) && ! str_contains( $checkout_orchestrator_source, 'ManualDeliveryPricing' ) && ! str_contains( $checkout_orchestrator_source, 'wdc_manual_delivery_' ), 'CheckoutOrchestrator must not contain manual geography/pricing SQL or carrier-specific branches.' );
wdc_manual_assert( ! str_contains( $manual_geo_source, 'location_id' ) && ! str_contains( $manual_geo_source, 'wp_wdc_locations.id' ), 'Manual geography must not depend on permanent location IDs.' );
wdc_manual_assert( str_contains( $manual_pricing_source, 'billing_weight_g' ) && str_contains( $manual_pricing_source, 'price_per_kg_kopecks * $billing_weight_g' ) && ! str_contains( $manual_pricing_source, 'zone_id' ) && ! str_contains( $manual_pricing_source, 'dbDelta' ), 'Manual pricing must be carrier-owned, integer based, zone-free, and must not create runtime schema.' );
wdc_manual_assert( str_contains( $manual_pickup_source, 'wdc_manual_delivery_pickup_points' ) && str_contains( $manual_pickup_source, 'service_key' ) && ! str_contains( $manual_pickup_source, 'manual_service_key' ) && ! str_contains( $manual_pickup_source, 'dbDelta' ), 'Manual pickup storage/provider must be manual-owned, service-key aware, and must not create runtime schema.' );
wdc_manual_assert( str_contains( $pickup_query_source, 'service_key' ) && str_contains( $pickup_query_source, 'normalized_service_key' ) && ! str_contains( $pickup_query_source, 'manual_service_key' ), 'Pickup provider query must expose generic service_key context, not a manual-specific browser authority.' );
wdc_manual_assert( str_contains( $pickup_rest_source, "\$this->param( \$request, 'pickup_family' )" ) && str_contains( $pickup_rest_source, '$this->provider_query_resolver->resolve( $method_id, $carrier, $family )' ), 'Checkout pickup selection save must use the trusted rate pickup family context for service-specific provider queries.' );
wdc_manual_assert( str_contains( $admin_source, "wp_ajax_wdc_manual_delivery_region_search" ) && str_contains( $admin_source, "wp_ajax_wdc_manual_delivery_location_search" ) && str_contains( $admin_source, "current_user_can( AdminMenu::CAPABILITY )" ) && str_contains( $admin_source, "check_ajax_referer( 'wdc_manual_delivery_geography', 'nonce', false )" ), 'Manual geography admin search must use capability and nonce protected AJAX.' );
wdc_manual_assert( str_contains( $admin_source, 'manual_pricing_mode_options' ) && str_contains( $admin_source, 'manual_weight_ranges_from_post' ) && str_contains( $admin_source, 'manual_delivery_weight_ranges->validate_ranges' ) && str_contains( $admin_source, 'wdc_manual_pricing_notice' ), 'Manual pricing admin UI/save must expose typed modes and validate ranges before replacing stored rows.' );
wdc_manual_assert( str_contains( $admin_source, 'resolve_active_by_place_and_region' ) && str_contains( $admin_source, "'country_code' => strtoupper( trim( \$canonical->country_code ) )" ) && str_contains( $admin_source, "'location_name' => \$canonical->resolved_place_name()" ) && str_contains( $admin_source, "'region_name' => \$canonical->region_name" ) && str_contains( $admin_source, 'name="manual_locations[]"' ) && ! str_contains( $admin_source, 'name="manual_location_ids[]"' ), 'Manual geography admin save must canonicalize locations server-side as country_code plus location_name plus region_name, not location ID.' );
wdc_manual_assert( str_contains( $admin_source, 'save_manual_delivery_geography' ) && substr_count( $admin_source, 'clear_delivery_quote_cache();' ) >= 4, 'Manual geography saves must invalidate the shared delivery quote cache.' );
wdc_manual_assert( str_contains( $order_mapper_source, 'canonical_location' ) && str_contains( $order_mapper_source, 'resolved_place_name()' ) && str_contains( $order_mapper_source, "'place_name'" ) && str_contains( $order_mapper_source, '$address->settlement ?: $address->city' ), 'Order-admin quote mapping must build trusted region_name and place_name from canonical Location when a location id is selected.' );
wdc_manual_assert( str_contains( $migration_0061_source, 'country_code' ) && str_contains( $migration_0061_source, "country_code = 'RU'" ) && str_contains( $migration_0061_source, 'ux_manual_region_country' ) && str_contains( $migration_0061_source, 'ux_manual_location_country' ), 'Migration 0061 must add/backfill country-aware manual geography identity and unique indexes.' );
wdc_manual_assert( str_contains( $migration_0062_source, 'wdc_manual_delivery_weight_ranges' ) && str_contains( $migration_0062_source, 'ux_manual_weight_range' ) && str_contains( $migration_0062_source, 'from_weight_g' ) && str_contains( $migration_0062_source, 'price_kopecks' ), 'Migration 0062 must create manual delivery weight ranges with grams, kopecks, and unique range identity.' );
wdc_manual_assert( str_contains( $migration_0063_source, 'wdc_manual_delivery_pickup_points' ) && str_contains( $migration_0063_source, 'ux_manual_pickup_service_code' ) && str_contains( $migration_0063_source, 'country_code' ) && str_contains( $migration_0063_source, 'location_name' ) && str_contains( $migration_0063_source, 'region_name' ), 'Migration 0063 must create manual pickup points with stable per-service code and textual locality identity.' );

echo "Manual delivery foundation smoke test passed.\n";
