<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\Pek\Geography\PekLocationMappingRepository;

defined( 'ABSPATH' ) || exit;

return static function (): void {
	( new PekLocationMappingRepository() )->create_schema_if_needed();
};
