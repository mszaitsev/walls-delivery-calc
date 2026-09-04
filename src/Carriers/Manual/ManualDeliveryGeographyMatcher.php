<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Manual;

use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\Domain\Quote\QuoteRequest;

defined( 'ABSPATH' ) || exit;

final class ManualDeliveryGeographyMatcher {
	public function __construct( private ManualDeliveryGeographyRepository $geography ) {
	}

	/**
	 * @return array{available:bool,reason:string}
	 */
	public function match( DeliveryService $service, QuoteRequest $request ): array {
		if ( null === $service->id ) {
			return array( 'available' => false, 'reason' => 'manual_service_id_missing' );
		}

		$country = strtoupper( trim( $request->country_code ?: $request->destination->country_code ) );
		if ( 'RU' !== $country ) {
			return array( 'available' => true, 'reason' => 'manual_non_ru_country_scope' );
		}

		$regions = $this->geography->regions( (int) $service->id );
		$locations = $this->geography->locations( (int) $service->id );
		if ( array() === $regions && array() === $locations ) {
			return array( 'available' => true, 'reason' => 'manual_no_ru_restrictions' );
		}

		$region = $this->destination_region_name( $request );
		$location = $this->destination_location_name( $request );
		if ( '' === $region || '' === $location ) {
			return array( 'available' => false, 'reason' => 'manual_destination_identity_missing' );
		}

		$region_key = $this->key( $region );
		foreach ( $regions as $allowed_region ) {
			if ( $this->key( $allowed_region ) === $region_key ) {
				return array( 'available' => true, 'reason' => 'manual_region_match' );
			}
		}

		$location_key = $this->key( $location );
		foreach ( $locations as $allowed_location ) {
			if ( $this->key( $allowed_location['region_name'] ) === $region_key && $this->key( $allowed_location['location_name'] ) === $location_key ) {
				return array( 'available' => true, 'reason' => 'manual_location_match' );
			}
		}

		return array( 'available' => false, 'reason' => 'manual_geography_restricted' );
	}

	private function destination_region_name( QuoteRequest $request ): string {
		foreach ( array( $request->customer_context['region_name'] ?? null, $request->customer_context['selected_location_region'] ?? null, $request->destination->region_name ) as $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	private function destination_location_name( QuoteRequest $request ): string {
		foreach ( array( $request->customer_context['place_name'] ?? null, $request->customer_context['settlement_name'] ?? null, $request->customer_context['city_name'] ?? null, $request->customer_context['selected_location_name'] ?? null, $request->destination->settlement, $request->destination->city ) as $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return '';
	}

	private function key( string $value ): string {
		$value = str_replace( array( 'Ё', 'ё' ), array( 'Е', 'е' ), $value );
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		$value = preg_replace( '/\s+/u', ' ', trim( $value ) );

		return is_string( $value ) ? $value : '';
	}
}
