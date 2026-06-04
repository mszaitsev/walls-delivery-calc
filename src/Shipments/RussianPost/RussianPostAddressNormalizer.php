<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\RussianPost;

use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiClient;

defined( 'ABSPATH' ) || exit;

final class RussianPostAddressNormalizer {
	private const GOOD_QUALITY = array( 'GOOD', 'POSTAL_BOX', 'ON_DEMAND', 'UNDEF_05' );
	private const GOOD_VALIDATION = array( 'VALIDATED', 'OVERRIDDEN', 'CONFIRMED_MANUALLY' );

	public function __construct( private RussianPostOtpravkaApiClient $client ) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function normalize( int $order_id, string $original_address ): array {
		$original_address = trim( $original_address );
		if ( '' === $original_address ) {
			return array(
				'success' => false,
				'status' => 'empty',
				'message' => 'Original address is empty.',
				'original_hash' => $this->original_hash( $original_address ),
			);
		}

		$response = $this->client->clean_address(
			array(
				array(
					'id' => 'order-' . $order_id,
					'original-address' => $original_address,
				),
			)
		);
		$rows = is_array( $response['addresses'] ?? null ) ? array_values( $response['addresses'] ) : array();
		$row = is_array( $rows[0] ?? null ) ? $rows[0] : array();
		$result = $this->normalize_row( $row, $original_address );
		$result['http_code'] = (int) ( $response['http_code'] ?? 0 );
		$result['duration_ms'] = (int) ( $response['duration_ms'] ?? 0 );
		if ( empty( $response['success'] ) && '' === (string) ( $result['message'] ?? '' ) ) {
			$result['message'] = (string) ( $response['error_message'] ?? 'Russian Post address normalization failed.' );
		}

		return $result;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public function normalize_row( array $row, string $original_address = '' ): array {
		$quality = (string) ( $row['quality-code'] ?? $row['quality_code'] ?? '' );
		$validation = (string) ( $row['validation-code'] ?? $row['validation_code'] ?? '' );
		$fields = $this->payload_fields( $row );
		$success = in_array( $quality, self::GOOD_QUALITY, true ) && in_array( $validation, self::GOOD_VALIDATION, true );

		return array(
			'success' => $success,
			'status' => $success ? 'normalized' : 'failed',
			'quality-code' => $quality,
			'validation-code' => $validation,
			'display' => $this->display( $row ),
			'fields' => $fields,
			'raw' => $this->safe_row( $row ),
			'original_hash' => $this->original_hash( $original_address ),
			'normalized_at' => function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ),
			'expires_at' => gmdate( 'Y-m-d H:i:s', time() + ( defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400 ) ),
			'message' => $success ? '' : 'Address was not confirmed by Russian Post.',
		);
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public function payload_fields( array $row ): array {
		$map = array(
			'index-to' => 'index',
			'region-to' => 'region',
			'area-to' => 'area',
			'place-to' => 'place',
			'location-to' => 'location',
			'street-to' => 'street',
			'house-to' => 'house',
			'slash-to' => 'slash',
			'letter-to' => 'letter',
			'building-to' => 'building',
			'corpus-to' => 'corpus',
			'room-to' => 'room',
			'num-address-type-to' => 'num-address-type',
			'address-type-to' => 'address-type',
		);
		$result = array();
		foreach ( $map as $payload_key => $row_key ) {
			$value = trim( (string) ( $row[ $row_key ] ?? '' ) );
			if ( '' !== $value ) {
				$result[ $payload_key ] = $value;
			}
		}
		$result['address-type-to'] = (string) ( $result['address-type-to'] ?? 'DEFAULT' );

		return $result;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	public function display( array $row ): string {
		$parts = array();
		foreach ( array( 'index', 'region', 'area', 'place', 'location', 'street', 'house', 'slash', 'letter', 'building', 'corpus', 'room', 'num-address-type' ) as $key ) {
			$value = trim( (string) ( $row[ $key ] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}
			$parts[] = match ( $key ) {
				'location' => 'мкр ' . $value,
				'house' => 'д. ' . $value,
				'slash' => '/ ' . $value,
				'letter' => 'лит ' . $value,
				'building' => 'стр. ' . $value,
				'corpus' => 'корп. ' . $value,
				default => $value,
			};
		}

		return implode( ', ', $parts );
	}

	public function original_hash( string $original_address ): string {
		return hash( 'sha256', trim( $original_address ) );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function safe_row( array $row ): array {
		unset( $row['Authorization'], $row['X-User-Authorization'], $row['password'], $row['access_token'] );

		return $row;
	}
}
