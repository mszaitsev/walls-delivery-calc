<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

$GLOBALS['wdc_test_options'] = array();
$GLOBALS['wdc_test_actions'] = array();
$GLOBALS['wdc_test_editors'] = array();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['wdc_test_options'][ $key ] ?? $default;
	}
}

if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, mixed $value, bool|string $autoload = false ): bool {
		$GLOBALS['wdc_test_options'][ $key ] = $value;
		return true;
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): void {
		$GLOBALS['wdc_test_actions'][ $hook ][] = array( $callback, $priority, $accepted_args );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $value ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $value ) ?? '' );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}
}

if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( mixed $value ): string {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'checked' ) ) {
	function checked( mixed $checked, mixed $current = true, bool $display = true ): string {
		$result = (string) $checked === (string) $current ? ' checked="checked"' : '';
		if ( $display ) {
			echo $result;
		}
		return $result;
	}
}

if ( ! function_exists( 'selected' ) ) {
	function selected( mixed $selected, mixed $current = true, bool $display = true ): string {
		$result = (string) $selected === (string) $current ? ' selected="selected"' : '';
		if ( $display ) {
			echo $result;
		}
		return $result;
	}
}

if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( string $capability ): bool {
		return true;
	}
}

if ( ! function_exists( 'wp_nonce_field' ) ) {
	function wp_nonce_field( string|int $action = -1, string $name = '_wpnonce', bool $referer = true, bool $display = true ): string {
		$field = '<input type="hidden" name="' . esc_attr( $name ) . '" value="nonce">';
		if ( $display ) {
			echo $field;
		}
		return $field;
	}
}

if ( ! function_exists( 'submit_button' ) ) {
	function submit_button( string $text = 'Save Changes' ): void {
		echo '<button type="submit">' . esc_html( $text ) . '</button>';
	}
}

if ( ! function_exists( 'wp_editor' ) ) {
	function wp_editor( string $content, string $editor_id, array $settings = array() ): void {
		$GLOBALS['wdc_test_editors'][ $editor_id ] = array(
			'content'  => $content,
			'settings' => $settings,
		);
		echo '<textarea id="' . esc_attr( $editor_id ) . '" name="' . esc_attr( (string) ( $settings['textarea_name'] ?? $editor_id ) ) . '">' . esc_html( $content ) . '</textarea>';
	}
}

final class WdcDeliveryMessagesSmokeCart {
	public float $contents_total = 0.0;

	/** @var list<array<string,mixed>> */
	public array $shipping_packages = array();

	public function get_cart_contents_total(): float {
		return $this->contents_total;
	}

	/**
	 * @return list<array<string,mixed>>
	 */
	public function get_shipping_packages(): array {
		return $this->shipping_packages;
	}
}

final class WdcDeliveryMessagesSmokeWooCommerce {
	public WdcDeliveryMessagesSmokeCart $cart;

	public function __construct() {
		$this->cart = new WdcDeliveryMessagesSmokeCart();
	}
}

if ( ! function_exists( 'WC' ) ) {
	function WC(): WdcDeliveryMessagesSmokeWooCommerce {
		static $wc = null;
		if ( null === $wc ) {
			$wc = new WdcDeliveryMessagesSmokeWooCommerce();
		}

		return $wc;
	}
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Admin\SettingsAdminPage;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutCartTotalsResolver;
use WallsShop\WDC\Checkout\WooCommerce\CheckoutDeliveryMessages;
use WallsShop\WDC\Infrastructure\Settings\CheckoutDeliveryMessageSettings;
use WallsShop\WDC\Infrastructure\Settings\PlatformRuntimeSettings;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

function wdc_delivery_messages_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function wdc_delivery_messages_render( SettingsRepository $settings ): string {
	ob_start();
	( new CheckoutDeliveryMessages( new CheckoutDeliveryMessageSettings( $settings ), new CheckoutCartTotalsResolver() ) )->render();
	return (string) ob_get_clean();
}

$settings = new SettingsRepository();
$message_settings = new CheckoutDeliveryMessageSettings( $settings );
wdc_delivery_messages_assert( ! $message_settings->info_enabled(), 'Missing info enabled must default to false.' );
wdc_delivery_messages_assert( ! $message_settings->promo_enabled(), 'Missing promo enabled must default to false.' );
wdc_delivery_messages_assert( CheckoutDeliveryMessageSettings::DEFAULT_PROMO_THRESHOLD_KOPECKS === $message_settings->promo_threshold_kopecks(), 'Promo threshold default must exist while the feature is disabled.' );
wdc_delivery_messages_assert( CheckoutDeliveryMessageSettings::BASIS_ALL_CART_ITEMS === $message_settings->promo_total_basis(), 'Missing promo total basis must default to all_cart_items.' );
$settings->set( CheckoutDeliveryMessageSettings::PROMO_TOTAL_BASIS_KEY, 'bad-basis' );
wdc_delivery_messages_assert( CheckoutDeliveryMessageSettings::BASIS_ALL_CART_ITEMS === $message_settings->promo_total_basis(), 'Invalid promo total basis must fall back to all_cart_items.' );

$admin = new SettingsAdminPage( $settings, new PlatformRuntimeSettings( $settings ) );
$sanitized = $admin->sanitize_settings(
	array(
		CheckoutDeliveryMessageSettings::INFO_ENABLED_KEY => '1',
		CheckoutDeliveryMessageSettings::INFO_HTML_KEY => '<p><strong>Bold</strong> <em>Italic</em> <u>Under</u> <s>Strike</s> <a href="https://example.test">link</a> <span style="color:#ff0000;">red</span><script>alert(1)</script><a href="javascript:alert(1)">bad</a></p><p>Вторая строка</p>Первая строка<br>Вторая строка',
		CheckoutDeliveryMessageSettings::PROMO_ENABLED_KEY => '1',
		'checkout_delivery_promo_threshold_rub' => '3499.50',
		CheckoutDeliveryMessageSettings::PROMO_TOTAL_BASIS_KEY => CheckoutDeliveryMessageSettings::BASIS_SHIPPABLE_CART_ITEMS,
		CheckoutDeliveryMessageSettings::PROMO_BELOW_HTML_KEY => '<p>От {s} руб. Добавьте ещё {d} руб.</p>',
		CheckoutDeliveryMessageSettings::PROMO_REACHED_HTML_KEY => '<p>От {s} руб. достигнуто.</p>',
	)
);
wdc_delivery_messages_assert( true === $sanitized[ CheckoutDeliveryMessageSettings::INFO_ENABLED_KEY ], 'Info enabled must sanitize to true.' );
wdc_delivery_messages_assert( true === $sanitized[ CheckoutDeliveryMessageSettings::PROMO_ENABLED_KEY ], 'Promo enabled must sanitize to true.' );
wdc_delivery_messages_assert( 349950 === $sanitized[ CheckoutDeliveryMessageSettings::PROMO_THRESHOLD_KOPECKS_KEY ], 'Promo threshold must be stored as kopecks without losing cents.' );
wdc_delivery_messages_assert( CheckoutDeliveryMessageSettings::BASIS_SHIPPABLE_CART_ITEMS === $sanitized[ CheckoutDeliveryMessageSettings::PROMO_TOTAL_BASIS_KEY ], 'Promo basis must sanitize to canonical machine value.' );
foreach ( array( '<strong>Bold</strong>', '<em>Italic</em>', '<u>Under</u>', '<s>Strike</s>', 'href="https://example.test"', 'style="color: #ff0000"', '<p>Вторая строка</p>', '<br>' ) as $needle ) {
	wdc_delivery_messages_assert( str_contains( $sanitized[ CheckoutDeliveryMessageSettings::INFO_HTML_KEY ], $needle ), 'Sanitizer must preserve safe editor formatting: ' . $needle );
}
wdc_delivery_messages_assert( ! str_contains( $sanitized[ CheckoutDeliveryMessageSettings::INFO_HTML_KEY ], '<script' ), 'Sanitizer must strip script tags.' );
wdc_delivery_messages_assert( ! str_contains( $sanitized[ CheckoutDeliveryMessageSettings::INFO_HTML_KEY ], 'javascript:' ), 'Sanitizer must strip javascript URLs.' );
wdc_delivery_messages_assert( str_contains( $sanitized[ CheckoutDeliveryMessageSettings::PROMO_BELOW_HTML_KEY ], '{s}' ) && str_contains( $sanitized[ CheckoutDeliveryMessageSettings::PROMO_BELOW_HTML_KEY ], '{d}' ), 'Sanitizer must preserve promo placeholders.' );
wdc_delivery_messages_assert( 'A<br>B' === CheckoutDeliveryMessageSettings::normalize_soft_break_html( '<p>A</p><p>B</p>' ), 'Top-level editor paragraphs must normalize to soft breaks.' );
wdc_delivery_messages_assert( '<strong>A</strong><br><span style="color:#ff0000">B</span>' === CheckoutDeliveryMessageSettings::normalize_soft_break_html( '<p><strong>A</strong></p><p><span style="color:#ff0000">B</span></p>' ), 'Soft-break normalization must preserve rich inline formatting.' );
wdc_delivery_messages_assert( 'A<br>B<br>C' === CheckoutDeliveryMessageSettings::normalize_soft_break_html( '<p>A<br>B</p><p>C</p>' ), 'Existing br tags inside editor paragraphs must be preserved without double breaks.' );
wdc_delivery_messages_assert( 'A' === CheckoutDeliveryMessageSettings::normalize_soft_break_html( '<p>A</p>' ), 'A single editor paragraph must not leave a trailing break.' );
wdc_delivery_messages_assert( 'A<br><br>B' === CheckoutDeliveryMessageSettings::normalize_soft_break_html( '<p>A</p><p><br></p><p>B</p>' ), 'An intentional empty editor paragraph must render as one blank soft line.' );
wdc_delivery_messages_assert( 'A<br>B' === CheckoutDeliveryMessageSettings::normalize_soft_break_html( '<div>A</div><div>B</div>' ), 'Top-level editor div blocks must normalize to soft breaks when present.' );
wdc_delivery_messages_assert( 'A<br>B' === CheckoutDeliveryMessageSettings::normalize_soft_break_html( "A\nB" ), 'Plain editor newlines without HTML tags must normalize to soft breaks.' );

$settings->replace(
	array_merge(
		$settings->all(),
		array(
			CheckoutDeliveryMessageSettings::INFO_ENABLED_KEY => true,
			CheckoutDeliveryMessageSettings::INFO_HTML_KEY => '<p>Обычная доставка</p>',
			CheckoutDeliveryMessageSettings::PROMO_ENABLED_KEY => true,
			CheckoutDeliveryMessageSettings::PROMO_THRESHOLD_KOPECKS_KEY => 350000,
			CheckoutDeliveryMessageSettings::PROMO_TOTAL_BASIS_KEY => CheckoutDeliveryMessageSettings::BASIS_ALL_CART_ITEMS,
			CheckoutDeliveryMessageSettings::PROMO_BELOW_HTML_KEY => '<p>От {s} руб. Добавьте ещё {d} руб. {x}</p>',
			CheckoutDeliveryMessageSettings::PROMO_REACHED_HTML_KEY => '<p>Ваша корзина достигла {s} руб.</p>',
		)
	)
);

WC()->cart->contents_total = 0.0;
WC()->cart->shipping_packages = array( array( 'contents_cost' => 0.0 ) );
$below_zero = wdc_delivery_messages_render( $settings );
wdc_delivery_messages_assert( str_contains( $below_zero, 'wdc-checkout-delivery-info' ) && str_contains( $below_zero, 'Обычная доставка' ), 'Enabled info block must render before promo.' );
wdc_delivery_messages_assert( str_starts_with( $below_zero, '<tr class="wdc-checkout-delivery-messages-row"><th></th><td>' ), 'Checkout delivery messages source markup must be a valid table row for the WooCommerce shipping hook.' );
wdc_delivery_messages_assert( 1 === substr_count( $below_zero, 'class="wdc-checkout-delivery-messages"' ), 'Source row must contain exactly one delivery messages holder.' );
wdc_delivery_messages_assert( str_contains( $below_zero, '</td></tr>' ), 'Source row must contain a table cell holder for valid table markup.' );
wdc_delivery_messages_assert( str_contains( $below_zero, 'wdc-checkout-delivery-promo--below' ) && str_contains( $below_zero, 'От 3500 руб. Добавьте ещё 3500 руб. {x}' ), 'T=0 must render below state with full difference and keep unknown tokens.' );

WC()->cart->contents_total = 3499.0;
$below_one_ruble = wdc_delivery_messages_render( $settings );
wdc_delivery_messages_assert( str_contains( $below_one_ruble, 'Добавьте ещё 1 руб.' ), 'T=349900 kopecks must leave d=100 kopecks formatted as 1.' );

WC()->cart->contents_total = 3500.0;
$reached_equal = wdc_delivery_messages_render( $settings );
wdc_delivery_messages_assert( str_contains( $reached_equal, 'wdc-checkout-delivery-promo--reached' ), 'T=S must be reached.' );
wdc_delivery_messages_assert( ! str_contains( $reached_equal, 'Добавьте ещё' ), 'Reached state must not render below text.' );

WC()->cart->contents_total = 3500.01;
wdc_delivery_messages_assert( str_contains( wdc_delivery_messages_render( $settings ), 'wdc-checkout-delivery-promo--reached' ), 'T>S must be reached.' );
WC()->cart->contents_total = 5000.0;
wdc_delivery_messages_assert( str_contains( wdc_delivery_messages_render( $settings ), 'wdc-checkout-delivery-promo--reached' ), 'Large T must stay reached and d must not go negative.' );

$settings->set( CheckoutDeliveryMessageSettings::PROMO_TOTAL_BASIS_KEY, CheckoutDeliveryMessageSettings::BASIS_ALL_CART_ITEMS );
WC()->cart->contents_total = 4000.0;
WC()->cart->shipping_packages = array( array( 'contents_cost' => 3000.0 ) );
wdc_delivery_messages_assert( str_contains( wdc_delivery_messages_render( $settings ), 'wdc-checkout-delivery-promo--reached' ), 'all_cart_items basis must use full Woo cart contents total.' );
$settings->set( CheckoutDeliveryMessageSettings::PROMO_TOTAL_BASIS_KEY, CheckoutDeliveryMessageSettings::BASIS_SHIPPABLE_CART_ITEMS );
$shippable_below = wdc_delivery_messages_render( $settings );
wdc_delivery_messages_assert( str_contains( $shippable_below, 'wdc-checkout-delivery-promo--below' ) && str_contains( $shippable_below, 'Добавьте ещё 500 руб.' ), 'shippable_cart_items basis must use shippable package contents_cost aggregate.' );

$settings->set( CheckoutDeliveryMessageSettings::PROMO_TOTAL_BASIS_KEY, CheckoutDeliveryMessageSettings::BASIS_ALL_CART_ITEMS );
WC()->cart->contents_total = 1990.0;
$old_below = wdc_delivery_messages_render( $settings );
wdc_delivery_messages_assert( str_contains( $old_below, 'Добавьте ещё 1510 руб.' ), 'Initial below-threshold render must calculate d=1510 for the stale-state regression.' );
WC()->cart->contents_total = 3980.0;
$fresh_reached = wdc_delivery_messages_render( $settings );
wdc_delivery_messages_assert( str_contains( $fresh_reached, 'wdc-checkout-delivery-promo--reached' ) && ! str_contains( $fresh_reached, '1510' ) && ! str_contains( $fresh_reached, 'wdc-checkout-delivery-promo--below' ), 'Second checkout refresh render must contain only fresh reached state without stale below text.' );
WC()->cart->contents_total = 3200.0;
$coupon_aware = wdc_delivery_messages_render( $settings );
wdc_delivery_messages_assert( str_contains( $coupon_aware, 'wdc-checkout-delivery-promo--below' ) && str_contains( $coupon_aware, 'Добавьте ещё 300 руб.' ), 'Promo must use the typed effective total after coupons, not a raw subtotal.' );
WC()->cart->contents_total = 3600.0;
wdc_delivery_messages_assert( str_contains( wdc_delivery_messages_render( $settings ), 'wdc-checkout-delivery-promo--reached' ), 'Repeated render must use current Woo totals during checkout AJAX refresh.' );

$settings->set( CheckoutDeliveryMessageSettings::INFO_ENABLED_KEY, true );
$settings->set( CheckoutDeliveryMessageSettings::INFO_HTML_KEY, '<p>A</p><p>B</p>' );
$settings->set( CheckoutDeliveryMessageSettings::PROMO_ENABLED_KEY, false );
$soft_info = wdc_delivery_messages_render( $settings );
wdc_delivery_messages_assert( str_contains( $soft_info, '<div class="wdc-checkout-delivery-info">A<br>B</div>' ), 'Rendered info must contain literal br soft breaks for two editor paragraphs.' );
wdc_delivery_messages_assert( ! str_contains( $soft_info, '<p>A</p><p>B</p>' ), 'Rendered info must not depend on paragraph display CSS for editor Enter line breaks.' );

$settings->set( CheckoutDeliveryMessageSettings::INFO_ENABLED_KEY, false );
$settings->set( CheckoutDeliveryMessageSettings::PROMO_ENABLED_KEY, true );
$settings->set( CheckoutDeliveryMessageSettings::PROMO_THRESHOLD_KOPECKS_KEY, 350000 );
$settings->set( CheckoutDeliveryMessageSettings::PROMO_TOTAL_BASIS_KEY, CheckoutDeliveryMessageSettings::BASIS_ALL_CART_ITEMS );
$settings->set( CheckoutDeliveryMessageSettings::PROMO_BELOW_HTML_KEY, '<p>От {s} руб.</p><p>Добавьте {d} руб.</p>' );
$settings->set( CheckoutDeliveryMessageSettings::PROMO_REACHED_HTML_KEY, '<p>Достигли {s} руб.</p><p>Спасибо</p>' );
WC()->cart->contents_total = 3000.0;
$soft_promo_below = wdc_delivery_messages_render( $settings );
wdc_delivery_messages_assert( str_contains( $soft_promo_below, 'От 3500 руб.<br>Добавьте 500 руб.' ), 'Rendered below-threshold promo must substitute placeholders before paragraph soft-break normalization.' );
WC()->cart->contents_total = 3500.0;
$soft_promo_reached = wdc_delivery_messages_render( $settings );
wdc_delivery_messages_assert( str_contains( $soft_promo_reached, 'Достигли 3500 руб.<br>Спасибо' ), 'Rendered reached promo must normalize editor paragraphs after threshold placeholder substitution.' );

$settings->set( CheckoutDeliveryMessageSettings::PROMO_ENABLED_KEY, false );
wdc_delivery_messages_assert( ! str_contains( wdc_delivery_messages_render( $settings ), 'wdc-checkout-delivery-promo' ), 'Disabled promo must not render.' );
$settings->set( CheckoutDeliveryMessageSettings::INFO_ENABLED_KEY, false );
wdc_delivery_messages_assert( '' === wdc_delivery_messages_render( $settings ), 'Disabled info and promo must render nothing.' );

$renderer = new CheckoutDeliveryMessages( new CheckoutDeliveryMessageSettings( $settings ), new CheckoutCartTotalsResolver() );
$renderer->register();
$delivery_hooks = $GLOBALS['wdc_test_actions']['woocommerce_review_order_before_shipping'] ?? array();
wdc_delivery_messages_assert( 1 === count( $delivery_hooks ) && 4 === $delivery_hooks[0][1], 'Checkout delivery messages must register before the sort selector priority 5.' );

ob_start();
$admin->render_page();
$admin_html = (string) ob_get_clean();
wdc_delivery_messages_assert( str_contains( $admin_html, 'Тексты о доставке на checkout' ), 'Platform settings page must render checkout delivery messages section.' );
foreach ( array( 'wdc_checkout_delivery_info_html', 'wdc_checkout_delivery_promo_below_html', 'wdc_checkout_delivery_promo_reached_html' ) as $editor_id ) {
	wdc_delivery_messages_assert( isset( $GLOBALS['wdc_test_editors'][ $editor_id ] ), 'Platform settings page must render editor ' . $editor_id . '.' );
	wdc_delivery_messages_assert( false === ( $GLOBALS['wdc_test_editors'][ $editor_id ]['settings']['teeny'] ?? true ), 'Checkout delivery editors must use the normal TinyMCE toolbar so forecolor is visible.' );
	wdc_delivery_messages_assert( str_contains( (string) ( $GLOBALS['wdc_test_editors'][ $editor_id ]['settings']['tinymce']['toolbar1'] ?? '' ), 'underline' ), 'Checkout delivery editor toolbar must include underline.' );
	wdc_delivery_messages_assert( str_contains( (string) ( $GLOBALS['wdc_test_editors'][ $editor_id ]['settings']['tinymce']['toolbar1'] ?? '' ), 'forecolor' ), 'Checkout delivery editor toolbar must include text color.' );
	wdc_delivery_messages_assert( str_contains( (string) ( $GLOBALS['wdc_test_editors'][ $editor_id ]['settings']['tinymce']['toolbar1'] ?? '' ), 'link' ), 'Checkout delivery editor toolbar must include link controls.' );
}
wdc_delivery_messages_assert( 3 === count( array_unique( array_keys( $GLOBALS['wdc_test_editors'] ) ) ), 'Checkout delivery editor IDs must be unique.' );
wdc_delivery_messages_assert( str_contains( $admin_html, 'checkout_delivery_promo_threshold_rub' ) && str_contains( $admin_html, CheckoutDeliveryMessageSettings::PROMO_TOTAL_BASIS_KEY ), 'Platform settings page must render threshold input and basis select.' );
$roundtrip = $admin->sanitize_settings(
	array(
		CheckoutDeliveryMessageSettings::INFO_ENABLED_KEY => '',
		CheckoutDeliveryMessageSettings::INFO_HTML_KEY => '<p>Stored info</p>',
		CheckoutDeliveryMessageSettings::PROMO_ENABLED_KEY => '',
		CheckoutDeliveryMessageSettings::PROMO_BELOW_HTML_KEY => '<p>Stored below {d}</p>',
		CheckoutDeliveryMessageSettings::PROMO_REACHED_HTML_KEY => '<p>Stored reached {s}</p>',
	)
);
wdc_delivery_messages_assert( false === $roundtrip[ CheckoutDeliveryMessageSettings::INFO_ENABLED_KEY ] && '<p>Stored info</p>' === $roundtrip[ CheckoutDeliveryMessageSettings::INFO_HTML_KEY ], 'Disabling info checkbox must not clear submitted info HTML.' );
wdc_delivery_messages_assert( false === $roundtrip[ CheckoutDeliveryMessageSettings::PROMO_ENABLED_KEY ] && str_contains( $roundtrip[ CheckoutDeliveryMessageSettings::PROMO_BELOW_HTML_KEY ], 'Stored below' ), 'Disabling promo checkbox must not clear submitted promo HTML.' );

$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-rates.css' );
wdc_delivery_messages_assert( str_contains( $css, '.woocommerce-checkout .woocommerce-checkout-review-order-table tr.woocommerce-shipping-totals.shipping > th' ), 'Checkout heading spacing CSS must be scoped to the checkout shipping row heading.' );
wdc_delivery_messages_assert( str_contains( $css, 'tr.cart-discount + tr.woocommerce-shipping-totals.shipping > th' ) && str_contains( $css, 'tr.cart-discount + tr.woocommerce-shipping-totals.shipping > td' ), 'Coupon spacing must use an adjacent cart-discount to shipping-row selector.' );
wdc_delivery_messages_assert( str_contains( $css, '.wdc-checkout-delivery-messages-row' ) && str_contains( $css, '.wdc-checkout-sort-row' ) && str_contains( $css, 'display: none !important;' ), 'Source delivery message and sort rows must be hidden until JS relocates their content.' );
wdc_delivery_messages_assert( ! str_contains( $css, 'white-space: pre-line;' ), 'Checkout message CSS must not preserve source whitespace between rich editor HTML tags.' );
wdc_delivery_messages_assert( str_contains( $css, '.wdc-checkout-delivery-info,' ) && str_contains( $css, '.wdc-checkout-delivery-promo {' ) && str_contains( $css, 'margin: 0 0 10px;' ), 'Checkout message wrappers must keep logical spacing between info, promo, and sort controls.' );
wdc_delivery_messages_assert( str_contains( $css, '.wdc-checkout-delivery-info p' ) && str_contains( $css, '.wdc-checkout-delivery-promo p' ) && str_contains( $css, 'margin: 0;' ), 'Checkout message paragraphs must render as compact line-height rhythm.' );
wdc_delivery_messages_assert( ! str_contains( $css, "\nh3 {" ) && ! str_contains( $css, "\ntable {" ), 'Checkout spacing CSS must not add generic Woo heading/table overrides.' );
$sort_js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/frontend/checkout-sort.js' );
foreach ( array( 'function relocateDeliveryControls()', 'function relocateDeliveryMessages()', "'.wdc-checkout-delivery-messages-row'", "'.wdc-checkout-delivery-messages'", '.detach()', 'prependTo( $shippingCell )', 'insertAfter( $messages )', "'.wdc-checkout-sort-inline'" ) as $needle ) {
	wdc_delivery_messages_assert( str_contains( $sort_js, $needle ), 'Checkout JS must contain relocation contract: ' . $needle );
}
wdc_delivery_messages_assert( 2 <= substr_count( $sort_js, '$row.remove();' ), 'Checkout relocation must remove source table rows after moving messages and sort controls.' );
wdc_delivery_messages_assert( ! str_contains( $sort_js, '--relocated' ) && ! str_contains( $css, '--relocated' ), 'Relocation must not leave persistent hidden --relocated source rows between coupon and shipping rows.' );
wdc_delivery_messages_assert( ! str_contains( $sort_js, '.clone(' ), 'Checkout relocation must move nodes instead of cloning them.' );
wdc_delivery_messages_assert( str_contains( $sort_js, '$existing.length' ), 'Checkout delivery message relocation must be idempotent on repeated calls.' );

echo "Checkout delivery messages smoke passed.\n";
