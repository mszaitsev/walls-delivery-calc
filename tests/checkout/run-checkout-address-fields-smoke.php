<?php
declare(strict_types=1);

use WallsShop\WDC\Checkout\WooCommerce\CheckoutFieldConfigurator;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutRateRenderer;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutValidation;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = '' ): string {
		return $text;
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
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
	}
}

final class WdcAddressFieldsSmokeSession {
	/** @var array<string,mixed> */
	public array $data = array();

	public function set( string $key, mixed $value ): void {
		$this->data[ $key ] = $value;
	}

	public function get( string $key, mixed $default = null ): mixed {
		return $this->data[ $key ] ?? $default;
	}
}

final class WdcAddressFieldsSmokeWooCommerce {
	public WdcAddressFieldsSmokeSession $session;

	public function __construct() {
		$this->session = new WdcAddressFieldsSmokeSession();
	}
}

function WC(): WdcAddressFieldsSmokeWooCommerce {
	static $wc = null;
	if ( null === $wc ) {
		$wc = new WdcAddressFieldsSmokeWooCommerce();
	}

	return $wc;
}

final class WdcAddressFieldsSmokeErrors {
	/** @var array<string,string> */
	public array $errors = array();

	public function add( string $code, string $message ): void {
		$this->errors[ $code ] = $message;
	}
}

final class WdcAddressFieldsSmokeRate {
	/** @param array<string,mixed> $meta */
	public function __construct(
		private string $id,
		private array $meta
	) {
	}

	public function get_meta_data(): array {
		return $this->meta;
	}

	public function get_id(): string {
		return $this->id;
	}
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function address_fields_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function address_fields_default_fixture(): array {
	return array(
		'country' => array( 'label' => 'Страна/регион', 'priority' => 40 ),
		'address_1' => array( 'label' => 'Адрес улицы', 'priority' => 50, 'required' => true ),
		'address_2' => array( 'label' => 'Apartment', 'priority' => 60 ),
		'city' => array( 'label' => 'Населённый пункт', 'priority' => 70 ),
		'state' => array( 'label' => 'Область / район', 'priority' => 80 ),
		'postcode' => array( 'label' => 'Почтовый индекс', 'priority' => 90 ),
	);
}

/**
 * @param array<string,array<string,mixed>> $default_fields
 * @return array<string,array<string,mixed>>
 */
function address_fields_prefix_billing_fields( array $default_fields ): array {
	$billing = array(
		'billing_first_name' => array( 'label' => 'Имя', 'priority' => 10, 'class' => array( 'form-row-first' ) ),
		'billing_last_name' => array( 'label' => 'Фамилия', 'priority' => 20, 'class' => array( 'form-row-last' ) ),
		'billing_company' => array( 'label' => 'Company', 'priority' => 30 ),
		'billing_phone' => array( 'label' => 'Телефон', 'priority' => 100, 'required' => false ),
		'billing_email' => array( 'label' => 'Email', 'priority' => 110, 'required' => true ),
	);

	foreach ( $default_fields as $key => $field ) {
		$billing[ 'billing_' . $key ] = $field;
	}

	return $billing;
}

function address_fields_composed_checkout_fields(): array {
	$configurator = new CheckoutFieldConfigurator();
	$default_fields = $configurator->configure_default_address_fields( address_fields_default_fixture() );
	$billing_fields = $configurator->configure_billing_fields( address_fields_prefix_billing_fields( $default_fields ) );

	return $configurator->configure_checkout_fields(
		array(
			'billing' => $billing_fields,
			'shipping' => array(
				'shipping_address_1' => array( 'label' => 'Shipping address', 'priority' => 50, 'required' => true ),
				'shipping_address_2' => array( 'label' => 'Shipping apartment', 'priority' => 60 ),
			),
			'order' => array(
				'order_comments' => array( 'placeholder' => 'Old placeholder' ),
			),
		)
	);
}

$configured = address_fields_composed_checkout_fields();
$billing_order = array_keys( $configured['billing'] );
usort(
	$billing_order,
	static fn( string $left, string $right ): int => (int) $configured['billing'][ $left ]['priority'] <=> (int) $configured['billing'][ $right ]['priority']
);
$expected_order = array( 'billing_first_name', 'billing_last_name', 'billing_country', 'billing_city', 'billing_state', 'billing_postcode', 'billing_address_1', 'billing_phone', 'billing_email' );
address_fields_smoke_assert( $expected_order === array_values( array_intersect( $billing_order, $expected_order ) ), 'Billing field priorities must produce the requested visual order.' );
address_fields_smoke_assert( array( 'form-row-first' ) === $configured['billing']['billing_first_name']['class'], 'First name row class must remain first-column.' );
address_fields_smoke_assert( array( 'form-row-last' ) === $configured['billing']['billing_last_name']['class'], 'Last name row class must remain last-column.' );
address_fields_smoke_assert( 'Имя и отчество' === $configured['billing']['billing_first_name']['label'], 'First-name label must become name plus patronymic.' );
address_fields_smoke_assert( 'Фамилия' === $configured['billing']['billing_last_name']['label'], 'Last-name label must remain unchanged.' );
address_fields_smoke_assert( CheckoutFieldConfigurator::ADDRESS_LABEL === $configured['billing']['billing_address_1']['label'], 'Billing address label must match the exact UX copy.' );
address_fields_smoke_assert( false === $configured['billing']['billing_address_1']['required'], 'Billing address must be optional by default.' );
address_fields_smoke_assert( 60 === $configured['billing']['billing_postcode']['priority'] && 70 === $configured['billing']['billing_address_1']['priority'], 'Billing postcode must render before address after the actual Woo field pipeline.' );
address_fields_smoke_assert( ! isset( $configured['billing']['billing_address_2'] ), 'Billing address line 2 must be removed from checkout fields.' );
address_fields_smoke_assert( true === $configured['billing']['billing_phone']['required'], 'Billing phone must be required.' );
address_fields_smoke_assert( true === $configured['billing']['billing_email']['required'], 'Email required semantics must remain unchanged.' );
address_fields_smoke_assert( CheckoutFieldConfigurator::ORDER_COMMENTS_PLACEHOLDER === $configured['order']['order_comments']['placeholder'], 'Order comments placeholder must match the exact multiline copy.' );
address_fields_smoke_assert( str_contains( $configured['order']['order_comments']['placeholder'], "\nотправить заказы вместе;\n" ), 'Order comments placeholder must preserve exact line breaks without bullets or blank lines.' );

$session = new CheckoutSessionManager();
$session->save_rates(
	array(
		'demo:courier' => array( 'rate_id' => 'demo:courier', 'delivery_type' => DeliveryType::COURIER, 'requires_courier_address' => true ),
		'demo:pickup' => array( 'rate_id' => 'demo:pickup', 'delivery_type' => DeliveryType::PICKUP, 'requires_pickup_point' => false ),
		'self_pickup' => array( 'rate_id' => 'self_pickup', 'delivery_type' => DeliveryType::PICKUP, 'requires_pickup_point' => false ),
	)
);
$validator = new CheckoutValidation( $session );
$errors = new WdcAddressFieldsSmokeErrors();
	$validator->validate(
	array(
		'shipping_method' => array( 'wdc_platform_delivery:demo:courier' ),
		'shipping_city' => 'Москва',
		'billing_city' => 'Москва',
		'billing_address_1' => '',
	),
	$errors
);
address_fields_smoke_assert( 1 === count( $errors->errors ) && isset( $errors->errors['wdc_courier_address_required'] ), 'Courier with empty billing address must produce one address-required error.' );

$errors = new WdcAddressFieldsSmokeErrors();
	$validator->validate(
	array(
		'shipping_method' => array( 'wdc_platform_delivery:demo:courier' ),
		'shipping_city' => 'Москва',
		'billing_city' => 'Москва',
		'billing_address_1' => 'Ленина, 1',
	),
	$errors
);
address_fields_smoke_assert( array() === $errors->errors, 'Courier with address must pass WDC courier-address validation.' );

$errors = new WdcAddressFieldsSmokeErrors();
	$validator->validate(
	array(
		'shipping_method' => array( 'wdc_platform_delivery:demo:pickup' ),
		'shipping_city' => 'Москва',
		'billing_city' => 'Москва',
		'billing_address_1' => '',
	),
	$errors
);
address_fields_smoke_assert( array() === $errors->errors, 'Pickup with empty address must not be blocked by WDC courier-address validation.' );

$errors = new WdcAddressFieldsSmokeErrors();
	$validator->validate(
	array(
		'shipping_method' => array( 'wdc_platform_delivery:self_pickup' ),
		'shipping_city' => 'Москва',
		'billing_city' => 'Москва',
		'billing_address_1' => '',
	),
	$errors
);
address_fields_smoke_assert( array() === $errors->errors, 'Self-pickup/fixed pickup with empty address must not be blocked by WDC courier-address validation.' );

$errors = new WdcAddressFieldsSmokeErrors();
	$validator->validate(
	array(
		'shipping_method' => array( 'wdc_platform_delivery:demo:courier' ),
		'shipping_city' => 'Москва',
		'billing_address_1' => '',
		'shipping_address_1' => 'Тверская, 1',
	),
	$errors
);
address_fields_smoke_assert( isset( $errors->errors['wdc_courier_address_required'] ), 'Current billing-only checkout contract must require billing_address_1 for courier even when stale shipping address data is present.' );

$rate = new DeliveryRate(
	'demo:courier',
	'demo',
	'Demo',
	'demo',
	'Demo courier',
	'courier',
	'Courier',
	DeliveryType::COURIER,
	'Demo courier',
	Money::from_rubles( 500 ),
	null,
	null,
	DateRange::single( 2 ),
	'',
	'',
	array(),
	false,
	'',
	false,
	true
);
$mapped = ( new WallsShop\WDC\Checkout\WooCommerce\WooCommerceRateMapper() )->map( $rate );
ob_start();
( new CheckoutRateRenderer( $session ) )->render( new WdcAddressFieldsSmokeRate( 'demo:courier', $mapped['meta_data'] ) );
$html = (string) ob_get_clean();
address_fields_smoke_assert( str_contains( $html, 'data-wdc-delivery-type="courier"' ), 'Rendered WDC rate meta must expose generic delivery type for frontend required toggle.' );
address_fields_smoke_assert( str_contains( $html, 'data-wdc-requires-courier-address="1"' ), 'Rendered WDC courier rate meta must expose courier-address requirement without carrier keys.' );

echo "Checkout address fields smoke passed.\n";
