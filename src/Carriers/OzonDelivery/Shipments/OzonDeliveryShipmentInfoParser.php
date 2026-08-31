<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Shipments;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryShipmentInfoParseException extends \RuntimeException {
	/** @param array<string,mixed> $diagnostics */
	public function __construct(
		public readonly string $safe_code,
		public readonly array $diagnostics
	) {
		parent::__construct( (string) ( $diagnostics['safe_error_message'] ?? 'Ozon Delivery вернул неполный статус отправлений.' ) );
	}
}

final class OzonDeliveryShipmentInfoParser {
	/**
	 * @param array<string,mixed> $response
	 * @param array<int,array<string,mixed>> $persisted_postings
	 * @return array{statuses:array<int,string>,normalized:array<int,array<string,string>>,outbound_statuses:array<string,string>}
	 */
	public function parse( array $response, array $persisted_postings ): array {
		$expected = $this->expected_numbers( $persisted_postings );
		if ( array() === $expected ) {
			throw $this->exception( 'ozon_posting_info_incomplete', count( $expected ), 0, 0, 0, 0 );
		}
		if ( ! array_key_exists( 'postings', $response ) || ! is_array( $response['postings'] ) ) {
			throw $this->exception( 'ozon_posting_info_incomplete', count( $expected ), 0, 0, 0, count( $expected ) );
		}

		$rows = array();
		$duplicates = array();
		$unexpected = array();
		foreach ( $response['postings'] as $row ) {
			if ( ! is_array( $row ) ) {
				throw $this->exception( 'ozon_posting_info_incomplete', count( $expected ), count( $rows ), count( $duplicates ), count( $unexpected ), count( $expected ) );
			}
			$number = $this->scalar_string( $row['posting_number'] ?? null );
			$status = $this->scalar_string( $row['status'] ?? null );
			if ( '' === $number || '' === $status ) {
				throw $this->exception( 'ozon_posting_info_incomplete', count( $expected ), count( $rows ), count( $duplicates ), count( $unexpected ), count( $expected ) );
			}
			if ( ! isset( $expected[ $number ] ) ) {
				$unexpected[ $number ] = true;
				continue;
			}
			if ( isset( $rows[ $number ] ) ) {
				$duplicates[ $number ] = true;
				continue;
			}
			$changed_at = $this->scalar_string( $row['status_changed_at'] ?? '' );
			$rows[ $number ] = array(
				'posting_number' => $number,
				'status' => $status,
				'normalized_status' => OzonDeliveryShipmentStatusMapping::normalize( $status ),
				'status_changed_at' => $changed_at,
			);
		}

		$missing = array();
		foreach ( array_keys( $expected ) as $number ) {
			if ( ! isset( $rows[ $number ] ) ) {
				$missing[ $number ] = true;
			}
		}
		if ( array() !== $duplicates || array() !== $unexpected || array() !== $missing || count( $rows ) !== count( $expected ) ) {
			throw $this->exception( 'ozon_posting_info_incomplete', count( $expected ), count( $rows ), count( $duplicates ), count( $unexpected ), count( $missing ) );
		}

		$statuses = array();
		$normalized = array();
		$outbound_statuses = array();
		foreach ( array_keys( $expected ) as $number ) {
			$statuses[] = $rows[ $number ]['status'];
			$normalized[] = $rows[ $number ];
			$outbound_statuses[ $number ] = $rows[ $number ]['status'];
		}

		return array( 'statuses' => $statuses, 'normalized' => $normalized, 'outbound_statuses' => $outbound_statuses );
	}

	/** @param array<int,array<string,mixed>> $postings @return array<string,true> */
	private function expected_numbers( array $postings ): array {
		$by_place = array();
		foreach ( $postings as $posting ) {
			if ( ! is_array( $posting ) ) {
				continue;
			}
			$number = trim( (string) ( $posting['posting_number'] ?? '' ) );
			if ( '' === $number ) {
				continue;
			}
			$place = max( 1, (int) ( $posting['place_number'] ?? count( $by_place ) + 1 ) );
			$by_place[ $place ] = $number;
		}
		ksort( $by_place );

		return array_fill_keys( array_values( array_unique( $by_place ) ), true );
	}

	private function scalar_string( mixed $value ): string {
		if ( is_scalar( $value ) ) {
			return trim( (string) $value );
		}
		return '';
	}

	private function exception( string $code, int $expected, int $returned, int $duplicates, int $unexpected, int $missing ): OzonDeliveryShipmentInfoParseException {
		return new OzonDeliveryShipmentInfoParseException(
			$code,
			array(
				'safe_error_code' => $code,
				'safe_error_message' => 'Ozon Delivery вернул неполный статус отправлений.',
				'expected_count' => $expected,
				'returned_count' => $returned,
				'duplicate_count' => $duplicates,
				'unexpected_count' => $unexpected,
				'missing_count' => $missing,
			)
		);
	}
}
