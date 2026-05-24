<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\Admin;

use WallsShop\WDC\Rules\Domain\RuleCondition;
use WallsShop\WDC\Rules\ValueObjects\RuleConditionTypes;
use WallsShop\WDC\Rules\ValueObjects\RuleOperators;

defined( 'ABSPATH' ) || exit;

final class RuleConditionUiSchema {
	private const NUMERIC_OPERATORS = array( RuleOperators::GTE, RuleOperators::EQ, RuleOperators::NEQ, RuleOperators::GT, RuleOperators::LT, RuleOperators::LTE );
	private const EQUALS_OPERATORS  = array( RuleOperators::EQ, RuleOperators::NEQ );

	/**
	 * @param array<string,string> $payment_methods
	 * @param array<string,string> $countries
	 * @return array<string,array<string,mixed>>
	 */
	public function definitions( array $payment_methods = array(), array $countries = array() ): array {
		return array(
			RuleConditionTypes::ORDER_TOTAL => array(
				'label'     => __( 'Сумма заказа', 'walls-delivery-calc' ),
				'operators' => self::NUMERIC_OPERATORS,
				'input'     => 'number',
				'storage'   => 'value_number',
				'unit'      => __( 'руб.', 'walls-delivery-calc' ),
			),
			RuleConditionTypes::ITEMS_COUNT => array(
				'label'     => __( 'Количество товаров в заказе', 'walls-delivery-calc' ),
				'operators' => self::NUMERIC_OPERATORS,
				'input'     => 'integer',
				'storage'   => 'value_number',
				'unit'      => __( 'шт.', 'walls-delivery-calc' ),
			),
			RuleConditionTypes::PAYMENT_METHOD => array(
				'label'     => __( 'Способ оплаты', 'walls-delivery-calc' ),
				'operators' => self::EQUALS_OPERATORS,
				'input'     => 'select',
				'storage'   => 'value_text',
				'options'   => $payment_methods,
			),
			RuleConditionTypes::CITY => array(
				'label'     => __( 'Населенный пункт', 'walls-delivery-calc' ),
				'operators' => self::EQUALS_OPERATORS,
				'input'     => 'fias_id',
				'storage'   => 'value_text_value_json',
			),
			RuleConditionTypes::COUNTRY => array(
				'label'     => __( 'Страна', 'walls-delivery-calc' ),
				'operators' => self::EQUALS_OPERATORS,
				'input'     => 'select',
				'storage'   => 'value_text',
				'options'   => $countries,
			),
			RuleConditionTypes::DELIVERY_TYPE => array(
				'label'     => __( 'Тип доставки', 'walls-delivery-calc' ),
				'operators' => self::EQUALS_OPERATORS,
				'input'     => 'select',
				'storage'   => 'value_text',
				'options'   => $this->delivery_type_options(),
			),
			RuleConditionTypes::DELIVERY_PRICE => array(
				'label'     => __( 'Рассчитанная стоимость доставки', 'walls-delivery-calc' ),
				'operators' => self::NUMERIC_OPERATORS,
				'input'     => 'number',
				'storage'   => 'value_number',
				'unit'      => __( 'руб.', 'walls-delivery-calc' ),
			),
			RuleConditionTypes::WEIGHT => array(
				'label'     => __( 'Вес', 'walls-delivery-calc' ),
				'operators' => self::NUMERIC_OPERATORS,
				'input'     => 'number',
				'storage'   => 'value_number',
				'unit'      => __( 'грамм', 'walls-delivery-calc' ),
			),
			RuleConditionTypes::DIMENSIONS => array(
				'label'     => __( 'Габариты (Д*Ш*В см)', 'walls-delivery-calc' ),
				'operators' => self::NUMERIC_OPERATORS,
				'input'     => 'dimensions',
				'storage'   => 'value_json',
				'unit'      => __( 'см', 'walls-delivery-calc' ),
			),
			RuleConditionTypes::VOLUME => array(
				'label'     => __( 'Объем', 'walls-delivery-calc' ),
				'operators' => self::NUMERIC_OPERATORS,
				'input'     => 'number',
				'storage'   => 'value_number',
				'unit'      => __( 'куб.м.', 'walls-delivery-calc' ),
			),
			RuleConditionTypes::DAY_OF_WEEK => array(
				'label'     => __( 'День недели', 'walls-delivery-calc' ),
				'operators' => self::EQUALS_OPERATORS,
				'input'     => 'select_number',
				'storage'   => 'value_number',
				'options'   => $this->day_of_week_options(),
			),
			RuleConditionTypes::DAY_OF_MONTH => array(
				'label'     => __( 'День месяца', 'walls-delivery-calc' ),
				'operators' => self::NUMERIC_OPERATORS,
				'input'     => 'select_number',
				'storage'   => 'value_number',
				'options'   => array_combine( range( 1, 31 ), range( 1, 31 ) ),
			),
			RuleConditionTypes::MONTH => array(
				'label'     => __( 'Месяц', 'walls-delivery-calc' ),
				'operators' => self::EQUALS_OPERATORS,
				'input'     => 'select_number',
				'storage'   => 'value_number',
				'options'   => $this->month_options(),
			),
			RuleConditionTypes::DATE => array(
				'label'     => __( 'Дата', 'walls-delivery-calc' ),
				'operators' => self::NUMERIC_OPERATORS,
				'input'     => 'date',
				'storage'   => 'value_text',
			),
		);
	}

	/**
	 * @param array<string,string> $payment_methods
	 * @param array<string,string> $countries
	 * @return array<string,mixed>
	 */
	public function definition( string $type, array $payment_methods = array(), array $countries = array() ): array {
		$definitions = $this->definitions( $payment_methods, $countries );

		return $definitions[ $type ] ?? array();
	}

	/**
	 * @return array<int,string>
	 */
	public function allowed_operators( string $type ): array {
		$definition = $this->definition( $type );

		return is_array( $definition['operators'] ?? null ) ? $definition['operators'] : array();
	}

	public function default_operator( string $type ): string {
		$operators = $this->allowed_operators( $type );

		return (string) ( $operators[0] ?? RuleOperators::EQ );
	}

	/**
	 * @param array<string,mixed> $raw
	 */
	public function sanitize_condition_input( array $raw ): ?RuleCondition {
		$type = isset( $raw['condition_type'] ) ? sanitize_key( (string) $raw['condition_type'] ) : '';
		if ( '' === $type ) {
			return null;
		}

		$definition = $this->definition( $type );
		if ( array() === $definition ) {
			return new RuleCondition( null, null, 1, $type, '', '', null, array() );
		}

		$operator = isset( $raw['operator'] ) ? sanitize_key( (string) $raw['operator'] ) : '';
		if ( ! in_array( $operator, $this->allowed_operators( $type ), true ) ) {
			$operator = $this->default_operator( $type );
		}

		$value_text = '';
		$value_number = null;
		$value_json = array();
		$storage = (string) ( $definition['storage'] ?? '' );

		if ( 'value_number' === $storage ) {
			$value_number = $this->number_value( $raw['value_number'] ?? null );
		} elseif ( 'value_text_value_json' === $storage ) {
			$value_text = sanitize_text_field( (string) ( $raw['value_text'] ?? '' ) );
			$value_json = $this->json_array( $raw['value_json'] ?? array() );
			if ( '' !== $value_text ) {
				$value_json['fias_id'] = $value_text;
			}
			if ( isset( $raw['display_name'] ) && '' !== trim( (string) $raw['display_name'] ) ) {
				$value_json['display_name'] = sanitize_text_field( (string) $raw['display_name'] );
			}
		} elseif ( 'value_json' === $storage ) {
			$value_json = $this->json_array( $raw['value_json'] ?? array() );
			foreach ( array( 'length_cm', 'width_cm', 'height_cm' ) as $key ) {
				if ( isset( $value_json[ $key ] ) && '' !== trim( (string) $value_json[ $key ] ) ) {
					$value_json[ $key ] = max( 0.0, self::normalize_decimal_input( $value_json[ $key ] ) );
				}
				if ( isset( $raw[ $key ] ) && '' !== trim( (string) $raw[ $key ] ) ) {
					$value_json[ $key ] = max( 0.0, self::normalize_decimal_input( $raw[ $key ] ) );
				}
			}
		} else {
			$value_text = sanitize_text_field( (string) ( $raw['value_text'] ?? '' ) );
			if ( RuleConditionTypes::DATE === $type ) {
				$value_text = $this->normalize_date( $value_text );
			}
		}

		return new RuleCondition(
			null,
			null,
			$this->sanitize_group( $raw['condition_group'] ?? 1 ),
			$type,
			$operator,
			$value_text,
			$value_number,
			$value_json
		);
	}

	public function condition_summary( RuleCondition $condition ): string {
		$definition = $this->definition( $condition->condition_type );
		$label = (string) ( $definition['label'] ?? $condition->condition_type );
		$value = $this->display_value( $condition, $definition );
		$unit = (string) ( $definition['unit'] ?? '' );

		return trim( $label . ' ' . $this->operator_label( $condition->operator ) . ' ' . $value . ( '' !== $unit ? ' ' . $unit : '' ) );
	}

	/**
	 * @return array<string,string>
	 */
	public function operator_labels(): array {
		return array(
			RuleOperators::EQ  => '=',
			RuleOperators::NEQ => '!=',
			RuleOperators::GT  => '>',
			RuleOperators::GTE => '>=',
			RuleOperators::LT  => '<',
			RuleOperators::LTE => '<=',
		);
	}

	public function operator_label( string $operator ): string {
		$labels = $this->operator_labels();

		return $labels[ $operator ] ?? $operator;
	}

	/**
	 * @return array<string,string>
	 */
	public function delivery_type_options(): array {
		return array(
			'pickup'  => __( 'Доставка до ПВЗ', 'walls-delivery-calc' ),
			'courier' => __( 'Доставка курьером', 'walls-delivery-calc' ),
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function day_of_week_options(): array {
		return array(
			1 => __( 'понедельник', 'walls-delivery-calc' ),
			2 => __( 'вторник', 'walls-delivery-calc' ),
			3 => __( 'среда', 'walls-delivery-calc' ),
			4 => __( 'четверг', 'walls-delivery-calc' ),
			5 => __( 'пятница', 'walls-delivery-calc' ),
			6 => __( 'суббота', 'walls-delivery-calc' ),
			7 => __( 'воскресенье', 'walls-delivery-calc' ),
		);
	}

	/**
	 * @return array<int,string>
	 */
	public function month_options(): array {
		return array(
			1  => __( 'январь', 'walls-delivery-calc' ),
			2  => __( 'февраль', 'walls-delivery-calc' ),
			3  => __( 'март', 'walls-delivery-calc' ),
			4  => __( 'апрель', 'walls-delivery-calc' ),
			5  => __( 'май', 'walls-delivery-calc' ),
			6  => __( 'июнь', 'walls-delivery-calc' ),
			7  => __( 'июль', 'walls-delivery-calc' ),
			8  => __( 'август', 'walls-delivery-calc' ),
			9  => __( 'сентябрь', 'walls-delivery-calc' ),
			10 => __( 'октябрь', 'walls-delivery-calc' ),
			11 => __( 'ноябрь', 'walls-delivery-calc' ),
			12 => __( 'декабрь', 'walls-delivery-calc' ),
		);
	}

	private function number_value( mixed $value ): ?float {
		$value = trim( str_replace( ',', '.', sanitize_text_field( (string) $value ) ) );

		return '' === $value ? null : (float) $value;
	}

	public static function normalize_decimal_input( mixed $value ): float {
		$value = trim( str_replace( ',', '.', sanitize_text_field( (string) $value ) ) );

		return '' === $value ? 0.0 : (float) $value;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function json_array( mixed $value ): array {
		if ( is_string( $value ) ) {
			$decoded = json_decode( $value, true );
			$value = is_array( $decoded ) ? $decoded : array();
		}

		if ( ! is_array( $value ) ) {
			return array();
		}

		$result = array();
		foreach ( $value as $key => $item ) {
			if ( is_scalar( $item ) ) {
				$result[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $item );
			}
		}

		return $result;
	}

	private function normalize_date( string $value ): string {
		$value = trim( $value );
		if ( 1 === preg_match( '/^(\d{2})\.(\d{2})\.(\d{4})$/', $value, $matches ) ) {
			return $matches[3] . '-' . $matches[2] . '-' . $matches[1];
		}

		return 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
	}

	private function sanitize_group( mixed $value ): int {
		$group = (int) sanitize_text_field( (string) $value );

		return in_array( $group, array( 1, 2, 3 ), true ) ? $group : 1;
	}

	/**
	 * @param array<string,mixed> $definition
	 */
	private function display_value( RuleCondition $condition, array $definition ): string {
		$input = (string) ( $definition['input'] ?? '' );
		$options = is_array( $definition['options'] ?? null ) ? $definition['options'] : array();

		if ( 'fias_id' === $input ) {
			$display = (string) ( $condition->value_json['display_name'] ?? '' );
			$fias_id = (string) ( $condition->value_json['fias_id'] ?? $condition->value_text );
			return '' !== $display ? trim( $display . ' (' . $fias_id . ')' ) : $condition->value_text;
		}

		if ( 'dimensions' === $input ) {
			$parts = array();
			foreach ( array( 'length_cm' => 'Д', 'width_cm' => 'Ш', 'height_cm' => 'В' ) as $key => $label ) {
				if ( isset( $condition->value_json[ $key ] ) && '' !== (string) $condition->value_json[ $key ] ) {
					$parts[] = $label . '=' . (string) $condition->value_json[ $key ];
				}
			}
			return implode( ', ', $parts );
		}

		if ( 'date' === $input && 1 === preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $condition->value_text, $matches ) ) {
			return $matches[3] . '.' . $matches[2] . '.' . $matches[1];
		}

		if ( null !== $condition->value_number ) {
			$key = (int) $condition->value_number;
			return (string) ( $options[ $key ] ?? $condition->value_number );
		}

		return (string) ( $options[ $condition->value_text ] ?? $condition->value_text );
	}
}
