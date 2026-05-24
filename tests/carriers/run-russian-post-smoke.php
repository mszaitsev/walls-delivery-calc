<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Carriers\RussianPost\RussianPostApiClient;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory;
use WallsShop\WDC\Carriers\RussianPost\RussianPostSettings;
use WallsShop\WDC\Carriers\Runtime\RussianPostInternationalCarrier;
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
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
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
	public function __construct() { $this->session = $GLOBALS['wdc_rp_session']; }
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
						array( 'id' => 840, 'iso2' => 'US', 'name' => 'United States', 'parcel' => array( 'block' => 0 ) ),
						array( 'id' => 643, 'iso2' => 'RU', 'name' => 'Россия', 'parcel' => array( 'block' => 0 ) ),
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

	return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'paynds' => 10000, 'transtype' => 2 ) ) );
}
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( mixed $response ): string { return (string) ( $response['body'] ?? '' ); }

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
				'enable_demo_carrier' => false,
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

	return new RussianPostInternationalCarrier( $rp_settings, $client, new RussianPostCountryDirectory( $client, $logger ), $logger );
}

function rp_request( int $item_weight = 1000, string $country = 'US' ): QuoteRequest {
	$item = new PackageItem( 'SKU', 'Item', 1, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), $item_weight );
	$package = Package::from_items( array( $item ), 0, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ) );

	return new QuoteRequest( $country, new Address( country_code: $country, city: 'New York', street: 'Broadway', house: '1', raw_address: 'Broadway 1' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-25' );
}

$settings = rp_settings();
$carrier = rp_carrier( $settings );

$GLOBALS['wdc_rp_remote_mode'] = 'no_vat';
$quote = $carrier->quote( rp_request() );
rp_smoke_assert( $quote->has_available_rates(), 'API success must return a rate.' );
rp_smoke_assert( 33500 === $quote->rates[0]->price->get_kopecks(), 'API price without VAT must apply VAT once and formula ceil(price / 0.89 + 200).' );
rp_smoke_assert( false === $quote->rates[0]->meta['api_price_has_vat'], 'No-VAT source price must be marked as without VAT.' );

$GLOBALS['wdc_rp_remote_mode'] = 'success';
$GLOBALS['wdc_rp_transients'] = array();
$quote = $carrier->quote( rp_request() );
rp_smoke_assert( 31300 === $quote->rates[0]->price->get_kopecks(), 'API price with VAT must not double VAT.' );

$GLOBALS['wdc_rp_remote_mode'] = 'fail';
$GLOBALS['wdc_rp_transients'] = array();
$quote = $carrier->quote( rp_request() );
rp_smoke_assert( $quote->has_available_rates(), 'API fail must return fallback rate.' );
rp_smoke_assert( 0 === $quote->rates[0]->price->get_kopecks(), 'Fallback rate must be zero cost.' );
rp_smoke_assert( 'http_status_500' === $quote->rates[0]->meta['fallback_reason'], 'Fallback must keep reason in meta.' );

$GLOBALS['wdc_rp_remote_mode'] = 'success';
$GLOBALS['wdc_rp_transients'] = array();
$quote = $carrier->quote( rp_request( 0 ) );
rp_smoke_assert( 150 === $quote->package->packaging_weight_g && 150 === $quote->package->total_weight_g, 'No-weight product must be 0g plus packaging tier.' );

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
$orchestrator = new CheckoutOrchestrator( $registry, new RuleAppliedRateBuilder( $engine ), new RateSorter(), new FallbackRateFactory(), new CarrierExecutionGuard( new CheckoutLogger( new Logger() ) ), new CheckoutLogger( new Logger() ) );
$increase_rule = new Rule( null, 'Add 10', true, 10, 'default', '', RuleActionTypes::CHANGE_PRICE, RuleOperationTypes::INCREASE, 10, RuleOperationBases::RUBLES, false, false );
$comment_rule = new Rule( null, 'Comment', true, 20, 'default', '', RuleActionTypes::ADD_COMMENT, RuleOperationTypes::EQUALS, 0, RuleOperationBases::RUBLES, false, false, array(), array( 1 => 'and', 2 => 'and', 3 => 'and' ), 'Комментарий правила' );
$result = $orchestrator->calculate( rp_request(), array( $increase_rule, $comment_rule ), RateSorter::CHEAPEST, false );
rp_smoke_assert( 32300 === $result->rates[0]->price->get_kopecks(), 'Rules must apply after carrier quote.' );
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
( new OrderShippingMetaPersister( $session ) )->persist( $order, array() );
rp_smoke_assert( 'russian_post' === $order->meta['_wdc_platform_carrier_key'], 'Order meta must contain carrier_key.' );
rp_smoke_assert( 'russian_post_worldwide_parcel' === $order->meta['_wdc_platform_rate_id'], 'Order meta must contain rate_id.' );
rp_smoke_assert( DeliveryType::COURIER === $order->meta['_wdc_platform_delivery_type'], 'Order meta must contain delivery_type.' );
rp_smoke_assert( 0 === $order->meta['_wdc_platform_requires_pickup_point'], 'Order meta must store requires_pickup_point = 0.' );
rp_smoke_assert( is_array( $order->meta['_wdc_platform_rate_meta'] ), 'Order meta must contain sanitized rate metadata.' );

$legacy_diff = function_exists( 'shell_exec' ) ? trim( (string) shell_exec( 'git diff --name-only -- includes' ) ) : '';
rp_smoke_assert( '' === $legacy_diff, 'legacy includes/* must not be modified.' );

echo "Russian Post smoke test passed.\n";
