<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Comments;

defined( 'ABSPATH' ) || exit;

final class DeliveryCustomerCommentRenderer {
	public function __construct(
		private ?DeliveryCustomerCommentNormalizer $normalizer = null
	) {
		$this->normalizer ??= new DeliveryCustomerCommentNormalizer();
	}

	public function render_items( mixed $comments, string $item_class = 'wdc-order-delivery-comments__item' ): string {
		$html = '';
		foreach ( $this->normalizer->normalize( $comments ) as $comment ) {
			$html .= '<div class="' . esc_attr( $item_class ) . '" style="margin:4px 0;">' . $this->render_item( $comment ) . '</div>';
		}

		return $html;
	}

	/**
	 * @param array<string,string> $comment
	 */
	private function render_item( array $comment ): string {
		if ( 'link' !== (string) ( $comment['type'] ?? '' ) ) {
			return esc_html( (string) ( $comment['text'] ?? '' ) );
		}
		$url = $this->safe_url( (string) ( $comment['url'] ?? '' ) );
		$text = esc_html( (string) ( $comment['text_before'] ?? '' ) );
		if ( '' === $url ) {
			$text .= esc_html( (string) ( $comment['label'] ?? '' ) );
		} else {
			$text .= '<a class="wdc-platform-delivery-comment-link" href="' . esc_url( $url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( (string) ( $comment['label'] ?? '' ) ) . '</a>';
		}
		$text .= esc_html( (string) ( $comment['text_after'] ?? '' ) );

		return $text;
	}

	private function safe_url( string $url ): string {
		$scheme = strtolower( (string) parse_url( $url, PHP_URL_SCHEME ) );
		if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return '';
		}

		return $url;
	}
}
