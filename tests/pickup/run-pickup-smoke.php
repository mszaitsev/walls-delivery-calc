<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value ): string|false {
		return json_encode( $value );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string {
		return gmdate( 'Y-m-d H:i:s' );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return $value;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( mixed $text ): string {
		return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return 'manage_woocommerce' === $capability;
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		/** @var array<int,array<string,mixed>> */
		public array $pickup_rows = array();

		public function get_charset_collate(): string {
			return 'DEFAULT CHARSET=utf8mb4';
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$value = is_int( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sd]/', $value, $query, 1 ) ?? $query;
			}

			return $query;
		}

		public function insert( string $table, array $data, array $format = array() ): bool {
			$this->insert_id++;
			$data['id'] = $this->insert_id;
			$this->pickup_rows[] = $data;

			return true;
		}

		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			foreach ( $this->pickup_rows as $index => $row ) {
				if ( (int) ( $row['id'] ?? 0 ) === (int) ( $where['id'] ?? 0 ) ) {
					$this->pickup_rows[ $index ] = array_merge( $row, $data );
				}
			}

			return true;
		}

		public function get_row( string $query, mixed $output = null ): ?array {
			if ( ! preg_match( "/carrier_key = '([^']+)'.*point_code = '([^']+)'/", $query, $matches ) ) {
				return null;
			}

			foreach ( $this->pickup_rows as $row ) {
				if ( $row['carrier_key'] === $matches[1] && $row['point_code'] === $matches[2] ) {
					return $row;
				}
			}

			return null;
		}

		public function get_results( string $query, mixed $output = null ): array {
			if ( preg_match( "/carrier_key = '([^']+)'.*country_code = '([^']+)'.*city_name LIKE '%([^']+)%'/", $query, $matches ) ) {
				return array_values(
					array_filter(
						$this->pickup_rows,
						static fn ( array $row ): bool => (bool) ( $row['active'] ?? 0 )
							&& $row['carrier_key'] === $matches[1]
							&& $row['country_code'] === $matches[2]
							&& false !== ( function_exists( 'mb_stripos' ) ? mb_stripos( (string) $row['city_name'], $matches[3] ) : stripos( (string) $row['city_name'], $matches[3] ) )
					)
				);
			}

			if ( ! preg_match( "/carrier_key = '([^']+)'.*country_code = '([^']+)'/", $query, $matches ) ) {
				return array();
			}

			return array_values(
				array_filter(
					$this->pickup_rows,
					static fn ( array $row ): bool => (bool) ( $row['active'] ?? 0 )
						&& $row['carrier_key'] === $matches[1]
						&& $row['country_code'] === $matches[2]
				)
			);
		}

		public function get_var( string $query ): int {
			return count( $this->pickup_rows );
		}

		public function query( string $query ): bool {
			if ( str_starts_with( strtoupper( trim( $query ) ), 'DELETE' ) ) {
				$this->pickup_rows = array();
			}

			return true;
		}
	}
}

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {
		public string $id = '';
	}
}

$GLOBALS['wpdb'] = new wpdb();

final class WdcPickupSmokeSession {
	/** @var array<string,mixed> */
	private array $data = array();

	public function set( string $key, mixed $value ): void {
		$this->data[ $key ] = $value;
	}

	public function get( string $key, mixed $default = null ): mixed {
		return $this->data[ $key ] ?? $default;
	}
}

final class WdcPickupSmokeWooCommerce {
	public WdcPickupSmokeSession $session;

	public function __construct() {
		$this->session = new WdcPickupSmokeSession();
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC(): WdcPickupSmokeWooCommerce {
		static $wc = null;
		if ( null === $wc ) {
			$wc = new WdcPickupSmokeWooCommerce();
		}

		return $wc;
	}
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();
require_once dirname( __DIR__ ) . '/fixtures/TestDemoCarrier.php';
require_once dirname( __DIR__ ) . '/fixtures/TestPickupProvider.php';

use WallsShop\WDC\Carriers\Registry\CarrierRegistry;
use WallsShop\WDC\Checkout\Runtime\CarrierExecutionGuard;
use WallsShop\WDC\Checkout\Runtime\CheckoutLogger;
use WallsShop\WDC\Checkout\Runtime\CheckoutOrchestrator;
use WallsShop\WDC\Checkout\Runtime\FallbackRateFactory;
use WallsShop\WDC\Checkout\Runtime\RuleAppliedRateBuilder;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutDeliveryTypeSelector;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutValidation;
use WallsShop\WDC\Checkout\WooCommerce\NewShippingMethod;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Checkout\WooCommerce\PickupPointRenderer;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Address\AddressNormalizationResult;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Orders\Admin\OrderDeliveryMetabox;
use WallsShop\WDC\Pickup\Storage\PickupPointRepository;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;

function pickup_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class WdcPickupSmokeOrder {
	/** @var array<string,mixed> */
	public array $meta = array();
	/** @var array<string,string> */
	public array $shipping = array();

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}

	public function get_meta( string $key, bool $single = true ): mixed {
		return $this->meta[ $key ] ?? '';
	}

	public function set_shipping_address_1( string $value ): void {
		$this->shipping['address_1'] = $value;
	}

	public function set_shipping_address_2( string $value ): void {
		$this->shipping['address_2'] = $value;
	}

	public function set_shipping_city( string $value ): void {
		$this->shipping['city'] = $value;
	}

	public function set_shipping_postcode( string $value ): void {
		$this->shipping['postcode'] = $value;
	}

	public function set_shipping_country( string $value ): void {
		$this->shipping['country'] = $value;
	}

	public function get_shipping_address_1(): string {
		return $this->shipping['address_1'] ?? '';
	}

	public function get_shipping_address_2(): string {
		return $this->shipping['address_2'] ?? '';
	}

	public function get_shipping_city(): string {
		return $this->shipping['city'] ?? '';
	}

	public function get_shipping_postcode(): string {
		return $this->shipping['postcode'] ?? '';
	}

	public function get_shipping_country(): string {
		return $this->shipping['country'] ?? '';
	}
}

final class WdcPickupSmokeShippingItem {
	/** @var array<string,mixed> */
	public array $meta = array();

	public function add_meta_data( string $key, mixed $value, bool $unique = false ): void {
		$this->meta[ $key ] = $value;
	}
}

final class WdcPickupSmokeErrors {
	/** @var array<string,string> */
	public array $errors = array();

	public function add( string $code, string $message ): void {
		$this->errors[ $code ] = $message;
	}

	public function has_errors(): bool {
		return array() !== $this->errors;
	}
}

function pickup_smoke_request( string $delivery_type = '' ): QuoteRequest {
	return new QuoteRequest(
		'RU',
		new Address( country_code: 'RU', city: 'Новосибирск' ),
		Package::from_items( array(), 0, Money::from_rubles( 1000 ), Money::from_rubles( 1000 ) ),
		'',
		Money::from_rubles( 1000 ),
		'2026-05-21',
		array( 'delivery_type' => $delivery_type )
	);
}

function pickup_smoke_orchestrator(): CheckoutOrchestrator {
	$logger = new CheckoutLogger();
	$registry = new CarrierRegistry();
	$registry->register( new TestDemoCarrier() );

	return new CheckoutOrchestrator(
		$registry,
		new RuleAppliedRateBuilder( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) ),
		new RateSorter(),
		new FallbackRateFactory(),
		new CarrierExecutionGuard( $logger ),
		$logger
	);
}

$provider = new TestPickupProvider( dirname( __DIR__ ) . '/fixtures/demo/pickup-points-demo.json' );
$repo     = new PickupPointRepository();
$imported = $repo->save_many( $provider->load_points() );
pickup_smoke_assert( $imported >= 5, 'Demo pickup import must load at least five points.' );
pickup_smoke_assert( $repo->count_all() >= 5, 'Pickup repository must count imported rows.' );

$nsk = $repo->search( 'demo', 'RU', 'Новосибирск' );
pickup_smoke_assert( count( $nsk ) >= 3, 'Pickup search must find Novosibirsk points.' );
pickup_smoke_assert( count( $repo->search( 'demo', 'RU', 'новосибирск' ) ) >= 3, 'Pickup search must be case tolerant for Russian city names.' );
pickup_smoke_assert( count( $repo->search( 'demo', 'RU', 'Новосиб' ) ) >= 3, 'Pickup search must support partial Russian city names.' );
$moscow = $repo->search( 'demo', 'RU', 'Москва' );
pickup_smoke_assert( array() !== $moscow, 'Pickup search must find Moscow demo point.' );
pickup_smoke_assert( ! in_array( 'demo-nsk-001', array_map( static fn ( object $point ): string => $point->code, $moscow ), true ), 'Moscow pickup search must not include Novosibirsk points.' );
pickup_smoke_assert( null !== $repo->find_by_code( 'demo', 'demo-nsk-001' ), 'Pickup repository must find point by carrier and code.' );

$session = new CheckoutSessionManager();
$capture_session = new CheckoutSessionManager();
( new CheckoutDeliveryTypeSelector( $capture_session, $repo, new PickupPointRenderer() ) )->capture_update_order_review( 'wdc_platform_pickup_carrier=demo&wdc_platform_pickup_rate_id=demo%3Apickup&wdc_platform_pickup_point=demo-nsk-001' );
$captured_pickup = $capture_session->pickup_selection();
pickup_smoke_assert( 'demo-nsk-001' === ( $captured_pickup['point_code'] ?? '' ), 'Pickup update capture must save point code.' );
pickup_smoke_assert( 'Красный проспект, 25' === ( $captured_pickup['point_address'] ?? '' ), 'Pickup update capture must save point address.' );
pickup_smoke_assert( '' !== ( $captured_pickup['point_comment'] ?? '' ), 'Pickup update capture must save point comment.' );
pickup_smoke_assert( '' !== ( $captured_pickup['point_work_time'] ?? '' ), 'Pickup update capture must save point work time.' );
$session->save_selected_delivery_type( DeliveryType::PICKUP );
$session->save_pickup_selection(
	array(
		'carrier_key'      => 'demo',
		'rate_id'          => 'demo:pickup',
		'point_code'       => 'demo-nsk-001',
		'point_address'    => 'Красный проспект, 25',
		'point_comment'    => 'Тестовый пункт выдачи в центре города',
		'point_work_time'  => 'Пн-Сб 10:00-20:00',
		'selected_at'      => '2026-05-21T00:00:00+00:00',
	)
);
pickup_smoke_assert( 'pickup' === $session->selected_delivery_type(), 'Session must save selected delivery type.' );
pickup_smoke_assert( 'demo-nsk-001' === ( $session->pickup_selection()['point_code'] ?? '' ), 'Session must save pickup selection.' );
pickup_smoke_assert( 'demo' === $session->selected_pickup_carrier(), 'Session must save selected pickup carrier.' );
pickup_smoke_assert( $session->pickup_selection_matches( 'demo', 'demo:pickup' ), 'Pickup selection must match normalized rate id.' );
pickup_smoke_assert( $session->pickup_selection_matches( 'demo', NewShippingMethod::METHOD_ID . ':demo:pickup' ), 'Pickup selection must match full WooCommerce rate id.' );
pickup_smoke_assert( ! $session->pickup_selection_matches( 'other_carrier', 'demo:pickup' ), 'Pickup selection must reject another carrier.' );
pickup_smoke_assert( ! $session->pickup_selection_matches( 'demo', 'demo:courier' ), 'Pickup selection must reject another rate.' );

$carrier = new TestDemoCarrier();
$pickup_quote = $carrier->quote( pickup_smoke_request( DeliveryType::PICKUP ) );
pickup_smoke_assert( 2 === count( $pickup_quote->rates ), 'Pickup context must not hide courier rate.' );
pickup_smoke_assert( $pickup_quote->rates[0]->requires_pickup_point, 'Pickup delivery must require pickup point.' );

$courier_quote = $carrier->quote( pickup_smoke_request( DeliveryType::COURIER ) );
pickup_smoke_assert( 2 === count( $courier_quote->rates ), 'Courier context must not hide pickup rate.' );
pickup_smoke_assert( ! $courier_quote->rates[1]->requires_pickup_point, 'Courier delivery must not require pickup point.' );
pickup_smoke_assert( $courier_quote->rates[1]->requires_courier_address, 'Courier delivery must require courier address marker.' );

$session->save_rates(
	array(
		'demo:pickup' => array(
			'carrier_key'      => 'demo',
			'rate_id'          => 'demo:pickup',
			'delivery_type'    => 'pickup',
			'fallback_used'    => false,
		),
	)
);
$session->save_normalized_address_result(
	new AddressNormalizationResult(
		'RU 630000 Новосибирск',
		new Address( country_code: 'RU', city: 'Новосибирск', postcode: '630000', normalized: true ),
		true,
		1.0,
		'fias'
	)
);
WC()->session->set( 'chosen_shipping_methods', array( NewShippingMethod::METHOD_ID . ':demo:pickup' ) );
$order = new WdcPickupSmokeOrder();
$persister = new OrderShippingMetaPersister( $session );
$persister->persist( $order );
$shipping_item = new WdcPickupSmokeShippingItem();
$persister->persist_shipping_item_meta( $shipping_item, 0, array(), $order );
pickup_smoke_assert( 'demo-nsk-001' === ( $order->meta['_wdc_platform_pickup_code'] ?? '' ), 'Order meta must save pickup code.' );
pickup_smoke_assert( isset( $order->meta['_wdc_platform_pickup_address'], $order->meta['_wdc_platform_pickup_comment'], $order->meta['_wdc_platform_pickup_work_time'] ), 'Order meta must save pickup details.' );
pickup_smoke_assert( 'Красный проспект, 25' === ( $order->shipping['address_1'] ?? '' ), 'Pickup order must write pickup address to shipping address_1.' );
pickup_smoke_assert( 'Код ПВЗ: demo-nsk-001' === ( $order->shipping['address_2'] ?? '' ), 'Pickup order must write pickup code to shipping address_2.' );
pickup_smoke_assert( 'Новосибирск' === ( $order->shipping['city'] ?? '' ), 'Pickup order must write normalized city to shipping city.' );
pickup_smoke_assert( '630000' === ( $order->shipping['postcode'] ?? '' ), 'Pickup order must write resolved postcode to shipping postcode.' );
pickup_smoke_assert( 'demo-nsk-001' === ( $shipping_item->meta['Код ПВЗ'] ?? '' ), 'Pickup shipping item meta must save pickup code.' );
pickup_smoke_assert( isset( $shipping_item->meta['Адрес ПВЗ'], $shipping_item->meta['Комментарий ПВЗ'], $shipping_item->meta['Режим работы ПВЗ'] ), 'Pickup shipping item meta must save visible pickup details.' );
pickup_smoke_assert( 'Пункт выдачи' === ( $shipping_item->meta['Тип доставки'] ?? '' ), 'Pickup shipping item meta must expose human delivery type.' );
ob_start();
( new OrderDeliveryMetabox() )->render( $order );
$metabox_html = (string) ob_get_clean();
pickup_smoke_assert( str_contains( $metabox_html, 'Код ПВЗ' ) && str_contains( $metabox_html, 'Адрес ПВЗ' ), 'Order metabox must render pickup fields from order meta.' );
pickup_smoke_assert( ! str_contains( $metabox_html, 'postmeta' ), 'Order metabox must not expose direct postmeta access.' );
$metabox_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Orders/Admin/OrderDeliveryMetabox.php' );
pickup_smoke_assert( str_contains( $metabox_source, 'get_meta' ), 'Order metabox must read order meta through WC CRUD get_meta.' );
pickup_smoke_assert( ! str_contains( $metabox_source, 'get_post_meta' ) && ! str_contains( $metabox_source, 'update_post_meta' ) && ! str_contains( $metabox_source, '$wpdb' ), 'Order metabox must not use direct postmeta or wpdb access.' );

$errors = new WdcPickupSmokeErrors();
( new CheckoutValidation( $session ) )->validate( array( 'shipping_city' => 'Новосибирск' ), $errors );
pickup_smoke_assert( ! $errors->has_errors(), 'Validation must pass for matching pickup selection.' );

$session->save_pickup_selection(
	array(
		'carrier_key'      => 'other_carrier',
		'rate_id'          => 'other_carrier:pickup',
		'point_code'       => 'OTHER-1',
		'point_address'    => 'Other address',
		'point_comment'    => 'Wrong selection',
		'point_work_time'  => 'Daily',
		'selected_at'      => '2026-05-21T00:00:00+00:00',
	)
);
$errors = new WdcPickupSmokeErrors();
( new CheckoutValidation( $session ) )->validate( array( 'shipping_city' => 'Новосибирск' ), $errors );
pickup_smoke_assert( $errors->has_errors(), 'Validation must fail for pickup selection from another carrier or rate.' );
$order = new WdcPickupSmokeOrder();
( new OrderShippingMetaPersister( $session ) )->persist( $order );
pickup_smoke_assert( ! isset( $order->meta['_wdc_platform_pickup_code'] ), 'Order meta must not save mismatched pickup selection.' );

$session->save_selected_delivery_type( DeliveryType::COURIER );
$session->save_rates(
	array(
		'demo:courier' => array(
			'carrier_key'      => 'demo',
			'rate_id'          => 'demo:courier',
			'delivery_type'    => 'courier',
			'fallback_used'    => false,
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( NewShippingMethod::METHOD_ID . ':demo:courier' ) );
$errors = new WdcPickupSmokeErrors();
( new CheckoutValidation( $session ) )->validate( array( 'shipping_city' => 'Новосибирск' ), $errors );
pickup_smoke_assert( ! $errors->has_errors(), 'Validation must pass for courier with stale pickup selection.' );
$order = new WdcPickupSmokeOrder();
$persister = new OrderShippingMetaPersister( $session );
$persister->persist( $order );
$shipping_item = new WdcPickupSmokeShippingItem();
$persister->persist_shipping_item_meta( $shipping_item, 0, array(), $order );
pickup_smoke_assert( ! isset( $order->meta['_wdc_platform_pickup_code'] ), 'Courier order meta must not save stale pickup selection.' );
pickup_smoke_assert( array() === $order->shipping, 'Courier order must not write pickup shipping address.' );
pickup_smoke_assert( ! isset( $shipping_item->meta['Код ПВЗ'], $shipping_item->meta['Адрес ПВЗ'] ), 'Courier shipping item meta must not save stale pickup data.' );

ob_start();
( new OrderDeliveryMetabox() )->render( new WdcPickupSmokeOrder() );
$empty_metabox_html = (string) ob_get_clean();
pickup_smoke_assert( str_contains( $empty_metabox_html, 'Данные WDC для заказа не сохранены.' ), 'Order metabox must render fallback message without WDC meta.' );

$session->save_selected_delivery_type( DeliveryType::PICKUP );
$session->save_rates(
	array(
		'demo:pickup' => array(
			'carrier_key'      => 'demo',
			'rate_id'          => 'demo:pickup',
			'delivery_type'    => 'pickup',
			'fallback_used'    => false,
		),
	)
);
$session->save_pickup_selection(
	array(
		'carrier_key'      => 'demo',
		'rate_id'          => NewShippingMethod::METHOD_ID . ':demo:pickup',
		'point_code'       => 'demo-nsk-001',
		'point_address'    => 'Красный проспект, 25',
		'point_comment'    => 'Тестовый пункт выдачи в центре города',
		'point_work_time'  => 'Пн-Сб 10:00-20:00',
		'selected_at'      => '2026-05-21T00:00:00+00:00',
	)
);
WC()->session->set( 'chosen_shipping_methods', array( 'demo:pickup' ) );
$errors = new WdcPickupSmokeErrors();
( new CheckoutValidation( $session ) )->validate( array( 'shipping_city' => 'Новосибирск' ), $errors );
pickup_smoke_assert( ! $errors->has_errors(), 'Validation must accept full pickup selection rate id for normalized selected rate.' );

$orchestrator = pickup_smoke_orchestrator();
$pickup_rates = $orchestrator->calculate_rates( pickup_smoke_request( DeliveryType::PICKUP ) );
$courier_rates = $orchestrator->calculate_rates( pickup_smoke_request( DeliveryType::COURIER ) );
pickup_smoke_assert( 2 === count( $pickup_rates ), 'Orchestrator must not filter rates by pickup delivery type.' );
pickup_smoke_assert( 2 === count( $courier_rates ), 'Orchestrator must not filter rates by courier delivery type.' );

$root = dirname( __DIR__, 2 );
$modal_js = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-modal.js' ) ?: '';
$checkout_js = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-checkout.js' ) ?: '';
$map_js = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-map.js' ) ?: '';
$leaflet_provider_js = file_get_contents( $root . '/assets/frontend/pickup-map/providers/wdc-map-provider-leaflet.js' ) ?: '';
$yandex_provider_js = file_get_contents( $root . '/assets/frontend/pickup-map/providers/wdc-map-provider-yandex.js' ) ?: '';
pickup_smoke_assert( str_contains( $modal_js, 'wdc-pickup-map__locate' ) && str_contains( $modal_js, 'data-wdc-geolocation' ) && str_contains( $checkout_js, 'window.navigator.geolocation.getCurrentPosition' ), 'Pickup frontend must expose a map overlay geolocation button wired to browser geolocation.' );
pickup_smoke_assert( str_contains( $map_js, 'function useUserLocation(lat, lng)' ) && str_contains( $map_js, 'loadBounds(bboxAround(lat, lng), { force: true })' ) && str_contains( $map_js, 'searchAddress = null' ), 'Pickup geolocation must load nearby points from bbox without keeping the address marker active.' );
pickup_smoke_assert( ! str_contains( $leaflet_provider_js . $yandex_provider_js, 'wdc-map-user-marker' ) && str_contains( $leaflet_provider_js . $yandex_provider_js, 'wdc-map-search-pin--push' ), 'Pickup geolocation origin must reuse the red search push-pin instead of the old blue user marker.' );

echo "Pickup foundation smoke test passed.\n";
