<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Shipment;

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiClient;
use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryShipmentClient {
	public function __construct(
		private YandexDeliveryApiClient $api
	) {
	}

	/** @param array<string,mixed> $payload */
	public function create_offers( array $payload ): YandexDeliveryOfferCollection {
		return YandexDeliveryOfferCollection::from_api_response( $this->api->offersCreate( $payload ) );
	}

	public function confirm_offer( YandexDeliveryOffer|string $offer ): YandexDeliveryConfirmedRequest {
		$offer_id = $offer instanceof YandexDeliveryOffer ? $offer->offer_id : trim( $offer );
		if ( '' === $offer_id ) {
			throw new YandexDeliveryApiException( 'Yandex offer_id is required.', array( 'error_code' => 'offer_id_missing' ) );
		}
		$confirmed = YandexDeliveryConfirmedRequest::from_api_response( $this->api->offersConfirm( array( 'offer_id' => $offer_id ) ), $offer_id );
		if ( '' === $confirmed->request_id ) {
			throw new YandexDeliveryApiException( 'Yandex offers/confirm did not return request_id.', array( 'error_code' => 'request_id_missing', 'offer_id' => $offer_id, 'response' => $confirmed->raw ) );
		}

		return $confirmed;
	}

	/**
	 * @param array<int,array<string,mixed>> $temporary_places
	 */
	public function request_info( string $request_id, array $temporary_places = array() ): YandexDeliveryRequestInfo {
		$request_id = trim( $request_id );
		if ( '' === $request_id ) {
			throw new YandexDeliveryApiException( 'Yandex request_id is required.', array( 'error_code' => 'request_id_missing' ) );
		}
		$info = YandexDeliveryRequestInfo::from_api_response( $this->api->requestInfo( array( 'request_id' => $request_id ) ), $temporary_places );
		if ( '' === $info->request_id ) {
			throw new YandexDeliveryApiException( 'Yandex request/info did not return request_id.', array( 'error_code' => 'request_info_id_missing', 'request_id' => $request_id, 'response' => $info->raw ) );
		}

		return $info;
	}

	public function request_history( string $request_id ): YandexDeliveryRequestHistory {
		$request_id = trim( $request_id );
		if ( '' === $request_id ) {
			throw new YandexDeliveryApiException( 'Yandex request_id is required.', array( 'error_code' => 'request_id_missing' ) );
		}

		return YandexDeliveryRequestHistory::from_api_response( $this->api->requestHistory( array( 'request_id' => $request_id ) ), $request_id );
	}

	public function cancel_request( string $request_id ): YandexDeliveryShipmentState {
		$request_id = trim( $request_id );
		if ( '' === $request_id ) {
			throw new YandexDeliveryApiException( 'Yandex request_id is required.', array( 'error_code' => 'request_id_missing' ) );
		}

		return YandexDeliveryShipmentState::from_api_response( $this->api->requestCancel( array( 'request_id' => $request_id ) ), $request_id );
	}
}
