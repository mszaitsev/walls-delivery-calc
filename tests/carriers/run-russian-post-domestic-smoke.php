<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\RussianPost\DomesticTariffVariant;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticApiClient;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticTariffVariantResolver;
use WallsShop\WDC\Carriers\Runtime\RussianPostDomesticCarrier;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutRateRenderer;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\NewShippingMethod;
use WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMapper;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\DeliveryDaysFormatter;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Postcodes\DaDataPostcodeClient;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleEvaluationContext;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\ValueObjects\RuleActionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationBases;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationTypes;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'APP_ENCRYPTION_KEY' ) || define( 'APP_ENCRYPTION_KEY', 'wdc-rpd-postcode-test-key' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {}
}

$GLOBALS['wdc_rpd_options'] = array();
$GLOBALS['wdc_rpd_transients'] = array();
$GLOBALS['wdc_rpd_requests'] = array();
$GLOBALS['wdc_rpd_wc_session'] = new class {
	public array $data = array();
	public function get( string $key, mixed $default = null ): mixed { return $this->data[ $key ] ?? $default; }
	public function set( string $key, mixed $value ): void { $this->data[ $key ] = $value; }
};
$GLOBALS['wdc_rpd_wc'] = new class {
	public mixed $session;
	public function __construct() { $this->session = $GLOBALS['wdc_rpd_wc_session']; }
};

function rpd_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_rpd_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, mixed $autoload = null ): bool { $GLOBALS['wdc_rpd_options'][ $key ] = $value; return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_rpd_transients'][ $key ]['value'] ?? false; }
function set_transient( string $key, mixed $value, int $ttl = 0 ): bool { $GLOBALS['wdc_rpd_transients'][ $key ] = array( 'value' => $value, 'ttl' => $ttl ); return true; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function add_query_arg( array $params, string $url ): string { return $url . '?' . http_build_query( $params ); }
function wp_date( string $format ): string { return gmdate( $format, strtotime( '2026-05-26 10:00:00 UTC' ) ); }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'Asia/Novosibirsk' ); }
function esc_html( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr( mixed $text ): string { return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function is_wp_error( mixed $value ): bool { return false; }
function wp_remote_get( string $url, array $args = array() ): array {
	parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $params );
	$GLOBALS['wdc_rpd_requests'][] = $params;
	if ( ! empty( $params['force_errorcode'] ) ) {
		return array( 'response' => array( 'code' => 200 ), 'body' => json_encode( array( 'errorcode' => 42, 'errormsg' => 'bad domestic request' ) ) );
	}
	$object = (int) ( $params['object'] ?? 0 );
	if ( 27030 === $object ) {
		return array( 'response' => array( 'code' => 400 ), 'body' => json_encode( array( 'errorcode' => 27030, 'errormsg' => 'standard parcel fixture error' ) ) );
	}
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
				'items' => array(
					array(
						'name' => 'base',
						'serviceon' => 1,
						'tariff' => array( 'valnds' => 1234 ),
						'delivery' => array( 'min' => 2, 'max' => 4 ),
						'pay' => 80,
						'nds' => 20,
						'paynds' => 100,
					),
				),
			)
		),
	);
}
function wp_remote_post( string $url, array $args = array() ): array {
	return array(
		'response' => array( 'code' => 200 ),
		'body' => json_encode(
			array(
				'suggestions' => array(
					array(
						'data' => array(
							'fias_id' => 'fias-nsk',
							'city' => 'Novosibirsk',
							'settlement' => '',
							'postal_code' => '630099',
						),
					),
				),
			)
		),
	);
}
function wp_remote_retrieve_response_code( mixed $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
function wp_remote_retrieve_body( mixed $response ): string { return (string) ( $response['body'] ?? '' ); }
function WC(): object { return $GLOBALS['wdc_rpd_wc']; }

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
$encryption = new EncryptionService();
$settings->set(
	DaDataTokenPool::OPTION_KEY,
	array(
		array(
			'id' => 'token-1',
			'encrypted_token' => $encryption->encrypt( 'dadata-token' ),
			'masked_token' => '********',
			'daily_limit' => 100,
			'enabled' => true,
		),
	)
);
$postcode_client = new DaDataPostcodeClient( new DaDataTokenPool( $settings, $encryption ), new Logger(), 3 );
$carrier = new RussianPostDomesticCarrier( $domestic_settings, new RussianPostDomesticApiClient( $domestic_settings, new Logger() ), new RussianPostDomesticTariffVariantResolver(), new Logger(), $postcode_client );
$item = new PackageItem( 'SKU', 'Item', 1, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ), 1000 );
$package = Package::from_items( array( $item ), 0, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ) );
$request = new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Novosibirsk', postcode: '630099' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-26', array( 'service_key' => RussianPostDomesticSettings::PICKUP_SERVICE_KEY ) );
$quote = $carrier->quote( $request );

rpd_assert( $quote->has_available_rates(), 'Domestic pickup quote must return rates when postcode exists.' );
$objects = array_map( static fn( $rate ): string => $rate->tariff_key, $quote->rates );
rpd_assert( in_array( '4030', $objects, true ) && in_array( '23030', $objects, true ), 'Non-declared pickup variants must work when insurance is disabled.' );
rpd_assert( ! in_array( '4020', $objects, true ) && ! in_array( '27020', $objects, true ) && ! in_array( '23020', $objects, true ), 'Declared-value pickup variants must be hidden when insurance is disabled.' );
rpd_assert( in_array( '54020', $objects, true ), '54020 must remain always available.' );
rpd_assert( 99 === (int) $GLOBALS['wdc_rpd_requests'][0]['pack'], 'Domestic tariff requests must force pack=99.' );
rpd_assert( 27030 === (int) $GLOBALS['wdc_rpd_requests'][0]['object'] && 99 === (int) $GLOBALS['wdc_rpd_requests'][0]['pack'], '27030 request must force pack=99.' );
$skipped_27030 = $quote->raw_reference['skipped_tariffs'][0] ?? array();
rpd_assert( 27030 === (int) ( $skipped_27030['object_code'] ?? 0 ) && 400 === (int) ( $skipped_27030['http_code'] ?? 0 ) && 27030 === (int) ( $skipped_27030['errorcode'] ?? 0 ) && str_contains( (string) ( $skipped_27030['errormsg'] ?? '' ), 'standard parcel fixture error' ) && str_contains( (string) ( $skipped_27030['raw_error_body'] ?? '' ), 'standard parcel fixture error' ) && 99 === (int) ( $skipped_27030['request_params']['pack'] ?? 0 ), 'Skipped 27030 diagnostic must expose object, request params, HTTP/API error body, and pack=99.' );
rpd_assert( ! isset( $GLOBALS['wdc_rpd_requests'][0]['sumoc'] ), 'Non-declared variants must not send sumoc.' );
rpd_assert( ! empty( $quote->rates[0]->meta['no_pickup_selection'] ), 'Pickup variants must skip pickup selector.' );
$item_summary = $quote->rates[0]->meta['items_summary'][0] ?? array();
rpd_assert( 1 === (int) ( $item_summary['serviceon'] ?? 0 ) && 1234 === (int) ( $item_summary['tariff']['valnds'] ?? 0 ) && 2 === (int) ( $item_summary['delivery']['min'] ?? 0 ) && 4 === (int) ( $item_summary['delivery']['max'] ?? 0 ), 'Domestic items summary must include serviceon, tariff.valnds, and delivery min/max.' );

$settings->set( 'russian_post_domestic', array_merge( $settings->all()['russian_post_domestic'], array( 'insurance_enabled' => true ) ) );
$GLOBALS['wdc_rpd_requests'] = array();
$quote = $carrier->quote( $request );
$objects = array_map( static fn( $rate ): string => $rate->tariff_key, $quote->rates );
rpd_assert( in_array( '4020', $objects, true ) && in_array( '27020', $objects, true ) && in_array( '23020', $objects, true ), 'Declared-value variants must work when insurance is enabled.' );
rpd_assert( in_array( '54020', $objects, true ), '54020 must remain available when insurance is enabled.' );
rpd_assert( isset( $GLOBALS['wdc_rpd_requests'][0]['sumoc'] ), 'Declared-value variants must send sumoc.' );

$settings->set( 'russian_post_domestic', array_merge( $settings->all()['russian_post_domestic'], array( 'insurance_enabled' => false ) ) );
$courier = $carrier->quote( new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Novosibirsk', postcode: '630099' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-26', array( 'service_key' => RussianPostDomesticSettings::COURIER_SERVICE_KEY ) ) );
$courier_objects = array_map( static fn( $rate ): string => $rate->tariff_key, $courier->rates );
rpd_assert( in_array( '41030', $courier_objects, true ) && in_array( '52030', $courier_objects, true ) && in_array( '28030', $courier_objects, true ) && in_array( '7030', $courier_objects, true ), '41030, 52030, 28030, and 7030 must be available without insurance.' );
rpd_assert( ! in_array( '28020', $courier_objects, true ) && ! in_array( '7020', $courier_objects, true ), 'Declared-value EMS courier variants must be hidden without insurance.' );
rpd_assert( DeliveryType::COURIER === $courier->rates[0]->delivery_type && $courier->rates[0]->requires_courier_address, 'Courier variants must use courier delivery type.' );
$settings->set( 'russian_post_domestic', array_merge( $settings->all()['russian_post_domestic'], array( 'insurance_enabled' => true ) ) );
$courier_insured = $carrier->quote( new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Novosibirsk', postcode: '630099' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-26', array( 'service_key' => RussianPostDomesticSettings::COURIER_SERVICE_KEY ) ) );
$courier_insured_objects = array_map( static fn( $rate ): string => $rate->tariff_key, $courier_insured->rates );
rpd_assert( in_array( '28020', $courier_insured_objects, true ) && in_array( '7020', $courier_insured_objects, true ), 'Declared-value EMS courier variants must be available with insurance.' );
$settings->set( 'russian_post_domestic', array_merge( $settings->all()['russian_post_domestic'], array( 'insurance_enabled' => false ) ) );

$GLOBALS['wdc_rpd_requests'] = array();
$enriched = $carrier->quote( new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Novosibirsk', postcode: '', fias_id: 'fias-nsk' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-26', array( 'service_key' => RussianPostDomesticSettings::PICKUP_SERVICE_KEY ) ) );
rpd_assert( $enriched->has_available_rates() && '630099' === (string) ( $enriched->raw_reference['postcode'] ?? '' ), 'Domestic carrier must use DaData postcode enrichment fallback when postcode is empty.' );

$missing = $carrier->quote( new QuoteRequest( 'RU', new Address( country_code: 'RU', city: 'Novosibirsk', postcode: '999999999', fias_id: 'fias-nsk' ), $package, 'card', Money::from_rubles( 1000 ), '2026-05-26', array( 'service_key' => RussianPostDomesticSettings::PICKUP_SERVICE_KEY ) ) );
rpd_assert( ! $missing->has_available_rates() && 'postcode_required' === $missing->error_code, 'Domestic carrier must not silently calculate without a real postcode.' );

$api_client = new RussianPostDomesticApiClient( $domestic_settings, new Logger() );
$api_error = $api_client->calculate_tariff( array( 'object' => 4030, 'from' => '630005', 'to' => '630099', 'weight' => 1000, 'date' => '20260526', 'pack' => 99, 'force_errorcode' => 1 ) );
rpd_assert( empty( $api_error['success'] ) && 'api_error' === (string) ( $api_error['error_code'] ?? '' ), 'Domestic API client must treat errorcode/errormsg as API errors.' );
$http_error = $api_client->calculate_tariff( array( 'object' => 27030, 'from' => '630005', 'to' => '630099', 'weight' => 1000, 'date' => '20260526', 'pack' => 99 ) );
rpd_assert( empty( $http_error['success'] ) && 400 === (int) ( $http_error['http_code'] ?? 0 ) && str_contains( (string) ( $http_error['raw']['raw_body'] ?? '' ), 'standard parcel fixture error' ) && 27030 === (int) ( $http_error['raw']['errorcode'] ?? 0 ), 'Domestic API client must keep raw and decoded HTTP error bodies.' );
$variant_with_comment = DomesticTariffVariant::from_array( array_merge( ( new RussianPostDomesticTariffVariantResolver() )->defaults()[0]->to_array(), array( 'admin_comment' => 'internal note' ) ) );
rpd_assert( 'internal note' === $variant_with_comment->admin_comment && 'internal note' === $variant_with_comment->to_array()['admin_comment'], 'Domestic tariff admin_comment must save and load in tariff variant JSON.' );
rpd_assert( ! array_key_exists( 'admin_comment', $quote->rates[0]->meta['domestic_tariff_variant'] ?? array() ), 'Domestic tariff admin_comment must not be exposed to checkout/order runtime meta.' );

$rule = new Rule( null, '+2 days', true, 10, 'default', '', RuleActionTypes::CHANGE_DELIVERY_DAYS, RuleOperationTypes::INCREASE, 2, RuleOperationBases::CALENDAR_DAYS, false, false );
$rate = $quote->rates[0];
$builder = new RuleAppliedRateBuilder( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) );
$built = $builder->apply(
	$rate,
	new RuleEvaluationContext( Money::from_rubles( 1000 ), $rate->price, $package, $request->destination, $rate->delivery_type, 'card', '2026-05-26', array(), array( 'original_delivery_min_days' => 5, 'original_delivery_max_days' => 6 ) ),
	array( $rule )
);
rpd_assert( 7 === $built['rate']->delivery_days->min_days && 8 === $built['rate']->delivery_days->max_days && DateRange::UNIT_CALENDAR_DAYS === $built['rate']->delivery_days->unit, 'Delivery ranges must support 5-6 + 2 => 7-8.' );

$session_manager = new CheckoutSessionManager();
$session_manager->save_selected_tariff( RussianPostDomesticSettings::PICKUP_SERVICE_KEY, array( 'object_code' => '47030', 'title' => 'Посылка 1 класса' ) );
$method_reflection = new ReflectionClass( NewShippingMethod::class );
$method = $method_reflection->newInstanceWithoutConstructor();
$session_property = $method_reflection->getProperty( 'session_manager' );
$session_property->setAccessible( true );
$session_property->setValue( $method, $session_manager );
$selector_method = $method_reflection->getMethod( 'tariff_selector_rate' );
$selector_method->setAccessible( true );
$rate_23030 = new DeliveryRate( 'russian_post_domestic_pickup:23030', RussianPostDomesticSettings::CARRIER_KEY, 'Почта России', RussianPostDomesticSettings::PICKUP_SERVICE_KEY, RussianPostDomesticSettings::PICKUP_SERVICE_TITLE, '23030', 'Посылка онлайн', DeliveryType::PICKUP, 'Посылка онлайн', Money::from_rubles( 450 ), null, null, DateRange::range( 5, 6 ), '', '5-6 дн.', array(), false, '', false, false, array( 'tariff_selector_group' => true ) );
$rate_47030 = new DeliveryRate( 'russian_post_domestic_pickup:47030', RussianPostDomesticSettings::CARRIER_KEY, 'Почта России', RussianPostDomesticSettings::PICKUP_SERVICE_KEY, RussianPostDomesticSettings::PICKUP_SERVICE_TITLE, '47030', 'Посылка 1 класса', DeliveryType::PICKUP, 'Посылка 1 класса', Money::from_rubles( 659 ), null, Money::from_rubles( 700 ), DateRange::single( 3 ), '', '2-3 дн.', array(), false, '', false, false, array( 'tariff_selector_group' => true ) );
$selected_rate = $selector_method->invoke( $method, RussianPostDomesticSettings::PICKUP_SERVICE_KEY, array( $rate_23030, $rate_47030 ) );
rpd_assert( $selected_rate instanceof DeliveryRate && 659.0 === $selected_rate->price->get_rubles() && '47030' === (string) ( $selected_rate->meta['selected_tariff_object'] ?? '' ), 'Selected domestic tariff must drive WC rate cost and selected object.' );
rpd_assert( RussianPostDomesticSettings::PICKUP_SERVICE_TITLE . ': Посылка 1 класса - 3 дня' === $selected_rate->title, 'Domestic grouped method title must include service, selected tariff, and delivery days.' );
rpd_assert( '3 дня' === (string) ( $selected_rate->meta['tariff_variants'][1]['planned_delivery_comment'] ?? '' ), 'Domestic selector variant row must use final delivery days comment.' );
rpd_assert( is_array( $selected_rate->meta['tariff_variants'][1]['crossed_price'] ?? null ), 'Domestic selector variant row must keep crossed price per variant.' );
$selected_rate_again = $selector_method->invoke( $method, RussianPostDomesticSettings::PICKUP_SERVICE_KEY, array( $rate_23030, $rate_47030 ) );
$selected_session = $session_manager->selected_tariff( RussianPostDomesticSettings::PICKUP_SERVICE_KEY );
rpd_assert( $selected_rate_again instanceof DeliveryRate && 659.0 === $selected_rate_again->price->get_rubles() && '47030' === (string) ( $selected_session['object_code'] ?? '' ), 'Repeated tariff selector calculation must not reset valid selected tariff to the first variant.' );
$single_rate = $selector_method->invoke( $method, RussianPostDomesticSettings::PICKUP_SERVICE_KEY, array( $rate_23030 ) );
rpd_assert( array() === ( $single_rate->meta['tariff_variants'] ?? array() ) && RussianPostDomesticSettings::PICKUP_SERVICE_TITLE . ': Посылка онлайн - 5-6 дней' === $single_rate->title, 'Single-tariff domestic service must not expose radio selector variants.' );
$mapper = new WooCommerceRateMapper();
$mapped_single_rate = $mapper->map( $single_rate );
rpd_assert( RussianPostDomesticSettings::PICKUP_SERVICE_TITLE . ': Посылка онлайн - 5-6 дней' === $mapped_single_rate['label'], 'Single-tariff domestic grouped label must include planned delivery comment.' );
rpd_assert( true === ( $mapped_single_rate['meta_data']['domestic_tariff_grouped'] ?? false ), 'Single-tariff domestic grouped meta must keep grouped marker for checkout rendering.' );
$single_wc_rate = new class( $mapped_single_rate['meta_data'] ) {
	/** @param array<string,mixed> $meta */
	public function __construct( private array $meta ) {}
	/** @return array<string,mixed> */
	public function get_meta_data(): array { return $this->meta; }
};
ob_start();
( new CheckoutRateRenderer( $session_manager ) )->render( $single_wc_rate );
$single_rate_html = (string) ob_get_clean();
rpd_assert( ! str_contains( $single_rate_html, 'wdc-platform-delivery-comment' ) && ! str_contains( $single_rate_html, $single_rate->planned_delivery_comment ), 'Single-tariff domestic grouped planned delivery comment must stay in the label only.' );
$mapped_multi_rate = $mapper->map( $selected_rate );
rpd_assert( RussianPostDomesticSettings::PICKUP_SERVICE_TITLE . ': Посылка 1 класса - 3 дня' === $mapped_multi_rate['label'], 'Multi-tariff domestic grouped label must include selected tariff delivery days.' );
rpd_assert( '1 день' === DeliveryDaysFormatter::format( DateRange::single( 1 ) ) && '3 дня' === DeliveryDaysFormatter::format( DateRange::single( 3 ) ) && '5 дней' === DeliveryDaysFormatter::format( DateRange::single( 5 ) ) && '21 день' === DeliveryDaysFormatter::format( DateRange::single( 21 ) ), 'Delivery days formatter must use Russian singular/plural forms.' );
rpd_assert( '1-3 дня' === DeliveryDaysFormatter::format( DateRange::range( 1, 3 ) ) && '3-5 дней' === DeliveryDaysFormatter::format( DateRange::range( 3, 5 ) ), 'Delivery days formatter must use Russian range suffixes.' );
$rates_for_wc = $method_reflection->getMethod( 'rates_for_wc' );
$rates_for_wc->setAccessible( true );
$rate_24030 = new DeliveryRate( 'russian_post_domestic_courier:24030', RussianPostDomesticSettings::CARRIER_KEY, 'Почта России', RussianPostDomesticSettings::COURIER_SERVICE_KEY, RussianPostDomesticSettings::COURIER_SERVICE_TITLE, '24030', 'Курьер онлайн', DeliveryType::COURIER, 'Курьер онлайн', Money::from_rubles( 800 ), null, null, DateRange::single( 1 ), '', '1 дн.', array(), false, '', false, true, array( 'tariff_selector_group' => true ) );
$grouped_rates = $rates_for_wc->invoke( $method, array( $rate_23030, $rate_47030, $rate_24030 ) );
rpd_assert( 2 === count( $grouped_rates ) && RussianPostDomesticSettings::PICKUP_SERVICE_KEY === $grouped_rates[0]->service_key && RussianPostDomesticSettings::COURIER_SERVICE_KEY === $grouped_rates[1]->service_key, 'Pickup and courier domestic services must become separate grouped checkout methods.' );
rpd_assert( RussianPostDomesticSettings::COURIER_SERVICE_TITLE . ': Курьер онлайн - 1 день' === $grouped_rates[1]->title, 'Courier grouped method title must include courier service, tariff title, and delivery days.' );

echo "Russian Post domestic smoke OK\n";
