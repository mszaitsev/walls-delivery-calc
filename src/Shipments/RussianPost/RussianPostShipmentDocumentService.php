<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\RussianPost;

use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiClient;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Shipments\Documents\ShipmentBinaryDocument;

defined( 'ABSPATH' ) || exit;

final class RussianPostShipmentDocumentService {
	public function __construct( private RussianPostOtpravkaApiClient $client ) {
	}

	/** @param array<string,mixed> $shipment */
	public function can_download_label( array $shipment ): bool {
		return array() !== $shipment
			&& RussianPostDomesticSettings::CARRIER_KEY === (string) ( $shipment['carrier_key'] ?? RussianPostDomesticSettings::CARRIER_KEY )
			&& '' !== $this->backlog_id( $shipment )
			&& ! $this->batch_formed( $shipment );
	}

	/** @param array<string,mixed> $shipment */
	public function download_label( object $order, array $shipment ): ShipmentBinaryDocument {
		if ( ! $this->can_download_label( $shipment ) ) {
			throw new \RuntimeException( 'Для текущего отправления ярлык Почты России недоступен.' );
		}
		$result = $this->client->download_backlog_forms( $this->backlog_id( $shipment ) );
		if ( empty( $result['success'] ) ) {
			throw new \RuntimeException( (string) ( $result['error_message'] ?? 'Не удалось скачать ярлык Почты России.' ) );
		}
		$body = (string) ( $result['body'] ?? '' );
		if ( '' === $body || ! str_starts_with( ltrim( $body ), '%PDF-' ) ) {
			throw new \RuntimeException( 'Почта России не вернула PDF печатной формы.' );
		}
		$content_type = strtolower( trim( (string) ( $result['content_type'] ?? '' ) ) );
		if ( '' !== $content_type && ! str_contains( $content_type, 'application/pdf' ) && ! str_contains( $content_type, 'application/octet-stream' ) ) {
			throw new \RuntimeException( 'Почта России вернула ответ, который не является PDF-файлом.' );
		}

		return new ShipmentBinaryDocument(
			$body,
			'application/pdf',
			$this->filename( $order, $shipment )
		);
	}

	/** @param array<string,mixed> $shipment */
	private function backlog_id( array $shipment ): string {
		return trim( (string) ( $shipment['backlog_order_id'] ?? '' ) );
	}

	/** @param array<string,mixed> $shipment */
	private function batch_formed( array $shipment ): bool {
		foreach ( array( 'russian_post_batch_id', 'batch_id', 'batch_name', 'shipment_group_id' ) as $key ) {
			if ( '' !== trim( (string) ( $shipment[ $key ] ?? '' ) ) ) {
				return true;
			}
		}

		return false;
	}

	/** @param array<string,mixed> $shipment */
	private function filename( object $order, array $shipment ): string {
		$number = method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : '';
		if ( '' === trim( $number ) ) {
			$number = (string) ( method_exists( $order, 'get_id' ) ? (int) $order->get_id() : '' );
		}
		if ( '' === trim( $number ) ) {
			$number = (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? 'shipment' );
		}
		$filename = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( 'pochta-rossii-' . $number . '.pdf' ) : preg_replace( '/[^A-Za-z0-9_.-]+/', '-', 'pochta-rossii-' . $number . '.pdf' );

		return '' !== (string) $filename ? (string) $filename : 'pochta-rossii-label.pdf';
	}
}
