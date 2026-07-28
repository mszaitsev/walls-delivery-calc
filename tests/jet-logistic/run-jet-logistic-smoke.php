<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
define( 'WDC_SECRET_KEY', 'jet-logistic-smoke-key' );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\JetLogistic\Api\JetLogisticApiClient;
use WallsShop\WDC\Carriers\JetLogistic\Api\JetLogisticHttpClientInterface;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticCitiesCsvParser;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticCityNameNormalizer;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyRepository;
use WallsShop\WDC\Carriers\JetLogistic\JetLogisticCredentials;
use WallsShop\WDC\Carriers\JetLogistic\JetLogisticSettings;
use WallsShop\WDC\Carriers\JetLogistic\Quote\JetLogisticQuoteRequestBuilder;
use WallsShop\WDC\Carriers\JetLogistic\Quote\JetLogisticQuoteResponseParser;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusMapper;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusMappingRepository;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusService;
use WallsShop\WDC\Carriers\Runtime\JetLogisticCarrier;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\JetLogistic\JetLogisticShipmentAdapter;
use WallsShop\WDC\Shipments\JetLogistic\JetLogisticShipmentService;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostResolver;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function jet_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function current_time( string $type ): string { return '2026-07-28 12:00:00'; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function get_option( string $option, mixed $default = false ): mixed { return $GLOBALS['wdc_options'][ $option ] ?? $default; }
function update_option( string $option, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_options'][ $option ] = $value; return true; }
function wp_salt( string $scheme = 'auth' ): string { return 'jet-salt-' . $scheme; }
function sanitize_text_field( mixed $value ): string { return trim( preg_replace( '/[\r\n\t]+/', ' ', (string) $value ) ?? (string) $value ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function dbDelta( string $sql ): void { $GLOBALS['wdc_db_delta'][] = $sql; }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		public array $jet_cities = array();
		public array $jet_statuses = array();
		public function get_charset_collate(): string { return 'DEFAULT CHARSET=utf8mb4'; }
		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$query = preg_replace( '/%[dsf]/', is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'", $query, 1 ) ?? $query;
			}
			return $query;
		}
		public function replace( string $table, array $data, array $formats = array() ): bool {
			if ( str_contains( $table, 'wdc_jet_logistic_cities' ) ) {
				$key = (string) $data['source_identity'];
				$this->jet_cities[ $key ] = $data;
				$this->jet_cities[ $key ]['id'] = $this->jet_cities[ $key ]['id'] ?? ++$this->insert_id;
				return true;
			}
			if ( str_contains( $table, 'wdc_jet_logistic_status_mappings' ) ) {
				$key = (string) $data['normalized_external_status'];
				$this->jet_statuses[ $key ] = array_merge( $this->jet_statuses[ $key ] ?? array( 'id' => ++$this->insert_id ), $data );
				return true;
			}
			return true;
		}
		public function update( string $table, array $data, array $where, array $formats = array(), array $where_formats = array() ): bool {
			if ( str_contains( $table, 'wdc_jet_logistic_status_mappings' ) ) {
				foreach ( $this->jet_statuses as $key => $row ) {
					if ( (int) ( $row['id'] ?? 0 ) === (int) ( $where['id'] ?? 0 ) ) {
						$this->jet_statuses[ $key ] = array_merge( $row, $data );
					}
				}
			}
			return true;
		}
		public function query( string $query ): int|bool {
			if ( str_contains( $query, 'UPDATE wp_wdc_jet_logistic_cities SET active = 0' ) ) {
				foreach ( $this->jet_cities as $key => $row ) {
					if ( ! str_contains( $query, "'" . $key . "'" ) ) {
						$this->jet_cities[ $key ]['active'] = 0;
					}
				}
			}
			return 1;
		}
		public function get_row( string $query, string $output = ARRAY_A ): ?array {
			if ( preg_match( "/source_identity = '([^']+)'/", $query, $m ) ) {
				return $this->jet_cities[ $m[1] ] ?? null;
			}
			if ( preg_match( '/location_id = (\d+)/', $query, $m ) ) {
				foreach ( $this->jet_cities as $row ) {
					if ( 1 === (int) ( $row['active'] ?? 1 ) && (int) ( $row['location_id'] ?? 0 ) === (int) $m[1] && 'matched' === (string) ( $row['match_status'] ?? '' ) ) {
						return $row;
					}
				}
			}
			if ( preg_match( "/normalized_external_status = '([^']+)'/", $query, $m ) ) {
				return $this->jet_statuses[ $m[1] ] ?? null;
			}
			return null;
		}
		public function get_results( string $query, string $output = ARRAY_A ): array { return array_values( $this->jet_cities ); }
		public function get_col( string $query ): array {
			if ( str_contains( $query, 'DISTINCT country_code' ) ) {
				return array_values( array_unique( array_map( static fn( array $row ): string => (string) $row['country_code'], array_filter( $this->jet_cities, static fn( array $row ): bool => 1 === (int) ( $row['active'] ?? 1 ) && 'matched' === (string) ( $row['match_status'] ?? '' ) && 'RU' !== (string) ( $row['country_code'] ?? '' ) ) ) ) );
			}
			return array();
		}
	}
}

final class JetFakeHttp implements JetLogisticHttpClientInterface {
	public array $requests = array();
	public function __construct( private array $responses ) {}
	public function post_json( string $url, array $payload, int $timeout ): array {
		$this->requests[] = array( 'url' => $url, 'payload' => $payload, 'timeout' => $timeout );
		return array_shift( $this->responses ) ?? array( 'status' => 200, 'body' => '{"success":true,"result":{}}' );
	}
}
final class JetFakeOrder {
	public array $meta = array();
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? array(); }
	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function save(): void {}
}

$GLOBALS['wdc_options'] = array();
$GLOBALS['wpdb'] = new wpdb();

$normalizer = new JetLogisticCityNameNormalizer();
jet_assert( $normalizer->normalize( ' г. АСТАНА  ' ) === $normalizer->normalize( 'Астана' ), 'Jet city normalizer must trim prefixes, case and spaces.' );
$parser = new JetLogisticCitiesCsvParser( $normalizer );
$parsed = $parser->parse( "city;region;country_code\nАстана;;KZ\nМосква;;RU\n" );
jet_assert( 2 === count( $parsed ) && 'KZ' === $parsed[0]['country_code'] && 'RU' === $parsed[1]['country_code'], 'Jet CSV parser must parse country and rows without region.' );

$settings_repo = new SettingsRepository();
$credentials = new JetLogisticCredentials( $settings_repo, new EncryptionService() );
$credentials->save_access_token( 'secret-token' );
$settings_repo->set( JetLogisticSettings::ORIGIN_SOURCE_IDENTITY_KEY, 'origin' );
$settings = new JetLogisticSettings( $settings_repo );
$geo = new JetLogisticGeographyRepository( $GLOBALS['wpdb'] );
$geo->replace_snapshot(
	array(
		array( 'source_identity' => 'origin', 'source_city' => 'Алматы', 'source_region' => '', 'normalized_city' => 'алматы', 'normalized_region' => '', 'country_code' => 'KZ', 'location_id' => 1, 'match_status' => 'matched', 'match_source' => 'manual', 'active' => 1 ),
		array( 'source_identity' => 'dest', 'source_city' => 'Астана', 'source_region' => '', 'normalized_city' => 'астана', 'normalized_region' => '', 'country_code' => 'KZ', 'location_id' => 10, 'match_status' => 'matched', 'match_source' => 'manual', 'active' => 1 ),
	)
);

$http = new JetFakeHttp(
	array(
		array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'price_zabor' => 999, 'price_terminal' => 1000, 'price_delivery' => 500, 'price_dop' => 100, 'city_to' => 'г. Астана', 'city_terminal_to' => 'Караганда', 'day_from' => 3, 'day_to' => 5, 'valuta' => 'RUB' ) ), JSON_UNESCAPED_UNICODE ) ),
	)
);
$api = new JetLogisticApiClient( $http, $settings );
$carrier = new JetLogisticCarrier( $settings, $api, new JetLogisticQuoteRequestBuilder( $credentials ), new JetLogisticQuoteResponseParser(), $geo, $normalizer );
$package = Package::from_items( array( new PackageItem( 'A', 'Товар', 1, Money::from_rubles( 21000 ), Money::from_rubles( 19500 ), 2000, 100, 50, 40 ) ), 0, Money::from_rubles( 19500 ), Money::from_rubles( 19500 ) );
$quote = $carrier->quote( new QuoteRequest( 'KZ', new Address( country_code: 'KZ', city: 'Астана' ), $package, 'card', Money::from_rubles( 19500 ), '2026-07-28', array( 'location_id' => 10 ) ) );
jet_assert( $quote->success && 2 === count( $quote->rates ) && 1 === count( $http->requests ), 'Jet quote must use one API call and return two rates.' );
jet_assert( 110000 === $quote->rates[0]->price->get_kopecks() && 160000 === $quote->rates[1]->price->get_kopecks(), 'Jet rates must ignore pickup price_zabor and calculate terminal/delivery/dop sums.' );
jet_assert( DeliveryType::PICKUP === $quote->rates[0]->delivery_type && false === $quote->rates[0]->requires_pickup_point, 'Jet pickup rate must not require a concrete pickup point.' );
jet_assert( str_contains( $quote->rates[0]->title, 'Караганда' ) && str_contains( $quote->rates[0]->comments[0] ?? '', 'Караганда' ), 'Jet non-local terminal city must be in pickup title and comment.' );
jet_assert( '[redacted]' === (string) ( $quote->raw_reference['jet_request']['access_token'] ?? '' ) && 'secret-token' === (string) $http->requests[0]['payload']['access_token'], 'Jet token must be sent to API but redacted from diagnostics.' );
jet_assert( 19500 === (int) $http->requests[0]['payload']['cost'] && 0 === (int) $http->requests[0]['payload']['dops']['D_SDOC'], 'Jet cost and D_SDOC must use discounted package goods cost below threshold.' );

$payload = ( new JetLogisticQuoteRequestBuilder( $credentials ) )->build(
	new QuoteRequest( 'KZ', new Address( country_code: 'KZ' ), Package::from_items( array( new PackageItem( 'B', 'Товар', 1, Money::from_rubles( 25000 ), Money::from_rubles( 20000 ), 1000, 10, 10, 10 ) ), 0, Money::from_rubles( 20000 ), Money::from_rubles( 20000 ) ), 'card', Money::from_rubles( 20000 ), '2026-07-28' ),
	array( 'source_city' => 'Алматы' ),
	array( 'source_city' => 'Астана' )
);
jet_assert( 20000 === (int) $payload['cost'] && 1 === (int) $payload['dops']['D_SDOC'] && 'ТЕКСТИЛЬ' === $payload['naimenovanie'], 'Jet D_SDOC threshold and fixed cargo name must be applied.' );

$GLOBALS['wpdb']->jet_statuses = array();
$status_repo = new JetLogisticStatusMappingRepository( $GLOBALS['wpdb'] );
$status_repo->ensure_default_mappings();
$status_http = new JetFakeHttp( array( array( 'status' => 200, 'body' => json_encode( array( 'success' => true, 'result' => array( 'logs' => array( array( 'date' => '2026-07-28 10:00:00', 'message' => 'Неизвестно' ), array( 'date' => '2026-07-27 10:00:00', 'message' => 'Груз выдан' ), array( 'date' => '2026-07-27 10:00:00', 'message' => 'Груз выдан' ) ) ) ), JSON_UNESCAPED_UNICODE ) ) ) );
$status_service = new JetLogisticStatusService( new JetLogisticApiClient( $status_http, $settings ), new JetLogisticStatusMapper( $status_repo ) );
$status = $status_service->update( array( 'tracking_number' => 'JET-1', 'universal_status_code' => DeliveryStatus::IN_TRANSIT ) );
jet_assert( DeliveryStatus::IN_TRANSIT === $status['shipment_patch']['universal_status_code'] && 2 === count( $status['shipment_patch']['status_events'] ), 'Unknown latest Jet status must preserve current universal status and deduplicate compact events.' );

$order = new JetFakeOrder();
$actual_cost_resolver = ( new ReflectionClass( ShipmentActualCostResolver::class ) )->newInstanceWithoutConstructor();
$shipment_service = new JetLogisticShipmentService( new OrderShipmentRepository(), $status_service );
$adapter = new JetLogisticShipmentAdapter( $shipment_service, $actual_cost_resolver );
$attached = $adapter->attach_manual( $order, array( 'tracking_number' => 'JET-777' ) );
$stored = $order->meta[ OrderShipmentRepository::META_KEY ][ JetLogisticSettings::CARRIER_KEY ] ?? array();
jet_assert( ! empty( $attached['success'] ) && 'JET-777' === $stored['tracking_number'] && DeliveryStatus::IN_TRANSIT === $stored['universal_status_code'] && true === $stored['attached_manually'], 'Jet manual attach must store tracking number and initial in_transit status.' );
jet_assert( ! $adapter->create( new \WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest( 1, JetLogisticSettings::CARRIER_KEY, DeliveryType::COURIER, '', new Address(), null, array(), Money::from_rubles( 0 ) ) )->success, 'Jet API shipment creation must be unsupported.' );
$adapter->remove_from_order( $order );
jet_assert( empty( $order->meta[ OrderShipmentRepository::META_KEY ][ JetLogisticSettings::CARRIER_KEY ] ?? array() ), 'Jet local remove must delete only local shipment record.' );

echo "Jet Logistic smoke passed.\n";
