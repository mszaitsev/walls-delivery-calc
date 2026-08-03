<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\Pek\Geography\PekLocationMappingRepository;

defined( 'ABSPATH' ) || exit;

return static function (): void {
	( new PekLocationMappingRepository() )->install_schema();
};
