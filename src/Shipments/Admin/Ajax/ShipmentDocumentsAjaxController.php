<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin\Ajax;

defined( 'ABSPATH' ) || exit;

final class ShipmentDocumentsAjaxController {
	public function __construct( private ShipmentAdminAjaxService $ajax ) {}

	public function handle_cdek_barcode_prepare(): void {
		$this->ajax->ajax_cdek_barcode_prepare();
	}
}
