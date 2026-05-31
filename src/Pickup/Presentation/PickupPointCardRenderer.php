<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Presentation;

use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;

defined( 'ABSPATH' ) || exit;

final class PickupPointCardRenderer {
	/**
	 * @param array<string,mixed> $point
	 */
	public function render( array $point, bool $include_change_button = false, bool $hidden = false ): string {
		$data      = $this->normalize( $point );
		$work_time = $data['work_time'];
		$classes   = 'wdc-pickup-point-card' . ( $include_change_button ? ' wdc-pickup-point-card--checkout' : '' );
		$hidden_attr = $hidden ? ' hidden' : '';
		$parts     = array();

		$parts[] = '<div class="' . esc_attr( $classes ) . '" data-wdc-pickup-selection data-wdc-pickup-card' . $hidden_attr . ' style="' . esc_attr( $this->card_style() ) . '">';
		$parts[] = '<div class="wdc-pickup-point-card__title" data-wdc-pickup-title style="' . esc_attr( $this->title_style() ) . '">' . esc_html( $data['title'] ) . '</div>';
		$parts[] = '<div class="wdc-pickup-point-card__body" style="' . esc_attr( $this->body_style() ) . '">';
		$parts[] = '<div class="wdc-pickup-point-card__city" data-wdc-pickup-city style="' . esc_attr( $this->line_style() ) . '">' . esc_html( $data['city_line'] ) . '</div>';
		$parts[] = '<div class="wdc-pickup-point-card__address" data-wdc-pickup-address style="' . esc_attr( $this->address_style() ) . '">' . esc_html( $data['address'] ) . '</div>';
		$parts[] = '<div class="wdc-pickup-point-card__work-time" data-wdc-pickup-work-time-block' . ( '' === $work_time ? ' hidden' : '' ) . ' style="' . esc_attr( $this->work_time_style() ) . '">';
		$parts[] = '<span style="' . esc_attr( $this->muted_style() ) . '">' . esc_html( __( 'Время работы:', 'walls-delivery-calc' ) ) . '</span>';
		$parts[] = '<span data-wdc-pickup-work-time>' . esc_html( $work_time ) . '</span>';
		$parts[] = '</div>';
		$parts[] = '</div>';

		if ( $include_change_button ) {
			$parts[] = '<button type="button" class="button wdc-pickup-point-card__change" data-wdc-pickup-open style="' . esc_attr( $this->button_style() ) . '">' . esc_html( __( 'Изменить пункт выдачи', 'walls-delivery-calc' ) ) . '</button>';
		}

		$parts[] = '</div>';

		return implode( '', $parts );
	}

	/**
	 * @param array<string,mixed> $point
	 * @return array{title:string,city_line:string,address:string,work_time:string}
	 */
	public function normalize( array $point ): array {
		$snapshot = is_array( $point['snapshot'] ?? null ) ? $point['snapshot'] : array();
		$carrier  = trim( (string) ( $point['carrier'] ?? $point['carrier_key'] ?? $snapshot['carrier'] ?? $snapshot['carrier_key'] ?? '' ) );
		$rate_id  = trim( (string) ( $point['rate_id'] ?? $point['shipping_method_id'] ?? $snapshot['rate_id'] ?? '' ) );
		$service  = trim( (string) ( $point['service_key'] ?? $snapshot['service_key'] ?? '' ) );
		$postcode = trim( (string) ( $point['postcode'] ?? $point['point_postcode'] ?? $snapshot['postcode'] ?? '' ) );
		$city     = trim( (string) ( $point['city'] ?? $point['city_name'] ?? $snapshot['city'] ?? $snapshot['city_name'] ?? '' ) );
		$address  = trim( (string) ( $point['address'] ?? $point['point_address'] ?? $snapshot['address'] ?? '' ) );
		$work_time = trim( (string) ( $point['point_work_time'] ?? $point['work_time'] ?? $snapshot['work_time'] ?? '' ) );

		return array(
			'title'     => $this->is_russian_post( $carrier, $rate_id, $service ) ? __( 'Отделение Почты России', 'walls-delivery-calc' ) : __( 'Пункт выдачи', 'walls-delivery-calc' ),
			'city_line' => $this->city_line( $postcode, $city ),
			'address'   => $address,
			'work_time' => $work_time,
		);
	}

	private function is_russian_post( string $carrier, string $rate_id, string $service ): bool {
		if ( in_array( $carrier, array( 'russian_post', RussianPostDomesticSettings::CARRIER_KEY ), true ) ) {
			return true;
		}

		return RussianPostDomesticSettings::PICKUP_SERVICE_KEY === $service
			|| RussianPostDomesticSettings::PICKUP_SERVICE_KEY === $rate_id
			|| str_starts_with( $rate_id, RussianPostDomesticSettings::PICKUP_SERVICE_KEY . ':' );
	}

	private function city_line( string $postcode, string $city ): string {
		$city = $this->city_with_type( $city );
		if ( '' !== $postcode && '' !== $city ) {
			return $postcode . ', ' . $city;
		}

		return '' !== $postcode ? $postcode : $city;
	}

	private function city_with_type( string $city ): string {
		if ( '' === $city || (bool) preg_match( '/^(г|город|п|пос|с|д|рп|пгт)\.?\s+/iu', $city ) ) {
			return $city;
		}

		return 'г ' . $city;
	}

	private function card_style(): string {
		return 'box-sizing:border-box;max-width:520px;margin:10px 0;padding:14px 16px;border:1px solid #d9e2ec;border-radius:8px;background:#fff;color:#1f2937;font-family:Arial,sans-serif;line-height:1.45;';
	}

	private function title_style(): string {
		return 'margin:0 0 10px;font-size:16px;font-weight:700;color:#111827;';
	}

	private function body_style(): string {
		return 'display:block;margin:0;';
	}

	private function line_style(): string {
		return 'margin:0 0 6px;color:#374151;';
	}

	private function address_style(): string {
		return 'margin:0 0 10px;color:#111827;';
	}

	private function work_time_style(): string {
		return 'display:grid;gap:2px;margin:0 0 12px;color:#111827;';
	}

	private function muted_style(): string {
		return 'color:#6b7280;';
	}

	private function button_style(): string {
		return 'margin-top:4px;';
	}
}
