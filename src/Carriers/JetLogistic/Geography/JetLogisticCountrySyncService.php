<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Geography;

use WallsShop\WDC\Carriers\JetLogistic\JetLogisticSettings;
use WallsShop\WDC\DeliveryServices\DeliveryServiceCountryRepository;
use WallsShop\WDC\DeliveryServices\DeliveryServiceRepository;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class JetLogisticCountrySyncService {
	private const SEEN_COUNTRIES_KEY = 'jet_logistic_seen_country_codes';

	public function __construct(
		private JetLogisticGeographyRepository $repository,
		private DeliveryServiceRepository $services,
		private DeliveryServiceCountryRepository $countries,
		private SettingsRepository $settings
	) {
	}

	public function sync_discovered_countries(): void {
		$discovered = $this->normalize_country_codes( $this->repository->matched_country_codes() );
		$this->remember_seen_countries( $discovered );
		$this->enable_new_countries( $discovered );
	}

	public function ensure_country_enabled( string $country_code ): void {
		$country_code = $this->normalize_country_code( $country_code );
		if ( '' === $country_code ) {
			return;
		}

		$this->remember_seen_countries( array( $country_code ) );
		$this->enable_new_countries( array( $country_code ) );
	}

	/** @param array<int,string> $country_codes */
	private function enable_new_countries( array $country_codes ): void {
		$service = $this->services->find_by_service_key( JetLogisticSettings::SERVICE_KEY );
		if ( null === $service || null === $service->id ) {
			return;
		}

		$existing = $this->normalize_country_codes( $this->countries->countries( (int) $service->id ) );
		$merged = array_values( array_unique( array_merge( $existing, $this->normalize_country_codes( $country_codes ) ) ) );
		if ( $merged !== $existing ) {
			$this->countries->replace_countries( (int) $service->id, $merged );
		}
	}

	/** @param array<int,string> $country_codes */
	private function remember_seen_countries( array $country_codes ): void {
		$seen = $this->normalize_country_codes( $this->settings->get_array( self::SEEN_COUNTRIES_KEY, array() ) );
		$merged = array_values( array_unique( array_merge( $seen, $this->normalize_country_codes( $country_codes ) ) ) );
		if ( $merged !== $seen ) {
			$this->settings->set( self::SEEN_COUNTRIES_KEY, $merged );
		}
	}

	/** @param array<int,mixed> $country_codes @return array<int,string> */
	private function normalize_country_codes( array $country_codes ): array {
		$normalized = array();
		foreach ( $country_codes as $country_code ) {
			$country_code = $this->normalize_country_code( (string) $country_code );
			if ( '' !== $country_code ) {
				$normalized[] = $country_code;
			}
		}

		return array_values( array_unique( $normalized ) );
	}

	private function normalize_country_code( string $country_code ): string {
		$country_code = strtoupper( trim( $country_code ) );
		if ( 'RU' === $country_code || 1 !== preg_match( '/^[A-Z]{2}$/', $country_code ) ) {
			return '';
		}

		return $country_code;
	}
}
