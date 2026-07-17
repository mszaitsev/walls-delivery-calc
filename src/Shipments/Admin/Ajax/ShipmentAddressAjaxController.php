<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

final class ShipmentAddressAjaxController {
	public function __construct( private ShipmentAdminAjaxService $ajax ) {}

	public function handle_normalize(): void {
		$this->ajax->ajax_normalize_address();
	}

	public function handle_search_pickup_points(): void {
		$this->ajax->ajax_search_pickup_points();
	}
}
