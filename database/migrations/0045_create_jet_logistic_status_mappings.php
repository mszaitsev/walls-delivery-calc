<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusMappingRepository;

defined( 'ABSPATH' ) || exit;

( new JetLogisticStatusMappingRepository() )->create_schema();
