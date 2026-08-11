<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\Api\PekHttpClientInterface;
use WallsShop\WDC\Carriers\Pek\Api\PekRequestBudget;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\Pek\Quote\PekLightCargoSurchargePolicy;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteCargoBuilder;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteMessageSanitizer;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteOptions;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteRequestBuilder;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteResponseParser;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteService;
use WallsShop\WDC\Carriers\Pek\Admin\PekQuoteDiagnosticStore;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

function pek_quote_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function pek_quote_assert_no_secrets( string $value, array $secrets, string $message ): void {
	foreach ( $secrets as $secret ) {
		pek_quote_assert( '' === (string) $secret || ! str_contains( $value, (string) $secret ), $message . ': ' . (string) $secret );
	}
}

function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['pek_quote_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool $autoload = true ): bool { $GLOBALS['pek_quote_options'][ $option ] = $value; return true; }
function current_datetime(): DateTimeImmutable { return new DateTimeImmutable( '2026-08-04 12:00:00', new DateTimeZone( 'UTC' ) ); }
function current_time( string $type ): string { return 'mysql' === $type ? '2026-08-04 12:00:00' : '2026-08-04'; }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'UTC' ); }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function get_transient( string $key ): mixed { return $GLOBALS['pek_quote_transients'][ $key ]['value'] ?? false; }
function set_transient( string $key, mixed $value, int $ttl = 0 ): bool { $GLOBALS['pek_quote_transients'][ $key ] = array( 'value' => $value, 'ttl' => $ttl ); return true; }
function delete_transient( string $key ): bool { unset( $GLOBALS['pek_quote_transients'][ $key ] ); return true; }
function get_current_user_id(): int { return 17; }
function wc_get_logger(): object { return $GLOBALS['pek_quote_wc_logger']; }

final class PekQuoteFakeWooLogger {
	public array $entries = array();
	public function log( string $level, string $message, array $context = array() ): void {
		$this->entries[] = compact( 'level', 'message', 'context' );
	}
}

final class PekQuoteFakeHttp implements PekHttpClientInterface {
	public array $requests = array();
	public function __construct( private array $responses ) {}
	public function request( string $method, string $url, array $args ): array {
		$this->requests[] = array( 'method' => strtoupper( $method ), 'url' => $url, 'args' => $args );
		return array_shift( $this->responses ) ?? array( 'status' => 500, 'body' => '{}' );
	}
}

function pek_quote_response( mixed $body, int $status = 200 ): array {
	return array( 'status' => $status, 'body' => json_encode( $body, JSON_UNESCAPED_UNICODE ) ?: '{}' );
}

function pek_quote_success_response(): array {
	return array(
		'hasError' => false,
		'currencyCode' => '643',
		'branchSenderUID' => 'sender-branch',
		'branchSender' => 'Новосибирск',
		'branchReceiverUID' => 'receiver-branch',
		'branchReceiver' => 'Москва',
		'transfers' => array(
			array( 'type' => 3, 'hasError' => false, 'costTotal' => 1234.56, 'estDeliveryTime' => 3, 'services' => array( array( 'serviceType' => 'Перевозка', 'cost' => 1000 ), array( 'serviceType' => 'Страхование', 'cost' => 234.56, 'insuranceTerm' => false, 'services' => array( array( 'serviceType' => 'Страхование', 'cost' => 0, 'insuranceTerm' => true, 'services' => null ) ) ) ) ),
		),
		'commonTerms' => 'safe',
	);
}

function pek_quote_light_cargo_services_response(): array {
	return array(
		'hasError' => false,
		'currencyCode' => '643',
		'branchSenderUID' => 'sender-branch',
		'branchSender' => 'Новосибирск',
		'branchReceiverUID' => 'receiver-branch',
		'branchReceiver' => 'Москва',
		'transfers' => array(
			array(
				'type' => 3,
				'hasError' => false,
				'costTotal' => 1017.92,
				'estDeliveryTime' => 6,
				'services' => array(
					array( 'serviceType' => 'Перевозка', 'senderCity' => 'Новосибирск', 'cost' => 820, 'info' => 'Автоперевозка', 'services' => null ),
					array( 'serviceType' => 'Упаковка', 'senderCity' => 'Новосибирск', 'cost' => 70, 'info' => 'Мешок малый (70×90)', 'services' => null ),
					array( 'serviceType' => 'Дополнительные услуги', 'senderCity' => 'Новосибирск', 'cost' => 20, 'info' => 'Пломбировка', 'services' => null ),
				),
			),
		),
	);
}

/** @param array<int,array<string,mixed>> $services */
function pek_quote_services_contain_text( array $services, string $needle ): bool {
	foreach ( $services as $service ) {
		foreach ( array( 'serviceType', 'senderCity', 'info' ) as $key ) {
			if ( is_string( $service[ $key ] ?? null ) && str_contains( $service[ $key ], $needle ) ) {
				return true;
			}
		}
		if ( is_array( $service['services'] ?? null ) && pek_quote_services_contain_text( $service['services'], $needle ) ) {
			return true;
		}
	}

	return false;
}

function pek_quote_boot( array $responses, array $sensitive = array(), bool $with_logger = false ): array {
	$GLOBALS['pek_quote_options'] = array();
	$GLOBALS['pek_quote_transients'] = array();
	$GLOBALS['pek_quote_wc_logger'] = new PekQuoteFakeWooLogger();
	$repository = new SettingsRepository();
	$settings = new PekSettings( $repository, new \WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer() );
	$credentials = new PekCredentials( $repository, new EncryptionService() );
	if ( ! defined( 'APP_ENCRYPTION_KEY' ) ) {
		define( 'APP_ENCRYPTION_KEY', 'pek-quote-test-key' );
	}
	$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => (string) ( $sensitive['login'] ?? 'login' ), 'pek_api_key' => (string) ( $sensitive['api_key'] ?? 'secret' ) ) );
	$settings->save_from_admin( array( PekSettings::SENDER_INN_KEY => (string) ( $sensitive['inn'] ?? '5400000000' ), PekSettings::SENDER_KPP_KEY => (string) ( $sensitive['kpp'] ?? '540001001' ), PekSettings::CLIENT_CARD_KEY => (string) ( $sensitive['client_card'] ?? 'client-card' ) ) );
	$repository->set( PekSettings::SENDER_WAREHOUSE_KEY, array( 'warehouseId' => 'sender-wh', 'source' => 'free', 'branchTimezone' => 'UTC' ) );
	$http = new PekQuoteFakeHttp( $responses );
	$api = new PekApiClient( $settings, $credentials, $http, new PekRequestBudget( $settings ) );
	$builder = new PekQuoteRequestBuilder( $settings, new PekQuoteCargoBuilder() );
	$service = new PekQuoteService( $credentials, $api, $builder, new PekQuoteResponseParser(), new PekQuoteMessageSanitizer( $credentials, $settings ), new PekLightCargoSurchargePolicy( $settings ), $with_logger ? new Logger() : null );

	return array( $settings, $http, $builder, $service );
}

function pek_quote_request( int $weight_g = 1001, int $length = 10, int $width = 10, int $height = 10, int $declared_kopecks = 100050 ): QuoteRequest {
	$money = Money::from_kopecks( $declared_kopecks );
	return new QuoteRequest(
		'RU',
		new Address( country_code: 'RU', city: 'Москва', raw_address: 'Россия, Москва' ),
		new Package( array(), $money, $money, $weight_g, 0, $weight_g, $length, $width, $height, $length * $width * $height, 'cart' ),
		'',
		Money::from_rubles( 999999 ),
		'2026-08-04',
		array( 'selected_location_id' => 153912 )
	);
}

function pek_quote_custom_package_request( int $product_weight_g, int $packaging_weight_g, int $total_weight_g, int $quantity = 0 ): QuoteRequest {
	$money = Money::from_rubles( 1000 );
	$items = array();
	if ( $quantity > 0 && $product_weight_g > 0 ) {
		$items[] = new PackageItem( 'sku', 'Товар', $quantity, Money::from_rubles( 100 ), Money::from_rubles( 100 * $quantity ), (int) floor( $product_weight_g / $quantity ), 10, 10, 10 );
	}

	return new QuoteRequest(
		'RU',
		new Address( country_code: 'RU', city: 'Москва', raw_address: 'Россия, Москва' ),
		new Package( $items, $money, $money, $product_weight_g, $packaging_weight_g, $total_weight_g, 10, 10, 10, 1000, 'cart' ),
		'',
		$money,
		'2026-08-04',
		array( 'selected_location_id' => 153912 )
	);
}

function pek_quote_volume_request(): QuoteRequest {
	$money = Money::from_rubles( 1000 );
	return new QuoteRequest(
		'RU',
		new Address( country_code: 'RU', city: 'Москва', raw_address: 'Россия, Москва' ),
		new Package( array(), $money, $money, 1, 0, 1, null, 25, null, 1, 'manual' ),
		'',
		$money,
		'2026-08-04',
		array( 'selected_location_id' => 153912 )
	);
}

list( $settings, $http, $builder, $service ) = pek_quote_boot( array( pek_quote_response( pek_quote_success_response() ) ) );
$request = pek_quote_request();
$pickup = new PekQuoteOptions( PekQuoteOptions::MODE_PICKUP, '2026-08-04T13:15:00', 'receiver-wh' );
$result = $service->calculate( $request, $pickup );
pek_quote_assert( $result->success && 123456 === $result->carrier_price_kopecks && 132456 === $result->price_kopecks && 7000 === $result->bag_surcharge_kopecks && 2000 === $result->sealing_surcharge_kopecks && 9000 === $result->light_cargo_surcharge_kopecks && 3 === $result->delivery_days, 'PEK quote service must keep carrier costTotal separate and add default store light-cargo surcharges to final price.' );
pek_quote_assert( '/calculator/calculateprice/' === $result->endpoint && 'POST' === $result->method && 200 === $result->http_status, 'Successful PEK quote result must preserve calculator endpoint, method and HTTP status.' );
pek_quote_assert( false === $result->services[1]['insuranceTerm'] && true === $result->services[1]['services'][0]['insuranceTerm'], 'PEK quote services must preserve insuranceTerm as Boolean including nested services.' );
pek_quote_assert( 1 === count( $http->requests ) && 'POST' === $http->requests[0]['method'] && str_ends_with( $http->requests[0]['url'], '/calculator/calculateprice/' ), 'PEK quote must call POST /calculator/calculateprice/ exactly once.' );
$payload = json_decode( (string) $http->requests[0]['args']['body'], true );
pek_quote_assert( is_array( $payload ) && '643' === $payload['currencyCode'] && array( 3 ) === $payload['types'], 'PEK quote payload must use currencyCode=643 and types=[3].' );
pek_quote_assert( 'sender-wh' === $payload['senderWarehouseId'] && 'receiver-wh' === $payload['receiverWarehouseId'] && false === $payload['isPickUp'] && false === $payload['isDelivery'], 'Pickup quote payload must use configured sender warehouse and receiver warehouse without pickup/delivery services.' );
pek_quote_assert( ! array_key_exists( 'pickup', $payload ) && ! array_key_exists( 'delivery', $payload ) && ! array_key_exists( 'transportingTypes', $payload ) && ! array_key_exists( 'senderCityId', $payload ) && ! array_key_exists( 'receiverCityId', $payload ) && ! array_key_exists( 'overSize', $payload ), 'PEK quote payload must avoid pickup/delivery blocks and deprecated calculator fields in pickup mode.' );
pek_quote_assert( true === $payload['isInsurance'] && 1000.5 === $payload['isInsurancePrice'], 'PEK quote payload must use Package declared_value as mandatory insurance price with kopecks preserved.' );
pek_quote_assert( '5400000000' === $payload['counterpart']['inn'] && '540001001' === $payload['counterpart']['kpp'] && 'client-card' === $payload['counterpart']['counterpartClientCard'] && array( 1, 3 ) === $payload['counterpart']['whoMakesCalculation'], 'PEK quote payload must include counterpart and client card contract fields.' );
pek_quote_assert( 1.01 === $payload['cargos'][0]['weight'] && 1.01 === $payload['cargos'][0]['maxPlaceWeight'] && 0.1 === $payload['cargos'][0]['length'] && false === $payload['cargos'][0]['isHP'] && 0 === $payload['cargos'][0]['sealingPositionsCount'], 'PEK cargo builder must use one aggregate place with upward rounding and never request bag/sealing services through PEK API.' );
pek_quote_assert( false === ( $result->safe_request['cargo_policy']['isHP'] ?? null ) && 0 === ( $result->safe_request['cargo_policy']['sealingPositionsCount'] ?? null ) && 1001 === ( $result->safe_request['cargo_policy']['product_weight_g'] ?? null ) && 1001 === ( $result->safe_request['cargo_policy']['total_weight_g'] ?? null ), 'PEK quote safe request must expose actual outgoing cargo policy without store surcharge metadata.' );
pek_quote_assert( true === ( $result->pricing_adjustment['light_cargo_eligible'] ?? null ) && true === ( $result->pricing_adjustment['surcharge_applied'] ?? null ) && 'applied' === ( $result->pricing_adjustment['surcharge_reason'] ?? '' ) && 2 === count( $result->surcharges ), 'PEK quote result must expose separate store surcharge diagnostics and rows.' );
pek_quote_assert( 7000 === $settings->light_cargo_bag_price_kopecks() && 2000 === $settings->light_cargo_sealing_price_kopecks() && 3000 === $settings->light_cargo_weight_limit_g(), 'PEK light-cargo surcharge settings must provide existing-install defaults without migration.' );
$settings->save_from_admin( array( PekSettings::SENDER_INN_KEY => '5400000000', PekSettings::SENDER_KPP_KEY => '540001001', PekSettings::CLIENT_CARD_KEY => 'client-card', PekSettings::LIGHT_CARGO_BAG_PRICE_RUB_KEY => '85.50', PekSettings::LIGHT_CARGO_SEALING_PRICE_RUB_KEY => '25,25', PekSettings::LIGHT_CARGO_WEIGHT_LIMIT_G_KEY => 2500 ) );
pek_quote_assert( 8550 === $settings->light_cargo_bag_price_kopecks() && 2525 === $settings->light_cargo_sealing_price_kopecks() && 2500 === $settings->light_cargo_weight_limit_g(), 'PEK light-cargo surcharge settings must save decimal RUB values and custom weight limit.' );

foreach ( array(
	1 => array( false, 0 ),
	2999 => array( false, 0 ),
	3000 => array( false, 0 ),
	3001 => array( false, 0 ),
) as $case_weight_g => $expected_policy ) {
	list( $settings_case, $http_case, $builder_case, $service_case ) = pek_quote_boot( array( pek_quote_response( pek_quote_success_response() ) ) );
	$case_payload = $builder_case->build( pek_quote_request( (int) $case_weight_g ), $pickup );
	pek_quote_assert( $expected_policy[0] === $case_payload['cargos'][0]['isHP'] && $expected_policy[1] === $case_payload['cargos'][0]['sealingPositionsCount'], 'PEK API payload must always keep isHP=false and sealingPositionsCount=0 for product weight ' . (string) $case_weight_g . 'g.' );
}

list( $settings_unknown_weight, $http_unknown_weight, $builder_unknown_weight, $service_unknown_weight ) = pek_quote_boot( array() );
$unknown_weight_payload = $builder_unknown_weight->build( pek_quote_custom_package_request( 0, 1000, 1000 ), $pickup );
pek_quote_assert( false === $unknown_weight_payload['cargos'][0]['isHP'] && 0 === $unknown_weight_payload['cargos'][0]['sealingPositionsCount'] && 1.0 === $unknown_weight_payload['cargos'][0]['weight'], 'Unknown product weight must not trigger light-cargo services while calculator transport weight still uses total weight.' );
list( $settings_unknown_service, $http_unknown_service, $builder_unknown_service, $service_unknown_service ) = pek_quote_boot( array( pek_quote_response( pek_quote_success_response() ) ) );
$unknown_service_result = $service_unknown_service->calculate( pek_quote_custom_package_request( 0, 1000, 1000 ), $pickup );
pek_quote_assert( 123456 === $unknown_service_result->price_kopecks && false === $unknown_service_result->pricing_adjustment['surcharge_applied'] && 'weight_not_known' === $unknown_service_result->pricing_adjustment['surcharge_reason'], 'Unknown product weight must not trigger store light-cargo surcharges.' );

list( $settings_packaging_a, $http_packaging_a, $builder_packaging_a, $service_packaging_a ) = pek_quote_boot( array() );
$packaging_a = $builder_packaging_a->build( pek_quote_custom_package_request( 2999, 1000, 3999 ), $pickup );
pek_quote_assert( 4.0 === $packaging_a['cargos'][0]['weight'] && false === $packaging_a['cargos'][0]['isHP'] && 0 === $packaging_a['cargos'][0]['sealingPositionsCount'], 'PEK API payload must ignore store light-cargo surcharges while calculator weight includes packaging weight.' );
list( $settings_packaging_b, $http_packaging_b, $builder_packaging_b, $service_packaging_b ) = pek_quote_boot( array() );
$packaging_b = $builder_packaging_b->build( pek_quote_custom_package_request( 3000, 1000, 4000 ), $pickup );
pek_quote_assert( 4.0 === $packaging_b['cargos'][0]['weight'] && false === $packaging_b['cargos'][0]['isHP'] && 0 === $packaging_b['cargos'][0]['sealingPositionsCount'], 'Product weight at 3000g must not trigger light-cargo services even when packaging increases total weight.' );
list( $settings_items, $http_items, $builder_items, $service_items ) = pek_quote_boot( array() );
$items_payload = $builder_items->build( pek_quote_custom_package_request( 2500, 0, 2500, 10 ), $pickup );
pek_quote_assert( 1 === count( $items_payload['cargos'] ) && false === $items_payload['cargos'][0]['isHP'] && 0 === $items_payload['cargos'][0]['sealingPositionsCount'], 'PEK quote must keep one aggregate cargo place and must not turn product item quantity into PEK sealing positions.' );

foreach ( array(
	2999 => array( 109000, true, 'applied' ),
	3000 => array( 100000, false, 'weight_at_or_above_limit' ),
) as $weight_for_surcharge => $expected_surcharge ) {
	list( $settings_surcharge, $http_surcharge, $builder_surcharge, $service_surcharge ) = pek_quote_boot( array( pek_quote_response( pek_quote_success_response() ) ) );
	$surcharge_result = $service_surcharge->calculate( pek_quote_request( (int) $weight_for_surcharge, declared_kopecks: 100000 ), $pickup );
	pek_quote_assert( 123456 === $surcharge_result->carrier_price_kopecks && $expected_surcharge[1] === $surcharge_result->pricing_adjustment['surcharge_applied'] && $expected_surcharge[2] === $surcharge_result->pricing_adjustment['surcharge_reason'], 'PEK surcharge threshold must be strict for product weight ' . (string) $weight_for_surcharge . 'g.' );
}

list( $settings_custom_limit, $http_custom_limit, $builder_custom_limit, $service_custom_limit ) = pek_quote_boot( array( pek_quote_response( pek_quote_success_response() ), pek_quote_response( pek_quote_success_response() ) ) );
$settings_custom_limit->save_from_admin( array( PekSettings::SENDER_INN_KEY => '5400000000', PekSettings::SENDER_KPP_KEY => '540001001', PekSettings::CLIENT_CARD_KEY => 'client-card', PekSettings::LIGHT_CARGO_BAG_PRICE_RUB_KEY => '80', PekSettings::LIGHT_CARGO_SEALING_PRICE_RUB_KEY => '25', PekSettings::LIGHT_CARGO_WEIGHT_LIMIT_G_KEY => 2500 ) );
$custom_limit_applied = $service_custom_limit->calculate( pek_quote_request( 2499 ), $pickup );
$custom_limit_blocked = $service_custom_limit->calculate( pek_quote_request( 2500 ), $pickup );
pek_quote_assert( 133956 === $custom_limit_applied->price_kopecks && 123456 === $custom_limit_blocked->price_kopecks && 'applied' === $custom_limit_applied->pricing_adjustment['surcharge_reason'] && 'weight_at_or_above_limit' === $custom_limit_blocked->pricing_adjustment['surcharge_reason'], 'Custom PEK light-cargo limit and prices must affect only store surcharge final price.' );

list( $settings_zero, $http_zero, $builder_zero, $service_zero ) = pek_quote_boot( array( pek_quote_response( pek_quote_success_response() ) ) );
$settings_zero->save_from_admin( array( PekSettings::SENDER_INN_KEY => '5400000000', PekSettings::SENDER_KPP_KEY => '540001001', PekSettings::CLIENT_CARD_KEY => 'client-card', PekSettings::LIGHT_CARGO_BAG_PRICE_RUB_KEY => '0', PekSettings::LIGHT_CARGO_SEALING_PRICE_RUB_KEY => '0', PekSettings::LIGHT_CARGO_WEIGHT_LIMIT_G_KEY => 3000 ) );
$zero_surcharge = $service_zero->calculate( pek_quote_request( 1000 ), $pickup );
pek_quote_assert( 123456 === $zero_surcharge->price_kopecks && array() === $zero_surcharge->surcharges && 'zero_surcharge' === $zero_surcharge->pricing_adjustment['surcharge_reason'], 'Zero PEK light-cargo surcharge settings must not create rows or change final price.' );

list( $settings_packaging_policy, $http_packaging_policy, $builder_packaging_policy, $service_packaging_policy ) = pek_quote_boot( array( pek_quote_response( pek_quote_success_response() ) ) );
$packaging_policy = $service_packaging_policy->calculate( pek_quote_custom_package_request( 2999, 5000, 7999 ), $pickup );
pek_quote_assert( 8.0 === (float) json_decode( (string) $http_packaging_policy->requests[0]['args']['body'], true )['cargos'][0]['weight'] && true === $packaging_policy->pricing_adjustment['surcharge_applied'], 'PEK surcharge eligibility must use product weight while calculator transport weight uses total weight including packaging.' );

list( $settings_light_services, $http_light_services, $builder_light_services, $service_light_services ) = pek_quote_boot( array( pek_quote_response( pek_quote_light_cargo_services_response() ) ) );
$light_services_result = $service_light_services->calculate( pek_quote_request( 1000 ), $pickup );
pek_quote_assert( 101792 === $light_services_result->carrier_price_kopecks && 110792 === $light_services_result->price_kopecks && pek_quote_services_contain_text( $light_services_result->services, 'Мешок малый' ) && pek_quote_services_contain_text( $light_services_result->services, 'Пломбировка' ), 'PEK quote result must use transfer costTotal as authoritative carrier total, expose returned services separately, and add store surcharges only to final price.' );

list( $settings2, $http2, $builder2, $service2 ) = pek_quote_boot( array( pek_quote_response( pek_quote_success_response() ) ) );
$courier = new PekQuoteOptions( PekQuoteOptions::MODE_COURIER, '2026-08-04T13:15:00', '', 'Россия, Москва, улица Большая Лубянка, 2', 55.754058, 37.62049 );
$service2->calculate( pek_quote_volume_request(), $courier );
$courier_payload = json_decode( (string) $http2->requests[0]['args']['body'], true );
pek_quote_assert( ! array_key_exists( 'receiverWarehouseId', $courier_payload ) && true === $courier_payload['isDelivery'] && false === $courier_payload['isPickUp'], 'Courier quote payload must not use receiverWarehouseId and must enable delivery.' );
pek_quote_assert( 'Россия, Москва, улица Большая Лубянка, 2' === $courier_payload['delivery']['address'] && '55.754058' === $courier_payload['delivery']['coordinates']['latitude'] && '37.62049' === $courier_payload['delivery']['coordinates']['longitude'], 'Courier quote payload must include delivery address and decimal-string coordinates.' );
pek_quote_assert( 0.01 === $courier_payload['cargos'][0]['weight'] && 0.01 === $courier_payload['cargos'][0]['volume'] && 0.25 === $courier_payload['cargos'][0]['maxSize'], 'PEK volume/maxSize path must round upward to hundredths.' );

list( $settings3, $http3, $builder3, $service3 ) = pek_quote_boot( array() );
$bad_country = new QuoteRequest( 'UZ', new Address( country_code: 'UZ', city: 'Ташкент', raw_address: 'Узбекистан, Ташкент' ), pek_quote_request()->package, '', Money::from_rubles( 1 ), '2026-08-04' );
$country_result = $service3->calculate( $bad_country, $pickup );
pek_quote_assert( ! $country_result->success && 'pek_quote_country_not_supported' === $country_result->error_code && array() === $http3->requests, 'Unsupported PEK quote direction must fail closed before API call.' );

list( $settings_root, $http_root, $builder_root, $service_root ) = pek_quote_boot( array( pek_quote_response( array( 'hasError' => true, 'errorMessage' => 'Ошибка расчёта' ) ) ) );
$root_error = $service_root->calculate( pek_quote_request(), $pickup );
pek_quote_assert( ! $root_error->success && 'pek_quote_root_error' === $root_error->error_code && 'quote_calculator_logical' === $root_error->failure_stage && 200 === $root_error->http_status && '/calculator/calculateprice/' === $root_error->endpoint && ! str_contains( $root_error->error_code, 'pek_has_error' ), 'Calculator root hasError must be owned by quote parser and preserve HTTP metadata.' );

list( $settings_generic, $credentials_generic, $api_generic ) = ( function (): array {
	$GLOBALS['pek_quote_options'] = array();
	$repository = new SettingsRepository();
	$settings = new PekSettings( $repository, new \WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer() );
	$credentials = new PekCredentials( $repository, new EncryptionService() );
	$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login', 'pek_api_key' => 'secret' ) );
	$api = new PekApiClient( $settings, $credentials, new PekQuoteFakeHttp( array( pek_quote_response( array( 'hasError' => true, 'errorMessage' => 'generic failure' ) ) ) ), new PekRequestBudget( $settings ) );

	return array( $settings, $credentials, $api );
} )();
try {
	$api_generic->branches_all_for_warehouse( 'warehouse' );
	pek_quote_assert( false, 'Non-calculator hasError must keep generic PekApiClient handling.' );
} catch ( PekApiException $exception ) {
	pek_quote_assert( 'pek_has_error' === (string) ( $exception->context()['error_code'] ?? '' ), 'Non-calculator hasError must keep generic pek_has_error.' );
}

$parser = new PekQuoteResponseParser();
$meta = array( 'endpoint' => '/calculator/calculateprice/', 'method' => 'POST', 'http_status' => 200 );
foreach ( array(
	'pek_quote_ltl_transfer_missing' => array( 'hasError' => false, 'currencyCode' => '643', 'transfers' => array( array( 'type' => 1, 'hasError' => false ) ) ),
	'pek_quote_ltl_transfer_duplicate' => array( 'hasError' => false, 'currencyCode' => '643', 'transfers' => array( array( 'type' => 3, 'hasError' => false, 'costTotal' => 1, 'estDeliveryTime' => 1 ), array( 'type' => 3, 'hasError' => false, 'costTotal' => 2, 'estDeliveryTime' => 2 ) ) ),
	'pek_quote_currency_mismatch' => array( 'hasError' => false, 'currencyCode' => '840', 'transfers' => array() ),
	'pek_quote_cost_invalid' => array( 'hasError' => false, 'currencyCode' => '643', 'transfers' => array( array( 'type' => 3, 'hasError' => false, 'costTotal' => -1, 'estDeliveryTime' => 1 ) ) ),
	'pek_quote_delivery_time_invalid' => array( 'hasError' => false, 'currencyCode' => '643', 'transfers' => array( array( 'type' => 3, 'hasError' => false, 'costTotal' => 1, 'estDeliveryTime' => 1.5 ) ) ),
	'pek_quote_ltl_transfer_error' => array( 'hasError' => false, 'currencyCode' => '643', 'transfers' => array( array( 'type' => 3, 'costTotal' => 1, 'estDeliveryTime' => 1 ) ) ),
	'pek_quote_services_invalid' => array( 'hasError' => false, 'currencyCode' => '643', 'transfers' => array( array( 'type' => 3, 'hasError' => false, 'costTotal' => 1, 'estDeliveryTime' => 1, 'services' => array( array( 'insuranceTerm' => 'false' ) ) ) ) ),
) as $code => $response ) {
	try {
		$parser->parse( $response, PekQuoteOptions::MODE_PICKUP, array(), $meta );
		pek_quote_assert( false, 'Parser must reject calculator response with ' . $code );
	} catch ( PekApiException $exception ) {
		pek_quote_assert( $code === (string) ( $exception->context()['error_code'] ?? '' ), 'Parser must expose stable code ' . $code );
		pek_quote_assert( '/calculator/calculateprice/' === (string) ( $exception->context()['endpoint'] ?? '' ) && 'POST' === (string) ( $exception->context()['method'] ?? '' ) && 200 === (int) ( $exception->context()['http_status'] ?? 0 ), 'Parser failures must preserve calculator response metadata for ' . $code );
	}
}

foreach ( array( 0, 1, 'false', null ) as $bad_has_error ) {
	try {
		$parser->parse( array( 'hasError' => false, 'currencyCode' => '643', 'transfers' => array( array( 'type' => 3, 'hasError' => $bad_has_error, 'costTotal' => 1, 'estDeliveryTime' => 1 ) ) ), PekQuoteOptions::MODE_PICKUP, array(), $meta );
		pek_quote_assert( false, 'Parser must reject non-bool transfer hasError.' );
	} catch ( PekApiException $exception ) {
		pek_quote_assert( 'pek_quote_ltl_transfer_error' === (string) ( $exception->context()['error_code'] ?? '' ), 'Non-bool transfer hasError must fail with stable LTL transfer code.' );
	}
}
try {
	$parser->parse( array( 'hasError' => false, 'currencyCode' => '643', 'transfers' => array( array( 'type' => 3, 'hasError' => true, 'errorMessage' => 'bad transfer', 'costTotal' => 0, 'estDeliveryTime' => 0 ) ) ), PekQuoteOptions::MODE_PICKUP, array(), $meta );
	pek_quote_assert( false, 'Parser must fail logical transfer hasError=true.' );
} catch ( PekApiException $exception ) {
	pek_quote_assert( 'pek_quote_ltl_transfer_error' === (string) ( $exception->context()['error_code'] ?? '' ) && 'quote_calculator_logical' === (string) ( $exception->context()['failure_stage'] ?? '' ), 'Transfer hasError=true must remain a logical LTL failure.' );
}

$cost_cases = array( '0' => 0, '0.01' => 1, '1.005' => 100, '1234.56' => 123456 );
foreach ( $cost_cases as $cost => $kopecks ) {
	$parsed = $parser->parse( array( 'hasError' => false, 'currencyCode' => '643', 'transfers' => array( array( 'type' => 3, 'hasError' => false, 'costTotal' => $cost, 'estDeliveryTime' => 0, 'services' => array() ) ) ), PekQuoteOptions::MODE_PICKUP, array(), $meta );
	pek_quote_assert( $kopecks === $parsed->price_kopecks, 'PEK quote cost conversion must keep documented PHP rounding behavior for ' . $cost );
}

foreach ( array( 0, 1, 'true', 'false', array(), (object) array() ) as $bad_insurance_term ) {
	try {
		$parser->parse( array( 'hasError' => false, 'currencyCode' => '643', 'transfers' => array( array( 'type' => 3, 'hasError' => false, 'costTotal' => 1, 'estDeliveryTime' => 1, 'services' => array( array( 'insuranceTerm' => $bad_insurance_term ) ) ) ) ), PekQuoteOptions::MODE_PICKUP, array(), $meta );
		pek_quote_assert( false, 'Parser must reject non-bool insuranceTerm.' );
	} catch ( PekApiException $exception ) {
		pek_quote_assert( 'pek_quote_services_invalid' === (string) ( $exception->context()['error_code'] ?? '' ), 'Non-bool insuranceTerm must fail services contract.' );
	}
}

foreach ( array( 'serviceType' => true, 'serviceType_int' => 1, 'senderCity' => 123, 'info' => false, 'info_array' => array() ) as $case => $bad_text ) {
	$key = str_starts_with( (string) $case, 'serviceType' ) ? 'serviceType' : ( str_starts_with( (string) $case, 'senderCity' ) ? 'senderCity' : 'info' );
	try {
		$parser->parse( array( 'hasError' => false, 'currencyCode' => '643', 'transfers' => array( array( 'type' => 3, 'hasError' => false, 'costTotal' => 1, 'estDeliveryTime' => 1, 'services' => array( array( $key => $bad_text ) ) ) ) ), PekQuoteOptions::MODE_PICKUP, array(), $meta );
		pek_quote_assert( false, 'Parser must reject non-string service text fields.' );
	} catch ( PekApiException $exception ) {
		pek_quote_assert( 'pek_quote_services_invalid' === (string) ( $exception->context()['error_code'] ?? '' ), 'Non-string service text fields must fail service contract.' );
	}
}
$empty_service = $parser->parse( array( 'hasError' => false, 'currencyCode' => '643', 'transfers' => array( array( 'type' => 3, 'hasError' => false, 'costTotal' => 1, 'estDeliveryTime' => 1, 'services' => array( array( 'serviceType' => '', 'senderCity' => null, 'info' => 'Условие', 'insuranceTerm' => false ) ) ) ) ), PekQuoteOptions::MODE_PICKUP, array(), $meta );
pek_quote_assert( ! array_key_exists( 'serviceType', $empty_service->services[0] ) && ! array_key_exists( 'senderCity', $empty_service->services[0] ) && 'Условие' === $empty_service->services[0]['info'] && false === $empty_service->services[0]['insuranceTerm'], 'Empty service text is omitted while valid info and Boolean insuranceTerm are preserved.' );

$sensitive = array( 'login' => 'diagnostic-user', 'api_key' => 'very-secret-key', 'client_card' => 'CLIENT-SECRET-777', 'inn' => '1234567890', 'kpp' => '123456789' );
$basic = base64_encode( 'diagnostic-user:very-secret-key' );
$secret_message = 'login=diagnostic-user api_key=very-secret-key diagnostic-user:very-secret-key Basic ' . $basic . ' counterpartClientCard=CLIENT-SECRET-777 inn=1234567890 kpp=123456789 token=very-secret-key Authorization: Basic abc ?token=very-secret-key &login=diagnostic-user Карта CLIENT-SECRET-777 ИНН 1234567890 Пользователь diagnostic-user';
list( $settings_secret, $http_secret, $builder_secret, $service_secret ) = pek_quote_boot( array( pek_quote_response( array( 'hasError' => true, 'errorMessage' => $secret_message ) ) ), $sensitive, true );
$secret_result = $service_secret->calculate( pek_quote_request(), $pickup );
$secret_json = wp_json_encode( $secret_result->to_array() );
foreach ( array( 'diagnostic-user', 'very-secret-key', 'CLIENT-SECRET-777', '1234567890', '123456789', $basic, 'diagnostic-user:very-secret-key' ) as $secret ) {
	pek_quote_assert( ! str_contains( (string) $secret_json, $secret ) && ! str_contains( $secret_result->api_error_message, $secret ), 'Root calculator error sanitization must remove actual sensitive value ' . $secret );
}
pek_quote_assert( 'ПЭК вернул ошибку без безопасного описания.' !== $secret_result->api_error_message && str_contains( $secret_result->api_error_message, '[redacted]' ), 'Root calculator error sanitization must keep a readable redacted message.' );
$store = new PekQuoteDiagnosticStore();
$store->save_for_current_user( $secret_result->to_array() );
$stored_secret = $store->consume_for_current_user();
$stored_secret_json = wp_json_encode( $stored_secret );
foreach ( array( 'diagnostic-user', 'very-secret-key', 'CLIENT-SECRET-777', '1234567890', '123456789', $basic ) as $secret ) {
	pek_quote_assert( ! str_contains( (string) $stored_secret_json, $secret ), 'Quote diagnostic transient must not contain sensitive value ' . $secret );
}
$log_json = wp_json_encode( $GLOBALS['pek_quote_wc_logger']->entries );
pek_quote_assert( 1 === count( $GLOBALS['pek_quote_wc_logger']->entries ) && str_contains( (string) $log_json, 'field_error_count' ) && ! str_contains( (string) $log_json, 'api_error_message' ) && ! str_contains( (string) $log_json, 'field_errors' ) && ! str_contains( (string) $log_json, 'very-secret-key' ) && ! str_contains( (string) $log_json, 'CLIENT-SECRET-777' ), 'Quote failure logger must omit API messages, field messages and sensitive values.' );

list( $settings_transfer, $http_transfer, $builder_transfer, $service_transfer ) = pek_quote_boot( array( pek_quote_response( array( 'hasError' => false, 'currencyCode' => '643', 'transfers' => array( array( 'type' => 3, 'hasError' => true, 'errorMessage' => 'client card CLIENT-SECRET-777, inn 1234567890', 'costTotal' => 0, 'estDeliveryTime' => 0 ) ) ) ) ), $sensitive );
$transfer_secret = $service_transfer->calculate( pek_quote_request(), $pickup );
pek_quote_assert( ! str_contains( $transfer_secret->api_error_message, 'CLIENT-SECRET-777' ) && ! str_contains( $transfer_secret->api_error_message, '1234567890' ) && str_contains( $transfer_secret->api_error_message, '[redacted]' ), 'Transfer calculator error sanitization must remove counterpart identifiers.' );

list( $settings_field, $http_field, $builder_field, $service_field ) = pek_quote_boot( array( pek_quote_response( array( 'error' => array( 'title' => 'Ошибка', 'message' => 'Описание', 'fields' => array( array( 'Key' => 'counterpart.inn', 'Value' => array( 'Недопустимое значение 1234567890', 'client_card=CLIENT-SECRET-777', 'client_card=CLIENT-SECRET-777' ) ) ) ) ) ) ), $sensitive, true );
$field_secret = $service_field->calculate( pek_quote_request(), $pickup );
$field_json = wp_json_encode( $field_secret->to_array() );
pek_quote_assert( 'counterpart.inn' === (string) ( $field_secret->field_errors[0]['field'] ?? '' ) && 2 === count( $field_secret->field_errors[0]['messages'] ?? array() ) && ! str_contains( (string) $field_json, '1234567890' ) && ! str_contains( (string) $field_json, 'CLIENT-SECRET-777' ), 'Quote field error messages must be sanitized and deduplicated after redaction.' );
pek_quote_assert( ! str_contains( (string) wp_json_encode( $GLOBALS['pek_quote_wc_logger']->entries ), 'Недопустимое значение' ) && ! str_contains( (string) wp_json_encode( $GLOBALS['pek_quote_wc_logger']->entries ), 'CLIENT-SECRET-777' ), 'Quote logger must not contain field error messages.' );

$field_secret_values = array( 'diagnostic-user', 'very-secret-key', 'CLIENT-SECRET-777', '1234567890', '123456789', $basic, 'diagnostic-user:very-secret-key' );
list( $settings_field_name, $http_field_name, $builder_field_name, $service_field_name ) = pek_quote_boot( array( pek_quote_response( array( 'error' => array( 'title' => 'Ошибка', 'message' => 'Описание', 'fields' => array(
	array( 'Key' => 'CLIENT-SECRET-777 diagnostic-user very-secret-key 1234567890 123456789', 'Value' => array( 'Ошибка проверки поля.' ) ),
	array( 'Key' => 'counterpart.inn', 'Value' => array( 'Canonical INN path.' ) ),
	array( 'Key' => 'counterpart.kpp', 'Value' => array( 'Canonical KPP path.' ) ),
	array( 'Key' => 'counterpart.counterpartClientCard', 'Value' => array( 'Canonical client card path.' ) ),
	array( 'Key' => 'cargos[0].weight', 'Value' => array( 'Canonical cargo path.' ) ),
	array( 'Key' => 'delivery.address', 'Value' => array( 'Canonical delivery path.' ) ),
	array( 'Key' => 'counterpart.inn=1234567890', 'Value' => array( 'Assignment value redacted.' ) ),
	array( 'Key' => 'counterpart.kpp: 123456789', 'Value' => array( 'Assignment value redacted.' ) ),
	array( 'Key' => 'counterpartClientCard=CLIENT-SECRET-777', 'Value' => array( 'Assignment value redacted.' ) ),
	array( 'Key' => 'login=diagnostic-user', 'Value' => array( 'Assignment value redacted.' ) ),
	array( 'Key' => 'api_key=very-secret-key', 'Value' => array( 'Assignment value redacted.' ) ),
	array( 'Key' => '?token=very-secret-key', 'Value' => array( 'Query token redacted.' ) ),
	array( 'Key' => '&login=diagnostic-user', 'Value' => array( 'Query login redacted.' ) ),
	array( 'Key' => 'Authorization: Basic abcdef', 'Value' => array( 'Authorization redacted.' ) ),
) ) ) ) ), $sensitive, true );
$field_name_result = $service_field_name->calculate( pek_quote_request(), $pickup );
$field_name_json = (string) wp_json_encode( $field_name_result->to_array() );
pek_quote_assert_no_secrets( $field_name_json, $field_secret_values, 'Quote result field names must remove configured sensitive values' );
$fields = array_column( $field_name_result->field_errors, 'field' );
foreach ( array( 'counterpart.inn', 'counterpart.kpp', 'counterpart.counterpartClientCard', 'cargos[0].weight', 'delivery.address' ) as $canonical_field ) {
	pek_quote_assert( in_array( $canonical_field, $fields, true ), 'Canonical PEK field path must survive field-name sanitization: ' . $canonical_field );
}
pek_quote_assert( 'unknown_field' === (string) ( $field_name_result->field_errors[0]['field'] ?? '' ), 'Field names made only of configured secrets must collapse to unknown_field.' );
foreach ( $field_name_result->field_errors as $item ) {
	pek_quote_assert( is_string( $item['field'] ) && '' !== $item['field'] && ( function_exists( 'mb_strlen' ) ? mb_strlen( $item['field'] ) : strlen( $item['field'] ) ) <= 100, 'Sanitized quote field names must be non-empty and limited to 100 characters.' );
}
$store_field = new PekQuoteDiagnosticStore();
$store_field->save_for_current_user( array_merge( $field_name_result->to_array(), array( 'field_errors' => array_merge( $field_name_result->field_errors, array( array( 'field' => 'counterpart.inn', 'messages' => array( 'Ошибка' ), 'raw_field' => '1234567890', 'original_field' => 'CLIENT-SECRET-777', 'rejectedValue' => 'very-secret-key', 'metadata' => array( 'authorization' => 'Basic secret' ) ) ) ) ) ) );
$stored_field = $store_field->consume_for_current_user();
$stored_field_json = (string) wp_json_encode( $stored_field );
pek_quote_assert_no_secrets( $stored_field_json, $field_secret_values, 'Quote diagnostic store must not persist sensitive field-name values' );
foreach ( array( 'raw_field', 'original_field', 'rejectedValue', 'metadata' ) as $unsafe_key ) {
	pek_quote_assert( ! str_contains( $stored_field_json, $unsafe_key ), 'Quote diagnostic store must discard unsafe field-error key ' . $unsafe_key );
}
$field_name_log_json = (string) wp_json_encode( $GLOBALS['pek_quote_wc_logger']->entries );
pek_quote_assert_no_secrets( $field_name_log_json, $field_secret_values, 'Quote logger field_error_fields must not contain sensitive values' );
pek_quote_assert( str_contains( $field_name_log_json, 'field_error_fields' ) && ! str_contains( $field_name_log_json, 'Ошибка проверки поля.' ), 'Quote logger must contain only sanitized field names, not field messages.' );

list( $settings_field_only, $http_field_only, $builder_field_only, $service_field_only ) = pek_quote_boot( array( pek_quote_response( array( 'error' => array( 'title' => 'Ошибка', 'message' => 'Описание', 'fields' => array(
	array( 'Key' => 'CLIENT-SECRET-777', 'Value' => array( 'Ошибка A', 'Ошибка A' ) ),
	array( 'Key' => '1234567890', 'Value' => array( 'Ошибка B' ) ),
	array( 'Key' => 'diagnostic-user:very-secret-key', 'Value' => array( 'Ошибка C' ) ),
	array( 'Key' => 'Basic ' . $basic, 'Value' => array( 'Ошибка D' ) ),
) ) ) ) ), $sensitive );
$secret_only_fields = $service_field_only->calculate( pek_quote_request(), $pickup );
pek_quote_assert( 1 === count( $secret_only_fields->field_errors ) && 'unknown_field' === $secret_only_fields->field_errors[0]['field'], 'Duplicate secret-only field names must merge into one unknown_field item.' );
pek_quote_assert( array( 'Ошибка A', 'Ошибка B', 'Ошибка C', 'Ошибка D' ) === $secret_only_fields->field_errors[0]['messages'], 'Merged unknown_field item must preserve first message order and deduplicate messages.' );

list( $settings_mixed_field, $http_mixed_field, $builder_mixed_field, $service_mixed_field ) = pek_quote_boot( array( pek_quote_response( array( 'error' => array( 'title' => 'Ошибка', 'message' => 'Описание', 'fields' => array( array( 'Key' => "counterpart.inn\r\n\t rejected value 1234567890 <script>" . str_repeat( 'ж', 220 ) . ' CLIENT-SECRET-777', 'Value' => array( 'Ошибка' ) ) ) ) ) ) ), $sensitive );
$mixed_field = $service_mixed_field->calculate( pek_quote_request(), $pickup );
$mixed_name = (string) ( $mixed_field->field_errors[0]['field'] ?? '' );
pek_quote_assert( ! str_contains( $mixed_name, '1234567890' ) && ! str_contains( $mixed_name, 'CLIENT-SECRET-777' ) && ! str_contains( $mixed_name, "\r" ) && ! str_contains( $mixed_name, "\n" ) && ! str_contains( $mixed_name, "\t" ), 'Mixed safe field names must redact secrets before truncation and remove controls.' );
pek_quote_assert( ( function_exists( 'mb_strlen' ) ? mb_strlen( $mixed_name ) : strlen( $mixed_name ) ) <= 100, 'Mixed safe field names must be truncated to 100 Unicode characters.' );

list( $settings_empty, $http_empty, $builder_empty, $service_empty ) = pek_quote_boot( array( pek_quote_response( array( 'hasError' => true, 'errorMessage' => 'very-secret-key CLIENT-SECRET-777 1234567890 123456789 diagnostic-user' ) ) ), $sensitive );
$empty_secret = $service_empty->calculate( pek_quote_request(), $pickup );
pek_quote_assert( 'ПЭК вернул ошибку без безопасного описания.' === $empty_secret->api_error_message, 'Message reduced to secrets only must use generic fallback.' );

list( $settings4, $http4, $builder4, $service4 ) = pek_quote_boot( array( pek_quote_response( array( 'error' => array( 'title' => 'Ошибка валидации', 'message' => 'Детали отдельно', 'fields' => array( array( 'Key' => 'volume', 'Value' => array( 'Значение должно быть больше 0.' ) ) ) ) ) ) ) );
$logical = $service4->calculate( pek_quote_request(), $pickup );
pek_quote_assert( ! $logical->success && 'pek_logical_error' === $logical->error_code && 'quote_calculator_logical' === $logical->failure_stage && 'volume' === $logical->field_errors[0]['field'], 'PEK calculator logical errors must preserve safe field errors and quote failure stage.' );

echo "PEK quote foundation smoke passed.\n";
