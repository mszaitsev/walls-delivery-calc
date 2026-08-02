<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\Pek\Pickup\PekTerminalRepository;

defined( 'ABSPATH' ) || exit;

return static function (): void {
	( new PekTerminalRepository() )->create_schema_if_needed();
};
