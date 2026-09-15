<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\AddressSuggestions;

defined( 'ABSPATH' ) || exit;

final class FallbackSuggestionClient implements AddressSuggestionClientInterface {
	public function suggest( string $stage, string $query, array $context = array() ): array {
		return array(
			'success'       => false,
			'stage'         => $stage,
			'status_code'   => 0,
			'body'          => array(),
			'suggestions'   => array(),
			'error_code'    => 'dadata_suggestions_unavailable',
			'error_message' => 'DaData suggestions are unavailable.',
		);
	}
}
