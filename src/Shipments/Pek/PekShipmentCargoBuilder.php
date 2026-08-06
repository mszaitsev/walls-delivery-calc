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
		unset( $declared_value_kopecks );
		if ( count( $request->places ) < 1 ) {
			throw new \RuntimeException( 'Для заявки ПЭК нужно минимум одно грузоместо.' );
		}
		$places = array();
		$weight = 0.0;
		$raw_volume_sum = 0.0;
		$max_dimension = 0.0;
		foreach ( $request->places as $place ) {
			if ( ! $place instanceof ShipmentPlace || array() !== $place->validate() ) {
				throw new \RuntimeException( 'Некорректные грузоместа ПЭК.' );
			}
			$w = $this->ceil2( $place->weight_g / 1000 );
			$l = $this->ceil2( $place->length_cm / 100 );
			$wi = $this->ceil2( $place->width_cm / 100 );
			$h = $this->ceil2( $place->height_cm / 100 );
			$raw_volume = $l * $wi * $h;
			$v = $this->ceil2( $raw_volume );
			$weight += $w;
			$raw_volume_sum += $raw_volume;
			$max_dimension = max( $max_dimension, $l, $wi, $h );
			$places[] = array(
				'quantity' => 1,
				'weight' => $w,
				'length' => $l,
				'width' => $wi,
				'height' => $h,
				'volume' => $v,
			);
		}
		$weight = $this->ceil2( $weight );
		$volume = $this->ceil2( $raw_volume_sum );
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
					'customerCorrelation' => (string) ( $request->meta['pek_correlation'] ?? '' ),
					'orderNumber' => (string) ( $request->meta['order_num'] ?? $request->order_id ),
					'positionsCount' => count( $places ),
					'weight' => $weight,
					'volume' => $volume,
					'description' => $summary['description'],
					'cargoPlaceList' => $places,
				),
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
