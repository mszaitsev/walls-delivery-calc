<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

return static function (): void {
	global $wpdb;

	$services_table = $wpdb->prefix . 'wdc_delivery_services';
	$settings_table = $wpdb->prefix . 'wdc_delivery_service_settings';
	$countries_table = $wpdb->prefix . 'wdc_delivery_service_countries';

	$table_exists = static fn ( string $table ): bool => (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	if ( ! $table_exists( $services_table ) || ! $table_exists( $settings_table ) ) {
		return;
	}

	$now = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	$new_key = 'russian_post_domestic';
	$old_pickup_key = 'russian_post_domestic_pickup';
	$old_courier_key = 'russian_post_domestic_courier';

	$get_service = static function ( string $service_key ) use ( $wpdb, $services_table ): ?array {
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$services_table} WHERE service_key = %s ORDER BY deleted ASC, id ASC LIMIT 1", $service_key ), ARRAY_A );
		return is_array( $row ) ? $row : null;
	};

	$pickup = $get_service( $old_pickup_key );
	$courier = $get_service( $old_courier_key );
	$existing = $get_service( $new_key );
	$template = is_array( $pickup ) ? $pickup : ( is_array( $courier ) ? $courier : array() );

	if ( ! is_array( $existing ) ) {
		$wpdb->insert(
			$services_table,
			array(
				'service_key' => $new_key,
				'carrier_key' => $new_key,
				'service_type' => 'parcel',
				'title' => 'Почта России по РФ',
				'enabled' => (int) ( $template['enabled'] ?? 1 ),
				'availability_mode' => (string) ( $template['availability_mode'] ?? 'selected_countries' ),
				'use_default_rules_when_no_service_rules' => (int) ( $template['use_default_rules_when_no_service_rules'] ?? 1 ),
				'round_up_to_ruble' => (int) ( $template['round_up_to_ruble'] ?? 1 ),
				'minimum_price_rub' => (string) ( $template['minimum_price_rub'] ?? '1.0000' ),
				'include_packaging_weight' => (int) ( $template['include_packaging_weight'] ?? 1 ),
				'packaging_weight_mode' => (string) ( $template['packaging_weight_mode'] ?? 'total_weight' ),
				'pickup_customer_comment' => 'Доставка до почтового отделения по индексу',
				'courier_customer_comment' => (string) ( $template['courier_customer_comment'] ?? '' ),
				'sort_order' => 20,
				'deleted' => 0,
				'created_at' => $now,
				'updated_at' => $now,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' )
		);
		$existing = $get_service( $new_key );
	} else {
		$wpdb->update(
			$services_table,
			array(
				'carrier_key' => $new_key,
				'title' => 'Почта России по РФ',
				'enabled' => 1,
				'deleted' => 0,
				'updated_at' => $now,
			),
			array( 'id' => (int) $existing['id'] ),
			array( '%s', '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	if ( ! is_array( $existing ) || empty( $existing['id'] ) ) {
		return;
	}

	$new_service_id = (int) $existing['id'];
	$read_settings = static function ( ?array $service ) use ( $wpdb, $settings_table ): array {
		if ( ! is_array( $service ) || empty( $service['id'] ) ) {
			return array();
		}
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT setting_key, setting_value, value_format FROM {$settings_table} WHERE service_id = %d", (int) $service['id'] ), ARRAY_A );
		$result = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$key = (string) ( $row['setting_key'] ?? '' );
			if ( '' === $key ) {
				continue;
			}
			$format = (string) ( $row['value_format'] ?? 'json' );
			$value = (string) ( $row['setting_value'] ?? '' );
			$result[ $key ] = 'json' === $format ? json_decode( $value, true ) : $value;
		}
		return $result;
	};
	$service_settings = array_merge( $read_settings( $pickup ), $read_settings( $courier ), $read_settings( $existing ) );

	$core_settings = get_option( 'wdc_core_settings', array() );
	$core_settings = is_array( $core_settings ) ? $core_settings : array();
	foreach ( array(
		'russian_post_otpravka_access_token',
		'russian_post_otpravka_login',
		'russian_post_otpravka_password_encrypted',
		'russian_post_otpravka_timeout',
		'russian_post_otpravka_postoffice_codes',
		'russian_post_otpravka_pickup_unload_type',
		'russian_post_pickup_import_schedule_enabled',
		'russian_post_pickup_import_last_result',
		'russian_post_pickup_import_last_success_at',
		'russian_post_tracking_login',
		'russian_post_tracking_password_encrypted',
	) as $key ) {
		if ( array_key_exists( $key, $core_settings ) && ! array_key_exists( $key, $service_settings ) ) {
			$service_settings[ $key ] = $core_settings[ $key ];
		}
	}

	foreach ( array( 'ops', 'pvz', 'aps' ) as $type ) {
		foreach ( array( 'enabled', 'label' ) as $field ) {
			$old = 'russian_post_point_type_' . $type . '_' . $field;
			$new = 'russian_post_domestic_point_type_' . $type . '_' . $field;
			if ( array_key_exists( $old, $service_settings ) && ! array_key_exists( $new, $service_settings ) ) {
				$service_settings[ $new ] = $service_settings[ $old ];
			}
		}
	}

	$tariffs = array();
	foreach ( array( $read_settings( $pickup ), $read_settings( $courier ), $read_settings( $existing ) ) as $settings ) {
		$variants = is_array( $settings['tariff_variants'] ?? null ) ? $settings['tariff_variants'] : array();
		foreach ( $variants as $variant ) {
			if ( ! is_array( $variant ) || empty( $variant['object_code'] ) ) {
				continue;
			}
			$delivery_type = (string) ( $variant['delivery_type'] ?? '' );
			$key = $delivery_type . ':' . (string) $variant['object_code'];
			$tariffs[ $key ] = $variant;
		}
	}
	if ( array() !== $tariffs ) {
		$service_settings['tariff_variants'] = array_values( $tariffs );
	}

	$format_for = static function ( string $key ): string {
		if ( str_ends_with( $key, '_enabled' ) || in_array( $key, array( 'send_goods_items', 'combine_goods_items_default' ), true ) ) {
			return 'bool';
		}
		if ( in_array( $key, array( 'rp_timeout', 'rp_vat_rate', 'shelf_life_days_default', 'status_polling_frequency_minutes', 'russian_post_otpravka_timeout' ), true ) ) {
			return 'int';
		}
		if ( in_array( $key, array( 'tariff_variants', 'status_mapping_json', 'status_auto_sync_wc_statuses' ), true ) ) {
			return 'json';
		}
		return 'string';
	};
	$encode = static function ( mixed $value, string $format ): string {
		if ( 'json' === $format ) {
			$encoded = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
			return is_string( $encoded ) ? $encoded : 'null';
		}
		return (string) $value;
	};
	foreach ( $service_settings as $key => $value ) {
		$key = (string) $key;
		if ( '' === $key ) {
			continue;
		}
		$format = $format_for( $key );
		$wpdb->replace(
			$settings_table,
			array(
				'service_id' => $new_service_id,
				'setting_key' => $key,
				'setting_value' => $encode( $value, $format ),
				'value_format' => $format,
				'autoload' => 0,
				'updated_at' => $now,
			),
			array( '%d', '%s', '%s', '%s', '%d', '%s' )
		);
	}

	if ( $table_exists( $countries_table ) ) {
		$country_rows = array();
		foreach ( array( $pickup, $courier ) as $service ) {
			if ( ! is_array( $service ) || empty( $service['id'] ) ) {
				continue;
			}
			$rows = $wpdb->get_col( $wpdb->prepare( "SELECT country_code FROM {$countries_table} WHERE service_id = %d", (int) $service['id'] ) );
			foreach ( is_array( $rows ) ? $rows : array() as $country ) {
				$country_rows[ strtoupper( (string) $country ) ] = true;
			}
		}
		$country_rows['RU'] = true;
		foreach ( array_keys( $country_rows ) as $country ) {
			$wpdb->replace(
				$countries_table,
				array(
					'service_id' => $new_service_id,
					'country_code' => substr( $country, 0, 2 ),
					'created_at' => $now,
				),
				array( '%d', '%s', '%s' )
			);
		}
	}

	foreach ( array( $old_pickup_key, $old_courier_key ) as $old_key ) {
		$wpdb->update(
			$services_table,
			array( 'enabled' => 0, 'deleted' => 1, 'updated_at' => $now ),
			array( 'service_key' => $old_key ),
			array( '%d', '%d', '%s' ),
			array( '%s' )
		);
	}
};
