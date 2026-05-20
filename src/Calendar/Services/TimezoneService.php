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
