<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Comments;

defined( 'ABSPATH' ) || exit;

final class DeliveryCustomerCommentNormalizer {
	/**
	 * @return array<int,array<string,string>>
	 */
	public function normalize( mixed $raw ): array {
		if ( is_string( $raw ) && '' !== trim( $raw ) ) {
			$decoded = json_decode( $raw, true );
			$raw = is_array( $decoded ) ? $decoded : array( $raw );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$items = $this->is_list( $raw ) ? $raw : array( $raw );
		$result = array();
		$seen = array();
		foreach ( $items as $item ) {
			$normalized = $this->normalize_item( $item );
			if ( array() === $normalized ) {
				continue;
			}
			$identity = $this->identity( $normalized );
			if ( isset( $seen[ $identity ] ) ) {
				continue;
			}
			$seen[ $identity ] = true;
			$result[] = $normalized;
		}

		return $result;
	}

	/**
	 * @return array<string,string>
	 */
	private function normalize_item( mixed $item ): array {
		if ( is_scalar( $item ) ) {
			$text = $this->bounded_text( $item );
			return '' === $text ? array() : array( 'type' => 'text', 'text' => $text );
		}
		if ( ! is_array( $item ) ) {
			return array();
		}
		$type = (string) ( $item['type'] ?? '' );
		if ( 'link' === $type ) {
			$label = $this->bounded_text( $item['label'] ?? '' );
			$url = $this->bounded_url( $item['url'] ?? '' );
			if ( '' === $label || '' === $url ) {
				return array();
			}
			return array(
				'type' => 'link',
				'text_before' => $this->bounded_fragment( $item['text_before'] ?? '' ),
				'label' => $label,
				'url' => $url,
				'text_after' => $this->bounded_fragment( $item['text_after'] ?? '' ),
			);
		}
		if ( 'text' === $type || array_key_exists( 'text', $item ) ) {
			$text = $this->bounded_text( $item['text'] ?? '' );
			return '' === $text ? array() : array( 'type' => 'text', 'text' => $text );
		}

		return array();
	}

	private function bounded_text( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return substr( trim( (string) $value ), 0, 500 );
	}

	private function bounded_url( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return substr( trim( (string) $value ), 0, 1000 );
	}

	private function bounded_fragment( mixed $value ): string {
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		return substr( (string) $value, 0, 500 );
	}

	/**
	 * @param array<string,string> $item
	 */
	private function identity( array $item ): string {
		if ( 'link' === (string) ( $item['type'] ?? '' ) ) {
			return implode( "\n", array( 'link', $item['text_before'] ?? '', $item['label'] ?? '', $item['url'] ?? '', $item['text_after'] ?? '' ) );
		}

		return 'text' . "\n" . (string) ( $item['text'] ?? '' );
	}

	/**
	 * @param array<mixed> $value
	 */
	private function is_list( array $value ): bool {
		$expected = 0;
		foreach ( $value as $key => $_ ) {
			if ( $key !== $expected ) {
				return false;
			}
			++$expected;
		}

		return true;
	}
}
