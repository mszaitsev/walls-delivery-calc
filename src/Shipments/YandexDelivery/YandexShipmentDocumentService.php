<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\YandexDelivery;

use WallsShop\WDC\Carriers\YandexDelivery\Api\YandexDeliveryApiException;
use WallsShop\WDC\Carriers\YandexDelivery\Shipment\YandexDeliveryShipmentClient;

defined( 'ABSPATH' ) || exit;

final class YandexShipmentDocumentService {
	public function __construct(
		private YandexShipmentRepository $repository,
		private YandexDeliveryShipmentClient $client,
		private YandexShipmentLabelPolicy $label_policy
	) {
	}

	/**
	 * @return array{success:bool,message:string,body?:string,content_type?:string,filename?:string,http_code?:int}
	 */
	public function label_pdf_for_order( object $order ): array {
		$shipment = $this->repository->find( $order );
		if ( array() === $shipment ) {
			return $this->failure( 'Отправление Яндекс не найдено.' );
		}
		$request_id = $this->label_policy->request_id( $shipment );
		if ( '' === $request_id ) {
			return $this->failure( 'Для отправления не найден Request ID Яндекс.' );
		}
		if ( ! $this->label_policy->can_download( $shipment ) ) {
			return $this->failure( 'Для текущего статуса отправления ярлык Яндекс недоступен.' );
		}

		try {
			$pdf = $this->client->generate_labels( array( $request_id ), 'one', 'ru' );
		} catch ( YandexDeliveryApiException $exception ) {
			return $this->failure( 'Не удалось получить ярлык Яндекс.Доставки. ' . $exception->getMessage() );
		}

		if ( $pdf->http_code < 200 || $pdf->http_code >= 300 ) {
			return $this->failure( 'Не удалось получить ярлык Яндекс.Доставки.' );
		}
		if ( '' === $pdf->body ) {
			return $this->failure( 'Яндекс.Доставка вернула пустой файл ярлыка.' );
		}
		$content_type = strtolower( trim( $pdf->content_type ) );
		if ( '' !== $content_type && ! str_contains( $content_type, 'application/pdf' ) ) {
			return $this->failure( 'Яндекс.Доставка вернула ответ, который не является PDF-файлом.' );
		}
		if ( ! str_starts_with( ltrim( $pdf->body ), '%PDF-' ) ) {
			return $this->failure( 'Яндекс.Доставка вернула ответ, который не является PDF-файлом.' );
		}

		return array(
			'success' => true,
			'message' => '',
			'body' => $pdf->body,
			'content_type' => '' !== $pdf->content_type ? $pdf->content_type : 'application/pdf',
			'filename' => $this->filename( $order ),
			'http_code' => $pdf->http_code,
		);
	}

	private function filename( object $order ): string {
		$number = method_exists( $order, 'get_order_number' ) ? (string) $order->get_order_number() : (string) ( method_exists( $order, 'get_id' ) ? (int) $order->get_id() : '' );
		$filename = function_exists( 'sanitize_file_name' ) ? sanitize_file_name( 'yandex-label-' . $number . '.pdf' ) : preg_replace( '/[^A-Za-z0-9_.-]+/', '-', 'yandex-label-' . $number . '.pdf' );

		return '' !== (string) $filename ? (string) $filename : 'yandex-label.pdf';
	}

	/** @return array{success:bool,message:string} */
	private function failure( string $message ): array {
		return array( 'success' => false, 'message' => $message );
	}
}
