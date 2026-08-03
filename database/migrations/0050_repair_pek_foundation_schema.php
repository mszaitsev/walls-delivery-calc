<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\Pek\Installation\PekSchemaIntegrityService;

defined( 'ABSPATH' ) || exit;

return static function (): void {
	( new PekSchemaIntegrityService() )->repair();
};
