<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\AddressSuggestions;

defined( 'ABSPATH' ) || exit;

final class AddressSuggestionService {
	public function __construct(
		private AddressSuggestionSettings $settings,
		private AddressSuggestionClientInterface $client,
		private AddressSuggestionNormalizer $normalizer
	) {
	}

	/**
	 * @param array<string,string> $context
	 * @return array<string,mixed>
	 */
	public function suggest( string $stage, string $query, array $context = array() ): array {
		if ( ! $this->settings->enabled() ) {
			return $this->failure( 'dadata_suggestions_disabled' );
		}

		if ( ! $this->settings->has_any_configured_token() ) {
			return $this->failure( 'no_available_dadata_token' );
		}

		$response = $this->client->suggest( $stage, $query, $context );
		if ( empty( $response['success'] ) ) {
			return array(
				'success'       => false,
				'error_code'    => (string) ( $response['error_code'] ?? 'dadata_api_failed' ),
				'error_message' => '',
				'items'         => array(),
				'debug'         => $this->debug( $stage, $query, $context, $response ),
			);
		}

		$items = $this->normalizer->normalize_many( is_array( $response['suggestions'] ?? null ) ? $response['suggestions'] : array() );

		return array(
			'success' => true,
			'error_code' => '',
			'error_message' => '',
			'items' => $items,
			'debug' => $this->debug( $stage, $query, $context, $response ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function failure( string $code ): array {
		return array(
			'success'       => false,
			'error_code'    => $code,
			'error_message' => '',
			'items'         => array(),
			'debug'         => array(),
		);
	}

	/**
	 * @param array<string,string> $context
	 * @param array<string,mixed> $response
	 * @return array<string,mixed>
	 */
	private function debug( string $stage, string $query, array $context, array $response ): array {
		return array(
			'stage' => $stage,
			'query' => $query,
			'context' => array(
				'city_kladr_id' => (string) ( $context['city_kladr_id'] ?? '' ),
				'street_fias_id' => (string) ( $context['street_fias_id'] ?? '' ),
			),
			'suggestions_count' => count( is_array( $response['suggestions'] ?? null ) ? $response['suggestions'] : array() ),
			'status_code' => (int) ( $response['status_code'] ?? 0 ),
			'request_body' => is_array( $response['body'] ?? null ) ? $response['body'] : array(),
		);
	}
}
