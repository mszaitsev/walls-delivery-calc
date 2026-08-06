<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

defined( 'ABSPATH' ) || exit;

final class PekShipmentCreateResponseParser {
	/** @param array<string,mixed> $response @return array<string,string|array<int,string>> */
	public function parse( array $response ): array {
		$document_id = trim( (string) ( $response['documentId'] ?? '' ) );
		$cargos = is_array( $response['cargos'] ?? null ) ? $response['cargos'] : array();
		if ( '' === $document_id || 1 !== count( $cargos ) ) {
			throw new \RuntimeException( 'ПЭК не вернул обязательные идентификаторы заявки и груза.' );
		}
		$cargo = is_array( $cargos[0] ?? null ) ? $cargos[0] : array();
		$code = trim( (string) ( $cargo['cargoCode'] ?? '' ) );
		if ( '' === $code ) {
			throw new \RuntimeException( 'ПЭК не вернул код груза.' );
		}
		$positions = array();
		foreach ( is_array( $cargo['positions'] ?? null ) ? $cargo['positions'] : array() as $position ) {
			if ( ! is_array( $position ) ) {
				throw new \RuntimeException( 'ПЭК вернул некорректные штрихкоды мест.' );
			}
			$barcode = trim( (string) ( $position['barcode'] ?? '' ) );
			if ( '' === $barcode ) {
				throw new \RuntimeException( 'ПЭК вернул некорректные штрихкоды мест.' );
			}
			$positions[] = $barcode;
		}

		return array(
			'document_id' => $document_id,
			'cargo_code' => $code,
			'cargo_barcode' => (string) ( $cargo['barсode'] ?? $cargo['barcode'] ?? '' ),
			'position_barcodes' => $positions,
		);
	}
}
