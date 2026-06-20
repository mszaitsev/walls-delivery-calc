<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdCredentials;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSoapClientInterface;
use WallsShop\WDC\Carriers\Dpd\DpdSoapRequest;
use WallsShop\WDC\Carriers\Dpd\DpdSoapResponse;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Dpd\DpdEventNormalizer;
use WallsShop\WDC\Shipments\Dpd\DpdEventSyncService;

function dpd_event_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_event_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_dpd_event_options'][ $key ] = $value; return true; }
function add_option( string $key, mixed $value, string $deprecated = '', string $autoload = 'yes' ): bool { if ( isset( $GLOBALS['wdc_dpd_event_options'][ $key ] ) ) { return false; } $GLOBALS['wdc_dpd_event_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_dpd_event_options'][ $key ] ); return true; }
function wp_salt( string $scheme = '' ): string { return 'event-sync'; }
function sanitize_key( mixed $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ) ?? ''; }
function wp_unslash( mixed $value ): mixed { return $value; }
function sanitize_text_field( mixed $value ): string { return trim( (string) $value ); }
function wp_timezone(): DateTimeZone { return new DateTimeZone( 'Asia/Novosibirsk' ); }

final class DpdEventFakeSoap implements DpdSoapClientInterface { public array $calls = array(); public function call( string $service, string $method, array $payload, DpdCredentials $credentials, array $options = array() ): DpdSoapResponse { $this->calls[] = compact( 'service', 'method', 'payload', 'options' ); $body = 'getEvents' === $method ? array( 'docId' => 10, 'resultComplete' => true, 'event' => array() ) : 'OK'; return new DpdSoapResponse( true, $body, array() ); } public function is_available(): bool { return true; } }
$settings = new DpdSettings( new SettingsRepository(), new EncryptionService() );
$settings->save_from_admin( array( DpdSettings::ENVIRONMENT_KEY => DpdSettings::ENV_TEST, DpdSettings::TEST_CLIENT_NUMBER_KEY => '123', 'dpd_test_client_key' => 'secret' ) );
$settings->save_event_settings_from_admin( array( DpdSettings::EVENTS_CONFIRM_ENABLED_KEY => '1', DpdSettings::EVENTS_LOOKBACK_DAYS_KEY => '2' ) );
dpd_event_assert( 2 === $settings->events_lookback_days() && $settings->events_confirm_enabled(), 'DPD event settings must save days and confirm flag.' );
$soap = new DpdEventFakeSoap();
$client = new DpdApiClient( $settings, $soap );
$client->getEvents( array( 'dateFromSpecified' => true, 'dateToSpecified' => true, 'maxRowCountSpecified' => true ) );
dpd_event_assert( 'event-tracking' === $soap->calls[0]['service'] && 'getEvents' === $soap->calls[0]['method'] && DpdSoapRequest::WRAPPER_REQUEST === $soap->calls[0]['options']['wrapper'], 'getEvents must use event-tracking/request wrapper.' );
dpd_event_assert( 500 === (int) $soap->calls[0]['payload']['maxRowCount'] && ! isset( $soap->calls[0]['payload']['maxRowCountSpecified'] ), 'getEvents must force maxRowCount=500 and omit *Specified flags.' );
$client->confirmEvents( array( 'docId' => 10 ) );
dpd_event_assert( 'confirm' === $soap->calls[1]['method'] && DpdSoapRequest::WRAPPER_REQUEST === $soap->calls[1]['options']['wrapper'], 'confirm must use event-tracking/request wrapper with docId.' );
$normalizer = new DpdEventNormalizer();
$events = $normalizer->normalize_many( array( array( 'dpdOrderNr' => 'DPD1', 'eventNumber' => '1401', 'eventCode' => 'OrderCreate', 'eventName' => 'Заказ создан', 'eventDate' => '2026-06-20T10:00:00+07:00' ), array( 'dpdOrderNr' => 'DPD1', 'eventNumber' => '2201', 'eventCode' => 'OrderReady', 'eventName' => 'Готов', 'eventDate' => '2026-06-20T11:00:00+07:00' ) ) );
$latest = $normalizer->latest_by_order( $events );
dpd_event_assert( '2201' === $latest['dpd:DPD1']['eventNumber'], 'DPD normalizer must choose latest eventDate per dpdOrderNr.' );
$source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Dpd/DpdEventSyncService.php' );
dpd_event_assert( str_contains( $source, 'add_option' ) && str_contains( $source, 'MAX_PACKAGES = 20' ) && str_contains( $source, 'resultComplete' ) && str_contains( $source, 'confirmEvents' ), 'DpdEventSyncService must include atomic lock, batch limit, resultComplete and confirm loop.' );
echo "DPD event sync smoke passed
";
