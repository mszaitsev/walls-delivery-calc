<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Admin;

defined( 'ABSPATH' ) || exit;

final class PekDestinationPickupDiagnosticStore {
	public function ttl(): int {
		return 900;
	}

	/** @param array<string,mixed> $report */
	public function save_for_current_user( array $report ): void {
		if ( function_exists( 'set_transient' ) ) {
			set_transient( $this->key(), $this->sanitize( $report ), $this->ttl() );
		}
	}

	/** @return array<string,mixed> */
	public function consume_for_current_user(): array {
		if ( ! function_exists( 'get_transient' ) ) {
			return array();
		}
		$key = $this->key();
		$value = get_transient( $key );
		if ( function_exists( 'delete_transient' ) ) {
			delete_transient( $key );
		}

		return is_array( $value ) ? $value : array();
	}

	private function key(): string {
		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;

		return 'wdc_pek_dest_pickup_diag_' . max( 0, $user_id );
	}

	/** @param array<string,mixed> $report @return array<string,mixed> */
	private function sanitize( array $report ): array {
		unset( $report['raw_response'], $report['credentials'], $report['Authorization'], $report['request_headers'] );

		return $report;
	}
}
