<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekHttpClientInterface;
use WallsShop\WDC\Carriers\Pek\Api\PekRequestBudget;
use WallsShop\WDC\Carriers\Pek\Checkout\PekCheckoutQuoteContextResolver;
use WallsShop\WDC\Carriers\Pek\PekCountryPolicy;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekRuPhoneNormalizer;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteCargoBuilder;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteOptions;
use WallsShop\WDC\Carriers\Pek\Quote\PekQuoteRequestBuilder;
use WallsShop\WDC\Carriers\Runtime\PekCarrier;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostService;
use WallsShop\WDC\Shipments\Application\ShipmentCreationAttemptService;
use WallsShop\WDC\Shipments\Pek\PekManualAttachContextResolver;
use WallsShop\WDC\Shipments\Pek\PekShipmentAdapter;
use WallsShop\WDC\Shipments\Pek\PekShipmentButtonPolicy;
use WallsShop\WDC\Shipments\Pek\PekShipmentService;
use WallsShop\WDC\Shipments\Pek\PekShipmentStatusResponseNormalizer;
use WallsShop\WDC\Shipments\Pek\PekShipmentStatusService;
use WallsShop\WDC\Shipments\Pek\PekStatusMapping;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function pek_int_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function pek_int_assert_throws( callable $callback, string $message ): void {
	try {
		$callback();
	} catch ( Throwable ) {
		return;
	}
	throw new RuntimeException( $message );
}

function pek_int_assert_required_policy_dependency( string $class ): void {
	$constructor = ( new ReflectionClass( $class ) )->getConstructor();
	pek_int_assert( null !== $constructor, $class . ' must have constructor DI.' );
	foreach ( $constructor->getParameters() as $parameter ) {
		$type = $parameter->getType();
		if ( $type instanceof ReflectionNamedType && PekCountryPolicy::class === $type->getName() ) {
			pek_int_assert( ! $type->allowsNull(), $class . ' PekCountryPolicy dependency must not be nullable.' );
			pek_int_assert( ! $parameter->isDefaultValueAvailable(), $class . ' PekCountryPolicy dependency must not have a default.' );
			return;
		}
	}
	throw new RuntimeException( $class . ' must require PekCountryPolicy in constructor.' );
}

function pek_int_assert_no_hidden_policy_new( string $relative_path ): void {
	$source = (string) file_get_contents( dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR . $relative_path );
	pek_int_assert( ! str_contains( $source, 'new PekCountryPolicy()' ), $relative_path . ' must not instantiate PekCountryPolicy directly.' );
	pek_int_assert( ! str_contains( $source, '?PekCountryPolicy' ), $relative_path . ' must not accept nullable PekCountryPolicy.' );
	pek_int_assert( ! str_contains( $source, 'PekCountryPolicy $countries = null' ), $relative_path . ' must not default PekCountryPolicy to null.' );
}

function sanitize_key( string $value ): string { return strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', $value ) ?? '' ); }
function wp_json_encode( mixed $value, int $flags = 0, int $depth = 512 ): string|false { return json_encode( $value, $flags, $depth ); }
function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['pek_int_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool $autoload = true ): bool { $GLOBALS['pek_int_options'][ $option ] = $value; return true; }
function current_datetime(): DateTimeImmutable { return new DateTimeImmutable( '2026-08-11 00:00:00', new DateTimeZone( 'UTC' ) ); }
function current_time( string $type = 'mysql', int $gmt = 0 ): string { return '2026-08-11 00:00:00'; }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'UTC' ); }

final class PekIntFakeHttp implements PekHttpClientInterface {
	/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();

	public function request( string $method, string $url, array $args ): array {
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );

		return array( 'status' => 200, 'body' => '[]' );
	}

	public function endpoint_count( string $endpoint ): int {
		$count = 0;
		foreach ( $this->requests as $request ) {
			if ( str_contains( $request['url'], $endpoint ) ) {
				++$count;
			}
		}

		return $count;
	}
}

final class PekIntOrder {
	/** @param array<string,mixed> $meta */
	public function __construct(
		private int $id,
		private string $shipping_country = '',
		private array $meta = array()
	) {
	}

	public function get_id(): int { return $this->id; }
	public function get_shipping_country(): string { return $this->shipping_country; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ''; }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function save(): void {}
}

/** @param array<string,mixed> $overrides */
function pek_int_shipment( array $overrides = array() ): array {
	return array_merge(
		array(
			'carrier_key' => PekSettings::CARRIER_KEY,
			'service_key' => PekSettings::SERVICE_KEY,
			'service_title' => PekSettings::TITLE,
			'order_id' => 10,
			'delivery_type' => DeliveryType::PICKUP,
			'shipment_mode' => DeliveryType::PICKUP,
			'rate_id' => PekSettings::PICKUP_RATE_ID,
			'places' => array(
				array( 'place_number' => 1, 'weight_g' => 1000, 'length_cm' => 20, 'width_cm' => 20, 'height_cm' => 10 ),
			),
			'created_at' => '2026-08-10 00:00:00',
		),
		$overrides
	);
}

function pek_int_manual_context_resolver( PekCountryPolicy $policy, OrderShipmentRepository $repository ): PekManualAttachContextResolver {
	$drafts = ( new ReflectionClass( OrderShipmentDraftFactory::class ) )->newInstanceWithoutConstructor();

	return new PekManualAttachContextResolver( $drafts, $repository, $policy );
}

function pek_int_cancellation_service( PekCountryPolicy $policy, OrderShipmentRepository $repository, PekIntFakeHttp $http ): PekShipmentService {
	$settings = new PekSettings( new SettingsRepository(), new PekRuPhoneNormalizer() );
	$credentials = new PekCredentials( new SettingsRepository(), new EncryptionService() );
	$api = new PekApiClient( $settings, $credentials, $http, new PekRequestBudget( $settings ) );
	$mapping = new PekStatusMapping( new SettingsRepository() );
	$actual_costs = new ShipmentActualCostService( $repository );
	$attempts = new ShipmentCreationAttemptService( $repository );
	$statuses = new PekShipmentStatusService( $api, $mapping, $repository, $actual_costs, new PekShipmentStatusResponseNormalizer(), $attempts );
	$buttons = new PekShipmentButtonPolicy( $mapping, $policy );
	$manual_contexts = pek_int_manual_context_resolver( $policy, $repository );

	return new PekShipmentService( $api, $statuses, $repository, $buttons, $actual_costs, $mapping, $manual_contexts, $policy, $attempts );
}

foreach ( array(
	PekCarrier::class,
	PekCheckoutQuoteContextResolver::class,
	PekQuoteRequestBuilder::class,
	PekShipmentAdapter::class,
	PekShipmentButtonPolicy::class,
	PekManualAttachContextResolver::class,
	PekShipmentService::class,
) as $class ) {
	pek_int_assert_required_policy_dependency( $class );
}
foreach ( array(
	'src/Carriers/Runtime/PekCarrier.php',
	'src/Carriers/Pek/Checkout/PekCheckoutQuoteContextResolver.php',
	'src/Carriers/Pek/Quote/PekQuoteRequestBuilder.php',
	'src/Shipments/Pek/PekShipmentAdapter.php',
	'src/Shipments/Pek/PekShipmentButtonPolicy.php',
	'src/Shipments/Pek/PekManualAttachContextResolver.php',
	'src/Shipments/Pek/PekShipmentService.php',
) as $file ) {
	pek_int_assert_no_hidden_policy_new( $file );
}

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

$repository = new OrderShipmentRepository();
$manual_contexts = pek_int_manual_context_resolver( $policy, $repository );
$kz_context = $manual_contexts->resolve( new PekIntOrder( 10 ), pek_int_shipment( array( 'receiver_country_code' => 'KZ', 'sms_release_requested' => true, 'sms_release_confirmed' => true ) ) );
pek_int_assert( 'KZ' === $kz_context['receiver_country_code'] && false === $kz_context['sms_release_requested'], 'Foreign manual attach must preserve KZ and suppress SMS release.' );
$by_context = $manual_contexts->resolve( new PekIntOrder( 11 ), pek_int_shipment( array( 'receiver_country_code' => 'BY' ) ) );
pek_int_assert( 'BY' === $by_context['receiver_country_code'], 'Foreign manual attach must preserve BY.' );
$ru_legacy_context = $manual_contexts->resolve( new PekIntOrder( 12 ), pek_int_shipment( array( 'calculation_data' => array( 'country_code' => 'RU' ) ) ) );
pek_int_assert( 'RU' === $ru_legacy_context['receiver_country_code'], 'Historical RU manual attach may fallback only with affirmative RU calculation evidence.' );
pek_int_assert_throws( static fn(): array => $manual_contexts->resolve( new PekIntOrder( 13 ), pek_int_shipment() ), 'Manual attach with no country authority must fail closed.' );
pek_int_assert_throws( static fn(): array => $manual_contexts->resolve( new PekIntOrder( 14 ), pek_int_shipment( array( 'receiver_country_code' => 'UZ' ) ) ), 'Manual attach with unsupported country must fail closed.' );

$foreign_http = new PekIntFakeHttp();
$foreign_service = pek_int_cancellation_service( $policy, $repository, $foreign_http );
$foreign_order = new PekIntOrder( 20, '', array( OrderShipmentRepository::META_KEY => array( PekSettings::CARRIER_KEY => pek_int_shipment( array( 'receiver_country_code' => 'KZ', 'pek_cargo_code' => 'KZ123' ) ) ) ) );
$foreign_cancel = $foreign_service->cancel_in_carrier( $foreign_order );
pek_int_assert( false === $foreign_cancel['success'] && str_contains( (string) $foreign_cancel['message'], 'Международные отправления ПЭК отменяются вручную' ), 'Foreign PEK cancellation must fail with manual-cabinet message.' );
pek_int_assert( 0 === $foreign_http->endpoint_count( '/cargos/status/' ) && 0 === $foreign_http->endpoint_count( '/order/cancellation/' ), 'Foreign cancellation must not call PEK status or cancellation endpoints.' );

$unknown_http = new PekIntFakeHttp();
$unknown_service = pek_int_cancellation_service( $policy, $repository, $unknown_http );
$unknown_order = new PekIntOrder( 21, '', array( OrderShipmentRepository::META_KEY => array( PekSettings::CARRIER_KEY => pek_int_shipment( array( 'receiver_country_code' => 'UZ', 'pek_cargo_code' => 'UZ123' ) ) ) ) );
$unknown_cancel = $unknown_service->cancel_in_carrier( $unknown_order );
pek_int_assert( false === $unknown_cancel['success'] && str_contains( (string) $unknown_cancel['message'], 'Не удалось подтвердить страну получателя ПЭК для отмены' ), 'Unsupported/unknown PEK cancellation country must fail closed.' );
pek_int_assert( 0 === $unknown_http->endpoint_count( '/cargos/status/' ) && 0 === $unknown_http->endpoint_count( '/order/cancellation/' ), 'Unknown-country cancellation must not call PEK status or cancellation endpoints.' );

echo "PEK international runtime smoke passed.\n";
