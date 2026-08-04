<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Pickup;

use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Pickup\PickupPoint;

defined( 'ABSPATH' ) || exit;

final class PekCheckoutPickupPointFormatter {
	/**
	 * @return array<string,mixed>
	 */
	public function format( PickupPoint $point, string $destination_fingerprint = '', int $location_id = 0, string $country_code = 'RU' ): array {
		$raw = is_array( $point->raw_reference ) ? $point->raw_reference : array();
		$source = (string) ( $raw['source'] ?? '' );
		$type = 'free' === $source || 'terminal' === $point->type ? 'terminal' : 'pvz';
		$title = 'terminal' === $type ? 'Терминал ПЭК' : 'Пункт выдачи ПЭК';
		$row = array(
			'id' => $point->code,
			'point_code' => $point->code,
			'carrier' => PekSettings::CARRIER_KEY,
			'carrier_key' => PekSettings::CARRIER_KEY,
			'service_key' => PekSettings::SERVICE_KEY,
			'pickup_family' => PekSettings::PICKUP_FAMILY,
			'point_type' => $type,
			'point_type_label' => 'terminal' === $type ? 'Терминал' : 'Пункт выдачи',
			'point_title' => $title,
			'card_title' => $title,
			'point_name' => (string) ( $raw['division_name'] ?? $raw['branch_name'] ?? '' ),
			'point_address' => $point->address,
			'address' => $point->address,
			'city_name' => $point->city,
			'region_name' => $point->region,
			'latitude' => $point->latitude,
			'longitude' => $point->longitude,
			'lat' => $point->latitude,
			'lng' => $point->longitude,
			'work_time' => $point->work_time,
			'description' => $point->comment,
			'marker_type' => 'terminal' === $type ? 'terminal' : 'pickup',
			'source' => in_array( $source, array( 'free', 'paid' ), true ) ? $source : '',
			'location_id' => $location_id,
			'country_code' => strtoupper( trim( $country_code ) ?: 'RU' ),
			'destination_fingerprint' => $destination_fingerprint,
			'requires_rate_refresh' => true,
			'snapshot' => array(
				'carrier_key' => PekSettings::CARRIER_KEY,
				'service_key' => PekSettings::SERVICE_KEY,
				'pickup_family' => PekSettings::PICKUP_FAMILY,
				'point_code' => $point->code,
				'point_id' => $point->code,
				'point_type' => $type,
				'point_type_label' => 'terminal' === $type ? 'Терминал' : 'Пункт выдачи',
				'point_title' => $title,
				'point_name' => (string) ( $raw['division_name'] ?? $raw['branch_name'] ?? '' ),
				'point_address' => $point->address,
				'address' => $point->address,
				'city_name' => $point->city,
				'region_name' => $point->region,
				'latitude' => $point->latitude,
				'longitude' => $point->longitude,
				'lat' => $point->latitude,
				'lng' => $point->longitude,
				'work_time' => $point->work_time,
				'description' => $point->comment,
				'marker_type' => 'terminal' === $type ? 'terminal' : 'pickup',
				'source' => in_array( $source, array( 'free', 'paid' ), true ) ? $source : '',
				'location_id' => $location_id,
				'country_code' => strtoupper( trim( $country_code ) ?: 'RU' ),
				'destination_fingerprint' => $destination_fingerprint,
				'requires_rate_refresh' => true,
			),
		);

		return $row;
	}
}
