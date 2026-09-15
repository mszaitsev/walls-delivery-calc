<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Dpd;

defined( 'ABSPATH' ) || exit;

final class DpdEventNormalizer {
	/** @return array<int,array<string,mixed>> */
	public function normalize_many( mixed $events ): array {
		$rows = $this->list( $events );
		$result = array();
		foreach ( $rows as $index => $row ) {
			$event = $this->normalize_one( $row, $index );
			if ( array() !== $event ) {
				$result[] = $event;
			}
		}
		return $result;
	}

	/** @param array<int,array<string,mixed>> $events @return array<string,array<string,mixed>> */
	public function latest_by_order( array $events ): array {
		$groups = array();
		foreach ( $events as $event ) {
			$key = '' !== $event['dpdOrderNr'] ? 'dpd:' . $event['dpdOrderNr'] : 'client:' . $event['clientOrderNr'];
			if ( 'dpd:' === $key || 'client:' === $key ) {
				continue;
			}
			$current = $groups[ $key ] ?? null;
			if ( ! is_array( $current ) || $this->is_later( $event, $current ) ) {
				$groups[ $key ] = $event;
			}
		}
		return $groups;
	}

	/** @return array<string,mixed> */
	private function normalize_one( mixed $row, int $index ): array {
		if ( is_object( $row ) ) {
			$row = get_object_vars( $row );
		}
		if ( ! is_array( $row ) ) {
			return array();
		}
		$date = trim( (string) ( $row['eventDate'] ?? '' ) );
		$timestamp = $this->timestamp( $date );
		return array(
			'clientOrderNr' => trim( (string) ( $row['clientOrderNr'] ?? '' ) ),
			'dpdOrderNr' => trim( (string) ( $row['dpdOrderNr'] ?? '' ) ),
			'dpdParcelNr' => trim( (string) ( $row['dpdParcelNr'] ?? '' ) ),
			'eventNumber' => preg_replace( '/[^0-9]/', '', (string) ( $row['eventNumber'] ?? '' ) ) ?: '',
			'eventCode' => trim( (string) ( $row['eventCode'] ?? '' ) ),
			'eventName' => trim( (string) ( $row['eventName'] ?? '' ) ),
			'eventDate' => $date,
			'timestamp' => $timestamp,
			'index' => $index,
		);
	}

	/** @return array<int,mixed> */
	private function list( mixed $value ): array {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( ! is_array( $value ) ) {
			return array();
		}
		if ( array_key_exists( 'eventNumber', $value ) || array_key_exists( 'eventCode', $value ) ) {
			return array( $value );
		}
		return array_values( $value );
	}

	private function is_later( array $incoming, array $current ): bool {
		$it = (int) $incoming['timestamp'];
		$ct = (int) $current['timestamp'];
		if ( $it > 0 && $ct > 0 && $it !== $ct ) {
			return $it > $ct;
		}
		if ( 0 === $it && $ct > 0 ) {
			return false;
		}
		return (int) $incoming['index'] >= (int) $current['index'];
	}

	private function timestamp( string $date ): int {
		try { return '' !== $date ? ( new \DateTimeImmutable( $date ) )->getTimestamp() : 0; } catch ( \Throwable ) { return 0; }
	}
}
