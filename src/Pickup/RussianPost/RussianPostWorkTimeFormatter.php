<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\RussianPost;

defined( 'ABSPATH' ) || exit;

final class RussianPostWorkTimeFormatter {
	private const DAYS = array(
		'пн' => 'Пн',
		'вт' => 'Вт',
		'ср' => 'Ср',
		'чт' => 'Чт',
		'пт' => 'Пт',
		'сб' => 'Сб',
		'вс' => 'Вс',
	);

	/**
	 * @param mixed $work_time
	 */
	public function format( mixed $work_time ): string {
		if ( is_string( $work_time ) ) {
			return $this->normalize_dashes( trim( $work_time ) );
		}
		if ( ! is_array( $work_time ) || array() === $work_time ) {
			return '';
		}

		$parsed = array();
		foreach ( $work_time as $line ) {
			$item = $this->parse_line( (string) $line );
			if ( null === $item ) {
				return $this->fallback( $work_time );
			}
			$parsed[] = $item;
		}

		return $this->render_groups( $parsed );
	}

	/**
	 * @return array{day:string,work:string,break:string}|null
	 */
	private function parse_line( string $line ): ?array {
		$line = trim( $this->lower( $line ) );
		if ( preg_match( '/^(пн|вт|ср|чт|пт|сб|вс),\s*выходной$/u', $line, $matches ) ) {
			return array( 'day' => self::DAYS[ $matches[1] ], 'work' => 'выходной', 'break' => '' );
		}
		if ( preg_match( '/^(пн|вт|ср|чт|пт|сб|вс),\s*открыто:\s*(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2})(?:,\s*перерыв:\s*(\d{2}:\d{2})\s*-\s*(\d{2}:\d{2}))?$/u', $line, $matches ) ) {
			return array(
				'day' => self::DAYS[ $matches[1] ],
				'work' => $matches[2] . '–' . $matches[3],
				'break' => isset( $matches[4], $matches[5] ) ? $matches[4] . '–' . $matches[5] : '',
			);
		}

		return null;
	}

	/**
	 * @param array<int,array{day:string,work:string,break:string}> $items
	 */
	private function render_groups( array $items ): string {
		$groups = array();
		foreach ( $items as $item ) {
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
	 * @param array<int|string,mixed> $lines
	 */
	private function fallback( array $lines ): string {
		return implode( "\n", array_values( array_filter( array_map( fn( mixed $line ): string => $this->normalize_dashes( trim( (string) $line ) ), $lines ), static fn( string $line ): bool => '' !== $line ) ) );
	}

	private function normalize_dashes( string $line ): string {
		return preg_replace( '/\s+-\s+/', '–', $line ) ?? $line;
	}

	private function lower( string $line ): string {
		if ( function_exists( 'mb_strtolower' ) ) {
			return mb_strtolower( $line );
		}

		return strtr(
			$line,
			array(
				'П' => 'п',
				'Н' => 'н',
				'В' => 'в',
				'Т' => 'т',
				'С' => 'с',
				'Р' => 'р',
				'Ч' => 'ч',
				'Б' => 'б',
				'О' => 'о',
				'Ы' => 'ы',
				'К' => 'к',
				'Е' => 'е',
			)
		);
	}
}
