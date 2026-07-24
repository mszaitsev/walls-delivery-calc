<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin\Ajax;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointService;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionService;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Pickup\Cdek\CdekDeliveryPointService;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointTypeSettings;
use WallsShop\WDC\Shipments\Application\OrderShipmentDraftFactory;
use WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry;
use WallsShop\WDC\Shipments\Application\ShipmentBacklogService;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Application\ShipmentMetaboxButtonPolicy;
use WallsShop\WDC\Shipments\Application\ShipmentStatusUpdateService;
use WallsShop\WDC\Shipments\Cdek\CdekBarcodePrintService;
use WallsShop\WDC\Shipments\Cdek\CdekOrderStatusService;
use WallsShop\WDC\Shipments\Cdek\CdekRecipientAddressPreparationService;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentLifecycleContinuationInterface;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentAction;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentDownloadService;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentProviderRegistry;
use WallsShop\WDC\Shipments\Modal\CarrierShipmentModalExtensionInterface;
use WallsShop\WDC\Shipments\Modal\ShipmentModalExtensionRegistry;
use WallsShop\WDC\Shipments\RussianPost\RussianPostAddressNormalizer;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;


defined( 'ABSPATH' ) || exit;


final class ShipmentAddressAjaxController {

	private ?YandexDeliveryPickupPointV2Repository $yandex_pickup_points = null;
	private ?YandexLocationMappingV2Repository $yandex_location_mapping = null;

	public function __construct(
		private ?RussianPostAddressNormalizer $address_normalizer = null,
		private ?CdekDeliveryPointService $cdek_delivery_points = null,
		private ?DpdPickupPointService $dpd_pickup_points = null,
		private ?CdekRecipientAddressPreparationService $cdek_address_preparation = null,
		private ?AddressSuggestionService $address_suggestions = null,
		private ?RussianPostPickupPointTypeSettings $pickup_point_type_settings = null
	) {
	}

	public function handle_normalize(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( ShipmentAdminAjaxService::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ) ), 404 );
		}
		$original_address = sanitize_text_field( wp_unslash( $_POST['courier_original_address'] ?? $_POST['original_address'] ?? '' ) );
		$service_key = sanitize_key( wp_unslash( $_POST['service_key'] ?? '' ) );
		$carrier_key = sanitize_key( wp_unslash( $_POST['carrier_key'] ?? '' ) );
		$delivery_type = RussianPostDomesticSettings::normalize_delivery_type( sanitize_key( wp_unslash( $_POST['delivery_type'] ?? '' ) ) );
		if ( YandexDeliverySettings::CARRIER_KEY === $carrier_key && DeliveryType::COURIER === $delivery_type ) {
			$result = $this->normalize_yandex_courier_address( $order, $original_address );
			wp_send_json_success( array( 'normalized_address' => $result ) );
		}
		if ( CdekSettings::CARRIER_KEY === $carrier_key && DeliveryType::COURIER === $delivery_type ) {
			if ( ! $this->cdek_address_preparation instanceof CdekRecipientAddressPreparationService ) {
				wp_send_json_error( array( 'message' => __( 'Нормализация адреса СДЭК недоступна.', 'walls-delivery-calc' ) ), 500 );
			}
			$result = $this->cdek_address_preparation->prepare( $order, $original_address, $this->recipient_location_context_from_request( $order ), $service_key ?: CdekSettings::SERVICE_KEY );
			wp_send_json_success( array( 'normalized_address' => $result ) );
		}
		if ( DpdSettings::CARRIER_KEY === $carrier_key && DeliveryType::COURIER === $delivery_type ) {
			if ( ! $this->cdek_address_preparation instanceof CdekRecipientAddressPreparationService ) {
				wp_send_json_error( array( 'message' => __( 'Нормализация адреса DPD недоступна.', 'walls-delivery-calc' ) ), 500 );
			}
			$result = $this->cdek_address_preparation->prepare( $order, $original_address, $this->recipient_location_context_from_request( $order ), DpdSettings::SERVICE_KEY );
			$result['service_key'] = DpdSettings::SERVICE_KEY;
			$result['source'] = ! empty( $result['success'] ) ? 'dadata+dpd' : (string) ( $result['source'] ?? 'dadata+dpd' );
			wp_send_json_success( array( 'normalized_address' => $result ) );
		}
		if ( ! $this->address_normalizer instanceof RussianPostAddressNormalizer ) {
			wp_send_json_error( array( 'message' => __( 'Нормализация адреса недоступна.', 'walls-delivery-calc' ) ), 500 );
		}

		$result = $this->address_normalizer->normalize( $order_id, $original_address );
		$result['order_id'] = $order_id;
		$result['service_key'] = $service_key;

		if ( method_exists( $order, 'update_meta_data' ) && method_exists( $order, 'save' ) ) {
			$order->update_meta_data( '_wdc_shipment_rp_clean_address', $result );
			$order->save();
		}

		wp_send_json_success( array( 'normalized_address' => $result ) );
	}

	public function handle_search_pickup_points(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( ShipmentAdminAjaxService::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}

		$query = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
		$mode = sanitize_key( wp_unslash( $_POST['mode'] ?? '' ) );
		$mode = in_array( $mode, array( 'location', 'nearby', 'search' ), true ) ? $mode : 'search';
		$limit = max( 1, min( 'location' === $mode ? 2000 : 100, (int) ( $_POST['limit'] ?? ( 'location' === $mode ? 2000 : 50 ) ) ) );
		$carrier_key = sanitize_key( wp_unslash( $_POST['carrier_key'] ?? '' ) );
		$purpose = sanitize_key( wp_unslash( $_POST['purpose'] ?? '' ) );
		if ( YandexDeliverySettings::CARRIER_KEY === $carrier_key && 'source_dropoff' === $purpose ) {
			$this->ajax_search_yandex_source_dropoff_points( $mode, $limit );
		}
		if ( DpdSettings::CARRIER_KEY === $carrier_key && $this->dpd_pickup_points instanceof DpdPickupPointService ) {
			$city_id = (int) preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['city_id'] ?? '' ) );
			$location_id = (int) preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['location_id'] ?? '' ) );
			if ( $city_id > 0 ) {
				$points = 'search' === $mode && '' !== $query
					? $this->dpd_pickup_points->search_parcel_shops( $query, array( 'city_id' => $city_id, 'limit' => $limit ) )
					: $this->dpd_pickup_points->get_parcel_shops_by_city_id( $city_id, $limit );
			} elseif ( $location_id > 0 && 'location' === $mode ) {
				$points = array_values(
					array_filter(
						$this->dpd_pickup_points->get_points_for_location_id( $location_id ),
						static fn( array $point ): bool => 'parcel_shop' === (string) ( $point['type'] ?? '' )
					)
				);
			} else {
				$points = $this->dpd_pickup_points->search_parcel_shops(
					$query,
					array(
						'city_name' => sanitize_text_field( wp_unslash( $_POST['city'] ?? $_POST['city_name'] ?? '' ) ),
						'limit' => $limit,
					)
				);
			}
			wp_send_json_success(
				array(
					'points' => array_map( array( $this, 'dpd_pickup_point_ajax_row' ), array_slice( $points, 0, $limit ) ),
				)
			);
		}
		if ( CdekSettings::CARRIER_KEY === $carrier_key && $this->cdek_delivery_points instanceof CdekDeliveryPointService ) {
			$points = $this->cdek_delivery_points->pointsForLocation(
				array(
					'country_code' => 'RU',
					'city_name' => sanitize_text_field( wp_unslash( $_POST['city'] ?? $_POST['city_name'] ?? '' ) ),
					'city_value' => sanitize_text_field( wp_unslash( $_POST['city'] ?? $_POST['city_name'] ?? '' ) ),
					'region_name' => sanitize_text_field( wp_unslash( $_POST['region'] ?? $_POST['region_name'] ?? '' ) ),
					'state_value' => sanitize_text_field( wp_unslash( $_POST['region'] ?? $_POST['region_name'] ?? '' ) ),
					'postal_code' => sanitize_text_field( wp_unslash( $_POST['postcode'] ?? '' ) ),
					'postcode' => sanitize_text_field( wp_unslash( $_POST['postcode'] ?? '' ) ),
					'display_name' => sanitize_text_field( wp_unslash( $_POST['address'] ?? $query ) ),
					'fias_id' => sanitize_text_field( wp_unslash( $_POST['fias_id'] ?? '' ) ),
					'gar_id' => sanitize_text_field( wp_unslash( $_POST['gar_id'] ?? '' ) ),
					'location_id' => sanitize_text_field( wp_unslash( $_POST['location_id'] ?? '' ) ),
				),
				array( 'type' => 'ALL' )
			);
			if ( 'search' === $mode && '' !== $query ) {
				$needle = $this->normalize_pickup_search_text( $query );
				$points = array_values(
					array_filter(
						$points,
						fn( array $point ): bool => str_contains(
							$this->normalize_pickup_search_text(
								implode(
									' ',
									array(
										(string) ( $point['point_code'] ?? '' ),
										(string) ( $point['cdek_code'] ?? '' ),
										(string) ( $point['point_name'] ?? '' ),
										(string) ( $point['point_address'] ?? $point['address'] ?? '' ),
										(string) ( $point['point_postcode'] ?? $point['postcode'] ?? '' ),
									)
								)
							),
							$needle
						)
					)
				);
			}
			wp_send_json_success(
				array(
					'points' => array_values( $points ),
				)
			);
		}
		$repository = new RussianPostPickupPointRepository();
		if ( 'location' === $mode ) {
			$location_context = array(
				'city_name' => sanitize_text_field( wp_unslash( $_POST['city'] ?? $_POST['city_name'] ?? '' ) ),
				'city_value' => sanitize_text_field( wp_unslash( $_POST['city'] ?? $_POST['city_name'] ?? '' ) ),
				'region_name' => sanitize_text_field( wp_unslash( $_POST['region'] ?? $_POST['region_name'] ?? '' ) ),
				'state_value' => sanitize_text_field( wp_unslash( $_POST['region'] ?? $_POST['region_name'] ?? '' ) ),
				'postal_code' => sanitize_text_field( wp_unslash( $_POST['postcode'] ?? '' ) ),
				'postcode' => sanitize_text_field( wp_unslash( $_POST['postcode'] ?? '' ) ),
				'display_name' => sanitize_text_field( wp_unslash( $_POST['address'] ?? $query ) ),
				'fias_id' => sanitize_text_field( wp_unslash( $_POST['fias_id'] ?? '' ) ),
				'gar_id' => sanitize_text_field( wp_unslash( $_POST['gar_id'] ?? '' ) ),
				'location_id' => sanitize_text_field( wp_unslash( $_POST['location_id'] ?? '' ) ),
			);
			$order_id = (int) ( $_POST['order_id'] ?? 0 );
			if ( $order_id > 0 && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $order_id );
				if ( is_object( $order ) ) {
					$shipping_city = method_exists( $order, 'get_shipping_city' ) ? (string) $order->get_shipping_city() : '';
					$shipping_region = method_exists( $order, 'get_shipping_state' ) ? (string) $order->get_shipping_state() : '';
					$shipping_postcode = method_exists( $order, 'get_shipping_postcode' ) ? (string) $order->get_shipping_postcode() : '';
					$shipping_address = trim(
						implode(
							' ',
							array_filter(
								array(
									method_exists( $order, 'get_shipping_address_1' ) ? (string) $order->get_shipping_address_1() : '',
									method_exists( $order, 'get_shipping_address_2' ) ? (string) $order->get_shipping_address_2() : '',
								),
								static fn( string $value ): bool => '' !== trim( $value )
							)
						)
					);
					$location_context['city_name'] = '' !== trim( (string) $location_context['city_name'] ) ? $location_context['city_name'] : $shipping_city;
					$location_context['city_value'] = '' !== trim( (string) $location_context['city_value'] ) ? $location_context['city_value'] : $shipping_city;
					$location_context['region_name'] = '' !== trim( (string) $location_context['region_name'] ) ? $location_context['region_name'] : $shipping_region;
					$location_context['state_value'] = '' !== trim( (string) $location_context['state_value'] ) ? $location_context['state_value'] : $shipping_region;
					$location_context['postal_code'] = '' !== trim( (string) $location_context['postal_code'] ) ? $location_context['postal_code'] : $shipping_postcode;
					$location_context['postcode'] = '' !== trim( (string) $location_context['postcode'] ) ? $location_context['postcode'] : $shipping_postcode;
					$location_context['display_name'] = '' !== trim( (string) $location_context['display_name'] ) ? $location_context['display_name'] : $shipping_address;
				}
			}
			$rows = $repository->find_rows_by_location_context(
				$location_context,
				array( 'limit' => $limit )
			);
		} else {
			$rows = $repository->search_admin_pickup_rows( $query, array( 'limit' => $limit ) );
		}

		wp_send_json_success(
			array(
				'points' => array_map( array( $this, 'pickup_point_ajax_row' ), $rows ),
			)
		);
	}

	private function normalize_yandex_courier_address( object $order, string $original_address ): array {
		$original_address = trim( $original_address );
		if ( '' === $original_address ) {
			return array(
				'success' => false,
				'message' => __( 'Введите полный адрес доставки.', 'walls-delivery-calc' ),
				'source' => 'dadata+yandex',
				'fields' => array(),
				'display' => '',
				'original_hash' => hash( 'sha256', $original_address ),
				'service_key' => YandexDeliverySettings::SERVICE_KEY,
			);
		}
		if ( ! $this->address_suggestions instanceof AddressSuggestionService ) {
			return array(
				'success' => false,
				'message' => __( 'Проверка адреса через DaData недоступна.', 'walls-delivery-calc' ),
				'source' => 'dadata+yandex',
				'fields' => array(),
				'display' => '',
				'original_hash' => hash( 'sha256', $original_address ),
				'service_key' => YandexDeliverySettings::SERVICE_KEY,
			);
		}

		$response = $this->address_suggestions->suggest( 'address', $original_address, $this->yandex_address_suggestion_context( $order ) );
		if ( empty( $response['success'] ) ) {
			return array(
				'success' => false,
				'message' => $this->dadata_error_message( (string) ( $response['error_code'] ?? '' ) ),
				'source' => 'dadata+yandex',
				'fields' => array(),
				'display' => '',
				'original_hash' => hash( 'sha256', $original_address ),
				'service_key' => YandexDeliverySettings::SERVICE_KEY,
			);
		}

		$items = is_array( $response['items'] ?? null ) ? $response['items'] : array();
		$item = null;
		foreach ( $items as $candidate ) {
			if ( is_array( $candidate ) && ! empty( $candidate['isDeliverable'] ) ) {
				$item = $candidate;
				break;
			}
		}
		if ( null === $item && isset( $items[0] ) && is_array( $items[0] ) ) {
			$item = $items[0];
		}
		if ( null === $item ) {
			return array(
				'success' => false,
				'message' => __( 'Адрес распознан недостаточно точно. Уточните его и проверьте повторно.', 'walls-delivery-calc' ),
				'source' => 'dadata+yandex',
				'fields' => array(),
				'display' => '',
				'original_hash' => hash( 'sha256', $original_address ),
				'service_key' => YandexDeliverySettings::SERVICE_KEY,
			);
		}

		$data = is_array( $item['data'] ?? null ) ? $item['data'] : array();
		$locality = $this->yandex_locality_from_normalized_item( $item );
		$street = trim( (string) ( $data['street_with_type'] ?? $data['street'] ?? '' ) );
		$house = trim( (string) ( $data['house'] ?? '' ) );
		$room = trim( (string) ( $data['flat'] ?? $data['room'] ?? $data['room_number'] ?? $data['premise'] ?? '' ) );
		$full_address = trim( (string) ( $item['unrestrictedValue'] ?? $item['value'] ?? $item['label'] ?? $original_address ) );
		$message = '';
		if ( '' === $locality ) {
			$message = __( 'Не удалось определить населённый пункт. Проверьте полный адрес.', 'walls-delivery-calc' );
		} elseif ( '' === $street ) {
			$message = __( 'Не удалось определить улицу. Проверьте полный адрес.', 'walls-delivery-calc' );
		} elseif ( '' === $house ) {
			$message = __( 'Не удалось определить номер дома. Проверьте полный адрес.', 'walls-delivery-calc' );
		} elseif ( empty( $item['isDeliverable'] ) ) {
			$message = __( 'Адрес распознан недостаточно точно. Уточните его и проверьте повторно.', 'walls-delivery-calc' );
		}
		$fields = array(
			'country' => 'Россия',
			'postal_code' => preg_replace( '/\D+/', '', (string) ( $data['postal_code'] ?? '' ) ) ?: '',
			'region' => trim( (string) ( $data['region_with_type'] ?? $data['region'] ?? '' ) ),
			'locality' => $locality,
			'street' => $street,
			'house' => $house,
			'room' => $room,
			'full_address' => $full_address,
		);

		return array(
			'success' => '' === $message,
			'message' => '' === $message ? __( 'Адрес Яндекс проверен через DaData.', 'walls-delivery-calc' ) : $message,
			'source' => 'dadata+yandex',
			'service_key' => YandexDeliverySettings::SERVICE_KEY,
			'original_hash' => hash( 'sha256', $original_address ),
			'display' => $full_address,
			'fields' => $fields,
			'quality' => array(
				'level' => (string) ( $item['level'] ?? '' ),
				'is_deliverable' => ! empty( $item['isDeliverable'] ),
			),
			'order_id' => method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0,
		);
	}

	private function yandex_locality_from_normalized_item( array $item ): string {
		$data = is_array( $item['data'] ?? null ) ? $item['data'] : array();
		foreach ( array(
			$item['locality'] ?? null,
			$item['city_name'] ?? null,
			$item['city'] ?? null,
			$item['place'] ?? null,
			$item['settlement'] ?? null,
			$data['locality'] ?? null,
			$data['city_name'] ?? null,
			$data['place'] ?? null,
			$data['settlement_with_type'] ?? null,
			$data['city_with_type'] ?? null,
			$data['settlement'] ?? null,
			$data['city'] ?? null,
		) as $value ) {
			$locality = $this->clean_yandex_locality( (string) $value );
			if ( '' !== $locality ) {
				return $locality;
			}
		}

		return $this->federal_city_locality( $data );
	}

	private function clean_yandex_locality( string $value ): string {
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
		$value = preg_replace( '/^(г\.?|город|пгт|рп|рабочий\s+пос[её]лок|пос[её]лок|с\.?|село|д\.?|деревня)\s+/iu', '', $value ) ?? $value;
		return trim( $value );
	}

	private function federal_city_locality( array $data ): string {
		$region = $this->clean_yandex_locality( (string) ( $data['region_with_type'] ?? $data['region'] ?? '' ) );
		$normalized = function_exists( 'mb_strtolower' ) ? mb_strtolower( $region ) : strtolower( $region );
		foreach ( array( 'москва', 'санкт-петербург', 'севастополь' ) as $city ) {
			if ( $city === $normalized ) {
				return $region;
			}
		}

		return '';
	}

	private function yandex_address_suggestion_context( object $order ): array {
		return array_filter(
			array(
				'country_code' => 'RU',
				'location_city_fias_id' => method_exists( $order, 'get_meta' ) ? (string) $order->get_meta( '_wdc_platform_location_fias_id', true ) : '',
			),
			static fn( string $value ): bool => '' !== trim( $value )
		);
	}

	private function dadata_error_message( string $code ): string {
		return match ( $code ) {
			'no_available_dadata_token' => __( 'Не настроен токен DaData для проверки адреса.', 'walls-delivery-calc' ),
			'dadata_daily_limit_exhausted' => __( 'Лимит DaData исчерпан. Повторите проверку позднее.', 'walls-delivery-calc' ),
			'dadata_timeout' => __( 'DaData не ответила вовремя. Повторите проверку адреса.', 'walls-delivery-calc' ),
			default => __( 'Не удалось проверить адрес через DaData.', 'walls-delivery-calc' ),
		};
	}

	private function maybe_prepare_cdek_courier_address( object $order, array &$data ): array {
		$carrier_key = sanitize_key( wp_unslash( $data['carrier_key'] ?? '' ) );
		$delivery_type = RussianPostDomesticSettings::normalize_delivery_type( sanitize_key( wp_unslash( $data['delivery_type'] ?? '' ) ) );
		if ( CdekSettings::CARRIER_KEY !== $carrier_key || DeliveryType::COURIER !== $delivery_type ) {
			return array( 'error' => '' );
		}
		$original_address = sanitize_text_field( wp_unslash( $data['courier_original_address'] ?? $data['original_address'] ?? '' ) );
		$snapshot = $this->decoded_json_field( $data['normalized_address_json'] ?? '' );
		$valid = ! empty( $snapshot['success'] )
			&& (string) ( $snapshot['source'] ?? '' ) === 'dadata+cdek_location'
			&& (string) ( $snapshot['original_hash'] ?? '' ) === hash( 'sha256', trim( $original_address ) )
			&& (int) ( $snapshot['fields']['cdek_city_code'] ?? 0 ) > 0;
		if ( $valid ) {
			return array( 'error' => '' );
		}
		if ( ! $this->cdek_address_preparation instanceof CdekRecipientAddressPreparationService ) {
			return array( 'error' => __( 'Нормализация адреса СДЭК недоступна.', 'walls-delivery-calc' ) );
		}
		$prepared = $this->cdek_address_preparation->prepare( $order, $original_address, $this->recipient_location_context_from_request( $order, $data ), CdekSettings::SERVICE_KEY );
		$data['normalized_address_json'] = wp_json_encode( $prepared, JSON_UNESCAPED_UNICODE ) ?: '';
		if ( empty( $prepared['success'] ) ) {
			return array( 'error' => (string) ( $prepared['message'] ?? CdekRecipientAddressPreparationService::CITY_CODE_ERROR ) );
		}

		return array( 'error' => '' );
	}

	private function decoded_json_field( mixed $value ): array {
		$json = (string) wp_unslash( $value );
		$decoded = '' !== trim( $json ) ? json_decode( $json, true ) : array();

		return is_array( $decoded ) ? $decoded : array();
	}

	private function recipient_location_context_from_request( object $order, array $data = array() ): array {
		$city = sanitize_text_field( wp_unslash( $data['recipient_location_city'] ?? $_POST['recipient_location_city'] ?? '' ) );
		$region = sanitize_text_field( wp_unslash( $data['recipient_location_region'] ?? $_POST['recipient_location_region'] ?? '' ) );
		$postcode = sanitize_text_field( wp_unslash( $data['recipient_location_postcode'] ?? $_POST['recipient_location_postcode'] ?? '' ) );
		$address = sanitize_text_field( wp_unslash( $data['recipient_location_address'] ?? $_POST['recipient_location_address'] ?? '' ) );
		if ( '' === $city && method_exists( $order, 'get_shipping_city' ) ) {
			$city = (string) $order->get_shipping_city();
		}
		if ( '' === $region && method_exists( $order, 'get_shipping_state' ) ) {
			$region = (string) $order->get_shipping_state();
		}
		if ( '' === $postcode && method_exists( $order, 'get_shipping_postcode' ) ) {
			$postcode = (string) $order->get_shipping_postcode();
		}

		$calculation = $this->order_array_meta( $order, '_wdc_delivery_calculation_data' );
		$rate_meta = $this->order_array_meta( $order, '_wdc_platform_rate_meta' );
		$cdek_city_code = $this->cdek_city_code_from_saved_data( $calculation, $rate_meta );
		$country_code = strtoupper( trim( sanitize_text_field( wp_unslash( $data['recipient_location_country'] ?? $_POST['recipient_location_country'] ?? $rate_meta['country_code'] ?? $rate_meta['location']['cdek_to_country_code'] ?? $calculation['country_code'] ?? '' ) ) ) );
		if ( '' === $country_code && method_exists( $order, 'get_shipping_country' ) ) {
			$country_code = strtoupper( trim( (string) $order->get_shipping_country() ) );
		}
		$country_code = in_array( $country_code, CdekSettings::SUPPORTED_COUNTRIES, true ) ? $country_code : 'RU';

		return array(
			'country_code' => $country_code,
			'cdek_city_code' => $cdek_city_code > 0 ? $cdek_city_code : '',
			'cdek_to_city_code' => $cdek_city_code > 0 ? $cdek_city_code : '',
			'delivery_calculation_data' => $calculation,
			'rate_meta' => $rate_meta,
			'city_name' => $city,
			'city_value' => $city,
			'region_name' => $region,
			'state_value' => $region,
			'postal_code' => $postcode,
			'postcode' => $postcode,
			'display_name' => '' !== $address ? $address : trim( implode( ', ', array_filter( array( $postcode, $region, $city ) ) ) ),
			'fias_id' => sanitize_text_field( wp_unslash( $data['recipient_location_fias_id'] ?? $_POST['recipient_location_fias_id'] ?? '' ) ),
			'gar_id' => sanitize_text_field( wp_unslash( $data['recipient_location_gar_id'] ?? $_POST['recipient_location_gar_id'] ?? '' ) ),
			'location_id' => sanitize_text_field( wp_unslash( $data['recipient_location_id'] ?? $_POST['recipient_location_id'] ?? '' ) ),
			'lat' => sanitize_text_field( wp_unslash( $data['recipient_location_lat'] ?? $_POST['recipient_location_lat'] ?? '' ) ),
			'lng' => sanitize_text_field( wp_unslash( $data['recipient_location_lng'] ?? $_POST['recipient_location_lng'] ?? '' ) ),
		);
	}

	private function order_array_meta( object $order, string $key ): array {
		if ( ! method_exists( $order, 'get_meta' ) ) {
			return array();
		}
		$value = $order->get_meta( $key, true );
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			return is_array( $decoded ) ? $decoded : array();
		}

		return is_array( $value ) ? $value : array();
	}

	private function cdek_city_code_from_saved_data( array $calculation, array $rate_meta ): int {
		foreach ( array(
			$calculation['api']['cdek_to_city_code'] ?? null,
			$rate_meta['api']['cdek_to_city_code'] ?? null,
			$rate_meta['location']['cdek_to_city_code'] ?? null,
			$calculation['api']['request_payload_sanitized']['to_location']['code'] ?? null,
			$rate_meta['request_payload_sanitized']['to_location']['code'] ?? null,
			$rate_meta['api']['request_payload_sanitized']['to_location']['code'] ?? null,
		) as $value ) {
			if ( is_numeric( $value ) && (int) $value > 0 ) {
				return (int) $value;
			}
		}

		return 0;
	}

	private function normalize_pickup_search_text( string $value ): string {
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );

		return trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
	}

	private function ajax_search_yandex_source_dropoff_points( string $mode, int $limit ): void {
		$limit = max( 1, min( 2000, $limit ) );
		$source_location_id = (int) preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['source_location_id'] ?? $_POST['location_id'] ?? '' ) );
		$source_platform_station_id = sanitize_text_field( wp_unslash( $_POST['source_platform_station_id'] ?? '' ) );
		$default_row = '' !== trim( $source_platform_station_id ) ? $this->yandex_pickup_points()->find( $source_platform_station_id ) : null;
		$context = array(
			'mode' => $mode,
			'center' => $this->yandex_source_dropoff_center( is_array( $default_row ) ? $default_row : null, array() ),
			'radius_km' => null,
			'total' => 0,
			'source_location_id' => $source_location_id,
			'yandex_geo_ids' => array(),
		);

		if ( 'nearby' === $mode ) {
			$latitude = filter_var( wp_unslash( $_POST['latitude'] ?? $_POST['lat'] ?? null ), FILTER_VALIDATE_FLOAT );
			$longitude = filter_var( wp_unslash( $_POST['longitude'] ?? $_POST['lng'] ?? null ), FILTER_VALIDATE_FLOAT );
			$radius_km = filter_var( wp_unslash( $_POST['radius_km'] ?? 10 ), FILTER_VALIDATE_FLOAT );
			if ( false === $latitude || false === $longitude || $latitude < -90 || $latitude > 90 || $longitude < -180 || $longitude > 180 ) {
				wp_send_json_error( array( 'message' => __( 'Не удалось определить область поиска ПВЗ.', 'walls-delivery-calc' ) ), 400 );
			}
			$radius_km = false === $radius_km ? 10.0 : max( 1.0, min( 50.0, (float) $radius_km ) );
			$rows = $this->yandex_pickup_points()->search_source_dropoff_points_near( (float) $latitude, (float) $longitude, $radius_km, min( 200, $limit ) );
			$context['center'] = array( 'lat' => (float) $latitude, 'lng' => (float) $longitude );
			$context['radius_km'] = $radius_km;
			$context['total'] = count( $rows );
			wp_send_json_success(
				array(
					'points' => array_map( array( $this, 'yandex_source_dropoff_ajax_row' ), $rows ),
					'context' => $context,
					'message' => array() === $rows ? __( 'Рядом с найденным адресом нет ПВЗ Яндекс, принимающих отправления.', 'walls-delivery-calc' ) : '',
				)
			);
		}

		$geo_ids = $source_location_id > 0 ? $this->yandex_location_mapping()->geo_ids_for_location( $source_location_id ) : array();
		if ( array() === $geo_ids && is_array( $default_row ) && (int) ( $default_row['yandex_geo_id'] ?? 0 ) > 0 ) {
			$geo_ids = array( (int) $default_row['yandex_geo_id'] );
		}
		if ( array() !== $geo_ids ) {
			$rows = $this->yandex_pickup_points()->source_dropoff_map_points_by_geo_ids( $geo_ids, $limit );
		} elseif ( is_array( $default_row ) && is_numeric( $default_row['latitude'] ?? null ) && is_numeric( $default_row['longitude'] ?? null ) ) {
			$rows = $this->yandex_pickup_points()->search_source_dropoff_points_near( (float) $default_row['latitude'], (float) $default_row['longitude'], 10.0, min( 200, $limit ) );
		} else {
			$rows = array();
		}

		$context['mode'] = 'location';
		$context['center'] = $this->yandex_source_dropoff_center( is_array( $default_row ) ? $default_row : null, $rows );
		$context['total'] = count( $rows );
		$context['yandex_geo_ids'] = array_values( array_map( 'intval', $geo_ids ) );
		wp_send_json_success(
			array(
				'points' => array_map( array( $this, 'yandex_source_dropoff_ajax_row' ), $rows ),
				'context' => $context,
				'message' => array() === $rows ? __( 'В выбранном городе не найдены ПВЗ Яндекс, принимающие отправления.', 'walls-delivery-calc' ) : '',
			)
		);
	}

	private function yandex_pickup_points(): YandexDeliveryPickupPointV2Repository {
		if ( ! $this->yandex_pickup_points instanceof YandexDeliveryPickupPointV2Repository ) {
			$this->yandex_pickup_points = new YandexDeliveryPickupPointV2Repository();
		}

		return $this->yandex_pickup_points;
	}

	private function yandex_location_mapping(): YandexLocationMappingV2Repository {
		if ( ! $this->yandex_location_mapping instanceof YandexLocationMappingV2Repository ) {
			$this->yandex_location_mapping = new YandexLocationMappingV2Repository();
		}

		return $this->yandex_location_mapping;
	}

	private function yandex_source_dropoff_center( ?array $source_row, array $rows ): ?array {
		if ( is_array( $source_row ) && is_numeric( $source_row['latitude'] ?? null ) && is_numeric( $source_row['longitude'] ?? null ) ) {
			return array( 'lat' => (float) $source_row['latitude'], 'lng' => (float) $source_row['longitude'] );
		}
		$lat_sum = 0.0;
		$lng_sum = 0.0;
		$count = 0;
		foreach ( $rows as $row ) {
			if ( is_numeric( $row['latitude'] ?? null ) && is_numeric( $row['longitude'] ?? null ) ) {
				$lat_sum += (float) $row['latitude'];
				$lng_sum += (float) $row['longitude'];
				++$count;
			}
		}

		return $count > 0 ? array( 'lat' => round( $lat_sum / $count, 7 ), 'lng' => round( $lng_sum / $count, 7 ) ) : null;
	}

	private function map_provider(): string {
		$provider = ( new SettingsRepository() )->get_string( 'pickup_map_provider', 'leaflet' );

		return 'yandex' === $provider ? 'yandex' : 'leaflet';
	}

	private function yandex_api_key(): string {
		return trim( ( new SettingsRepository() )->get_string( 'pickup_map_yandex_api_key', '' ) );
	}

	private function pickup_point_ajax_row( array $row ): array {
		return array(
			'point_code' => (string) ( $row['point_code'] ?? '' ),
			'postcode' => (string) ( $row['postcode'] ?? '' ),
			'region_name' => (string) ( $row['region_name'] ?? '' ),
			'city_name' => (string) ( $row['city_name'] ?? '' ),
			'address' => (string) ( $row['address'] ?? '' ),
			'lat' => null !== ( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null,
			'lng' => null !== ( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null,
		);
	}

	private function yandex_source_dropoff_ajax_row( array $row ): array {
		$station_id = (string) ( $row['platform_station_id'] ?? '' );
		$title = (string) ( $row['name'] ?? '' );
		$address = (string) ( $row['full_address'] ?? '' );

		return array(
			'carrier_key' => YandexDeliverySettings::CARRIER_KEY,
			'carrier' => YandexDeliverySettings::CARRIER_KEY,
			'service_key' => YandexDeliverySettings::SERVICE_KEY,
			'pickup_family' => YandexDeliverySettings::CARRIER_KEY . ':source_dropoff',
			'point_code' => $station_id,
			'platform_station_id' => $station_id,
			'display_code' => $station_id,
			'point_type' => 'source_dropoff',
			'type' => (string) ( $row['type'] ?? 'pickup_point' ),
			'point_title' => '' !== $title ? $title : ( '' !== $address ? $address : $station_id ),
			'display_title' => '' !== $title ? $title : ( '' !== $address ? $address : $station_id ),
			'region_name' => (string) ( $row['region'] ?? '' ),
			'city_name' => (string) ( $row['locality'] ?? '' ),
			'city' => (string) ( $row['locality'] ?? '' ),
			'address' => $address,
			'work_time' => (string) ( $row['schedule_text'] ?? '' ),
			'schedule_text' => (string) ( $row['schedule_text'] ?? '' ),
			'lat' => null !== ( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null,
			'lng' => null !== ( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null,
			'drop_off' => true,
			'available_for_dropoff' => true,
			'marker_type' => 'source_dropoff',
			'distance_km' => isset( $row['distance_km'] ) ? (float) $row['distance_km'] : null,
		);
	}

	private function dpd_pickup_point_ajax_row( array $row ): array {
		return array(
			'carrier_key' => DpdSettings::CARRIER_KEY,
			'carrier' => DpdSettings::CARRIER_KEY,
			'point_code' => (string) ( $row['terminal_code'] ?? '' ),
			'display_code' => (string) ( $row['terminal_code'] ?? '' ),
			'point_type' => (string) ( $row['type'] ?? 'parcel_shop' ),
			'type' => (string) ( $row['type'] ?? 'parcel_shop' ),
			'point_title' => (string) ( $row['name'] ?? 'ПВЗ DPD' ),
			'display_title' => (string) ( $row['name'] ?? 'ПВЗ DPD' ),
			'postcode' => '',
			'region_name' => (string) ( $row['region_name'] ?? '' ),
			'city_id' => (string) ( $row['city_id'] ?? '' ),
			'city_name' => (string) ( $row['city_name'] ?? '' ),
			'address' => (string) ( $row['address'] ?? '' ),
			'lat' => null !== ( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null,
			'lng' => null !== ( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null,
			'source' => (string) ( $row['source'] ?? '' ),
		);
	}

}
