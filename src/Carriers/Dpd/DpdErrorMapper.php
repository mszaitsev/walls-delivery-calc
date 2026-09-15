<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd;

defined( 'ABSPATH' ) || exit;

final class DpdErrorMapper {
	/**
	 * @param mixed $response
	 * @return array{code:string,message:string}
	 */
	public function map( mixed $response ): array {
		$data = is_array( $response ) ? $response : ( is_object( $response ) ? get_object_vars( $response ) : array() );
		$code = (string) ( $data['errorCode'] ?? $data['code'] ?? $data['status'] ?? '' );
		$message = (string) ( $data['errorMessage'] ?? $data['message'] ?? '' );

		return array(
			'code' => $code,
			'message' => '' !== trim( $message ) ? $message : $code,
		);
	}
}

