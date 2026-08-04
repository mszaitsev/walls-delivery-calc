<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Quote;

use DateTimeImmutable;
use DateTimeZone;
use WallsShop\WDC\Carriers\Pek\PekSettings;

defined( 'ABSPATH' ) || exit;

final class PekQuotePlannedDateTimeResolver {
	public function __construct( private PekSettings $settings ) {
	}

	public function resolve(): string {
		$timezone = $this->timezone();
		$now = function_exists( 'current_datetime' ) ? current_datetime()->setTimezone( $timezone ) : new DateTimeImmutable( 'now', $timezone );
		$planned = $now->modify( '+1 hour' );
		$minute = (int) $planned->format( 'i' );
		$remainder = $minute % 15;
		if ( 0 !== $remainder || '00' !== $planned->format( 's' ) ) {
			$planned = $planned->modify( '+' . ( 15 - $remainder ) . ' minutes' );
		}

		return $planned->setTime( (int) $planned->format( 'H' ), (int) $planned->format( 'i' ), 0 )->format( 'Y-m-d\TH:i:s' );
	}

	public function timezone_source(): string {
		$sender = $this->settings->sender_warehouse();
		$value = trim( (string) ( $sender['branchTimezone'] ?? '' ) );
		if ( '' !== $value ) {
			try {
				new DateTimeZone( $value );
				return 'sender_branch';
			} catch ( \Throwable ) {
			}
		}

		return 'wordpress';
	}

	private function timezone(): DateTimeZone {
		$sender = $this->settings->sender_warehouse();
		$value = trim( (string) ( $sender['branchTimezone'] ?? '' ) );
		if ( '' !== $value ) {
			try {
				return new DateTimeZone( $value );
			} catch ( \Throwable ) {
			}
		}

		return function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' );
	}
}
