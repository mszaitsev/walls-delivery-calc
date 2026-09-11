<?php
declare(strict_types=1);

namespace WallsShop\WDC\Calendar\Services;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

final class TimezoneService {
	public const TIMEZONE = 'Asia/Novosibirsk';
	private const CUTOFF_TIME = '19:00:00';

	private DateTimeZone $timezone;

	public function __construct() {
		$this->timezone = new DateTimeZone( self::TIMEZONE );
	}

	public function now(): DateTimeImmutable {
		return new DateTimeImmutable( 'now', $this->timezone );
	}

	public function timezone(): DateTimeZone {
		return $this->timezone;
	}

	public function next_local_time_timestamp( string $time, ?DateTimeImmutable $now = null ): int {
		if ( 1 !== preg_match( '/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/', $time ) ) {
			return 0;
		}

		$now = null === $now ? $this->now() : $now->setTimezone( $this->timezone );
		[ $hour, $minute ] = array_map( 'intval', explode( ':', $time ) );
		$next = $now->setTime( $hour, $minute, 0 );
		if ( $next->getTimestamp() <= $now->getTimestamp() ) {
			$next = $next->modify( '+1 day' );
		}

		return $next->getTimestamp();
	}

	public function format_timestamp( int $timestamp, string $format = 'd.m.Y H:i' ): string {
		if ( $timestamp <= 0 ) {
			return '';
		}

		return ( new DateTimeImmutable( '@' . $timestamp ) )->setTimezone( $this->timezone )->format( $format );
	}

	public static function format_unix_timestamp( int $timestamp, string $format = 'd.m.Y H:i' ): string {
		return ( new self() )->format_timestamp( $timestamp, $format );
	}

	public static function format_site_datetime( string $value, string $format = 'd.m.Y H:i:s' ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		$source = function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
		try {
			$date = new DateTimeImmutable( $value, $source );
		} catch ( \Exception ) {
			return $value;
		}

		return $date->setTimezone( ( new self() )->timezone() )->format( $format );
	}

	public static function format_utc_datetime( string $value, string $format = 'd.m.Y H:i:s' ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		try {
			$date = new DateTimeImmutable( $value, new DateTimeZone( 'UTC' ) );
		} catch ( \Exception ) {
			return $value;
		}

		return $date->setTimezone( ( new self() )->timezone() )->format( $format );
	}

	public function today(): string {
		return $this->now()->format( 'Y-m-d' );
	}

	public function is_after_cutoff(): bool {
		return $this->now()->format( 'H:i:s' ) >= self::CUTOFF_TIME;
	}

	public function normalize_order_date( DateTimeInterface|string|null $date = null ): string {
		$local = $this->to_local_datetime( $date );

		if ( $local->format( 'H:i:s' ) >= self::CUTOFF_TIME ) {
			$local = $local->modify( '+1 day' );
		}

		return $local->format( 'Y-m-d' );
	}

	public function to_local_datetime( DateTimeInterface|string|null $date = null ): DateTimeImmutable {
		if ( null === $date ) {
			return $this->now();
		}

		if ( $date instanceof DateTimeInterface ) {
			return DateTimeImmutable::createFromInterface( $date )->setTimezone( $this->timezone );
		}

		if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new DateTimeImmutable( $date . ' 00:00:00', $this->timezone );
		}

		return new DateTimeImmutable( $date, $this->timezone );
	}
}
