<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Dpd;

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

defined( 'ABSPATH' ) || exit;

final class DpdShipmentDocumentService {
	public const READY_EVENT_CODE = '1401';
	public const PICKUP_DATE_ERROR = 'Наклейка DPD недоступна: у заказа дата забора ранее текущей даты.';

	public function __construct(
		private OrderShipmentRepository $repository,
		private DpdApiClient $client
	) {
	}

	/** @param array<string,mixed> $shipment */
	public static function can_download_documents( array $shipment ): bool {
		return array() !== $shipment
			&& '' !== trim( (string) ( $shipment['dpd_order_number'] ?? '' ) );
	}

	/**
	 * @return array{success:bool,message:string,path?:string,filename?:string,cleanup?:array<int,string>}
	 */
	public function create_zip_for_order( object $order ): array {
		$shipment = $this->repository->find_by_carrier( $order, DpdSettings::CARRIER_KEY );
		$validation = $this->validate_shipment( $shipment );
		if ( empty( $validation['success'] ) ) {
			return $validation;
		}

		$order_id = method_exists( $order, 'get_id' ) ? (int) $order->get_id() : 0;
		$dpd_order_number = trim( (string) $shipment['dpd_order_number'] );
		$cleanup = array();
		$zip_path = '';
		$keep_zip = false;

		try {
			$invoice = $this->pdf_from_response(
				$this->client->getInvoiceFile( $this->invoice_payload( $dpd_order_number ) ),
				'Накладная DPD недоступна.'
			);
			if ( empty( $invoice['success'] ) ) {
				return $invoice;
			}

			$label = $this->pdf_from_response(
				$this->client->createLabelFile( $this->label_payload( $dpd_order_number, $this->label_count( $shipment ) ) ),
				'Наклейка DPD недоступна.'
			);
			if ( empty( $label['success'] ) ) {
				return $label;
			}

			if ( ! class_exists( \ZipArchive::class ) ) {
				return array( 'success' => false, 'message' => 'PHP ZipArchive недоступен, ZIP-файл документов DPD не создан.' );
			}

			$zip_path = $this->temp_path( 'wdc-dpd-documents-' . $order_id . '-' . wp_generate_uuid4() . '.zip' );
			$cleanup[] = $zip_path;
			$zip = new \ZipArchive();
			if ( true !== $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) ) {
				return array( 'success' => false, 'message' => 'Не удалось создать ZIP-файл документов DPD.' );
			}

			$zip->addFromString( sanitize_file_name( 'dpd-invoice-' . $dpd_order_number . '.pdf' ), (string) $invoice['body'] );
			$zip->addFromString( sanitize_file_name( 'dpd-label-a6-' . $dpd_order_number . '.pdf' ), (string) $label['body'] );
			$zip->close();

			if ( ! is_file( $zip_path ) || filesize( $zip_path ) <= 0 ) {
				return array( 'success' => false, 'message' => 'DPD вернул пустой ZIP-файл документов.' );
			}

			$keep_zip = true;

			return array(
				'success' => true,
				'message' => '',
				'path' => $zip_path,
				'filename' => sanitize_file_name( 'dpd-documents-order-' . $order_id . '-' . $dpd_order_number . '.zip' ),
				'cleanup' => $cleanup,
			);
		} finally {
			foreach ( $cleanup as $path ) {
				if ( $keep_zip && '' !== $zip_path && $path === $zip_path && is_file( $zip_path ) ) {
					continue;
				}
				$this->delete_temp_file( $path );
			}
		}
	}

	public function delete_temp_file( string $path ): void {
		if ( '' !== $path && is_file( $path ) ) {
			@unlink( $path );
		}
	}

	/** @param array<string,mixed> $shipment */
	private function validate_shipment( array $shipment ): array {
		if ( array() === $shipment ) {
			return array( 'success' => false, 'message' => 'DPD-отправление не найдено.' );
		}
		if ( '' === trim( (string) ( $shipment['dpd_order_number'] ?? '' ) ) ) {
			return array( 'success' => false, 'message' => 'Не найден номер заказа DPD.' );
		}

		return array( 'success' => true, 'message' => '' );
	}

	/** @return array<string,mixed> */
	private function invoice_payload( string $dpd_order_number ): array {
		return array( 'orderNum' => $dpd_order_number );
	}

	/** @return array<string,mixed> */
	private function label_payload( string $dpd_order_number, int $parcels_number ): array {
		return array(
			'fileFormat' => 'PDF',
			'pageSize' => 'A6',
			'order' => array(
				'orderNum' => $dpd_order_number,
				'parcelsNumber' => max( 1, $parcels_number ),
			),
		);
	}

	/** @param array<string,mixed> $shipment */
	private function label_count( array $shipment ): int {
		$places = is_array( $shipment['dpd_sent_places'] ?? null ) ? count( $shipment['dpd_sent_places'] ) : 0;
		$parcels = is_array( $shipment['dpd_parcel_numbers'] ?? null ) ? count( $shipment['dpd_parcel_numbers'] ) : 0;

		return max( 1, $places, $parcels );
	}

	/**
	 * @param array<string,mixed> $response
	 * @return array{success:bool,message:string,body?:string}
	 */
	private function pdf_from_response( array $response, string $fallback_message ): array {
		if ( empty( $response['success'] ) ) {
			return array( 'success' => false, 'message' => $this->document_error_message( (string) ( $response['error_message'] ?? $fallback_message ), $fallback_message ) );
		}

		$body = is_array( $response['body'] ?? null ) ? $response['body'] : array();
		$error = $this->first_error_message( $body );
		if ( '' !== $error ) {
			return array( 'success' => false, 'message' => $this->document_error_message( $error, $fallback_message ) );
		}

		$file = $this->first_file_value( $body );
		$pdf = $this->normalize_pdf_bytes( $file );
		if ( '' === $pdf ) {
			return array( 'success' => false, 'message' => $fallback_message . ' DPD вернул пустой файл.' );
		}
		if ( ! str_starts_with( ltrim( $pdf ), '%PDF-' ) ) {
			return array( 'success' => false, 'message' => $fallback_message . ' DPD вернул не PDF-файл.' );
		}

		return array( 'success' => true, 'message' => '', 'body' => $pdf );
	}

	private function document_error_message( string $message, string $fallback_message ): string {
		$message = trim( preg_replace( '/\s+/', ' ', $message ) ?? $message );
		$normalized = function_exists( 'mb_strtolower' ) ? mb_strtolower( $message ) : strtolower( $message );
		if ( str_contains( $message, 'У заказа дата забора ранее текущей даты' ) || str_contains( $normalized, 'у заказа дата забора ранее текущей даты' ) ) {
			return self::PICKUP_DATE_ERROR;
		}

		return '' !== $message ? $message : $fallback_message;
	}

	/** @param array<string,mixed> $body */
	private function first_file_value( array $body ): mixed {
		foreach ( array( 'file', 'return' ) as $key ) {
			if ( array_key_exists( $key, $body ) ) {
				$value = $body[ $key ];
				if ( is_array( $value ) && array_key_exists( 'file', $value ) ) {
					return $value['file'];
				}
				return $value;
			}
		}
		foreach ( $body as $value ) {
			if ( is_array( $value ) ) {
				$found = $this->first_file_value( $value );
				if ( null !== $found ) {
					return $found;
				}
			}
		}

		return null;
	}

	private function normalize_pdf_bytes( mixed $value ): string {
		if ( is_string( $value ) ) {
			$trimmed = trim( $value );
			if ( str_starts_with( ltrim( $value ), '%PDF-' ) ) {
				return $value;
			}
			$decoded = base64_decode( $trimmed, true );
			return false !== $decoded ? $decoded : $value;
		}
		if ( is_array( $value ) ) {
			$bytes = '';
			foreach ( $value as $item ) {
				if ( is_numeric( $item ) ) {
					$bytes .= chr( max( 0, min( 255, (int) $item ) ) );
				}
			}
			return $bytes;
		}

		return '';
	}

	/** @param array<string,mixed> $body */
	private function first_error_message( array $body ): string {
		if ( isset( $body['errorMessage'] ) && '' !== trim( (string) $body['errorMessage'] ) ) {
			return trim( (string) $body['errorMessage'] );
		}
		foreach ( $body as $value ) {
			if ( is_array( $value ) ) {
				$found = $this->first_error_message( $value );
				if ( '' !== $found ) {
					return $found;
				}
			}
		}

		return '';
	}

	private function temp_path( string $filename ): string {
		$base = function_exists( 'get_temp_dir' ) ? get_temp_dir() : sys_get_temp_dir();
		$base = rtrim( str_replace( '\\', '/', $base ), '/' );

		return $base . '/' . sanitize_file_name( $filename );
	}
}
