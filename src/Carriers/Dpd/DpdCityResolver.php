<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd;

use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class DpdCityResolver {
	public const CARRIER_KEY = DpdSettings::CARRIER_KEY;

	private string $last_error = '';

	public function __construct(
		private LocationDeliveryCodeRepository $delivery_codes
	) {
	}

	public function last_error(): string {
		return $this->last_error;
	}

	/**
	 * @return array{city_id:string,source:string,confidence:string,saved:bool,multiple:bool,resolver_applied:bool,matched_by:array<int,string>,diagnostics:array<string,mixed>}|null
	 */
	public function resolve( Location $location ): ?array {
		$this->last_error = '';
		if ( null === $location->id || $location->id <= 0 ) {
			$this->last_error = 'DPD cityId mapping lookup requires a saved WDC location_id.';
			return null;
		}

		$city_id = $this->delivery_codes->get_dpd_city_id( $location->id );
		if ( null !== $city_id ) {
			return array(
				'city_id' => $city_id,
				'source' => 'mapping',
				'confidence' => 'stored',
				'saved' => false,
				'multiple' => false,
				'resolver_applied' => false,
				'matched_by' => array( 'stored_mapping' ),
				'diagnostics' => array( 'location_id' => $location->id ),
			);
		}

		$this->last_error = 'DPD cityId mapping was not found. Use DPD geography import or manual mapping.';

		return null;
	}
}
