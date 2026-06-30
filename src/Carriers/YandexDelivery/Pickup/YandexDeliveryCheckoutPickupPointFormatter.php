<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Pickup;

use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryCheckoutPickupPointFormatter {
	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	public function format( array $row ): array {
		$station_id = $this->station_id( $row );
		$type = (string) ( $row['type'] ?? '' );
		$type_label = 'terminal' === $type ? 'Терминал' : 'Пункт выдачи';
		$point_title = 'terminal' === $type ? 'Терминал Яндекс.Доставки' : 'Пункт выдачи Яндекс.Доставки';
		$marker_type = 'terminal' === $type ? 'terminal' : 'pickup';
		$address = (string) ( $row['full_address'] ?? '' );
		$name = (string) ( $row['name'] ?? '' );
		if ( '' === trim( $name ) ) {
			$name = $point_title;
		}
		$snapshot = array(
			'id' => YandexDeliverySettings::CARRIER_KEY . ':' . $station_id,
			'carrier_key' => YandexDeliverySettings::CARRIER_KEY,
			'service_key' => YandexDeliverySettings::SERVICE_KEY,
			'pickup_family' => YandexDeliverySettings::CARRIER_KEY . ':pickup',
			'point_code' => $station_id,
			'platform_station_id' => $station_id,
			'point_type' => $type,
			'point_type_label' => $type_label,
			'point_title' => $point_title,
			'display_code' => $station_id,
			'display_title' => trim( $point_title . ' ' . $station_id ),
			'marker_type' => $marker_type,
			'point_name' => $name,
			'address' => $address,
			'city' => (string) ( $row['locality'] ?? '' ),
			'region' => (string) ( $row['region'] ?? '' ),
			'postcode' => (string) ( $row['postal_code'] ?? '' ),
			'lat' => $this->coordinate( $row['latitude'] ?? null ),
			'lng' => $this->coordinate( $row['longitude'] ?? null ),
			'work_time' => (string) ( $row['schedule_text'] ?? '' ),
			'description' => (string) ( $row['instruction'] ?? '' ),
			'storage_notice' => '',
			'operator_id' => (string) ( $row['operator_id'] ?? '' ),
			'operator_station_id' => (string) ( $row['operator_station_id'] ?? '' ),
			'yandex_geo_id' => is_numeric( $row['yandex_geo_id'] ?? null ) ? (int) $row['yandex_geo_id'] : null,
		);

		return array(
			'id' => $snapshot['id'],
			'carrier' => YandexDeliverySettings::CARRIER_KEY,
			'carrier_key' => YandexDeliverySettings::CARRIER_KEY,
			'service_key' => YandexDeliverySettings::SERVICE_KEY,
			'pickup_family' => $snapshot['pickup_family'],
			'point_code' => $station_id,
			'platform_station_id' => $station_id,
			'point_type' => $type,
			'point_type_label' => $type_label,
			'point_title' => $point_title,
			'card_title' => $point_title,
			'display_code' => $station_id,
			'display_title' => $snapshot['display_title'],
			'marker_type' => $marker_type,
			'title' => $name,
			'point_name' => $name,
			'address' => $address,
			'point_address' => $address,
			'city' => $snapshot['city'],
			'city_name' => $snapshot['city'],
			'region' => $snapshot['region'],
			'region_name' => $snapshot['region'],
			'postal_code' => $snapshot['postcode'],
			'postcode' => $snapshot['postcode'],
			'point_postcode' => $snapshot['postcode'],
			'lat' => $snapshot['lat'],
			'lng' => $snapshot['lng'],
			'latitude' => $snapshot['lat'],
			'longitude' => $snapshot['lng'],
			'work_time' => $snapshot['work_time'],
			'schedule' => $snapshot['work_time'],
			'description' => $snapshot['description'],
			'storage_notice' => $snapshot['storage_notice'],
			'operator_id' => $snapshot['operator_id'],
			'snapshot' => $snapshot,
		);
	}

	/** @param array<string,mixed> $row */
	private function station_id( array $row ): string {
		return substr( preg_replace( '/[^A-Za-z0-9_-]+/', '', trim( (string) ( $row['platform_station_id'] ?? '' ) ) ) ?? '', 0, 80 );
	}

	private function coordinate( mixed $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}
}
