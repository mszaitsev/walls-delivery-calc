<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\AddressSuggestions;

defined( 'ABSPATH' ) || exit;

final class AddressSuggestionAjax {
	public const ACTION = 'wdc_platform_dadata_address_suggest';
	public const SELECTION_ACTION = 'wdc_platform_dadata_suggestion_selected';
	public const NONCE_ACTION = 'wdc_platform_dadata_address_suggest';

	public function __construct(
		private AddressSuggestionService $service,
		private ?DaDataTokenPool $token_pool = null
	) {
	}

	public function register(): void {
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'wp_ajax_' . self::SELECTION_ACTION, array( $this, 'handle_selection' ) );
		add_action( 'wp_ajax_nopriv_' . self::SELECTION_ACTION, array( $this, 'handle_selection' ) );
	}

	public function handle(): void {
		$stage = $this->field( 'stage' );
		$query = $this->field( 'query' );
		$context = $this->context();
		$payload = $this->service->suggest( $stage, $query, $context );

		if ( function_exists( 'wp_send_json' ) ) {
			wp_send_json( $payload );
			return;
		}

		echo function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) : json_encode( $payload, JSON_UNESCAPED_UNICODE );
	}

	public function handle_selection(): void {
		$token_id = $this->token_pool instanceof DaDataTokenPool ? $this->token_pool->last_used_token_id() : '';
		$usage_type = $this->selection_usage_type();
		$stage = 'final_selection' === $usage_type ? 'final_selection' : 'selection';
		$counted = false;
		if ( '' !== $token_id && $this->token_pool instanceof DaDataTokenPool ) {
			$this->token_pool->increment_usage( $token_id );
			$this->token_pool->record_request_attempt( $token_id, $stage, $this->field( 'level' ), false, true, 'selection', $usage_type );
			$counted = true;
		}

		$payload = array(
			'success' => true,
			'counted' => $counted,
			'usage_type' => $usage_type,
		);

		if ( function_exists( 'wp_send_json' ) ) {
			wp_send_json( $payload );
			return;
		}

		echo function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ) : json_encode( $payload, JSON_UNESCAPED_UNICODE );
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
		foreach ( array( 'city_kladr_id', 'city_fias_id', 'settlement_kladr_id', 'settlement_fias_id', 'street_fias_id', 'house_fias_id', 'house_kladr_id', 'selected_level', 'desired_level', 'selected_display_name', 'city' ) as $key ) {
			$value = $raw[ $key ] ?? '';
			$value = is_array( $value ) ? '' : (string) $value;
			$context[ $key ] = function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( strip_tags( $value ) );
		}

		return $context;
	}
}
