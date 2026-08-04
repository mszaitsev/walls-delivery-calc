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
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteCargoBuilder;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteOptions;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteRequestBuilder;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteResponseParser;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteService;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

function pek_quote_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
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
			array( 'type' => 3, 'hasError' => false, 'costTotal' => 1234.56, 'estDeliveryTime' => 3, 'services' => array( array( 'serviceType' => 'Перевозка', 'cost' => 1000 ), array( 'serviceType' => 'Страхование', 'cost' => 234.56, 'services' => array() ) ) ),
		),
		'commonTerms' => 'safe',
	);
}

function pek_quote_boot( array $responses ): array {
	$GLOBALS['pek_quote_options'] = array();
	$GLOBALS['pek_quote_transients'] = array();
	$repository = new SettingsRepository();
	$settings = new PekSettings( $repository );
	$credentials = new PekCredentials( $repository, new EncryptionService() );
	if ( ! defined( 'APP_ENCRYPTION_KEY' ) ) {
		define( 'APP_ENCRYPTION_KEY', 'pek-quote-test-key' );
	}
	$credentials->save_from_admin( array( PekSettings::LOGIN_KEY => 'login', 'pek_api_key' => 'secret' ) );
	$settings->save_from_admin( array( PekSettings::SENDER_INN_KEY => '5400000000', PekSettings::SENDER_KPP_KEY => '540001001', PekSettings::CLIENT_CARD_KEY => 'client-card' ) );
	$repository->set( PekSettings::SENDER_WAREHOUSE_KEY, array( 'warehouseId' => 'sender-wh', 'source' => 'free', 'branchTimezone' => 'UTC' ) );
	$http = new PekQuoteFakeHttp( $responses );
	$api = new PekApiClient( $settings, $credentials, $http, new PekRequestBudget( $settings ) );
	$builder = new PekQuoteRequestBuilder( $settings, new PekQuoteCargoBuilder() );
	$service = new PekQuoteService( $credentials, $api, $builder, new PekQuoteResponseParser() );

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
pek_quote_assert( $result->success && 123456 === $result->price_kopecks && 3 === $result->delivery_days, 'PEK quote service must parse successful type=3 calculator response.' );
pek_quote_assert( 1 === count( $http->requests ) && 'POST' === $http->requests[0]['method'] && str_ends_with( $http->requests[0]['url'], '/calculator/calculateprice/' ), 'PEK quote must call POST /calculator/calculateprice/ exactly once.' );
$payload = json_decode( (string) $http->requests[0]['args']['body'], true );
pek_quote_assert( is_array( $payload ) && '643' === $payload['currencyCode'] && array( 3 ) === $payload['types'], 'PEK quote payload must use currencyCode=643 and types=[3].' );
pek_quote_assert( 'sender-wh' === $payload['senderWarehouseId'] && 'receiver-wh' === $payload['receiverWarehouseId'] && false === $payload['isPickUp'] && false === $payload['isDelivery'], 'Pickup quote payload must use configured sender warehouse and receiver warehouse without pickup/delivery services.' );
pek_quote_assert( ! array_key_exists( 'pickup', $payload ) && ! array_key_exists( 'delivery', $payload ) && ! array_key_exists( 'transportingTypes', $payload ) && ! array_key_exists( 'senderCityId', $payload ) && ! array_key_exists( 'receiverCityId', $payload ) && ! array_key_exists( 'overSize', $payload ), 'PEK quote payload must avoid pickup/delivery blocks and deprecated calculator fields in pickup mode.' );
pek_quote_assert( true === $payload['isInsurance'] && 1000.5 === $payload['isInsurancePrice'], 'PEK quote payload must use Package declared_value as mandatory insurance price with kopecks preserved.' );
pek_quote_assert( '5400000000' === $payload['counterpart']['inn'] && '540001001' === $payload['counterpart']['kpp'] && 'client-card' === $payload['counterpart']['counterpartClientCard'] && array( 1, 3 ) === $payload['counterpart']['whoMakesCalculation'], 'PEK quote payload must include counterpart and client card contract fields.' );
pek_quote_assert( 1.01 === $payload['cargos'][0]['weight'] && 1.01 === $payload['cargos'][0]['maxPlaceWeight'] && 0.1 === $payload['cargos'][0]['length'] && false === $payload['cargos'][0]['isHP'], 'PEK cargo builder must use one aggregate place with upward weight/dimension rounding.' );

list( $settings2, $http2, $builder2, $service2 ) = pek_quote_boot( array( pek_quote_response( pek_quote_success_response() ) ) );
$courier = new PekQuoteOptions( PekQuoteOptions::MODE_COURIER, '2026-08-04T13:15:00', '', 'Россия, Москва, улица Большая Лубянка, 2', 55.754058, 37.62049 );
$service2->calculate( pek_quote_volume_request(), $courier );
$courier_payload = json_decode( (string) $http2->requests[0]['args']['body'], true );
pek_quote_assert( ! array_key_exists( 'receiverWarehouseId', $courier_payload ) && true === $courier_payload['isDelivery'] && false === $courier_payload['isPickUp'], 'Courier quote payload must not use receiverWarehouseId and must enable delivery.' );
pek_quote_assert( 'Россия, Москва, улица Большая Лубянка, 2' === $courier_payload['delivery']['address'] && '55.754058' === $courier_payload['delivery']['coordinates']['latitude'] && '37.62049' === $courier_payload['delivery']['coordinates']['longitude'], 'Courier quote payload must include delivery address and decimal-string coordinates.' );
pek_quote_assert( 0.01 === $courier_payload['cargos'][0]['weight'] && 0.01 === $courier_payload['cargos'][0]['volume'] && 0.25 === $courier_payload['cargos'][0]['maxSize'], 'PEK volume/maxSize path must round upward to hundredths.' );

list( $settings3, $http3, $builder3, $service3 ) = pek_quote_boot( array() );
$bad_country = new QuoteRequest( 'KZ', new Address( country_code: 'KZ', city: 'Алматы', raw_address: 'Алматы' ), pek_quote_request()->package, '', Money::from_rubles( 1 ), '2026-08-04' );
$country_result = $service3->calculate( $bad_country, $pickup );
pek_quote_assert( ! $country_result->success && 'pek_quote_country_not_supported' === $country_result->error_code && array() === $http3->requests, 'Non-RU PEK quote must fail closed before API call.' );

$parser = new PekQuoteResponseParser();
foreach ( array(
	'pek_quote_ltl_transfer_missing' => array( 'hasError' => false, 'currencyCode' => '643', 'transfers' => array( array( 'type' => 1, 'hasError' => false ) ) ),
	'pek_quote_ltl_transfer_duplicate' => array( 'hasError' => false, 'currencyCode' => '643', 'transfers' => array( array( 'type' => 3, 'hasError' => false, 'costTotal' => 1, 'estDeliveryTime' => 1 ), array( 'type' => 3, 'hasError' => false, 'costTotal' => 2, 'estDeliveryTime' => 2 ) ) ),
	'pek_quote_currency_mismatch' => array( 'hasError' => false, 'currencyCode' => '840', 'transfers' => array() ),
	'pek_quote_cost_invalid' => array( 'hasError' => false, 'currencyCode' => '643', 'transfers' => array( array( 'type' => 3, 'hasError' => false, 'costTotal' => -1, 'estDeliveryTime' => 1 ) ) ),
	'pek_quote_delivery_time_invalid' => array( 'hasError' => false, 'currencyCode' => '643', 'transfers' => array( array( 'type' => 3, 'hasError' => false, 'costTotal' => 1, 'estDeliveryTime' => 1.5 ) ) ),
) as $code => $response ) {
	try {
		$parser->parse( $response, PekQuoteOptions::MODE_PICKUP, array() );
		pek_quote_assert( false, 'Parser must reject calculator response with ' . $code );
	} catch ( PekApiException $exception ) {
		pek_quote_assert( $code === (string) ( $exception->context()['error_code'] ?? '' ), 'Parser must expose stable code ' . $code );
	}
}

list( $settings4, $http4, $builder4, $service4 ) = pek_quote_boot( array( pek_quote_response( array( 'error' => array( 'title' => 'Ошибка валидации', 'message' => 'Детали отдельно', 'fields' => array( array( 'Key' => 'volume', 'Value' => array( 'Значение должно быть больше 0.' ) ) ) ) ) ) ) );
$logical = $service4->calculate( pek_quote_request(), $pickup );
pek_quote_assert( ! $logical->success && 'pek_logical_error' === $logical->error_code && 'quote_calculator_logical' === $logical->failure_stage && 'volume' === $logical->field_errors[0]['field'], 'PEK calculator logical errors must preserve safe field errors and quote failure stage.' );

echo "PEK quote foundation smoke passed.\n";
