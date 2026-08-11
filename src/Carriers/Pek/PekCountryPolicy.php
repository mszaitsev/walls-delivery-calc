<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek;

defined( 'ABSPATH' ) || exit;

final class PekCountryPolicy {
	private const SENDER_COUNTRY = 'RU';
	private const INTERNATIONAL_RECEIVER_COUNTRIES = array( 'AM', 'BY', 'KG', 'KZ' );

	/** @return array<int,string> */
	public function supported_receiver_countries(): array {
		return PekSettings::PLANNED_COUNTRIES;
	}

	public function sender_country(): string {
		return self::SENDER_COUNTRY;
	}

	public function supports_calculation_direction( string $sender_country, string $receiver_country ): bool {
		return self::SENDER_COUNTRY === $this->country( $sender_country )
			&& in_array( $this->country( $receiver_country ), PekSettings::PLANNED_COUNTRIES, true );
	}

	public function supports_receiver_country( string $receiver_country ): bool {
		return $this->supports_calculation_direction( self::SENDER_COUNTRY, $receiver_country );
	}

	public function is_international_receiver( string $receiver_country ): bool {
		return in_array( $this->country( $receiver_country ), self::INTERNATIONAL_RECEIVER_COUNTRIES, true );
	}

	public function allows_automatic_shipment_create( string $sender_country, string $receiver_country ): bool {
		return self::SENDER_COUNTRY === $this->country( $sender_country )
			&& self::SENDER_COUNTRY === $this->country( $receiver_country );
	}

	public function allows_manual_attach( string $receiver_country ): bool {
		return in_array( $this->country( $receiver_country ), PekSettings::PLANNED_COUNTRIES, true );
	}

	private function country( string $country ): string {
		return strtoupper( trim( $country ) );
	}
}
