<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Carriers\RussianPost\RussianPostApiClient;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryMappingRepository;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryMappingService;
use WallsShop\WDC\Carriers\RussianPost\RussianPostSettings;
use WallsShop\WDC\Carriers\Runtime\RussianPostInternationalCarrier;
use WallsShop\WDC\Admin\SettingsAdminPage;
use WallsShop\WDC\Checkout\Runtime\CarrierExecutionGuard;
use WallsShop\WDC\Checkout\Runtime\CheckoutLogger;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Runtime\FallbackRateFactory;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMapper;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Packaging\PackagingWeightCalculator;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleCondition;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\ValueObjects\RuleActionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleConditionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationBases;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperators;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {}
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

$GLOBALS['wdc_rp_options'] = array();
$GLOBALS['wdc_rp_transients'] = array();
$GLOBALS['wdc_rp_remote_mode'] = 'success';
$GLOBALS['wdc_rp_tariff_requests'] = 0;
$GLOBALS['wdc_rp_session'] = new class {
	public array $data = array();
	public function get( string $key, mixed $default = null ): mixed { return $this->data[ $key ] ?? $default; }
	public function set( string $key, mixed $value ): void { $this->data[ $key ] = $value; }
};
$GLOBALS['wdc_rp_wc'] = new class {
	public mixed $session;
	public mixed $countries;
	public function __construct() {
		$this->session = $GLOBALS['wdc_rp_session'];
		$this->countries = new class {
			public function get_countries(): array { return array( 'US' => 'United States', 'RU' => 'Russia' ); }
		};
	}
};

function rp_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_rp_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, mixed $autoload = null ): bool { $GLOBALS['wdc_rp_options'][ $key ] = $value; return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_rp_transients'][ $key ]['value'] ?? false; }
function set_transient( string $key, mixed $value, int $ttl = 0 ): bool { $GLOBALS['wdc_rp_transients'][ $key ] = array( 'value' => $value, 'ttl' => $ttl ); return true; }
function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
function add_query_arg( array $params, string $url ): string { return $url . '?' . http_build_query( $params ); }
function wp_date( string $format ): string { return gmdate( $format, strtotime( '2026-05-25 10:00:00 UTC' ) ); }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'Asia/Novosibirsk' ); }
function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function is_wp_error( mixed $value ): bool { return $value instanceof WP_Error; }
function WC(): object { return $GLOBALS['wdc_rp_wc']; }
function wp_remote_get( string $url, array $args = array() ): mixed {
	if ( str_contains( $url, '/dictionary/country' ) ) {
		return array(
			'response' => array( 'code' => 200 ),
			'body' => json_encode(
				array(
					'country' => array(
						array( 'id' => 840, 'name' => 'СОЕДИНЕННЫЕ ШТАТЫ АМЕРИКИ', 'parcel' => array( 'block' => 0 ) ),
						array( 'id' => 643, 'name' => 'Россия', 'parcel' => array( 'block' => 0 ) ),
					),
				)
			),
		);
	}

	++$GLOBALS['wdc_rp_tariff_requests'];
	if ( 'fail' === $GLOBALS['wdc_rp_remote_mode'] ) {
		return array( 'response' => array( 'code' => 500 ), 'body' => json_encode( array( 'error' => 'down' ) ) );
	}

	if ( 'no_vat' === $GLOBALS['wdc_rp_remote_mode'] ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'paymoney' => 10000, 'transtype' => 2 ) ) );
	}
	if ( 'zero' === $GLOBALS['wdc_rp_remote_mode'] ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'paynds' => 0, 'transtype' => 2 ) ) );
	}
	if ( 'missing_price' === $GLOBALS['wdc_rp_remote_mode'] ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'transtype' => 2 ) ) );
	}

	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'paynds' => 10000, 'transtype' => 2 ) ) );
}
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( mixed $response ): string { return (string) ( $response['body'] ?? '' ); }
function current_user_can( string $capability ): bool { return true; }
function esc_html__( string $text, string $domain = '' ): string { return $text; }
function __( string $text, string $domain = '' ): string { return $text; }
function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function sanitize_key( mixed $key ): string { return strtolower( preg_replace( '/[^a-z0-9_\\-]/', '', (string) $key ) ?? '' ); }
function checked( mixed $checked, mixed $current = true, bool $display = true ): string { $result = $checked == $current ? 'checked="checked"' : ''; if ( $display ) { echo $result; } return $result; }
function selected( mixed $selected, mixed $current = true, bool $display = true ): string { $result = $selected == $current ? 'selected="selected"' : ''; if ( $display ) { echo $result; } return $result; }
function wp_nonce_field( string $action, string $name ): void { echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce">'; }
function submit_button( string $text ): void { echo '<button type="submit">' . esc_html( $text ) . '</button>'; }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public array $rp_rows = array();
		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$value = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sd]/', $value, $query, 1 ) ?? $query;
			}
			return $query;
		}
		public function insert( string $table, array $data, array $format = array() ): bool { $this->insert_id++; $data['id'] = $this->insert_id; $this->rp_rows[] = $data; return true; }
		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			foreach ( $this->rp_rows as $i => $row ) {
				if ( (string) ( $row['wc_country_code'] ?? '' ) === (string) ( $where['wc_country_code'] ?? '' ) ) {
					$this->rp_rows[ $i ] = array_merge( $row, $data );
				}
			}
			return true;
		}
		public function get_row( string $query, mixed $output = null ): ?array {
			if ( str_contains( $query, 'COUNT(*) AS total' ) ) {
				return array( 'total' => count( $this->rp_rows ), 'matched' => 0, 'api_available' => 0, 'enabled' => 0, 'skipped' => 0, 'manual_enabled' => 0, 'manual_disabled' => 0, 'last_checked_at' => '' );
			}
			if ( preg_match( "/wc_country_code = '([^']+)'/", $query, $m ) ) {
				foreach ( $this->rp_rows as $row ) {
					if ( $row['wc_country_code'] === $m[1] ) { return $row; }
				}
			}
			return null;
		}
		public function get_results( string $query, mixed $output = null ): array { return $this->rp_rows; }
		public function get_col( string $query ): array { return array_values( array_column( array_filter( $this->rp_rows, static fn( array $r ): bool => ! empty( $r['effective_enabled'] ) ), 'wc_country_code' ) ); }
		public function get_var( string $query ): mixed { return count( $this->rp_rows ); }
		public function query( string $query ): bool { if ( str_starts_with( $query, 'DELETE' ) ) { $this->rp_rows = array(); } return true; }
	}
}
$GLOBALS['wpdb'] = new wpdb();

final class WP_Error {
	public function __construct( private string $message ) {}
	public function get_error_message(): string { return $this->message; }
}

function rp_settings(): SettingsRepository {
	$settings = new SettingsRepository();
	$settings->replace(
		array_merge(
			$settings->defaults(),
			array(
				'russian_post_worldwide_parcel' => array_merge(
					$settings->defaults()['russian_post_worldwide_parcel'],
					array(
						'debug' => true,
						'fallback_enabled' => true,
						'fallback_text' => 'Стоимость доставки рассчитает менеджер',
						'max_package_weight_g' => 19990,
					)
				),
			)
		)
	);

	return $settings;
}

function rp_carrier( SettingsRepository $settings ): RussianPostInternationalCarrier {
	$logger = new Logger();
	$rp_settings = new RussianPostSettings( $settings );
	$client = new RussianPostApiClient( $rp_settings, $logger );
	$repo = new RussianPostCountryMappingRepository( $GLOBALS['wpdb'] );
	$service = new RussianPostCountryMappingService( $repo, $client, $logger );
	if ( 0 === $repo->count_all() ) {
		$service->refresh_from_api();
	}

	return new RussianPostInternationalCarrier( $rp_settings, $client, new RussianPostCountryDirectory( $client, $logger, $repo, $service, $rp_settings ), $logger );
}

function rp_request( int $item_weight = 1000, string $country = 'US' ): QuoteRequest {
	$item = new PackageItem( 'SKU', 'Item', 1, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), $item_weight );
	$package = Package::from_items( array( $item ), 0, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ) );

	return new QuoteRequest( $country, new Address( country_code: $country, city: 'New York', street: 'Broadway', house: '1', raw_address: 'Broadway 1' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-25' );
}

function rp_smoke_lead_time_normalizer( int $processing_days = 0 ): \WallsShop\WDC\Checkout\Runtime\DeliveryLeadTimeNormalizer {
	$settings = new SettingsRepository();
	$settings->set( SettingsRepository::SHOP_PROCESSING_WORKING_DAYS_KEY, $processing_days );
	$timezone = new \WallsShop\WDC\Calendar\Services\TimezoneService();
	$formatter = new \WallsShop\WDC\Calendar\Services\DeliveryDateFormatter();

	return new \WallsShop\WDC\Checkout\Runtime\DeliveryLeadTimeNormalizer(
		$settings,
		new \WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository(),
		new \WallsShop\WDC\Calendar\Services\DeliveryDateCalculator( new \WallsShop\WDC\Calendar\Services\CalendarService( new \WallsShop\WDC\Calendar\Storage\CalendarRepository(), new \WallsShop\WDC\Calendar\Services\YearGenerator(), $settings, $timezone ), $timezone, $formatter ),
		$formatter
	);
}

$settings = rp_settings();
$carrier = rp_carrier( $settings );
rp_smoke_assert( ! array_key_exists( 'packaging_tiers', ( new RussianPostSettings( $settings ) )->all() ), 'RussianPostSettings must not expose packaging_tiers as service-specific settings.' );

$GLOBALS['wdc_rp_remote_mode'] = 'no_vat';
$quote = $carrier->quote( rp_request() );
rp_smoke_assert( $quote->has_available_rates(), 'API success must return a rate.' );
rp_smoke_assert( 12000 === $quote->rates[0]->price->get_kopecks(), 'API price without VAT must apply VAT once and return base price without built-in formula.' );
rp_smoke_assert( false === $quote->rates[0]->meta['api_price_has_vat'], 'No-VAT source price must be marked as without VAT.' );
rp_smoke_assert( DeliveryType::PICKUP === $quote->rates[0]->delivery_type && ! $quote->rates[0]->requires_courier_address, 'Russian Post international rate must be treated as pickup without courier address notice.' );
$removed_pickup_mode_key = 'pickup_selection_' . 'mode';
rp_smoke_assert( ! $quote->rates[0]->requires_pickup_point && ! empty( $quote->rates[0]->meta['no_pickup_selection'] ) && ! array_key_exists( $removed_pickup_mode_key, $quote->rates[0]->meta ), 'Russian Post international rate must bypass explicit pickup point selection with no_pickup_selection only.' );
$orchestrator_reflection = new ReflectionClass( CheckoutOrchestrator::class );
$service_rate_method = $orchestrator_reflection->getMethod( 'rate_for_service' );
$service_rate_method->setAccessible( true );
$service_rate = $service_rate_method->invoke(
	$orchestrator_reflection->newInstanceWithoutConstructor(),
	$quote->rates[0],
	DeliveryService::from_array(
		array(
			'service_key' => RussianPostSettings::SERVICE_KEY,
			'carrier_key' => RussianPostSettings::CARRIER_KEY,
			'title' => RussianPostSettings::TITLE,
			'pickup_customer_comment' => 'Комментарий Почты России для ПВЗ',
			'courier_customer_comment' => 'Комментарий Почты России для курьера',
		)
	)
);
rp_smoke_assert( in_array( 'Комментарий Почты России для ПВЗ', $service_rate->comments, true ) && ! in_array( 'Комментарий Почты России для курьера', $service_rate->comments, true ), 'Russian Post international pickup rate must use pickup_customer_comment only.' );

$GLOBALS['wdc_rp_remote_mode'] = 'success';
$GLOBALS['wdc_rp_transients'] = array();
$quote = $carrier->quote( rp_request() );
rp_smoke_assert( 10000 === $quote->rates[0]->price->get_kopecks(), 'API price with VAT must not double VAT and must not add built-in markup.' );

$GLOBALS['wdc_rp_remote_mode'] = 'fail';
$GLOBALS['wdc_rp_transients'] = array();
$quote = $carrier->quote( rp_request() );
rp_smoke_assert( $quote->has_available_rates(), 'API fail must return fallback rate.' );
rp_smoke_assert( 0 === $quote->rates[0]->price->get_kopecks(), 'Fallback rate must be zero cost.' );
rp_smoke_assert( 'http_status_500' === $quote->rates[0]->meta['fallback_reason'], 'Fallback must keep reason in meta.' );
rp_smoke_assert( DeliveryType::PICKUP === $quote->rates[0]->delivery_type && 'Стоимость доставки рассчитает менеджер' === $quote->rates[0]->title && array() === $quote->rates[0]->comments && '' === $quote->rates[0]->planned_delivery_comment, 'Russian Post terminal fallback must expose fallback text as title only.' );
rp_smoke_assert( ! empty( $quote->rates[0]->meta['skip_rules'] ) && ! empty( $quote->rates[0]->meta['skip_service_post_processing'] ) && ! empty( $quote->rates[0]->meta['terminal_fallback'] ), 'Russian Post fallback must request skipped rules and service post-processing.' );
rp_smoke_assert( ! $quote->rates[0]->requires_pickup_point && ! empty( $quote->rates[0]->meta['no_pickup_selection'] ) && ! array_key_exists( $removed_pickup_mode_key, $quote->rates[0]->meta ), 'Russian Post fallback must bypass explicit pickup point selection with no_pickup_selection only.' );

$fallback_rule = new Rule( null, 'Add 100', true, 10, 'default', '', RuleActionTypes::CHANGE_PRICE, RuleOperationTypes::INCREASE, 100, RuleOperationBases::RUBLES, false, false );
$fallback_comment_rule = new Rule( null, 'Fallback comment', true, 20, 'default', '', RuleActionTypes::ADD_COMMENT, RuleOperationTypes::EQUALS, 0, RuleOperationBases::RUBLES, false, false, array(), array( 1 => 'and', 2 => 'and', 3 => 'and' ), 'Комментарий правила' );
$builder = new RuleAppliedRateBuilder( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) );
$fallback_rate = $quote->rates[0];
$fallback_request = rp_request();
$built = $builder->apply( $fallback_rate, new \WallsShop\WDC\Rules\Domain\RuleEvaluationContext( Money::from_rubles( 1000 ), $fallback_rate->price, $fallback_request->package, $fallback_request->destination, $fallback_rate->delivery_type, '', '2026-05-25' ), array( $fallback_rule, $fallback_comment_rule ) );
rp_smoke_assert( 0 === $built['rate']->price->get_kopecks() && array() === $built['rate']->comments && array() === $built['audit'], 'Rules must not change Russian Post terminal fallback price or comments.' );

$GLOBALS['wdc_rp_remote_mode'] = 'zero';
$GLOBALS['wdc_rp_transients'] = array();
$quote = $carrier->quote( rp_request() );
rp_smoke_assert( $quote->has_available_rates() && 'zero_price' === $quote->rates[0]->meta['fallback_reason'] && 0 === $quote->rates[0]->price->get_kopecks() && array() === $quote->rates[0]->comments, 'API zero price must trigger terminal fallback.' );

$GLOBALS['wdc_rp_remote_mode'] = 'missing_price';
$GLOBALS['wdc_rp_transients'] = array();
$quote = $carrier->quote( rp_request() );
rp_smoke_assert( $quote->has_available_rates() && 'missing_price' === $quote->rates[0]->meta['fallback_reason'] && 0 === $quote->rates[0]->price->get_kopecks() && array() === $quote->rates[0]->comments, 'API missing price must trigger terminal fallback.' );

$fallback_disabled_settings = rp_settings();
$fallback_disabled_settings->set( 'russian_post_worldwide_parcel', array_merge( $fallback_disabled_settings->all()['russian_post_worldwide_parcel'], array( 'fallback_enabled' => false ) ) );
$GLOBALS['wdc_rp_remote_mode'] = 'fail';
$GLOBALS['wdc_rp_transients'] = array();
$quote = rp_carrier( $fallback_disabled_settings )->quote( rp_request() );
rp_smoke_assert( ! $quote->has_available_rates() && 'http_status_500' === $quote->error_code, 'When fallback_enabled=false Russian Post must return no visible fallback rate.' );

$settings = rp_settings();
$carrier = rp_carrier( $settings );
$GLOBALS['wdc_rp_remote_mode'] = 'success';
$GLOBALS['wdc_rp_transients'] = array();
$quote = $carrier->quote( rp_request( 0 ) );
rp_smoke_assert( 0 === $quote->package->packaging_weight_g && 0 === $quote->package->total_weight_g, 'Direct carrier quote must not apply global packaging tiers by itself.' );

$settings->set( PackagingWeightCalculator::SETTINGS_KEY, array( array( 'cart_weight_from_g' => 0, 'cart_weight_to_g' => 3000, 'packaging_weight_g' => 250 ) ) );
$calculator = new PackagingWeightCalculator( $settings );
$service = DeliveryService::from_array( array( 'service_key' => RussianPostSettings::SERVICE_KEY, 'carrier_key' => RussianPostSettings::CARRIER_KEY, 'include_packaging_weight' => 1, 'packaging_weight_mode' => DeliveryService::PACKAGING_WEIGHT_TOTAL_WEIGHT ) );
$packaged = $calculator->apply_to_package( rp_request( 1000 )->package, $service );
$quote = $carrier->quote( new QuoteRequest( 'US', new Address( country_code: 'US', city: 'New York', street: 'Broadway', house: '1', raw_address: 'Broadway 1' ), $packaged->package, 'card', Money::from_rubles( 1000 ), '2026-05-25' ) );
rp_smoke_assert( 1250 === (int) ( $quote->rates[0]->meta['request_params']['weight'] ?? 0 ), 'Russian Post API must receive products weight plus packaging when service packaging is applied.' );
$disabled_service = DeliveryService::from_array( array( 'service_key' => RussianPostSettings::SERVICE_KEY, 'carrier_key' => RussianPostSettings::CARRIER_KEY, 'include_packaging_weight' => 0, 'packaging_weight_mode' => DeliveryService::PACKAGING_WEIGHT_TOTAL_WEIGHT ) );
$packaged = $calculator->apply_to_package( rp_request( 1000 )->package, $disabled_service );
$quote = $carrier->quote( new QuoteRequest( 'US', new Address( country_code: 'US', city: 'New York', street: 'Broadway', house: '1', raw_address: 'Broadway 1' ), $packaged->package, 'card', Money::from_rubles( 1000 ), '2026-05-25' ) );
rp_smoke_assert( 1000 === (int) ( $quote->rates[0]->meta['request_params']['weight'] ?? 0 ), 'Russian Post API must receive product weight only when service packaging is disabled.' );

$settings->set( 'russian_post_worldwide_parcel', array_merge( $settings->all()['russian_post_worldwide_parcel'], array( 'max_package_weight_g' => 100 ) ) );
$quote = rp_carrier( $settings )->quote( rp_request( 1000 ) );
rp_smoke_assert( 'overweight' === $quote->rates[0]->meta['fallback_reason'], 'Overweight must use fallback reason.' );

$settings = rp_settings();
$carrier = rp_carrier( $settings );
rp_smoke_assert( ! $carrier->supports_country( 'RU' ), 'RU must be excluded from Russian Post international carrier.' );
rp_smoke_assert( array() === $carrier->quote( rp_request( 1000, 'RU' ) )->rates, 'RU direct quote must not show ordinary rate.' );

$GLOBALS['wdc_rp_remote_mode'] = 'success';
$GLOBALS['wdc_rp_transients'] = array();
$GLOBALS['wdc_rp_tariff_requests'] = 0;
$carrier->quote( rp_request() );
$carrier->quote( rp_request() );
rp_smoke_assert( 1 === $GLOBALS['wdc_rp_tariff_requests'], 'Tariff API result must be cached.' );
$tariff_cache = array_filter( $GLOBALS['wdc_rp_transients'], static fn ( array $entry, string $key ): bool => str_starts_with( $key, 'wdc_rp_tariff_' ), ARRAY_FILTER_USE_BOTH );
rp_smoke_assert( 1 === count( $tariff_cache ) && current( $tariff_cache )['ttl'] > 0 && current( $tariff_cache )['ttl'] <= 86400, 'Tariff cache TTL must expire by end of day.' );

$registry = new CarrierRegistry();
$registry->register( $carrier );
$engine = new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) );
$orchestrator = new CheckoutOrchestrator( $registry, new RuleAppliedRateBuilder( $engine ), new RateSorter(), new FallbackRateFactory(), new CarrierExecutionGuard( new CheckoutLogger( new Logger() ) ), new CheckoutLogger( new Logger() ), rp_smoke_lead_time_normalizer( 0 ) );
$increase_rule = new Rule( null, 'Add 10', true, 10, 'default', '', RuleActionTypes::CHANGE_PRICE, RuleOperationTypes::INCREASE, 10, RuleOperationBases::RUBLES, false, false );
$comment_rule = new Rule( null, 'Comment', true, 20, 'default', '', RuleActionTypes::ADD_COMMENT, RuleOperationTypes::EQUALS, 0, RuleOperationBases::RUBLES, false, false, array(), array( 1 => 'and', 2 => 'and', 3 => 'and' ), 'Комментарий правила' );
$result = $orchestrator->calculate( rp_request(), array( $increase_rule, $comment_rule ), RateSorter::CHEAPEST, false );
rp_smoke_assert( 11000 === $result->rates[0]->price->get_kopecks(), 'Rules must apply after carrier quote.' );
rp_smoke_assert( in_array( 'Комментарий правила', $result->rates[0]->comments, true ), 'add_comment rule must appear in rate comments.' );
$disable_rule = new Rule( null, 'Disable US', true, 10, 'default', 'disabled', RuleActionTypes::DISABLE_RATE, RuleOperationTypes::EQUALS, 0, RuleOperationBases::RUBLES, false, false, array( new RuleCondition( null, null, 1, RuleConditionTypes::COUNTRY, RuleOperators::EQ, 'US' ) ) );
$result = $orchestrator->calculate( rp_request(), array( $disable_rule ), RateSorter::CHEAPEST, false );
rp_smoke_assert( 'fallback' === $result->rates[0]->carrier_key, 'disable_rate must hide Russian Post rate and leave checkout fallback.' );

$session = new CheckoutSessionManager();
$mapped = ( new WooCommerceRateMapper() )->map( $carrier->quote( rp_request() )->rates[0] );
$session->save_rates( array( $mapped['id'] => array_merge( $mapped['meta_data'], array( 'rate_id' => $mapped['id'], 'planned_delivery_comment' => 'test' ) ) ) );
WC()->session->set( 'chosen_shipping_methods', array( $mapped['id'] ) );
$order = new class {
	public array $meta = array();
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
};
( new OrderShippingMetaPersister( $session, new \WallsShop\WDC\Calendar\Services\DeliveryDateFormatter(), new \WallsShop\WDC\Orders\Application\DeliveryCalculationDataBuilder( new \WallsShop\WDC\Rules\Services\RuleFormulaFormatter() ) ) )->persist( $order, array() );
rp_smoke_assert( 'russian_post' === $order->meta['_wdc_platform_carrier_key'], 'Order meta must contain carrier_key.' );
rp_smoke_assert( 'russian_post_worldwide_parcel' === $order->meta['_wdc_platform_rate_id'], 'Order meta must contain rate_id.' );
rp_smoke_assert( DeliveryType::PICKUP === $order->meta['_wdc_platform_delivery_type'], 'Order meta must contain delivery_type.' );
rp_smoke_assert( 0 === $order->meta['_wdc_platform_requires_pickup_point'], 'Order meta must store requires_pickup_point = 0.' );
rp_smoke_assert( is_array( $order->meta['_wdc_platform_rate_meta'] ), 'Order meta must contain sanitized rate metadata.' );

$delivery_type_selector_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Checkout/WooCommerce/CheckoutDeliveryTypeSelector.php' );
rp_smoke_assert( ! str_contains( $delivery_type_selector_source, 'Для курьерской доставки будет использован адрес, указанный в checkout.' ), 'Checkout must not auto-render courier address comment.' );

$GLOBALS['wdc_rp_options'] = array();
$settings = new SettingsRepository();
$admin = new SettingsAdminPage( $settings, null, null, null, new RussianPostSettings( $settings ) );
ob_start();
$admin->render_page();
$rendered = (string) ob_get_clean();
rp_smoke_assert( ! str_contains( $rendered, 'russian_post_worldwide_parcel[' ), 'Platform settings page must not render Russian Post service-specific fields.' );

$sanitized = $admin->sanitize_settings( array( 'checkout_sort_mode' => 'fastest' ) );
rp_smoke_assert( ! array_key_exists( 'russian_post_worldwide_parcel', $sanitized ), 'Platform settings sanitize must not write Russian Post service-specific settings.' );

$settings->replace( array( 'russian_post_worldwide_parcel' => array( 'enabled' => false, 'max_package_weight_g' => 12345 ) ) );
$admin = new SettingsAdminPage( $settings, null, null, null, new RussianPostSettings( $settings ) );
$sanitized = $admin->sanitize_settings(
	array(
		'russian_post_worldwide_parcel' => array(
			'enabled' => '1',
			'api_endpoint' => '',
			'country_endpoint' => '',
			'fallback_text' => '',
		),
	)
);
rp_smoke_assert( ! array_key_exists( 'russian_post_worldwide_parcel', $sanitized ), 'Submitted Russian Post payload must be ignored by platform settings.' );

echo "Russian Post smoke test passed.\n";
