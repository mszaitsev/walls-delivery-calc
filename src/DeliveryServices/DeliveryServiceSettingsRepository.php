<?php
declare(strict_types=1);

namespace WallsShop\WDC\DeliveryServices;

defined( 'ABSPATH' ) || exit;

final class DeliveryServiceSettingsRepository {
	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	public function get_setting( int $service_id, string $key, mixed $default = null ): mixed {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE service_id = %d AND setting_key = %s LIMIT 1", $service_id, $key ),
			ARRAY_A
		);
		if ( ! is_array( $row ) ) {
			return $default;
		}

		return $this->decode_value( (string) ( $row['setting_value'] ?? '' ), (string) ( $row['value_format'] ?? 'json' ) );
	}

	public function set_setting( int $service_id, string $key, mixed $value, string $format = 'json', bool $autoload = false ): void {
		$format = in_array( $format, array( 'json', 'string', 'number', 'bool' ), true ) ? $format : 'json';
		$row = array(
			'service_id' => $service_id,
			'setting_key' => $key,
			'setting_value' => $this->encode_value( $value, $format ),
			'value_format' => $format,
			'autoload' => $autoload ? 1 : 0,
			'updated_at' => current_time( 'mysql' ),
		);
		$existing = $this->wpdb->get_var(
			$this->wpdb->prepare( "SELECT id FROM {$this->table()} WHERE service_id = %d AND setting_key = %s LIMIT 1", $service_id, $key )
		);

		if ( null !== $existing ) {
			$this->wpdb->update( $this->table(), $row, array( 'id' => (int) $existing ), array( '%d', '%s', '%s', '%s', '%d', '%s' ), array( '%d' ) );
			return;
		}

		$this->wpdb->insert( $this->table(), $row, array( '%d', '%s', '%s', '%s', '%d', '%s' ) );
	}

	public function delete_setting( int $service_id, string $key ): void {
		$this->wpdb->delete( $this->table(), array( 'service_id' => $service_id, 'setting_key' => $key ), array( '%d', '%s' ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	public function all_settings( int $service_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$this->table()} WHERE service_id = %d ORDER BY setting_key ASC", $service_id ),
			ARRAY_A
		);
		$settings = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$settings[ (string) $row['setting_key'] ] = $this->decode_value( (string) ( $row['setting_value'] ?? '' ), (string) ( $row['value_format'] ?? 'json' ) );
		}

		return $settings;
	}

	private function encode_value( mixed $value, string $format ): string {
		return match ( $format ) {
			'string' => (string) $value,
			'number' => (string) (float) str_replace( ',', '.', (string) $value ),
			'bool' => ! empty( $value ) ? '1' : '0',
			default => (string) ( wp_json_encode( $value ) ?: 'null' ),
		};
	}

	private function decode_value( string $value, string $format ): mixed {
		return match ( $format ) {
			'string' => $value,
			'number' => (float) $value,
			'bool' => '1' === $value,
			default => json_decode( $value, true ),
		};
	}

	private function table(): string {
		return $this->wpdb->prefix . 'wdc_delivery_service_settings';
	}
}
