<?php
declare(strict_types=1);

namespace WallsShop\WDC\Infrastructure\Settings;

use WallsShop\WDC\Domain\Common\MoneyParser;

defined( 'ABSPATH' ) || exit;

final class CheckoutDeliveryMessageSettings {
	public const INFO_ENABLED_KEY = 'checkout_delivery_info_enabled';
	public const INFO_HTML_KEY = 'checkout_delivery_info_html';
	public const PROMO_ENABLED_KEY = 'checkout_delivery_promo_enabled';
	public const PROMO_THRESHOLD_KOPECKS_KEY = 'checkout_delivery_promo_threshold_kopecks';
	public const PROMO_TOTAL_BASIS_KEY = 'checkout_delivery_promo_total_basis';
	public const PROMO_BELOW_HTML_KEY = 'checkout_delivery_promo_below_html';
	public const PROMO_REACHED_HTML_KEY = 'checkout_delivery_promo_reached_html';

	public const BASIS_ALL_CART_ITEMS = 'all_cart_items';
	public const BASIS_SHIPPABLE_CART_ITEMS = 'shippable_cart_items';

	public const DEFAULT_PROMO_THRESHOLD_KOPECKS = 350000;
	public const DEFAULT_INFO_HTML = '';
	public const DEFAULT_PROMO_BELOW_HTML = '<p>При стоимости товаров от {s} руб. действует акция - компенсируем часть доставки. Для участия в акции добавьте в корзину товары ещё на {d} руб.!</p><p>Сейчас вы видите стоимость доставки без акции</p>';
	public const DEFAULT_PROMO_REACHED_HTML = '<p>Ваша корзина достигла суммы {s} руб. Мы с удовольствием компенсируем вам часть стоимости доставки - ниже показана акционная стоимость доставки!</p><p>Сейчас вы видите стоимость доставки по акции</p>';

	public function __construct( private SettingsRepository $settings ) {
	}

	public function info_enabled(): bool {
		return $this->settings->get_bool( self::INFO_ENABLED_KEY, false );
	}

	public function info_html(): string {
		return $this->html_setting( self::INFO_HTML_KEY, self::DEFAULT_INFO_HTML );
	}

	public function promo_enabled(): bool {
		return $this->settings->get_bool( self::PROMO_ENABLED_KEY, false );
	}

	public function promo_threshold_kopecks(): int {
		return max( 0, $this->settings->get_int( self::PROMO_THRESHOLD_KOPECKS_KEY, self::DEFAULT_PROMO_THRESHOLD_KOPECKS ) );
	}

	public function promo_total_basis(): string {
		$basis = $this->settings->get_string( self::PROMO_TOTAL_BASIS_KEY, self::BASIS_ALL_CART_ITEMS );

		return self::BASIS_SHIPPABLE_CART_ITEMS === $basis ? self::BASIS_SHIPPABLE_CART_ITEMS : self::BASIS_ALL_CART_ITEMS;
	}

	public function promo_below_html(): string {
		return $this->html_setting( self::PROMO_BELOW_HTML_KEY, self::DEFAULT_PROMO_BELOW_HTML );
	}

	public function promo_reached_html(): string {
		return $this->html_setting( self::PROMO_REACHED_HTML_KEY, self::DEFAULT_PROMO_REACHED_HTML );
	}

	public static function kopecks_from_admin_amount( mixed $value, int $default = self::DEFAULT_PROMO_THRESHOLD_KOPECKS ): int {
		if ( ! is_scalar( $value ) ) {
			return $default;
		}

		$kopecks = MoneyParser::numeric_to_kopecks( (string) $value );

		return null === $kopecks ? $default : max( 0, $kopecks );
	}

	public static function format_kopecks_amount( int $kopecks ): string {
		$kopecks = max( 0, $kopecks );
		$rubles = intdiv( $kopecks, 100 );
		$fraction = $kopecks % 100;

		return 0 === $fraction ? (string) $rubles : $rubles . '.' . str_pad( (string) $fraction, 2, '0', STR_PAD_LEFT );
	}

	public static function sanitize_html( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}

		$html = function_exists( 'wp_unslash' ) ? (string) wp_unslash( (string) $value ) : (string) $value;
		if ( function_exists( 'wp_kses' ) ) {
			return trim( wp_kses( $html, self::allowed_html() ) );
		}
		if ( function_exists( 'wp_kses_post' ) ) {
			return trim( wp_kses_post( $html ) );
		}

		return trim( self::fallback_sanitize_html( $html ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function allowed_html(): array {
		$allowed = function_exists( 'wp_kses_allowed_html' ) ? wp_kses_allowed_html( 'post' ) : array();
		foreach ( array( 'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del' ) as $tag ) {
			$allowed[ $tag ] ??= array();
		}
		$allowed['a'] = array_merge(
			$allowed['a'] ?? array(),
			array(
				'href'   => true,
				'title'  => true,
				'target' => true,
				'rel'    => true,
			)
		);
		$allowed['span'] = array_merge( $allowed['span'] ?? array(), array( 'style' => true ) );

		return $allowed;
	}

	private function html_setting( string $key, string $default ): string {
		return self::sanitize_html( $this->settings->get_string( $key, $default ) );
	}

	private static function fallback_sanitize_html( string $html ): string {
		$html = preg_replace( '#<script\b[^>]*>.*?</script>#is', '', $html ) ?? '';
		$html = preg_replace( '/\s+on[a-z]+\s*=\s*(".*?"|\'.*?\'|[^\s>]+)/is', '', $html ) ?? $html;
		$html = preg_replace( '/\s+href\s*=\s*(["\'])\s*javascript:[^"\']*\1/is', '', $html ) ?? $html;
		$html = preg_replace_callback(
			'/\s+style\s*=\s*(["\'])(.*?)\1/is',
			static function ( array $matches ): string {
				$style = strtolower( (string) $matches[2] );
				preg_match_all( '/(?:^|;)\s*(color|text-decoration)\s*:\s*([^;]+)/', $style, $properties, PREG_SET_ORDER );
				$kept = array();
				foreach ( $properties as $property ) {
					$value = trim( (string) $property[2] );
					if ( str_contains( $value, 'expression' ) || str_contains( $value, 'url(' ) ) {
						continue;
					}
					$kept[] = $property[1] . ': ' . $value;
				}

				return array() === $kept ? '' : ' style="' . htmlspecialchars( implode( '; ', $kept ), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' ) . '"';
			},
			$html
		) ?? $html;

		return strip_tags( $html, '<p><br><strong><b><em><i><u><s><del><a><span>' );
	}
}
