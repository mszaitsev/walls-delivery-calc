<?php
declare(strict_types=1);

use WallsShop\WDC\Checkout\Address\CheckoutAddressNormalizer;
use WallsShop\WDC\Checkout\Address\CheckoutAddressRuntime;
use WallsShop\WDC\Checkout\Address\DaDataAddressNormalizer;
use WallsShop\WDC\Checkout\Address\FiasAddressNormalizer;
use WallsShop\WDC\Checkout\Locations\CheckoutCityResolver;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationAjax;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationSearch;
use WallsShop\WDC\Checkout\Validation\CheckoutAddressValidation;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutAddressRenderer;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutValidation;
use WallsShop\WDC\Checkout\WooCommerce\NewShippingMethod;
use WallsShop\WDC\Checkout\WooCommerce\OrderShippingMetaPersister;
use WallsShop\WDC\Checkout\WooCommerce\WooCommercePackageMapper;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Import\LocationImportService;
use WallsShop\WDC\Locations\Normalization\FallbackAddressNormalizer;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

$GLOBALS['wdc_address_test_options'] = array();

function get_option( string $key, mixed $default = false ): mixed {
	return $GLOBALS['wdc_address_test_options'][ $key ] ?? $default;
}

function update_option( string $key, mixed $value, bool|string $autoload = false ): bool {
	$GLOBALS['wdc_address_test_options'][ $key ] = $value;
	return true;
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = '';
		public int $insert_id = 0;

		/** @var array<int,array<string,mixed>> */
		public array $rows = array();

		public function prepare( string $query, mixed ...$args ): array {
			return array( 'query' => $query, 'args' => $args );
		}

		public function esc_like( string $text ): string {
			return addcslashes( $text, '_%\\' );
		}

		public function insert( string $table, array $data, array $format ): int {
			++$this->insert_id;
			$data['id'] = $this->insert_id;
			$this->rows[ $this->insert_id ] = $data;

			return 1;
		}

		public function update( string $table, array $data, array $where, array $format, array $where_format ): int {
			return 1;
		}

		public function get_row( array $prepared, string $output ): ?array {
			$query = $prepared['query'];
			$value = (string) ( $prepared['args'][0] ?? '' );
			foreach ( $this->rows as $row ) {
				if ( str_contains( $query, 'WHERE id =' ) && (int) $row['id'] === (int) $value ) {
					return $row;
				}
				if ( str_contains( $query, 'WHERE fias_id =' ) && (string) $row['fias_id'] === $value ) {
					return $row;
				}
				if ( str_contains( $query, 'WHERE gar_id =' ) && (string) $row['gar_id'] === $value ) {
					return $row;
				}
			}

			return null;
		}

		public function get_results( array $prepared, string $output ): array {
			$query = trim( (string) ( $prepared['args'][0] ?? '' ), '%' );
			$limit = (int) ( $prepared['args'][1] ?? 20 );
			$rows = array_filter(
				$this->rows,
				static fn ( array $row ): bool => 1 === (int) $row['active'] && str_contains( (string) $row['searchable_text'], $query )
			);
			usort( $rows, static fn ( array $a, array $b ): int => strcmp( (string) $a['display_name'], (string) $b['display_name'] ) );

			return array_slice( array_values( $rows ), 0, $limit );
		}

		public function get_var( mixed $query ): int {
			return count( $this->rows );
		}

		public function query( mixed $query ): int {
			return 1;
		}
	}
}

if ( ! class_exists( 'WC_Shipping_Method' ) ) {
	class WC_Shipping_Method {
		public string $id = '';
		public int $instance_id = 0;
		public string $method_title = '';
		public string $method_description = '';
		public string $enabled = 'yes';
		public string $title = '';
		/** @var array<int,string> */
		public array $supports = array();
		/** @var array<int,array<string,mixed>> */
		public array $rates = array();

		public function add_rate( array $rate ): void {
			$this->rates[] = $rate;
		}
	}
}

function current_time( string $type ): string {
	return '2026-05-21 12:00:00';
}

function __( string $text, string $domain = '' ): string {
	return $text;
}

function esc_html__( string $text, string $domain = '' ): string {
	return $text;
}

function esc_html( mixed $text ): string {
	return htmlspecialchars( (string) $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
}

function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
	return json_encode( $value, $flags );
}

function sanitize_text_field( string $value ): string {
	return trim( strip_tags( $value ) );
}

function wp_unslash( mixed $value ): mixed {
	return $value;
}

final class WdcAddressSmokeSession {
	/** @var array<string,mixed> */
	private array $data = array();

	public function set( string $key, mixed $value ): void {
		$this->data[ $key ] = $value;
	}

	public function get( string $key, mixed $default = null ): mixed {
		return $this->data[ $key ] ?? $default;
	}

	public function __unset( string $key ): void {
		unset( $this->data[ $key ] );
	}
}

final class WdcAddressSmokeWooCommerce {
	public WdcAddressSmokeSession $session;

	public function __construct() {
		$this->session = new WdcAddressSmokeSession();
	}
}

function WC(): WdcAddressSmokeWooCommerce {
	static $wc = null;
	if ( null === $wc ) {
		$wc = new WdcAddressSmokeWooCommerce();
	}

	return $wc;
}

final class WdcAddressSmokeOrder {
	/** @var array<string,mixed> */
	public array $meta = array();

	public function update_meta_data( string $key, mixed $value ): void {
		$this->meta[ $key ] = $value;
	}
}

final class WdcAddressSmokeErrors {
	/** @var array<string,string> */
	public array $errors = array();

	public function add( string $code, string $message ): void {
		$this->errors[ $code ] = $message;
	}
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function address_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$wpdb = new wpdb();
$repository = new LocationRepository( $wpdb );
( new LocationImportService( $repository ) )->import_from_json_file( dirname( __DIR__, 2 ) . '/database/demo/locations-demo.json' );

$search = new CheckoutLocationSearch( new LocationSearchService( $repository ) );
$resolver = new CheckoutCityResolver( $repository, $search );
$session = new CheckoutSessionManager();
$normalizer = new CheckoutAddressNormalizer(
	new FiasAddressNormalizer( $resolver ),
	new DaDataAddressNormalizer(),
	new FallbackAddressNormalizer()
);
$runtime = new CheckoutAddressRuntime( $normalizer, $resolver, $session );

$known_city = (string) $wpdb->rows[1]['city_name'];
$known_postcode = (string) $wpdb->rows[1]['postcode'];

$known = $resolver->resolve_city( $known_city );
address_smoke_assert( null !== $known, 'Known city must resolve.' );
address_smoke_assert( $known_postcode === $resolver->resolve_postcode( $known_city ), 'Known city postcode must resolve.' );

$_REQUEST = array( 'query' => 'Новос' );
ob_start();
( new CheckoutLocationAjax( $search, new SettingsRepository() ) )->handle();
$ajax_payload = json_decode( (string) ob_get_clean(), true );
address_smoke_assert( true === ( $ajax_payload['success'] ?? false ), 'Location AJAX must return success.' );
address_smoke_assert( 'Новосибирская область' === ( $ajax_payload['data']['groups'][0]['region'] ?? '' ), 'Location AJAX must group Новос by region.' );
address_smoke_assert( 'Новосибирск' === ( $ajax_payload['data']['groups'][0]['locations'][0]['city_name'] ?? '' ), 'Location AJAX must return Новосибирск result.' );

$known_result = $runtime->resolve_checkout_address(
	array(
		'shipping_country' => 'RU',
		'shipping_city' => $known_city,
		'shipping_address_1' => 'Main street',
		'shipping_address_2' => '1',
	)
);
address_smoke_assert( ! $known_result->success, 'FIAS placeholder must not normalize known city.' );
address_smoke_assert( ! $known_result->address->normalized, 'Known city result must not be marked normalized.' );
address_smoke_assert( $known_result->address->fallback, 'Known city must continue to manual fallback.' );
address_smoke_assert( $known_postcode === $known_result->address->postcode, 'Known city result must keep local postcode context.' );

$selected_result = $runtime->resolve_checkout_address(
	array(
		'shipping_country'                    => 'RU',
		'shipping_city'                       => 'Новосибирск',
		'shipping_address_1'                  => 'Красный проспект',
		'wdc_platform_location_id'            => '1',
		'wdc_platform_location_fias_id'       => 'demo-fias-nsk',
		'wdc_platform_location_gar_id'        => 'demo-gar-nsk',
		'wdc_platform_location_display_name'  => 'Новосибирск, Новосибирская область',
		'wdc_platform_location_postcode'      => '630000',
		'wdc_platform_location_region_name'   => 'Новосибирская область',
	)
);
address_smoke_assert( $selected_result->address->normalized, 'Hidden selected location must normalize address.' );
address_smoke_assert( '630000' === $selected_result->address->postcode, 'Hidden selected location must set postcode.' );
address_smoke_assert( 'demo-fias-nsk' === $selected_result->address->fias_id, 'Hidden selected location must set FIAS id.' );
address_smoke_assert( 'demo-gar-nsk' === $selected_result->address->gar_id, 'Hidden selected location must set GAR id.' );

$unknown_result = $runtime->resolve_checkout_address(
	array(
		'shipping_country' => 'RU',
		'shipping_city' => 'Unknown Test City',
		'shipping_address_1' => 'Fallback street',
	)
);
address_smoke_assert( ! $unknown_result->success, 'Unknown city fallback must not report success.' );
address_smoke_assert( $unknown_result->address->fallback, 'Unknown city must be marked fallback.' );
address_smoke_assert( 'fallback' === $unknown_result->source, 'Unknown city must use fallback source.' );
address_smoke_assert( '' === $unknown_result->address->postcode, 'Unknown city must not set postcode.' );

$session->save_rates(
	array(
		'demo:courier' => array(
			'carrier_key' => 'demo',
			'rate_id' => 'demo:courier',
			'delivery_type' => 'courier',
			'crossed_price' => null,
			'planned_delivery_comment' => '',
			'comments' => array(),
			'fallback_used' => false,
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( NewShippingMethod::METHOD_ID . ':demo:courier' ) );
$order = new WdcAddressSmokeOrder();
( new OrderShippingMetaPersister( $session ) )->persist( $order );
address_smoke_assert( $unknown_result->address->raw_address === ( $order->meta['_wdc_platform_fallback_address'] ?? null ), 'Fallback order meta must persist raw fallback address.' );
address_smoke_assert( true === ( $order->meta['_wdc_platform_address_fallback_used'] ?? false ), 'Fallback order meta must persist fallback address flag.' );

$fallback = ( new FallbackAddressNormalizer() )->normalize( 'Fallback raw', array( 'city' => 'Fallback City' ) );
address_smoke_assert( $fallback->address->fallback, 'Fallback normalizer must mark fallback.' );

$manual_fallback = $runtime->resolve_checkout_address(
	array(
		'shipping_country'   => 'RU',
		'shipping_city'      => 'Berlin',
		'shipping_address_1' => 'Manual street',
	)
);
address_smoke_assert( $manual_fallback->address->fallback, 'Manual unknown city must remain fallback.' );
address_smoke_assert( ! $manual_fallback->address->normalized, 'Manual unknown city must not be normalized.' );
address_smoke_assert( '' === $manual_fallback->address->fias_id && '' === $manual_fallback->address->gar_id, 'Manual fallback city must not set FIAS/GAR ids.' );

$runtime->resolve_checkout_address( array( 'shipping_country' => 'RU', 'shipping_city' => $known_city ) );
$mapper = new WooCommercePackageMapper( $runtime, $session );
$request = $mapper->map(
	array(
		'destination' => array( 'country' => 'RU', 'city' => $known_city ),
		'contents_cost' => 100,
		'contents_weight' => 1,
		'contents' => array(),
	)
);
address_smoke_assert( ! $request->destination->normalized, 'QuoteRequest destination must not be address-normalized by local city DB.' );
address_smoke_assert( $known_postcode === $request->destination->postcode, 'QuoteRequest destination must include resolved postcode.' );
address_smoke_assert( true === $request->destination->fallback, 'QuoteRequest known destination must use manual fallback until FIAS runtime is verified.' );

$stored = $session->normalized_address_result();
address_smoke_assert( null !== $stored && $stored->address->fallback, 'Session must persist fallback address result.' );
address_smoke_assert( array() !== $session->selected_city(), 'Session must persist selected city.' );

$session->save_pickup_selection(
	array(
		'carrier_key'    => 'demo',
		'rate_id'        => 'demo:pickup',
		'point_code'     => 'demo-nsk-001',
		'point_address'  => 'Красный проспект, 25',
		'selected_at'    => '2026-05-21T00:00:00+00:00',
	)
);
WC()->session->set( 'shipping_for_package_0', array( 'cached' => true ) );
$nsk_fingerprint = $session->address_fingerprint();
$moscow_result = $runtime->resolve_checkout_address(
	array(
		'shipping_country'   => 'RU',
		'shipping_city'      => 'Москва',
		'shipping_address_1' => 'Тверская',
	)
);
address_smoke_assert( $nsk_fingerprint !== $session->address_fingerprint(), 'Address fingerprint must change after Новосибирск -> Москва.' );
address_smoke_assert( 'Москва' === $moscow_result->address->city, 'Normalized address must switch to Москва.' );
address_smoke_assert( 'Москва' === $session->normalized_address_result()?->address->city, 'Session normalized city must not stay Новосибирск.' );
address_smoke_assert( array() === $session->pickup_selection(), 'Pickup selection must clear after city fingerprint changes.' );
address_smoke_assert( null === WC()->session->get( 'shipping_for_package_0' ), 'Address fingerprint change must clear WooCommerce shipping cache.' );

ob_start();
( new CheckoutAddressRenderer( $session ) )->render();
$address_output = (string) ob_get_clean();
address_smoke_assert( str_contains( $address_output, 'Москва' ), 'Address renderer must show current city.' );
address_smoke_assert( ! str_contains( $address_output, 'Новосибирск' ), 'Address renderer must not show stale normalized city.' );

$session->save_rates(
	array(
		'demo:courier' => array(
			'carrier_key' => 'demo',
			'rate_id' => 'demo:courier',
			'delivery_type' => 'courier',
			'crossed_price' => null,
			'planned_delivery_comment' => '',
			'comments' => array(),
			'fallback_used' => false,
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( NewShippingMethod::METHOD_ID . ':demo:courier' ) );
$order = new WdcAddressSmokeOrder();
( new OrderShippingMetaPersister( $session ) )->persist( $order );
address_smoke_assert( false === ( $order->meta['_wdc_platform_normalized'] ?? true ), 'Order meta must not mark local city context as normalized.' );
address_smoke_assert( 'fallback' === ( $order->meta['_wdc_platform_normalization_source'] ?? '' ), 'Order meta must persist fallback normalization source.' );
address_smoke_assert( '' !== ( $order->meta['_wdc_platform_fallback_address'] ?? '' ), 'Fallback order meta must keep fallback address text.' );
address_smoke_assert( true === ( $order->meta['_wdc_platform_address_fallback_used'] ?? false ), 'Fallback order meta must persist true fallback address flag.' );
address_smoke_assert( '101000' === ( $order->meta['_wdc_platform_resolved_postcode'] ?? '' ), 'Order meta must persist current resolved postcode.' );
address_smoke_assert( '' === ( $order->meta['_wdc_platform_fias_id'] ?? '' ), 'Manual fallback must not persist FIAS id as normalized address data.' );
address_smoke_assert( '' === ( $order->meta['_wdc_platform_gar_id'] ?? '' ), 'Manual fallback must not persist GAR id as normalized address data.' );

$session->save_rates(
	array(
		'demo:courier' => array(
			'carrier_key' => 'demo',
			'rate_id' => 'demo:courier',
			'delivery_type' => 'courier',
		),
	)
);
WC()->session->set( 'chosen_shipping_methods', array( NewShippingMethod::METHOD_ID . ':demo:courier' ) );
$errors = new WdcAddressSmokeErrors();
( new CheckoutValidation( $session, new CheckoutAddressValidation( $session ) ) )->validate( array( 'shipping_city' => '' ), $errors );
address_smoke_assert( ! isset( $errors->errors['wdc_city_required'] ), 'Courier validation must accept city from normalized session.' );

$empty_session = new CheckoutSessionManager();
WC()->session->set( 'wdc_platform_normalized_address', array() );
$empty_session->save_selected_delivery_type( 'courier' );
$errors = new WdcAddressSmokeErrors();
( new CheckoutValidation( $empty_session, new CheckoutAddressValidation( $empty_session ) ) )->validate( array( 'shipping_city' => '' ), $errors );
address_smoke_assert( isset( $errors->errors['wdc_city_required'] ), 'Courier validation must require city when no runtime city exists.' );
address_smoke_assert( 'Введите населенный пункт.' === ( $errors->errors['wdc_city_required'] ?? '' ), 'City empty validation message must be Russian.' );

echo "Address normalization smoke test passed.\n";
