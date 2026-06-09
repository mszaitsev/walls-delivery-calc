<?php
declare(strict_types=1);

namespace WallsShop\WDC\Orders\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationAjax;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Orders\Application\OrderDeliveryAddressNormalizationService;
use WallsShop\WDC\Orders\Application\OrderDeliveryRecalculationService;
use WallsShop\WDC\Orders\Application\OrderDeliveryReplacementService;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointTypeSettings;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class OrderDeliveryRecalculationAdminController {
	public const AJAX_PREVIEW = 'wdc_order_delivery_recalculate_preview';
	public const AJAX_LOCATION_SEARCH = 'wdc_order_delivery_recalculate_location_search';
	public const AJAX_PICKUP_SEARCH = 'wdc_order_delivery_recalculate_pickup_search';
	public const AJAX_NORMALIZE_ADDRESS = 'wdc_order_delivery_recalculate_normalize_address';
	public const AJAX_GEOCODE_ADDRESS = 'wdc_order_delivery_recalculate_geocode_address';
	public const AJAX_SAVE = 'wdc_order_delivery_recalculate_save';
	public const NONCE_ACTION = 'wdc_order_delivery_recalculation';

	public function __construct(
		private OrderDeliveryRecalculationService $service,
		private OrderDeliveryRateRenderer $renderer,
		private ?CheckoutLocationAjax $location_search = null,
		private ?RussianPostPickupPointRepository $pickup_points = null,
		private string $plugin_url = '',
		private string $version = '1',
		private ?OrderDeliveryAddressNormalizationService $address_normalization = null,
		private ?OrderDeliveryReplacementService $replacement = null
	) {
		$this->pickup_points = $this->pickup_points ?? new RussianPostPickupPointRepository();
		$this->address_normalization = $this->address_normalization ?? new OrderDeliveryAddressNormalizationService();
		$this->replacement = $this->replacement ?? new OrderDeliveryReplacementService( new OrderShipmentRepository() );
	}

	public function register(): void {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::AJAX_PREVIEW, array( $this, 'ajax_preview' ) );
		add_action( 'wp_ajax_' . self::AJAX_LOCATION_SEARCH, array( $this, 'ajax_location_search' ) );
		add_action( 'wp_ajax_' . self::AJAX_PICKUP_SEARCH, array( $this, 'ajax_pickup_search' ) );
		add_action( 'wp_ajax_' . self::AJAX_NORMALIZE_ADDRESS, array( $this, 'ajax_normalize_address' ) );
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
				'geocodeAddressAction' => self::AJAX_GEOCODE_ADDRESS,
				'saveAction' => self::AJAX_SAVE,
				'mapProvider' => $provider,
				'yandexApiKeyPresent' => '' !== $this->yandex_api_key(),
				'yandexApiKey' => 'yandex' === $provider ? $this->yandex_api_key() : '',
				'pickupPointTypes' => ( new RussianPostPickupPointTypeSettings( new SettingsRepository() ) )->all(),
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

		$result = $this->service->preview( $order, $this->selected_location_from_request() );
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
		if ( null === $this->location_search ) {
			wp_send_json_error( array( 'message' => __( 'Поиск населенных пунктов недоступен.', 'walls-delivery-calc' ) ), 500 );
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
		$query = $this->request_string( 'query' );
		$mode = 'location' === $this->request_string( 'mode' ) ? 'location' : 'search';
		$limit = max( 1, min( 'location' === $mode ? 300 : 100, (int) ( $_POST['limit'] ?? ( 'location' === $mode ? 300 : 50 ) ) ) );
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
					'debug' => $result['debug'] ?? array(),
				)
			);
		}
		if ( empty( $result['success'] ) ) {
			wp_send_json_error(
				array(
					'message' => (string) ( $result['message'] ?? __( 'Адрес не нормализован.', 'walls-delivery-calc' ) ),
					'address' => $result['payload'] ?? array(),
					'suggestions' => $result['suggestions'] ?? array(),
					'debug' => $result['debug'] ?? array(),
				),
				400
			);
		}

		wp_send_json_success(
			array(
				'message' => (string) ( $result['message'] ?? __( 'Адрес нормализован.', 'walls-delivery-calc' ) ),
				'address' => $result['payload'] ?? array(),
				'suggestions' => $result['suggestions'] ?? array(),
				'debug' => $result['debug'] ?? array(),
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
			'region_name',
			'region_code',
			'state_value',
			'city_name',
			'city_value',
			'place_name',
			'settlement_name',
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

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function pickup_point_payload( array $row ): array {
		$point_code = (string) ( $row['point_code'] ?? '' );
		$postcode = (string) ( $row['postcode'] ?? $row['postal_code'] ?? '' );
		$address = (string) ( $row['address'] ?? '' );
		return array(
			'point_code' => $point_code,
			'point_type' => (string) ( $row['point_type'] ?? 'OPS' ),
			'point_name' => (string) ( $row['name'] ?? $postcode ),
			'point_address' => $address,
			'point_postcode' => $postcode,
			'postcode' => $postcode,
			'region_name' => (string) ( $row['region_name'] ?? '' ),
			'city_name' => (string) ( $row['city_name'] ?? '' ),
			'address' => $address,
			'lat' => null !== ( $row['latitude'] ?? null ) ? (float) $row['latitude'] : null,
			'lng' => null !== ( $row['longitude'] ?? null ) ? (float) $row['longitude'] : null,
			'point_raw' => $row,
		);
	}

	/**
	 * @param array<string,mixed> $location
	 * @return array<int,array<string,mixed>>
	 */
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
		$provider = ( new SettingsRepository() )->get_string( 'pickup_map_provider', 'leaflet' );
		return 'yandex' === $provider ? 'yandex' : 'leaflet';
	}

	private function yandex_api_key(): string {
		return trim( ( new SettingsRepository() )->get_string( 'pickup_map_yandex_api_key', '' ) );
	}
}
