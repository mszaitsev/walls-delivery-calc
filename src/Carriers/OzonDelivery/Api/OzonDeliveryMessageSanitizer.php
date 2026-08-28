<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Api;
defined( 'ABSPATH' ) || exit;
final class OzonDeliveryMessageSanitizer {
	public function sanitize( mixed $value, string $fallback = 'Ошибка Ozon Delivery API.' ): string {
		if ( ! is_scalar( $value ) ) { return $fallback; }
		$text = preg_replace( '/[\x00-\x1F\x7F]/u', ' ', (string) $value ) ?? '';
		$text = preg_replace( '/(?i)\b(client_secret|access_token|refresh_token|authorization|code)\b\s*[:=]\s*[^\s,;]+/', '$1=[скрыто]', $text ) ?? '';
		$text = preg_replace( '/(?i)bearer\s+[A-Za-z0-9._~+\/=\-]+/', 'Bearer [скрыто]', $text ) ?? '';
		$text = preg_replace( '/[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}/u', '[email скрыт]', $text ) ?? '';
		$text = preg_replace( '/\+?\d[\d\s()\-]{7,}\d/u', '[телефон скрыт]', $text ) ?? '';
		$text = trim( preg_replace( '/\s+/u', ' ', $text ) ?? '' );
		if ( '' === $text ) { return $fallback; }
		return function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 300 ) : substr( $text, 0, 300 );
	}
	public function code( mixed $value ): string {
		$value = is_scalar( $value ) ? (string) $value : '';
		return 1 === preg_match( '/^[A-Za-z0-9_.-]{1,80}$/', $value ) ? $value : '';
	}
}
