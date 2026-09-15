<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Geography;

use WallsShop\WDC\Locations\Storage\LocationDeliveryCodeRepository;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class DpdDaDataDeliveryFallbackService {
	public function __construct(
		private LocationRepository $locations,
		private LocationDeliveryCodeRepository $delivery_codes,
		private DpdDaDataDeliveryClientInterface $client
	) {
	}

	/**
	 * @return array{success:bool,message:string,city_id:string,location_id:int,token_id:string}
	 */
	public function resolve_location_id( int $location_id ): array {
		$location = $location_id > 0 ? $this->locations->find_by_id( $location_id ) : null;
		if ( ! $location instanceof Location || null === $location->id ) {
			return $this->failure( 'Location was not found.', $location_id, '' );
		}

		$kladr = $this->normalize_kladr( $location->kladr_id );
		if ( '' === $kladr ) {
			return $this->failure( 'Location does not have KLADR ID for DaData delivery lookup.', (int) $location->id, '' );
		}

		$result = $this->client->find_dpd_id_by_kladr( $kladr );
		if ( empty( $result['success'] ) || '' === (string) $result['dpd_id'] ) {
			return $this->failure( (string) $result['message'], (int) $location->id, (string) ( $result['token_id'] ?? '' ) );
		}

		$city_id = (string) $result['dpd_id'];
		if ( ! $this->delivery_codes->save_dpd_city_id( (int) $location->id, $city_id ) ) {
			return $this->failure( 'DPD cityId from DaData was not saved.', (int) $location->id, (string) ( $result['token_id'] ?? '' ) );
		}

		return array(
			'success' => true,
			'message' => 'DPD cityId saved from DaData delivery API.',
			'city_id' => $city_id,
			'location_id' => (int) $location->id,
			'token_id' => (string) ( $result['token_id'] ?? '' ),
		);
	}

	private function normalize_kladr( string $kladr_id ): string {
		return preg_replace( '/\D+/', '', strtoupper( preg_replace( '/^RU/i', '', trim( $kladr_id ) ) ) ) ?? '';
	}

	/**
	 * @return array{success:bool,message:string,city_id:string,location_id:int,token_id:string}
	 */
	private function failure( string $message, int $location_id, string $token_id ): array {
		return array( 'success' => false, 'message' => $message, 'city_id' => '', 'location_id' => $location_id, 'token_id' => $token_id );
	}
}
