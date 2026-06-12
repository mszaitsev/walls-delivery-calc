<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\WooCommerce;

use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Pickup\Presentation\PickupPointCardRenderer;

defined( 'ABSPATH' ) || exit;

final class PickupPointOrderDisplay {
	public function __construct(
		private ?PickupPointCardRenderer $card_renderer = null,
		private ?SettingsRepository $settings = null
	) {
		$this->card_renderer ??= new PickupPointCardRenderer();
	}

	public function register(): void {
		add_action( 'woocommerce_order_details_after_order_table', array( $this, 'render' ), 20 );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'render_email' ), 20, 4 );
	}

	public function render_by_order_id( mixed $order_id ): void {
		if ( function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( (int) $order_id );
			if ( is_object( $order ) ) {
				$this->render( $order );
			}
		}
	}

	public function render_email( mixed $order, mixed $sent_to_admin = false, mixed $plain_text = false, mixed $email = null ): void {
		unset( $sent_to_admin );
		if ( ! is_object( $order ) || $plain_text || ! $this->email_enabled( $email ) ) {
			return;
		}

		$this->render( $order );
	}

	public function render( mixed $order ): void {
		if ( ! is_object( $order ) ) {
			return;
		}
		$point = $this->point( $order );
		if ( array() === $point ) {
			return;
		}

		echo '<section class="wdc-order-pickup-point"><h2>' . esc_html( __( 'Пункт выдачи', 'walls-delivery-calc' ) ) . '</h2>';
		echo $this->card_renderer->render( $point );
		echo '</section>';
	}

	/**
	 * @return array<string,mixed>
	 */
	private function point( object $order ): array {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}
		$address = trim( (string) $order->get_meta( '_wdc_pickup_point_address', true ) );
		$code    = trim( (string) $order->get_meta( '_wdc_pickup_point_code', true ) );
		if ( '' === $address && '' === $code ) {
			return array();
		}

		$snapshot = $this->snapshot( $order );
		$comment  = $this->first_meaningful(
			$snapshot['description'] ?? '',
			$order->get_meta( '_wdc_platform_pickup_comment', true )
		);
		$work_time = $this->meaningful_text( $order->get_meta( '_wdc_platform_pickup_work_time', true ) );
		if ( '' === $work_time ) {
			unset( $snapshot['work_time'] );
		}

		return array(
			'address'         => $address,
			'postcode'        => trim( (string) $order->get_meta( '_wdc_pickup_point_postcode', true ) ),
			'type'            => trim( (string) $order->get_meta( '_wdc_pickup_point_type', true ) ),
			'point_type'      => trim( (string) $order->get_meta( '_wdc_pickup_point_type', true ) ),
			'code'            => $code,
			'point_code'      => $code,
			'carrier_key'     => trim( (string) $order->get_meta( '_wdc_platform_carrier_key', true ) ),
			'service_key'     => trim( (string) $order->get_meta( '_wdc_platform_service_key', true ) ),
			'pickup_family'   => $this->first_meaningful( $order->get_meta( '_wdc_pickup_family', true ), $snapshot['pickup_family'] ?? '' ),
			'point_type_label' => $this->first_meaningful( $order->get_meta( '_wdc_pickup_point_type_label', true ), $snapshot['point_type_label'] ?? '' ),
			'point_title'     => $this->first_meaningful( $order->get_meta( '_wdc_pickup_point_title', true ), $snapshot['point_title'] ?? '' ),
			'marker_type'     => $this->first_meaningful( $order->get_meta( '_wdc_pickup_marker_type', true ), $snapshot['marker_type'] ?? '' ),
			'rate_id'         => trim( (string) $order->get_meta( '_wdc_platform_rate_id', true ) ),
			'point_work_time' => $work_time,
			'description'     => $comment,
			'storage_notice'  => $this->meaningful_text( $snapshot['storage_notice'] ?? '' ),
			'cdek_code'       => (string) ( $snapshot['cdek_code'] ?? $code ),
			'snapshot'        => $snapshot,
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function snapshot( object $order ): array {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}
		$raw = (string) $order->get_meta( '_wdc_pickup_point_snapshot', true );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	private function email_enabled( mixed $email ): bool {
		$email_id = is_object( $email ) && isset( $email->id ) ? (string) $email->id : '';
		if ( '' === $email_id || ! $this->settings instanceof SettingsRepository ) {
			return false;
		}
		$enabled = $this->settings->get_array( 'pickup_email_card_enabled_emails', array() );
		$enabled = array_map( 'strval', $enabled );

		return in_array( $email_id, $enabled, true );
	}

	private function first_meaningful( mixed ...$values ): string {
		foreach ( $values as $value ) {
			$text = $this->meaningful_text( $value );
			if ( '' !== $text ) {
				return $text;
			}
		}

		return '';
	}

	private function meaningful_text( mixed $value ): string {
		if ( null === $value || is_array( $value ) || is_object( $value ) ) {
			return '';
		}
		$text = trim( (string) $value );
		if ( '' === $text ) {
			return '';
		}
		$normalized = str_replace( ',', '.', $text );
		if ( is_numeric( $normalized ) && 0.0 === (float) $normalized ) {
			return '';
		}

		return $text;
	}
}
