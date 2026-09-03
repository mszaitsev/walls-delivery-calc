<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusMappingRepository;

defined( 'ABSPATH' ) || exit;

return static function (): void {
	( new JetLogisticStatusMappingRepository() )->ensure_default_mappings();
};
