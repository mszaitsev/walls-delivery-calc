<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Presentation;

defined( 'ABSPATH' ) || exit;

final class PickupPointCardRenderer {
	private PickupPointPresentationResolver $presentation;

	public function __construct( ?PickupPointPresentationResolver $presentation = null ) {
		$this->presentation = $presentation ?? new PickupPointPresentationResolver();
	}

	/**
	 * @param array<string,mixed>|object $point
	 */
	public function render( array|object $point, bool $include_change_button = false, bool $hidden = false, bool $show_code_postcode_rows = true ): string {
		$data      = $this->normalize( $point );
		$work_time = $data['work_time'];
		$point_comment = $data['point_comment'];
		$description = $data['description'];
		$storage_notice = $data['storage_notice'];
		$customer_comments = $include_change_button ? array() : $data['customer_comments'];
		$code = $data['code'];
		$postcode = $data['postcode'];
		$show_code = $show_code_postcode_rows && ( $include_change_button ? $data['show_code_on_checkout'] : $data['show_code_on_order'] );
		$show_postcode = $show_code_postcode_rows && ( $include_change_button ? $data['show_postcode_on_checkout'] : $data['show_postcode_on_order'] );
		$classes   = 'wdc-pickup-point-card' . ( $include_change_button ? ' wdc-pickup-point-card--checkout' : '' ) . ( $hidden ? ' wdc-is-hidden' : '' );
		$hidden_attr = $hidden ? ' hidden' : '';
		$parts     = array();

		$parts[] = '<div class="' . esc_attr( $classes ) . '" data-wdc-pickup-selection data-wdc-pickup-card aria-hidden="' . esc_attr( $hidden ? 'true' : 'false' ) . '"' . $hidden_attr . ' style="' . esc_attr( $this->card_style( $hidden ) ) . '">';
		$parts[] = '<div class="wdc-pickup-point-card__title" data-wdc-pickup-title style="' . esc_attr( $this->title_style() ) . '"><span class="wdc-pickup-point-card__accent" aria-hidden="true" style="' . esc_attr( $this->accent_style() ) . '"></span><span data-wdc-pickup-title-text>' . esc_html( $data['title'] ) . '</span></div>';
		$parts[] = '<div class="wdc-pickup-point-card__body" style="' . esc_attr( $this->body_style() ) . '">';
		$parts[] = '<div class="wdc-pickup-point-card__address" data-wdc-pickup-address style="' . esc_attr( $this->address_style() ) . '">' . esc_html( $data['address_line'] ) . '</div>';
		$needs_placeholders = $include_change_button || $hidden;
		if ( $show_code && ( '' !== $code || $needs_placeholders ) ) {
			$parts[] = '<div class="wdc-pickup-point-card__code" data-wdc-pickup-code-block' . ( '' === $code ? ' hidden' : '' ) . ' style="' . esc_attr( $this->line_style() ) . '"><span style="' . esc_attr( $this->muted_style() ) . '">' . esc_html( __( 'Код пункта:', 'walls-delivery-calc' ) ) . '</span> <span data-wdc-pickup-code>' . esc_html( $code ) . '</span></div>';
		}
		if ( $show_postcode && ( '' !== $postcode || $needs_placeholders ) ) {
			$parts[] = '<div class="wdc-pickup-point-card__postcode" data-wdc-pickup-postcode-block' . ( '' === $postcode ? ' hidden' : '' ) . ' style="' . esc_attr( $this->line_style() ) . '"><span style="' . esc_attr( $this->muted_style() ) . '">' . esc_html( __( 'Индекс:', 'walls-delivery-calc' ) ) . '</span> <span data-wdc-pickup-postcode>' . esc_html( $postcode ) . '</span></div>';
		}
		if ( '' !== $work_time || $needs_placeholders ) {
			$parts[] = '<div class="wdc-pickup-point-card__work-time" data-wdc-pickup-work-time-block' . ( '' === $work_time ? ' hidden' : '' ) . ' style="' . esc_attr( $this->work_time_style() ) . '">';
			$parts[] = '<span style="' . esc_attr( $this->muted_style() ) . '">' . esc_html( __( 'Время работы:', 'walls-delivery-calc' ) ) . '</span>';
			$parts[] = '<span data-wdc-pickup-work-time>' . esc_html( $work_time ) . '</span>';
			$parts[] = '</div>';
		}
		if ( '' !== $point_comment || $needs_placeholders ) {
			$parts[] = '<div class="wdc-pickup-point-card__comment" data-wdc-pickup-comment' . ( '' === $point_comment ? ' hidden' : '' ) . ' style="' . esc_attr( $this->line_style() ) . '"><span style="' . esc_attr( $this->muted_style() ) . '">' . esc_html( __( 'Комментарий:', 'walls-delivery-calc' ) ) . '</span> <span data-wdc-pickup-comment-text>' . esc_html( $point_comment ) . '</span></div>';
		}
		if ( '' !== $description || $needs_placeholders ) {
			$parts[] = '<div class="wdc-pickup-point-card__description" data-wdc-pickup-description' . ( '' === $description ? ' hidden' : '' ) . ' style="' . esc_attr( $this->line_style() ) . '"><span style="' . esc_attr( $this->muted_style() ) . '">' . esc_html( __( 'Описание:', 'walls-delivery-calc' ) ) . '</span> <span data-wdc-pickup-description-text>' . esc_html( $description ) . '</span></div>';
		}
		if ( '' !== $storage_notice || $needs_placeholders ) {
			$parts[] = '<div class="wdc-pickup-point-card__storage" data-wdc-pickup-storage-notice' . ( '' === $storage_notice ? ' hidden' : '' ) . ' style="' . esc_attr( $this->storage_notice_style() ) . '">' . esc_html( $storage_notice ) . '</div>';
		}
		foreach ( $customer_comments as $comment ) {
			$parts[] = '<div class="wdc-pickup-point-card__customer-comment" style="' . esc_attr( $this->customer_comment_style() ) . '">' . esc_html( $comment ) . '</div>';
		}
		$parts[] = '</div>';

		if ( $include_change_button ) {
			$parts[] = '<button type="button" class="button wdc-pickup-point-card__change" data-wdc-pickup-open style="' . esc_attr( $this->button_style() ) . '">' . esc_html( __( 'Изменить пункт выдачи', 'walls-delivery-calc' ) ) . '</button>';
		}

		$parts[] = '</div>';

		return implode( '', $parts );
	}

	/**
	 * @param array<string,mixed>|object $point
	 * @return array{title:string,address_line:string,work_time:string,point_comment:string,description:string,storage_notice:string,customer_comments:array<int,string>,code:string,postcode:string,show_code_on_checkout:bool,show_postcode_on_checkout:bool,show_code_on_order:bool,show_postcode_on_order:bool}
	 */
	public function normalize( array|object $point ): array {
		$point    = $this->point_to_array( $point );
		$snapshot = is_array( $point['snapshot'] ?? null ) ? $point['snapshot'] : array();
		$postcode = trim( (string) ( $point['postcode'] ?? $point['point_postcode'] ?? $snapshot['postcode'] ?? '' ) );
		$city     = trim( (string) ( $point['city'] ?? $point['city_name'] ?? $snapshot['city'] ?? $snapshot['city_name'] ?? '' ) );
		$address  = trim( (string) ( $point['address'] ?? $point['point_address'] ?? $snapshot['address'] ?? '' ) );
		$work_time = $this->first_meaningful(
			$point['point_work_time'] ?? '',
			$point['work_time'] ?? '',
			$snapshot['work_time'] ?? ''
		);
		$point_comment = $this->first_meaningful(
			$point['point_comment'] ?? '',
			$snapshot['point_comment'] ?? ''
		);
		$description = $this->first_meaningful(
			$point['description'] ?? '',
			$snapshot['description'] ?? ''
		);
		if ( '' !== $point_comment && $this->same_text( $description, $point_comment ) ) {
			$description = '';
		}
		$presentation = $this->presentation->resolve( $point );
		$storage_notice = $presentation['storage_notice'];
		$code = trim( (string) ( $point['point_code'] ?? $point['code'] ?? $point['cdek_code'] ?? $snapshot['point_code'] ?? $snapshot['code'] ?? $snapshot['cdek_code'] ?? '' ) );

		return array(
			'title'     => $presentation['card_title'],
			'address_line' => '' !== $address ? $address : $this->city_line( $postcode, $city ),
			'work_time' => $work_time,
			'point_comment' => $point_comment,
			'description' => $description,
			'storage_notice' => $storage_notice,
			'customer_comments' => $this->customer_comments( $point, $snapshot ),
			'code' => $code,
			'postcode' => $postcode,
			'show_code_on_checkout' => $presentation['show_code_on_checkout'],
			'show_postcode_on_checkout' => $presentation['show_postcode_on_checkout'],
			'show_code_on_order' => $presentation['show_code_on_order'],
			'show_postcode_on_order' => $presentation['show_postcode_on_order'],
		);
	}

	/**
	 * @param array<string,mixed>|object $point
	 * @return array<string,mixed>
	 */
	private function point_to_array( array|object $point ): array {
		if ( is_array( $point ) ) {
			return $point;
		}
		if ( method_exists( $point, 'to_array' ) ) {
			$data = $point->to_array();
			if ( is_array( $data ) ) {
				return $data;
			}
		}

		return get_object_vars( $point );
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

	private function first_meaningful( mixed ...$values ): string {
		foreach ( $values as $value ) {
			$text = $this->meaningful_text( $value );
			if ( '' !== $text ) {
				return $text;
			}
		}

		return '';
	}

	private function same_text( string $left, string $right ): bool {
		return '' !== $left && '' !== $right && trim( $left ) === trim( $right );
	}

	/**
	 * @param array<string,mixed> $point
	 * @param array<string,mixed> $snapshot
	 * @return array<int,string>
	 */
	private function customer_comments( array $point, array $snapshot ): array {
		$raw = is_array( $point['customer_comments'] ?? null ) ? $point['customer_comments'] : ( is_array( $snapshot['customer_comments'] ?? null ) ? $snapshot['customer_comments'] : array() );
		$comments = array();
		foreach ( $raw as $comment ) {
			if ( ! is_scalar( $comment ) ) {
				continue;
			}
			$text = trim( (string) $comment );
			if ( '' === $text ) {
				continue;
			}
			$text = substr( $text, 0, 500 );
			if ( in_array( $text, $comments, true ) ) {
				continue;
			}
			$comments[] = $text;
		}

		return $comments;
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

	private function card_style( bool $hidden = false ): string {
		return ( $hidden ? 'display:none;' : '' ) . 'box-sizing:border-box;width:100%;max-width:none;margin:10px 0;padding:14px 16px;border:1px solid #d9e2ec;border-radius:8px;background:#fff;color:#1f2937;font-family:Arial,sans-serif;line-height:1.45;';
	}

	private function title_style(): string {
		return 'display:flex;align-items:center;gap:8px;margin:0 0 10px;font-size:16px;font-weight:700;color:#111827;';
	}

	private function accent_style(): string {
		return 'display:inline-block;flex:0 0 auto;width:10px;height:10px;border-radius:50%;background:#16a34a;box-shadow:0 0 0 4px rgba(22,163,74,0.14);';
	}

	private function body_style(): string {
		return 'display:block;margin:0;';
	}

	private function line_style(): string {
		return 'margin:0 0 6px;color:#374151;';
	}

	private function address_style(): string {
		return 'margin:0 0 10px;color:#111827;overflow-wrap:anywhere;word-break:normal;';
	}

	private function work_time_style(): string {
		return 'display:grid;gap:2px;margin:0 0 12px;color:#111827;';
	}

	private function storage_notice_style(): string {
		return 'margin:0 0 6px;color:#b91c1c;font-weight:700;';
	}

	private function customer_comment_style(): string {
		return 'margin:6px 0 0;color:#374151;overflow-wrap:anywhere;word-break:normal;';
	}

	private function muted_style(): string {
		return 'color:#6b7280;';
	}

	private function button_style(): string {
		return 'margin-top:4px;';
	}
}
