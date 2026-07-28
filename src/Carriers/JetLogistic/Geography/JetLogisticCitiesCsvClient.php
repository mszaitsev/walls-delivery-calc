<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Geography;

defined( 'ABSPATH' ) || exit;

final class JetLogisticCitiesCsvClient {
	public function fetch( string $url ): string {
		$response = wp_remote_get( $url, array( 'timeout' => 20 ) );
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( 'Jet Logistic cities CSV download failed.' );
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === trim( $body ) ) {
			throw new \RuntimeException( 'Jet Logistic cities CSV is empty.' );
		}

		return $body;
	}
}
