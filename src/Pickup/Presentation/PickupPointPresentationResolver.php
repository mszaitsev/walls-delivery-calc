<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Presentation;

defined( 'ABSPATH' ) || exit;

final class PickupPointPresentationResolver {
	/**
	 * @param array<string,mixed>|object $point
	 * @return array{card_title:string,point_type_label:string,show_code_on_checkout:bool,show_postcode_on_checkout:bool,show_code_on_order:bool,show_postcode_on_order:bool,storage_notice:string,marker_type:string,pickup_family:string}
	 */
	public function resolve( array|object $point ): array {
		$data = $this->point_to_array( $point );
		$snapshot = is_array( $data['snapshot'] ?? null ) ? $data['snapshot'] : array();
		$carrier = strtolower( trim( (string) ( $data['carrier_key'] ?? $data['carrier'] ?? $snapshot['carrier_key'] ?? $snapshot['carrier'] ?? '' ) ) );
		$service = strtolower( trim( (string) ( $data['service_key'] ?? $snapshot['service_key'] ?? $carrier ) ) );
		$family = trim( (string) ( $data['pickup_family'] ?? $snapshot['pickup_family'] ?? '' ) );
		if ( '' === $family ) {
			$family_source = '' !== $carrier ? $carrier : $service;
			$family = '' !== $family_source ? $family_source . ':pickup' : 'pickup';
		}
		$type = strtoupper( trim( (string) ( $data['point_type'] ?? $data['type'] ?? $snapshot['point_type'] ?? $snapshot['type'] ?? '' ) ) );
		$storage_notice = $this->meaningful_text( $data['storage_notice'] ?? $snapshot['storage_notice'] ?? '' );
		$card_title = $this->meaningful_text( $data['point_title'] ?? $data['card_title'] ?? $snapshot['point_title'] ?? $snapshot['card_title'] ?? '' );
		$type_label = $this->meaningful_text( $data['point_type_label'] ?? $snapshot['point_type_label'] ?? '' );
		$marker_type = $this->meaningful_text( $data['marker_type'] ?? $snapshot['marker_type'] ?? '' );

		if ( '' === $card_title || '' === $type_label || '' === $marker_type || '' === $storage_notice ) {
			$builtin = $this->builtin( $carrier, $service, $family, $type );
			$card_title = '' !== $card_title ? $card_title : $builtin['card_title'];
			$type_label = '' !== $type_label ? $type_label : $builtin['point_type_label'];
			$marker_type = '' !== $marker_type ? $marker_type : $builtin['marker_type'];
			$storage_notice = '' !== $storage_notice ? $storage_notice : $builtin['storage_notice'];
		}

		return array(
			'card_title' => $card_title,
			'point_type_label' => $type_label,
			'show_code_on_checkout' => $this->bool_value( $data['show_code_on_checkout'] ?? $snapshot['show_code_on_checkout'] ?? false ),
			'show_postcode_on_checkout' => $this->bool_value( $data['show_postcode_on_checkout'] ?? $snapshot['show_postcode_on_checkout'] ?? false ),
			'show_code_on_order' => $this->bool_value( $data['show_code_on_order'] ?? $snapshot['show_code_on_order'] ?? false ),
			'show_postcode_on_order' => $this->bool_value( $data['show_postcode_on_order'] ?? $snapshot['show_postcode_on_order'] ?? false ),
			'storage_notice' => $storage_notice,
			'marker_type' => $marker_type,
			'pickup_family' => $family,
		);
	}

	/**
	 * @return array{card_title:string,point_type_label:string,storage_notice:string,marker_type:string}
	 */
	private function builtin( string $carrier, string $service, string $family, string $type ): array {
		if ( 'cdek' === $carrier || 'cdek' === $service || 'cdek:pickup' === $family ) {
			if ( 'POSTAMAT' === $type ) {
				return array(
					'card_title' => __( 'Постамат СДЭК', 'walls-delivery-calc' ),
					'point_type_label' => __( 'Постамат', 'walls-delivery-calc' ),
					'storage_notice' => __( 'Срок хранения 3 дня', 'walls-delivery-calc' ),
					'marker_type' => 'postamat',
				);
			}

			return array(
				'card_title' => __( 'Пункт выдачи СДЭК', 'walls-delivery-calc' ),
				'point_type_label' => __( 'Пункт выдачи', 'walls-delivery-calc' ),
				'storage_notice' => '',
				'marker_type' => 'pickup',
			);
		}

		if ( 'russian_post_domestic' === $carrier || 'russian_post' === $carrier || 'russian_post_domestic' === $service || 'russian_post_domestic:pickup' === $family ) {
			if ( 'APS' === $type ) {
				return array(
					'card_title' => __( 'Почтомат Почты России', 'walls-delivery-calc' ),
					'point_type_label' => __( 'Почтомат', 'walls-delivery-calc' ),
					'storage_notice' => '',
					'marker_type' => 'postamat',
				);
			}

			return array(
				'card_title' => __( 'Отделение Почты России', 'walls-delivery-calc' ),
				'point_type_label' => __( 'Пункт выдачи', 'walls-delivery-calc' ),
				'storage_notice' => '',
				'marker_type' => 'pickup',
			);
		}

		return array(
			'card_title' => __( 'Пункт выдачи', 'walls-delivery-calc' ),
			'point_type_label' => __( 'Пункт выдачи', 'walls-delivery-calc' ),
			'storage_notice' => '',
			'marker_type' => 'pickup',
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

	private function bool_value( mixed $value ): bool {
		return true === $value || 1 === $value || '1' === $value || 'true' === strtolower( (string) $value ) || 'yes' === strtolower( (string) $value );
	}
}
