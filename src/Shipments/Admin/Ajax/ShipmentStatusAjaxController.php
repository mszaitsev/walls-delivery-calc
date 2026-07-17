<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

final class ShipmentStatusAjaxController {
	public function __construct( private ShipmentAdminAjaxService $ajax ) {}

	public function handle_update(): void {
		$this->ajax->ajax_update_status();
	}

	public function handle_mark_poll_exhausted(): void {
		$this->ajax->ajax_mark_poll_exhausted();
	}
}
