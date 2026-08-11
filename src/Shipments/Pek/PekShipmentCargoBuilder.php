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
		$weight_hundredths_kg = 0;
		$raw_volume_sum_cm3 = 0;
		$max_dimension_cm = 0;
		foreach ( $request->places as $place ) {
			if ( ! $place instanceof ShipmentPlace || array() !== $place->validate() ) {
				throw new \RuntimeException( 'Некорректные грузоместа ПЭК.' );
			}
			$w = $this->hundredths_kg( $place->weight_g );
			$l = $this->hundredths_m( $place->length_cm );
			$wi = $this->hundredths_m( $place->width_cm );
			$h = $this->hundredths_m( $place->height_cm );
			$raw_volume_cm3 = $place->length_cm * $place->width_cm * $place->height_cm;
			$v = $this->hundredths_m3( $raw_volume_cm3 );
			$weight_hundredths_kg += $w;
			$raw_volume_sum_cm3 += $raw_volume_cm3;
			$max_dimension_cm = max( $max_dimension_cm, $place->length_cm, $place->width_cm, $place->height_cm );
			$places[] = array(
				'quantity' => 1,
				'weight' => $this->decimal_hundredths( $w ),
				'length' => $this->decimal_hundredths( $l ),
				'width' => $this->decimal_hundredths( $wi ),
				'height' => $this->decimal_hundredths( $h ),
				'volume' => $this->decimal_hundredths( $v ),
			);
		}
		$weight = $this->decimal_hundredths( $weight_hundredths_kg );
		$volume = $this->decimal_hundredths( $this->hundredths_m3( $raw_volume_sum_cm3 ) );
		$summary = array(
			'place_count' => count( $places ),
			'aggregate_weight_kg' => $weight,
			'aggregate_volume_m3' => $volume,
			'max_dimension_m' => $this->decimal_hundredths( $this->hundredths_m( $max_dimension_cm ) ),
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

	private function hundredths_kg( int $weight_g ): int {
		return intdiv( max( 1, $weight_g ) + 9, 10 );
	}

	private function hundredths_m( int $centimeters ): int {
		return max( 1, $centimeters );
	}

	private function hundredths_m3( int $volume_cm3 ): int {
		return intdiv( max( 1, $volume_cm3 ) + 9999, 10000 );
	}

	private function decimal_hundredths( int $hundredths ): int|float {
		if ( 0 === $hundredths % 100 ) {
			return intdiv( $hundredths, 100 );
		}

		return (float) ( intdiv( $hundredths, 100 ) . '.' . str_pad( (string) ( $hundredths % 100 ), 2, '0', STR_PAD_LEFT ) );
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
