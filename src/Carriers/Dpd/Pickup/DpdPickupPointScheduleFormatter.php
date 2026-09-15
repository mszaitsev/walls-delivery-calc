<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Pickup;

defined( 'ABSPATH' ) || exit;

final class DpdPickupPointScheduleFormatter {
	private const OPERATION_PRIORITY = array( 'SelfDelivery', 'SelfPickup', 'Payment', 'PaymentByBankCard' );

	public function format( mixed $schedule ): string {
		$value = $this->decode( $schedule );
		if ( null === $value || '' === $value ) {
			return '';
		}
		if ( is_string( $value ) ) {
			return trim( $value );
		}

		$operations = $this->operations( $value );
		if ( array() === $operations ) {
			return '';
		}
		$selected = $this->select_operations( $operations );
		$lines = array();
		foreach ( $selected as $operation ) {
			foreach ( $this->timetables( $operation['timetable'] ?? $operation ) as $timetable ) {
				$line = $this->format_timetable( $timetable );
				if ( '' !== $line ) {
					$lines[] = $line;
				}
			}
		}

		return implode( "\n", array_values( array_unique( $lines ) ) );
	}

	private function decode( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->decode( $item );
			}

			return $value;
		}
		if ( ! is_string( $value ) ) {
			return $value;
		}
		$value = trim( $value );
		if ( '' === $value || ! in_array( $value[0], array( '[', '{' ), true ) ) {
			return $value;
		}
		$decoded = json_decode( $value, true );

		return JSON_ERROR_NONE === json_last_error() ? $decoded : $value;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function operations( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		if ( isset( $value['operation'] ) || isset( $value['timetable'] ) || isset( $value['weekDays'] ) || isset( $value['workTime'] ) ) {
			return array( $value );
		}
		if ( isset( $value['schedule'] ) ) {
			return $this->operations( $value['schedule'] );
		}
		if ( isset( $value['operationSchedules'] ) ) {
			return $this->operations( $value['operationSchedules'] );
		}
		if ( array_is_list( $value ) ) {
			return array_values( array_filter( $value, 'is_array' ) );
		}

		return array();
	}

	/**
	 * @param array<int,array<string,mixed>> $operations
	 * @return array<int,array<string,mixed>>
	 */
	private function select_operations( array $operations ): array {
		foreach ( self::OPERATION_PRIORITY as $operation_name ) {
			$matched = array_values(
				array_filter(
					$operations,
					static fn( array $operation ): bool => $operation_name === (string) ( $operation['operation'] ?? '' )
				)
			);
			if ( array() !== $matched ) {
				return $matched;
			}
		}

		return $operations;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function timetables( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array();
		}
		if ( isset( $value['weekDays'] ) || isset( $value['workTime'] ) ) {
			return array( $value );
		}
		if ( array_is_list( $value ) ) {
			return array_values( array_filter( $value, 'is_array' ) );
		}

		return array();
	}

	/**
	 * @param array<string,mixed> $timetable
	 */
	private function format_timetable( array $timetable ): string {
		$days = $this->format_days( (string) ( $timetable['weekDays'] ?? '' ) );
		$time = $this->format_time( (string) ( $timetable['workTime'] ?? '' ) );
		if ( '' === $days ) {
			return $time;
		}
		if ( '' === $time ) {
			return $days;
		}

		return $days . ': ' . $time;
	}

	private function format_days( string $days ): string {
		$days = trim( str_replace( array( ';', ' / ' ), array( ',', '/' ), $days ) );
		if ( '' === $days ) {
			return '';
		}
		if ( ! str_contains( $days, ',' ) && preg_match( '/^(.+?)\s*[-–]\s*(.+)$/u', $days, $matches ) ) {
			$from = $this->normalize_day_name( (string) $matches[1] );
			$to = $this->normalize_day_name( (string) $matches[2] );

			return '' !== $from && '' !== $to ? $from . '–' . $to : str_replace( '-', '–', $days );
		}
		$parts = preg_split( '/\s*,\s*/u', $days );
		$day_names = array( 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс' );
		$normalized = array();
		foreach ( is_array( $parts ) ? $parts : array() as $part ) {
			$part = $this->normalize_day_name( $part );
			if ( '' !== $part ) {
				$normalized[] = $part;
			}
		}
		if ( $day_names === $normalized ) {
			return 'Пн–Вс';
		}
		if ( array_slice( $day_names, 0, 5 ) === $normalized ) {
			return 'Пн–Пт';
		}
		if ( 1 === count( $normalized ) ) {
			return $normalized[0];
		}
		if ( count( $normalized ) > 1 ) {
			return implode( ', ', $normalized );
		}

		return str_replace( '-', '–', $days );
	}

	private function normalize_day_name( string $day ): string {
		$day = trim( str_replace( array( '.', ' ' ), '', $day ) );
		$map = array(
			'пн' => 'Пн',
			'понедельник' => 'Пн',
			'вт' => 'Вт',
			'вторник' => 'Вт',
			'ср' => 'Ср',
			'среда' => 'Ср',
			'чт' => 'Чт',
			'четверг' => 'Чт',
			'пт' => 'Пт',
			'пятница' => 'Пт',
			'сб' => 'Сб',
			'суббота' => 'Сб',
			'вс' => 'Вс',
			'воскресенье' => 'Вс',
		);
		$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $day, 'UTF-8' ) : strtolower( $day );

		return $map[ $key ] ?? $day;
	}

	private function format_time( string $time ): string {
		$time = trim( $time );
		if ( '' === $time ) {
			return '';
		}

		return preg_replace( '/\s*[-–]\s*/u', '–', $time ) ?? $time;
	}
}
