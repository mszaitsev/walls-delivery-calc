<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin\Ajax;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointService;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryCourierAddressNormalizer;
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


final class ShipmentPreviewAjaxController {

	private ?YandexDeliveryPickupPointV2Repository $yandex_pickup_points = null;

	public function __construct(
		private OrderShipmentDraftFactory $drafts,
		private ShipmentCreationService $creation,
		private ?CdekRecipientAddressPreparationService $cdek_address_preparation = null,
		private ?OzonDeliveryCourierAddressNormalizer $ozon_courier_addresses = null
	) {
	}

	public function handle(): void {
		$buffer_level = ob_get_level();
		ob_start();
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( ShipmentAdminAjaxService::NONCE_ACTION, 'nonce', false ) ) {
			$this->discard_preview_buffer( $buffer_level );
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		try {
			$order_id = (int) ( $_POST['order_id'] ?? 0 );
			$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
			if ( ! is_object( $order ) ) {
				$this->discard_preview_buffer( $buffer_level );
				wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ), 'error_code' => 'shipment_preview_invalid_request' ), 404 );
			}
			$data = $_POST;
			$prepared = $this->maybe_prepare_cdek_courier_address( $order, $data );
			if ( ! empty( $prepared['error'] ) ) {
				throw new \InvalidArgumentException( (string) $prepared['error'] );
			}
			$prepared = $this->maybe_prepare_ozon_courier_address( $order, $data );
			if ( ! empty( $prepared['error'] ) ) {
				throw new \InvalidArgumentException( (string) $prepared['error'] );
			}
			$request = $this->preview_request( $this->drafts->create_request_from_admin_data( $order, $data ) );
			$this->validate_preview_request( $request );
			$preview = $this->creation->safe_preview( $request, $order );
			if ( YandexDeliverySettings::CARRIER_KEY === $request->carrier_key && ! empty( $preview['errors'] ) && is_array( $preview['errors'] ) ) {
				throw new \InvalidArgumentException( $this->public_shipment_error_message( (string) reset( $preview['errors'] ) ) );
			}

			$this->discard_preview_buffer( $buffer_level );
			wp_send_json_success( array( 'preview' => $preview ) );
		} catch ( \InvalidArgumentException $exception ) {
			$this->discard_preview_buffer( $buffer_level );
			wp_send_json_error(
				array(
					'message' => $this->public_shipment_error_message( $exception->getMessage() ),
					'error_code' => 'shipment_preview_validation_failed',
				),
				400
			);
		} catch ( \Throwable $exception ) {
			if ( str_contains( $exception::class, 'AjaxResponse' ) ) {
				throw $exception;
			}
			error_log(
				sprintf(
					'[walls-delivery-calc] shipment preview failed. class=%s message=%s location=%s:%d',
					$exception::class,
					$exception->getMessage(),
					$exception->getFile(),
					$exception->getLine()
				)
			);
			$this->discard_preview_buffer( $buffer_level );
			wp_send_json_error(
				array(
					'message' => __( 'Сервер вернул некорректный ответ при подготовке отправления. Проверьте журнал ошибок.', 'walls-delivery-calc' ),
					'error_code' => 'shipment_preview_unexpected_error',
				),
				500
			);
		}
	}

	private function discard_preview_buffer( int $buffer_level ): void {
		while ( ob_get_level() > $buffer_level ) {
			ob_end_clean();
		}
	}

	private function validate_preview_request( ShipmentCreateRequest $request ): void {
		if ( YandexDeliverySettings::CARRIER_KEY !== $request->carrier_key ) {
			return;
		}
		$source_station = trim( (string) ( $request->meta['yandex_source_platform_station_id'] ?? '' ) );
		if ( '' === $source_station ) {
			throw new \InvalidArgumentException( __( 'Не указана исходная станция Яндекс.', 'walls-delivery-calc' ) );
		}
		$this->validate_yandex_source_station( $source_station, ! empty( $request->meta['yandex_source_station_overridden'] ) );
		$delivery_type = (string) ( $request->delivery_type ?: ( $request->meta['delivery_type'] ?? '' ) );
		if ( DeliveryType::PICKUP === $delivery_type ) {
			$destination_station = trim( (string) ( $request->meta['yandex_pickup_platform_station_id'] ?? $request->pickup_point?->point_code ?? '' ) );
			if ( '' === $destination_station ) {
				throw new \InvalidArgumentException( __( 'Не выбран ПВЗ назначения Яндекс.', 'walls-delivery-calc' ) );
			}
		} elseif ( DeliveryType::COURIER === $delivery_type ) {
			$details = is_array( $request->meta['yandex_courier_details'] ?? null ) ? $request->meta['yandex_courier_details'] : array();
			if ( empty( $details['address_verified'] ) || 'dadata+yandex' !== (string) ( $details['normalization_source'] ?? '' ) ) {
				throw new \InvalidArgumentException( __( 'Проверьте адрес доставки через DaData.', 'walls-delivery-calc' ) );
			}
			if ( '' === trim( (string) ( $details['locality'] ?? '' ) ) ) {
				throw new \InvalidArgumentException( __( 'Не удалось определить населённый пункт. Проверьте полный адрес.', 'walls-delivery-calc' ) );
			}
			if ( '' === trim( (string) ( $details['street'] ?? '' ) ) ) {
				throw new \InvalidArgumentException( __( 'Не удалось определить улицу. Проверьте полный адрес.', 'walls-delivery-calc' ) );
			}
			if ( '' === trim( (string) ( $details['house'] ?? '' ) ) ) {
				throw new \InvalidArgumentException( __( 'Не удалось определить номер дома. Проверьте полный адрес.', 'walls-delivery-calc' ) );
			}
		}
	}

	private function validate_yandex_source_station( string $platform_station_id, bool $overridden ): void {
		$platform_station_id = trim( $platform_station_id );
		if ( '' === $platform_station_id ) {
			throw new \InvalidArgumentException( __( 'Не указана исходная станция Яндекс.', 'walls-delivery-calc' ) );
		}
		$row = $this->yandex_pickup_points()->find( $platform_station_id );
		if ( ! is_array( $row ) ) {
			if ( $overridden ) {
				throw new \InvalidArgumentException( __( 'ПВЗ отправления Яндекс не найден.', 'walls-delivery-calc' ) );
			}
			return;
		}
		if ( empty( $row['active'] ) ) {
			throw new \InvalidArgumentException( $overridden ? __( 'Выбранный ПВЗ Яндекс сейчас недоступен.', 'walls-delivery-calc' ) : __( 'Сохранённый ПВЗ отправления Яндекс недоступен. Выберите другой ПВЗ.', 'walls-delivery-calc' ) );
		}
		if ( empty( $row['available_for_dropoff'] ) ) {
			throw new \InvalidArgumentException( $overridden ? __( 'Выбранный ПВЗ Яндекс не принимает отправления.', 'walls-delivery-calc' ) : __( 'Сохранённый ПВЗ отправления Яндекс недоступен. Выберите другой ПВЗ.', 'walls-delivery-calc' ) );
		}
		if ( '' === trim( (string) ( $row['platform_station_id'] ?? '' ) ) ) {
			throw new \InvalidArgumentException( __( 'ПВЗ отправления Яндекс не найден.', 'walls-delivery-calc' ) );
		}
	}

	private function public_shipment_error_message( string $message ): string {
		$message = trim( $message );
		if ( '' === $message ) {
			return __( 'Проверьте данные отправления.', 'walls-delivery-calc' );
		}
		if ( str_contains( $message, "\n" ) ) {
			$messages = array();
			foreach ( preg_split( '/\R+/', $message ) ?: array() as $line ) {
				$translated = $this->public_shipment_error_message( (string) $line );
				if ( '' !== $translated && ! in_array( $translated, $messages, true ) ) {
					$messages[] = $translated;
				}
			}

			return array() !== $messages ? implode( "\n", $messages ) : __( 'Проверьте данные отправления.', 'walls-delivery-calc' );
		}

		$translations = array(
			'amount must be greater than 0' => __( 'Укажите количество товара больше 0.', 'walls-delivery-calc' ),
			'ordered_quantity must be greater than 0' => __( 'Укажите исходное количество товара больше 0.', 'walls-delivery-calc' ),
			'weight must be greater than 0' => __( 'Укажите вес товара больше 0.', 'walls-delivery-calc' ),
			'weight_g must be greater than 0' => __( 'Укажите вес грузоместа.', 'walls-delivery-calc' ),
			'length_cm must be greater than 0' => __( 'Укажите длину грузоместа.', 'walls-delivery-calc' ),
			'width_cm must be greater than 0' => __( 'Укажите ширину грузоместа.', 'walls-delivery-calc' ),
			'height_cm must be greater than 0' => __( 'Укажите высоту грузоместа.', 'walls-delivery-calc' ),
			'cost must be greater than or equal to 0' => __( 'Укажите стоимость товара.', 'walls-delivery-calc' ),
			'must contain item_key' => __( 'Не удалось определить товар в строке распределения.', 'walls-delivery-calc' ),
			'references an unknown shipment place' => __( 'Строка товара ссылается на несуществующее грузоместо.', 'walls-delivery-calc' ),
			'must contain at least one allocation row' => __( 'Каждое грузоместо должно содержать хотя бы один товар.', 'walls-delivery-calc' ),
			'CDEK allocation rows must not be empty' => __( 'Добавьте товары в грузоместа.', 'walls-delivery-calc' ),
			'allocation must contain at least one item' => __( 'Добавьте хотя бы один товар в отправление.', 'walls-delivery-calc' ),
			'shipment place must contain at least one item' => __( 'Каждое грузоместо должно содержать хотя бы один товар.', 'walls-delivery-calc' ),
		);
		foreach ( $translations as $needle => $translation ) {
			if ( str_contains( $message, $needle ) ) {
				return $translation;
			}
		}
		if ( preg_match( '/\b(must|failed|invalid|unknown|error|missing|required)\b/i', $message ) && ! preg_match( '/[А-Яа-яЁё]/u', $message ) ) {
			return __( 'Проверьте данные отправления.', 'walls-delivery-calc' );
		}

		return $message;
	}

	private function maybe_prepare_cdek_courier_address( object $order, array &$data ): array {
		$carrier_key = sanitize_key( wp_unslash( $data['carrier_key'] ?? '' ) );
		$delivery_type = RussianPostDomesticSettings::normalize_delivery_type( sanitize_key( wp_unslash( $data['delivery_type'] ?? '' ) ) );
		if ( CdekSettings::CARRIER_KEY !== $carrier_key || DeliveryType::COURIER !== $delivery_type ) {
			return array( 'error' => '' );
		}
		$original_address = sanitize_text_field( wp_unslash( $data['courier_original_address'] ?? $data['original_address'] ?? '' ) );
		$snapshot = $this->decoded_json_field( $data['normalized_address_json'] ?? '' );
		$location_context = $this->recipient_location_context_from_request( $order, $data );
		$valid = $this->cdek_normalized_snapshot_valid( $snapshot, $original_address, (string) ( $location_context['country_code'] ?? '' ) );
		if ( $valid ) {
			return array( 'error' => '' );
		}
		if ( ! $this->cdek_address_preparation instanceof CdekRecipientAddressPreparationService ) {
			return array( 'error' => __( 'Нормализация адреса СДЭК недоступна.', 'walls-delivery-calc' ) );
		}
		$prepared = $this->cdek_address_preparation->prepare( $order, $original_address, $location_context, CdekSettings::SERVICE_KEY );
		$data['normalized_address_json'] = wp_json_encode( $prepared, JSON_UNESCAPED_UNICODE ) ?: '';
		if ( empty( $prepared['success'] ) ) {
			return array( 'error' => (string) ( $prepared['message'] ?? CdekRecipientAddressPreparationService::CITY_CODE_ERROR ) );
		}

		return array( 'error' => '' );
	}

	private function maybe_prepare_ozon_courier_address( object $order, array &$data ): array {
		$carrier_key = sanitize_key( wp_unslash( $data['carrier_key'] ?? '' ) );
		$delivery_type = RussianPostDomesticSettings::normalize_delivery_type( sanitize_key( wp_unslash( $data['delivery_type'] ?? '' ) ) );
		if ( OzonDeliverySettings::CARRIER_KEY !== $carrier_key || DeliveryType::COURIER !== $delivery_type ) {
			return array( 'error' => '' );
		}
		if ( ! $this->ozon_courier_addresses instanceof OzonDeliveryCourierAddressNormalizer ) {
			return array( 'error' => __( 'Нормализация адреса Ozon недоступна.', 'walls-delivery-calc' ) );
		}
		$original_address = sanitize_text_field( wp_unslash( $data['courier_original_address'] ?? $data['original_address'] ?? '' ) );
		$prepared = $this->ozon_courier_addresses->normalize( $original_address, $this->ozon_address_context_from_request( $order, $data ) );
		$data['normalized_address_json'] = wp_json_encode( $prepared, JSON_UNESCAPED_UNICODE ) ?: '';
		if ( empty( $prepared['success'] ) ) {
			return array( 'error' => (string) ( $prepared['message'] ?? __( 'Адрес Ozon не подтвержден.', 'walls-delivery-calc' ) ) );
		}

		return array( 'error' => '' );
	}

	/** @return array<string,string> */
	private function ozon_address_context_from_request( object $order, array $data ): array {
		return array_filter(
			array(
				'country_code' => 'RU',
				'selected_location_id' => sanitize_text_field( wp_unslash( $data['recipient_location_id'] ?? $data['location_id'] ?? ( method_exists( $order, 'get_meta' ) ? (string) $order->get_meta( '_wdc_platform_location_id', true ) : '' ) ) ),
				'selected_location_fias_id' => sanitize_text_field( wp_unslash( $data['recipient_location_fias_id'] ?? $data['fias_id'] ?? ( method_exists( $order, 'get_meta' ) ? (string) $order->get_meta( '_wdc_platform_location_fias_id', true ) : '' ) ) ),
			),
			static fn( string $value ): bool => '' !== trim( $value )
		);
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
		$country_code = '' === $country_code ? 'RU' : $country_code;

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

	/**
	 * @param array<string,mixed> $snapshot
	 */
	private function cdek_normalized_snapshot_valid( array $snapshot, string $original_address, string $current_country ): bool {
		$source = (string) ( $snapshot['source'] ?? '' );
		if ( empty( $snapshot['success'] ) || ! in_array( $source, array( 'dadata+cdek_location', 'cdek_eaeu_raw_address' ), true ) ) {
			return false;
		}
		if ( (string) ( $snapshot['original_hash'] ?? '' ) !== hash( 'sha256', trim( $original_address ) ) ) {
			return false;
		}
		$fields = is_array( $snapshot['fields'] ?? null ) ? $snapshot['fields'] : array();
		if ( (int) ( $fields['cdek_city_code'] ?? 0 ) <= 0 ) {
			return false;
		}
		$current_country = strtoupper( trim( $current_country ) );
		$snapshot_country = strtoupper( trim( (string) ( $fields['country_code'] ?? $snapshot['country_code'] ?? '' ) ) );
		if ( '' === $current_country ) {
			$current_country = 'RU';
		}
		if ( 'dadata+cdek_location' === $source ) {
			$snapshot_country = '' === $snapshot_country ? 'RU' : $snapshot_country;
			return 'RU' === $current_country && 'RU' === $snapshot_country;
		}
		if ( 'cdek_eaeu_raw_address' === $source ) {
			return in_array( $snapshot_country, array( 'AM', 'BY', 'KZ', 'KG' ), true ) && $snapshot_country === $current_country;
		}

		return false;
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

	private function preview_request( \WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest $request ): \WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest {
		return new \WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest(
			$request->order_id,
			$request->carrier_key,
			$request->delivery_type,
			$request->rate_id,
			$request->recipient_address,
			$request->pickup_point,
			$request->places,
			$request->declared_value,
			$request->insurance_enabled,
			$request->services,
			$request->recipient,
			array_merge( $request->meta, array( 'allow_failed_normalization_preview' => true ) )
		);
	}

	private function yandex_pickup_points(): YandexDeliveryPickupPointV2Repository {
		if ( ! $this->yandex_pickup_points instanceof YandexDeliveryPickupPointV2Repository ) {
			$this->yandex_pickup_points = new YandexDeliveryPickupPointV2Repository();
		}

		return $this->yandex_pickup_points;
	}

}
