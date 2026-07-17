<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

final class ShipmentRemovalAjaxController {
	public function __construct( private ShipmentAdminAjaxService $ajax ) {}

	public function handle_cancel(): void {
		$this->ajax->ajax_cancel();
	}

	public function handle_remove(): void {
		$this->ajax->ajax_remove_from_order();
	}
}
