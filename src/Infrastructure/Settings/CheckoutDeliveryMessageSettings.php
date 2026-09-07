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

	public static function normalize_soft_break_html( string $html ): string {
		$html = trim( $html );
		if ( '' === $html ) {
			return '';
		}

		$block_normalized = self::normalize_top_level_editor_blocks( $html );

		return self::normalize_text_fragment_line_breaks( null === $block_normalized ? $html : $block_normalized );
	}

	private static function normalize_top_level_editor_blocks( string $html ): ?string {
		preg_match_all(
			'#<(p|div)\b[^>]*>(.*?)</\1>#is',
			$html,
			$matches,
			PREG_SET_ORDER | PREG_OFFSET_CAPTURE
		);

		if ( array() === $matches ) {
			return null;
		}

		$lines = array();
		$offset = 0;
		foreach ( $matches as $match ) {
			$full = (string) $match[0][0];
			$start = (int) $match[0][1];
			$gap = substr( $html, $offset, $start - $offset );
			if ( '' !== trim( (string) $gap ) ) {
				return null;
			}

			$body = trim( (string) $match[2][0] );
			$lines[] = self::is_empty_soft_break_line( $body ) ? '' : $body;
			$offset = $start + strlen( $full );
		}

		if ( '' !== trim( substr( $html, $offset ) ) ) {
			return null;
		}

		return implode( '<br>', $lines );
	}

	private static function normalize_text_fragment_line_breaks( string $html ): string {
		$parts = function_exists( 'wp_html_split' )
			? wp_html_split( $html )
			: self::split_html_fragments( $html );

		foreach ( $parts as &$part ) {
			if ( '' === $part || '<' === $part[0] ) {
				continue;
			}
			$part = preg_replace( '/[ \t]*(?:(?:\r\n|\r|\n)[ \t]*)+/', '<br>', $part ) ?? $part;
		}
		unset( $part );

		return implode( '', $parts );
	}

	/**
	 * @return list<string>
	 */
	private static function split_html_fragments( string $html ): array {
		$parts = array();
		$length = strlen( $html );
		$offset = 0;

		while ( $offset < $length ) {
			$tag_start = strpos( $html, '<', $offset );
			if ( false === $tag_start ) {
				$parts[] = substr( $html, $offset );
				break;
			}

			if ( $tag_start > $offset ) {
				$parts[] = substr( $html, $offset, $tag_start - $offset );
			}

			$tag_end = self::find_html_tag_end( $html, $tag_start );
			if ( null === $tag_end ) {
				$parts[] = substr( $html, $tag_start );
				break;
			}

			$parts[] = substr( $html, $tag_start, $tag_end - $tag_start + 1 );
			$offset = $tag_end + 1;
		}

		return $parts;
	}

	private static function find_html_tag_end( string $html, int $start ): ?int {
		$length = strlen( $html );
		$quote = null;
		for ( $i = $start + 1; $i < $length; $i++ ) {
			$char = $html[ $i ];
			if ( null !== $quote ) {
				if ( $char === $quote ) {
					$quote = null;
				}
				continue;
			}
			if ( '"' === $char || "'" === $char ) {
				$quote = $char;
				continue;
			}
			if ( '>' === $char ) {
				return $i;
			}
		}

		return null;
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

	private static function is_empty_soft_break_line( string $html ): bool {
		$without_breaks = preg_replace( '#<br\s*/?>#i', '', $html ) ?? $html;
		$without_spaces = str_replace( '&nbsp;', '', $without_breaks );

		return '' === trim( $without_spaces );
	}
}
