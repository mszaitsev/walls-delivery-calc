<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

defined( 'ABSPATH' ) || exit;

final class PekShipmentCreateResponseParser {
	/** @param array<string,mixed> $response @return array<string,string|array<int,string>> */
	public function parse( array $response ): array {
		$document_id = $this->identifier( $response['documentId'] ?? null, 'ПЭК не вернул корректный ID заявки.' );
		$cargos = $response['cargos'] ?? null;
		if ( ! is_array( $cargos ) || ! array_is_list( $cargos ) || 1 !== count( $cargos ) ) {
			throw new \RuntimeException( 'ПЭК не вернул обязательные идентификаторы заявки и груза.' );
		}
		$cargo = $cargos[0] ?? null;
		if ( ! is_array( $cargo ) || array_is_list( $cargo ) ) {
			throw new \RuntimeException( 'ПЭК вернул некорректный груз в ответе заявки.' );
		}
		$code = $this->string_identifier( $cargo['cargoCode'] ?? null, 'ПЭК не вернул код груза.' );
		$positions = array();
		if ( array_key_exists( 'positions', $cargo ) ) {
			if ( ! is_array( $cargo['positions'] ) || ! array_is_list( $cargo['positions'] ) ) {
				throw new \RuntimeException( 'ПЭК вернул некорректные штрихкоды мест.' );
			}
			foreach ( $cargo['positions'] as $position ) {
				if ( ! is_array( $position ) || array_is_list( $position ) ) {
					throw new \RuntimeException( 'ПЭК вернул некорректные штрихкоды мест.' );
				}
				$positions[] = $this->string_identifier( $position['barcode'] ?? null, 'ПЭК вернул некорректные штрихкоды мест.' );
			}
		}
		$positions = array_values( array_unique( $positions ) );
		$barcode = '';
		foreach ( array( 'barсode', 'barcode' ) as $key ) {
			if ( array_key_exists( $key, $cargo ) ) {
				$barcode = $this->string_identifier( $cargo[ $key ], 'ПЭК вернул некорректный штрихкод груза.' );
				break;
			}
		}

		return array(
			'document_id' => $document_id,
			'cargo_code' => $code,
			'cargo_barcode' => $barcode,
			'position_barcodes' => $positions,
		);
	}

	private function identifier( mixed $value, string $message ): string {
		if ( is_int( $value ) ) {
			if ( $value <= 0 ) {
				throw new \RuntimeException( $message );
			}

			return (string) $value;
		}

		return $this->string_identifier( $value, $message );
	}

	private function string_identifier( mixed $value, string $message ): string {
		if ( ! is_string( $value ) ) {
			throw new \RuntimeException( $message );
		}
		$value = trim( $value );
		if ( '' === $value || strlen( $value ) > 128 ) {
			throw new \RuntimeException( $message );
		}

		return $value;
	}
}
