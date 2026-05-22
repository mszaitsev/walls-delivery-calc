<?php
declare(strict_types=1);

namespace WallsShop\WDC\Checkout\AddressSuggestions;

use WallsShop\WDC\Infrastructure\Security\EncryptionService;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class DaDataTokenPool {
	public const OPTION_KEY = 'dadata_suggestions_tokens';
	private const DEFAULT_DAILY_LIMIT = 10000;

	public function __construct(
		private SettingsRepository $settings,
		private EncryptionService $encryption
	) {
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function tokens(): array {
		$tokens = $this->settings->get_array( self::OPTION_KEY, array() );
		return array_values( array_filter( $tokens, 'is_array' ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function active_tokens(): array {
		return array_values(
			array_filter(
				$this->tokens(),
				fn( array $token ): bool => ! empty( $token['enabled'] ) && '' !== (string) ( $token['encrypted_token'] ?? '' )
			)
		);
	}

	public function total_tokens_count(): int {
		return count( $this->tokens() );
	}

	public function available_tokens_count(): int {
		return count(
			array_filter(
				$this->active_tokens(),
				fn( array $token ): bool => $this->remaining_today( $token ) > 0
			)
		);
	}

	public function has_any_configured_token(): bool {
		return 0 < count( $this->active_tokens() );
	}

	public function has_available_token(): bool {
		return 0 < $this->available_tokens_count();
	}

	/**
	 * @return array<string,mixed>|null
	 */
	public function next_available_token(): ?array {
		if ( ! $this->encryption->has_configured_key() ) {
			return null;
		}

		foreach ( $this->active_tokens() as $token ) {
			if ( $this->remaining_today( $token ) <= 0 ) {
				continue;
			}

			$plain = $this->encryption->decrypt( (string) ( $token['encrypted_token'] ?? '' ) );
			if ( ! is_string( $plain ) || '' === trim( $plain ) ) {
				continue;
			}

			$token['token'] = $plain;
			return $token;
		}

		return null;
	}

	public function usage_today( string $token_id ): int {
		$key = $this->usage_key( $token_id );
		$value = function_exists( 'get_transient' ) ? get_transient( $key ) : get_option( $key, 0 );
		return is_numeric( $value ) ? max( 0, (int) $value ) : 0;
	}

	/**
	 * @param array<string,mixed> $token
	 */
	public function remaining_today( array $token ): int {
		if ( $this->is_exhausted_today( (string) ( $token['id'] ?? '' ) ) ) {
			return 0;
		}

		$limit = $this->daily_limit( $token['daily_limit'] ?? self::DEFAULT_DAILY_LIMIT );
		return max( 0, $limit - $this->usage_today( (string) ( $token['id'] ?? '' ) ) );
	}

	public function increment_usage( string $token_id ): void {
		$this->set_usage( $token_id, $this->usage_today( $token_id ) + 1 );
	}

	/**
	 * @param array<string,mixed> $token
	 */
	public function mark_exhausted( array $token ): void {
		$token_id = (string) ( $token['id'] ?? '' );
		if ( '' === $token_id ) {
			return;
		}

		$this->set_daily_value( $this->exhausted_key( $token_id ), 1 );
	}

	public function is_exhausted_today( string $token_id ): bool {
		if ( '' === $token_id ) {
			return false;
		}

		return (bool) $this->get_daily_value( $this->exhausted_key( $token_id ), 0 );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function last_request_today( string $token_id ): array {
		$value = $this->get_daily_value( $this->last_request_key( $token_id ), array() );
		return is_array( $value ) ? $value : array();
	}

	public function record_request_attempt( string $token_id, string $stage, string $query, bool $attempted, bool $counted, int $status_code, string $error_code ): void {
		if ( '' === $token_id ) {
			return;
		}

		$this->set_daily_value(
			$this->last_request_key( $token_id ),
			array(
				'token_id' => $token_id,
				'stage' => $this->sanitize_key( $stage ),
				'query_preview' => $this->safe_query_preview( $query ),
				'query_hash' => hash( 'sha256', $query ),
				'http_attempted' => $attempted,
				'counted' => $counted,
				'status_code' => $status_code,
				'error_code' => $this->sanitize_key( $error_code ),
				'time' => $this->now(),
			)
		);
	}

	/**
	 * @param array<string,mixed> $raw
	 */
	public function save_tokens_from_admin( array $raw ): bool {
		if ( ! $this->encryption->has_configured_key() ) {
			return false;
		}

		$existing = array();
		foreach ( $this->tokens() as $token ) {
			$existing[ (string) ( $token['id'] ?? '' ) ] = $token;
		}

		$saved = array();
		$ids = is_array( $raw['id'] ?? null ) ? $raw['id'] : array();
		foreach ( $ids as $index => $raw_id ) {
			$id = $this->sanitize_key( (string) $raw_id );
			$old = $existing[ $id ] ?? array();
			if ( '' === $id ) {
				$id = $this->new_id();
			}
			if ( ! empty( $raw['delete'][ $index ] ) ) {
				continue;
			}

			$plain = trim( $this->unslash( (string) ( $raw['token'][ $index ] ?? '' ) ) );
			$encrypted = (string) ( $old['encrypted_token'] ?? '' );
			$masked = (string) ( $old['masked_token'] ?? '' );
			if ( '' !== $plain ) {
				$encrypted = $this->encryption->encrypt( $plain );
				$masked = $this->mask( $plain );
			}
			if ( '' === $encrypted ) {
				continue;
			}

			$now = $this->now();
			$saved[] = array(
				'id'              => $id,
				'encrypted_token' => $encrypted,
				'masked_token'    => '' !== $masked ? $masked : '********',
				'daily_limit'     => $this->daily_limit( $raw['daily_limit'][ $index ] ?? self::DEFAULT_DAILY_LIMIT ),
				'enabled'         => ! empty( $raw['enabled'][ $index ] ),
				'label'           => $this->sanitize_text( $this->unslash( (string) ( $raw['label'][ $index ] ?? '' ) ) ),
				'created_at'      => (string) ( $old['created_at'] ?? $now ),
				'updated_at'      => $now,
			);
		}

		$settings = $this->settings->all();
		$settings[ self::OPTION_KEY ] = $saved;
		$this->settings->replace( $settings );
		return true;
	}

	private function set_usage( string $token_id, int $usage ): void {
		if ( '' === $token_id ) {
			return;
		}

		$this->set_daily_value( $this->usage_key( $token_id ), max( 0, $usage ) );
	}

	private function set_daily_value( string $key, mixed $value ): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $key, $value, $this->usage_ttl() );
			return;
		}

		update_option( $key, $value, false );
	}

	private function get_daily_value( string $key, mixed $default ): mixed {
		return function_exists( 'get_transient' ) ? get_transient( $key ) : get_option( $key, $default );
	}

	private function usage_key( string $token_id ): string {
		return 'wdc_dadata_suggestions_usage_' . $this->sanitize_key( $token_id ) . '_' . $this->today();
	}

	private function exhausted_key( string $token_id ): string {
		return 'wdc_dadata_suggestions_exhausted_' . $this->sanitize_key( $token_id ) . '_' . $this->today();
	}

	private function last_request_key( string $token_id ): string {
		return 'wdc_dadata_suggestions_last_request_' . $this->sanitize_key( $token_id ) . '_' . $this->today();
	}

	private function today(): string {
		return function_exists( 'wp_date' ) ? wp_date( 'Ymd' ) : gmdate( 'Ymd' );
	}

	private function usage_ttl(): int {
		$now = function_exists( 'current_time' ) ? strtotime( current_time( 'mysql' ) ) : time();
		$end = strtotime( gmdate( 'Y-m-d 23:59:59', (int) $now ) );
		return max( 3600, (int) $end - (int) $now + 3600 );
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function daily_limit( mixed $value ): int {
		$limit = is_numeric( $value ) ? (int) $value : self::DEFAULT_DAILY_LIMIT;
		return max( 1, min( 1000000, $limit > 0 ? $limit : self::DEFAULT_DAILY_LIMIT ) );
	}

	private function mask( string $plain ): string {
		$plain = trim( $plain );
		if ( strlen( $plain ) <= 4 ) {
			return '********';
		}

		return '********' . substr( $plain, -4 );
	}

	private function new_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}

		return 'token-' . bin2hex( random_bytes( 8 ) );
	}

	private function sanitize_key( string $value ): string {
		return function_exists( 'sanitize_key' ) ? sanitize_key( $value ) : strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', $value ) ?? '' );
	}

	private function sanitize_text( string $value ): string {
		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( strip_tags( $value ) );
	}

	private function unslash( string $value ): string {
		return function_exists( 'wp_unslash' ) ? (string) wp_unslash( $value ) : $value;
	}

	private function safe_query_preview( string $query ): string {
		$clean = preg_replace( '/\s+/', ' ', trim( $query ) ) ?? '';
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $clean, 0, 40 );
		}

		return substr( $clean, 0, 40 );
	}
}
