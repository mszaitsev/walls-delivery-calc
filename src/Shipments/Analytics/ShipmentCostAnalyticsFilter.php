<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Analytics;

defined( 'ABSPATH' ) || exit;

final class ShipmentCostAnalyticsFilter {
	public const PERIOD_WEEK = 'week';
	public const PERIOD_MONTH = 'month';
	public const PERIOD_QUARTER = 'quarter';
	public const PERIOD_YEAR = 'year';
	public const PERIOD_CUSTOM = 'custom';

	/**
	 * @param array<int,string> $notices
	 */
	public function __construct(
		public readonly string $period,
		public readonly string $date_from,
		public readonly string $date_to,
		public readonly ?string $carrier_key,
		public readonly bool $include_missing_actual,
		public readonly string $order_search,
		public readonly string $orderby,
		public readonly string $order,
		public readonly int $page,
		public readonly int $per_page,
		public readonly array $notices = array()
	) {
	}

	/**
	 * @param array<string,mixed> $request
	 * @param array<string,string> $carrier_options
	 */
	public static function from_request( array $request, array $carrier_options, ?\DateTimeImmutable $now = null ): self {
		$timezone = self::timezone();
		$now = $now ?? new \DateTimeImmutable( self::current_date( $timezone ), $timezone );
		$notices = array();
		$period = self::sanitize_choice( (string) ( $request['analytics_period'] ?? self::PERIOD_MONTH ), array( self::PERIOD_WEEK, self::PERIOD_MONTH, self::PERIOD_QUARTER, self::PERIOD_YEAR, self::PERIOD_CUSTOM ), self::PERIOD_MONTH );
		$ranges = self::fixed_ranges( $now );
		$from = self::parse_date( $ranges[ self::PERIOD_MONTH ]['date_from'], $timezone ) ?? $now->modify( '-1 month' );
		$to = self::parse_date( $ranges[ self::PERIOD_MONTH ]['date_to'], $timezone ) ?? $now;

		if ( isset( $ranges[ $period ] ) ) {
			$from = self::parse_date( $ranges[ $period ]['date_from'], $timezone ) ?? $from;
			$to = self::parse_date( $ranges[ $period ]['date_to'], $timezone ) ?? $to;
		} elseif ( self::PERIOD_CUSTOM === $period ) {
			$custom_from = self::parse_date( (string) ( $request['date_from'] ?? '' ), $timezone );
			$custom_to = self::parse_date( (string) ( $request['date_to'] ?? '' ), $timezone );
			if ( ! $custom_from instanceof \DateTimeImmutable || ! $custom_to instanceof \DateTimeImmutable || $custom_from > $custom_to || $custom_from < $now->modify( '-2 years' ) ) {
				$notices[] = 'Некорректный произвольный период. Показан безопасный период: последний месяц.';
				$period = self::PERIOD_MONTH;
			} else {
				$from = $custom_from;
				$to = $custom_to;
			}
		}

		$carrier = self::sanitize_key_value( (string) ( $request['carrier'] ?? '' ) );
		$carrier = '' !== $carrier && isset( $carrier_options[ $carrier ] ) ? $carrier : null;
		$actual_mode = self::sanitize_choice( (string) ( $request['actual_cost_mode'] ?? 'with_actual' ), array( 'with_actual', 'all' ), 'with_actual' );
		$orderby = self::sanitize_choice( (string) ( $request['orderby'] ?? 'date' ), self::sortable_fields(), 'date' );
		$order = self::sanitize_choice( strtolower( (string) ( $request['order'] ?? 'desc' ) ), array( 'asc', 'desc' ), 'desc' );
		$per_page = (int) ( $request['per_page'] ?? 50 );
		$per_page = in_array( $per_page, array( 25, 50, 100 ), true ) ? $per_page : 50;
		$page = max( 1, (int) ( $request['paged'] ?? 1 ) );

		return new self(
			$period,
			$from->format( 'Y-m-d' ),
			$to->format( 'Y-m-d' ),
			$carrier,
			'all' === $actual_mode,
			self::sanitize_text( (string) ( $request['order_search'] ?? '' ) ),
			$orderby,
			$order,
			$page,
			$per_page,
			$notices
		);
	}

	/** @return array<int,string> */
	public static function sortable_fields(): array {
		return array( 'order_number', 'date', 'carrier', 'base', 'actual', 'difference', 'difference_percent' );
	}

	public function date_from_start(): string {
		return $this->date_from . ' 00:00:00';
	}

	public function date_to_end(): string {
		return $this->date_to . ' 23:59:59';
	}

	/**
	 * @return array<string,array{date_from:string,date_to:string}>
	 */
	public static function fixed_ranges( ?\DateTimeImmutable $now = null ): array {
		$timezone = self::timezone();
		$now = $now ?? new \DateTimeImmutable( self::current_date( $timezone ), $timezone );
		$to = $now->format( 'Y-m-d' );

		return array(
			self::PERIOD_WEEK => array(
				'date_from' => $now->modify( '-7 days' )->format( 'Y-m-d' ),
				'date_to' => $to,
			),
			self::PERIOD_MONTH => array(
				'date_from' => $now->modify( '-1 month' )->format( 'Y-m-d' ),
				'date_to' => $to,
			),
			self::PERIOD_QUARTER => array(
				'date_from' => $now->modify( '-3 months' )->format( 'Y-m-d' ),
				'date_to' => $to,
			),
			self::PERIOD_YEAR => array(
				'date_from' => $now->modify( '-1 year' )->format( 'Y-m-d' ),
				'date_to' => $to,
			),
		);
	}

	private static function timezone(): \DateTimeZone {
		return function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
	}

	private static function current_date( \DateTimeZone $timezone ): string {
		if ( function_exists( 'current_time' ) ) {
			$current = trim( (string) current_time( 'Y-m-d' ) );
			if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}/', $current, $matches ) ) {
				return $matches[0] . ' 00:00:00';
			}
		}

		return ( new \DateTimeImmutable( 'now', $timezone ) )->format( 'Y-m-d 00:00:00' );
	}

	private static function parse_date( string $value, \DateTimeZone $timezone ): ?\DateTimeImmutable {
		if ( 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value, $timezone );

		return $date instanceof \DateTimeImmutable && $date->format( 'Y-m-d' ) === $value ? $date : null;
	}

	/** @param array<int,string> $allowed */
	private static function sanitize_choice( string $value, array $allowed, string $default ): string {
		return in_array( $value, $allowed, true ) ? $value : $default;
	}

	private static function sanitize_key_value( string $value ): string {
		return function_exists( 'sanitize_key' ) ? sanitize_key( $value ) : strtolower( preg_replace( '/[^a-zA-Z0-9_\-]/', '', $value ) ?? '' );
	}

	private static function sanitize_text( string $value ): string {
		$value = function_exists( 'wp_unslash' ) ? (string) wp_unslash( $value ) : $value;

		return function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : trim( strip_tags( $value ) );
	}
}
