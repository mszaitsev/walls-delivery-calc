<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Pricing;

use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Packaging\PackagingBuilder;
use WallsShop\WDC\Packaging\PackagingBuilderConfig;
use WallsShop\WDC\Packaging\PackagingParcel;
use WallsShop\WDC\Packaging\PackagingResult;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryPricingRequestBuilder {
	private PackagingBuilder $packaging_builder;
	/** @var array<string,mixed> */
	private array $last_diagnostics = array();

	public function __construct( ?PackagingBuilder $packaging_builder = null ) {
		$this->packaging_builder = $packaging_builder ?? new PackagingBuilder( PackagingBuilderConfig::defaults() );
	}

	/** @return array<string,mixed> */
	public function pickup( QuoteRequest $request, string $source_platform_station_id, string $destination_platform_station_id ): array {
		return $this->base_payload(
			$request,
			$source_platform_station_id,
			array( 'platform_station_id' => $destination_platform_station_id ),
			'self_pickup'
		);
	}

	/** @return array<string,mixed> */
	public function courier( QuoteRequest $request, string $source_platform_station_id, string $destination_address ): array {
		return $this->base_payload(
			$request,
			$source_platform_station_id,
			array( 'address' => $destination_address ),
			'time_interval'
		);
	}

	/** @return array<string,mixed> */
	public function last_diagnostics(): array {
		return $this->last_diagnostics;
	}

	/** @param array<string,string> $destination @return array<string,mixed> */
	private function base_payload( QuoteRequest $request, string $source_platform_station_id, array $destination, string $tariff ): array {
		$package = $this->package_payload( $request );

		return array(
			'source' => array( 'platform_station_id' => $source_platform_station_id ),
			'destination' => $destination,
			'tariff' => $tariff,
			'total_weight' => $package['total_weight'],
			'total_assessed_price' => $this->assessed_price_kopecks( $request ),
			'client_price' => 0,
			'payment_method' => 'already_paid',
			'places' => $package['places'],
		);
	}

	/**
	 * @return array{total_weight:int,places:array<int,array{physical_dims:array{weight_gross:int,dx:float,dy:float,dz:float}}>}
	 */
	private function package_payload( QuoteRequest $request ): array {
		$result = $this->packaging_builder->build( $request );
		$places = $this->places_from_packaging_result( $result );

		if ( array() !== $places ) {
			$total_weight = array_sum( array_map( static fn( array $place ): int => (int) $place['physical_dims']['weight_gross'], $places ) );
			$this->last_diagnostics = $this->diagnostics( $result->diagnostics, $places, $total_weight, '' );

			return array(
				'total_weight' => $total_weight,
				'places' => $places,
			);
		}

		$fallback = $this->fallback_package_payload( $request );
		$this->last_diagnostics = $this->diagnostics(
			array(
				'package_builder_source' => 'legacy_single_place_fallback',
				'packing_strategy' => 'legacy_single_place_fallback',
				'parcels_count' => 0,
			),
			$fallback['places'],
			$fallback['total_weight'],
			'empty_or_invalid_packaging_result'
		);

		return $fallback;
	}

	/**
	 * @return array<int,array{physical_dims:array{weight_gross:int,dx:float,dy:float,dz:float}}>
	 */
	private function places_from_packaging_result( PackagingResult $result ): array {
		$places = array();
		foreach ( $result->parcels() as $parcel ) {
			if ( ! $parcel instanceof PackagingParcel || ! $this->is_valid_parcel( $parcel ) ) {
				return array();
			}
			for ( $index = 0; $index < max( 1, $parcel->quantity ); ++$index ) {
				$places[] = $this->place_from_parcel( $parcel );
			}
		}

		return $places;
	}

	/**
	 * @return array{total_weight:int,places:array<int,array{physical_dims:array{weight_gross:int,dx:float,dy:float,dz:float}}>}
	 */
	private function fallback_package_payload( QuoteRequest $request ): array {
		$config = PackagingBuilderConfig::defaults();
		$weight = max( $config->default_weight_g, (int) $request->package->get_total_weight_g() );

		return array(
			'total_weight' => $weight,
			'places' => array(
				array(
					'physical_dims' => array(
						'weight_gross' => $weight,
						'dx' => max( $config->default_length_cm, (float) ( $request->package->length_cm ?? 0 ) ),
						'dy' => max( $config->default_width_cm, (float) ( $request->package->width_cm ?? 0 ) ),
						'dz' => max( $config->default_height_cm, (float) ( $request->package->height_cm ?? 0 ) ),
					),
				),
			),
		);
	}

	private function assessed_price_kopecks( QuoteRequest $request ): int {
		return max( 1, $request->order_total->get_kopecks(), $request->package->cart_total->get_kopecks(), $request->package->declared_value->get_kopecks() );
	}

	private function is_valid_parcel( PackagingParcel $parcel ): bool {
		return $parcel->weight_g > 0 && $parcel->length_cm > 0 && $parcel->width_cm > 0 && $parcel->height_cm > 0;
	}

	/**
	 * @return array{physical_dims:array{weight_gross:int,dx:float,dy:float,dz:float}}
	 */
	private function place_from_parcel( PackagingParcel $parcel ): array {
		return array(
			'physical_dims' => array(
				'weight_gross' => max( 1, $parcel->weight_g ),
				'dx' => max( 0.1, $parcel->length_cm ),
				'dy' => max( 0.1, $parcel->width_cm ),
				'dz' => max( 0.1, $parcel->height_cm ),
			),
		);
	}

	/**
	 * @param array<string,mixed> $packaging
	 * @param array<int,array{physical_dims:array{weight_gross:int,dx:float,dy:float,dz:float}}> $places
	 * @return array<string,mixed>
	 */
	private function diagnostics( array $packaging, array $places, int $total_weight, string $fallback_reason ): array {
		return array(
			'package_builder_source' => (string) ( $packaging['package_builder_source'] ?? '' ),
			'packing_strategy' => (string) ( $packaging['packing_strategy'] ?? '' ),
			'parcels_count' => (int) ( $packaging['parcels_count'] ?? count( $places ) ),
			'total_weight_g' => $total_weight,
			'places_count' => count( $places ),
			'package' => array(
				'total_weight_g' => $total_weight,
				'places' => array_map( static fn( array $place ): array => $place['physical_dims'], $places ),
			),
			'fallback_reason' => $fallback_reason,
		);
	}
}
