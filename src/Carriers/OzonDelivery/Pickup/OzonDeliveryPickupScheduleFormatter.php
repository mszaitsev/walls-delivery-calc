<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Pickup;
use DateTimeImmutable;
use DateTimeZone;
defined( 'ABSPATH' ) || exit;
final class OzonDeliveryPickupScheduleFormatter {
	private const DAYS = array( 'Пн', 'Вт', 'Ср', 'Чт', 'Пт', 'Сб', 'Вс' );
	private const FALLBACK = 'График работы уточняйте в пункте выдачи';
	private const MAX_OUTPUT_LENGTH = 1000;
	public function format( array $schedule ): string {
		if ( array() === $schedule ) { return ''; }
		$weekdays = array_fill( 0, 7, array() ); $dates = array();
		foreach ( $schedule as $entry ) {
			if ( ! is_array( $entry ) || ! is_string( $entry['date'] ?? null ) || ! is_array( $entry['periods'] ?? null ) ) { return self::FALLBACK; }
			$date = $this->date( $entry['date'] ); if ( null === $date || isset( $dates[$date->format( 'Y-m-d' )] ) ) { return self::FALLBACK; }
			$dates[$date->format( 'Y-m-d' )] = true; $periods = $this->periods( $entry['periods'] ); if ( null === $periods ) { return self::FALLBACK; }
			$weekdays[(int) $date->format( 'N' ) - 1][] = $periods;
		}
		$signatures = array();
		foreach ( $weekdays as $entries ) { if ( count( $entries ) < 2 || 1 !== count( array_unique( $entries ) ) ) { return self::FALLBACK; } $signatures[] = $entries[0]; }
		if ( 1 === count( array_unique( $signatures ) ) ) { return $this->bound( 'Ежедневно' . $this->schedule_text( $signatures[0] ) ); }
		$lines = array(); for ( $start = 0; $start < 7; ) { $end = $start; while ( $end + 1 < 7 && $signatures[$end + 1] === $signatures[$start] ) { ++$end; } $days = $start === $end ? self::DAYS[$start] : self::DAYS[$start] . '–' . self::DAYS[$end]; $lines[] = $days . $this->schedule_text( $signatures[$start] ); $start = $end + 1; }
		return $this->bound( implode( "\n", $lines ) );
	}
	private function date( string $value ): ?DateTimeImmutable { if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) { return null; } $date = DateTimeImmutable::createFromFormat( '!Y-m-d', $value, new DateTimeZone( 'UTC' ) ); $errors = DateTimeImmutable::getLastErrors(); return $date instanceof DateTimeImmutable && $date->format( 'Y-m-d' ) === $value && ( false === $errors || 0 === $errors['warning_count'] + $errors['error_count'] ) ? $date : null; }
	/** @param array<int,mixed> $periods */ private function periods( array $periods ): ?string { $normalized = array(); foreach ( $periods as $period ) { if ( ! is_array( $period ) || ! is_string( $period['from_local'] ?? null ) || ! is_string( $period['to_local'] ?? null ) || ! $this->time( $period['from_local'] ) || ! $this->time( $period['to_local'] ) ) { return null; } $normalized[] = substr( $period['from_local'], 0, 5 ) . '–' . substr( $period['to_local'], 0, 5 ); } $normalized = array_values( array_unique( $normalized ) ); sort( $normalized, SORT_STRING ); return implode( ', ', $normalized ); }
	private function time( string $value ): bool { return 1 === preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $value ); }
	private function schedule_text( string $signature ): string { return '' === $signature ? ' — выходной' : ' ' . $signature; }
	private function bound( string $value ): string { return strlen( $value ) <= self::MAX_OUTPUT_LENGTH ? $value : self::FALLBACK; }
}
