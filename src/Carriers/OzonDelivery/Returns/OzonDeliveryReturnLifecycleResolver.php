<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Returns;

use WallsShop\WDC\Domain\Status\DeliveryStatus;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryReturnLifecycleResolver {
	/** @param array<int,array<string,mixed>> $place_states */
	public function aggregate( array $place_states ): string {
		if ( array() === $place_states ) {
			return DeliveryStatus::UNKNOWN;
		}
		$has_return = false;
		$has_active_return = false;
		$has_outbound_active = false;
		$has_cancelled_no_return = false;
		$return_universal = array();
		$outbound_universal = array();
		foreach ( $place_states as $state ) {
			$value = (string) ( $state['state'] ?? '' );
			if ( in_array( $value, array( 'return_not_found', 'return_search_error', 'return_info_error', 'return_unknown' ), true ) ) {
				return DeliveryStatus::UNKNOWN;
			}
			if ( 'outbound_active' === $value ) {
				$has_outbound_active = true;
				$outbound_universal[] = (string) ( $state['outbound_universal'] ?? DeliveryStatus::UNKNOWN );
				continue;
			}
			if ( 'return_found_active' === $value ) {
				$has_return = true;
				$has_active_return = true;
				$return_universal[] = (string) ( $state['return_universal'] ?? DeliveryStatus::RETURNING_TO_SENDER );
				continue;
			}
			if ( 'return_resolved' === $value ) {
				$has_return = true;
				$return_universal[] = (string) ( $state['return_universal'] ?? DeliveryStatus::RETURNED_TO_SENDER );
				continue;
			}
			if ( 'cancelled_no_return' === $value ) {
				$has_cancelled_no_return = true;
			}
		}

		if ( $has_return ) {
			if ( $has_active_return ) {
				return $this->aggregate_universal( $return_universal );
			}
			if ( $has_outbound_active ) {
				return DeliveryStatus::UNKNOWN;
			}
			return $this->aggregate_universal( $return_universal );
		}

		if ( $has_cancelled_no_return && ! $has_outbound_active ) {
			return DeliveryStatus::CANCELLED;
		}

		return $this->aggregate_universal( $outbound_universal );
	}

	/** @param array<int,string> $statuses */
	private function aggregate_universal( array $statuses ): string {
		$statuses = array_values( array_filter( $statuses, static fn( string $status ): bool => DeliveryStatus::is_valid( $status ) ) );
		if ( array() === $statuses ) {
			return DeliveryStatus::UNKNOWN;
		}
		if ( count( array_unique( $statuses ) ) === 1 ) {
			return $statuses[0];
		}
		if ( in_array( DeliveryStatus::REJECTED, $statuses, true ) ) {
			return DeliveryStatus::REJECTED;
		}
		if ( in_array( DeliveryStatus::UNKNOWN, $statuses, true ) || in_array( DeliveryStatus::CANCELLED, $statuses, true ) || in_array( DeliveryStatus::DELIVERED, $statuses, true ) ) {
			return DeliveryStatus::UNKNOWN;
		}
		if ( in_array( DeliveryStatus::RETURNING_TO_SENDER, $statuses, true ) || in_array( DeliveryStatus::RETURNED_TO_SENDER, $statuses, true ) ) {
			return DeliveryStatus::RETURNING_TO_SENDER;
		}
		foreach ( array( DeliveryStatus::READY_FOR_PICKUP, DeliveryStatus::HANDED_TO_COURIER, DeliveryStatus::IN_TRANSIT, DeliveryStatus::CREATED_IN_CARRIER, DeliveryStatus::PENDING_CREATION_IN_CARRIER ) as $candidate ) {
			if ( in_array( $candidate, $statuses, true ) ) {
				return $candidate;
			}
		}

		return DeliveryStatus::UNKNOWN;
	}
}
