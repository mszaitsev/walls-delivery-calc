<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Rules\Admin\RuleConditionUiSchema;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleCondition;
use WallsShop\WDC\Rules\Domain\RuleEvaluationContext;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\Services\RuleSimulator;
use WallsShop\WDC\Rules\Admin\RuleAdminContext;
use WallsShop\WDC\Rules\Storage\RuleRepository;
use WallsShop\WDC\Rules\Admin\RulesAdminPage;
use WallsShop\WDC\Rules\ValueObjects\RuleActionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleConditionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationBases;
use WallsShop\WDC\Rules\ValueObjects\RuleOperationTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperators;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
defined( 'ARRAY_A' ) || define( 'ARRAY_A', 'ARRAY_A' );

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type ): string {
		return '2026-05-21 12:00:00';
	}
}

if ( ! function_exists( '__' ) ) {
	function __( string $text, string $domain = '' ): string {
		return $text;
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( string $value ): string {
		return strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $value ) ?? '' );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}
}

if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( string $value ): string {
		return trim( strip_tags( $value ) );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( mixed $value ): mixed {
		return $value;
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( mixed $value ): int {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false {
		return json_encode( $value, $flags );
	}
}

if ( ! class_exists( 'wpdb' ) ) {
	class wpdb {
		public string $prefix = 'wp_';
		public int $insert_id = 0;
		/** @var array<int,array<string,mixed>> */
		public array $rules = array();
		/** @var array<int,array<string,mixed>> */
		public array $conditions = array();
		private int $condition_insert_id = 0;

		public function insert( string $table, array $data, array $format = array() ): bool {
			if ( str_contains( $table, 'wdc_rule_conditions' ) ) {
				++$this->condition_insert_id;
				$data['id'] = $this->condition_insert_id;
				$this->conditions[] = $data;
				return true;
			}

			++$this->insert_id;
			$data['id'] = $this->insert_id;
			$this->rules[] = $data;
			return true;
		}

		public function update( string $table, array $data, array $where, array $format = array(), array $where_format = array() ): bool {
			if ( ! str_contains( $table, 'wdc_rules' ) ) {
				return true;
			}

			foreach ( $this->rules as $index => $row ) {
				$matches = true;
				foreach ( $where as $key => $value ) {
					$matches = $matches && (string) ( $row[ $key ] ?? '' ) === (string) $value;
				}
				if ( $matches ) {
					$this->rules[ $index ] = array_merge( $row, $data, array( 'id' => $row['id'] ) );
				}
			}

			return true;
		}

		public function delete( string $table, array $where, array $format = array() ): bool {
			if ( str_contains( $table, 'wdc_rule_conditions' ) ) {
				$this->conditions = array_values(
					array_filter(
						$this->conditions,
						static fn ( array $row ): bool => (int) $row['rule_id'] !== (int) ( $where['rule_id'] ?? 0 )
					)
				);
				return true;
			}

			$id = (int) ( $where['id'] ?? 0 );
			$this->rules = array_values( array_filter( $this->rules, static fn ( array $row ): bool => (int) $row['id'] !== $id ) );
			$this->conditions = array_values( array_filter( $this->conditions, static fn ( array $row ): bool => (int) $row['rule_id'] !== $id ) );
			return true;
		}

		public function get_row( string $query, mixed $output = null ): ?array {
			preg_match( '/WHERE id = ([0-9]+)/', $query, $matches );
			$id = (int) ( $matches[1] ?? 0 );
			foreach ( $this->rules as $row ) {
				if ( (int) $row['id'] === $id ) {
					return $row;
				}
			}

			return null;
		}

		public function get_results( string $query, mixed $output = null ): array {
			if ( str_contains( $query, 'wdc_rule_conditions' ) ) {
				preg_match( '/rule_id = ([0-9]+)/', $query, $matches );
				$rule_id = (int) ( $matches[1] ?? 0 );
				$rows = array_values( array_filter( $this->conditions, static fn ( array $row ): bool => (int) $row['rule_id'] === $rule_id ) );
				usort( $rows, static fn ( array $a, array $b ): int => ( (int) $a['condition_group'] <=> (int) $b['condition_group'] ) ?: ( (int) $a['id'] <=> (int) $b['id'] ) );
				return $rows;
			}

			$rows = $this->rules;
			if ( str_contains( $query, 'enabled = 1' ) ) {
				$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (int) $row['enabled'] === 1 ) );
			}
			if ( preg_match( "/target_type = '([^']*)'/", $query, $matches ) ) {
				$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (string) $row['target_type'] === $matches[1] ) );
			}
			if ( preg_match( "/target_value = '([^']*)'/", $query, $matches ) ) {
				$rows = array_values( array_filter( $rows, static fn ( array $row ): bool => (string) $row['target_value'] === $matches[1] ) );
			}

			usort(
				$rows,
				static function ( array $a, array $b ) use ( $query ): int {
					if ( str_contains( $query, 'enabled DESC' ) ) {
						$enabled = (int) $b['enabled'] <=> (int) $a['enabled'];
						if ( 0 !== $enabled ) {
							return $enabled;
						}
					}

					$promo = (int) $a['promo_shipping'] <=> (int) $b['promo_shipping'];
					if ( 0 !== $promo && str_contains( $query, 'promo_shipping ASC' ) ) {
						return $promo;
					}

					return ( (int) $a['priority'] <=> (int) $b['priority'] ) ?: ( (int) $a['id'] <=> (int) $b['id'] );
				}
			);

			return $rows;
		}

		public function prepare( string $query, mixed ...$args ): string {
			foreach ( $args as $arg ) {
				$value = is_int( $arg ) || is_float( $arg ) ? (string) $arg : "'" . str_replace( "'", "''", (string) $arg ) . "'";
				$query = preg_replace( '/%[sdf]/', $value, $query, 1 ) ?? $query;
			}

			return $query;
		}

		public function query( string $query ): bool {
			if ( str_starts_with( $query, 'UPDATE' ) ) {
				foreach ( $this->rules as $index => $row ) {
					if ( '' === (string) ( $row['target_type'] ?? '' ) ) {
						$this->rules[ $index ]['target_type']  = 'default';
						$this->rules[ $index ]['target_value'] = '';
					}
				}
			}

			if ( str_starts_with( $query, 'DELETE FROM' ) ) {
				if ( str_contains( $query, 'wdc_rule_conditions' ) ) {
					$this->conditions = array();
				} elseif ( str_contains( $query, 'wdc_rules' ) ) {
					$this->rules = array();
				}
			}

			return true;
		}
	}
}

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function rules_admin_smoke_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function rules_admin_rule( string $name, int $priority = 100, bool $enabled = true, string $target_type = 'default', string $target_value = '' ): Rule {
	return new Rule(
		null,
		$name,
		$enabled,
		$priority,
		$target_type,
		$target_value,
		RuleActionTypes::CHANGE_PRICE,
		RuleOperationTypes::DECREASE,
		50,
		RuleOperationBases::RUBLES,
		false,
		false,
		array( new RuleCondition( null, null, 1, RuleConditionTypes::ORDER_TOTAL, RuleOperators::GTE, '', 5000 ) )
	);
}

function rules_admin_context(): RuleEvaluationContext {
	$item = new PackageItem( 'SKU', 'Item', 1, Money::from_rubles( 6000 ), Money::from_rubles( 6000 ), 1000, 10, 10, 10 );

	return new RuleEvaluationContext(
		Money::from_rubles( 6000 ),
		Money::from_rubles( 450 ),
		Package::from_items( array( $item ), 0, Money::from_rubles( 6000 ), Money::from_rubles( 6000 ) ),
		new Address( country_code: 'RU', city: 'Moscow' ),
		'courier',
		'card',
		'2026-05-21'
	);
}

$wpdb = new wpdb();
$repository = new RuleRepository( $wpdb );

$first_default_id = $repository->save_rule( rules_admin_rule( 'Default first', 10, true ) );
$default_id = $repository->save_rule( rules_admin_rule( 'Default enabled', 20, true ) );
$saved = $repository->get_rule( $first_default_id );
rules_admin_smoke_assert( $saved instanceof Rule && 'default' === $saved->target_type && '' === $saved->target_value, 'Default rules must persist current target fields.' );

$disabled_id = $repository->save_rule( rules_admin_rule( 'Default disabled', 30, false ) );
$carrier_id = $repository->save_rule( rules_admin_rule( 'Carrier rule', 5, true, 'carrier', 'demo' ) );

$default_rules = $repository->get_default_rules();
rules_admin_smoke_assert( 2 === count( $default_rules ), 'get_default_rules must return only enabled default rules.' );
rules_admin_smoke_assert( array( $first_default_id, $default_id ) === array_map( static fn ( Rule $rule ): ?int => $rule->id, $default_rules ), 'Default rules must be ordered by table order.' );

$repository->reorder_default_rules( array( $default_id, $first_default_id, $disabled_id ) );
$default_rules = $repository->get_default_rules();
rules_admin_smoke_assert( array( $default_id, $first_default_id ) === array_map( static fn ( Rule $rule ): ?int => $rule->id, $default_rules ), 'Drag-sort ordering must persist for enabled default rules.' );

$all_default_rules = $repository->get_all_default_rules();
rules_admin_smoke_assert( 3 === count( $all_default_rules ), 'get_all_default_rules must include disabled default rules.' );
rules_admin_smoke_assert( $all_default_rules[2]->id === $disabled_id, 'Disabled default rules must be sorted after enabled rules.' );

$carrier_rules = $repository->get_rules_for_target_or_default( 'carrier', 'demo' );
rules_admin_smoke_assert( 1 === count( $carrier_rules ) && $carrier_rules[0]->id === $carrier_id, 'Carrier rules must win over defaults.' );

$fallback_rules = $repository->get_rules_for_carrier_with_default_fallback( 'missing' );
rules_admin_smoke_assert( count( $fallback_rules ) === count( $default_rules ), 'Missing carrier rules must fall back to defaults.' );

$service_id = $repository->save_rule( rules_admin_rule( 'Service enabled', 40, true, RuleRepository::TARGET_SERVICE, 'service_a' ) );
$service_disabled_id = $repository->save_rule( rules_admin_rule( 'Service disabled', 50, false, RuleRepository::TARGET_SERVICE, 'service_a' ) );
$other_service_id = $repository->save_rule( rules_admin_rule( 'Other service', 60, true, RuleRepository::TARGET_SERVICE, 'service_b' ) );
$service_rules = $repository->get_all_rules_for_target( RuleRepository::TARGET_SERVICE, 'service_a' );
rules_admin_smoke_assert( array( $service_id, $service_disabled_id ) === array_map( static fn ( Rule $rule ): ?int => $rule->id, $service_rules ), 'Service rules tab must list only rules for the current service target.' );
$default_target_rules = $repository->get_all_rules_for_target( RuleRepository::TARGET_DEFAULT, '' );
rules_admin_smoke_assert( ! in_array( $service_id, array_map( static fn ( Rule $rule ): ?int => $rule->id, $default_target_rules ), true ), 'Default rules page must not list service rules.' );
$other_priority = $repository->get_rule( $other_service_id )?->priority;
$repository->reorder_rules_for_target( RuleRepository::TARGET_SERVICE, 'service_a', array( $other_service_id, $service_disabled_id, $service_id ) );
rules_admin_smoke_assert( $other_priority === $repository->get_rule( $other_service_id )?->priority, 'Reorder for one service must not affect another service target.' );
rules_admin_smoke_assert( array( $service_disabled_id, $service_id ) === array_map( static fn ( Rule $rule ): ?int => $rule->id, $repository->get_all_rules_for_target( RuleRepository::TARGET_SERVICE, 'service_a' ) ), 'Reorder must apply only to rules belonging to the target.' );
$service_duplicate_data = $repository->get_rule( $service_id )?->to_array() ?? array();
$service_duplicate_data['id'] = null;
$service_duplicate_id = $repository->save_rule( Rule::from_array( $service_duplicate_data ) );
rules_admin_smoke_assert( RuleRepository::TARGET_SERVICE === $repository->get_rule( $service_duplicate_id )?->target_type && 'service_a' === $repository->get_rule( $service_duplicate_id )?->target_value, 'Duplicate must keep the same target.' );

$edited = Rule::from_array(
	array_merge(
		$repository->get_rule( $default_id )?->to_array() ?? array(),
		array(
			'name'       => 'Default edited',
			'conditions' => array(
				new RuleCondition( null, null, 1, RuleConditionTypes::COUNTRY, RuleOperators::EQ, 'RU' ),
			),
		)
	)
);
$repository->save_rule( $edited );
$edited_loaded = $repository->get_rule( $default_id );
rules_admin_smoke_assert( $edited_loaded instanceof Rule && 'Default edited' === $edited_loaded->name, 'Edit must update rule fields.' );
rules_admin_smoke_assert( 1 === count( $edited_loaded->conditions ) && RuleConditionTypes::COUNTRY === $edited_loaded->conditions[0]->condition_type, 'Edit must replace conditions.' );

$duplicate_data = $edited_loaded->to_array();
$duplicate_data['id'] = null;
$duplicate_data['enabled'] = false;
$duplicate_id = $repository->save_rule( Rule::from_array( $duplicate_data ) );
rules_admin_smoke_assert( false === $repository->get_rule( $duplicate_id )?->enabled, 'Duplicate must be disabled by default for safety.' );

$toggle_data = $repository->get_rule( $disabled_id )?->to_array() ?? array();
$toggle_data['enabled'] = true;
$repository->save_rule( Rule::from_array( $toggle_data ) );
rules_admin_smoke_assert( true === $repository->get_rule( $disabled_id )?->enabled, 'Toggle must change enabled state.' );

$move_a = $repository->get_rule( $default_id );
$move_b = $repository->get_rule( $first_default_id );
rules_admin_smoke_assert( $move_a instanceof Rule && $move_b instanceof Rule, 'Move fixture rules must exist.' );
$a = $move_a->to_array();
$b = $move_b->to_array();
$a['priority'] = $move_b->priority;
$b['priority'] = $move_a->priority;
$repository->save_rule( Rule::from_array( $a ) );
$repository->save_rule( Rule::from_array( $b ) );
rules_admin_smoke_assert( $repository->get_rule( $default_id )?->priority === $move_b->priority, 'Move must swap sort order values.' );

$repository->delete_rule( $duplicate_id );
rules_admin_smoke_assert( null === $repository->get_rule( $duplicate_id ), 'Delete must remove rule.' );

$empty_condition_rule = Rule::from_array(
	array(
		'name'            => 'Empty conditions ignored',
		'target_type'     => 'default',
		'action_type'     => RuleActionTypes::CHANGE_PRICE,
		'operation_type'  => RuleOperationTypes::DECREASE,
		'operation_base'  => RuleOperationBases::RUBLES,
		'conditions'      => array(),
	)
);
rules_admin_smoke_assert( array() === $empty_condition_rule->validate(), 'Empty condition rows should be ignored before validation.' );

$invalid_rule = Rule::from_array(
	array(
		'name'            => 'Invalid',
		'target_type'     => 'default',
		'action_type'     => 'bad',
		'operation_type'  => RuleOperationTypes::DECREASE,
		'operation_base'  => RuleOperationBases::RUBLES,
		'conditions'      => array( new RuleCondition( null, null, 1, 'bad', '' ) ),
	)
);
$invalid_errors = $invalid_rule->validate();
rules_admin_smoke_assert( in_array( 'action_type is invalid', $invalid_errors, true ), 'Invalid action must be rejected.' );
rules_admin_smoke_assert( in_array( 'condition_type is invalid', $invalid_errors, true ), 'Invalid condition type must be rejected.' );
rules_admin_smoke_assert( in_array( 'operator is invalid', $invalid_errors, true ), 'Invalid operator must be rejected.' );

$schema = new RuleConditionUiSchema();
$order_total_condition = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::ORDER_TOTAL, 'operator' => RuleOperators::GTE, 'value_number' => '5000' ) );
rules_admin_smoke_assert( $order_total_condition instanceof RuleCondition && 5000.0 === $order_total_condition->value_number && '' === $order_total_condition->value_text, 'order_total must store value_number.' );
$items_count_condition = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::ITEMS_COUNT, 'operator' => RuleOperators::EQ, 'value_number' => '3' ) );
rules_admin_smoke_assert( $items_count_condition instanceof RuleCondition && 3.0 === $items_count_condition->value_number, 'items_count must store value_number.' );
$payment_condition = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::PAYMENT_METHOD, 'operator' => RuleOperators::EQ, 'value_text' => 'cod' ) );
rules_admin_smoke_assert( $payment_condition instanceof RuleCondition && 'cod' === $payment_condition->value_text, 'payment_method must store gateway id in value_text.' );
$city_condition = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::CITY, 'operator' => RuleOperators::EQ, 'value_text' => 'fias-nsk', 'value_json' => '{"display_name":"Новосибирская область, г Новосибирск"}' ) );
rules_admin_smoke_assert( $city_condition instanceof RuleCondition && 'fias-nsk' === $city_condition->value_text, 'city must store fias_id in value_text.' );
rules_admin_smoke_assert( 'fias-nsk' === ( $city_condition->value_json['fias_id'] ?? '' ) && 'Новосибирская область, г Новосибирск' === ( $city_condition->value_json['display_name'] ?? '' ), 'city must store display_name/fias_id in value_json.' );
$invalid_group_condition = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::ORDER_TOTAL, 'condition_group' => '7', 'operator' => RuleOperators::GTE, 'value_number' => '5000' ) );
rules_admin_smoke_assert( $invalid_group_condition instanceof RuleCondition && 1 === $invalid_group_condition->condition_group, 'Invalid condition group must be sanitized to 1.' );
$country_condition = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::COUNTRY, 'operator' => RuleOperators::EQ, 'value_text' => 'RU' ) );
rules_admin_smoke_assert( $country_condition instanceof RuleCondition && 'RU' === $country_condition->value_text, 'country must store country code.' );
$delivery_type_condition = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::DELIVERY_TYPE, 'operator' => RuleOperators::EQ, 'value_text' => 'pickup' ) );
rules_admin_smoke_assert( $delivery_type_condition instanceof RuleCondition && 'pickup' === $delivery_type_condition->value_text, 'delivery_type must store pickup/courier.' );
$delivery_price_condition = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::DELIVERY_PRICE, 'operator' => RuleOperators::LTE, 'value_number' => '450.5' ) );
rules_admin_smoke_assert( $delivery_price_condition instanceof RuleCondition && 450.5 === $delivery_price_condition->value_number, 'delivery_price must store rub value_number.' );
$weight_condition = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::WEIGHT, 'operator' => RuleOperators::GT, 'value_number' => '12000' ) );
rules_admin_smoke_assert( $weight_condition instanceof RuleCondition && 12000.0 === $weight_condition->value_number, 'weight must store grams.' );
$dimensions_condition = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::DIMENSIONS, 'operator' => RuleOperators::GTE, 'value_json' => '{"length_cm":"100","height_cm":"10"}' ) );
rules_admin_smoke_assert( $dimensions_condition instanceof RuleCondition && '100' === (string) ( $dimensions_condition->value_json['length_cm'] ?? '' ) && ! isset( $dimensions_condition->value_json['width_cm'] ), 'dimensions must store filled length/width/height in value_json.' );
$volume_condition = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::VOLUME, 'operator' => RuleOperators::GTE, 'value_number' => '0.25' ) );
rules_admin_smoke_assert( $volume_condition instanceof RuleCondition && 0.25 === $volume_condition->value_number, 'volume must store cubic meters in value_number.' );
$day_condition = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::DAY_OF_WEEK, 'operator' => RuleOperators::EQ, 'value_number' => '1' ) );
rules_admin_smoke_assert( $day_condition instanceof RuleCondition && 1.0 === $day_condition->value_number, 'day_of_week must store 1..7 value_number.' );
$month_day_condition = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::DAY_OF_MONTH, 'operator' => RuleOperators::EQ, 'value_number' => '31' ) );
rules_admin_smoke_assert( $month_day_condition instanceof RuleCondition && 31.0 === $month_day_condition->value_number, 'day_of_month must store 1..31 value_number.' );
$month_condition = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::MONTH, 'operator' => RuleOperators::EQ, 'value_number' => '12' ) );
rules_admin_smoke_assert( $month_condition instanceof RuleCondition && 12.0 === $month_condition->value_number, 'month must store 1..12 value_number.' );
$date_condition = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::DATE, 'operator' => RuleOperators::GTE, 'value_text' => '25.05.2026' ) );
rules_admin_smoke_assert( $date_condition instanceof RuleCondition && '2026-05-25' === $date_condition->value_text, 'date UI dd.mm.yyyy must store YYYY-MM-DD.' );
$normalized_operator = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::PAYMENT_METHOD, 'operator' => RuleOperators::GT, 'value_text' => 'cod' ) );
rules_admin_smoke_assert( $normalized_operator instanceof RuleCondition && RuleOperators::EQ === $normalized_operator->operator, 'Invalid operator must be normalized to condition default.' );
$comma_decimal_condition = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::ORDER_TOTAL, 'operator' => RuleOperators::GTE, 'value_number' => '12,5' ) );
rules_admin_smoke_assert( $comma_decimal_condition instanceof RuleCondition && 12.5 === $comma_decimal_condition->value_number, 'Comma decimal condition values must normalize to dot decimals.' );
$dot_decimal_condition = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::ORDER_TOTAL, 'operator' => RuleOperators::GTE, 'value_number' => '12.5' ) );
rules_admin_smoke_assert( $dot_decimal_condition instanceof RuleCondition && 12.5 === $dot_decimal_condition->value_number, 'Dot decimal condition values must remain valid.' );
$comma_dimensions_condition = $schema->sanitize_condition_input( array( 'condition_type' => RuleConditionTypes::DIMENSIONS, 'operator' => RuleOperators::GTE, 'value_json' => '{}', 'length_cm' => '12,5' ) );
rules_admin_smoke_assert( $comma_dimensions_condition instanceof RuleCondition && 12.5 === $comma_dimensions_condition->value_json['length_cm'], 'Dimensions must accept comma decimals.' );
$empty_type_condition = $schema->sanitize_condition_input( array( 'condition_type' => '', 'operator' => RuleOperators::EQ, 'value_number' => '12' ) );
rules_admin_smoke_assert( null === $empty_type_condition, 'Rows with empty condition_type must be ignored even if operator/value is filled.' );

$simulation_db = new wpdb();
$simulation_repository = new RuleRepository( $simulation_db );
$simulation_repository->save_rule( rules_admin_rule( 'Simulation default', 10, true ) );
$simulation_repository->save_rule( rules_admin_rule( 'Simulation carrier', 1, true, 'carrier', 'demo' ) );
$simulator = new RuleSimulator( new RuleEngine( new RuleEvaluator( new ConditionEvaluator() ) ) );
$simulation = $simulator->simulate( $simulation_repository->get_default_rules(), rules_admin_context() );
rules_admin_smoke_assert( 40000 === $simulation->final_price?->get_kopecks(), 'Simulation must use default rules.' );

$delivery_days_rule = new Rule(
	null,
	'Delivery days',
	true,
	10,
	'default',
	'',
	RuleActionTypes::CHANGE_DELIVERY_DAYS,
	RuleOperationTypes::INCREASE,
	2,
	RuleOperationBases::CALENDAR_DAYS,
	false,
	false
);
rules_admin_smoke_assert( array() === $delivery_days_rule->validate(), 'calendar_days must be valid for change_delivery_days.' );
$delivery_days_result = $simulator->simulate( array( $delivery_days_rule ), RuleEvaluationContext::from_array( array_merge( rules_admin_context()->to_array(), array( 'meta' => array( 'original_delivery_days' => 5 ) ) ) ) );
rules_admin_smoke_assert( 7 === $delivery_days_result->final_delivery_days?->min_days, 'Simulation must change delivery days when a delivery-days rule applies.' );
$business_days_rule = Rule::from_array( array_merge( $delivery_days_rule->to_array(), array( 'operation_base' => RuleOperationBases::BUSINESS_DAYS ) ) );
rules_admin_smoke_assert( array() === $business_days_rule->validate(), 'business_days must be valid for change_delivery_days.' );

$logic_rule_id = $repository->save_rule(
	new Rule(
		null,
		'Logic storage',
		true,
		50,
		'default',
		'',
		RuleActionTypes::CHANGE_PRICE,
		RuleOperationTypes::DECREASE,
		10,
		RuleOperationBases::RUBLES,
		false,
		false,
		array(),
		array( 1 => 'and', 2 => 'or', 3 => 'and' ),
		'',
		'condition_1_and_2_or_3'
	)
);
$logic_rule = $repository->get_rule( $logic_rule_id );
rules_admin_smoke_assert( $logic_rule instanceof Rule && array( 1 => 'and', 2 => 'or', 3 => 'and' ) === $logic_rule->condition_group_logic, 'Rule must store condition_group_logic.' );
rules_admin_smoke_assert( $logic_rule instanceof Rule && 'condition_1_and_2_or_3' === $logic_rule->condition_group_expression, 'Rule must store condition_group_expression.' );
rules_admin_smoke_assert( Rule::DEFAULT_GROUP_EXPRESSION === Rule::from_array( array( 'name' => 'Default expression' ) )->condition_group_expression, 'Default condition_group_expression must preserve default OR behavior.' );
rules_admin_smoke_assert( Rule::DEFAULT_GROUP_EXPRESSION === Rule::normalized_group_expression( 'invalid' ), 'Invalid condition_group_expression must normalize to default.' );

$comment_rule_id = $repository->save_rule(
	new Rule(
		null,
		'Comment storage',
		true,
		60,
		'default',
		'',
		RuleActionTypes::ADD_COMMENT,
		RuleOperationTypes::EQUALS,
		0,
		RuleOperationBases::RUBLES,
		false,
		false,
		array(),
		array( 1 => 'and', 2 => 'and', 3 => 'and' ),
		'Оставить у двери'
	)
);
$comment_rule = $repository->get_rule( $comment_rule_id );
rules_admin_smoke_assert( $comment_rule instanceof Rule && 'Оставить у двери' === $comment_rule->operation_text, 'add_comment must save operation_text.' );
rules_admin_smoke_assert( in_array( 'operation_text is required', ( new Rule( null, 'No comment', true, 10, 'default', '', RuleActionTypes::ADD_COMMENT, RuleOperationTypes::EQUALS, 0, RuleOperationBases::RUBLES, false, false ) )->validate(), true ), 'add_comment must require operation_text.' );

$admin_reflection = new ReflectionClass( RulesAdminPage::class );
$admin_page = $admin_reflection->newInstanceWithoutConstructor();
$operation_summary = $admin_reflection->getMethod( 'operation_summary' );
$operation_summary->setAccessible( true );
rules_admin_smoke_assert( str_contains( $operation_summary->invoke( $admin_page, new Rule( null, 'Increase percent', true, 10, 'default', '', RuleActionTypes::CHANGE_PRICE, RuleOperationTypes::INCREASE, 12.4, RuleOperationBases::PERCENT_OF_ORDER, false, false ) ), 'увеличить на 12.4% от заказа' ), 'Increase summary must contain "увеличить на" and no space before percent.' );
rules_admin_smoke_assert( str_contains( $operation_summary->invoke( $admin_page, new Rule( null, 'Decrease percent', true, 10, 'default', '', RuleActionTypes::CHANGE_PRICE, RuleOperationTypes::DECREASE, 10, RuleOperationBases::PERCENT_OF_DELIVERY, false, false ) ), 'уменьшить на 10% от доставки' ), 'Decrease summary must contain "уменьшить на" and no space before percent.' );
rules_admin_smoke_assert( 'установить 500 руб.' === $operation_summary->invoke( $admin_page, new Rule( null, 'Equals rub', true, 10, 'default', '', RuleActionTypes::CHANGE_PRICE, RuleOperationTypes::EQUALS, 500, RuleOperationBases::RUBLES, false, false ) ), 'Equals summary must not contain extra "на" and rub values must keep spacing.' );
rules_admin_smoke_assert( 'увеличить на 3 календарных дня' === $operation_summary->invoke( $admin_page, new Rule( null, 'Days', true, 10, 'default', '', RuleActionTypes::CHANGE_DELIVERY_DAYS, RuleOperationTypes::INCREASE, 3, RuleOperationBases::CALENDAR_DAYS, false, false ) ), 'Day values must keep normal spacing.' );
rules_admin_smoke_assert( str_contains( $operation_summary->invoke( $admin_page, $comment_rule ), 'Оставить у двери' ), 'Table summary must show comment text.' );

$sanitize_rule = $admin_reflection->getMethod( 'sanitize_rule_from_post' );
$sanitize_rule->setAccessible( true );
$repository_property = $admin_reflection->getProperty( 'repository' );
$repository_property->setAccessible( true );
$repository_property->setValue( $admin_page, new RuleRepository( new wpdb() ) );
$context_property = $admin_reflection->getProperty( 'context' );
$context_property->setAccessible( true );
$context_property->setValue( $admin_page, new RuleAdminContext( RuleRepository::TARGET_SERVICE, 'service_a', 'wdc-delivery-services', 'admin.php?page=wdc-delivery-services&service=service_a&tab=rules', 'Service rules', 'Service rule', 'No service rules.', true ) );
$_POST = array(
	'name'            => 'Equals without conditions',
	'action_type'     => RuleActionTypes::CHANGE_PRICE,
	'operation_type'  => RuleOperationTypes::EQUALS,
	'operation_value' => '12,5',
	'operation_base'  => RuleOperationBases::RUBLES,
	'condition_group_expression' => 'condition_1_and_2',
	'conditions'      => array( array( 'condition_type' => '', 'operator' => RuleOperators::EQ, 'value_number' => '10' ) ),
);
$sanitized_rule = $sanitize_rule->invoke( $admin_page );
rules_admin_smoke_assert( $sanitized_rule instanceof Rule && RuleRepository::TARGET_SERVICE === $sanitized_rule->target_type && 'service_a' === $sanitized_rule->target_value && array() === $sanitized_rule->conditions && 12.5 === $sanitized_rule->operation_value && 'condition_1_and_2' === $sanitized_rule->condition_group_expression && array() === $sanitized_rule->validate(), 'Service rules admin context must save service target and normalize comma operation_value.' );
$_POST = array();

$admin_page_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Rules/Admin/RulesAdminPage.php' );
rules_admin_smoke_assert( ! str_contains( $admin_page_source, 'Создать демо-правила' ), 'Admin page must not show create demo button.' );
rules_admin_smoke_assert( ! str_contains( $admin_page_source, 'Удалить демо-правила' ), 'Admin page must not show delete demo button.' );
rules_admin_smoke_assert( ! str_contains( $admin_page_source, 'Приоритет' ), 'Admin UI must not contain priority wording.' );
rules_admin_smoke_assert( ! str_contains( $admin_page_source, 'name="priority"' ), 'Admin UI must not expose a priority field.' );
rules_admin_smoke_assert( ! str_contains( $admin_page_source, "esc_html__( 'Текст'" ) && ! str_contains( $admin_page_source, "esc_html__( 'Число'" ), 'Admin UI must not expose universal text and number inputs for every condition.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'wdc_rules_action" value="reorder_rules"' ), 'Admin UI must expose a drag-sort reorder action.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'data-rule-row' ), 'Admin table rows must be draggable.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'render_for_context( RuleAdminContext $context )' ), 'Rules admin must expose reusable context rendering.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'posted_context_rule' ) && str_contains( $admin_page_source, 'rule_matches_context' ), 'Rules admin actions must verify target context.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'get_all_rules_for_target( $this->context()->target_type' ), 'Rules admin list must use target-aware repository methods.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'get_rules_for_target( $this->context()->target_type' ), 'Rules admin simulation must use only current target rules.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'Исходный срок доставки' ), 'Simulation UI must always expose original delivery days.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'Итоговый срок' ), 'Simulation result must show final delivery days.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'RuleOperationBases::CALENDAR_DAYS' ), 'change_delivery_days must default to calendar_days in admin handling.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, "delivery_type_options()" ), 'Simulation delivery_type must use select values pickup/courier.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'payment_method_options()' ) && ! str_contains( $admin_page_source, "'payment_method' => 'card'" ), 'Simulation payment_method must use WooCommerce gateways without hardcoded card default.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, "Условие %d" ) && str_contains( $admin_page_source, 'data-condition-group' ), 'Condition group UI must use select groups 1/2/3.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'condition_group_logic[<?php echo esc_attr( (string) $group ); ?>]' ), 'Rule form must save condition_group_logic inputs.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'Логика условий внутри групп' ), 'Internal group logic UI must be clearly labeled.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'name="condition_group_expression"' ), 'Rule form must save condition_group_expression.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'condition_1_and_2_or_3' ) && str_contains( $admin_page_source, '(Условие 1 И Условие 2) ИЛИ Условие 3' ), 'Condition expression options must include grouped labels.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'data-condition-group-block' ) && str_contains( $admin_page_source, 'Добавить условие в Условие %d' ), 'Edit form must group conditions and add rows inside a group.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'Условие применения: %s' ), 'Summary must include condition expression label.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'name="operation_text"' ), 'add_comment UI must expose operation_text textarea.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'RuleConditionUiSchema::normalize_decimal_input' ), 'Admin sanitize must normalize decimal inputs with comma or dot.' );

$schema_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Rules/Admin/RuleConditionUiSchema.php' );
foreach ( array( 'руб.', 'шт.', 'грамм', 'куб.м.' ) as $unit_label ) {
	rules_admin_smoke_assert( str_contains( $schema_source, $unit_label ), 'Condition UI schema must expose unit label: ' . $unit_label );
}
rules_admin_smoke_assert( str_contains( $schema_source, "'input'     => 'fias_id'" ), 'City condition UI must be FIAS ID only.' );

$delivery_services_admin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
rules_admin_smoke_assert( str_contains( $delivery_services_admin_source, 'render_rules_tab' ) && str_contains( $delivery_services_admin_source, 'Скопировать дефолтные правила' ), 'Delivery service page must expose service rules tab with copy default rules action.' );
rules_admin_smoke_assert( str_contains( $delivery_services_admin_source, 'copy_default_rules_to_service' ) && str_contains( $delivery_services_admin_source, "RuleRepository::TARGET_SERVICE" ), 'Copy default rules must create service-targeted copies.' );

$js_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/admin/rules-admin.js' );
rules_admin_smoke_assert( str_contains( $js_source, 'appendUnit' ), 'JS must render unit labels next to value controls.' );
rules_admin_smoke_assert( str_contains( $js_source, 'data-condition-template' ) && str_contains( $js_source, 'dataset.conditionGroupBlock' ), 'JS add condition must clone rows inside the selected group.' );
rules_admin_smoke_assert( str_contains( $js_source, "action.value === 'add_comment'" ) && str_contains( $js_source, "operationType.value = 'equals'" ), 'JS must switch add_comment to equals-only comment mode.' );
rules_admin_smoke_assert( str_contains( $js_source, 'Введите FIAS ID населенного пункта' ) || str_contains( $admin_page_source, 'Введите FIAS ID населенного пункта' ), 'UI must use FIAS ID placeholder for city.' );
rules_admin_smoke_assert( ! str_contains( $js_source, 'Начните вводить населенный пункт' ), 'UI must not contain location autocomplete by name.' );

echo "Rules admin smoke test passed.\n";
