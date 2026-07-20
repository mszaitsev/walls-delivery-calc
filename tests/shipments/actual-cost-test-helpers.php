<?php
declare(strict_types=1);

use WallsShop\WDC\Shipments\Application\ShipmentActualCostResolver;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostService;
use WallsShop\WDC\Shipments\Application\ShipmentCreationService;
use WallsShop\WDC\Shipments\Presentation\ShipmentActualCostComparisonService;
use WallsShop\WDC\Shipments\Presentation\ShipmentBaseApiCostResolver;
use WallsShop\WDC\Shipments\Storage\OrderShipmentRepository;

function shipment_test_actual_cost_resolver(): ShipmentActualCostResolver {
	return new ShipmentActualCostResolver( new ShipmentActualCostComparisonService(), new ShipmentBaseApiCostResolver() );
}

function shipment_test_actual_cost_service( OrderShipmentRepository $repository ): ShipmentActualCostService {
	return new ShipmentActualCostService( $repository );
}

/**
 * @param array<int,mixed> $adapters
 * @param array<int,mixed> $mappers
 */
function shipment_test_creation_service( OrderShipmentRepository $repository, array $adapters, array $mappers = array(), mixed $registry = null, mixed $logger = null ): ShipmentCreationService {
	return new ShipmentCreationService( $repository, $adapters, shipment_test_actual_cost_service( $repository ), $logger, $registry, $mappers );
}
