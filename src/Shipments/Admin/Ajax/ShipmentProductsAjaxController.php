<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

final class ShipmentProductsAjaxController {
	public function __construct( private ShipmentAdminAjaxService $ajax ) {}

	public function handle_search_products(): void {
		$this->ajax->ajax_search_products();
	}

	public function handle_dpd_contact_history(): void {
		$this->ajax->ajax_dpd_courier_contact_history();
	}
}
