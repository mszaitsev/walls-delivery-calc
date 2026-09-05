<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Manual;

use RuntimeException;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\Domain\Pickup\PickupPoint;

defined( 'ABSPATH' ) || exit;

final class ManualPickupPointRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	/** @return array<int,array<string,mixed>> */
	public function list_by_service( int $service_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE service_id = %d ORDER BY sort_order ASC, id ASC", $service_id ),
			ARRAY_A
		);

		return array_map( array( $this, 'normalize_row' ), is_array( $rows ) ? $rows : array() );
	}

	/** @return array<int,array<string,mixed>> */
	public function active_points_for_destination( int $service_id, string $country_code, string $region_name, string $location_name, int $limit = 50 ): array {
		$country_code = $this->normalize_country_code( $country_code );
		$region_name = $this->normalize_text( $region_name );
		$location_name = $this->normalize_text( $location_name );
		if ( '' === $country_code || '' === $region_name || '' === $location_name ) {
			return array();
		}
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->table()} WHERE service_id = %d AND active = 1 AND country_code = %s AND region_name = %s AND location_name = %s ORDER BY sort_order ASC, id ASC LIMIT %d",
				$service_id,
				$country_code,
				$region_name,
				$location_name,
				max( 1, $limit )
			),
			ARRAY_A
		);

		return array_map( array( $this, 'normalize_row' ), is_array( $rows ) ? $rows : array() );
	}

	public function has_active_for_destination( int $service_id, string $country_code, string $region_name, string $location_name ): bool {
		return array() !== $this->active_points_for_destination( $service_id, $country_code, $region_name, $location_name, 1 );
	}

	public function find_active_by_code( int $service_id, string $code ): ?array {
		$code = $this->normalize_code( $code );
		if ( '' === $code ) {
			return null;
		}
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE service_id = %d AND code = %s AND active = 1 LIMIT 1", $service_id, $code ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->normalize_row( $row ) : null;
	}

	/** @param array<int,array<string,mixed>> $points */
	public function replace_points( int $service_id, array $points ): void {
		$normalized = $this->normalize_points( $service_id, $points );
		$this->validate_points( $normalized );
		$existing = $this->list_by_service( $service_id );
		$existing_codes = array_fill_keys( array_map( static fn( array $row ): string => (string) $row['code'], $existing ), true );
		$seen = array();
		$table = $this->table();
		$now = current_time( 'mysql' );

		$this->wpdb->query( 'START TRANSACTION' );
		try {
			foreach ( $normalized as $index => $point ) {
				$code = $point['code'];
				$seen[ $code ] = true;
				$data = array(
					'service_id' => $service_id,
					'code' => $code,
					'title' => $point['title'],
					'country_code' => $point['country_code'],
					'region_name' => $point['region_name'],
					'location_name' => $point['location_name'],
					'address' => $point['address'],
					'postcode' => $point['postcode'],
					'latitude' => $point['latitude'],
					'longitude' => $point['longitude'],
					'work_time' => $point['work_time'],
					'comment' => $point['comment'],
					'active' => $point['active'] ? 1 : 0,
					'sort_order' => $index + 1,
					'updated_at' => $now,
				);
				if ( isset( $existing_codes[ $code ] ) ) {
					$result = $this->wpdb->update( $table, $data, array( 'service_id' => $service_id, 'code' => $code ), array(), array( '%d', '%s' ) );
				} else {
					$data['created_at'] = $now;
					$result = $this->wpdb->insert( $table, $data, array() );
				}
				if ( false === $result ) {
					throw new RuntimeException( 'Failed to save manual pickup point.' );
				}
			}
			foreach ( $existing_codes as $code => $_ ) {
				if ( ! isset( $seen[ $code ] ) ) {
					$result = $this->wpdb->update( $table, array( 'active' => 0, 'updated_at' => $now ), array( 'service_id' => $service_id, 'code' => $code ), array( '%d', '%s' ), array( '%d', '%s' ) );
					if ( false === $result ) {
						throw new RuntimeException( 'Failed to deactivate stale manual pickup point.' );
					}
				}
			}
			$this->wpdb->query( 'COMMIT' );
		} catch ( \Throwable $exception ) {
			$this->wpdb->query( 'ROLLBACK' );
			throw $exception;
		}
	}

	public function to_pickup_point( array $row, DeliveryService $service ): ?PickupPoint {
		$row = $this->normalize_row( $row );
		if ( '' === $row['code'] || '' === $row['address'] || '' === $row['country_code'] || '' === $row['region_name'] || '' === $row['location_name'] || ! $row['active'] ) {
			return null;
		}
		$title = '' !== $row['title'] ? $row['title'] : 'Пункт выдачи';

		return new PickupPoint(
			ManualDeliverySettings::CARRIER_KEY,
			$row['code'],
			$row['address'],
			$row['location_name'],
			$row['region_name'],
			$row['postcode'],
			$row['latitude'],
			$row['longitude'],
			'pvz',
			$row['work_time'],
			$row['comment'],
			null,
			true,
			array(
				'service_id' => (int) $service->id,
				'service_key' => $service->service_key,
				'point_title' => $title,
				'point_name' => $title,
				'country_code' => $row['country_code'],
				'presentation_type' => 'pvz',
				'presentation_title' => 'Пункт выдачи',
				'marker_type' => 'pickup',
				'display_code' => '',
				'reload_on_viewport_change' => false,
				'requires_rate_refresh' => false,
			)
		);
	}

	/** @param array<int,array<string,mixed>> $points @return array<int,array<string,mixed>> */
	private function normalize_points( int $service_id, array $points ): array {
		$result = array();
		foreach ( $points as $index => $point ) {
			if ( ! is_array( $point ) || ! empty( $point['delete'] ) ) {
				continue;
			}
			$row = $this->normalize_row( $point );
			$row['service_id'] = $service_id;
			$row['sort_order'] = $index + 1;
			if ( '' === $row['code'] ) {
				$row['code'] = $this->generate_code();
			}
			$result[] = $row;
		}

		return $result;
	}

	/** @param array<int,array<string,mixed>> $points */
	private function validate_points( array $points ): void {
		$seen = array();
		foreach ( $points as $point ) {
			if ( '' === $point['code'] || isset( $seen[ $point['code'] ] ) ) {
				throw new \InvalidArgumentException( 'manual_pickup_code_invalid' );
			}
			$seen[ $point['code'] ] = true;
			if ( '' === $point['country_code'] || '' === $point['region_name'] || '' === $point['location_name'] ) {
				throw new \InvalidArgumentException( 'manual_pickup_location_required' );
			}
			if ( '' === $point['address'] ) {
				throw new \InvalidArgumentException( 'manual_pickup_address_required' );
			}
			if ( ( null === $point['latitude'] ) !== ( null === $point['longitude'] ) ) {
				throw new \InvalidArgumentException( 'manual_pickup_coordinates_invalid' );
			}
			if ( null !== $point['latitude'] && ( $point['latitude'] < -90 || $point['latitude'] > 90 || $point['longitude'] < -180 || $point['longitude'] > 180 ) ) {
				throw new \InvalidArgumentException( 'manual_pickup_coordinates_invalid' );
			}
		}
	}

	/** @return array<string,mixed> */
	private function normalize_row( array $row ): array {
		return array(
			'id' => (int) ( $row['id'] ?? 0 ),
			'service_id' => (int) ( $row['service_id'] ?? 0 ),
			'code' => $this->normalize_code( (string) ( $row['code'] ?? '' ) ),
			'title' => $this->normalize_text( (string) ( $row['title'] ?? '' ) ),
			'country_code' => $this->normalize_country_code( (string) ( $row['country_code'] ?? '' ) ),
			'region_name' => $this->normalize_text( (string) ( $row['region_name'] ?? '' ) ),
			'location_name' => $this->normalize_text( (string) ( $row['location_name'] ?? '' ) ),
			'address' => $this->normalize_text( (string) ( $row['address'] ?? '' ) ),
			'postcode' => $this->normalize_text( (string) ( $row['postcode'] ?? '' ) ),
			'latitude' => $this->optional_float( $row['latitude'] ?? null ),
			'longitude' => $this->optional_float( $row['longitude'] ?? null ),
			'work_time' => $this->normalize_text( (string) ( $row['work_time'] ?? '' ) ),
			'comment' => $this->normalize_text( (string) ( $row['comment'] ?? '' ) ),
			'active' => ! array_key_exists( 'active', $row ) || ! in_array( (string) $row['active'], array( '', '0', 'false' ), true ),
			'sort_order' => (int) ( $row['sort_order'] ?? 0 ),
		);
	}

	private function normalize_code( string $code ): string {
		return substr( preg_replace( '/[^a-z0-9_\-]+/', '', strtolower( trim( $code ) ) ) ?? '', 0, 120 );
	}

	private function normalize_country_code( string $country_code ): string {
		$country_code = preg_replace( '/[^A-Z]/', '', strtoupper( trim( $country_code ) ) ) ?? '';

		return preg_match( '/^[A-Z]{2}$/', $country_code ) ? $country_code : '';
	}

	private function normalize_text( string $value ): string {
		return sanitize_text_field( $value );
	}

	private function optional_float( mixed $value ): ?float {
		if ( null === $value || '' === trim( (string) $value ) ) {
			return null;
		}

		return is_numeric( $value ) ? (float) $value : INF;
	}

	private function generate_code(): string {
		return 'manual-pvz-' . strtolower( bin2hex( random_bytes( 6 ) ) );
	}

	private function table(): string {
		return $this->wpdb->prefix . 'wdc_manual_delivery_pickup_points';
	}
}
