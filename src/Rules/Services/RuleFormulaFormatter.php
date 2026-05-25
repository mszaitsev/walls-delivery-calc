<?php
declare(strict_types=1);

namespace WallsShop\WDC\Rules\Services;

use WallsShop\WDC\Domain\Common\Money;

defined( 'ABSPATH' ) || exit;

final class RuleFormulaFormatter {
	/**
	 * @param array<int,array<string,mixed>> $audit
	 * @param array<string,mixed> $post_processing
	 * @return array<int,string>
	 */
	public function lines( float $base_price_rub, array $audit, float $final_price_rub, array $post_processing = array() ): array {
		$lines = array(
			'Базовая цена API: ' . $this->format_price( $base_price_rub ) . ' руб.',
		);

		foreach ( $audit as $entry ) {
			if ( empty( $entry['applied'] ) || 'change_price' !== (string) ( $entry['action_type'] ?? '' ) ) {
				continue;
			}

			$after = $this->money_rubles( $entry['after_value'] ?? null );
			if ( null === $after ) {
				continue;
			}

			$name = trim( (string) ( $entry['rule_name'] ?? '' ) );
			$name = '' !== $name ? $name : 'Без названия';
			$lines[] = 'Правило "' . $name . '": ' . $this->operation_label( (string) ( $entry['operation'] ?? '' ), $entry ) . ' → ' . $this->format_price( $after ) . ' руб.';
		}

		if ( ! empty( $post_processing['minimum_price_applied'] ) ) {
			$lines[] = 'Минимальная цена → ' . $this->format_price( $final_price_rub ) . ' руб.';
		}

		if ( ! empty( $post_processing['round_up_applied'] ) ) {
			$lines[] = 'Округление вверх → ' . $this->format_price( $final_price_rub ) . ' руб.';
		}

		$lines[] = 'Итог: ' . $this->format_price( $final_price_rub ) . ' руб.';

		return array_values( array_unique( $lines ) );
	}

	/**
	 * @param array<string,mixed> $entry
	 */
	private function operation_label( string $operation, array $entry ): string {
		$value = array_key_exists( 'operation_value', $entry ) && is_numeric( $entry['operation_value'] )
			? $this->format_decimal( (float) $entry['operation_value'] )
			: '';
		$base = $this->operation_base_label( (string) ( $entry['operation_base'] ?? '' ) );
		$suffix = '' !== $value ? ' ' . $value . $base : '';

		return match ( $operation ) {
			'increase' => 'увеличить на' . $suffix,
			'decrease' => 'уменьшить на' . $suffix,
			'set' => 'установить' . $suffix,
			'multiply' => 'умножить на' . $suffix,
			'divide' => 'разделить на' . $suffix,
			default => '' !== $operation ? $operation : 'изменить цену',
		};
	}

	private function operation_base_label( string $base ): string {
		return match ( $base ) {
			'rubles' => ' руб.',
			'percent_of_delivery' => '% от доставки',
			'percent_of_order' => '% от заказа',
			'percent_of_order_and_delivery' => '% от заказа и доставки',
			default => '',
		};
	}

	private function money_rubles( mixed $value ): ?float {
		if ( $value instanceof Money ) {
			return $value->get_rubles();
		}

		if ( is_array( $value ) && array_key_exists( 'amount_kopecks', $value ) ) {
			return Money::from_array( $value )->get_rubles();
		}

		if ( is_numeric( $value ) ) {
			return (float) $value;
		}

		return null;
	}

	private function format_price( float $value ): string {
		$formatted = number_format( $value, 2, '.', ' ' );

		return str_ends_with( $formatted, '.00' ) ? substr( $formatted, 0, -3 ) : $formatted;
	}

	private function format_decimal( float $value ): string {
		$formatted = number_format( $value, 4, '.', '' );
		$formatted = rtrim( rtrim( $formatted, '0' ), '.' );

		return '' !== $formatted ? $formatted : '0';
	}
}
