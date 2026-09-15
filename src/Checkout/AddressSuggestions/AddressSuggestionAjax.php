<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\AddressSuggestions;

use WallsShop\WDC\Checkout\WooCommerce\CheckoutSessionManager;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class AddressSuggestionAjax {
	public const ACTION = 'wdc_platform_dadata_address_suggest';
	public const SELECTION_ACTION = 'wdc_platform_dadata_suggestion_selected';
	public const NONCE_ACTION = 'wdc_platform_dadata_address_suggest';

	public function __construct(
		private AddressSuggestionService $service,
		private ?DaDataTokenPool $token_pool = null,
		private ?CheckoutSessionManager $session_manager = null,
		private ?LocationRepository $locations = null
	) {
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_' . self::SELECTION_ACTION, array( $this, 'handle_selection' ) );
		add_action( 'wp_ajax_nopriv_' . self::SELECTION_ACTION, array( $this, 'handle_selection' ) );
	}

	public function handle(): void {
		if ( ! $this->valid_nonce() ) {
			return;
		}
		$query = $this->field( 'query' );
		if ( mb_strlen( $query ) < 3 || mb_strlen( $query ) > 250 ) {
			$this->send( array( 'success' => true, 'items' => array() ) );
			return;
		}
		$context = $this->context();
		$eligible = $this->canonical_checkout_context( $context );
		if ( null === $eligible ) {
			$this->send(
				array(
					'success'       => true,
					'error_code'    => 'address_autocomplete_ineligible',
					'error_message' => '',
					'items'         => array(),
					'debug'         => array( 'eligibility' => 'ineligible' ),
				)
			);
			return;
		}

		$context = $eligible;
		$payload = $this->service->suggest( 'address_inline', $query, $context );
		if ( ! empty( $payload['success'] ) && is_array( $payload['items'] ?? null ) ) {
			$payload['items'] = array_slice( array_values( array_filter( $payload['items'], static function ( array $item ) use ( $context ): bool {
				$data = $item['data'] ?? array();
				$locality = trim( (string) ( $data['settlement_fias_id'] ?? '' ) );
				$locality = '' !== $locality ? $locality : trim( (string) ( $data['city_fias_id'] ?? '' ) );
				return '' !== trim( (string) ( $item['input_value'] ?? '' ) ) && $locality === $context['location_fias_id'];
			} ) ), 0, 8 );
		}
		if ( ! empty( $payload['success'] ) && is_array( $payload['items'] ?? null ) && $this->session_manager instanceof CheckoutSessionManager ) {
			$payload['items'] = $this->session_manager->cache_dadata_address_suggestions( 'billing', $context, $payload['items'] );
		}

		$this->send( $payload );
	}

	public function handle_selection(): void {
		if ( ! $this->valid_nonce() ) {
			return;
		}
		$token_id = $this->token_pool instanceof DaDataTokenPool ? $this->token_pool->last_used_token_id() : '';
		$usage_type = $this->selection_usage_type();
		$stage = 'final_selection' === $usage_type ? 'final_selection' : 'selection';
		$counted = false;
		$trusted = false;
		if ( '' !== $token_id && $this->token_pool instanceof DaDataTokenPool ) {
			$this->token_pool->increment_usage( $token_id );
			$this->token_pool->record_request_attempt( $token_id, $stage, $this->field( 'level' ), false, true, 'selection', $usage_type );
			$counted = true;
		}
		if ( 'final_selection' === $usage_type && $this->session_manager instanceof CheckoutSessionManager ) {
			$trusted = $this->session_manager->confirm_dadata_address_evidence( $this->field( 'selection_token' ), $this->field( 'prefix' ) );
		}

		$payload = array(
			'success' => true,
			'counted' => $counted,
			'usage_type' => $usage_type,
			'trusted' => $trusted,
		);

		$this->send( $payload );
	}

	private function selection_usage_type(): string {
		$type = $this->field( 'usage_type' );
		return 'final_selection' === $type ? 'final_selection' : 'suggestion_click';
	}

	private function field( string $key ): string {
		$value = $_POST[ $key ] ?? $_REQUEST[ $key ] ?? '';
		$value = is_array( $value ) ? '' : (string) $value;
		$value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( (string) $value ) : trim( strip_tags( (string) $value ) );
	}

	/**
	 * @return array<string,string>
	 */
	private function context(): array {
		$raw = $_POST['context'] ?? $_REQUEST['context'] ?? array();
		if ( is_string( $raw ) ) {
			$decoded = json_decode( function_exists( 'wp_unslash' ) ? wp_unslash( $raw ) : $raw, true );
			$raw = is_array( $decoded ) ? $decoded : array();
		}
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		$context = array();
		foreach ( array( 'country_code', 'selected_source', 'selected_location_id', 'selected_location_fias_id' ) as $key ) {
			$value = $raw[ $key ] ?? '';
			$value = is_array( $value ) ? '' : (string) $value;
			$context[ $key ] = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( strip_tags( $value ) );
		}

		return $context;
	}

	private function valid_nonce(): bool {
		if ( function_exists( 'wp_verify_nonce' ) && wp_verify_nonce( $this->field( 'nonce' ), self::NONCE_ACTION ) ) {
			return true;
		}
		$this->send( array( 'success' => false, 'error_code' => 'invalid_nonce', 'items' => array() ) );
		return false;
	}

	/**
	 * @param array<string,string> $context
	 * @return array<string,string>|null
	 */
	private function canonical_checkout_context( array $context ): ?array {
		$country_code = strtoupper( trim( (string) ( $context['country_code'] ?? '' ) ) );
		if ( 'RU' !== $country_code ) {
			return null;
		}
		if ( 'manual' === strtolower( trim( (string) ( $context['selected_source'] ?? '' ) ) ) ) {
			return null;
		}
		$location_id = max( 0, (int) ( $context['selected_location_id'] ?? 0 ) );
		$fias_id = trim( (string) ( $context['selected_location_fias_id'] ?? '' ) );
		if ( 0 >= $location_id && '' === $fias_id ) {
			return null;
		}

		if ( ! $this->locations instanceof LocationRepository ) {
			return null;
		}
		$location = $location_id > 0 ? $this->locations->find_by_id( $location_id ) : $this->locations->find_by_fias_id( $fias_id );
		if ( ! $location instanceof Location || ! $location->active || 'RU' !== $location->country_code || '' === trim( $location->fias_id ) ) {
			return null;
		}
		if ( '' !== $fias_id && $fias_id !== $location->fias_id ) {
			return null;
		}
		return array(
			'country_code' => 'RU',
			'selected_location_id' => (string) $location->id,
			'selected_location_fias_id' => $location->fias_id,
			'location_fias_id' => $location->fias_id,
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	private function send( array $payload ): void {
		if ( function_exists( 'wp_send_json' ) ) {
			wp_send_json( $payload );
			return;
		}

		echo function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) : json_encode( $payload, JSON_UNESCAPED_UNICODE );
	}
}
