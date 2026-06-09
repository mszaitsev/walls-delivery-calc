<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\AddressSuggestions;

use WallsShop\WDC\Checkout\Address\AddressLineParser;

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

		if ( 'address' === $stage ) {
			return $this->suggest_address_with_variants( $stage, $query, $context );
		}
		if ( 'address_next' === $stage ) {
			return $this->suggest_address_next( $stage, $query, $context );
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
	 * @param array<string,string> $context
	 * @return array<string,mixed>
	 */
	private function suggest_address_next( string $stage, string $query, array $context ): array {
		$response = $this->client->suggest( $stage, $query, $context );
		if ( empty( $response['success'] ) ) {
			return array(
				'success'       => false,
				'error_code'    => (string) ( $response['error_code'] ?? 'dadata_api_failed' ),
				'error_message' => '',
				'items'         => array(),
				'debug'         => $this->debug( $stage, $query, $context, $response ) + array(
					'selected_variant' => 'address_next_relaxed',
					'lower_level_count' => 0,
				),
			);
		}

		$items = $this->normalizer->normalize_many( is_array( $response['suggestions'] ?? null ) ? $response['suggestions'] : array() );
		return array(
			'success' => true,
			'error_code' => '',
			'error_message' => '',
			'items' => $items,
			'debug' => $this->debug( $stage, $query, $context, $response ) + array(
				'selected_variant' => 'address_next_relaxed',
				'lower_level_count' => $this->lower_level_count( $items ),
			),
		);
	}

	/**
	 * @param array<string,string> $context
	 * @return array<string,mixed>
	 */
	private function suggest_address_with_variants( string $stage, string $query, array $context ): array {
		$attempts = AddressLineParser::query_attempts( $query, $context, 5 );
		$first_success = null;
		$last_failure = null;
		$attempt_debug = array();
		$flat = AddressLineParser::flat_context( $query );

		foreach ( $attempts as $attempt ) {
			$response = $this->client->suggest( $stage, $attempt['query'], $attempt['context'] );
			$attempt_debug[] = array(
				'variant' => $attempt['variant'],
				'query' => $attempt['query'],
				'suggestions_count' => count( is_array( $response['suggestions'] ?? null ) ? $response['suggestions'] : array() ),
				'success' => ! empty( $response['success'] ),
			);
			if ( empty( $response['success'] ) ) {
				$last_failure = $response;
				continue;
			}

			$items = $this->normalizer->normalize_many( is_array( $response['suggestions'] ?? null ) ? $this->restore_flat_in_suggestions( $response['suggestions'], $flat['flat'] ) : array() );
			$payload = array(
				'success' => true,
				'error_code' => '',
				'error_message' => '',
				'items' => $items,
				'debug' => $this->debug( $stage, $attempt['query'], $attempt['context'], $response ) + array(
					'query_variants' => $attempt_debug,
					'flat_restored_from_input' => '' !== $flat['flat'] && $this->has_restored_flat( $items, $flat['flat'] ),
					'selected_variant' => $attempt['variant'],
				),
			);
			if ( null === $first_success ) {
				$first_success = $payload;
			}
			if ( $this->has_deliverable_items( $items ) ) {
				return $payload;
			}
		}

		if ( null !== $first_success ) {
			return $first_success;
		}

		$failure = is_array( $last_failure ) ? $last_failure : $this->failure( 'dadata_api_failed', '', 0 );
		return array(
			'success'       => false,
			'error_code'    => (string) ( $failure['error_code'] ?? 'dadata_api_failed' ),
			'error_message' => '',
			'items'         => array(),
			'debug'         => array( 'query_variants' => $attempt_debug ),
		);
	}

	/**
	 * @param array<int,mixed> $suggestions
	 * @return array<int,mixed>
	 */
	private function restore_flat_in_suggestions( array $suggestions, string $flat ): array {
		if ( '' === $flat ) {
			return $suggestions;
		}
		foreach ( $suggestions as $index => $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}
			$data = is_array( $suggestion['data'] ?? null ) ? $suggestion['data'] : array();
			if ( '' === (string) ( $data['flat'] ?? '' ) ) {
				$data['flat'] = $flat;
				$suggestion['data'] = $data;
				$suggestions[ $index ] = $suggestion;
			}
		}

		return $suggestions;
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 */
	private function has_deliverable_items( array $items ): bool {
		foreach ( $items as $item ) {
			if ( ! empty( $item['isDeliverable'] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 */
	private function has_restored_flat( array $items, string $flat ): bool {
		foreach ( $items as $item ) {
			$data = is_array( $item['data'] ?? null ) ? $item['data'] : array();
			if ( $flat === (string) ( $data['flat'] ?? '' ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<int,array<string,mixed>> $items
	 */
	private function lower_level_count( array $items ): int {
		$count = 0;
		foreach ( $items as $item ) {
			if ( in_array( (string) ( $item['level'] ?? '' ), array( 'flat', 'room', 'premise' ), true ) ) {
				$count++;
			}
		}

		return $count;
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
				'house_fias_id' => (string) ( $context['house_fias_id'] ?? '' ),
				'selected_level' => (string) ( $context['selected_level'] ?? '' ),
				'desired_level' => (string) ( $context['desired_level'] ?? '' ),
			),
			'context_keys' => array_values( array_keys( array_filter( $context, static fn( string $value ): bool => '' !== trim( $value ) ) ) ),
			'suggestions_count' => count( is_array( $response['suggestions'] ?? null ) ? $response['suggestions'] : array() ),
			'status_code' => (int) ( $response['status_code'] ?? 0 ),
		);
	}
}
