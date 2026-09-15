<?php
declare(strict_types=1);

require_once __DIR__ . '/regression/ShipmentRegressionRunner.php';

function shipment_regression_profile_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function shipment_regression_fixture_root(): string {
	$root = rtrim( sys_get_temp_dir(), DIRECTORY_SEPARATOR ) . DIRECTORY_SEPARATOR . 'wdc-regression-profile-smoke-' . getmypid() . '-' . bin2hex( random_bytes( 4 ) );
	mkdir( $root . DIRECTORY_SEPARATOR . 'tests', 0777, true );
	return $root;
}

function shipment_regression_write_fixture( string $root, string $name, string $source ): string {
	$path = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . $name;
	file_put_contents( $path, $source );
	return 'tests/' . $name;
}

function shipment_regression_remove_dir( string $path ): void {
	if ( ! is_dir( $path ) ) {
		return;
	}
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $iterator as $item ) {
		$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
	}
	rmdir( $path );
}

$project_root = dirname( __DIR__, 2 );
$manifest = ShipmentRegressionRunner::load_manifest( __DIR__ . '/regression/shipment-regression-manifest.php' );
$runner = new ShipmentRegressionRunner( $project_root, $manifest );
$groups = $runner->groups();

foreach ( array( 'framework', 'cdek', 'dpd', 'russian-post', 'yandex', 'status-core', 'baseline', 'optional' ) as $group ) {
	shipment_regression_profile_assert( isset( $groups[ $group ] ) && array() !== $groups[ $group ], 'Regression profile group must be present and non-empty: ' . $group );
}

$baseline_entries = array_filter( $groups['baseline'], static fn( array $entry ): bool => ! empty( $entry['baseline'] ) );
shipment_regression_profile_assert( count( $baseline_entries ) >= 2, 'Regression profile must keep known baselines outside the default run.' );
foreach ( $baseline_entries as $entry ) {
	shipment_regression_profile_assert( '' !== (string) $entry['expected_failure'], 'Baseline entry must include exact expected signature: ' . $entry['id'] );
}

$list_result = null;
$runner_path = __DIR__ . '/run-shipment-regression-profile.php';
$list_command = array( PHP_BINARY, $runner_path, '--list' );
$descriptor_spec = array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) );
$process = proc_open( $list_command, $descriptor_spec, $pipes, $project_root );
shipment_regression_profile_assert( is_resource( $process ), 'Regression profile --list process must start.' );
$list_output = stream_get_contents( $pipes[1] );
$list_error = stream_get_contents( $pipes[2] );
fclose( $pipes[1] );
fclose( $pipes[2] );
$list_result = proc_close( $process );
shipment_regression_profile_assert( 0 === $list_result && str_contains( $list_output, '[framework]' ) && '' === trim( $list_error ), 'Regression profile --list must print groups and exit 0.' );

$unknown_process = proc_open( array( PHP_BINARY, $runner_path, '--group=__missing__' ), $descriptor_spec, $pipes, $project_root );
shipment_regression_profile_assert( is_resource( $unknown_process ), 'Unknown group process must start.' );
stream_get_contents( $pipes[1] );
$unknown_error = stream_get_contents( $pipes[2] );
fclose( $pipes[1] );
fclose( $pipes[2] );
$unknown_result = proc_close( $unknown_process );
shipment_regression_profile_assert( ShipmentRegressionRunner::EXIT_CONFIG === $unknown_result && str_contains( $unknown_error, 'Unknown regression profile group' ), 'Unknown group must return configuration error.' );

$fixture_root = shipment_regression_fixture_root();
try {
	$pass = shipment_regression_write_fixture( $fixture_root, 'pass.php', "<?php echo \"fixture pass\\n\";\n" );
	$fail = shipment_regression_write_fixture( $fixture_root, 'fail.php', "<?php fwrite(STDERR, \"fixture fail signature\\n\"); exit(1);\n" );
	$baseline = shipment_regression_write_fixture( $fixture_root, 'baseline.php', "<?php echo \"expected baseline signature\\n\"; exit(1);\n" );
	$resolved_baseline = shipment_regression_write_fixture( $fixture_root, 'baseline-resolved.php', "<?php echo \"baseline resolved\\n\";\n" );
	$mismatch = shipment_regression_write_fixture( $fixture_root, 'baseline-mismatch.php', "<?php echo \"different failure\\n\"; exit(1);\n" );
	$timeout = shipment_regression_write_fixture( $fixture_root, 'timeout.php', "<?php while (true) { usleep(100000); }\n" );

	$fixture_manifest = array(
		'fixture.pass' => array( 'path' => $pass, 'groups' => array( 'fixtures' ), 'timeout' => 5 ),
		'fixture.fail' => array( 'path' => $fail, 'groups' => array( 'fixtures' ), 'timeout' => 5 ),
		'fixture.baseline' => array( 'path' => $baseline, 'groups' => array( 'fixtures' ), 'required' => false, 'baseline' => true, 'expected_failure' => 'expected baseline signature', 'timeout' => 5 ),
		'fixture.baseline-resolved' => array( 'path' => $resolved_baseline, 'groups' => array( 'fixtures' ), 'required' => false, 'baseline' => true, 'expected_failure' => 'expected baseline signature', 'timeout' => 5 ),
		'fixture.mismatch' => array( 'path' => $mismatch, 'groups' => array( 'fixtures' ), 'required' => false, 'baseline' => true, 'expected_failure' => 'expected baseline signature', 'timeout' => 5 ),
		'fixture.timeout' => array( 'path' => $timeout, 'groups' => array( 'fixtures' ), 'timeout' => 1 ),
	);
	$fixture_runner = new ShipmentRegressionRunner( $fixture_root, $fixture_manifest );
	$fixture_result = $fixture_runner->run( array( 'group' => 'fixtures', 'include_baseline' => true ) );
	$statuses = array_column( $fixture_result['results'], 'status', 'id' );
	shipment_regression_profile_assert( 'PASS' === $statuses['fixture.pass'], 'Fixture pass process must be PASS.' );
	shipment_regression_profile_assert( 'FAIL' === $statuses['fixture.fail'], 'Fixture failing process must be FAIL.' );
	shipment_regression_profile_assert( 'BASELINE' === $statuses['fixture.baseline'], 'Fixture expected baseline must be BASELINE.' );
	shipment_regression_profile_assert( 'BASELINE-RESOLVED' === $statuses['fixture.baseline-resolved'], 'Fixture passing baseline must be BASELINE-RESOLVED.' );
	shipment_regression_profile_assert( 'BASELINE-MISMATCH' === $statuses['fixture.mismatch'], 'Fixture wrong baseline signature must be BASELINE-MISMATCH.' );
	shipment_regression_profile_assert( 'TIMEOUT' === $statuses['fixture.timeout'], 'Fixture timeout process must be TIMEOUT.' );
	shipment_regression_profile_assert( ShipmentRegressionRunner::EXIT_INFRASTRUCTURE === $fixture_result['exit_code'] && 1 === $fixture_result['counts']['timeout'], 'Timeout fixture must set runner infrastructure exit code and timeout count.' );
} finally {
	shipment_regression_remove_dir( $fixture_root );
}

$fast_manifest = array(
	'required.pass' => array( 'path' => 'tests/shipments/run-shipment-lifecycle-contract-smoke.php', 'groups' => array( 'required' ) ),
	'baseline.skip' => array( 'path' => 'tests/shipments/run-shipment-lifecycle-contract-smoke.php', 'groups' => array( 'baseline' ), 'required' => false, 'baseline' => true, 'expected_failure' => 'baseline signature' ),
	'optional.skip' => array( 'path' => 'tests/shipments/run-shipment-lifecycle-contract-smoke.php', 'groups' => array( 'optional' ), 'required' => false, 'optional' => true, 'expected_failure' => 'optional signature' ),
);
$fast_executor = static fn( string $path, int $timeout ): array => array(
	'exit_code' => 0,
	'stdout' => "synthetic pass\n",
	'stderr' => '',
	'timed_out' => false,
	'infrastructure_error' => false,
);
$fast_runner = new ShipmentRegressionRunner( $project_root, $fast_manifest, $fast_executor );
$default_scope = $fast_runner->run();
shipment_regression_profile_assert( 1 === $default_scope['counts']['passed'] && 2 === $default_scope['counts']['skipped'], 'Default skipped count must include only scoped baseline/optional entries.' );
$required_scope = $fast_runner->run( array( 'group' => 'required' ) );
shipment_regression_profile_assert( 1 === $required_scope['counts']['passed'] && 0 === $required_scope['counts']['skipped'], 'Required group skipped count must ignore baseline/optional entries from other groups.' );
$baseline_scope = $fast_runner->run( array( 'group' => 'baseline' ) );
shipment_regression_profile_assert( 0 === $baseline_scope['counts']['passed'] && 1 === $baseline_scope['counts']['skipped'], 'Baseline group without include flag must count only baseline entries in that group as skipped.' );
$optional_scope = $fast_runner->run( array( 'group' => 'optional' ) );
shipment_regression_profile_assert( 0 === $optional_scope['counts']['passed'] && 1 === $optional_scope['counts']['skipped'], 'Optional group without include flag must count only optional entries in that group as skipped.' );

$infrastructure_manifest = array(
	'required.infrastructure' => array( 'path' => 'tests/shipments/run-shipment-lifecycle-contract-smoke.php', 'groups' => array( 'infra' ) ),
	'baseline.infrastructure' => array( 'path' => 'tests/shipments/run-shipment-lifecycle-contract-smoke.php', 'groups' => array( 'infra' ), 'required' => false, 'baseline' => true, 'expected_failure' => 'expected baseline signature' ),
	'optional.infrastructure' => array( 'path' => 'tests/shipments/run-shipment-lifecycle-contract-smoke.php', 'groups' => array( 'infra' ), 'required' => false, 'optional' => true, 'expected_failure' => 'optional signature' ),
);
$infrastructure_runner = new ShipmentRegressionRunner(
	$project_root,
	$infrastructure_manifest,
	static fn( string $path, int $timeout ): array => array(
		'exit_code' => ShipmentRegressionRunner::EXIT_INFRASTRUCTURE,
		'stdout' => '',
		'stderr' => 'Unable to start PHP process.',
		'timed_out' => false,
		'infrastructure_error' => true,
	)
);
$infrastructure_result = $infrastructure_runner->run( array( 'group' => 'infra', 'include_baseline' => true, 'include_optional' => true ) );
$infrastructure_statuses = array_column( $infrastructure_result['results'], 'status', 'id' );
shipment_regression_profile_assert( array( 'required.infrastructure' => 'INFRASTRUCTURE', 'baseline.infrastructure' => 'INFRASTRUCTURE', 'optional.infrastructure' => 'INFRASTRUCTURE' ) === $infrastructure_statuses, 'Infrastructure failures must not be classified as FAIL or baseline mismatch.' );
shipment_regression_profile_assert( ShipmentRegressionRunner::EXIT_INFRASTRUCTURE === $infrastructure_result['exit_code'] && 3 === $infrastructure_result['counts']['infrastructure'] && 0 === $infrastructure_result['counts']['failed'], 'Infrastructure failures must return exit 3 and use dedicated infrastructure count.' );
$infrastructure_fail_fast = $infrastructure_runner->run( array( 'group' => 'infra', 'include_baseline' => true, 'include_optional' => true, 'fail_fast' => true ) );
shipment_regression_profile_assert( ShipmentRegressionRunner::EXIT_INFRASTRUCTURE === $infrastructure_fail_fast['exit_code'] && 1 === count( $infrastructure_fail_fast['results'] ), 'Fail-fast must stop on infrastructure failure.' );

$child_exit_three_runner = new ShipmentRegressionRunner(
	$project_root,
	array( 'required.child-exit-three' => array( 'path' => 'tests/shipments/run-shipment-lifecycle-contract-smoke.php', 'groups' => array( 'child' ) ) ),
	static fn( string $path, int $timeout ): array => array(
		'exit_code' => ShipmentRegressionRunner::EXIT_INFRASTRUCTURE,
		'stdout' => '',
		'stderr' => 'test returned 3',
		'timed_out' => false,
		'infrastructure_error' => false,
	)
);
$child_exit_three_result = $child_exit_three_runner->run( array( 'group' => 'child' ) );
shipment_regression_profile_assert( 'FAIL' === $child_exit_three_result['results'][0]['status'] && ShipmentRegressionRunner::EXIT_FAILURE === $child_exit_three_result['exit_code'] && 1 === $child_exit_three_result['counts']['failed'] && 0 === $child_exit_three_result['counts']['infrastructure'], 'Child exit code 3 without infrastructure flag must remain ordinary FAIL with exit 1.' );

echo "Shipment regression profile smoke passed.\n";
