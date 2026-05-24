<?php
declare(strict_types=1);

use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\Package;
use WallsShop\WDC\Domain\Package\PackageItem;
use WallsShop\WDC\Rules\Domain\Rule;
use WallsShop\WDC\Rules\Domain\RuleCondition;
use WallsShop\WDC\Rules\Domain\RuleEvaluationContext;
use WallsShop\WDC\Rules\Services\ConditionEvaluator;
use WallsShop\WDC\Rules\Services\RuleEngine;
use WallsShop\WDC\Rules\Services\RuleEvaluator;
use WallsShop\WDC\Rules\Services\RuleSimulator;
use WallsShop\WDC\Rules\Storage\RuleRepository;
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
				if ( (int) $row['id'] === (int) ( $where['id'] ?? 0 ) ) {
					$this->rules[ $index ] = array_merge( $row, $data, array( 'id' => $row['id'] ) );
					return true;
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

$legacy_id = $repository->save_rule( rules_admin_rule( 'Legacy empty target', 10, true, '', 'legacy' ) );
$saved = $repository->get_rule( $legacy_id );
rules_admin_smoke_assert( $saved instanceof Rule && 'default' === $saved->target_type && '' === $saved->target_value, 'Legacy empty target_type must normalize to default.' );

$default_id = $repository->save_rule( rules_admin_rule( 'Default enabled', 20, true ) );
$disabled_id = $repository->save_rule( rules_admin_rule( 'Default disabled', 30, false ) );
$carrier_id = $repository->save_rule( rules_admin_rule( 'Carrier rule', 5, true, 'carrier', 'demo' ) );

$default_rules = $repository->get_default_rules();
rules_admin_smoke_assert( 2 === count( $default_rules ), 'get_default_rules must return only enabled default rules.' );
rules_admin_smoke_assert( array( $legacy_id, $default_id ) === array_map( static fn ( Rule $rule ): ?int => $rule->id, $default_rules ), 'Default rules must be ordered by table order.' );

$repository->reorder_default_rules( array( $default_id, $legacy_id, $disabled_id ) );
$default_rules = $repository->get_default_rules();
rules_admin_smoke_assert( array( $default_id, $legacy_id ) === array_map( static fn ( Rule $rule ): ?int => $rule->id, $default_rules ), 'Drag-sort ordering must persist for enabled default rules.' );

$all_default_rules = $repository->get_all_default_rules();
rules_admin_smoke_assert( 3 === count( $all_default_rules ), 'get_all_default_rules must include disabled default rules.' );
rules_admin_smoke_assert( $all_default_rules[2]->id === $disabled_id, 'Disabled default rules must be sorted after enabled rules.' );

$carrier_rules = $repository->get_rules_for_target_or_default( 'carrier', 'demo' );
rules_admin_smoke_assert( 1 === count( $carrier_rules ) && $carrier_rules[0]->id === $carrier_id, 'Carrier rules must win over defaults.' );

$fallback_rules = $repository->get_rules_for_carrier_with_default_fallback( 'missing' );
rules_admin_smoke_assert( count( $fallback_rules ) === count( $default_rules ), 'Missing carrier rules must fall back to defaults.' );

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
$move_b = $repository->get_rule( $legacy_id );
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

$admin_page_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Rules/Admin/RulesAdminPage.php' );
rules_admin_smoke_assert( ! str_contains( $admin_page_source, 'Создать демо-правила' ), 'Admin page must not show create demo button.' );
rules_admin_smoke_assert( ! str_contains( $admin_page_source, 'Удалить демо-правила' ), 'Admin page must not show delete demo button.' );
rules_admin_smoke_assert( ! str_contains( $admin_page_source, 'Приоритет' ), 'Admin UI must not contain priority wording.' );
rules_admin_smoke_assert( ! str_contains( $admin_page_source, 'name="priority"' ), 'Admin UI must not expose a priority field.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'wdc_rules_action" value="reorder_rules"' ), 'Admin UI must expose a drag-sort reorder action.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'data-rule-row' ), 'Admin table rows must be draggable.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'Исходный срок доставки' ), 'Simulation UI must always expose original delivery days.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'Итоговый срок' ), 'Simulation result must show final delivery days.' );
rules_admin_smoke_assert( str_contains( $admin_page_source, 'RuleOperationBases::CALENDAR_DAYS' ), 'change_delivery_days must default to calendar_days in admin handling.' );

$legacy_files = shell_exec( 'git diff --name-only -- includes' );
rules_admin_smoke_assert( '' === trim( (string) $legacy_files ), 'Legacy includes/* must remain unchanged.' );

echo "Rules admin smoke test passed.\n";
