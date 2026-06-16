<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd;

use Throwable;
use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class DpdGeographyDiagnosticService {
	public function __construct(
		private DpdCityResolver $resolver,
		private LocationDeliveryCodeRepository $delivery_codes,
		private LocationRepository $locations
	) {
	}

	/**
	 * @return array{success:bool,message:string,city_id:string,source:string,saved:bool,multiple:bool,resolver_applied:bool,matched_by:array<int,string>}
	 */
	public function diagnose_location_id( int $location_id ): array {
		$location = $location_id > 0 ? $this->locations->find_by_id( $location_id ) : null;
		if ( ! $location instanceof Location ) {
			return $this->empty_result( 'Location was not found.' );
		}

		try {
			$result = $this->resolver->resolve( $location );
		} catch ( DpdException $throwable ) {
			return $this->empty_result( 'DPD API error: ' . $this->safe_message( $throwable->getMessage() ) );
		} catch ( Throwable $throwable ) {
			return $this->empty_result( 'DPD API error: ' . $this->safe_message( $throwable->getMessage() ) );
		}
		if ( null === $result ) {
			if ( '' !== $this->resolver->last_error() ) {
				return $this->empty_result( 'DPD cityId mapping was not found for location_id=' . $location_id . '. Run DPD geography import, DaData fallback, or add cityId manually.' );
			}

			return $this->empty_result( 'DPD cityId mapping was not found for location_id=' . $location_id . '. Run DPD geography import, DaData fallback, or add cityId manually.' );
		}

		return array(
			'success' => true,
			'message' => 'DPD cityId mapping found.',
			'city_id' => $result['city_id'],
			'source' => $result['source'],
			'saved' => $result['saved'],
			'multiple' => $result['multiple'],
			'resolver_applied' => $result['resolver_applied'],
			'matched_by' => $result['matched_by'],
		);
	}

	/**
	 * @return array{success:bool,message:string,city_id:string,source:string,saved:bool,multiple:bool,resolver_applied:bool,matched_by:array<int,string>}
	 */
	public function save_manual_mapping( int $location_id, string $city_id ): array {
		$location = $location_id > 0 ? $this->locations->find_by_id( $location_id ) : null;
		$city_id = trim( $city_id );
		if ( ! $location instanceof Location ) {
			return $this->empty_result( 'Location was not found.' );
		}
		if ( '' === $city_id ) {
			return $this->empty_result( 'DPD cityId is empty.' );
		}

		if ( null === $location->id || $location->id <= 0 || ! $this->delivery_codes->save_dpd_city_id( $location->id, $city_id ) ) {
			return $this->empty_result( 'DPD cityId mapping was not saved.' );
		}

		return array(
			'success' => true,
			'message' => 'DPD cityId mapping saved.',
			'city_id' => $city_id,
			'source' => 'manual_admin',
			'saved' => true,
			'multiple' => false,
			'resolver_applied' => false,
			'matched_by' => array( 'manual_admin' ),
		);
	}

	/**
	 * @return array{success:bool,message:string,city_id:string,source:string,saved:bool,multiple:bool,resolver_applied:bool,matched_by:array<int,string>}
	 */
	private function empty_result( string $message ): array {
		return array(
			'success' => false,
			'message' => $message,
			'city_id' => '',
			'source' => '',
			'saved' => false,
			'multiple' => false,
			'resolver_applied' => false,
			'matched_by' => array(),
		);
	}

	private function safe_message( string $message ): string {
		$message = preg_replace( '/\b(?:clientKey|client_key|token|secret)[A-Za-z0-9._\-:=]*\b/i', '[redacted]', $message ) ?? $message;

		return trim( $message );
	}
}
