<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd;

use WallsShop\WDC\Locations\Storage\LocationCarrierCodeRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class DpdCityResolver {
	public const CARRIER_KEY = DpdSettings::CARRIER_KEY;

	private string $last_error = '';

	public function __construct(
		private LocationCarrierCodeRepository $carrier_codes
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
		$stored = $this->carrier_codes->find_best( self::CARRIER_KEY, $location );
		if ( null !== $stored && '' !== trim( $stored['external_code'] ) ) {
			return array(
				'city_id' => $stored['external_code'],
				'source' => 'mapping',
				'confidence' => 'stored',
				'saved' => false,
				'multiple' => false,
				'resolver_applied' => false,
				'matched_by' => array( 'stored_mapping' ),
				'diagnostics' => array( 'mapping_id' => $stored['id'], 'meta' => $stored['meta'] ),
			);
		}

		$this->last_error = 'DPD cityId mapping was not found. Use manual mapping or future geography import.';

		return null;
	}
}
