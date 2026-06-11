<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {}
}

use WallsShop\WDC\Carriers\Cdek\Api\CdekApiClient;
use WallsShop\WDC\Carriers\Cdek\Api\CdekApiResponse;
use WallsShop\WDC\Carriers\Cdek\Api\CdekHttpClientInterface;
use WallsShop\WDC\Carriers\Cdek\Api\CdekOAuthTokenService;
use WallsShop\WDC\Carriers\Cdek\CdekLocationResolver;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutValidation;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Checkout\WooCommerce\PickupPointOrderDisplay;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Orders\Application\OrderDeliveryReplacementService;
use WallsShop\WDC\Pickup\Cdek\CdekDeliveryPointService;
use WallsShop\WDC\Pickup\Presentation\PickupPointCardRenderer;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function cdek_pickup_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function current_time( string $type ): string { return '2026-06-10 12:00:00'; }
function wp_salt( string $scheme = '' ): string { return 'cdek-pickup-smoke-' . $scheme; }
function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['wdc_cdek_pickup_options'][ $key ] ?? $default; }
function update_option( string $key, mixed $value, bool|string $autoload = false ): bool { $GLOBALS['wdc_cdek_pickup_options'][ $key ] = $value; return true; }
function delete_option( string $key ): bool { unset( $GLOBALS['wdc_cdek_pickup_options'][ $key ] ); return true; }
function get_transient( string $key ): mixed { return $GLOBALS['wdc_cdek_pickup_transients'][ $key ] ?? false; }
function set_transient( string $key, mixed $value, int $expiration = 0 ): bool { unset( $expiration ); $GLOBALS['wdc_cdek_pickup_transients'][ $key ] = $value; return true; }
function delete_transient( string $key ): bool { unset( $GLOBALS['wdc_cdek_pickup_transients'][ $key ] ); return true; }
function sanitize_key( string $key ): string { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) ) ?? ''; }
function sanitize_text_field( mixed $value ): string { return trim( strip_tags( (string) $value ) ); }
function wp_unslash( mixed $value ): mixed { return $value; }
function __( string $text, string $domain = '' ): string { unset( $domain ); return $text; }
function esc_html__( string $text, string $domain = '' ): string { unset( $domain ); return htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_html( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function esc_attr( mixed $value ): string { return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ); }
function WC(): object {
	static $wc = null;
	if ( null === $wc ) {
		$wc = new class {
			public object $session;

			public function __construct() {
				$this->session = new class {
					/** @var array<string,mixed> */
					public array $data = array();

					public function get( string $key, mixed $default = null ): mixed {
						return $this->data[ $key ] ?? $default;
					}

					public function set( string $key, mixed $value ): void {
						$this->data[ $key ] = $value;
					}
				};
			}
		};
	}

	return $wc;
}
function wc_get_logger(): object {
	return new class {
		/**
		 * @param array<string,mixed> $context
		 */
		public function log( string $level, string $message, array $context = array() ): void {
			$GLOBALS['wdc_cdek_pickup_logs'][] = compact( 'level', 'message', 'context' );
		}
	};
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
	}
}

final class CdekPickupFakeHttpClient implements CdekHttpClientInterface {
	/** @var array<int,array{method:string,url:string,args:array<string,mixed>}> */
	public array $requests = array();

	public function request( string $method, string $url, array $args = array() ): CdekApiResponse {
		$this->requests[] = array( 'method' => $method, 'url' => $url, 'args' => $args );
		if ( str_contains( $url, '/v2/oauth/token' ) ) {
			return new CdekApiResponse( 200, (string) json_encode( array( 'access_token' => 'pickup-token', 'expires_in' => 3600 ) ) );
		}
		if ( str_contains( $url, '/v2/deliverypoints' ) ) {
			return new CdekApiResponse(
				200,
				(string) json_encode(
					array(
						array(
							'code' => 'NSK1',
							'uuid' => 'uuid-nsk-1',
							'name' => 'CDEK Point One',
							'type' => 'PVZ',
							'owner_code' => 'CDEK',
							'nearest_station' => 'Metro',
							'note' => 'No cash',
							'work_time' => 'Mon-Fri 10-20',
							'address_comment' => 'Entrance from yard',
							'secret' => 'must-not-be-kept',
							'location' => array(
								'country_code' => 'RU',
								'region' => 'Novosibirsk region',
								'city' => 'Novosibirsk',
								'city_code' => 270,
								'postal_code' => '630099',
								'address' => 'Lenina 1',
								'address_full' => 'Novosibirsk, Lenina 1',
								'latitude' => 55.0302,
								'longitude' => 82.9204,
							),
						),
						array(
							'code' => 'KEM7',
							'uuid' => 'uuid-kem-7',
							'name' => 'CDEK Postamat',
							'type' => 'POSTAMAT',
							'owner_code' => 'CDEK',
							'nearest_station' => 'Mall',
							'note' => 'Inside the shopping center',
							'description' => '0.000000',
							'work_time' => 'Daily 10-22',
							'address_comment' => 'First floor',
							'location' => array(
								'country_code' => 'RU',
								'region' => 'Kemerovo region',
								'city' => 'Kemerovo',
								'city_code' => 270,
								'postal_code' => '650004',
								'address' => 'Sovetskiy 10',
								'address_full' => 'Kemerovo, Sovetskiy 10',
								'latitude' => 55.3547,
								'longitude' => 86.0873,
							),
						),
					)
				)
			);
		}

		return new CdekApiResponse( 404, (string) json_encode( array( 'message' => 'not found' ) ) );
	}

	public function countDeliveryPointRequests(): int {
		return count(
			array_filter(
				$this->requests,
				static fn( array $request ): bool => str_contains( $request['url'], '/v2/deliverypoints' )
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	public function lastDeliveryPointQuery(): array {
		foreach ( array_reverse( $this->requests ) as $request ) {
			if ( ! str_contains( $request['url'], '/v2/deliverypoints' ) ) {
				continue;
			}
			parse_str( (string) parse_url( $request['url'], PHP_URL_QUERY ), $query );
			return $query;
		}

		return array();
	}
}

final class CdekPickupSmokeOrder {
	/** @var array<string,mixed> */
	public array $meta = array();
	/** @var array<string,mixed> */
	public array $shipping_items = array();
	public string $shipping_country = '';
	public string $shipping_state = '';
	public string $shipping_city = '';
	public string $shipping_postcode = '';
	public string $shipping_address_1 = '';
	public string $shipping_address_2 = 'filled';
	public bool $saved = false;

	public function update_meta_data( string $key, mixed $value ): void { $this->meta[ $key ] = $value; }
	public function get_meta( string $key, bool $single = true ): mixed { return $this->meta[ $key ] ?? ( $single ? '' : array() ); }
	public function set_shipping_country( string $value ): void { $this->shipping_country = $value; }
	public function set_shipping_state( string $value ): void { $this->shipping_state = $value; }
	public function set_shipping_city( string $value ): void { $this->shipping_city = $value; }
	public function set_shipping_postcode( string $value ): void { $this->shipping_postcode = $value; }
	public function set_shipping_address_1( string $value ): void { $this->shipping_address_1 = $value; }
	public function set_shipping_address_2( string $value ): void { $this->shipping_address_2 = $value; }
	public function save(): void { $this->saved = true; }
	public function calculate_totals( bool $and_taxes = true ): void { unset( $and_taxes ); }
	public function add_order_note( string $note, bool $is_customer_note = false, bool $added_by_user = false ): void { unset( $note, $is_customer_note, $added_by_user ); }
}

final class CdekPickupSmokeItem {
	/** @var array<string,mixed> */
	public array $meta = array();
	public string $method_title = '';

	public function add_meta_data( string $key, mixed $value, bool $unique = false ): void { unset( $unique ); $this->meta[ $key ] = $value; }
	public function delete_meta_data( string $key ): void { unset( $this->meta[ $key ] ); }
	public function set_method_title( string $title ): void { $this->method_title = $title; }
}

final class CdekPickupSmokeErrors {
	/** @var array<string,string> */
	public array $errors = array();

	public function add( string $code, string $message ): void {
		$this->errors[ $code ] = $message;
	}
}

$GLOBALS['wdc_cdek_pickup_options'] = array();
$GLOBALS['wdc_cdek_pickup_transients'] = array();
$GLOBALS['wdc_cdek_pickup_logs'] = array();
$GLOBALS['wpdb'] = new wpdb();

$settings_repository = new SettingsRepository();
$settings = new CdekSettings( $settings_repository, new EncryptionService() );
$settings->save_from_admin(
	array(
		CdekSettings::ENVIRONMENT_KEY => CdekSettings::ENV_TEST,
		CdekSettings::TEST_ACCOUNT_KEY => 'pickup-account',
		'cdek_test_secure_password' => 'pickup-secret',
	)
);

$http = new CdekPickupFakeHttpClient();
$tokens = new CdekOAuthTokenService( $settings, $http );
$client = new CdekApiClient( $tokens, $settings, $http );
$service = new CdekDeliveryPointService( $client, $settings, new CdekLocationResolver( $client, new Logger() ), new Logger() );

$points = $service->pointsByCityCode( 270 );
cdek_pickup_assert( 2 === count( $points ), 'CDEK deliverypoints response must normalize all points.' );
$query = $http->lastDeliveryPointQuery();
cdek_pickup_assert( '270' === (string) ( $query['city_code'] ?? '' ), 'CDEK deliverypoints request must include city_code.' );
cdek_pickup_assert( 'RU' === (string) ( $query['country_code'] ?? '' ), 'CDEK deliverypoints request must include country_code=RU.' );
cdek_pickup_assert( 'ALL' === (string) ( $query['type'] ?? '' ), 'CDEK deliverypoints request must include type=ALL by default.' );

$pvz = $points[0];
$postamat = $points[1];
cdek_pickup_assert( 'cdek' === ( $pvz['carrier_key'] ?? '' ) && 'NSK1' === ( $pvz['point_code'] ?? '' ), 'Normalized CDEK point must expose carrier_key and point_code.' );
cdek_pickup_assert( 'PVZ' === ( $pvz['point_type'] ?? '' ) && 'CDEK Point One' === ( $pvz['point_name'] ?? '' ), 'Normalized CDEK point must expose type and name.' );
cdek_pickup_assert( 'Novosibirsk, Lenina 1' === ( $pvz['point_address'] ?? '' ) && '630099' === ( $pvz['point_postcode'] ?? '' ), 'Normalized CDEK point must expose address and postcode.' );
cdek_pickup_assert( 'Novosibirsk' === ( $pvz['city_name'] ?? '' ) && 'Novosibirsk region' === ( $pvz['region_name'] ?? '' ), 'Normalized CDEK point must expose city and region.' );
cdek_pickup_assert( 55.0302 === (float) ( $pvz['latitude'] ?? 0 ) && 82.9204 === (float) ( $pvz['longitude'] ?? 0 ), 'Normalized CDEK point must expose coordinates.' );
cdek_pickup_assert( 'Mon-Fri 10-20' === ( $pvz['work_time'] ?? '' ), 'Normalized CDEK point must expose work_time.' );
cdek_pickup_assert( 'uuid-nsk-1' === ( $pvz['cdek_uuid'] ?? '' ) && 'CDEK' === ( $pvz['cdek_owner_code'] ?? '' ), 'Normalized CDEK point must keep CDEK-specific identifiers.' );
cdek_pickup_assert( ! str_contains( (string) wp_json_encode( $pvz ), 'must-not-be-kept' ), 'Normalized CDEK point raw payload must not keep sensitive-looking fields.' );
cdek_pickup_assert( 'KEM7' === ( $postamat['point_code'] ?? '' ) && 'KEM7' === ( $postamat['cdek_code'] ?? '' ), 'CDEK point_code must use CDEK code, not postcode.' );
cdek_pickup_assert( '650004' === ( $postamat['point_postcode'] ?? '' ) && 'KEM7' !== ( $postamat['point_postcode'] ?? '' ), 'CDEK postcode must stay separate from point_code.' );
cdek_pickup_assert( 'POSTAMAT' === ( $postamat['point_type'] ?? '' ) && 'Срок хранения 3 дня' === ( $postamat['storage_notice'] ?? '' ), 'CDEK POSTAMAT must normalize type and storage notice.' );
cdek_pickup_assert( 'Inside the shopping center' === ( $postamat['description'] ?? '' ), 'CDEK deliverypoints description must be normalized.' );

$card_renderer = new PickupPointCardRenderer();
$pvz_card = $card_renderer->render( array_merge( $pvz, array( 'rate_id' => 'cdek:pickup:136' ) ), false, false );
$postamat_card = $card_renderer->render( array_merge( $postamat, array( 'rate_id' => 'cdek:pickup:136' ) ), false, false );
$checkout_postamat_card = $card_renderer->render( array_merge( $postamat, array( 'rate_id' => 'cdek:pickup:136' ) ), true, false, false );
cdek_pickup_assert( str_contains( $pvz_card, 'Пункт выдачи СДЭК' ), 'CDEK PVZ card title must be carrier-aware.' );
cdek_pickup_assert( str_contains( $postamat_card, 'Постамат СДЭК' ) && str_contains( $postamat_card, 'Срок хранения 3 дня' ), 'CDEK POSTAMAT card title and storage notice must render.' );
cdek_pickup_assert( str_contains( $postamat_card, 'Описание:' ) && str_contains( $postamat_card, 'Inside the shopping center' ), 'CDEK pickup description must render with label in pickup card.' );
cdek_pickup_assert( ! str_contains( $postamat_card, '0.000000' ), 'CDEK pickup card must not render numeric zero description.' );
cdek_pickup_assert( str_contains( $checkout_postamat_card, 'Постамат СДЭК' ) && str_contains( $checkout_postamat_card, 'Kemerovo, Sovetskiy 10' ) && str_contains( $checkout_postamat_card, 'Inside the shopping center' ) && str_contains( $checkout_postamat_card, 'Срок хранения 3 дня' ), 'CDEK checkout selected card must render full visual pickup details.' );
cdek_pickup_assert( ! str_contains( $checkout_postamat_card, 'Код пункта:' ) && ! str_contains( $checkout_postamat_card, 'Индекс:' ) && ! str_contains( $checkout_postamat_card, '650004' ), 'CDEK checkout selected card must not render point code or postcode rows.' );
$empty_work_card = $card_renderer->render( array_merge( $postamat, array( 'work_time' => '0.000000', 'point_work_time' => '0.000000', 'rate_id' => 'cdek:pickup:136' ) ), false, false );
cdek_pickup_assert( ! str_contains( $empty_work_card, 'Время работы:' ), 'Pickup card must not render empty numeric work_time row.' );

$delivery_requests = $http->countDeliveryPointRequests();
$service->pointsByCityCode( 270 );
cdek_pickup_assert( $delivery_requests === $http->countDeliveryPointRequests(), 'Second CDEK deliverypoints request must be served from cache.' );
$service->pointsByCityCode( 44 );
cdek_pickup_assert( $delivery_requests + 1 === $http->countDeliveryPointRequests(), 'CDEK deliverypoints cache key must distinguish city_code.' );
$settings->save_from_admin(
	array(
		CdekSettings::ENVIRONMENT_KEY => CdekSettings::ENV_PRODUCTION,
		CdekSettings::PRODUCTION_ACCOUNT_KEY => 'pickup-account',
		'cdek_production_secure_password' => 'pickup-secret',
	)
);
$service->pointsByCityCode( 270 );
cdek_pickup_assert( $delivery_requests + 2 === $http->countDeliveryPointRequests(), 'CDEK deliverypoints cache key must distinguish environment.' );
$service->pointsByCityCode( 270, array( 'refresh' => true ) );
cdek_pickup_assert( $delivery_requests + 3 === $http->countDeliveryPointRequests(), 'CDEK deliverypoints refresh must bypass cache.' );

$session = new CheckoutSessionManager();
$rate = array(
	'rate_id' => 'cdek:pickup:136',
	'id' => 'cdek:pickup:136',
	'carrier_key' => 'cdek',
	'service_key' => 'cdek',
	'service_title' => 'CDEK',
	'delivery_type' => DeliveryType::PICKUP,
	'requires_pickup_point' => true,
	'tariff_key' => '136',
	'tariff_title' => 'Warehouse warehouse',
	'selected_tariff_object' => '136',
	'selected_tariff_title' => 'Warehouse warehouse',
	'delivery_comment' => '2-4 days',
	'cost' => 350.5,
	'rate_meta' => array(
		'package' => array( 'weight_g' => 1200, 'dimensions_cm' => array( 'length' => 20, 'width' => 15, 'height' => 10 ) ),
		'request_payload_sanitized' => array( 'to_location' => array( 'code' => 270 ) ),
	),
);
WC()->session->set( 'chosen_shipping_methods', array( 'wdc_platform_delivery:cdek:pickup:136' ) );
$session->save_rates( array( 'cdek:pickup:136' => $rate ) );
$session->save_city_context( array( 'city_code' => 270, 'city_name' => 'Kemerovo', 'region_name' => 'Kemerovo region', 'postcode' => '650004', 'country_code' => 'RU' ) );
$point = $postamat;
$selection = array(
	'id' => (string) ( $point['id'] ?? 'cdek:KEM7' ),
	'carrier_key' => 'cdek',
	'rate_id' => 'cdek:pickup:136',
	'point_code' => (string) $point['point_code'],
	'point_type' => (string) $point['point_type'],
	'point_name' => (string) $point['point_name'],
	'point_address' => (string) $point['point_address'],
	'point_postcode' => (string) $point['point_postcode'],
	'city_name' => (string) $point['city_name'],
	'region_name' => (string) $point['region_name'],
	'lat' => $point['lat'],
	'lng' => $point['lng'],
	'point_work_time' => '0.000000',
	'description' => (string) $point['description'],
	'storage_notice' => (string) $point['storage_notice'],
	'cdek_code' => (string) $point['cdek_code'],
	'snapshot' => $point,
);
$session->save_pickup_selection( $selection );
$session->save_checkout_pickup_point( $selection );
cdek_pickup_assert( true === $session->pickup_selection_matches( 'cdek', 'cdek:pickup:999' ), 'CDEK checkout pickup selection must match grouped CDEK pickup family.' );
cdek_pickup_assert( false === $session->pickup_selection_matches( 'cdek', 'cdek:courier:137' ), 'CDEK checkout pickup selection must not match courier family.' );

$session->clear_pickup_selection( 'validation_restore_smoke' );
$session->save_checkout_pickup_point( $selection );
$errors = new CdekPickupSmokeErrors();
( new CheckoutValidation( $session ) )->validate(
	array(
		'shipping_method' => array( 'wdc_platform_delivery:cdek:pickup:136' ),
		'shipping_city' => 'Kemerovo',
		'wdc_pickup_point_code' => 'KEM7',
	),
	$errors
);
cdek_pickup_assert( array() === $errors->errors, 'Selected CDEK pickup must pass checkout validation after restore from checkout session.' );
cdek_pickup_assert( true === $session->pickup_selection_matches( 'cdek', 'cdek:pickup:136' ), 'Checkout validation must restore CDEK pickup selection with selected rate id.' );
cdek_pickup_assert( 'Kemerovo, Sovetskiy 10' === (string) ( $session->checkout_pickup_point()['point_address'] ?? '' ) && 'Inside the shopping center' === (string) ( $session->checkout_pickup_point()['description'] ?? '' ), 'Checkout validation restore must keep the full CDEK checkout pickup payload.' );

$checkout_order = new CdekPickupSmokeOrder();
$persister = new OrderShippingMetaPersister( $session );
$persister->persist( $checkout_order, array() );
$item = new CdekPickupSmokeItem();
$persister->persist_shipping_item_meta( $item );
cdek_pickup_assert( 'RU' === $checkout_order->shipping_country && 'Kemerovo region' === $checkout_order->shipping_state && 'Kemerovo' === $checkout_order->shipping_city, 'Checkout order create must write CDEK pickup country/state/city.' );
cdek_pickup_assert( '650004' === $checkout_order->shipping_postcode && 'Kemerovo, Sovetskiy 10' === $checkout_order->shipping_address_1 && '' === $checkout_order->shipping_address_2, 'Checkout order create must write CDEK pickup postcode/address and clear address_2.' );
$calculation = $checkout_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ] ?? array();
cdek_pickup_assert( 'cdek' === ( $calculation['pickup']['carrier_key'] ?? '' ) && 'KEM7' === ( $calculation['pickup']['point_code'] ?? '' ), 'Checkout calculation data must save CDEK pickup block.' );
cdek_pickup_assert( 'KEM7' === ( $calculation['pickup']['cdek_code'] ?? '' ) && 'POSTAMAT' === ( $calculation['pickup']['point_type'] ?? '' ), 'Checkout calculation data must save CDEK code and point type.' );
cdek_pickup_assert( 'Inside the shopping center' === ( $calculation['pickup']['description'] ?? '' ) && 'Срок хранения 3 дня' === ( $calculation['pickup']['storage_notice'] ?? '' ), 'Checkout calculation data must save CDEK description and storage notice.' );
cdek_pickup_assert( '' === ( $calculation['pickup']['work_time'] ?? '' ), 'Checkout calculation data must not save numeric zero work_time.' );
cdek_pickup_assert( isset( $calculation['pickup']['raw_sanitized'] ) && is_array( $calculation['pickup']['raw_sanitized'] ), 'Checkout calculation data must save raw_sanitized pickup payload.' );
cdek_pickup_assert( 1 === count( $item->meta ) && in_array( '2-4 days', array_values( $item->meta ), true ), 'Checkout visible shipping item meta must contain only delivery time.' );

$order_display = new PickupPointOrderDisplay( $card_renderer, $settings_repository );
ob_start();
$order_display->render( $checkout_order );
$order_card = (string) ob_get_clean();
cdek_pickup_assert( str_contains( $order_card, 'Постамат СДЭК' ), 'CDEK thankyou/order card must render carrier-aware POSTAMAT title.' );
cdek_pickup_assert( str_contains( $order_card, 'Код пункта:' ) && str_contains( $order_card, 'KEM7' ), 'CDEK thankyou/order card must render CDEK point code.' );
cdek_pickup_assert( str_contains( $order_card, 'Индекс:' ) && str_contains( $order_card, '650004' ), 'CDEK thankyou/order card must render pickup postcode.' );
cdek_pickup_assert( str_contains( $order_card, 'Kemerovo, Sovetskiy 10' ), 'CDEK thankyou/order card must render pickup address.' );
cdek_pickup_assert( str_contains( $order_card, 'Описание:' ) && str_contains( $order_card, 'Inside the shopping center' ), 'CDEK thankyou/order card must render saved description with label.' );
cdek_pickup_assert( str_contains( $order_card, 'Срок хранения 3 дня' ), 'CDEK thankyou/order card must render POSTAMAT storage notice.' );
cdek_pickup_assert( ! str_contains( $order_card, 'Время работы:' ) && ! str_contains( $order_card, '0.000000' ), 'CDEK thankyou/order card must not render empty work_time or numeric zero values.' );

$settings_repository->set( 'pickup_email_card_enabled_emails', array( 'customer_completed_order' ) );
ob_start();
$order_display->render_email( $checkout_order, false, false, (object) array( 'id' => 'customer_completed_order' ) );
$email_card = (string) ob_get_clean();
cdek_pickup_assert( str_contains( $email_card, 'Постамат СДЭК' ) && str_contains( $email_card, 'Inside the shopping center' ) && str_contains( $email_card, 'Срок хранения 3 дня' ), 'CDEK email pickup card must render title, description and storage notice.' );

$replacement = new OrderDeliveryReplacementService( new OrderShipmentRepository() );
$admin_order_without_point = new CdekPickupSmokeOrder();
$admin_order_without_point->shipping_items = array( 'method_title' => 'Old', 'total' => 10.0, 'meta' => array() );
$blocked = $replacement->save(
	$admin_order_without_point,
	array(
		'selected_location' => array( 'city_code' => 270, 'city_value' => 'Kemerovo', 'region_name' => 'Kemerovo region', 'country_code' => 'RU' ),
		'selected_rate' => $rate,
		'selected_pickup_point' => array(),
		'normalized_shipping_address' => array(),
	)
);
cdek_pickup_assert( false === $blocked['success'], 'Admin recalculation must block CDEK pickup save without selected point.' );

$admin_order = new CdekPickupSmokeOrder();
$admin_order->shipping_items = array( 'method_title' => 'Old', 'total' => 10.0, 'meta' => array() );
$saved = $replacement->save(
	$admin_order,
	array(
		'selected_location' => array( 'city_code' => 270, 'city_value' => 'Kemerovo', 'region_name' => 'Kemerovo region', 'country_code' => 'RU' ),
		'selected_rate' => $rate,
		'selected_pickup_point' => $selection,
		'normalized_shipping_address' => array(),
	)
);
cdek_pickup_assert( true === $saved['success'], 'Admin recalculation must save CDEK pickup with selected point.' );
cdek_pickup_assert( 'Kemerovo, Sovetskiy 10' === $admin_order->shipping_address_1 && '' === $admin_order->shipping_address_2, 'Admin recalculation must write CDEK pickup address.' );
cdek_pickup_assert( '650004' === $admin_order->shipping_postcode && 'RU' === $admin_order->shipping_country, 'Admin recalculation must write CDEK pickup postcode and country.' );
cdek_pickup_assert( 'KEM7' === ( $admin_order->meta['_wdc_pickup_point_code'] ?? '' ), 'Admin recalculation must save selected CDEK point code, not postcode.' );
cdek_pickup_assert( 1 === count( $admin_order->shipping_items['meta'] ?? array() ) && in_array( '2-4 days', array_values( $admin_order->shipping_items['meta'] ?? array() ), true ), 'Admin visible shipping item meta must contain only delivery time.' );
cdek_pickup_assert( 'cdek' === ( $admin_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ]['pickup']['carrier_key'] ?? '' ), 'Admin calculation data must save CDEK pickup block.' );
cdek_pickup_assert( 'Inside the shopping center' === ( $admin_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ]['pickup']['description'] ?? '' ), 'Admin calculation data must save CDEK pickup description.' );
cdek_pickup_assert( 'Срок хранения 3 дня' === ( $admin_order->meta[ OrderShippingMetaPersister::CALCULATION_META_KEY ]['pickup']['storage_notice'] ?? '' ), 'Admin calculation data must save CDEK POSTAMAT storage notice.' );

$checkout_js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/pickup-map/wdc-pickup-checkout.js' );
$admin_js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/order-delivery-recalculation.js' );
$rest_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/Rest/PickupPointsRestController.php' );
cdek_pickup_assert( str_contains( $checkout_js, "'cdek:pickup'" ) && str_contains( $checkout_js, 'carrier_key: point.carrier_key' ), 'Checkout pickup JS must carry CDEK carrier context and selected point payload.' );
cdek_pickup_assert( str_contains( $checkout_js, 'function pickupMatchesFamily' ) && str_contains( $checkout_js, "family === 'cdek:pickup'" ) && str_contains( $checkout_js, 'function containerMethod' ), 'Checkout pickup JS must keep carrier-aware active state per pickup family.' );
cdek_pickup_assert( str_contains( $checkout_js, "toggleBlock(container, '[data-wdc-pickup-code-block]', false)" ) && str_contains( $checkout_js, "toggleBlock(container, '[data-wdc-pickup-postcode-block]', false)" ), 'Checkout pickup JS must hide CDEK point code and postcode blocks in selected checkout cards.' );
cdek_pickup_assert( str_contains( $checkout_js, 'description: point.description' ) && str_contains( $checkout_js, 'storage_notice: point.storage_notice' ) && str_contains( $checkout_js, 'cdek_code: point.cdek_code' ), 'Checkout pickup JS restore payload must include full CDEK point fields.' );
cdek_pickup_assert( str_contains( $admin_js, "'cdek' === String( rate.carrier_key || rate.service_key || '' )" ) && str_contains( $admin_js, "loadPickupPointsForLocation( 'search', value )" ), 'Admin recalculation JS must use CDEK pickup search path.' );
cdek_pickup_assert( str_contains( $rest_source, "carrier === 'cdek'" ) || str_contains( $rest_source, "'cdek' === \$carrier" ), 'Pickup REST source must route CDEK pickup requests.' );

$encoded_meta = (string) wp_json_encode( array( $checkout_order->meta, $admin_order->meta, $GLOBALS['wdc_cdek_pickup_logs'] ) );
cdek_pickup_assert( ! str_contains( $encoded_meta, 'pickup-secret' ) && ! str_contains( $encoded_meta, 'pickup-token' ), 'CDEK pickup logs and meta must not contain access token or secret.' );

echo "CDEK pickup points smoke OK\n";
