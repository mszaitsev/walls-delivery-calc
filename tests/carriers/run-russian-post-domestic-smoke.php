<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticApiClient;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticTariffVariantResolver;
use WallsShop\WDC\Carriers\Runtime\RussianPostDomesticCarrier;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleEvaluationContext;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\ValueObjects\RuleActionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationBases;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationTypes;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

$GLOBALS['wdc_rpd_options'] = array();
$GLOBALS['wdc_rpd_transients'] = array();
$GLOBALS['wdc_rpd_requests'] = array();

function rpd_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_rpd_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, mixed $autoload = null ): bool { $GLOBALS['wdc_rpd_options'][ $key ] = $value; return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_rpd_transients'][ $key ]['value'] ?? false; }
function set_transient( string $key, mixed $value, int $ttl = 0 ): bool { $GLOBALS['wdc_rpd_transients'][ $key ] = array( 'value' => $value, 'ttl' => $ttl ); return true; }
function wp_json_encode( mixed $value ): string|false { return json_encode( $value ); }
function add_query_arg( array $params, string $url ): string { return $url . '?' . http_build_query( $params ); }
function wp_date( string $format ): string { return gmdate( $format, strtotime( '2026-05-26 10:00:00 UTC' ) ); }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'Asia/Novosibirsk' ); }
function is_wp_error( mixed $value ): bool { return false; }
function wp_remote_get( string $url, array $args = array() ): array {
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );
	$GLOBALS['wdc_rpd_requests'][] = $params;
	$object = (int) ( $params['object'] ?? 0 );
	return array(
		'response' => array( 'code' => 200 ),
		'body' => json_encode(
			array(
				'pay' => ( 400 + $object % 100 ) * 100,
				'nds' => 0,
				'paynds' => ( 400 + $object % 100 ) * 100,
				'delivery' => array( 'min' => 5, 'max' => 6 ),
				'transtype' => 1,
				'delivery-to' => (string) ( $params['to'] ?? '' ),
				'items' => array( array( 'name' => 'base', 'paynds' => 100 ) ),
			)
		),
	);
}
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( mixed $response ): string { return (string) ( $response['body'] ?? '' ); }

$settings = new SettingsRepository();
$settings->replace(
	array_merge(
		$settings->defaults(),
		array(
			'russian_post_domestic' => array_merge(
				$settings->defaults()['russian_post_domestic'],
				array(
					'insurance_enabled' => false,
					'default_from_postcode' => '630005',
				)
			),
		)
	)
);
$domestic_settings = new RussianPostDomesticSettings( $settings );
$carrier = new RussianPostDomesticCarrier( $domestic_settings, new RussianPostDomesticApiClient( $domestic_settings, new Logger() ), new RussianPostDomesticTariffVariantResolver(), new Logger() );
$item = new PackageItem( 'SKU', 'Item', 1, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), 1000 );
$package = Package::from_items( array( $item ), 0, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ) );
$request = new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Новосибирск', postcode: '630099' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-26', array( 'service_key' => RussianPostDomesticSettings::PICKUP_SERVICE_KEY ) );
$quote = $carrier->quote( $request );

rpd_assert( $quote->has_available_rates(), 'Domestic pickup quote must return rates when postcode exists.' );
$objects = array_map( static fn( $rate ): string => $rate->tariff_key, $quote->rates );
rpd_assert( in_array( '4020', $objects, true ) && in_array( '27020', $objects, true ), 'Non-declared pickup variants 4020 and 27020 must work when insurance is disabled.' );
rpd_assert( ! in_array( '4030', $objects, true ) && ! in_array( '27030', $objects, true ), 'Declared-value variants must be hidden when insurance is disabled.' );
rpd_assert( in_array( '54020', $objects, true ), '54020 must remain always available.' );
rpd_assert( 99 === (int) $GLOBALS['wdc_rpd_requests'][0]['pack'], 'Domestic tariff requests must force pack=99.' );
rpd_assert( ! isset( $GLOBALS['wdc_rpd_requests'][0]['sumoc'] ), 'Non-declared variants must not send sumoc.' );
rpd_assert( ! empty( $quote->rates[0]->meta['no_pickup_selection'] ), 'Pickup variants must skip pickup selector.' );

$settings->set( 'russian_post_domestic', array_merge( $settings->all()['russian_post_domestic'], array( 'insurance_enabled' => true ) ) );
$GLOBALS['wdc_rpd_requests'] = array();
$quote = $carrier->quote( $request );
$objects = array_map( static fn( $rate ): string => $rate->tariff_key, $quote->rates );
rpd_assert( in_array( '4030', $objects, true ) && in_array( '27030', $objects, true ), 'Declared-value variants must work when insurance is enabled.' );
rpd_assert( isset( $GLOBALS['wdc_rpd_requests'][0]['sumoc'] ), 'Declared-value variants must send sumoc.' );

$courier = $carrier->quote( new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Новосибирск', postcode: '630099' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-26', array( 'service_key' => RussianPostDomesticSettings::COURIER_SERVICE_KEY ) ) );
rpd_assert( DeliveryType::COURIER === $courier->rates[0]->delivery_type && $courier->rates[0]->requires_courier_address, 'Courier variants must use courier delivery type.' );

$missing = $carrier->quote( new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Новосибирск', postcode: '999999999' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-26', array( 'service_key' => RussianPostDomesticSettings::PICKUP_SERVICE_KEY ) ) );
rpd_assert( ! $missing->has_available_rates() && 'postcode_required' === $missing->error_code, 'Domestic carrier must not silently calculate without a real postcode.' );

$rule = new Rule( null, '+2 days', true, 10, 'default', '', RuleActionTypes::CHANGE_DELIVERY_DAYS, RuleOperationTypes::INCREASE, 2, RuleOperationBases::CALENDAR_DAYS, false, false );
$rate = $quote->rates[0];
$builder = new RuleAppliedRateBuilder( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) );
$built = $builder->apply(
	$rate,
	new RuleEvaluationContext( Money::from_rubles( 1000 ), $rate->price, $package, $request->destination, $rate->delivery_type, 'card', '2026-05-26', array(), array( 'original_delivery_min_days' => 5, 'original_delivery_max_days' => 6 ) ),
	array( $rule )
);
rpd_assert( 7 === $built['rate']->delivery_days->min_days && 8 === $built['rate']->delivery_days->max_days && DateRange::UNIT_CALENDAR_DAYS === $built['rate']->delivery_days->unit, 'Delivery ranges must support 5-6 + 2 => 7-8.' );

echo "Russian Post domestic smoke OK\n";
