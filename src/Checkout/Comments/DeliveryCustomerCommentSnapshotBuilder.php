<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\Comments;

use WallsShop\WDC\Domain\Quote\DeliveryRate;

defined( 'ABSPATH' ) || exit;

final class DeliveryCustomerCommentSnapshotBuilder {
	public function __construct(
		private ?DeliveryCustomerCommentNormalizer $normalizer = null
	) {
		$this->normalizer ??= new DeliveryCustomerCommentNormalizer();
	}

	/**
	 * @return array<int,array<string,string>>
	 */
	public function build( DeliveryRate $rate ): array {
		$raw = array();
		if ( is_array( $rate->meta['customer_link_comments'] ?? null ) ) {
			foreach ( $rate->meta['customer_link_comments'] as $comment ) {
				if ( is_array( $comment ) ) {
					$raw[] = array(
						'type' => 'link',
						'text_before' => (string) ( $comment['text_before'] ?? '' ),
						'label' => (string) ( $comment['label'] ?? '' ),
						'url' => (string) ( $comment['url'] ?? '' ),
						'text_after' => (string) ( $comment['text_after'] ?? '' ),
					);
				}
			}
		}
		foreach ( $rate->comments as $comment ) {
			$raw[] = $comment;
		}
		if ( '' !== trim( $rate->planned_delivery_comment ) ) {
			$raw[] = $rate->planned_delivery_comment;
		}

		return $this->normalizer->normalize( $raw );
	}

	/**
	 * @return array<int,string>
	 */
	public function materialized_template_comments( DeliveryRate $rate ): array {
		$templates = is_array( $rate->meta['customer_comment_templates'] ?? null ) ? $rate->meta['customer_comment_templates'] : array();
		$comments = array();
		foreach ( $templates as $template ) {
			if ( ! is_array( $template ) || 'money_text' !== (string) ( $template['type'] ?? '' ) ) {
				continue;
			}
			$source = (string) ( $template['money_source'] ?? 'price' );
			$money = 'crossed_or_price' === $source ? ( $rate->crossed_price ?? $rate->price ) : $rate->price;
			$text = trim( (string) ( $template['text_before'] ?? '' ) . $this->format_rubles( $money->get_kopecks() ) . (string) ( $template['text_after'] ?? '' ) );
			if ( '' !== $text && ! in_array( $text, $comments, true ) ) {
				$comments[] = $text;
			}
		}

		return $comments;
	}

	private function format_rubles( int $kopecks ): string {
		$rubles = intdiv( $kopecks, 100 );
		$rest = abs( $kopecks % 100 );

		return 0 === $rest ? (string) $rubles : $rubles . ',' . str_pad( (string) $rest, 2, '0', STR_PAD_LEFT );
	}
}
