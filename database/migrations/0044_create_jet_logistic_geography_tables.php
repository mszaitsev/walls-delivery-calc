<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyOverrideRepository;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyRepository;

defined( 'ABSPATH' ) || exit;

( new JetLogisticGeographyRepository() )->create_schema();
( new JetLogisticGeographyOverrideRepository() )->create_schema();
