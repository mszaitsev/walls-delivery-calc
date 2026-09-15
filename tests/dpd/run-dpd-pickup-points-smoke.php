<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdCredentials;
use WallsShop\WDC\Carriers\Dpd\DpdEndpoints;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSoapClientInterface;
use WallsShop\WDC\Carriers\Dpd\DpdSoapRequest;
use WallsShop\WDC\Carriers\Dpd\DpdSoapResponse;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointImportService;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointNormalizer;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointRepository;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointScheduleFormatter;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointService;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;

function dpd_pickup_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-17 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'dpd-pickup-smoke-salt-' . $scheme; }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_dpd_pickup_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_dpd_pickup_options'][ $key ] = $value; return true; }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		/** @var array<int,array<string,mixed>> */
		public array $dpd_pickup_points = array();
		/** @var array<int,array<string,mixed>> */
		public array $delivery_codes = array();

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$replacement = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sdf]/', $replacement, $query, 1 ) ?? $query;
			}
			return $query;
		}

		public function esc_like( string $text ): string { return addcslashes( $text, '_%\\' ); }
	}
}

final class DpdPickupFakeSoapClient implements DpdSoapClientInterface {
	/** @var array<int,array<string,mixed>> */
	public array $calls = array();
	public string $parcel_mode = 'default';

	public function call( string $service, string $method, array $payload, DpdCredentials $credentials, array $options = array() ): DpdSoapResponse {
		$this->calls[] = compact( 'service', 'method', 'payload', 'options' );
		if ( 'getParcelShops' === $method ) {
			if ( 'empty' === $this->parcel_mode ) {
				return new DpdSoapResponse( true, (object) array( 'return' => (object) array( 'parcelShop' => array() ) ) );
			}
			if ( 'invalid_only' === $this->parcel_mode ) {
				return new DpdSoapResponse(
					true,
					(object) array(
						'return' => (object) array(
							'parcelShop' => array(
								(object) array( 'parcelShopType' => 'П', 'address' => (object) array( 'cityName' => 'Новосибирск' ) ),
								(object) array( 'brand' => 'DPD' ),
							),
						),
					)
				);
			}
			if ( 'success_single' === $this->parcel_mode ) {
				return new DpdSoapResponse(
					true,
					(object) array(
						'return' => (object) array(
							'parcelShop' => (object) array(
								'code' => 'PS2',
								'parcelShopType' => 'П',
								'address' => (object) array( 'cityId' => 49455627, 'countryCode' => 'RU', 'cityName' => 'Новосибирск', 'street' => 'Новая', 'houseNo' => '5' ),
								'geoCoordinates' => (object) array( 'latitude' => '55.200000', 'longitude' => '82.800000' ),
							),
						),
					)
				);
			}
			return new DpdSoapResponse(
				true,
				(object) array(
					'return' => (object) array(
						'parcelShop' => array(
							(object) array(
								'code' => 'PS1',
								'parcelShopType' => 'П',
								'address' => (object) array( 'cityId' => 49455627, 'countryCode' => 'RU', 'regionCode' => '54', 'regionName' => 'Новосибирская обл.', 'cityCode' => '54000001000', 'cityName' => 'Новосибирск', 'street' => 'Ленина', 'streetAbbr' => 'ул', 'houseNo' => '1' ),
								'geoCoordinates' => (object) array( 'latitude' => '55.030199', 'longitude' => '82.920430' ),
								'schedule' => (object) array( 'operation' => 'SelfDelivery', 'timetable' => (object) array( 'weekDays' => 'пн-пт', 'workTime' => '09:00-18:00' ) ),
							),
							(object) array( 'parcelShopType' => 'П' ),
						),
					),
				)
			);
		}

		return new DpdSoapResponse(
			true,
			(object) array(
				'return' => (object) array(
					'terminal' => (object) array(
						'terminalCode' => 'TERM1',
						'terminalName' => 'Новосибирск',
						'address' => (object) array( 'cityId' => 49455627, 'countryCode' => 'RU', 'regionCode' => '54', 'regionName' => 'Новосибирская обл.', 'cityCode' => '54000001000', 'cityName' => 'Новосибирск', 'street' => 'Складская', 'houseNo' => '2' ),
						'geoCoordinates' => (object) array( 'latitude' => '55.100000', 'longitude' => '82.950000' ),
					),
				),
			)
		);
	}

	public function is_available(): bool { return true; }
}

$GLOBALS['wdc_dpd_pickup_options'] = array();
$GLOBALS['wpdb'] = new wpdb();

$settings = new DpdSettings( new SettingsRepository(), new EncryptionService() );
$settings->save_from_admin(
	array(
		DpdSettings::ENVIRONMENT_KEY => DpdSettings::ENV_TEST,
		DpdSettings::TEST_CLIENT_NUMBER_KEY => '1000000000',
		'dpd_test_client_key' => 'secret',
	)
);
$repository = new DpdPickupPointRepository( $GLOBALS['wpdb'] );
$repository->create_schema_if_needed();

$upsert = $repository->upsert_many(
	array(
		array( 'terminal_code' => 'PS1', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 49455627, 'city_code' => '54000001000', 'city_name' => 'Новосибирск', 'address' => 'ул Ленина, 1', 'name' => 'DPD', 'latitude' => 55.030199, 'longitude' => 82.92043, 'source' => 'getParcelShops', 'is_active' => 1 ),
		array( 'terminal_code' => 'PS1', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 49455627, 'city_name' => 'Новосибирск', 'address' => 'ул Ленина, 2', 'source' => 'getParcelShops', 'is_active' => 1 ),
		array( 'type' => 'parcel_shop', 'source' => 'getParcelShops' ),
	)
);
dpd_pickup_assert( 2 === $upsert['saved'] && 1 === $upsert['skipped_invalid'], 'Repository upsert_many must save valid rows and skip invalid rows.' );
dpd_pickup_assert( 1 === $repository->count_all(), 'Unique terminal_code + type must update existing row instead of duplicating it.' );
dpd_pickup_assert( 'ул Ленина, 2' === (string) ( $repository->find_by_terminal_code( 'PS1' )['address'] ?? '' ), 'find_by_terminal_code must return the updated point.' );
dpd_pickup_assert( 1 === count( $repository->find_by_city_id( 49455627 ) ), 'find_by_city_id must return city points.' );
dpd_pickup_assert( 1 === count( $repository->find_by_city_name( 'Новосиб' ) ), 'find_by_city_name must support partial city search.' );

$normalizer = new DpdPickupPointNormalizer();
$schedule_formatter = new DpdPickupPointScheduleFormatter();
$dpd_json_schedule = wp_json_encode(
	array(
		array( 'operation' => 'Payment', 'timetable' => array( 'weekDays' => 'Пн,Вт,Ср,Чт,Пт,Сб,Вс', 'workTime' => '10:00 - 22:00' ) ),
	),
	JSON_UNESCAPED_UNICODE
);
dpd_pickup_assert( 'Пн–Вс: 10:00–22:00' === $schedule_formatter->format( $dpd_json_schedule ), 'DPD schedule JSON must be formatted for humans.' );
$priority_schedule = array(
	array( 'operation' => 'Payment', 'timetable' => array( 'weekDays' => 'Пн,Вт,Ср,Чт,Пт,Сб,Вс', 'workTime' => '10:00 - 22:00' ) ),
	array( 'operation' => 'SelfDelivery', 'timetable' => array( 'weekDays' => 'Пн,Ср,Пт', 'workTime' => '09:00 - 18:00' ) ),
);
dpd_pickup_assert( 'Пн, Ср, Пт: 09:00–18:00' === $schedule_formatter->format( $priority_schedule ), 'DPD SelfDelivery schedule must have priority over Payment.' );
dpd_pickup_assert( 'Пн–Пт: 09:00–18:00' === $schedule_formatter->format( array( 'operation' => 'SelfDelivery', 'timetable' => array( 'weekDays' => 'пн-пт', 'workTime' => '09:00-18:00' ) ) ), 'DPD weekday range schedule must be formatted cleanly.' );
dpd_pickup_assert( 'ежедневно' === $schedule_formatter->format( 'ежедневно' ), 'DPD plain string schedule must pass through unchanged.' );
$object_result = $normalizer->normalize_response(
	(object) array(
		'return' => (object) array(
			'parcelShop' => (object) array(
				'code' => 'OBJ1',
				'address' => (object) array( 'cityId' => 1, 'cityName' => 'Москва' ),
				'schedule' => json_decode( (string) $dpd_json_schedule ),
			),
		),
	),
	DpdPickupPointNormalizer::SOURCE_PARCEL_SHOPS,
	DpdPickupPointNormalizer::TYPE_PARCEL_SHOP
);
dpd_pickup_assert( 1 === $object_result['fetched_count'] && 1 === count( $object_result['points'] ), 'Normalizer must handle single object parcelShop response.' );
dpd_pickup_assert( 'Пн–Вс: 10:00–22:00' === (string) ( $object_result['points'][0]['schedule'] ?? '' ), 'Normalizer must store readable DPD schedule.' );
$array_result = $normalizer->normalize_response(
	array(
		'return' => array(
			'terminal' => array(
				array( 'terminalCode' => 'ARR1', 'terminalName' => 'Terminal', 'address' => array( 'cityId' => 2, 'cityName' => 'Казань' ) ),
				array( 'terminalName' => 'Broken terminal' ),
			),
		),
	),
	DpdPickupPointNormalizer::SOURCE_TERMINALS_SELF_DELIVERY,
	DpdPickupPointNormalizer::TYPE_TERMINAL_SELF_DELIVERY
);
dpd_pickup_assert( 2 === $array_result['fetched_count'] && 1 === count( $array_result['points'] ) && 1 === $array_result['skipped_invalid'], 'Normalizer must handle arrays and skip rows without terminalCode/code.' );
dpd_pickup_assert( 'Казань' === (string) $array_result['points'][0]['city_name'], 'Normalizer must preserve optional city fields.' );

$soap = new DpdPickupFakeSoapClient();
$api = new DpdApiClient( $settings, $soap );
$api->getParcelShops( array( 'countryCode' => 'RU' ) );
$api->getTerminalsSelfDelivery2();
dpd_pickup_assert( 'getParcelShops' === $soap->calls[0]['method'] && DpdEndpoints::SERVICE_GEOGRAPHY === $soap->calls[0]['service'], 'getParcelShops wrapper must call geography2.' );
dpd_pickup_assert( DpdSoapRequest::WRAPPER_REQUEST === (string) ( $soap->calls[0]['options']['wrapper'] ?? '' ), 'getParcelShops must use request wrapper according to WSDL.' );
dpd_pickup_assert( 'getTerminalsSelfDelivery2' === $soap->calls[1]['method'] && DpdEndpoints::SERVICE_GEOGRAPHY === $soap->calls[1]['service'], 'getTerminalsSelfDelivery2 wrapper must call geography2.' );
dpd_pickup_assert( DpdSoapRequest::WRAPPER_DIRECT === (string) ( $soap->calls[1]['options']['wrapper'] ?? '' ), 'getTerminalsSelfDelivery2 must use direct auth according to WSDL.' );

$GLOBALS['wpdb']->dpd_pickup_points = array();
$importer = new DpdPickupPointImportService( $api, $normalizer, $repository, $settings );
$parcel_report = $importer->import_parcel_shops();
dpd_pickup_assert( 'getParcelShops' === $parcel_report->source && 2 === $parcel_report->fetched_count && 1 === $parcel_report->normalized_count && 1 === $parcel_report->saved_count && 1 === $parcel_report->skipped_invalid, 'Import service must count parcel shops fetched/normalized/saved/skipped.' );
$all_report = $importer->import_all();
dpd_pickup_assert( str_contains( $all_report->source, 'getParcelShops' ) && str_contains( $all_report->source, 'getTerminalsSelfDelivery2' ), 'import_all must combine both DPD pickup sources.' );
dpd_pickup_assert( 2 === $repository->count_all(), 'import_all must save parcel shops and self-delivery terminals.' );
dpd_pickup_assert( 'getTerminalsSelfDelivery2' === (string) ( $repository->find_by_terminal_code( 'TERM1' )['source'] ?? '' ), 'Self-delivery terminal source must be stored.' );
dpd_pickup_assert( 'getTerminalsSelfDelivery2' === (string) ( $settings->last_pickup_import_report()['source'] ?? '' ) || str_contains( (string) ( $settings->last_pickup_import_report()['source'] ?? '' ), 'getTerminalsSelfDelivery2' ), 'Last pickup import report must be stored in DpdSettings.' );

$GLOBALS['wpdb']->dpd_pickup_points = array(
	array( 'id' => 1, 'terminal_code' => 'KEEP_EMPTY', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 49455627, 'city_name' => 'Новосибирск', 'address' => 'old empty', 'source' => 'getParcelShops', 'is_active' => 1 ),
);
$soap->parcel_mode = 'empty';
$empty_report = $importer->import_parcel_shops();
dpd_pickup_assert( 0 === $empty_report->fetched_count && 0 === $empty_report->normalized_count && 0 === $empty_report->saved_count && 0 === $empty_report->marked_inactive, 'Empty DPD pickup response must not save or deactivate rows.' );
dpd_pickup_assert( null !== $repository->find_by_terminal_code( 'KEEP_EMPTY' ), 'Existing active points must remain active when API response is empty.' );
dpd_pickup_assert( str_contains( implode( ' ', $empty_report->errors ), 'DPD pickup import returned no rows. Existing points were left unchanged.' ), 'Empty DPD pickup response must report safe unchanged state.' );

$GLOBALS['wpdb']->dpd_pickup_points = array(
	array( 'id' => 1, 'terminal_code' => 'KEEP_INVALID', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 49455627, 'city_name' => 'Новосибирск', 'address' => 'old invalid', 'source' => 'getParcelShops', 'is_active' => 1 ),
);
$soap->parcel_mode = 'invalid_only';
$invalid_report = $importer->import_parcel_shops();
dpd_pickup_assert( 2 === $invalid_report->fetched_count && 0 === $invalid_report->normalized_count && 0 === $invalid_report->saved_count && 0 === $invalid_report->marked_inactive, 'Unrecognized DPD pickup response must not save or deactivate rows.' );
dpd_pickup_assert( null !== $repository->find_by_terminal_code( 'KEEP_INVALID' ), 'Existing active points must remain active when normalizer produces no valid points.' );
dpd_pickup_assert( str_contains( implode( ' ', $invalid_report->errors ), 'DPD pickup import returned rows, but no valid points were normalized. Existing points were left unchanged.' ), 'Unrecognized DPD pickup response must report safe unchanged state.' );

$GLOBALS['wpdb']->dpd_pickup_points = array(
	array( 'id' => 1, 'terminal_code' => 'STALE', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 49455627, 'city_name' => 'Новосибирск', 'address' => 'old stale', 'source' => 'getParcelShops', 'is_active' => 1 ),
	array( 'id' => 2, 'terminal_code' => 'PS2', 'type' => 'parcel_shop', 'country_code' => 'RU', 'city_id' => 49455627, 'city_name' => 'Новосибирск', 'address' => 'old ps2', 'source' => 'getParcelShops', 'is_active' => 1 ),
);
$soap->parcel_mode = 'success_single';
$success_report = $importer->import_parcel_shops();
dpd_pickup_assert( 1 === $success_report->fetched_count && 1 === $success_report->normalized_count && 1 === $success_report->saved_count && 1 === $success_report->marked_inactive, 'Successful DPD pickup import must upsert valid points and mark missing old points inactive.' );
dpd_pickup_assert( null === $repository->find_by_terminal_code( 'STALE' ) && empty( $GLOBALS['wpdb']->dpd_pickup_points[0]['is_active'] ), 'Old points absent from successful new import must become inactive.' );
dpd_pickup_assert( str_contains( (string) ( $repository->find_by_terminal_code( 'PS2' )['address'] ?? '' ), 'Новая' ), 'Successful DPD pickup import must update existing active points.' );
$soap->parcel_mode = 'default';

$GLOBALS['wpdb']->dpd_pickup_points[] = array( 'id' => 3, 'terminal_code' => 'PS2', 'type' => 'terminal_self_delivery', 'country_code' => 'RU', 'city_id' => 49455627, 'city_name' => 'Новосибирск', 'address' => 'terminal duplicate', 'name' => 'DPD terminal duplicate', 'source' => 'getTerminalsSelfDelivery2', 'raw_json' => '{"diagnostic":true}', 'is_active' => 1 );
$GLOBALS['wpdb']->dpd_pickup_points[] = array( 'id' => 4, 'terminal_code' => 'TERM_ONLY', 'type' => 'terminal_self_delivery', 'country_code' => 'RU', 'city_id' => 49455627, 'city_name' => 'Новосибирск', 'address' => 'terminal only', 'name' => 'DPD terminal only', 'source' => 'getTerminalsSelfDelivery2', 'raw_json' => '{"diagnostic":true}', 'is_active' => 1 );
$GLOBALS['wpdb']->delivery_codes[] = array( 'location_id' => 77, 'dpd_city_id' => '49455627', 'updated_at' => '2026-06-17 12:00:00' );
$service = new DpdPickupPointService( $repository, new LocationDeliveryCodeRepository( $GLOBALS['wpdb'] ) );
$consumer_points = $service->get_points_for_location_id( 77 );
dpd_pickup_assert( 2 === count( $consumer_points ), 'Read-only service must resolve location_id to DPD cityId and return consumer-deduplicated active points.' );
dpd_pickup_assert( 'parcel_shop' === (string) ( $service->get_point_by_terminal_code( 'PS2' )['type'] ?? '' ), 'Read-only service must prefer parcel_shop by terminalCode.' );
dpd_pickup_assert( 'terminal_self_delivery' === (string) ( $service->get_point_by_terminal_code( 'TERM_ONLY' )['type'] ?? '' ), 'Read-only service must return terminal_self_delivery when no parcel_shop exists for terminalCode.' );
dpd_pickup_assert( '' !== (string) ( $repository->find_by_terminal_code( 'PS2' )['raw_json'] ?? '' ), 'Repository/admin storage may keep raw_json for diagnostics.' );

$api_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Dpd/DpdApiClient.php' );
$tariff_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Dpd/Tariff/DpdTariffCalculationService.php' );
$runtime_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Carriers/Runtime/DpdQuoteCarrier.php' );
$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
$shipments_metabox = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );
$dpd_adapter_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Dpd/DpdShipmentAdapter.php' );
dpd_pickup_assert( str_contains( $api_source, 'getServiceCostByParcels2' ), 'DPD API client must keep getServiceCostByParcels2.' );
dpd_pickup_assert( str_contains( $tariff_source, 'getServiceCostByParcels3' ) && str_contains( $tariff_source, 'pickup_terminal_code' ), 'DPD checkout runtime must use Parcels3 with terminalCode-aware pricing.' );
dpd_pickup_assert( str_contains( $plugin_source, 'DpdShipmentAdapter' ) && str_contains( $shipments_metabox, 'data-wdc-preview-shipment' ) && str_contains( $shipments_metabox, 'Подготовить отправление' ) && str_contains( $dpd_adapter_source, 'createOrder2' ), 'DPD shipment metabox must expose preview and manual create only.' );

echo "DPD pickup points smoke test passed.\n";
