<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Services;

defined( 'ABSPATH' ) || exit;

final class GarChangesService {
	private const LAST_CHECK_OPTION = 'wdc_gar_changes_last_check_at';
	private const PENDING_OPTION = 'wdc_gar_changes_pending';

	public function has_pending_changes(): bool {
		return (bool) get_option( self::PENDING_OPTION, false );
	}

	public function last_check_at(): ?string {
		$value = get_option( self::LAST_CHECK_OPTION, '' );

		return is_string( $value ) && '' !== $value ? $value : null;
	}

	public function mark_checked(): void {
		update_option( self::LAST_CHECK_OPTION, current_time( 'mysql' ), false );
		update_option( self::PENDING_OPTION, false, false );
	}
}
