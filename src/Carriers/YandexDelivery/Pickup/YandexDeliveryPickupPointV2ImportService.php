<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Pickup;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryPickupPointV2ImportService {
	private const BATCH_SIZE = 500;

	public function __construct(
		private YandexDeliveryPickupPointV2Repository $repository,
		private ?YandexDeliveryPickupPointV2ScheduleFormatter $schedule_formatter = null
	) {
		$this->schedule_formatter ??= new YandexDeliveryPickupPointV2ScheduleFormatter();
	}

	/**
	 * @return array{received:int,normalized:int,saved:int,skipped_invalid:int,batches:int,progress:array<int,array<string,int>>}
	 */
	public function import_from_json_file( string $filename ): array {
		$contents = is_readable( $filename ) ? file_get_contents( $filename ) : false;
		if ( false === $contents ) {
			throw new \RuntimeException( 'Yandex Delivery pickup v2 JSON file is not readable.' );
		}
		$decoded = json_decode( $contents, true );
		if ( ! is_array( $decoded ) ) {
			throw new \RuntimeException( 'Yandex Delivery pickup v2 JSON file contains invalid JSON.' );
		}

		$rows = $this->extract_rows( $decoded );
		$report = array( 'received' => count( $rows ), 'normalized' => 0, 'saved' => 0, 'skipped_invalid' => 0, 'batches' => 0, 'progress' => array() );
		foreach ( array_chunk( $rows, self::BATCH_SIZE ) as $batch ) {
			$normalized = array();
			foreach ( $batch as $row ) {
				$point = $this->normalizePickupPoint( $row );
				if ( null === $point ) {
					++$report['skipped_invalid'];
					continue;
				}
				$normalized[] = $point;
			}
			$save = $this->repository->upsert( $normalized );
			++$report['batches'];
			$report['normalized'] += count( $normalized );
			$report['saved'] += (int) $save['saved'];
			$report['skipped_invalid'] += (int) $save['skipped_invalid'];
			$report['progress'][] = array(
				'batch' => $report['batches'],
				'received' => count( $batch ),
				'normalized' => count( $normalized ),
				'saved' => (int) $save['saved'],
				'skipped_invalid' => (int) $save['skipped_invalid'],
			);
			unset( $normalized, $save );
			if ( function_exists( 'gc_collect_cycles' ) ) {
				gc_collect_cycles();
			}
		}

		return $report;
	}

	/** @return array<string,mixed>|null */
	public function normalizePickupPoint( mixed $raw ): ?array {
		$row = $this->to_array( $raw );
		if ( ! is_array( $row ) ) {
			return null;
		}
		$platform_station_id = $this->first( $row['platform_station_id'] ?? null, $row['platformStationId'] ?? null, $row['id'] ?? null, $row['station_id'] ?? null );
		$type = $this->first( $row['type'] ?? null, 'pickup_point' );
		if ( '' === $platform_station_id || '' === $type ) {
			return null;
		}

		$address = is_array( $row['address'] ?? null ) ? $row['address'] : array();
		$position = is_array( $row['position'] ?? null ) ? $row['position'] : array();
		$coordinates = is_array( $row['coordinates'] ?? null ) ? $row['coordinates'] : array();
		$contact = is_array( $row['contact'] ?? null ) ? $row['contact'] : array();
		$location_details = $row['location_details'] ?? $row['locationDetails'] ?? $row['address'] ?? null;
		$station_contact = $row['station_contact'] ?? $row['stationContact'] ?? $row['contact'] ?? null;
		$raw_json = $this->json( $row );

		return array(
			'platform_station_id' => $platform_station_id,
			'operator_station_id' => $this->first( $row['operator_station_id'] ?? null, $row['operatorStationId'] ?? null ),
			'operator_id' => $this->first( $row['operator_id'] ?? null, $row['operatorId'] ?? null ),
			'type' => $type,
			'name' => $this->first( $row['name'] ?? null, $row['title'] ?? null, $platform_station_id ),
			'yandex_geo_id' => $this->first( $row['yandex_geo_id'] ?? null, $row['yandexGeoId'] ?? null, $address['geoId'] ?? null, $address['geo_id'] ?? null ),
			'country' => $this->first( $row['country'] ?? null, $address['country'] ?? null ),
			'region' => $this->first( $row['region'] ?? null, $address['region'] ?? null ),
			'sub_region' => $this->first( $row['sub_region'] ?? null, $row['subRegion'] ?? null, $address['sub_region'] ?? null, $address['subRegion'] ?? null ),
			'locality' => $this->first( $row['locality'] ?? null, $address['locality'] ?? null, $address['city'] ?? null, $address['city_name'] ?? null ),
			'street' => $this->first( $row['street'] ?? null, $address['street'] ?? null ),
			'house' => $this->first( $row['house'] ?? null, $address['house'] ?? null ),
			'housing' => $this->first( $row['housing'] ?? null, $address['housing'] ?? null ),
			'building' => $this->first( $row['building'] ?? null, $address['building'] ?? null ),
			'apartment' => $this->first( $row['apartment'] ?? null, $address['apartment'] ?? null ),
			'postal_code' => $this->first( $row['postal_code'] ?? null, $row['postalCode'] ?? null, $address['postal_code'] ?? null, $address['postalCode'] ?? null ),
			'full_address' => $this->full_address( $address, $row['full_address'] ?? null, $row['fullAddress'] ?? null ),
			'latitude' => $this->first( $row['latitude'] ?? null, $row['lat'] ?? null, $position['latitude'] ?? null, $coordinates['latitude'] ?? null, $coordinates['lat'] ?? null ),
			'longitude' => $this->first( $row['longitude'] ?? null, $row['lon'] ?? null, $row['lng'] ?? null, $position['longitude'] ?? null, $coordinates['longitude'] ?? null, $coordinates['lon'] ?? null, $coordinates['lng'] ?? null ),
			'instruction' => $this->first( $row['instruction'] ?? null, $address['comment'] ?? null ),
			'phone' => $this->first( $row['phone'] ?? null, $row['phone_number'] ?? null, $contact['phone'] ?? null ),
			'schedule_text' => $this->schedule_formatter->format( $row['schedule'] ?? null ),
			'is_yandex_branded' => $this->bool( $row['is_yandex_branded'] ?? $row['isYandexBranded'] ?? false ),
			'is_market_partner' => $this->bool( $row['is_market_partner'] ?? $row['isMarketPartner'] ?? false ),
			'is_dark_store' => $this->bool( $row['is_dark_store'] ?? $row['isDarkStore'] ?? false ),
			'is_post_office' => $this->bool( $row['is_post_office'] ?? $row['isPostOffice'] ?? false ),
			'available_for_dropoff' => $this->bool( $row['available_for_dropoff'] ?? $row['availableForDropoff'] ?? false ),
			'deactivation_date' => $this->first( $row['deactivation_date'] ?? $row['deactivationDate'] ?? null ),
			'deactivation_date_predicted_debt' => $this->first( $row['deactivation_date_predicted_debt'] ?? $row['deactivationDatePredictedDebt'] ?? null ),
			'location_details_json' => $this->json( $location_details ),
			'station_contact_json' => $this->json( $station_contact ),
			'active' => true,
			'last_seen_at' => $this->now(),
			'raw_hash' => sha1( $raw_json ?? '' ),
			'created_at' => $this->now(),
			'updated_at' => $this->now(),
		);
	}

	/** @return array<int,mixed> */
	private function extract_rows( array $decoded ): array {
		foreach ( array( 'points', 'pickup_points', 'pickupPoints', 'items', 'results' ) as $key ) {
			if ( isset( $decoded[ $key ] ) ) {
				return $this->listify( $decoded[ $key ] );
			}
		}

		return $this->listify( $decoded );
	}

	/** @return array<int,mixed> */
	private function listify( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}

		return array_is_list( $value ) ? $value : array( $value );
	}

	private function to_array( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->to_array( $item );
			}
		}

		return $value;
	}

	private function first( mixed ...$values ): string {
		foreach ( $values as $value ) {
			if ( null === $value || is_array( $value ) || is_object( $value ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	/** @param array<string,mixed> $address */
	private function full_address( array $address, mixed ...$fallbacks ): string {
		$value = $this->first( $address['full_address'] ?? null, $address['fullAddress'] ?? null, ...$fallbacks );
		if ( '' !== $value ) {
			return $value;
		}
		$parts = array();
		foreach ( array( 'country', 'region', 'subRegion', 'sub_region', 'locality', 'street', 'house', 'housing', 'building', 'apartment' ) as $key ) {
			$part = $this->first( $address[ $key ] ?? null );
			if ( '' !== $part ) {
				$parts[] = $part;
			}
		}

		return implode( ', ', $parts );
	}

	private function bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		if ( is_numeric( $value ) ) {
			return (int) $value > 0;
		}

		return in_array( strtolower( trim( (string) $value ) ), array( '1', 'true', 'yes', 'y' ), true );
	}

	private function json( mixed $value ): ?string {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_UNICODE );

		return is_string( $json ) ? $json : null;
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
