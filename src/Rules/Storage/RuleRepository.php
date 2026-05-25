<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\Storage;

use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleCondition;

defined( 'ABSPATH' ) || exit;

final class RuleRepository {
	public const TARGET_DEFAULT = 'default';
	public const TARGET_CARRIER = 'carrier';
	public const TARGET_SERVICE = 'service';

	private \wpdb $wpdb;

	public function __construct( ?\wpdb $db = null ) {
		global $wpdb;

		$this->wpdb = $db ?? $wpdb;
	}

	public function save_rule( Rule $rule ): int {
		$now  = current_time( 'mysql' );
		$data = $this->rule_to_row( $rule, $now );

		if ( null !== $rule->id && $rule->id > 0 ) {
			$this->wpdb->update( $this->rules_table(), $data, array( 'id' => $rule->id ), $this->rule_formats(), array( '%d' ) );
			$rule_id = $rule->id;
		} else {
			$this->wpdb->insert( $this->rules_table(), $data, $this->rule_formats() );
			$rule_id = (int) $this->wpdb->insert_id;
		}

		$this->replace_conditions( $rule_id, $rule->conditions, $now );

		return $rule_id;
	}

	public function delete_rule( int $id ): void {
		$this->wpdb->delete( $this->conditions_table(), array( 'rule_id' => $id ), array( '%d' ) );
		$this->wpdb->delete( $this->rules_table(), array( 'id' => $id ), array( '%d' ) );
	}

	public function delete_all(): void {
		$this->wpdb->query( "DELETE FROM {$this->conditions_table()}" );
		$this->wpdb->query( "DELETE FROM {$this->rules_table()}" );
	}

	public function get_rule( int $id ): ?Rule {
		$row = $this->wpdb->get_row(
			$this->wpdb->prepare( "SELECT * FROM {$this->rules_table()} WHERE id = %d LIMIT 1", $id ),
			ARRAY_A
		);

		return is_array( $row ) ? $this->row_to_rule( $row ) : null;
	}

	/**
	 * @return array<int,Rule>
	 */
	public function get_enabled_rules(): array {
		$rows = $this->wpdb->get_results( "SELECT * FROM {$this->rules_table()} WHERE enabled = 1 ORDER BY priority ASC, id ASC", ARRAY_A );

		return $this->rows_to_rules( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @return array<int,Rule>
	 */
	public function get_default_rules(): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->rules_table()} WHERE enabled = 1 AND target_type = %s ORDER BY priority ASC, id ASC",
				self::TARGET_DEFAULT
			),
			ARRAY_A
		);

		return $this->rows_to_rules( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @return array<int,Rule>
	 */
	public function get_all_default_rules(): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->rules_table()} WHERE target_type = %s ORDER BY priority ASC, id ASC",
				self::TARGET_DEFAULT
			),
			ARRAY_A
		);

		return $this->rows_to_rules( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @return array<int,Rule>
	 */
	public function get_rules_for_target( string $targetType, string $targetValue ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare(
				"SELECT * FROM {$this->rules_table()} WHERE enabled = 1 AND target_type = %s AND target_value = %s ORDER BY priority ASC, id ASC",
				$targetType,
				$targetValue
			),
			ARRAY_A
		);

		return $this->rows_to_rules( is_array( $rows ) ? $rows : array() );
	}

	/**
	 * @return array<int,Rule>
	 */
	public function get_rules_for_carrier_with_default_fallback( string $carrierKey ): array {
		return $this->get_rules_for_target_or_default( self::TARGET_CARRIER, $carrierKey );
	}

	/**
	 * @return array<int,Rule>
	 */
	public function get_rules_for_service( string $service_key ): array {
		return $this->get_rules_for_target( self::TARGET_SERVICE, $service_key );
	}

	/**
	 * @return array{rules:array<int,Rule>,source:string}
	 */
	public function get_rules_for_service_with_default_fallback( string $service_key, bool $fallback_to_default = true ): array {
		$service_rules = $this->get_rules_for_service( $service_key );
		if ( array() !== $service_rules ) {
			return array( 'rules' => $service_rules, 'source' => 'service' );
		}

		if ( $fallback_to_default ) {
			$default_rules = $this->get_default_rules();

			return array( 'rules' => $default_rules, 'source' => array() !== $default_rules ? 'default' : 'none' );
		}

		return array( 'rules' => array(), 'source' => 'none' );
	}

	/**
	 * @return array<int,Rule>
	 */
	public function get_rules_for_target_or_default( string $target_type, string $target_value ): array {
		$target_rules = $this->get_rules_for_target( $target_type, $target_value );
		if ( array() !== $target_rules ) {
			return $target_rules;
		}

		return $this->get_default_rules();
	}

	/**
	 * @param array<int,int> $ordered_ids
	 */
	public function reorder_default_rules( array $ordered_ids ): void {
		$position = 10;
		foreach ( $ordered_ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 ) {
				continue;
			}

			$this->wpdb->update(
				$this->rules_table(),
				array(
					'priority'   => $position,
					'updated_at' => current_time( 'mysql' ),
				),
				array(
					'id'          => $id,
					'target_type' => self::TARGET_DEFAULT,
				),
				array( '%d', '%s' ),
				array( '%d', '%s' )
			);
			$position += 10;
		}
	}

	/**
	 * @param array<int,RuleCondition> $conditions
	 */
	private function replace_conditions( int $rule_id, array $conditions, string $now ): void {
		$this->wpdb->delete( $this->conditions_table(), array( 'rule_id' => $rule_id ), array( '%d' ) );

		foreach ( $conditions as $condition ) {
			if ( ! $condition instanceof RuleCondition ) {
				continue;
			}

			$this->wpdb->insert(
				$this->conditions_table(),
				array(
					'rule_id'         => $rule_id,
					'condition_group' => $condition->condition_group,
					'condition_type'  => $condition->condition_type,
					'operator'        => $condition->operator,
					'value_text'      => $condition->value_text,
					'value_number'    => $condition->value_number,
					'value_json'      => wp_json_encode( $condition->value_json ),
					'created_at'      => $now,
				),
				array( '%d', '%d', '%s', '%s', '%s', '%f', '%s', '%s' )
			);
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,Rule>
	 */
	private function rows_to_rules( array $rows ): array {
		$rules = array();
		foreach ( $rows as $row ) {
			if ( is_array( $row ) ) {
				$rules[] = $this->row_to_rule( $row );
			}
		}

		return $rules;
	}

	/**
	 * @param array<string,mixed> $row
	 */
	private function row_to_rule( array $row ): Rule {
		$conditions = $this->conditions_for_rule( (int) $row['id'] );

		return Rule::from_array(
			array_merge(
				$row,
				array(
					'enabled'         => (bool) (int) $row['enabled'],
					'promo_shipping'  => (bool) (int) $row['promo_shipping'],
					'stop_processing' => (bool) (int) $row['stop_processing'],
					'conditions'      => $conditions,
					'condition_group_logic' => $this->decode_group_logic( $row['condition_group_logic'] ?? '' ),
					'condition_group_expression' => Rule::normalized_group_expression( $row['condition_group_expression'] ?? Rule::DEFAULT_GROUP_EXPRESSION ),
				)
			)
		);
	}

	/**
	 * @return array<int,RuleCondition>
	 */
	private function conditions_for_rule( int $rule_id ): array {
		$rows = $this->wpdb->get_results(
			$this->wpdb->prepare( "SELECT * FROM {$this->conditions_table()} WHERE rule_id = %d ORDER BY condition_group ASC, id ASC", $rule_id ),
			ARRAY_A
		);

		return array_map(
			static fn ( array $row ): RuleCondition => RuleCondition::from_array( $row ),
			is_array( $rows ) ? $rows : array()
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function rule_to_row( Rule $rule, string $now ): array {
		return array(
			'name'            => $rule->name,
			'enabled'         => $rule->enabled ? 1 : 0,
			'priority'        => $rule->priority,
			'target_type'     => $rule->target_type,
			'target_value'    => $rule->target_value,
			'action_type'     => $rule->action_type,
			'operation_type'  => $rule->operation_type,
			'operation_value' => $rule->operation_value,
			'operation_base'  => $rule->operation_base,
			'operation_text'  => $rule->operation_text,
			'promo_shipping'  => $rule->promo_shipping ? 1 : 0,
			'stop_processing' => $rule->stop_processing ? 1 : 0,
			'condition_group_logic' => wp_json_encode( Rule::normalized_group_logic( $rule->condition_group_logic ) ),
			'condition_group_expression' => Rule::normalized_group_expression( $rule->condition_group_expression ),
			'created_at'      => $now,
			'updated_at'      => $now,
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function rule_formats(): array {
		return array( '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%f', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s' );
	}

	/**
	 * @return array<int,string>
	 */
	private function decode_group_logic( mixed $value ): array {
		if ( is_string( $value ) && '' !== trim( $value ) ) {
			$decoded = json_decode( $value, true );
			return Rule::normalized_group_logic( is_array( $decoded ) ? $decoded : array() );
		}

		return Rule::normalized_group_logic( is_array( $value ) ? $value : array() );
	}

	private function rules_table(): string {
		return $this->wpdb->prefix . 'wdc_rules';
	}

	private function conditions_table(): string {
		return $this->wpdb->prefix . 'wdc_rule_conditions';
	}
}
