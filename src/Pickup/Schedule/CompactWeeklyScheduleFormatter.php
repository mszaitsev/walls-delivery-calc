<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Schedule;

defined( 'ABSPATH' ) || exit;

final class CompactWeeklyScheduleFormatter {
	private const DAY_ORDER = array( 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс' );
	private const DAY_ALIASES = array(
		'1' => 'Пн',
		'2' => 'Вт',
		'3' => 'Ср',
		'4' => 'Чт',
		'5' => 'Пт',
		'6' => 'Сб',
		'7' => 'Вс',
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

	/**
	 * @param array<int,array{day:string,work:string,break?:string}> $items
	 */
	public function format_items( array $items ): string {
		$normalized = array();
		foreach ( $items as $item ) {
			$day = $this->normalize_day( (string) ( $item['day'] ?? '' ) );
			$work = $this->normalize_time_range( (string) ( $item['work'] ?? '' ) );
			if ( '' === $day || '' === $work ) {
				continue;
			}
			$normalized[] = array(
				'day' => $day,
				'work' => $work,
				'break' => $this->normalize_time_range( (string) ( $item['break'] ?? '' ) ),
			);
		}
		if ( array() === $normalized ) {
			return '';
		}

		usort(
			$normalized,
			static fn( array $a, array $b ): int => array_search( $a['day'], self::DAY_ORDER, true ) <=> array_search( $b['day'], self::DAY_ORDER, true )
		);

		$groups = array();
		foreach ( $normalized as $item ) {
			$last_index = count( $groups ) - 1;
			if ( $last_index >= 0 && $groups[ $last_index ]['work'] === $item['work'] && $groups[ $last_index ]['break'] === $item['break'] ) {
				$groups[ $last_index ]['end'] = $item['day'];
				continue;
			}
			$groups[] = array(
				'start' => $item['day'],
				'end' => $item['day'],
				'work' => $item['work'],
				'break' => $item['break'],
			);
		}

		$lines = array();
		foreach ( $groups as $group ) {
			$days = $group['start'] === $group['end'] ? $group['start'] : $group['start'] . '–' . $group['end'];
			$lines[] = $days . ': ' . $group['work'];
			if ( '' !== $group['break'] ) {
				$lines[] = 'Перерыв: ' . $group['break'];
			}
		}

		return implode( "\n", $lines );
	}

	/**
	 * @return array<int,string>
	 */
	public function expand_days( mixed $value ): array {
		if ( is_array( $value ) ) {
			$days = array();
			foreach ( $value as $item ) {
				array_push( $days, ...$this->expand_days( $item ) );
			}

			return array_values( array_unique( $days ) );
		}
		$value = trim( $this->lower( (string) $value ) );
		if ( '' === $value ) {
			return array();
		}
		if ( in_array( $value, array( 'ежедневно', 'каждый день', 'daily', 'all' ), true ) ) {
			return self::DAY_ORDER;
		}
		$value = str_replace( array( '—', '–' ), '-', $value );
		$parts = preg_split( '/[\s,;]+/u', $value ) ?: array();
		$days = array();
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}
			if ( str_contains( $part, '-' ) ) {
				$range = array_values( array_filter( array_map( 'trim', explode( '-', $part ) ) ) );
				if ( 2 === count( $range ) ) {
					array_push( $days, ...$this->expand_range( $range[0], $range[1] ) );
					continue;
				}
			}
			$day = $this->normalize_day( $part );
			if ( '' !== $day ) {
				$days[] = $day;
			}
		}

		return array_values( array_unique( $days ) );
	}

	public function normalize_time_range( string $value ): string {
		$value = trim( preg_replace( '/\s+/u', ' ', $value ) ?? $value );
		if ( '' === $value ) {
			return '';
		}
		if ( 'выходной' === $this->lower( $value ) ) {
			return 'выходной';
		}
		if ( preg_match( '/(\d{1,2}:\d{2})\s*[-–—]\s*(\d{1,2}:\d{2})/u', $value, $matches ) ) {
			return $this->pad_time( $matches[1] ) . '–' . $this->pad_time( $matches[2] );
		}

		return str_replace( array( ' - ', '-' ), '–', $value );
	}

	public function normalize_day( string $value ): string {
		$value = trim( $this->lower( $value ) );
		$value = preg_replace( '/\.$/', '', $value ) ?? $value;

		return self::DAY_ALIASES[ $value ] ?? '';
	}

	/**
	 * @return array<int,string>
	 */
	private function expand_range( string $start, string $end ): array {
		$start_day = $this->normalize_day( $start );
		$end_day = $this->normalize_day( $end );
		$start_index = array_search( $start_day, self::DAY_ORDER, true );
		$end_index = array_search( $end_day, self::DAY_ORDER, true );
		if ( false === $start_index || false === $end_index ) {
			return array();
		}
		if ( $end_index < $start_index ) {
			return array_merge( array_slice( self::DAY_ORDER, $start_index ), array_slice( self::DAY_ORDER, 0, $end_index + 1 ) );
		}

		return array_slice( self::DAY_ORDER, $start_index, $end_index - $start_index + 1 );
	}

	private function pad_time( string $value ): string {
		if ( preg_match( '/^(\d):(\d{2})$/', $value, $matches ) ) {
			return '0' . $matches[1] . ':' . $matches[2];
		}

		return $value;
	}

	private function lower( string $line ): string {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $line, 'UTF-8' ) : strtolower( $line );
	}
}
