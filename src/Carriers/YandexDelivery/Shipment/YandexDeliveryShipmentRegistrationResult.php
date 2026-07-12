<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Shipment;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryShipmentRegistrationResult {
	/** @param array<string,mixed> $payload */
	public function __construct(
		public readonly array $payload,
		public readonly YandexDeliveryOfferCollection $offers,
		public readonly YandexDeliveryOffer $selected_offer,
		public readonly YandexDeliveryConfirmedRequest $confirmed_request,
		public readonly YandexDeliveryRequestInfo $request_info
	) {
	}
}
