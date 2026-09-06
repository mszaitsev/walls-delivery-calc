<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Runtime;

use WallsShop\WDC\Carriers\Contracts\CarrierAdapterInterface;
use WallsShop\WDC\Carriers\SelfPickup\SelfPickupDiscountService;
use WallsShop\WDC\Carriers\SelfPickup\SelfPickupSettings;
use WallsShop\WDC\Domain\Carrier\CarrierCapabilities;
use WallsShop\WDC\Domain\Carrier\CarrierIdentity;
use WallsShop\WDC\Domain\Common\DateRange;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Quote\DeliveryQuote;
use WallsShop\WDC\Domain\Quote\DeliveryRate;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

final class SelfPickupCarrier implements CarrierAdapterInterface {
	public function __construct(
		private SelfPickupSettings $settings,
		private ?SelfPickupDiscountService $discounts = null
	) {
	}

	public function get_identity(): CarrierIdentity {
		return new CarrierIdentity( SelfPickupSettings::CARRIER_KEY, SelfPickupSettings::TITLE, 'fixed', true );
	}

	public function get_capabilities(): CarrierCapabilities {
		return new CarrierCapabilities( supports_quotes: true, supports_pickup_delivery: true, supports_international: true );
	}

	public function supports_country( string $countryCode ): bool {
		return '' !== strtoupper( trim( $countryCode ) );
	}

	public function quote( QuoteRequest $request ): DeliveryQuote {
		$service_id = max( 0, (int) ( $request->customer_context['service_id'] ?? 0 ) );
		$service_title = trim( (string) ( $request->customer_context['service_title'] ?? SelfPickupSettings::TITLE ) );
		$title = '' !== $service_title ? $service_title : SelfPickupSettings::TITLE;
		$snapshot = $this->fixed_location_snapshot( $service_id, $title );
		$comments = array();
		if ( $service_id > 0 && $this->settings->customer_comment_enabled( $service_id ) ) {
			$comments[] = $this->settings->customer_comment( $service_id );
		}
		if ( $service_id > 0 && $this->settings->discount_comment_enabled( $service_id ) && $this->discounts instanceof SelfPickupDiscountService ) {
			$discount = $this->discounts->current_discount_result();
			if ( $discount->eligible ) {
				$comments[] = $this->discount_comment( $service_id, $discount->percent );
			}
		}
		$comment_payload = $this->comment_payload( $comments );

		$rate = new DeliveryRate(
			SelfPickupSettings::SERVICE_KEY,
			SelfPickupSettings::CARRIER_KEY,
			SelfPickupSettings::TITLE,
			SelfPickupSettings::SERVICE_KEY,
			$title,
			SelfPickupSettings::SERVICE_KEY,
			$title,
			DeliveryType::PICKUP,
			$title,
			Money::from_kopecks( 0 ),
			null,
			null,
			DateRange::range( null, null ),
			'',
			'',
			$comment_payload['plain_comments'],
			false,
			'',
			false,
			false,
			array(
				'preserve_rate_title' => true,
				'skip_service_post_processing' => true,
				'api_base_price_rub' => 0,
				'fixed_pickup_point_snapshot' => $snapshot,
				'no_pickup_selection' => true,
				'customer_link_comments' => $comment_payload['link_comments'],
				'customer_comments' => $comment_payload['customer_comments'],
				'order_recalculation_requires_address' => false,
				'non_shipment_state' => array(
					'message' => 'Самовывоз покупателем',
					'can_create' => false,
					'can_attach_manual' => false,
					'can_update_status' => false,
					'can_cancel' => false,
					'can_remove_from_order' => false,
				),
			),
			Money::from_kopecks( 0 ),
			DateRange::range( null, null )
		);

		return new DeliveryQuote( 'self_pickup_' . md5( $request->country_code . '|' . $title ), SelfPickupSettings::CARRIER_KEY, $request->destination, $request->package, array( $rate ), true, '', '', false, 'manual' );
	}

	/** @return array<string,mixed> */
	private function fixed_location_snapshot( int $service_id, string $title ): array {
		$address = $service_id > 0 ? $this->settings->address( $service_id ) : SelfPickupSettings::DEFAULT_ADDRESS;
		$work_time = $service_id > 0 ? $this->settings->working_hours( $service_id ) : SelfPickupSettings::DEFAULT_WORKING_HOURS;

		return array(
			'carrier_key' => SelfPickupSettings::CARRIER_KEY,
			'service_key' => SelfPickupSettings::SERVICE_KEY,
			'pickup_family' => SelfPickupSettings::CARRIER_KEY . ':pickup',
			'point_type' => 'store_pickup',
			'point_type_label' => 'Самовывоз из магазина',
			'point_title' => $title,
			'card_title' => 'Самовывоз из магазина',
			'point_name' => $title,
			'point_address' => $address,
			'address' => $address,
			'point_work_time' => $work_time,
			'work_time' => $work_time,
			'marker_type' => 'pickup',
			'fixed_fulfillment_location' => true,
			'requires_pickup_point' => false,
			'selectable' => false,
		);
	}

	/** @return array<string,string> */
	private function discount_comment( int $service_id, float $percent ): array {
		$comment = $this->settings->discount_comment( $service_id );
		$percent_label = $this->percent_label( $percent );
		foreach ( array( 'text', 'text_before', 'label', 'text_after' ) as $key ) {
			if ( isset( $comment[ $key ] ) ) {
				$comment[ $key ] = str_replace( '{s}', $percent_label, $comment[ $key ] );
			}
		}

		return $comment;
	}

	private function percent_label( float $percent ): string {
		return rtrim( rtrim( number_format( $percent, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * @param array<int,array<string,string>> $comments
	 * @return array{plain_comments:array<int,string>,link_comments:array<int,array<string,string>>,customer_comments:array<int,array<string,string>>}
	 */
	private function comment_payload( array $comments ): array {
		$plain = array();
		$link_comments = array();
		$customer_comments = array();
		foreach ( $comments as $comment ) {
			$link = '' !== trim( $comment['url'] ?? '' ) && '' !== trim( $comment['label'] ?? '' );
			if ( $link ) {
				$link_comments[] = array(
					'text_before' => (string) ( $comment['text_before'] ?? '' ),
					'label' => (string) ( $comment['label'] ?? '' ),
					'url' => (string) ( $comment['url'] ?? '' ),
					'text_after' => (string) ( $comment['text_after'] ?? '' ),
				);
				$customer_comments[] = array(
					'type' => 'link',
					'text_before' => (string) ( $comment['text_before'] ?? '' ),
					'label' => (string) ( $comment['label'] ?? '' ),
					'url' => (string) ( $comment['url'] ?? '' ),
					'text_after' => (string) ( $comment['text_after'] ?? '' ),
				);
				continue;
			}
			$text = trim( (string) ( $comment['text'] ?? '' ) );
			if ( '' === $text ) {
				continue;
			}
			$plain[] = $text;
			$customer_comments[] = array( 'type' => 'text', 'text' => $text );
		}

		return array(
			'plain_comments' => $plain,
			'link_comments' => $link_comments,
			'customer_comments' => $customer_comments,
		);
	}

}
