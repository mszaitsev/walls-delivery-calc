<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Shipment;

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;
use WallsShop\WDC\Shipments\Allocation\ShipmentAllocation;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryShipmentRegistrationService {
	public function __construct(
		private YandexDeliveryShipmentPayloadBuilder $payload_builder,
		private YandexDeliveryShipmentClient $client,
		private ?YandexDeliveryEarliestOfferSelector $selector = null
	) {
	}

	/**
	 * @param array<string,mixed> $context
	 */
	public function register( ShipmentAllocation $allocation, array $context ): YandexDeliveryShipmentRegistrationResult {
		$payload = $this->payload_builder->build( $allocation, $context );
		$offers = $this->client->create_offers( $payload );
		$selected = ( $this->selector ?? new YandexDeliveryEarliestOfferSelector() )->select( $offers, (string) ( $payload['last_mile_policy'] ?? '' ) );
		if ( ! $selected instanceof YandexDeliveryOffer ) {
			throw new YandexDeliveryApiException(
				'Yandex offers/create returned no matching offers.',
				array( 'error_code' => 'empty_matching_offers', 'last_mile_policy' => (string) ( $payload['last_mile_policy'] ?? '' ) )
			);
		}
		$confirmed = $this->client->confirm_offer( $selected );
		try {
			$info = $this->client->request_info( $confirmed->request_id, is_array( $payload['places'] ?? null ) ? $payload['places'] : array() );
		} catch ( YandexDeliveryApiException $exception ) {
			throw new YandexDeliveryApiException(
				$exception->getMessage(),
				array_merge(
					$exception->details(),
					array(
						'error_code' => 'request_info_after_confirm_failed',
						'registration_phase' => 'request_info',
						'confirmed_request_id' => $confirmed->request_id,
						'selected_offer_id' => $selected->offer_id,
					)
				),
				0,
				$exception
			);
		}

		return new YandexDeliveryShipmentRegistrationResult( $payload, $offers, $selected, $confirmed, $info );
	}
}
