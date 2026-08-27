<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Status;

use WallsShop\WDC\Domain\Status\DeliveryStatus;

defined( 'ABSPATH' ) || exit;

final class JetLogisticStatusEventResolver {
	public function __construct( private JetLogisticStatusMapper $mapper ) {
	}

	/**
	 * @param array<int,mixed> $logs
	 * @return array{events:array<int,array<string,string>>,current_event:array<string,string>}
	 */
	public function resolve( array $logs ): array {
		$events = $this->events( $logs );
		$current = $this->current_event( $events );
		$public_events = array_map( fn( array $event ): array => $this->public_event( $event ), array_slice( $events, 0, 5 ) );

		return array(
			'events' => $public_events,
			'current_event' => array() !== $current ? $this->public_event( $current ) : array(),
		);
	}

	/** @param array<int,mixed> $logs @return array<int,array<string,mixed>> */
	private function events( array $logs ): array {
		$events = array();
		$seen = array();
		foreach ( $logs as $index => $log ) {
			$event = $this->event_from_log( $log );
			if ( array() === $event ) {
				continue;
			}
			$key = (string) $event['date'] . '|' . (string) $event['message'];
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$mapping = $this->mapper->match_mapping( (string) $event['message'] );
			$status = (string) ( $mapping['universal_status'] ?? '' );
			$status = DeliveryStatus::is_valid( $status ) ? $status : '';
			$event['matched_rule'] = (string) ( $mapping['external_status'] ?? '' );
			$event['universal_status'] = $status;
			$event['universal_status_label'] = '' !== $status ? DeliveryStatus::label( $status ) : '';
			$event['timestamp'] = $this->timestamp( (string) $event['date'], (string) $event['message'] );
			$event['source_index'] = $index;
			$events[] = $event;
		}
		usort(
			$events,
			static function ( array $a, array $b ): int {
				$a_time = $a['timestamp'];
				$b_time = $b['timestamp'];
				if ( null !== $a_time && null !== $b_time && $a_time !== $b_time ) {
					return $b_time <=> $a_time;
				}
				if ( null !== $a_time && null === $b_time ) {
					return -1;
				}
				if ( null === $a_time && null !== $b_time ) {
					return 1;
				}

				return (int) ( $a['source_index'] ?? 0 ) <=> (int) ( $b['source_index'] ?? 0 );
			}
		);

		return $events;
	}

	/** @return array<string,string> */
	private function event_from_log( mixed $log ): array {
		if ( is_array( $log ) ) {
			$date = trim( (string) ( $log['date'] ?? '' ) );
			$message = trim( (string) ( $log['message'] ?? '' ) );
		} else {
			$date = '';
			$message = trim( (string) $log );
			if ( preg_match( '/^\s*((?:\d{2}\.\d{2}\.\d{4})(?:\s+\d{2}:\d{2}(?::\d{2})?)?|(?:\d{4}-\d{2}-\d{2})(?:\s+\d{2}:\d{2}(?::\d{2})?)?)\s*:?\s*(.+)$/us', $message, $matches ) ) {
				$date = trim( $matches[1] );
				$message = trim( $matches[2] );
			}
		}
		if ( '' === $date && '' === $message ) {
			return array();
		}

		return array( 'date' => $date, 'message' => $message );
	}

	/** @param array<int,array<string,mixed>> $events @return array<string,mixed> */
	private function current_event( array $events ): array {
		$current = array();
		foreach ( $events as $event ) {
			if ( '' === (string) ( $event['universal_status'] ?? '' ) ) {
				continue;
			}
			if ( array() === $current ) {
				$current = $event;
				continue;
			}
			$event_time = $event['timestamp'];
			$current_time = $current['timestamp'];
			if ( null !== $event_time && null === $current_time ) {
				$current = $event;
				continue;
			}
			if ( null !== $event_time && null !== $current_time && $event_time > $current_time ) {
				$current = $event;
			}
		}

		return $current;
	}

	private function timestamp( string $date, string $message ): ?int {
		foreach ( array( 'd.m.Y H:i:s', 'd.m.Y H:i', 'd.m.Y', 'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d' ) as $format ) {
			$value = \DateTimeImmutable::createFromFormat( '!' . $format, $date );
			if ( $value instanceof \DateTimeImmutable ) {
				return $value->getTimestamp();
			}
		}
		if ( preg_match( '/(\d{1,2})\s+(января|февраля|марта|апреля|мая|июня|июля|августа|сентября|октября|ноября|декабря)\s+(\d{4})/iu', $message, $matches ) ) {
			$months = array( 'января' => 1, 'февраля' => 2, 'марта' => 3, 'апреля' => 4, 'мая' => 5, 'июня' => 6, 'июля' => 7, 'августа' => 8, 'сентября' => 9, 'октября' => 10, 'ноября' => 11, 'декабря' => 12 );
			$month = $months[ mb_strtolower( $matches[2], 'UTF-8' ) ] ?? 0;
			if ( $month > 0 ) {
				return mktime( 0, 0, 0, $month, (int) $matches[1], (int) $matches[3] );
			}
		}

		return null;
	}

	/** @param array<string,mixed> $event @return array<string,string> */
	private function public_event( array $event ): array {
		return array(
			'date' => (string) ( $event['date'] ?? '' ),
			'message' => (string) ( $event['message'] ?? '' ),
			'matched_rule' => (string) ( $event['matched_rule'] ?? '' ),
			'universal_status' => (string) ( $event['universal_status'] ?? '' ),
			'universal_status_label' => (string) ( $event['universal_status_label'] ?? '' ),
		);
	}
}
