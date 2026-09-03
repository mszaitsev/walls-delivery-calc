<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointScheduleFormatter;
use WallsShop\WDC\Carriers\Dpd\Pickup\DpdPickupPointService;
use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\Checkout\PekCheckoutQuoteContextResolver;
use WallsShop\WDC\Carriers\Pek\PekCountryPolicy;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Carriers\Pek\Pickup\PekCheckoutPickupPointFormatter;
use WallsShop\WDC\Carriers\YandexDelivery\LocationMappingV2\YandexLocationMappingV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryCheckoutPickupPointFormatter;
use WallsShop\WDC\Carriers\YandexDelivery\Pickup\YandexDeliveryPickupPointV2Repository;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationAjax;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Orders\Application\OrderDeliveryAddressNormalizationService;
use WallsShop\WDC\Orders\Application\OrderDeliveryRecalculationService;
use WallsShop\WDC\Orders\Application\OrderDeliveryReplacementService;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderRegistry;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\PickupCargoConstraints;
use WallsShop\WDC\Pickup\Cdek\CdekDeliveryPointService;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointTypeSettings;

defined( 'ABSPATH' ) || exit;

final class OrderDeliveryRecalculationAdminController {
	public const AJAX_PREVIEW = 'wdc_order_delivery_recalculate_preview';
	public const AJAX_LOCATION_SEARCH = 'wdc_order_delivery_recalculate_location_search';
	public const AJAX_PICKUP_SEARCH = 'wdc_order_delivery_recalculate_pickup_search';
	public const AJAX_NORMALIZE_ADDRESS = 'wdc_order_delivery_recalculate_normalize_address';
	public const AJAX_ADDRESS_SUGGEST = 'wdc_order_delivery_recalculate_address_suggest';
	public const AJAX_GEOCODE_ADDRESS = 'wdc_order_delivery_recalculate_geocode_address';
	public const AJAX_SAVE = 'wdc_order_delivery_recalculate_save';
	public const NONCE_ACTION = 'wdc_order_delivery_recalculation';

	public function __construct(
		private OrderDeliveryRecalculationService $service,
		private OrderDeliveryRateRenderer $renderer,
		private CheckoutLocationAjax $location_search,
		private RussianPostPickupPointRepository $pickup_points,
		private OrderDeliveryAddressNormalizationService $address_normalization,
		private OrderDeliveryReplacementService $replacement,
		private YandexDeliveryCheckoutPickupPointFormatter $yandex_formatter,
		private SettingsRepository $settings,
		private RussianPostPickupPointTypeSettings $pickup_point_type_settings,
		private DpdPickupPointScheduleFormatter $dpd_schedule_formatter,
		private CarrierPickupPointProviderRegistry $pickup_providers,
		private PekCheckoutQuoteContextResolver $pek_quote_context,
		private PekCheckoutPickupPointFormatter $pek_formatter,
		private PekCountryPolicy $pek_countries,
		private string $plugin_url = '',
		private string $version = '1',
		private ?CdekDeliveryPointService $cdek_points = null,
		private ?DpdPickupPointService $dpd_points = null,
		private ?YandexDeliveryPickupPointV2Repository $yandex_points = null,
		private ?YandexLocationMappingV2Repository $yandex_location_mapping = null
	) {
	}

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREVIEW, array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_' . self::AJAX_LOCATION_SEARCH, array( $this, 'ajax_location_search' ) );
		add_action( 'wp_ajax_' . self::AJAX_PICKUP_SEARCH, array( $this, 'ajax_pickup_search' ) );
		add_action( 'wp_ajax_' . self::AJAX_NORMALIZE_ADDRESS, array( $this, 'ajax_normalize_address' ) );
		add_action( 'wp_ajax_' . self::AJAX_ADDRESS_SUGGEST, array( $this, 'ajax_address_suggest' ) );
		add_action( 'wp_ajax_' . self::AJAX_GEOCODE_ADDRESS, array( $this, 'ajax_geocode_address' ) );
		add_action( 'wp_ajax_' . self::AJAX_SAVE, array( $this, 'ajax_save' ) );
	}

	public function enqueue_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id = is_object( $screen ) ? (string) ( $screen->id ?? '' ) : '';
		if ( ! in_array( $id, array( 'shop_order', 'woocommerce_page_wc-orders' ), true ) ) {
			return;
		}

		$provider = $this->map_provider();
		$provider_handle = 'wdc-map-provider-' . $provider;
		if ( 'leaflet' === $provider ) {
			wp_enqueue_style( 'wdc-leaflet', $this->plugin_url . 'assets/vendor/leaflet/leaflet.css', array(), '1.9.4' );
			wp_enqueue_script( 'wdc-leaflet', $this->plugin_url . 'assets/vendor/leaflet/leaflet.js', array(), '1.9.4', true );
			wp_enqueue_script( $provider_handle, $this->plugin_url . 'assets/frontend/pickup-map/providers/wdc-map-provider-leaflet.js', array( 'wdc-leaflet' ), $this->version, true );
		} else {
			wp_enqueue_script( $provider_handle, $this->plugin_url . 'assets/frontend/pickup-map/providers/wdc-map-provider-yandex.js', array(), $this->version, true );
		}

		wp_enqueue_style( 'wdc-pickup-map', $this->plugin_url . 'assets/frontend/pickup-map/wdc-pickup-map.css', array(), $this->version );
		wp_enqueue_style( 'wdc-order-delivery-recalculation', $this->plugin_url . 'assets/admin/order-delivery-recalculation.css', array( 'wdc-pickup-map' ), $this->version );
		wp_enqueue_script( 'wdc-order-delivery-recalculation', $this->plugin_url . 'assets/admin/order-delivery-recalculation.js', array( $provider_handle ), $this->version, true );
		wp_localize_script(
			'wdc-order-delivery-recalculation',
			'wdcOrderDeliveryRecalculation',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( self::NONCE_ACTION ),
				'action'  => self::AJAX_PREVIEW,
				'locationSearchAction' => self::AJAX_LOCATION_SEARCH,
				'pickupSearchAction' => self::AJAX_PICKUP_SEARCH,
				'normalizeAddressAction' => self::AJAX_NORMALIZE_ADDRESS,
				'addressSuggestAction' => self::AJAX_ADDRESS_SUGGEST,
				'geocodeAddressAction' => self::AJAX_GEOCODE_ADDRESS,
				'saveAction' => self::AJAX_SAVE,
				'mapProvider' => $provider,
				'yandexApiKeyPresent' => '' !== $this->yandex_api_key(),
				'yandexApiKey' => 'yandex' === $provider ? $this->yandex_api_key() : '',
				'pickupPointTypes' => $this->pickup_point_type_settings->all(),
			)
		);
	}

	public function ajax_preview(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}

		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ) ), 404 );
		}

		$selected_location = $this->selected_location_from_request() ?? array();
		if ( $this->positive_location_id( $selected_location ) <= 0 ) {
			wp_send_json_error( array( 'message' => __( 'Выберите населенный пункт из базы перед расчетом доставки.', 'walls-delivery-calc' ) ), 400 );
		}

		$result = $this->service->preview( $order, $selected_location, $this->array_from_request( 'selected_pickup_point' ) );
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( array( 'message' => (string) $result['message'] ), 400 );
		}

		wp_send_json_success(
			array(
				'html'    => $this->renderer->render( $result['rates'] ),
				'rates'   => $result['rates'],
				'request' => $result['request'],
				'location' => $result['location'],
			)
		);
	}

	public function ajax_location_search(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		$query = $this->request_string( 'query' );
		$country = $this->request_string( 'country_code' );
		$payload = $this->location_search->payload( $query, '', $country );
		wp_send_json_success( $payload );
	}

	public function ajax_pickup_search(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}

		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ) ), 404 );
		}

		$location = $this->selected_location_from_request() ?? array();
		$rate = $this->array_from_request( 'selected_rate' );
		$query = $this->request_string( 'query' );
		$mode = 'location' === $this->request_string( 'mode' ) ? 'location' : 'search';
		$limit = max( 1, min( 'location' === $mode ? 2000 : 100, (int) ( $_POST['limit'] ?? ( 'location' === $mode ? 2000 : 50 ) ) ) );
		$carrier = (string) ( $rate['carrier_key'] ?? $rate['service_key'] ?? '' );
		if ( DpdSettings::CARRIER_KEY === $carrier ) {
			$rows = $this->dpd_pickup_points( $location, $query, $mode, $limit );
			wp_send_json_success(
				array(
					'points' => array_map( array( $this, 'pickup_point_payload' ), $rows ),
				)
			);
		}
		if ( YandexDeliverySettings::CARRIER_KEY === $carrier ) {
			if ( $this->positive_location_id( $location ) <= 0 ) {
				$resolved_location = $this->service->resolved_location_payload( $order, array() === $location ? null : $location );
				if ( $this->positive_location_id( $resolved_location ) <= 0 && array() !== $location ) {
					$resolved_location = $this->service->resolved_location_payload( $order, null );
				}
				$location = $this->merge_resolved_location_payload( $location, $resolved_location );
			}
			wp_send_json_success( array( 'points' => $this->yandex_pickup_points( $location, $query, $mode ) ) );
		}
		if ( 'cdek' === $carrier ) {
			$rows = $this->cdek_pickup_points( $location, $query, $mode, $limit );
			wp_send_json_success(
				array(
					'points' => array_map( array( $this, 'pickup_point_payload' ), $rows ),
				)
			);
		}
		if ( PekSettings::CARRIER_KEY === $carrier ) {
			try {
				$points = $this->pek_pickup_points( $order, $rate, $location );
			} catch ( PekApiException|\RuntimeException $exception ) {
				wp_send_json_error( array( 'message' => $this->safe_message( $exception->getMessage() ) ), 400 );
			}
			wp_send_json_success( array( 'points' => $points ) );
		}
		try {
			$registry_points = $this->registry_pickup_points_from_rate( $order, $rate, $location );
		} catch ( \RuntimeException $exception ) {
			wp_send_json_error( array( 'message' => $this->safe_message( $exception->getMessage() ) ), 400 );
		}
		if ( null !== $registry_points ) {
			wp_send_json_success( array( 'points' => $registry_points ) );
		}
		$postcode = preg_replace( '/\D+/', '', (string) ( $location['postal_code'] ?? $location['postcode'] ?? '' ) ) ?? '';
		if ( 'location' === $mode ) {
			$rows = $this->pickup_rows_for_location( $location, $limit, $postcode );
		} elseif ( '' !== $query ) {
			$rows = $this->pickup_points->search_admin_pickup_rows( $query, array( 'limit' => $limit ) );
		} elseif ( '' !== $postcode ) {
			$rows = $this->pickup_points->find_rows_by_postcode( $postcode, array( 'limit' => $limit ) );
		} else {
			$rows = array();
		}

		wp_send_json_success(
			array(
				'points' => array_map( array( $this, 'pickup_point_payload' ), $rows ),
			)
		);
	}

	public function ajax_normalize_address(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}

		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ) ), 404 );
		}

		$result = $this->address_normalization->normalize(
			$order,
			$this->selected_location_from_request() ?? array(),
			$this->request_string( 'address_line' )
		);
		if ( ! empty( $result['requires_selection'] ) ) {
			wp_send_json_success(
				array(
					'message' => (string) ( $result['message'] ?? __( 'Выберите подходящий адрес из вариантов.', 'walls-delivery-calc' ) ),
					'address' => $result['payload'] ?? array(),
					'requires_selection' => true,
					'suggestions' => $result['suggestions'] ?? array(),
				)
			);
		}
		if ( empty( $result['success'] ) ) {
			wp_send_json_error(
				array(
					'message' => (string) ( $result['message'] ?? __( 'Адрес не нормализован.', 'walls-delivery-calc' ) ),
					'address' => $result['payload'] ?? array(),
					'suggestions' => $result['suggestions'] ?? array(),
				),
				400
			);
		}

		wp_send_json_success(
			array(
				'message' => (string) ( $result['message'] ?? __( 'Адрес нормализован.', 'walls-delivery-calc' ) ),
				'address' => $result['payload'] ?? array(),
				'suggestions' => $result['suggestions'] ?? array(),
			)
		);
	}

	public function ajax_geocode_address(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}

		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ) ), 404 );
		}

		$result = $this->address_normalization->geocode(
			$order,
			$this->selected_location_from_request() ?? array(),
			$this->request_string( 'address_line' )
		);
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( array( 'message' => (string) ( $result['message'] ?? __( 'Адрес не найден.', 'walls-delivery-calc' ) ) ), 400 );
		}

		wp_send_json_success( $result );
	}

	public function ajax_address_suggest(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}

		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ) ), 404 );
		}

		$result = $this->address_normalization->suggest(
			$order,
			$this->selected_location_from_request() ?? array(),
			$this->request_string( 'stage' ),
			$this->request_string( 'query' ),
			$this->array_from_request( 'context' )
		);
		if ( empty( $result['success'] ) ) {
			wp_send_json_error(
				array(
					'message' => (string) ( $result['message'] ?? __( 'Подсказки адреса недоступны.', 'walls-delivery-calc' ) ),
					'items' => $result['items'] ?? array(),
				),
				400
			);
		}

		wp_send_json_success(
			array(
				'items' => $result['items'] ?? array(),
			)
		);
	}

	public function ajax_save(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}

		$order_id = (int) ( $_POST['order_id'] ?? 0 );
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) ) {
			wp_send_json_error( array( 'message' => __( 'Заказ не найден.', 'walls-delivery-calc' ) ), 404 );
		}

		$result = $this->replacement->save(
			$order,
			array(
				'selected_location' => $this->selected_location_from_request() ?? array(),
				'selected_rate' => $this->array_from_request( 'selected_rate' ),
				'selected_tariff' => $this->array_from_request( 'selected_tariff' ),
				'selected_pickup_point' => $this->array_from_request( 'selected_pickup_point' ),
				'normalized_shipping_address' => $this->array_from_request( 'normalized_shipping_address' ),
			)
		);
		if ( empty( $result['success'] ) ) {
			wp_send_json_error( array( 'message' => (string) $result['message'] ), 400 );
		}

		wp_send_json_success( array( 'message' => (string) $result['message'] ) );
	}

	/**
	 * @return array<string,mixed>|null
	 */
	private function selected_location_from_request(): ?array {
		$raw = $_POST['selected_location'] ?? null;
		if ( null === $raw ) {
			return null;
		}
		if ( is_string( $raw ) ) {
			$raw = function_exists( 'wp_unslash' ) ? wp_unslash( $raw ) : $raw;
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $this->sanitize_location_payload( $decoded ) : null;
		}
		return is_array( $raw ) ? $this->sanitize_location_payload( $raw ) : null;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function array_from_request( string $key ): array {
		$raw = $_POST[ $key ] ?? null;
		if ( null === $raw ) {
			return array();
		}
		if ( is_string( $raw ) ) {
			$raw = function_exists( 'wp_unslash' ) ? wp_unslash( $raw ) : $raw;
			$decoded = json_decode( $raw, true );
			return is_array( $decoded ) ? $this->sanitize_payload( $decoded ) : array();
		}

		return is_array( $raw ) ? $this->sanitize_payload( $raw ) : array();
	}

	/**
	 * @param array<string|int,mixed> $payload
	 * @return array<string|int,mixed>
	 */
	private function sanitize_payload( array $payload ): array {
		$clean = array();
		foreach ( $payload as $key => $value ) {
			$clean_key = is_int( $key ) ? $key : $this->clean_string( (string) $key );
			if ( is_array( $value ) ) {
				$clean[ $clean_key ] = $this->sanitize_payload( $value );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$clean[ $clean_key ] = null === $value ? null : $this->clean_string( (string) $value );
			}
		}

		return $clean;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function sanitize_location_payload( array $payload ): array {
		$allowed = array(
			'id',
			'location_id',
			'fias_id',
			'gar_id',
			'gar_object_id',
			'country_code',
			'city_code',
			'cdek_city_code',
			'dpd_city_id',
			'dpd_receiver_city_id',
			'region_name',
			'region_type',
			'region_code',
			'state_value',
			'district_name',
			'district_type',
			'city_name',
			'city_type',
			'city_fias_id',
			'city_kladr_id',
			'city_value',
			'place_name',
			'place_type',
			'settlement_name',
			'settlement_type',
			'settlement_fias_id',
			'settlement_kladr_id',
			'display_name',
			'option_label',
			'label',
			'postal_code',
			'postcode',
			'lat',
			'lng',
			'latitude',
			'longitude',
		);
		$clean = array();
		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $payload ) ) {
				continue;
			}
			$value = $payload[ $key ];
			$clean[ $key ] = is_scalar( $value ) ? $this->clean_string( (string) $value ) : $value;
		}

		return $clean;
	}

	private function request_string( string $key ): string {
		$value = $_POST[ $key ] ?? $_REQUEST[ $key ] ?? '';
		if ( is_array( $value ) ) {
			return '';
		}
		return $this->clean_string( (string) $value );
	}

	private function clean_string( string $value ): string {
		$value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( $value );
	}

	private function safe_message( string $message ): string {
		$message = trim( function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $message ) : strip_tags( $message ) );
		return '' !== $message ? $message : __( 'Не удалось загрузить пункты ПЭК. Пересчитайте доставку и попробуйте ещё раз.', 'walls-delivery-calc' );
	}

	/**
	 * @param array<string,mixed> $location
	 */
	private function positive_location_id( array $location ): int {
		foreach ( array( 'id', 'location_id' ) as $key ) {
			$value = $location[ $key ] ?? null;
			if ( is_numeric( $value ) && (int) $value > 0 ) {
				return (int) $value;
			}
		}

		return 0;
	}

	/**
	 * @param array<string,mixed> $current
	 * @param array<string,mixed> $resolved
	 * @return array<string,mixed>
	 */
	private function merge_resolved_location_payload( array $current, array $resolved ): array {
		if ( array() === $resolved ) {
			return $current;
		}
		$merged = $resolved;
		foreach ( $current as $key => $value ) {
			if ( null !== $value && '' !== $value ) {
				$merged[ $key ] = $value;
			}
		}
		$current_id = $this->positive_location_id( $current );
		$resolved_id = $this->positive_location_id( $resolved );
		$location_id = $current_id > 0 ? $current_id : $resolved_id;
		if ( $location_id > 0 ) {
			$merged['id'] = $location_id;
			$merged['location_id'] = $location_id;
		}

		return $merged;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function pickup_point_payload( array $row ): array {
		$point_code = (string) ( $row['point_code'] ?? '' );
		$postcode = (string) ( $row['point_postcode'] ?? $row['postcode'] ?? $row['postal_code'] ?? '' );
		$address = (string) ( $row['point_address'] ?? $row['address'] ?? '' );
		return array(
			'id' => (string) ( $row['id'] ?? ( (string) ( $row['carrier_key'] ?? '' ) === 'cdek' ? 'cdek:' . $point_code : '' ) ),
			'carrier_key' => (string) ( $row['carrier_key'] ?? ( (string) ( $row['carrier'] ?? '' ) === 'cdek' ? 'cdek' : 'russian_post_domestic' ) ),
			'point_code' => $point_code,
			'point_type' => (string) ( $row['point_type'] ?? 'OPS' ),
			'point_name' => (string) ( $row['point_name'] ?? $row['name'] ?? $postcode ),
			'point_address' => $address,
			'point_postcode' => $postcode,
			'postcode' => $postcode,
			'region_name' => (string) ( $row['region_name'] ?? '' ),
			'city_name' => (string) ( $row['city_name'] ?? '' ),
			'address' => $address,
			'lat' => null !== ( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null,
			'lng' => null !== ( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null,
			'work_time' => (string) ( $row['work_time'] ?? '' ),
			'description' => (string) ( $row['description'] ?? $row['cdek_note'] ?? '' ),
			'storage_notice' => (string) ( $row['storage_notice'] ?? '' ),
			'raw_sanitized' => is_array( $row['raw_sanitized'] ?? null ) ? $row['raw_sanitized'] : ( is_array( $row['raw'] ?? null ) ? $row['raw'] : array() ),
			'cdek_code' => (string) ( $row['cdek_code'] ?? '' ),
			'cdek_uuid' => (string) ( $row['cdek_uuid'] ?? '' ),
			'cdek_type' => (string) ( $row['cdek_type'] ?? '' ),
			'cdek_owner_code' => (string) ( $row['cdek_owner_code'] ?? '' ),
			'cdek_nearest_station' => (string) ( $row['cdek_nearest_station'] ?? '' ),
			'cdek_note' => (string) ( $row['cdek_note'] ?? '' ),
			'terminal_code' => (string) ( $row['terminal_code'] ?? '' ),
			'dpd_source' => (string) ( $row['dpd_source'] ?? $row['source'] ?? '' ),
			'point_raw' => $row,
		);
	}

	/**
	 * @param array<string,mixed> $location
	 * @return array<int,array<string,mixed>>
	 */
	private function cdek_pickup_points( array $location, string $query, string $mode, int $limit ): array {
		if ( ! $this->cdek_points instanceof CdekDeliveryPointService ) {
			return array();
		}
		$points = $this->cdek_points->pointsForLocation( $location );
		if ( 'search' === $mode && '' !== $query ) {
			$needle = $this->normalize_search_text( $query );
			$points = array_values(
				array_filter(
					$points,
					fn( array $point ): bool => str_contains(
						$this->normalize_search_text(
							implode(
								' ',
								array(
									(string) ( $point['point_code'] ?? '' ),
									(string) ( $point['point_name'] ?? '' ),
									(string) ( $point['point_address'] ?? '' ),
									(string) ( $point['point_postcode'] ?? '' ),
								)
							)
						),
						$needle
					)
				)
			);
		}

		unset( $limit );

		return $points;
	}

	/**
	 * @param array<string,mixed> $location
	 * @return array<int,array<string,mixed>>
	 */
	private function dpd_pickup_points( array $location, string $query, string $mode, int $limit ): array {
		if ( ! $this->dpd_points instanceof DpdPickupPointService ) {
			return array();
		}
		$location_id = isset( $location['id'] ) && is_numeric( $location['id'] ) ? (int) $location['id'] : 0;
		$city_id = isset( $location['dpd_city_id'] ) && is_numeric( $location['dpd_city_id'] ) ? (int) $location['dpd_city_id'] : 0;
		if ( $city_id <= 0 && isset( $location['dpd_receiver_city_id'] ) && is_numeric( $location['dpd_receiver_city_id'] ) ) {
			$city_id = (int) $location['dpd_receiver_city_id'];
		}
		if ( 'location' === $mode ) {
			$points = $city_id > 0
				? $this->dpd_points->get_parcel_shops_by_city_id( $city_id, $limit )
				: ( $location_id > 0 ? $this->dpd_points->get_points_for_location_id( $location_id ) : array() );
		} elseif ( '' !== $query ) {
			$filters = array( 'limit' => $limit );
			if ( $city_id > 0 ) {
				$filters['city_id'] = $city_id;
			} elseif ( '' !== trim( (string) ( $location['display_name'] ?? $location['city_value'] ?? '' ) ) ) {
				$filters['city_name'] = trim( (string) ( $location['display_name'] ?? $location['city_value'] ?? '' ) );
			}
			$points = $this->dpd_points->search_parcel_shops( $query, $filters );
		} else {
			$points = array();
		}

		return array_slice( array_map( array( $this, 'dpd_pickup_point_payload' ), $points ), 0, $limit );
	}

	/**
	 * @param array<string,mixed> $point
	 * @return array<string,mixed>
	 */
	private function dpd_pickup_point_payload( array $point ): array {
		$type = (string) ( $point['type'] ?? '' );
		$type_label = 'terminal_self_delivery' === $type ? 'Терминал' : 'Пункт выдачи';
		$point_title = 'terminal_self_delivery' === $type ? 'Терминал DPD' : 'Пункт выдачи DPD';
		$marker_type = 'terminal_self_delivery' === $type ? 'terminal' : 'pickup';
		$code = (string) ( $point['terminal_code'] ?? '' );
		$work_time = $this->dpd_schedule_formatter->format( $point['schedule'] ?? '' );
		$snapshot = array(
			'id' => DpdSettings::CARRIER_KEY . ':' . $code,
			'carrier_key' => DpdSettings::CARRIER_KEY,
			'service_key' => DpdSettings::SERVICE_KEY,
			'pickup_family' => DpdSettings::CARRIER_KEY . ':pickup',
			'point_code' => $code,
			'terminal_code' => $code,
			'point_type' => $type,
			'point_type_label' => $type_label,
			'point_title' => $point_title,
			'display_code' => $code,
			'display_title' => trim( $point_title . ' ' . $code ),
			'marker_type' => $marker_type,
			'point_name' => (string) ( $point['name'] ?? '' ),
			'address' => (string) ( $point['address'] ?? '' ),
			'city' => (string) ( $point['city_name'] ?? '' ),
			'region' => (string) ( $point['region_name'] ?? '' ),
			'lat' => $point['latitude'] ?? null,
			'lng' => $point['longitude'] ?? null,
			'work_time' => $work_time,
			'description' => '',
			'dpd_source' => (string) ( $point['source'] ?? '' ),
		);

		return array(
			'id' => $snapshot['id'],
			'carrier' => DpdSettings::CARRIER_KEY,
			'carrier_key' => DpdSettings::CARRIER_KEY,
			'service_key' => DpdSettings::SERVICE_KEY,
			'pickup_family' => $snapshot['pickup_family'],
			'point_code' => $snapshot['point_code'],
			'terminal_code' => $snapshot['terminal_code'],
			'point_type' => $snapshot['point_type'],
			'point_type_label' => $snapshot['point_type_label'],
			'point_title' => $snapshot['point_title'],
			'card_title' => $snapshot['point_title'],
			'display_code' => $snapshot['display_code'],
			'display_title' => $snapshot['display_title'],
			'marker_type' => $snapshot['marker_type'],
			'title' => $snapshot['point_name'],
			'point_name' => $snapshot['point_name'],
			'address' => $snapshot['address'],
			'point_address' => $snapshot['address'],
			'city' => $snapshot['city'],
			'city_name' => $snapshot['city'],
			'region' => $snapshot['region'],
			'region_name' => $snapshot['region'],
			'lat' => $snapshot['lat'],
			'lng' => $snapshot['lng'],
			'latitude' => $snapshot['lat'],
			'longitude' => $snapshot['lng'],
			'work_time' => $snapshot['work_time'],
			'schedule' => $snapshot['work_time'],
			'description' => $snapshot['description'],
			'dpd_source' => $snapshot['dpd_source'],
			'source' => $snapshot['dpd_source'],
			'snapshot' => $snapshot,
		);
	}

	private function normalize_search_text( string $value ): string {
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );

		return trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
	}

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $location
	 * @return array<int,array<string,mixed>>
	 */
	private function pek_pickup_points( object $order, array $rate, array $location ): array {
		$query = $this->pek_pickup_query_from_rate( $order, $rate, $location );
		$provider = $this->pickup_providers->get( PekSettings::CARRIER_KEY );
		if ( null === $provider ) {
			throw new \RuntimeException( 'Пункты выдачи ПЭК временно недоступны.' );
		}
		$fingerprint = $this->pek_provider_fingerprint( $this->pek_pickup_query_snapshot( $rate ) );

		return array_map(
			fn( $point ): array => $this->pek_formatter->format( $point, $fingerprint, $query->location_id, $query->country_code ),
			$provider->search( $query )
		);
	}

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $location
	 */
	private function pek_pickup_query_from_rate( object $order, array $rate, array $location ): CarrierPickupPointQuery {
		if (
			DeliveryType::PICKUP !== (string) ( $rate['delivery_type'] ?? '' )
			|| empty( $rate['requires_pickup_point'] )
		) {
			throw new \RuntimeException( 'Для ПЭК выберите pickup-вариант доставки и пересчитайте доставку.' );
		}
		$snapshot = $this->pek_pickup_query_snapshot( $rate );
		if (
			PekSettings::CARRIER_KEY !== (string) ( $snapshot['carrier_key'] ?? '' )
			|| CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP !== (string) ( $snapshot['purpose'] ?? '' )
		) {
			throw new \RuntimeException( 'Контекст пунктов ПЭК устарел. Пересчитайте доставку.' );
		}
		$snapshot_country = strtoupper( trim( (string) ( $snapshot['country_code'] ?? '' ) ) );
		if ( ! $this->pek_countries->supports_calculation_direction( $this->pek_countries->sender_country(), $snapshot_country ) ) {
			throw new \RuntimeException( 'Контекст пунктов ПЭК устарел. Пересчитайте доставку.' );
		}
		$snapshot_location_id = (int) ( $snapshot['location_id'] ?? 0 );
		$current_location_id = $this->current_location_id_for_pickup( $order, $location );
		if ( $snapshot_location_id <= 0 || $current_location_id <= 0 || $snapshot_location_id !== $current_location_id ) {
			throw new \RuntimeException( 'Населенный пункт изменился. Пересчитайте доставку перед выбором пункта ПЭК.' );
		}
		$current_country = $this->current_country_code_for_pickup( $order, $location );
		if ( '' === $current_country || $snapshot_country !== $current_country ) {
			throw new \RuntimeException( 'Страна пункта ПЭК не соответствует текущему месту доставки. Пересчитайте доставку.' );
		}
		if ( ! $this->looks_like_sha256( $this->pek_provider_fingerprint( $snapshot ) ) ) {
			throw new \RuntimeException( 'Контекст пунктов ПЭК устарел. Пересчитайте доставку.' );
		}
		$query = $this->pek_quote_context->query_from_snapshot( $snapshot );
		if ( null === $query ) {
			throw new \RuntimeException( 'Контекст пунктов ПЭК устарел. Пересчитайте доставку.' );
		}

		return $query;
	}

	/** @param array<string,mixed> $rate @return array<string,mixed> */
	private function pek_pickup_query_snapshot( array $rate ): array {
		$meta = is_array( $rate['rate_meta'] ?? null ) ? $rate['rate_meta'] : array();
		$snapshot = is_array( $meta['pickup_provider_query'] ?? null ) ? $meta['pickup_provider_query'] : array();
		if ( array() === $snapshot ) {
			throw new \RuntimeException( 'Контекст пунктов ПЭК отсутствует. Пересчитайте доставку.' );
		}

		return $snapshot;
	}

	/** @param array<string,mixed> $snapshot */
	private function pek_provider_fingerprint( array $snapshot ): string {
		$fingerprint = (string) ( $snapshot['provider_destination_fingerprint'] ?? '' );
		return '' !== $fingerprint ? $fingerprint : (string) ( $snapshot['destination_fingerprint'] ?? '' );
	}

	/**
	 * @param array<string,mixed> $rate
	 * @param array<string,mixed> $location
	 * @return array<int,array<string,mixed>>|null
	 */
	private function registry_pickup_points_from_rate( object $order, array $rate, array $location ): ?array {
		$meta = is_array( $rate['rate_meta'] ?? null ) ? $rate['rate_meta'] : array();
		$snapshot = is_array( $meta['pickup_provider_query'] ?? null ) ? $meta['pickup_provider_query'] : array();
		if ( array() === $snapshot ) {
			return null;
		}
		$carrier = strtolower( trim( (string) ( $snapshot['carrier_key'] ?? '' ) ) );
		if ( '' === $carrier || ! $this->pickup_providers->has( $carrier ) ) {
			return null;
		}
		$rate_carrier = strtolower( trim( (string) ( $rate['carrier_key'] ?? $rate['service_key'] ?? '' ) ) );
		$family = (string) ( $rate['pickup_family'] ?? $meta['pickup_family'] ?? ( $carrier . ':pickup' ) );
		if (
			DeliveryType::PICKUP !== (string) ( $rate['delivery_type'] ?? '' )
			|| empty( $rate['requires_pickup_point'] )
			|| $carrier !== $rate_carrier
			|| CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP !== (string) ( $snapshot['purpose'] ?? '' )
		) {
			throw new \RuntimeException( 'Контекст пунктов выдачи устарел. Пересчитайте доставку.' );
		}
		$snapshot_location_id = (int) ( $snapshot['location_id'] ?? 0 );
		$current_location_id = $this->current_location_id_for_pickup( $order, $location );
		if ( $snapshot_location_id <= 0 || $current_location_id <= 0 || $snapshot_location_id !== $current_location_id ) {
			throw new \RuntimeException( 'Населенный пункт изменился. Пересчитайте доставку перед выбором пункта выдачи.' );
		}
		$snapshot_country = strtoupper( trim( (string) ( $snapshot['country_code'] ?? '' ) ) );
		if ( '' === $snapshot_country || $snapshot_country !== $this->current_country_code_for_pickup( $order, $location ) ) {
			throw new \RuntimeException( 'Страна пункта выдачи не соответствует текущему месту доставки. Пересчитайте доставку.' );
		}
		$fingerprint = $this->registry_provider_fingerprint( $snapshot );
		if ( '' === $fingerprint ) {
			throw new \RuntimeException( 'Контекст пунктов выдачи устарел. Пересчитайте доставку.' );
		}
		$provider = $this->pickup_providers->get( $carrier );
		if ( null === $provider ) {
			return null;
		}
		$query = $this->registry_query_from_snapshot( $snapshot, $carrier );
		if ( null === $query ) {
			throw new \RuntimeException( 'Контекст пунктов выдачи устарел. Пересчитайте доставку.' );
		}

		return array_map(
			fn( PickupPoint $point ): array => $this->registry_point_payload( $point, $carrier, $family, $fingerprint, $query->location_id, $query->country_code ),
			$provider->search( $query )
		);
	}

	/** @param array<string,mixed> $snapshot */
	private function registry_query_from_snapshot( array $snapshot, string $carrier ): ?CarrierPickupPointQuery {
		$provider = $this->pickup_providers->get( $carrier );
		if ( null !== $provider && method_exists( $provider, 'query_from_snapshot' ) ) {
			$query = $provider->query_from_snapshot( $snapshot );
			return $query instanceof CarrierPickupPointQuery && array() === $query->validate() && $query->normalized_carrier_key() === $carrier ? $query : null;
		}
		$cargo = is_array( $snapshot['cargo'] ?? null ) ? $snapshot['cargo'] : array();
		$query = new CarrierPickupPointQuery(
			(string) ( $snapshot['carrier_key'] ?? $carrier ),
			(int) ( $snapshot['location_id'] ?? 0 ),
			(string) ( $snapshot['country_code'] ?? 'RU' ),
			(string) ( $snapshot['fallback_address'] ?? '' ),
			is_numeric( $snapshot['latitude'] ?? null ) ? (float) $snapshot['latitude'] : null,
			is_numeric( $snapshot['longitude'] ?? null ) ? (float) $snapshot['longitude'] : null,
			new PickupCargoConstraints(
				(int) ( $cargo['weight_g'] ?? 0 ),
				(int) ( $cargo['volume_cm3'] ?? 0 ),
				(int) ( $cargo['max_dimension_cm'] ?? 0 ),
				(int) ( $cargo['max_place_weight_g'] ?? 0 ),
				max( 1, (int) ( $cargo['places_count'] ?? 1 ) ),
				$this->registry_places_from_cargo( $cargo )
			),
			(string) ( $snapshot['purpose'] ?? CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP ),
			max( 1, (int) ( $snapshot['radius_km'] ?? 50 ) ),
			max( 1, (int) ( $snapshot['limit'] ?? 50 ) )
		);

		return array() === $query->validate() ? $query : null;
	}

	/** @param array<string,mixed> $cargo @return array<int,array<string,mixed>> */
	private function registry_places_from_cargo( array $cargo ): array {
		$places = is_array( $cargo['places'] ?? null ) ? $cargo['places'] : array();
		$normalized = array();
		foreach ( $places as $place ) {
			if ( ! is_array( $place ) ) {
				continue;
			}
			$normalized[] = array(
				'weight_g' => max( 0, (int) ( $place['weight_g'] ?? 0 ) ),
				'length_cm' => max( 0.0, (float) ( $place['length_cm'] ?? $place['length'] ?? 0 ) ),
				'width_cm' => max( 0.0, (float) ( $place['width_cm'] ?? $place['width'] ?? 0 ) ),
				'height_cm' => max( 0.0, (float) ( $place['height_cm'] ?? $place['height'] ?? 0 ) ),
			);
		}

		return $normalized;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function registry_point_payload( PickupPoint $point, string $carrier, string $family, string $fingerprint, int $location_id, string $country_code ): array {
		$raw = is_array( $point->raw_reference ) ? $point->raw_reference : array();
		$type = $this->registry_presentation_value( $raw, 'presentation_type', $point->type );
		if ( ! in_array( $type, array( 'pvz', 'postamat', 'terminal', 'warehouse', 'unknown' ), true ) ) {
			$type = 'unknown';
		}
		$title = $this->registry_presentation_value( $raw, 'presentation_title', 'Пункт выдачи' );
		$point_name = $this->registry_presentation_value( $raw, 'point_name', '' );
		$marker_type = $this->registry_presentation_value( $raw, 'marker_type', 'pickup' );
		if ( ! in_array( $marker_type, array( 'pickup', 'postamat', 'terminal' ), true ) ) {
			$marker_type = 'pickup';
		}
		$comment = $this->registry_presentation_value( $raw, 'presentation_comment', $point->comment );
		$display_code = $this->registry_presentation_value( $raw, 'display_code', '' );
		$requires_rate_refresh = $this->registry_boolean_value( $raw, 'requires_rate_refresh' );
		$snapshot = array(
			'carrier_key' => $carrier,
			'service_key' => $carrier,
			'pickup_family' => $family,
			'point_code' => $point->code,
			'point_id' => $point->code,
			'point_type' => $type,
			'point_type_label' => $title,
			'point_title' => $title,
			'card_title' => $title,
			'point_name' => $point_name,
			'point_address' => $point->address,
			'address' => $point->address,
			'city_name' => $point->city,
			'region_name' => $point->region,
			'lat' => $point->latitude,
			'lng' => $point->longitude,
			'work_time' => $point->work_time,
			'description' => $point->comment,
			'presentation_comment' => $comment,
			'marker_type' => $marker_type,
			'display_code' => $display_code,
			'display_title' => trim( $title . ( '' !== $display_code ? ' ' . $display_code : '' ) ),
			'location_id' => $location_id,
			'country_code' => strtoupper( trim( $country_code ) ),
			'destination_fingerprint' => $fingerprint,
			'provider_destination_fingerprint' => $fingerprint,
			'requires_rate_refresh' => $requires_rate_refresh,
		);

		return array_merge( $snapshot, array( 'id' => $point->code, 'carrier' => $carrier, 'title' => $point_name, 'requires_rate_refresh' => $requires_rate_refresh, 'snapshot' => $snapshot ) );
	}

	/** @param array<string,mixed> $raw */
	private function registry_presentation_value( array $raw, string $key, string $default ): string {
		$value = $raw[ $key ] ?? null;
		return is_scalar( $value ) && '' !== trim( (string) $value ) ? trim( (string) $value ) : $default;
	}

	/** @param array<string,mixed> $raw */
	private function registry_boolean_value( array $raw, string $key ): bool {
		$value = $raw[ $key ] ?? false;
		return true === $value || '1' === $value || 1 === $value || 'true' === $value;
	}

	/** @param array<string,mixed> $snapshot */
	private function registry_provider_fingerprint( array $snapshot ): string {
		$fingerprint = (string) ( $snapshot['provider_destination_fingerprint'] ?? '' );
		return '' !== $fingerprint ? $fingerprint : (string) ( $snapshot['destination_fingerprint'] ?? '' );
	}

	/** @param array<string,mixed> $location */
	private function current_location_id_for_pickup( object $order, array $location ): int {
		$location_id = $this->positive_location_id( $location );
		if ( $location_id > 0 ) {
			return $location_id;
		}
		$resolved = $this->service->resolved_location_payload( $order, array() === $location ? null : $location );

		return is_array( $resolved ) ? $this->positive_location_id( $resolved ) : 0;
	}

	/** @param array<string,mixed> $location */
	private function current_country_code_for_pickup( object $order, array $location ): string {
		$location_id = $this->positive_location_id( $location );
		$country = $location_id > 0 ? $this->country_code_from_location_payload( $location ) : '';
		if ( '' !== $country ) {
			return $country;
		}
		$resolved = $this->service->resolved_location_payload( $order, array() === $location ? null : $location );
		if ( is_array( $resolved ) && $this->positive_location_id( $resolved ) > 0 ) {
			return $this->country_code_from_location_payload( $resolved );
		}

		return '';
	}

	/** @param array<string,mixed> $location */
	private function country_code_from_location_payload( array $location ): string {
		$country = strtoupper( trim( (string) ( $location['country_code'] ?? $location['country'] ?? '' ) ) );

		return preg_match( '/^[A-Z]{2}$/', $country ) ? $country : '';
	}

	private function looks_like_sha256( string $value ): bool {
		return 64 === strlen( $value ) && ctype_xdigit( $value );
	}

	/**
	 * @param array<string,mixed> $location
	 * @return array<int,array<string,mixed>>
	 */
	private function yandex_pickup_points( array $location, string $query, string $mode ): array {
		if ( ! $this->yandex_points instanceof YandexDeliveryPickupPointV2Repository || ! $this->yandex_location_mapping instanceof YandexLocationMappingV2Repository ) {
			return array();
		}
		$location_id = (int) ( $location['id'] ?? $location['location_id'] ?? 0 );
		if ( $location_id <= 0 ) {
			return array();
		}
		$geo_ids = array_values( array_unique( array_filter( array_map( 'intval', $this->yandex_location_mapping->geo_ids_for_location( $location_id ) ), static fn( int $geo_id ): bool => $geo_id > 0 ) ) );
		if ( array() === $geo_ids ) {
			return array();
		}

		$points = array_map( fn( array $row ): array => $this->yandex_formatter->format( $row ), $this->yandex_points->destination_pickup_points_by_geo_ids( $geo_ids ) );
		if ( 'search' !== $mode || '' === trim( $query ) ) {
			return $points;
		}

		$query = $this->normalize_search_text( $query );
		return array_values(
			array_filter(
				$points,
				function ( array $point ) use ( $query ): bool {
					$fields = array( 'address', 'point_address', 'full_address', 'point_title', 'card_title', 'display_title', 'title', 'point_name', 'name', 'platform_station_id' );
					foreach ( $fields as $field ) {
						if ( str_contains( $this->normalize_search_text( (string) ( $point[ $field ] ?? '' ) ), $query ) ) {
							return true;
						}
					}

					return false;
				}
			)
		);
	}
	private function pickup_rows_for_location( array $location, int $limit, string $postcode ): array {
		foreach ( array( 'ids', 'city_region', 'city' ) as $match ) {
			$rows = $this->pickup_points->find_rows_by_location_context(
				$location,
				array(
					'match' => $match,
					'limit' => $limit,
				)
			);
			if ( array() !== $rows ) {
				return $rows;
			}
		}

		return '' !== $postcode ? $this->pickup_points->find_rows_by_postcode( $postcode, array( 'limit' => $limit ) ) : array();
	}

	private function map_provider(): string {
		$provider = $this->settings->get_string( 'pickup_map_provider', 'leaflet' );
		return 'yandex' === $provider ? 'yandex' : 'leaflet';
	}

	private function yandex_api_key(): string {
		return trim( $this->settings->get_string( 'pickup_map_yandex_api_key', '' ) );
	}
}
