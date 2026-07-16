<?php
declare(strict_types=1);

require_once __DIR__ . '/regression/ShipmentRegressionRunner.php';

$root = dirname( __DIR__, 2 );

try {
	$manifest = ShipmentRegressionRunner::load_manifest( __DIR__ . '/regression/shipment-regression-manifest.php' );
	$runner = new ShipmentRegressionRunner( $root, $manifest );
	$options = array(
		'group' => '',
		'include_baseline' => false,
		'include_optional' => false,
		'fail_fast' => false,
	);
	$list = false;

	foreach ( array_slice( $argv, 1 ) as $argument ) {
		if ( '--list' === $argument ) {
			$list = true;
		} elseif ( '--include-baseline' === $argument ) {
			$options['include_baseline'] = true;
		} elseif ( '--include-optional' === $argument ) {
			$options['include_optional'] = true;
		} elseif ( '--fail-fast' === $argument ) {
			$options['fail_fast'] = true;
		} elseif ( str_starts_with( $argument, '--group=' ) ) {
			$options['group'] = substr( $argument, strlen( '--group=' ) );
		} elseif ( '--help' === $argument || '-h' === $argument ) {
			echo "Usage: php tests/shipments/run-shipment-regression-profile.php [--list] [--group=name] [--include-baseline] [--include-optional] [--fail-fast]\n";
			exit( ShipmentRegressionRunner::EXIT_SUCCESS );
		} else {
			fwrite( STDERR, 'Unknown option: ' . $argument . "\n" );
			exit( ShipmentRegressionRunner::EXIT_CONFIG );
		}
	}

	if ( $list ) {
		$runner->print_list();
		exit( ShipmentRegressionRunner::EXIT_SUCCESS );
	}

	$result = $runner->run( $options );
	$runner->print_report( $result );
	exit( $result['exit_code'] );
} catch ( InvalidArgumentException $exception ) {
	fwrite( STDERR, 'Configuration error: ' . $exception->getMessage() . "\n" );
	exit( ShipmentRegressionRunner::EXIT_CONFIG );
} catch ( Throwable $exception ) {
	fwrite( STDERR, 'Runner infrastructure failure: ' . $exception->getMessage() . "\n" );
	exit( ShipmentRegressionRunner::EXIT_INFRASTRUCTURE );
}
