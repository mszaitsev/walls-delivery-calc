<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Shipments\Documents\ShipmentBinaryDocument;

defined( 'ABSPATH' ) || exit;

final class PekShipmentDocumentService {
	private const MAX_PDF_BYTES = 10485760;

	public function __construct( private PekApiClient $api ) {
	}

	public function download( string $cargo_code, string $type ): ShipmentBinaryDocument {
		$base64 = $this->api->order_print( $cargo_code, $type );
		$bytes = base64_decode( $base64, true );
		if ( false === $bytes || '' === $bytes ) {
			throw new \RuntimeException( 'ПЭК вернул некорректный PDF.' );
		}
		if ( strlen( $bytes ) > self::MAX_PDF_BYTES || ! str_starts_with( $bytes, '%PDF-' ) ) {
			throw new \RuntimeException( 'ПЭК вернул неподдерживаемый документ.' );
		}

		return new ShipmentBinaryDocument( $bytes, 'application/pdf', $this->filename( $cargo_code, $type ) );
	}

	private function filename( string $cargo_code, string $type ): string {
		$code = preg_replace( '/[^A-Za-z0-9_-]+/', '-', $cargo_code ) ?: 'cargo';
		$prefix = match ( $type ) {
			'big' => 'pek-application-',
			'multiple' => 'pek-labels-',
			default => 'pek-label-',
		};

		return $prefix . $code . '.pdf';
	}
}
