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
		$api_response = $this->api->requestInfo( array( 'request_id' => $request_id ) );
		$body = is_array( $api_response['body'] ?? null ) ? $api_response['body'] : $api_response;
		if ( ! is_array( $body['request'] ?? null ) ) {
			throw new YandexDeliveryApiException( 'Yandex request/info did not return request object.', array( 'error_code' => 'request_info_request_missing', 'request_id' => $request_id, 'response' => $body ) );
		}
		$info = YandexDeliveryRequestInfo::from_api_response( $api_response, $temporary_places );
		if ( '' === $info->request_id ) {
			throw new YandexDeliveryApiException( 'Yandex request/info did not return request_id.', array( 'error_code' => 'request_info_id_missing', 'request_id' => $request_id, 'response' => $info->raw ) );
		}
		if ( $info->request_id !== $request_id ) {
			throw new YandexDeliveryApiException( 'Yandex request/info returned unexpected request_id.', array( 'error_code' => 'request_info_id_mismatch', 'expected_request_id' => $request_id, 'actual_request_id' => $info->request_id, 'response' => $info->raw ) );
		}
		if ( '' === $info->status ) {
			throw new YandexDeliveryApiException( 'Yandex request/info did not return state.status.', array( 'error_code' => 'request_info_status_missing', 'request_id' => $request_id, 'response' => $info->raw ) );
		}
		if ( array() !== $temporary_places ) {
			if ( count( $temporary_places ) !== count( $info->places ) || count( $temporary_places ) !== count( $info->place_barcode_map ) ) {
				throw new YandexDeliveryApiException( 'Yandex request/info places count does not match temporary payload places.', array( 'error_code' => 'request_info_places_count_mismatch', 'request_id' => $request_id, 'temporary_places_count' => count( $temporary_places ), 'real_places_count' => count( $info->places ), 'response' => $info->raw ) );
			}
			if ( count( array_unique( array_values( $info->place_barcode_map ) ) ) !== count( $info->place_barcode_map ) ) {
				throw new YandexDeliveryApiException( 'Yandex request/info returned duplicate real place barcodes.', array( 'error_code' => 'request_info_place_barcode_duplicate', 'request_id' => $request_id, 'response' => $info->raw ) );
			}
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

		$state = YandexDeliveryShipmentState::from_api_response( $this->api->requestCancel( array( 'request_id' => $request_id ) ), $request_id );
		if ( '' === $state->status ) {
			throw new YandexDeliveryApiException( 'Yandex request/cancel did not return status.', array( 'error_code' => 'cancel_status_missing', 'request_id' => $request_id, 'response' => $state->raw ) );
		}

		return $state;
	}

	/**
	 * @param array<int,string> $request_ids
	 */
	public function generate_labels( array $request_ids, string $generate_type = 'one', string $language = 'ru' ): YandexDeliveryBinaryDocument {
		$request_ids = array_values( array_filter( array_map( static fn ( mixed $value ): string => trim( (string) $value ), $request_ids ), static fn ( string $value ): bool => '' !== $value ) );
		if ( array() === $request_ids ) {
			throw new YandexDeliveryApiException( 'Yandex request_id is required.', array( 'error_code' => 'request_id_missing' ) );
		}

		$response = $this->api->generateLabels(
			array(
				'request_ids' => $request_ids,
				'generate_type' => '' !== trim( $generate_type ) ? trim( $generate_type ) : 'one',
				'language' => '' !== trim( $language ) ? trim( $language ) : 'ru',
			)
		);

		return new YandexDeliveryBinaryDocument(
			$response->body,
			$this->header_value( $response->headers, 'content-type' ),
			$response->status_code,
			$response->headers
		);
	}

	/** @param array<string,mixed> $headers */
	private function header_value( array $headers, string $name ): string {
		$name = strtolower( $name );
		foreach ( $headers as $key => $value ) {
			if ( strtolower( (string) $key ) === $name ) {
				return is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
			}
		}

		return '';
	}
}
