<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;

defined( 'ABSPATH' ) || exit;

final class PekShipmentCargoBuilder {
	public function __construct( private PekSettings $settings ) {
	}

	/** @return array{payload:array<string,mixed>,summary:array<string,mixed>} */
	public function build( ShipmentCreateRequest $request, int $declared_value_kopecks ): array {
		if ( count( $request->places ) < 1 || count( $request->places ) > 10 ) {
			throw new \RuntimeException( 'Для заявки ПЭК допускается от 1 до 10 грузомест.' );
		}
		$places = array();
		$weight = 0.0;
		$volume = 0.0;
		$max_dimension = 0.0;
		foreach ( $request->places as $place ) {
			if ( ! $place instanceof ShipmentPlace || array() !== $place->validate() ) {
				throw new \RuntimeException( 'Некорректные грузоместа ПЭК.' );
			}
			$w = $this->ceil2( $place->weight_g / 1000 );
			$l = $this->ceil2( $place->length_cm / 100 );
			$wi = $this->ceil2( $place->width_cm / 100 );
			$h = $this->ceil2( $place->height_cm / 100 );
			$v = $this->ceil2( $l * $wi * $h );
			$weight += $w;
			$volume += $v;
			$max_dimension = max( $max_dimension, $l, $wi, $h );
			$places[] = array(
				'position' => $place->place_number,
				'quantity' => 1,
				'weight' => $w,
				'length' => $l,
				'width' => $wi,
				'height' => $h,
				'volume' => $v,
			);
		}
		$weight = $this->ceil2( $weight );
		$volume = $this->ceil2( $volume );
		$summary = array(
			'place_count' => count( $places ),
			'aggregate_weight_kg' => $weight,
			'aggregate_volume_m3' => $volume,
			'max_dimension_m' => $this->ceil2( $max_dimension ),
			'description' => $this->description( $request ),
		);

		return array(
			'payload' => array(
				'common' => array(
					'type' => PekSettings::LTL_PRODUCT_TYPE,
					'positionsCount' => count( $places ),
					'weight' => $weight,
					'volume' => $volume,
					'cargoDescription' => $summary['description'],
				),
				'cargoPlaceList' => $places,
				'cost' => round( $declared_value_kopecks / 100, 2 ),
			),
			'summary' => $summary,
		);
	}

	private function ceil2( float $value ): float {
		return ceil( $value * 100 ) / 100;
	}

	private function description( ShipmentCreateRequest $request ): string {
		foreach ( $request->places as $place ) {
			if ( $place instanceof ShipmentPlace && '' !== trim( $place->combined_name ) ) {
				return $this->bounded( $place->combined_name );
			}
		}

		return $this->bounded( $this->settings->default_cargo_description() ?: 'Товары интернет-магазина' );
	}

	private function bounded( string $value ): string {
		$value = function_exists( 'wp_strip_all_tags' ) ? wp_strip_all_tags( $value ) : strip_tags( $value );
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;
		$value = trim( $value );
		if ( '' === $value ) {
			$value = 'Товары интернет-магазина';
		}

		return function_exists( 'mb_substr' ) ? mb_substr( $value, 0, 120 ) : substr( $value, 0, 120 );
	}
}
