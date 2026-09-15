<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Shipments\Analytics\ShipmentCostThresholdPolicy;

function shipment_cost_threshold_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$policy = new ShipmentCostThresholdPolicy();
shipment_cost_threshold_assert( ShipmentCostThresholdPolicy::STATUS_WITHIN_THRESHOLD === $policy->classify( 10000, 10000 ), 'Equal actual cost must be within threshold.' );
shipment_cost_threshold_assert( ShipmentCostThresholdPolicy::STATUS_WITHIN_THRESHOLD === $policy->classify( 10000, 10300 ), 'Actual cost exactly +3% must be within threshold.' );
shipment_cost_threshold_assert( ShipmentCostThresholdPolicy::STATUS_OVER_THRESHOLD === $policy->classify( 10000, 10301 ), 'Actual cost above +3% must be over threshold.' );
shipment_cost_threshold_assert( ShipmentCostThresholdPolicy::STATUS_WITHIN_THRESHOLD === $policy->classify( 10000, 9000 ), 'Savings must be within threshold.' );
shipment_cost_threshold_assert( ShipmentCostThresholdPolicy::STATUS_NOT_COMPARABLE === $policy->classify( null, 10000 ), 'Missing plan must be not comparable.' );
shipment_cost_threshold_assert( ShipmentCostThresholdPolicy::STATUS_NOT_COMPARABLE === $policy->classify( 0, 10000 ), 'Zero plan must be not comparable.' );
shipment_cost_threshold_assert( ShipmentCostThresholdPolicy::STATUS_NOT_COMPARABLE === $policy->classify( 10000, null ), 'Missing actual must be not comparable.' );
shipment_cost_threshold_assert( ShipmentCostThresholdPolicy::STATUS_NOT_COMPARABLE === $policy->classify( 10000, 0 ), 'Zero actual must be not comparable.' );

echo "Shipment cost threshold policy smoke passed.\n";
