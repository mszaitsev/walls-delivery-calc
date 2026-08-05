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
		$type = 'paid' === $source ? 'pvz' : ( 'free' === $source || 'terminal' === $point->type ? 'terminal' : 'pvz' );
		$title = 'terminal' === $type ? 'Собственный пункт выдачи ПЭК' : 'Партнерский пункт выдачи ПЭК';
		$comment = 'paid' === $source ? 'Возможна небольшая доплата за доставку в этот пункт' : '';
		$point_name = $this->public_point_name( $point, $raw );
		$row = array(
			'id' => $point->code,
			'point_code' => $point->code,
			'carrier' => PekSettings::CARRIER_KEY,
			'carrier_key' => PekSettings::CARRIER_KEY,
			'service_key' => PekSettings::SERVICE_KEY,
			'pickup_family' => PekSettings::PICKUP_FAMILY,
			'point_type' => $type,
			'point_type_label' => $title,
			'point_title' => $title,
			'card_title' => $title,
			'point_name' => $point_name,
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
			'presentation_comment' => $comment,
			'marker_type' => 'terminal' === $type ? 'terminal' : 'pickup',
			'source' => in_array( $source, array( 'free', 'paid' ), true ) ? $source : '',
			'location_id' => $location_id,
			'country_code' => strtoupper( trim( $country_code ) ?: 'RU' ),
			'destination_fingerprint' => $destination_fingerprint,
			'provider_destination_fingerprint' => $destination_fingerprint,
			'requires_rate_refresh' => true,
			'snapshot' => array(
				'carrier_key' => PekSettings::CARRIER_KEY,
				'service_key' => PekSettings::SERVICE_KEY,
				'pickup_family' => PekSettings::PICKUP_FAMILY,
				'point_code' => $point->code,
				'point_id' => $point->code,
				'point_type' => $type,
				'point_type_label' => $title,
				'point_title' => $title,
				'card_title' => $title,
				'point_name' => $point_name,
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
				'presentation_comment' => $comment,
				'marker_type' => 'terminal' === $type ? 'terminal' : 'pickup',
				'source' => in_array( $source, array( 'free', 'paid' ), true ) ? $source : '',
				'location_id' => $location_id,
				'country_code' => strtoupper( trim( $country_code ) ?: 'RU' ),
				'destination_fingerprint' => $destination_fingerprint,
				'provider_destination_fingerprint' => $destination_fingerprint,
				'requires_rate_refresh' => true,
			),
		);

		return $row;
	}

	/**
	 * @param array<string,mixed> $raw
	 */
	private function public_point_name( PickupPoint $point, array $raw ): string {
		foreach ( array( $raw['division_name'] ?? null, $raw['branch_name'] ?? null ) as $candidate ) {
			if ( ! is_scalar( $candidate ) ) {
				continue;
			}
			$name = trim( (string) $candidate );
			if ( '' === $name || $name === $point->code || $this->looks_like_internal_identifier( $name ) ) {
				continue;
			}
			return $name;
		}

		return '';
	}

	private function looks_like_internal_identifier( string $value ): bool {
		if ( 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value ) ) {
			return true;
		}

		return 1 === preg_match( '/^[a-z0-9_-]{16,}$/i', $value )
			&& 1 === preg_match( '/[0-9]/', $value )
			&& 1 === preg_match( '/[a-z]/i', $value );
	}
}
