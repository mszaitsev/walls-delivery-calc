<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Documents;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class ShipmentDocumentDownloadService {
	public const ACTION = 'wdc_download_shipment_document';

	public function __construct(
		private OrderShipmentRepository $repository,
		private ShipmentDocumentProviderRegistry $providers,
		private ?Logger $logger = null
	) {
	}

	public function download_url( int $order_id, string $carrier_key, string $action_key ): string {
		return add_query_arg(
			array(
				'action' => self::ACTION,
				'order_id' => $order_id,
				'carrier_key' => $this->sanitize_key( $carrier_key ),
				'action_key' => $this->sanitize_key( $action_key ),
				'_wdc_nonce' => wp_create_nonce( $this->nonce_action( $order_id, $carrier_key, $action_key ) ),
			),
			admin_url( 'admin-post.php' )
		);
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'admin_post_download' ) );
	}

	public function admin_post_download(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			$this->die( 'Недостаточно прав.', 403 );
		}

		$order_id = (int) ( $_GET['order_id'] ?? 0 );
		$carrier_key = $this->sanitize_key( wp_unslash( (string) ( $_GET['carrier_key'] ?? '' ) ) );
		$action_key = $this->sanitize_key( wp_unslash( (string) ( $_GET['action_key'] ?? '' ) ) );
		$nonce = sanitize_text_field( wp_unslash( (string) ( $_GET['_wdc_nonce'] ?? '' ) ) );
		if ( $order_id <= 0 || '' === $carrier_key || '' === $action_key || ! wp_verify_nonce( $nonce, $this->nonce_action( $order_id, $carrier_key, $action_key ) ) ) {
			$this->die( 'Неверный запрос.', 403 );
		}

		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $order_id ) : null;
		if ( ! is_object( $order ) ) {
			$this->die( 'Заказ не найден.', 404 );
		}

		try {
			$document = $this->download_for_order( $order, $carrier_key, $action_key );
		} catch ( \Throwable $exception ) {
			$this->log_failure( $order_id, $carrier_key, $action_key, $exception );
			$this->die( $exception->getMessage() ?: 'Не удалось скачать документ отправления.', 400 );
		}

		if ( function_exists( 'nocache_headers' ) ) {
			nocache_headers();
		}
		header( 'Content-Type: ' . $document->content_type );
		header( 'Content-Disposition: attachment; filename="' . $document->filename . '"' );
		header( 'Content-Length: ' . strlen( $document->body ) );
		header( 'X-Content-Type-Options: nosniff' );
		echo $document->body;
		exit;
	}

	public function download_for_order( object $order, string $carrier_key, string $action_key ): ShipmentBinaryDocument {
		$carrier_key = $this->sanitize_key( $carrier_key );
		$action_key = $this->sanitize_key( $action_key );
		$provider = $this->providers->get( $carrier_key );
		if ( ! $provider instanceof CarrierShipmentDocumentProviderInterface ) {
			throw new \RuntimeException( 'Для выбранной службы документы не настроены.' );
		}

		$shipment = $this->repository->find_by_carrier( $order, $carrier_key );
		if ( array() === $shipment ) {
			throw new \RuntimeException( 'Отправление не найдено.' );
		}

		$allowed = false;
		foreach ( $provider->actions( $order, $shipment ) as $action ) {
			if ( $action instanceof ShipmentDocumentAction && $action->visible && $action->key === $action_key ) {
				$allowed = true;
				break;
			}
		}
		if ( ! $allowed ) {
			throw new \RuntimeException( 'Для текущего отправления документ недоступен.' );
		}

		return $provider->download( $order, $shipment, $action_key );
	}

	private function nonce_action( int $order_id, string $carrier_key, string $action_key ): string {
		return self::ACTION . '_' . $order_id . '_' . $this->sanitize_key( $carrier_key ) . '_' . $this->sanitize_key( $action_key );
	}

	private function die( string $message, int $status ): void {
		wp_die( esc_html( $message ), '', array( 'response' => $status ) );
	}

	private function log_failure( int $order_id, string $carrier_key, string $action_key, \Throwable $exception ): void {
		if ( $this->logger instanceof Logger ) {
			$this->logger->warning(
				'Shipment document download failed.',
				array(
					'order_id' => $order_id,
					'carrier_key' => $carrier_key,
					'action_key' => $action_key,
					'error' => $exception->getMessage(),
				)
			);
		}
	}

	private function sanitize_key( string $value ): string {
		if ( function_exists( 'sanitize_key' ) ) {
			return sanitize_key( $value );
		}

		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', $value ) ?? '' );
	}
}
