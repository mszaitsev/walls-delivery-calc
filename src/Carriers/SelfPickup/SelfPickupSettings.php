<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\SelfPickup;

use WallsShop\WDC\DeliveryServices\DeliveryServiceSettingsRepository;
use WallsShop\WDC\Domain\Common\MoneyParser;

defined( 'ABSPATH' ) || exit;

final class SelfPickupSettings {
	public const CARRIER_KEY = 'self_pickup';
	public const SERVICE_KEY = 'self_pickup';
	public const TITLE = 'Самовывоз';
	public const CARD_TITLE_KEY = 'self_pickup_card_title';
	public const ADDRESS_KEY = 'self_pickup_address';
	public const WORKING_HOURS_KEY = 'self_pickup_working_hours';
	public const CUSTOMER_COMMENT_ENABLED_KEY = 'self_pickup_customer_comment_enabled';
	public const CUSTOMER_COMMENT_KEY = 'self_pickup_customer_comment';
	public const DISCOUNT_ENABLED_KEY = 'self_pickup_discount_enabled';
	public const DISCOUNT_PERCENT_KEY = 'self_pickup_discount_percent';
	public const DISCOUNT_MINIMUM_KOPECKS_KEY = 'self_pickup_discount_minimum_kopecks';
	public const DISCOUNT_FEE_LABEL_KEY = 'self_pickup_discount_fee_label';
	public const DISCOUNT_COMMENT_ENABLED_KEY = 'self_pickup_discount_comment_enabled';
	public const DISCOUNT_COMMENT_KEY = 'self_pickup_discount_comment';

	public const DEFAULT_ADDRESS = 'Новосибирск, ул. Некрасова, д.63/1, 1 этаж (со стороны ул. Достоевского)';
	public const DEFAULT_CARD_TITLE = 'Самовывоз из магазина';
	public const DEFAULT_WORKING_HOURS = 'Ежедневно, 10:00-20:00';
	public const DEFAULT_CUSTOMER_COMMENT = 'Готовый заказ будет ждать вас 7 дней. Также можно забрать заказ любой доставкой-такси, которую заказывает покупатель самостоятельно.';
	public const DEFAULT_DISCOUNT_FEE_LABEL = 'Скидка {s}% за самовывоз. Подробнее в разделе "Акции"';
	public const DEFAULT_DISCOUNT_COMMENT = 'Скидка {s}% по акции';

	public function __construct(
		private DeliveryServiceSettingsRepository $settings
	) {
	}

	public function address( int $service_id ): string {
		return $this->text_setting( $service_id, self::ADDRESS_KEY, self::DEFAULT_ADDRESS );
	}

	public function card_title( int $service_id ): string {
		return $this->text_setting( $service_id, self::CARD_TITLE_KEY, self::DEFAULT_CARD_TITLE );
	}

	public function working_hours( int $service_id ): string {
		return $this->textarea_setting( $service_id, self::WORKING_HOURS_KEY, self::DEFAULT_WORKING_HOURS );
	}

	public function customer_comment_enabled( int $service_id ): bool {
		return true === $this->settings->get_setting( $service_id, self::CUSTOMER_COMMENT_ENABLED_KEY, true );
	}

	/** @return array<string,string> */
	public function customer_comment( int $service_id ): array {
		return $this->structured_comment( $service_id, self::CUSTOMER_COMMENT_KEY, self::DEFAULT_CUSTOMER_COMMENT );
	}

	public function discount_enabled( int $service_id ): bool {
		return true === $this->settings->get_setting( $service_id, self::DISCOUNT_ENABLED_KEY, true );
	}

	public function discount_percent( int $service_id ): float {
		return $this->decimal_setting( $service_id, self::DISCOUNT_PERCENT_KEY, 10.0, 0.0, 100.0 );
	}

	public function discount_minimum_kopecks( int $service_id ): int {
		$value = $this->settings->get_setting( $service_id, self::DISCOUNT_MINIMUM_KOPECKS_KEY, 350000 );
		return max( 0, (int) $value );
	}

	public function discount_fee_label( int $service_id ): string {
		return $this->text_setting( $service_id, self::DISCOUNT_FEE_LABEL_KEY, self::DEFAULT_DISCOUNT_FEE_LABEL );
	}

	public function discount_comment_enabled( int $service_id ): bool {
		return true === $this->settings->get_setting( $service_id, self::DISCOUNT_COMMENT_ENABLED_KEY, true );
	}

	/** @return array<string,string> */
	public function discount_comment( int $service_id ): array {
		return $this->structured_comment( $service_id, self::DISCOUNT_COMMENT_KEY, self::DEFAULT_DISCOUNT_COMMENT );
	}

	/** @return array{enabled:bool,percent:float,minimum_kopecks:int,fee_label:string} */
	public function discount_policy( int $service_id ): array {
		return array(
			'enabled' => $this->discount_enabled( $service_id ),
			'percent' => $this->discount_percent( $service_id ),
			'minimum_kopecks' => $this->discount_minimum_kopecks( $service_id ),
			'fee_label' => $this->discount_fee_label( $service_id ),
		);
	}

	/** @param array<string,mixed> $input */
	public function save_main_from_admin( int $service_id, array $input ): void {
		$this->settings->set_setting( $service_id, self::CARD_TITLE_KEY, $this->sanitize_text( $input[ self::CARD_TITLE_KEY ] ?? self::DEFAULT_CARD_TITLE ), 'string' );
		$this->settings->set_setting( $service_id, self::ADDRESS_KEY, $this->sanitize_text( $input[ self::ADDRESS_KEY ] ?? self::DEFAULT_ADDRESS ), 'string' );
		$this->settings->set_setting( $service_id, self::WORKING_HOURS_KEY, $this->sanitize_textarea( $input[ self::WORKING_HOURS_KEY ] ?? self::DEFAULT_WORKING_HOURS ), 'string' );
		$this->settings->set_setting( $service_id, self::CUSTOMER_COMMENT_ENABLED_KEY, ! empty( $input[ self::CUSTOMER_COMMENT_ENABLED_KEY ] ), 'bool' );
		$this->settings->set_setting( $service_id, self::CUSTOMER_COMMENT_KEY, $this->sanitize_structured_comment_from_input( $input, self::CUSTOMER_COMMENT_KEY, self::DEFAULT_CUSTOMER_COMMENT ), 'json' );
	}

	/** @param array<string,mixed> $input */
	public function save_discount_from_admin( int $service_id, array $input ): void {
		$this->settings->set_setting( $service_id, self::DISCOUNT_ENABLED_KEY, ! empty( $input[ self::DISCOUNT_ENABLED_KEY ] ), 'bool' );
		$this->settings->set_setting( $service_id, self::DISCOUNT_PERCENT_KEY, $this->sanitize_decimal( $input[ self::DISCOUNT_PERCENT_KEY ] ?? '10', 10.0, 0.0, 100.0 ), 'number' );
		$minimum = MoneyParser::numeric_to_kopecks( (string) ( $input[ self::DISCOUNT_MINIMUM_KOPECKS_KEY ] ?? '3500' ) );
		$this->settings->set_setting( $service_id, self::DISCOUNT_MINIMUM_KOPECKS_KEY, max( 0, (int) ( $minimum ?? 350000 ) ), 'number' );
		$this->settings->set_setting( $service_id, self::DISCOUNT_FEE_LABEL_KEY, $this->sanitize_text( $input[ self::DISCOUNT_FEE_LABEL_KEY ] ?? self::DEFAULT_DISCOUNT_FEE_LABEL ), 'string' );
		$this->settings->set_setting( $service_id, self::DISCOUNT_COMMENT_ENABLED_KEY, ! empty( $input[ self::DISCOUNT_COMMENT_ENABLED_KEY ] ), 'bool' );
		$this->settings->set_setting( $service_id, self::DISCOUNT_COMMENT_KEY, $this->sanitize_structured_comment_from_input( $input, self::DISCOUNT_COMMENT_KEY, self::DEFAULT_DISCOUNT_COMMENT ), 'json' );
	}

	private function text_setting( int $service_id, string $key, string $default ): string {
		$value = trim( (string) $this->settings->get_setting( $service_id, $key, $default ) );
		return '' !== $value ? $value : $default;
	}

	private function textarea_setting( int $service_id, string $key, string $default ): string {
		$value = trim( (string) $this->settings->get_setting( $service_id, $key, $default ) );
		return '' !== $value ? $value : $default;
	}

	private function decimal_setting( int $service_id, string $key, float $default, float $min, float $max ): float {
		return $this->sanitize_decimal( $this->settings->get_setting( $service_id, $key, $default ), $default, $min, $max );
	}

	/** @return array<string,string> */
	private function structured_comment( int $service_id, string $key, string $default_text ): array {
		$value = $this->settings->get_setting( $service_id, $key, array() );
		$value = is_array( $value ) ? $value : array();
		$text = $this->sanitize_textarea( $value['text'] ?? $default_text );
		return array(
			'text' => '' !== $text ? $text : $default_text,
			'text_before' => $this->sanitize_fragment( $value['text_before'] ?? '' ),
			'label' => $this->sanitize_text( $value['label'] ?? '' ),
			'url' => $this->safe_url( (string) ( $value['url'] ?? '' ) ),
			'text_after' => $this->sanitize_fragment( $value['text_after'] ?? '' ),
		);
	}

	/** @param array<string,mixed> $input @return array<string,string> */
	private function sanitize_structured_comment_from_input( array $input, string $prefix, string $default_text ): array {
		$value = is_array( $input[ $prefix ] ?? null ) ? $input[ $prefix ] : array();
		$text = $this->sanitize_textarea( $value['text'] ?? $default_text );
		return array(
			'text' => '' !== $text ? $text : $default_text,
			'text_before' => $this->sanitize_fragment( $value['text_before'] ?? '' ),
			'label' => $this->sanitize_text( $value['label'] ?? '' ),
			'url' => $this->safe_url( (string) ( $value['url'] ?? '' ) ),
			'text_after' => $this->sanitize_fragment( $value['text_after'] ?? '' ),
		);
	}

	private function sanitize_decimal( mixed $value, float $default, float $min, float $max ): float {
		$value = str_replace( ',', '.', trim( (string) wp_unslash( $value ) ) );
		$number = is_numeric( $value ) ? (float) $value : $default;
		return max( $min, min( $max, $number ) );
	}

	private function sanitize_text( mixed $value ): string {
		$value = wp_unslash( $value );
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( strip_tags( (string) $value ) );
	}

	private function sanitize_textarea( mixed $value ): string {
		$value = wp_unslash( $value );
		return function_exists( 'sanitize_textarea_field' ) ? sanitize_textarea_field( (string) $value ) : trim( strip_tags( (string) $value ) );
	}

	private function sanitize_fragment( mixed $value ): string {
		return substr( $this->sanitize_textarea( $value ), 0, 500 );
	}

	private function safe_url( string $url ): string {
		$url = trim( $url );
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		return in_array( $scheme, array( 'http', 'https' ), true ) ? substr( $url, 0, 1000 ) : '';
	}
}
