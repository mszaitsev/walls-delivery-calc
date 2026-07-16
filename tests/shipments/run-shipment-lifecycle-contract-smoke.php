<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Shipments\Lifecycle\ShipmentLifecycleResult;

function shipment_lifecycle_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function shipment_lifecycle_expect_invalid( callable $callback, string $message ): void {
	try {
		$callback();
		throw new RuntimeException( $message );
	} catch ( InvalidArgumentException $exception ) {
		shipment_lifecycle_assert( '' !== $exception->getMessage(), $message );
	}
}

$completed = new ShipmentLifecycleResult( ShipmentLifecycleResult::PHASE_COMPLETED );
shipment_lifecycle_assert( 'completed' === $completed->to_array()['phase'] && false === $completed->to_array()['submit_required'] && false === $completed->to_array()['poll_required'], 'Completed lifecycle must not require submit or polling.' );

$submission = new ShipmentLifecycleResult(
	ShipmentLifecycleResult::PHASE_SUBMISSION_REQUIRED,
	accepted: true,
	submit_required: true,
	poll_required: false,
	attempt_id: 'attempt-1',
	message: 'Ждём регистрацию DPD.',
	poll_interval_ms: 10000,
	poll_max_attempts: 0,
	stop_on_error: true
);
$submission_array = $submission->to_array();
shipment_lifecycle_assert( 'submission_required' === $submission_array['phase'] && true === $submission_array['submit_required'] && 'attempt-1' === $submission_array['attempt_id'] && 10000 === $submission_array['poll_interval_ms'] && 0 === $submission_array['poll_max_attempts'] && true === $submission_array['stop_on_error'], 'Submission-required lifecycle must serialize neutral submit and polling settings.' );

$polling = new ShipmentLifecycleResult(
	ShipmentLifecycleResult::PHASE_POLLING_REQUIRED,
	accepted: true,
	submit_required: false,
	poll_required: true,
	message: 'Ждём регистрацию',
	poll_interval_ms: 10000,
	poll_max_attempts: 0,
	poll_purpose: 'registration',
	stop_on_error: true
);
shipment_lifecycle_assert( 'polling_required' === $polling->to_array()['phase'] && true === $polling->to_array()['poll_required'] && 'registration' === $polling->to_array()['poll_purpose'], 'Polling-required lifecycle must serialize neutral polling settings.' );

$failed = new ShipmentLifecycleResult( ShipmentLifecycleResult::PHASE_FAILED, accepted: false, message: 'Ошибка регистрации' );
shipment_lifecycle_assert( 'failed' === $failed->to_array()['phase'] && false === $failed->to_array()['accepted'] && 'Ошибка регистрации' === $failed->to_array()['message'], 'Failed lifecycle must serialize controlled failure message.' );

$round_trip = ShipmentLifecycleResult::from_array( $submission_array )->to_array();
shipment_lifecycle_assert( $submission_array === $round_trip, 'Lifecycle array contract must round-trip without changing values.' );

shipment_lifecycle_expect_invalid(
	static fn (): ShipmentLifecycleResult => new ShipmentLifecycleResult( 'dpd_submission_required' ),
	'Carrier-specific lifecycle phase must be rejected.'
);
shipment_lifecycle_expect_invalid(
	static fn (): ShipmentLifecycleResult => new ShipmentLifecycleResult( ShipmentLifecycleResult::PHASE_SUBMISSION_REQUIRED, submit_required: true ),
	'Submit-required lifecycle must require attempt_id.'
);
shipment_lifecycle_expect_invalid(
	static fn (): ShipmentLifecycleResult => new ShipmentLifecycleResult( ShipmentLifecycleResult::PHASE_POLLING_REQUIRED, poll_required: true, poll_interval_ms: -1 ),
	'Negative poll interval must be rejected.'
);
shipment_lifecycle_expect_invalid(
	static fn (): ShipmentLifecycleResult => new ShipmentLifecycleResult( ShipmentLifecycleResult::PHASE_POLLING_REQUIRED, poll_required: true, poll_max_attempts: -1 ),
	'Negative max attempts must be rejected.'
);

echo "Shipment lifecycle contract smoke passed.\n";
