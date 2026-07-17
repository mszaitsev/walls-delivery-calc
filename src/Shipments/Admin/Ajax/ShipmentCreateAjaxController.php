<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

final class ShipmentCreateAjaxController {
	public function __construct( private ShipmentAdminAjaxService $ajax ) {}

	public function handle(): void {
		$this->ajax->ajax_create();
	}
}
