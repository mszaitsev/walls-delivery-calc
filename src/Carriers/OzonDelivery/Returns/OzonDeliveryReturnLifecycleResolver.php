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
		$has_active = false;
		foreach ( $place_states as $state ) {
			$value = (string) ( $state['state'] ?? '' );
			if ( 'return_not_found' === $value || 'return_error' === $value ) {
				return DeliveryStatus::UNKNOWN;
			}
			if ( 'outbound_active' === $value ) {
				return (string) ( $state['outbound_universal'] ?? DeliveryStatus::UNKNOWN );
			}
			if ( 'return_found_active' === $value ) {
				$has_return = true;
				$has_active = true;
			}
			if ( 'return_received' === $value ) {
				$has_return = true;
			}
		}
		if ( $has_return && $has_active ) {
			return DeliveryStatus::RETURNING_TO_SENDER;
		}
		if ( $has_return ) {
			return DeliveryStatus::RETURNED_TO_SENDER;
		}

		return DeliveryStatus::CANCELLED;
	}
}
