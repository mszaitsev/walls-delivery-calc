<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {}
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Runtime\SelfPickupCarrier;
use WallsShop\WDC\Carriers\SelfPickup\SelfPickupDiscountPolicy;
use WallsShop\WDC\Carriers\SelfPickup\SelfPickupDiscountService;
use WallsShop\WDC\Carriers\SelfPickup\SelfPickupSettings;
use WallsShop\WDC\Checkout\Comments\DeliveryCustomerCommentNormalizer;
use WallsShop\WDC\Checkout\Comments\DeliveryCustomerCommentRenderer;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutRateRenderer;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMapper;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceManager;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Rules\Storage\RuleRepository;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\ShipmentMetaboxButtonPolicy;
use WallsShop\WDC\Shipments\Application\ShipmentServiceSettings;

function self_pickup_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function self_pickup_smoke_rate( string $rate_id, string $title, int $kopecks, int $days = 1, array $meta = array() ): DeliveryRate {
	return new DeliveryRate(
		$rate_id,
		(string) ( $meta['carrier_key'] ?? 'smoke_carrier' ),
		(string) ( $meta['carrier_title'] ?? 'Smoke' ),
		(string) ( $meta['service_key'] ?? $rate_id ),
		$title,
		$rate_id,
		$title,
		DeliveryType::COURIER,
		$title,
		Money::from_kopecks( $kopecks ),
		null,
		null,
		DateRange::range( $days, $days ),
		'',
		'',
		array(),
		false,
		'',
		false,
		false,
		$meta,
		Money::from_kopecks( $kopecks ),
		DateRange::range( $days, $days )
	);
}

function current_time( string $type ): string { return '2026-09-06 12:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_textarea_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function sanitize_key( mixed $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\\-]/i', '', (string) $value ) ?? '' ); }
function __( string $text, string $domain = '' ): string { return $text; }
function esc_html__( string $text, string $domain = '' ): string { return esc_html( $text ); }
function esc_attr__( string $text, string $domain = '' ): string { return esc_attr( $text ); }
function esc_html( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' ); }
function esc_url( mixed $value ): string { return str_starts_with( (string) $value, 'javascript:' ) ? '' : (string) $value; }
function disabled( bool $disabled, bool $current = true, bool $display = true ): string { return $disabled === $current ? ' disabled="disabled"' : ''; }
function WC(): object { return $GLOBALS['wdc_wc']; }

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

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sdf]/', $replacement, $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function insert( string $table, array $data, array $format = array() ): bool {
			$data['id'] = ++$this->insert_id;
			$this->rows( $table )[] = $data;
			return true;
		}

		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			$rows =& $this->rows( $table );
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
			$rows =& $this->rows( $table );
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
				if ( preg_match( '/service_id = ([0-9]+)/', $query, $match ) ) {
					$service_id = (int) $match[1];
					$this->countries = array_values( array_filter( $this->countries, static fn ( array $row ): bool => (int) $row['service_id'] !== $service_id ) );
				}
				return true;
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
				$rows = array_values( array_filter( $this->services, static fn ( array $row ): bool => (string) $row['service_key'] === $matches[1] ) );
				usort( $rows, static fn ( array $left, array $right ): int => (int) ( $left['deleted'] ?? 0 ) <=> (int) ( $right['deleted'] ?? 0 ) ?: (int) $left['id'] <=> (int) $right['id'] );
				foreach ( $rows as $row ) {
					if ( str_contains( $query, 'deleted = 0' ) && ! empty( $row['deleted'] ) ) {
						continue;
					}
					return $row;
				}
			}
			if ( str_contains( $query, 'wdc_delivery_service_settings' ) && preg_match( "/service_id = ([0-9]+).*setting_key = '([^']+)'/", $query, $matches ) ) {
				foreach ( $this->settings as $row ) {
					if ( (int) $row['service_id'] === (int) $matches[1] && (string) $row['setting_key'] === $matches[2] ) {
						return $row;
					}
				}
			}
			return null;
		}

		public function get_results( string $query, mixed $output = null ): array {
			if ( str_contains( $query, 'wdc_delivery_services' ) && preg_match( "/service_key = '([^']+)'/", $query, $matches ) ) {
				return array_values( array_filter( $this->services, static fn ( array $row ): bool => (string) $row['service_key'] === $matches[1] && empty( $row['deleted'] ) ) );
			}
			return array();
		}

		public function get_var( string $query ): mixed {
			$row = $this->get_row( $query, ARRAY_A );
			return is_array( $row ) ? ( $row['id'] ?? null ) : null;
		}

		public function get_col( string $query ): array {
			if ( str_contains( $query, 'wdc_delivery_service_countries' ) && preg_match( '/service_id = ([0-9]+)/', $query, $matches ) ) {
				$service_id = (int) $matches[1];
				$countries = array();
				foreach ( $this->countries as $row ) {
					if ( (int) $row['service_id'] === $service_id ) {
						$countries[] = (string) $row['country_code'];
					}
				}
				sort( $countries );
				return $countries;
			}
			return array();
		}

		private function &rows( string $table ): array {
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

final class SelfPickupSmokeSession {
	/** @var array<string,mixed> */
	public array $data = array();
	public function get( string $key, mixed $default = null ): mixed { return $this->data[ $key ] ?? $default; }
	public function set( string $key, mixed $value ): void { $this->data[ $key ] = $value; }
}

final class SelfPickupSmokeCart {
	/** @var array<int,array{label:string,amount:float,taxable:bool}> */
	public array $fees = array();
	public function __construct( private float $contents_total, private bool $needs_shipping = true ) {}
	public function get_cart_contents_total(): float { return $this->contents_total; }
	public function needs_shipping(): bool { return $this->needs_shipping; }
	public function add_fee( string $label, float $amount, bool $taxable = false ): void { $this->fees[] = array( 'label' => $label, 'amount' => $amount, 'taxable' => $taxable ); }
}

final class SelfPickupSmokeOrder {
	/** @param array<string,mixed> $meta */
	public function __construct( public array $meta ) {}
	public function get_id(): int { return 123; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
}

$wpdb = new wpdb();
$GLOBALS['wpdb'] = $wpdb;
$GLOBALS['wdc_wc'] = (object) array( 'session' => new SelfPickupSmokeSession(), 'cart' => null );

$services = new DeliveryServiceRepository( $wpdb );
$countries = new DeliveryServiceCountryRepository( $wpdb );
$settings_repo = new DeliveryServiceSettingsRepository( $wpdb );
$settings = new SelfPickupSettings( $settings_repo );
$service = $services->ensure_self_pickup_service();

self_pickup_assert( $service instanceof DeliveryService && null !== $service->id, 'Self-pickup builtin service must be created.' );
self_pickup_assert( SelfPickupSettings::SERVICE_KEY === $service->service_key && SelfPickupSettings::CARRIER_KEY === $service->carrier_key, 'Self-pickup must use stable carrier/service identity.' );
self_pickup_assert( SelfPickupSettings::TITLE === $service->title && DeliveryService::TYPE_FIXED === $service->service_type, 'Self-pickup must be a fixed builtin service with the default title.' );
self_pickup_assert( DeliveryService::AVAILABILITY_ALL_COUNTRIES === $service->availability_mode, 'Self-pickup default availability must be all countries.' );

$manager = new DeliveryServiceManager(
	$services,
	$countries,
	new RuleRepository( $wpdb ),
	( new ReflectionClass( \WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory::class ) )->newInstanceWithoutConstructor(),
	$settings_repo
);
self_pickup_assert( $manager->service_available_for_country( $service, 'RU' ) && $manager->service_available_for_country( $service, 'KZ' ), 'All-countries self-pickup must be available for RU and foreign countries through generic country machinery.' );
$selected = DeliveryService::from_array( array_merge( $service->to_array(), array( 'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES ) ) );
$wpdb->countries[] = array( 'service_id' => $service->id, 'country_code' => 'RU' );
self_pickup_assert( $manager->service_available_for_country( $selected, 'RU' ) && ! $manager->service_available_for_country( $selected, 'KZ' ), 'Selected-country mode must keep working through generic country machinery.' );
$excluded = DeliveryService::from_array( array_merge( $service->to_array(), array( 'availability_mode' => DeliveryService::AVAILABILITY_ALL_EXCEPT_SELECTED ) ) );
self_pickup_assert( ! $manager->service_available_for_country( $excluded, 'RU' ) && $manager->service_available_for_country( $excluded, 'KZ' ), 'All-except-selected mode must keep working through generic country machinery.' );

$root = dirname( __DIR__, 2 );
$delivery_services_admin_source = file_get_contents( $root . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' ) ?: '';
self_pickup_assert( str_contains( $delivery_services_admin_source, '$tabs[\'rules\'] = \'Правила\'' ) && str_contains( $delivery_services_admin_source, 'if ( ! $this->is_self_pickup_service( $service ) )' ), 'Delivery Services admin must hide the Rules tab for self-pickup while keeping the standard Rules tab for other services.' );
self_pickup_assert( str_contains( $delivery_services_admin_source, 'SelfPickupSettings::CARD_TITLE_KEY' ) && str_contains( $delivery_services_admin_source, 'Название в карточке' ), 'Self-pickup admin UI must expose the card title setting.' );

$sorter = new RateSorter();
$sorted = $sorter->sort_methods(
	array(
		self_pickup_smoke_rate( 'zero_b', 'Beta Free', 0, 1 ),
		self_pickup_smoke_rate( 'paid_380', 'Paid 380', 38000, 5 ),
		self_pickup_smoke_rate( 'zero_a', 'Alpha Free', 0, 1 ),
		self_pickup_smoke_rate( 'paid_250', 'Paid 250', 25000, 9 ),
	),
	RateSorter::CHEAPEST
);
self_pickup_assert( array( 'paid_250', 'paid_380', 'zero_a', 'zero_b' ) === array_map( static fn ( DeliveryRate $rate ): string => $rate->rate_id, $sorted ), 'CHEAPEST sorting must put positive rates first by price, then zero-price rates alphabetically.' );
$rate_sorter_source = file_get_contents( $root . '/src/Checkout/Sorting/RateSorter.php' ) ?: '';
self_pickup_assert( ! str_contains( $rate_sorter_source, 'self_pickup' ) && str_contains( $rate_sorter_source, 'compare_cheapest_rates' ), 'RateSorter zero-price ordering must stay generic, without self-pickup-specific conditions.' );

$carrier = new SelfPickupCarrier( $settings );
$request = new QuoteRequest(
	'RU',
	new Address( country_code: 'RU', city: 'Новосибирск', raw_address: 'Новосибирск' ),
	new Package( array(), Money::from_kopecks( 0 ), Money::from_kopecks( 0 ) ),
	'',
	Money::from_kopecks( 0 ),
	'2026-09-06',
	array( 'service_id' => $service->id, 'service_title' => $service->title )
);
$quote = $carrier->quote( $request );
$rate = $quote->rates[0] ?? null;
self_pickup_assert( null !== $rate, 'Self-pickup quote must expose one checkout rate.' );
self_pickup_assert( SelfPickupSettings::CARRIER_KEY === $rate->carrier_key && SelfPickupSettings::SERVICE_KEY === $rate->service_key && SelfPickupSettings::SERVICE_KEY === $rate->rate_id, 'Self-pickup rate identity must be canonical and stable.' );
self_pickup_assert( 0 === $rate->price->get_kopecks() && DeliveryType::PICKUP === $rate->delivery_type && false === $rate->requires_pickup_point, 'Self-pickup rate must be zero-price pickup without selectable pickup requirement.' );
self_pickup_assert( ! empty( $rate->meta['fixed_pickup_point_snapshot']['fixed_fulfillment_location'] ) && empty( $rate->meta['fixed_pickup_point_snapshot']['selectable'] ), 'Self-pickup must expose a server-owned fixed fulfillment snapshot, not a selectable point.' );
self_pickup_assert( SelfPickupSettings::DEFAULT_CARD_TITLE === ( $rate->meta['fixed_pickup_point_snapshot']['card_title'] ?? '' ), 'Self-pickup fixed card title must come from the card title setting default, separately from method title.' );
self_pickup_assert( array( array( 'type' => 'text', 'text' => SelfPickupSettings::DEFAULT_CUSTOMER_COMMENT ) ) === ( $rate->meta['customer_comments'] ?? null ), 'Default self-pickup buyer comment must use the canonical structured comment payload.' );

$mapped = ( new WooCommerceRateMapper() )->map( $rate );
self_pickup_assert( ( $mapped['meta_data']['fixed_pickup_point_snapshot']['point_address'] ?? '' ) === SelfPickupSettings::DEFAULT_ADDRESS, 'Woo mapper must preserve fixed pickup snapshot metadata.' );
self_pickup_assert( ( $mapped['meta_data']['non_shipment_state']['message'] ?? '' ) === 'Самовывоз покупателем', 'Woo mapper must preserve generic non-shipment state metadata.' );

$GLOBALS['wdc_wc']->session->set( 'chosen_shipping_methods', array( 'wdc_platform:other_rate' ) );
ob_start();
( new CheckoutRateRenderer() )->render( array( 'id' => 'wdc_platform:self_pickup', 'meta_data' => $mapped['meta_data'] ) );
$inactive_checkout_html = (string) ob_get_clean();
self_pickup_assert( ! str_contains( $inactive_checkout_html, 'data-wdc-fixed-pickup-card' ), 'Checkout must hide fixed fulfillment cards for inactive shipping rates.' );

$GLOBALS['wdc_wc']->session->set( 'chosen_shipping_methods', array( 'wdc_platform:self_pickup' ) );
ob_start();
( new CheckoutRateRenderer() )->render( array( 'id' => 'wdc_platform:self_pickup', 'meta_data' => $mapped['meta_data'] ) );
$checkout_html = (string) ob_get_clean();
self_pickup_assert( str_contains( $checkout_html, 'data-wdc-fixed-pickup-card' ) && str_contains( $checkout_html, SelfPickupSettings::DEFAULT_ADDRESS ) && str_contains( $checkout_html, SelfPickupSettings::DEFAULT_WORKING_HOURS ), 'Checkout must render the fixed self-pickup card with address and working hours.' );
self_pickup_assert( ! str_contains( $checkout_html, 'Выбрать пункт выдачи' ) && ! str_contains( $checkout_html, 'Изменить пункт выдачи' ), 'Fixed self-pickup card must not render select/change pickup buttons.' );

$policy = new SelfPickupDiscountPolicy();
$discount_settings = array( 'enabled' => true, 'percent' => 10.0, 'minimum_kopecks' => 350000, 'fee_label' => 'Скидка' );
self_pickup_assert( 35000 === $policy->calculate( true, 350000, $discount_settings )->amount_kopecks, 'Discount math must produce 350.00 from 3500.00 at 10%.' );
self_pickup_assert( ! $policy->calculate( true, 349999, $discount_settings )->available, 'Discount threshold must not round 3499.99 up to promotion eligibility.' );
self_pickup_assert( 40000 === $policy->calculate( true, 400000, $discount_settings )->amount_kopecks, 'Discount math must produce 400.00 from 4000.00 at 10%.' );
self_pickup_assert( $policy->calculate( false, 450000, $discount_settings )->available && ! $policy->calculate( false, 450000, $discount_settings )->applied, 'Discount policy must separate promotion availability from selected-method application.' );
self_pickup_assert( ! $policy->calculate( true, 450000, array_merge( $discount_settings, array( 'enabled' => false ) ) )->available, 'Discount policy must respect disabled setting.' );

$GLOBALS['wdc_wc']->session->set( 'chosen_shipping_methods', array( 'wdc_platform:self_pickup' ) );
$session_manager = new CheckoutSessionManager();
$session_manager->save_rates( array( 'self_pickup' => array( 'carrier_key' => SelfPickupSettings::CARRIER_KEY, 'service_key' => SelfPickupSettings::SERVICE_KEY, 'label' => 'Любой настраиваемый title' ) ) );
$discount_service = new SelfPickupDiscountService( $services, $settings, $policy, $session_manager );
$GLOBALS['wdc_wc']->cart = new SelfPickupSmokeCart( 4000.0, true );
$carrier_with_discounts = new SelfPickupCarrier( $settings, $discount_service );
$eligible_quote = $carrier_with_discounts->quote( $request );
self_pickup_assert( in_array( 'Скидка 10% по акции', $eligible_quote->rates[0]->comments, true ), 'Self-pickup promo comment must be visible when promotion is available for the cart.' );
$GLOBALS['wdc_wc']->session->set( 'chosen_shipping_methods', array( 'wdc_platform:other_rate' ) );
self_pickup_assert( $discount_service->current_promotion_result()->available && ! $discount_service->current_discount_result()->applied, 'Promotion must be available before self-pickup is selected, without applying the fee.' );
$other_selected_cart = new SelfPickupSmokeCart( 4000.0, true );
$discount_service->apply( $other_selected_cart );
self_pickup_assert( array() === $other_selected_cart->fees, 'Available promotion must not add a fee until self-pickup is selected.' );
$GLOBALS['wdc_wc']->session->set( 'chosen_shipping_methods', array( 'wdc_platform:self_pickup' ) );
$cart = new SelfPickupSmokeCart( 4500.0, true );
$discount_service->apply( $cart );
self_pickup_assert( 1 === count( $cart->fees ) && -450.0 === $cart->fees[0]['amount'] && false === $cart->fees[0]['taxable'], 'Discount service must add one standard negative non-taxable Woo fee from post-coupon item total.' );
self_pickup_assert( 'Скидка 10% за самовывоз. Подробнее в разделе "Акции"' === $cart->fees[0]['label'], 'Discount fee must use the configured financial label as plain fee name.' );
$below_threshold_cart = new SelfPickupSmokeCart( 3499.99, true );
$discount_service->apply( $below_threshold_cart );
self_pickup_assert( array() === $below_threshold_cart->fees, 'Discount service must disappear below threshold.' );
$virtual_cart = new SelfPickupSmokeCart( 4500.0, false );
$discount_service->apply( $virtual_cart );
self_pickup_assert( array() === $virtual_cart->fees, 'Only-virtual carts that do not need shipping must not receive self-pickup discount.' );
$GLOBALS['wdc_wc']->session->set( 'chosen_shipping_methods', array( 'wdc_platform:other_rate' ) );
$other_cart = new SelfPickupSmokeCart( 4500.0, true );
$discount_service->apply( $other_cart );
self_pickup_assert( array() === $other_cart->fees, 'Switching to another stable shipping identity must remove the pickup discount on the next totals calculation.' );
$GLOBALS['wdc_wc']->session->set( 'chosen_shipping_methods', array( 'wdc_platform:self_pickup' ) );
$GLOBALS['wdc_wc']->cart = new SelfPickupSmokeCart( 3499.99, true );
$ineligible_quote = $carrier_with_discounts->quote( $request );
self_pickup_assert( ! in_array( 'Скидка 10% по акции', $ineligible_quote->rates[0]->comments, true ), 'Promo comment must disappear below the configured threshold.' );
$settings->save_discount_from_admin(
	(int) $service->id,
	array(
		SelfPickupSettings::DISCOUNT_ENABLED_KEY => '1',
		SelfPickupSettings::DISCOUNT_PERCENT_KEY => '7.5',
		SelfPickupSettings::DISCOUNT_MINIMUM_KOPECKS_KEY => '3500',
		SelfPickupSettings::DISCOUNT_FEE_LABEL_KEY => 'Скидка {s}% custom',
		SelfPickupSettings::DISCOUNT_COMMENT_ENABLED_KEY => '1',
		SelfPickupSettings::DISCOUNT_COMMENT_KEY => array( 'text' => SelfPickupSettings::DEFAULT_DISCOUNT_COMMENT ),
	)
);
$custom_label_cart = new SelfPickupSmokeCart( 4000.0, true );
$discount_service->apply( $custom_label_cart );
self_pickup_assert( 'Скидка 7.5% custom' === ( $custom_label_cart->fees[0]['label'] ?? '' ) && -300.0 === ( $custom_label_cart->fees[0]['amount'] ?? 0 ), 'Discount fee label must resolve {s} using the configured percent and remain plain text.' );
$settings->save_discount_from_admin(
	(int) $service->id,
	array(
		SelfPickupSettings::DISCOUNT_ENABLED_KEY => '1',
		SelfPickupSettings::DISCOUNT_PERCENT_KEY => '10',
		SelfPickupSettings::DISCOUNT_MINIMUM_KOPECKS_KEY => '3500',
		SelfPickupSettings::DISCOUNT_FEE_LABEL_KEY => SelfPickupSettings::DEFAULT_DISCOUNT_FEE_LABEL,
		SelfPickupSettings::DISCOUNT_COMMENT_ENABLED_KEY => '1',
		SelfPickupSettings::DISCOUNT_COMMENT_KEY => array( 'text' => SelfPickupSettings::DEFAULT_DISCOUNT_COMMENT ),
	)
);

$settings->save_main_from_admin(
	(int) $service->id,
	array(
		SelfPickupSettings::CARD_TITLE_KEY => '<b>Магазин WALLS</b>',
		SelfPickupSettings::ADDRESS_KEY => '<b>Склад</b>',
		SelfPickupSettings::WORKING_HOURS_KEY => "Пн-Пт\n10:00-19:00",
		SelfPickupSettings::CUSTOMER_COMMENT_ENABLED_KEY => '1',
		SelfPickupSettings::CUSTOMER_COMMENT_KEY => array(
			'text' => "Строка 1\nСтрока 2",
			'text_before' => 'Подробнее в разделе ',
			'label' => 'Акции',
			'url' => 'javascript:alert(1)',
			'text_after' => '.',
		),
	)
);
self_pickup_assert( 'Магазин WALLS' === $settings->card_title( (int) $service->id ) && 'Склад' === $settings->address( (int) $service->id ) && "Пн-Пт\n10:00-19:00" === $settings->working_hours( (int) $service->id ), 'Self-pickup settings must sanitize card title/address and preserve multiline working hours.' );
self_pickup_assert( '' === $settings->customer_comment( (int) $service->id )['url'], 'Structured self-pickup comment must reject unsafe URL schemes.' );
$normalized = ( new DeliveryCustomerCommentNormalizer() )->normalize( array( array( 'type' => 'link', 'text_before' => 'Подробнее в разделе ', 'label' => 'Акции', 'url' => 'https://example.com/actions', 'text_after' => '.' ) ) );
self_pickup_assert( 'https://example.com/actions' === ( $normalized[0]['url'] ?? '' ), 'Structured comment contract must preserve safe http/https links.' );
$custom_card_quote = $carrier->quote( $request );
self_pickup_assert( 'Магазин WALLS' === ( $custom_card_quote->rates[0]->meta['fixed_pickup_point_snapshot']['card_title'] ?? '' ), 'Fixed pickup snapshot must preserve the card title configured at quote/order time.' );

$checkout_link_renderer = new CheckoutRateRenderer();
ob_start();
$checkout_link_renderer->render(
	array(
		'id' => 'wdc_platform:link_test',
		'meta_data' => array(
			'carrier_key' => 'smoke',
			'delivery_type' => DeliveryType::COURIER,
			'customer_link_comments' => array(
				array( 'text_before' => 'Скидка 10% по', 'label' => 'акции', 'url' => 'https://example.com/actions', 'text_after' => '.' ),
				array( 'text_before' => 'Подробнее в разделе ', 'label' => 'Акции', 'url' => 'https://example.com/actions', 'text_after' => '' ),
				array( 'text_before' => '', 'label' => 'Акции', 'url' => 'https://example.com/actions', 'text_after' => '' ),
			),
		),
	)
);
$checkout_link_html = (string) ob_get_clean();
self_pickup_assert( str_contains( $checkout_link_html, 'Скидка 10% по <a' ) && ! str_contains( $checkout_link_html, 'поакции' ) && str_contains( $checkout_link_html, 'Подробнее в разделе <a' ) && str_contains( $checkout_link_html, '>Акции</a>' ), 'Checkout structured link renderer must add one missing whitespace before inline links without doubling existing whitespace.' );
$order_link_html = ( new DeliveryCustomerCommentRenderer() )->render_items(
	array(
		array( 'type' => 'link', 'text_before' => 'Скидка 10% по', 'label' => 'акции', 'url' => 'https://example.com/actions', 'text_after' => '.' ),
		array( 'type' => 'link', 'text_before' => 'Подробнее в разделе ', 'label' => 'Акции', 'url' => 'https://example.com/actions', 'text_after' => '' ),
		array( 'type' => 'link', 'text_before' => '', 'label' => 'Акции', 'url' => 'https://example.com/actions', 'text_after' => '' ),
	)
);
self_pickup_assert( str_contains( $order_link_html, 'Скидка 10% по <a' ) && ! str_contains( $order_link_html, 'поакции' ) && str_contains( $order_link_html, 'Подробнее в разделе <a' ) && str_contains( $order_link_html, '>Акции</a>' ), 'Order/email/account structured link renderer must match checkout whitespace normalization.' );

$order = new SelfPickupSmokeOrder(
	array(
		'_wdc_platform_carrier_key' => SelfPickupSettings::CARRIER_KEY,
		'_wdc_platform_service_key' => SelfPickupSettings::SERVICE_KEY,
		'_wdc_platform_delivery_type' => DeliveryType::PICKUP,
		'_wdc_platform_rate_id' => SelfPickupSettings::SERVICE_KEY,
		'_wdc_platform_service_title' => SelfPickupSettings::TITLE,
		'_wdc_platform_rate_meta' => array( 'non_shipment_state' => array( 'message' => 'Самовывоз покупателем' ) ),
	)
);
$draft_factory = new OrderShipmentDraftFactory( $services, new ShipmentServiceSettings( $settings_repo ) );
self_pickup_assert( $draft_factory->supports_order( $order ), 'OrderShipmentDraftFactory must support generic non-shipment delivery state without a shipment adapter.' );
$draft = $draft_factory->draft_array( $order );
self_pickup_assert( true === ( $draft['modal_capabilities']['non_shipment'] ?? false ) && true === ( $draft['modal_capabilities']['suppress_status_block'] ?? false ) && true === ( $draft['modal_capabilities']['suppress_actions'] ?? false ) && 'Самовывоз покупателем' === ( $draft['request']['meta']['non_shipment_state']['message'] ?? '' ), 'Non-shipment draft payload must carry static informational state and suppress shipment UI requirements.' );
$buttons = ( new ShipmentMetaboxButtonPolicy() )->resolve( SelfPickupSettings::CARRIER_KEY, array(), array( 'has_shipment' => false, 'can_create' => false, 'can_attach_manual' => false, 'can_update_status' => false, 'can_cancel' => false, 'can_remove_from_order' => false ) );
self_pickup_assert( ! $buttons['show_create'] && ! $buttons['show_manual_attach'] && ! $buttons['show_update'] && ! $buttons['show_cancel'] && ! $buttons['show_remove'], 'Self-pickup non-shipment state must expose no shipment actions.' );

$plugin_source = file_get_contents( $root . '/src/Core/Plugin.php' ) ?: '';
$metabox_source = file_get_contents( $root . '/src/Shipments/Admin/OrderShipmentsMetabox.php' ) ?: '';
$payload_builder_source = file_get_contents( $root . '/src/Shipments/Admin/Ajax/ShipmentAdminCarrierUiPayloadBuilder.php' ) ?: '';
$generic_js = implode( "\n", array_map( static fn ( string $path ): string => file_get_contents( $path ) ?: '', glob( $root . '/assets/js/*.js' ) ?: array() ) );
self_pickup_assert( str_contains( $plugin_source, 'SelfPickupCarrier::class' ) && str_contains( $plugin_source, 'SelfPickupDiscountService::class' ), 'Plugin DI must wire self-pickup runtime and discount service.' );
self_pickup_assert( ! str_contains( $plugin_source, 'SelfPickupShipmentAdapter' ) && ! str_contains( $plugin_source, 'SelfPickupShipmentPersistenceMapper' ) && ! str_contains( $plugin_source, 'SelfPickupShipmentDocumentProvider' ) && ! str_contains( $plugin_source, 'SelfPickupShipmentModalExtension' ), 'Self-pickup must not register shipment adapters, mappers, document providers, or modal extensions.' );
self_pickup_assert( ! str_contains( $metabox_source, "'self_pickup'" ) && ! str_contains( $generic_js, 'self_pickup' ), 'Generic shipment metabox and JS must not contain self-pickup-specific branches.' );
self_pickup_assert( str_contains( $metabox_source, 'if ( ! $non_shipment_active )' ) && str_contains( $metabox_source, "'non_shipment_state_active' => true" ) && str_contains( $payload_builder_source, '$non_shipment_active ? array() : $this->document_actions_for_carrier' ), 'Generic non-shipment metabox/AJAX payload must keep only service identity and suppress status blocks, actions, documents and actual-cost UI.' );

echo "Self-pickup smoke passed.\n";
