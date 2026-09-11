<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Infrastructure\Background\BackgroundExecutionBudget;

function background_budget_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$time = 100.0;
$budget = new BackgroundExecutionBudget( 18.0, 15, null, static function () use ( &$time ): float { return $time; } );
background_budget_assert( $budget->can_continue(), 'Fresh budget must allow work.' );
$budget->mark_unit_processed();
$time = 118.0;
background_budget_assert( ! $budget->can_continue() && 'time_budget' === $budget->stop_reason(), 'Elapsed soft limit must stop the budget.' );

$time = 200.0;
$budget = new BackgroundExecutionBudget( 100.0, 3, null, static function () use ( &$time ): float { return $time; } );
for ( $i = 0; $i < 3; ++$i ) {
	background_budget_assert( $budget->can_continue(), 'Unit budget must allow the configured number of units.' );
	$budget->mark_unit_processed();
}
background_budget_assert( ! $budget->can_continue() && 3 === $budget->units_processed() && 'unit_budget' === $budget->stop_reason(), 'Unit cap must stop deterministically.' );

$memory = 80;
$budget = new BackgroundExecutionBudget( 100.0, 10, 80, static fn(): float => 0.0, static function () use ( &$memory ): int { return $memory; } );
background_budget_assert( ! $budget->can_continue() && 'memory_budget' === $budget->stop_reason(), 'Memory threshold must stop the budget.' );
background_budget_assert( 80 * 1024 * 1024 === BackgroundExecutionBudget::memory_threshold_from_limit( '100M' ), 'Memory limit parser must apply the requested fraction.' );
background_budget_assert( null === BackgroundExecutionBudget::memory_threshold_from_limit( '-1' ), 'Unlimited memory must disable the memory guard.' );

echo "Background execution budget smoke test passed.\n";
