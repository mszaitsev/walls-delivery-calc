<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\Cdek\Tariffs\CdekTariffRepository;

defined( 'ABSPATH' ) || exit;

return static function (): void {
	( new CdekTariffRepository() )->create_schema_if_needed();
};
