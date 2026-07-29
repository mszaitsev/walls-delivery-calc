<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Geography;

defined( 'ABSPATH' ) || exit;

final class JetLogisticRegionNameNormalizer {
	private const ALIASES = array(
		'алма ата' => 'алматинская',
	);

	public function normalize( string $value ): string {
		$value = strtr(
			trim( $value ),
			array(
				'Ё' => 'Е',
				'ё' => 'е',
				'–' => '-',
				'—' => '-',
				'−' => '-',
			)
		);
		$value = mb_strtolower( $value, 'UTF-8' );
		$value = strtr( $value, array( '(' => ' ', ')' => ' ' ) );
		$value = preg_replace( '/[.]+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\b(область|обл|республика|респ|край)\b/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/[-]+/u', ' ', $value ) ?? $value;
		$value = preg_replace( '/\s+/u', ' ', $value ) ?? $value;
		$value = trim( $value );

		return self::ALIASES[ $value ] ?? $value;
	}
}
