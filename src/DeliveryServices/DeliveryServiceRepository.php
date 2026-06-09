<?php
declare(strict_types=1);

namespace WallsShop\WDC\DeliveryServices;

use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;

defined( 'ABSPATH' ) || exit;

final class DeliveryServiceRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function create_service( array $data ): int {
		$service_key = (string) ( $data['service_key'] ?? '' );
		if ( '' !== $service_key ) {
			$existing = $this->find_any_by_service_key( $service_key );
			if ( $existing instanceof DeliveryService && null !== $existing->id ) {
				$this->update_service(
					(int) $existing->id,
					array_merge(
						$data,
						array(
							'deleted' => 0,
						)
					)
				);

				return (int) $existing->id;
			}
		}

		$now = current_time( 'mysql' );
		$row = $this->normalize_row( $data, $now );
		$this->wpdb->insert( $this->table(), $row, $this->formats() );

		return (int) $this->wpdb->insert_id;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function update_service( int $id, array $data ): void {
		$row = $this->normalize_row( $data, current_time( 'mysql' ), false );
		if ( array() === $row ) {
			return;
		}

		$row['updated_at'] = current_time( 'mysql' );
		$this->wpdb->update( $this->table(), $row, array( 'id' => $id ), $this->formats_for_row( $row ), array( '%d' ) );
	}

	public function soft_delete_service( int $id ): void {
		$service = $this->find_by_id( $id );
		if ( $service instanceof DeliveryService && $this->is_predefined_service_key( $service->service_key ) ) {
			return;
		}

		$this->wpdb->update(
			$this->table(),
			array( 'deleted' => 1, 'enabled' => 0, 'updated_at' => current_time( 'mysql' ) ),
			array( 'id' => $id ),
			array( '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * @return array<int,DeliveryService>
	 */
	public function list_active(): array {
		$rows = $this->wpdb->get_results( "SELECT * FROM {$this->table()} WHERE deleted = 0 ORDER BY sort_order ASC, id ASC", ARRAY_A );

		return $this->rows_to_services( is_array( $rows ) ? $rows : array() );
	}

	public function find_by_service_key( string $service_key ): ?DeliveryService {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE service_key = %s AND deleted = 0 LIMIT 1", $service_key ),
			ARRAY_A
		);

		return is_array( $row ) ? DeliveryService::from_array( $row ) : null;
	}

	public function find_by_id( int $id ): ?DeliveryService {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		return is_array( $row ) ? DeliveryService::from_array( $row ) : null;
	}

	public function is_predefined_service_key( string $service_key ): bool {
		return in_array(
			$service_key,
			array(
				RussianPostSettings::SERVICE_KEY,
				RussianPostDomesticSettings::SERVICE_KEY,
				CdekSettings::SERVICE_KEY,
			),
			true
		);
	}

	/**
	 * @param array<int,int|string> $ordered_ids
	 */
	public function reorder( array $ordered_ids ): void {
		$position = 10;
		foreach ( $ordered_ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}

			$this->wpdb->update(
				$this->table(),
				array( 'sort_order' => $position, 'updated_at' => current_time( 'mysql' ) ),
				array( 'id' => $id ),
				array( '%d', '%s' ),
				array( '%d' )
			);
			$position += 10;
		}
	}

	public function ensure_russian_post_service(): DeliveryService {
		$existing = $this->find_any_by_service_key( RussianPostSettings::SERVICE_KEY );
		if ( $existing instanceof DeliveryService ) {
			if ( null !== $existing->id ) {
				$this->update_service(
					(int) $existing->id,
					array(
						'carrier_key' => RussianPostSettings::CARRIER_KEY,
						'service_type' => DeliveryService::TYPE_API,
						'title' => '' !== trim( $existing->title ) ? $existing->title : RussianPostSettings::TITLE,
						'enabled' => 1,
						'availability_mode' => DeliveryService::AVAILABILITY_CARRIER_DIRECTORY,
						'deleted' => 0,
					)
				);
				$this->delete_duplicate_active_services( RussianPostSettings::SERVICE_KEY, (int) $existing->id );
			}

			return $existing;
		}

		$id = $this->create_service(
			array(
				'service_key' => RussianPostSettings::SERVICE_KEY,
				'carrier_key' => RussianPostSettings::CARRIER_KEY,
				'service_type' => DeliveryService::TYPE_API,
				'title' => RussianPostSettings::TITLE,
				'enabled' => 1,
				'availability_mode' => DeliveryService::AVAILABILITY_CARRIER_DIRECTORY,
				'use_default_rules_when_no_service_rules' => 1,
				'round_up_to_ruble' => 1,
				'minimum_price_rub' => 1,
				'include_packaging_weight' => 1,
				'packaging_weight_mode' => DeliveryService::PACKAGING_WEIGHT_TOTAL_WEIGHT,
				'pickup_customer_comment' => '',
				'courier_customer_comment' => '',
				'sort_order' => 10,
				'deleted' => 0,
			)
		);

		$created = $this->find_by_service_key( RussianPostSettings::SERVICE_KEY );

		return $created instanceof DeliveryService ? $created : DeliveryService::from_array( array( 'id' => $id, 'service_key' => RussianPostSettings::SERVICE_KEY ) );
	}

	/**
	 * @return array<int,DeliveryService>
	 */
	public function ensure_russian_post_domestic_service(): DeliveryService {
		return $this->ensure_builtin_service(
			RussianPostDomesticSettings::SERVICE_KEY,
			RussianPostDomesticSettings::CARRIER_KEY,
			RussianPostDomesticSettings::TITLE,
			20,
			'Доставка до почтового отделения по индексу',
			array( RussianPostDomesticSettings::PICKUP_SERVICE_TITLE, RussianPostDomesticSettings::COURIER_SERVICE_TITLE )
		);
	}

	public function ensure_cdek_service(): DeliveryService {
		$existing = $this->find_any_by_service_key( CdekSettings::SERVICE_KEY );
		if ( $existing instanceof DeliveryService ) {
			if ( null !== $existing->id ) {
				$this->update_service(
					(int) $existing->id,
					array(
						'carrier_key' => CdekSettings::CARRIER_KEY,
						'service_type' => DeliveryService::TYPE_API,
						'title' => '' === trim( $existing->title ) ? CdekSettings::TITLE : $existing->title,
						'enabled' => $existing->enabled ? 1 : 0,
						'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES,
						'deleted' => 0,
					)
				);
				$this->delete_duplicate_active_services( CdekSettings::SERVICE_KEY, (int) $existing->id );
			}

			$updated = $this->find_by_service_key( CdekSettings::SERVICE_KEY );

			return $updated instanceof DeliveryService ? $updated : $existing;
		}

		$id = $this->create_service(
			array(
				'service_key' => CdekSettings::SERVICE_KEY,
				'carrier_key' => CdekSettings::CARRIER_KEY,
				'service_type' => DeliveryService::TYPE_API,
				'title' => CdekSettings::TITLE,
				'enabled' => 0,
				'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES,
				'use_default_rules_when_no_service_rules' => 1,
				'round_up_to_ruble' => 1,
				'minimum_price_rub' => 1,
				'include_packaging_weight' => 1,
				'packaging_weight_mode' => DeliveryService::PACKAGING_WEIGHT_TOTAL_WEIGHT,
				'pickup_customer_comment' => '',
				'courier_customer_comment' => '',
				'sort_order' => 30,
				'deleted' => 0,
			)
		);

		$created = $this->find_by_service_key( CdekSettings::SERVICE_KEY );

		return $created instanceof DeliveryService ? $created : DeliveryService::from_array( array( 'id' => $id, 'service_key' => CdekSettings::SERVICE_KEY, 'carrier_key' => CdekSettings::CARRIER_KEY, 'title' => CdekSettings::TITLE, 'enabled' => 0 ) );
	}

	/**
	 * @param array<int,string> $replaceable_titles
	 */
	private function ensure_builtin_service( string $service_key, string $carrier_key, string $title, int $sort_order, string $pickup_comment = '', array $replaceable_titles = array() ): DeliveryService {
		$existing = $this->find_any_by_service_key( $service_key );
		if ( $existing instanceof DeliveryService ) {
			if ( null !== $existing->id ) {
				$existing_title = trim( $existing->title );
				$should_replace_title = '' === $existing_title || in_array( $existing_title, $replaceable_titles, true );
				$this->update_service(
					(int) $existing->id,
					array(
						'carrier_key' => $carrier_key,
						'service_type' => DeliveryService::TYPE_API,
						'title' => $should_replace_title ? $title : $existing->title,
						'enabled' => $existing->enabled ? 1 : 0,
						'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES,
						'deleted' => 0,
					)
				);
				$this->delete_duplicate_active_services( $service_key, (int) $existing->id );
			}

			$updated = $this->find_by_service_key( $service_key );

			return $updated instanceof DeliveryService ? $updated : $existing;
		}

		$id = $this->create_service(
			array(
				'service_key' => $service_key,
				'carrier_key' => $carrier_key,
				'service_type' => DeliveryService::TYPE_API,
				'title' => $title,
				'enabled' => 1,
				'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES,
				'use_default_rules_when_no_service_rules' => 1,
				'round_up_to_ruble' => 1,
				'minimum_price_rub' => 1,
				'include_packaging_weight' => 1,
				'packaging_weight_mode' => DeliveryService::PACKAGING_WEIGHT_TOTAL_WEIGHT,
				'pickup_customer_comment' => $pickup_comment,
				'courier_customer_comment' => '',
				'sort_order' => $sort_order,
				'deleted' => 0,
			)
		);

		$created = $this->find_by_service_key( $service_key );

		return $created instanceof DeliveryService ? $created : DeliveryService::from_array( array( 'id' => $id, 'service_key' => $service_key, 'carrier_key' => $carrier_key, 'title' => $title ) );
	}

	private function find_any_by_service_key( string $service_key ): ?DeliveryService {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE service_key = %s ORDER BY deleted ASC, id ASC LIMIT 1", $service_key ),
			ARRAY_A
		);

		return is_array( $row ) ? DeliveryService::from_array( $row ) : null;
	}

	private function delete_duplicate_active_services( string $service_key, int $keep_id ): void {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE service_key = %s AND deleted = 0 ORDER BY id ASC", $service_key ),
			ARRAY_A
		);
		if ( ! is_array( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			$id = (int) ( $row['id'] ?? 0 );
			if ( $id > 0 && $id !== $keep_id ) {
				$this->wpdb->update(
					$this->table(),
					array( 'deleted' => 1, 'enabled' => 0, 'updated_at' => current_time( 'mysql' ) ),
					array( 'id' => $id ),
					array( '%d', '%d', '%s' ),
					array( '%d' )
				);
			}
		}
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	private function normalize_row( array $data, string $now, bool $include_created = true ): array {
		$allowed = array(
			'service_key' => '%s',
			'carrier_key' => '%s',
			'service_type' => '%s',
			'title' => '%s',
			'enabled' => '%d',
			'availability_mode' => '%s',
			'use_default_rules_when_no_service_rules' => '%d',
			'round_up_to_ruble' => '%d',
			'minimum_price_rub' => '%f',
			'include_packaging_weight' => '%d',
			'packaging_weight_mode' => '%s',
			'pickup_customer_comment' => '%s',
			'courier_customer_comment' => '%s',
			'sort_order' => '%d',
			'deleted' => '%d',
		);
		$row = array();
		foreach ( $allowed as $key => $format ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			$row[ $key ] = match ( $format ) {
				'%d' => (int) $data[ $key ],
				'%f' => 'minimum_price_rub' === $key ? max( 0, (float) str_replace( ',', '.', (string) $data[ $key ] ) ) : (float) str_replace( ',', '.', (string) $data[ $key ] ),
				default => (string) $data[ $key ],
			};
			if ( 'packaging_weight_mode' === $key ) {
				$row[ $key ] = DeliveryService::normalize_packaging_weight_mode( (string) $row[ $key ] );
			}
		}

		if ( $include_created ) {
			$row = array_merge(
				array(
					'service_key' => '',
					'carrier_key' => '',
					'service_type' => DeliveryService::TYPE_API,
					'title' => '',
					'enabled' => 1,
					'availability_mode' => DeliveryService::AVAILABILITY_SELECTED_COUNTRIES,
					'use_default_rules_when_no_service_rules' => 1,
					'round_up_to_ruble' => 1,
					'minimum_price_rub' => 1.0,
					'include_packaging_weight' => 1,
					'packaging_weight_mode' => DeliveryService::PACKAGING_WEIGHT_TOTAL_WEIGHT,
					'pickup_customer_comment' => '',
					'courier_customer_comment' => '',
					'sort_order' => 100,
					'deleted' => 0,
				),
				$row,
				array( 'created_at' => $now, 'updated_at' => $now )
			);
		}

		return $row;
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,DeliveryService>
	 */
	private function rows_to_services( array $rows ): array {
		return array_map( static fn ( array $row ): DeliveryService => DeliveryService::from_array( $row ), $rows );
	}

	/**
	 * @return array<int,string>
	 */
	private function formats(): array {
		return array( '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%f', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' );
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<int,string>
	 */
	private function formats_for_row( array $row ): array {
		$formats = array(
			'service_key' => '%s',
			'carrier_key' => '%s',
			'service_type' => '%s',
			'title' => '%s',
			'enabled' => '%d',
			'availability_mode' => '%s',
			'use_default_rules_when_no_service_rules' => '%d',
			'round_up_to_ruble' => '%d',
			'minimum_price_rub' => '%f',
			'include_packaging_weight' => '%d',
			'packaging_weight_mode' => '%s',
			'pickup_customer_comment' => '%s',
			'courier_customer_comment' => '%s',
			'sort_order' => '%d',
			'deleted' => '%d',
			'created_at' => '%s',
			'updated_at' => '%s',
		);

		return array_values( array_intersect_key( $formats, $row ) );
	}

	private function table(): string {
		return $this->wpdb->prefix . 'wdc_delivery_services';
	}
}
