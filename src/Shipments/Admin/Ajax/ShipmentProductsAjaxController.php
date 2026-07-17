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


final class ShipmentProductsAjaxController {

	public function __construct() {
	}

	public function handle_search_products(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( ShipmentAdminAjaxService::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		if ( ! function_exists( 'wc_get_products' ) || ! function_exists( 'wc_get_product' ) ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$query = sanitize_text_field( wp_unslash( $_POST['query'] ?? '' ) );
		if ( '' === trim( $query ) ) {
			wp_send_json_success( array( 'items' => array() ) );
		}

		$products = array();
		if ( function_exists( 'wc_get_product_id_by_sku' ) ) {
			$sku_id = (int) wc_get_product_id_by_sku( $query );
			if ( $sku_id > 0 ) {
				$product = wc_get_product( $sku_id );
				if ( is_object( $product ) ) {
					$products[ $sku_id ] = $product;
				}
			}
		}

		foreach ( $this->product_ids_by_partial_sku( $query, 20 ) as $sku_id ) {
			$product = wc_get_product( $sku_id );
			if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
				$products[ (int) $product->get_id() ] = $product;
			}
			if ( count( $products ) >= 20 ) {
				break;
			}
		}

		foreach ( wc_get_products( array( 'limit' => 20, 'status' => array( 'publish', 'private' ), 'type' => array( 'simple', 'variation' ), 's' => $query ) ) as $product ) {
			if ( is_object( $product ) && method_exists( $product, 'get_id' ) ) {
				$products[ (int) $product->get_id() ] = $product;
			}
			if ( count( $products ) >= 20 ) {
				break;
			}
		}

		$items = array();
		foreach ( array_slice( $products, 0, 20, true ) as $product ) {
			$items[] = $this->shipment_product_search_row( $product );
		}

		wp_send_json_success( array( 'items' => $items ) );
	}

	public function handle_dpd_contact_history(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! check_ajax_referer( ShipmentAdminAjaxService::NONCE_ACTION, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Недостаточно прав или неверный nonce.', 'walls-delivery-calc' ) ), 403 );
		}
		$operation = sanitize_key( wp_unslash( $_POST['operation'] ?? '' ) );
		$value = sanitize_text_field( wp_unslash( $_POST['value'] ?? '' ) );
		$history = 'remove' === $operation ? $this->remove_dpd_courier_contact_history( $value ) : $this->add_dpd_courier_contact_history( $value );

		wp_send_json_success( array( 'history' => $history ) );
	}

	private function product_ids_by_partial_sku( string $query, int $limit ): array {
		global $wpdb;

		if ( '' === $query || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_col' ) ) {
			return array();
		}

		$postmeta = isset( $wpdb->postmeta ) ? (string) $wpdb->postmeta : '';
		if ( '' === $postmeta ) {
			return array();
		}

		$like = function_exists( 'esc_like' ) ? esc_like( $query ) : addcslashes( $query, '_%\\' );
		$sql = "SELECT post_id FROM {$postmeta} WHERE meta_key = '_sku' AND meta_value LIKE %s LIMIT %d";
		if ( method_exists( $wpdb, 'prepare' ) ) {
			$sql = $wpdb->prepare( $sql, '%' . $like . '%', max( 1, $limit ) );
		} else {
			$sql = str_replace( array( '%s', '%d' ), array( "'" . str_replace( "'", "''", '%' . $like . '%' ) . "'", (string) max( 1, $limit ) ), $sql );
		}

		return array_values( array_filter( array_map( 'intval', (array) $wpdb->get_col( $sql ) ) ) );
	}

	private function shipment_product_search_row( object $product ): array {
		$product_id = method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0;
		$parent_id = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
		$is_variation = method_exists( $product, 'is_type' ) && $product->is_type( 'variation' );
		$price = method_exists( $product, 'get_price' ) ? (float) $product->get_price() : 0.0;
		$weight = method_exists( $product, 'get_weight' ) ? (string) $product->get_weight() : '';
		$length = method_exists( $product, 'get_length' ) ? (string) $product->get_length() : '';
		$width = method_exists( $product, 'get_width' ) ? (string) $product->get_width() : '';
		$height = method_exists( $product, 'get_height' ) ? (string) $product->get_height() : '';

		return array(
			'product_id' => $is_variation && $parent_id > 0 ? $parent_id : $product_id,
			'variation_id' => $is_variation ? $product_id : 0,
			'name' => method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '',
			'sku' => method_exists( $product, 'get_sku' ) ? (string) $product->get_sku() : '',
			'price' => round( max( 0.0, $price ), 2 ),
			'weight_g' => max( 1, (int) round( function_exists( 'wc_get_weight' ) ? (float) wc_get_weight( $weight, 'g' ) : (float) $weight ) ),
			'length_cm' => max( 0.1, round( function_exists( 'wc_get_dimension' ) ? (float) wc_get_dimension( $length, 'cm' ) : (float) $length, 1 ) ),
			'width_cm' => max( 0.1, round( function_exists( 'wc_get_dimension' ) ? (float) wc_get_dimension( $width, 'cm' ) : (float) $width, 1 ) ),
			'height_cm' => max( 0.1, round( function_exists( 'wc_get_dimension' ) ? (float) wc_get_dimension( $height, 'cm' ) : (float) $height, 1 ) ),
		);
	}

	private function dpd_courier_contact_history(): array {
		$settings = new SettingsRepository();
		$values = $settings->get_array( DpdSettings::COURIER_CONTACT_FIO_HISTORY_KEY, array() );

		return $this->sanitize_dpd_courier_contact_history( $values );
	}

	private function add_dpd_courier_contact_history( string $value ): array {
		$settings = new SettingsRepository();
		$history = $this->sanitize_dpd_courier_contact_history( array_merge( array( $value ), $settings->get_array( DpdSettings::COURIER_CONTACT_FIO_HISTORY_KEY, array() ) ) );
		$settings->set( DpdSettings::COURIER_CONTACT_FIO_HISTORY_KEY, $history );

		return $history;
	}

	private function remove_dpd_courier_contact_history( string $value ): array {
		$settings = new SettingsRepository();
		$remove = sanitize_text_field( wp_unslash( $value ) );
		$history = array_values( array_filter( $this->dpd_courier_contact_history(), static fn( string $item ): bool => $item !== $remove ) );
		$settings->set( DpdSettings::COURIER_CONTACT_FIO_HISTORY_KEY, $history );

		return $history;
	}

	private function sanitize_dpd_courier_contact_history( array $values ): array {
		$history = array();
		foreach ( $values as $value ) {
			$value = substr( sanitize_text_field( wp_unslash( (string) $value ) ), 0, 120 );
			if ( '' !== $value && ! in_array( $value, $history, true ) ) {
				$history[] = $value;
			}
		}

		return array_slice( $history, 0, 20 );
	}

}
