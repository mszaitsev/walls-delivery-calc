<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Pek\PekCountryPolicy;
use WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteCargoBuilder;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteOptions;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteRequestBuilder;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Pek\PekShipmentAdapter;
use WallsShop\WDC\Shipments\Pek\PekShipmentButtonPolicy;
use WallsShop\WDC\Shipments\Pek\PekStatusMapping;

function pek_int_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' ); }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['pek_int_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool $autoload = true ): bool { $GLOBALS['pek_int_options'][ $option ] = $value; return true; }
function current_datetime(): DateTimeImmutable { return new DateTimeImmutable( '2026-08-11 00:00:00', new DateTimeZone( 'UTC' ) ); }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'UTC' ); }

$policy = new PekCountryPolicy();
foreach ( array( 'RU', 'AM', 'BY', 'KG', 'KZ' ) as $receiver ) {
	pek_int_assert( $policy->supports_calculation_direction( 'RU', $receiver ), 'PEK must support RU -> ' . $receiver . ' calculation direction.' );
}
foreach ( array( array( 'AM', 'RU' ), array( 'BY', 'RU' ), array( 'KZ', 'RU' ), array( 'AM', 'BY' ), array( 'KZ', 'KG' ), array( 'RU', 'UZ' ), array( '', 'KZ' ) ) as $pair ) {
	pek_int_assert( ! $policy->supports_calculation_direction( $pair[0], $pair[1] ), 'PEK must reject unsupported direction ' . $pair[0] . ' -> ' . $pair[1] . '.' );
}
pek_int_assert( $policy->allows_automatic_shipment_create( 'RU', 'RU' ), 'RU automatic shipment creation must remain allowed.' );
pek_int_assert( ! $policy->allows_automatic_shipment_create( 'RU', 'KZ' ), 'Foreign automatic shipment creation must be disabled.' );
pek_int_assert( $policy->allows_manual_attach( 'KZ' ), 'Foreign PEK manual attach must be allowed.' );

$GLOBALS['pek_int_options']['wdc_core_settings'] = array(
	PekSettings::SENDER_WAREHOUSE_KEY => array( 'warehouseId' => 'sender-ru', 'source' => 'free', 'branchTimezone' => 'UTC' ),
	PekSettings::SENDER_INN_KEY => '7701234567',
	PekSettings::SENDER_KPP_KEY => '770101001',
);
$settings = new PekSettings( new SettingsRepository(), new PekRuPhoneNormalizer() );
$builder = new PekQuoteRequestBuilder( $settings, new PekQuoteCargoBuilder(), $policy );
$request = new QuoteRequest(
	'KZ',
	new Address( country_code: 'KZ', city: 'Алматы', raw_address: 'Казахстан, Алматы' ),
	new Package( array(), Money::from_kopecks( 100000 ), Money::from_kopecks( 100000 ), 1000, 0, 1000, 20, 20, 10, 4000 ),
	'',
	Money::from_kopecks( 100000 ),
	'2026-08-11'
);
$payload = $builder->build( $request, new PekQuoteOptions( PekQuoteOptions::MODE_PICKUP, '2026-08-12T12:00:00', 'receiver-kz' ) );
pek_int_assert( '643' === $payload['currencyCode'], 'International PEK calculator payload must request RUB currency code 643.' );
pek_int_assert( false === $payload['needArrangeTransportationDocuments'], 'International PEK quote must not enable accompanying-documents service.' );
pek_int_assert( 'sender-ru' === $payload['senderWarehouseId'] && 'receiver-kz' === $payload['receiverWarehouseId'], 'International pickup quote must keep sender and receiver warehouse IDs.' );

$foreign_request = new ShipmentCreateRequest(
	1,
	PekSettings::CARRIER_KEY,
	DeliveryType::PICKUP,
	PekSettings::PICKUP_RATE_ID,
	new Address( country_code: 'KZ', raw_address: 'Казахстан, Алматы' ),
	null,
	array(),
	Money::from_kopecks( 0 ),
	true,
	array(),
	array(),
	array( 'sender_country_code' => 'RU', 'receiver_country_code' => 'KZ', 'creation_attempt_id' => 'attempt-1' )
);
$adapter_reflection = new ReflectionClass( PekShipmentAdapter::class );
$adapter = $adapter_reflection->newInstanceWithoutConstructor();
$countries_property = $adapter_reflection->getProperty( 'countries' );
$countries_property->setAccessible( true );
$countries_property->setValue( $adapter, $policy );
$preview = $adapter->build_safe_payload_preview( $foreign_request );
pek_int_assert( '' === $preview['path'] && ! empty( $preview['body']['manual_attach_available'] ), 'Foreign PEK preview must stop before preregistration path and allow manual attach.' );
$create = $adapter->create_for_order( new stdClass(), $foreign_request );
pek_int_assert( ! $create->success && 'pek_international_auto_create_disabled' === $create->error_code, 'Foreign PEK create must fail before preregistration submit.' );

$button_policy = new PekShipmentButtonPolicy( new PekStatusMapping( new SettingsRepository() ), $policy );
$buttons = $button_policy->resolve(
	array(
		'receiver_country_code' => 'KZ',
		'manual_attach' => true,
		'pek_cargo_code' => 'KZ123',
		'pek_cargo_status' => 'Оформлена',
		'universal_status_code' => DeliveryStatus::CREATED_IN_CARRIER,
	)
);
pek_int_assert( ! $buttons['cancel'] && $buttons['update'] && $buttons['remove'], 'Foreign manual PEK shipment must allow status/remove and disallow cancellation mutation.' );

echo "PEK international runtime smoke passed.\n";
