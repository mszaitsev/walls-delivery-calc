<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Shipments\Lifecycle\ShipmentLifecycleResult;
use WallsShop\WDC\Domain\Address\Address;
use WallsShop\WDC\Domain\Common\Money;
use WallsShop\WDC\Domain\Package\ShipmentPlace;
use WallsShop\WDC\Domain\Pickup\PickupPointSelection;
use WallsShop\WDC\Domain\Quote\DeliveryType;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;

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
	continuation_token: 'token-1',
	message: 'Ждём регистрацию DPD.',
	poll_interval_ms: 10000,
	poll_max_attempts: 0,
	stop_on_error: true
);
$submission_array = $submission->to_array();
shipment_lifecycle_assert( 'submission_required' === $submission_array['phase'] && true === $submission_array['submit_required'] && 'token-1' === $submission_array['continuation_token'] && ! array_key_exists( 'attempt_id', $submission_array ) && 10000 === $submission_array['poll_interval_ms'] && 0 === $submission_array['poll_max_attempts'] && true === $submission_array['stop_on_error'], 'Submission-required lifecycle must serialize neutral continuation token and polling settings.' );

$polling = new ShipmentLifecycleResult(
	ShipmentLifecycleResult::PHASE_POLLING_REQUIRED,
	accepted: true,
	submit_required: false,
	poll_required: true,
	message: 'Ждём регистрацию',
	poll_interval_ms: 10000,
	poll_max_attempts: 0,
	purpose: 'registration',
	stop_on_error: true
);
$polling_array = $polling->to_array();
shipment_lifecycle_assert( 'polling_required' === $polling_array['phase'] && true === $polling_array['poll_required'] && 'registration' === $polling_array['purpose'] && ! array_key_exists( 'poll_purpose', $polling_array ), 'Polling-required lifecycle must serialize neutral purpose settings.' );

$failed = new ShipmentLifecycleResult( ShipmentLifecycleResult::PHASE_FAILED, accepted: false, message: 'Ошибка регистрации' );
shipment_lifecycle_assert( 'failed' === $failed->to_array()['phase'] && false === $failed->to_array()['accepted'] && 'Ошибка регистрации' === $failed->to_array()['message'], 'Failed lifecycle must serialize controlled failure message.' );

$round_trip = ShipmentLifecycleResult::from_array( $submission_array )->to_array();
shipment_lifecycle_assert( $submission_array === $round_trip, 'Lifecycle array contract must round-trip without changing values.' );

$dto_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Lifecycle/ShipmentLifecycleResult.php' );
$continuation_interface_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Contracts/CarrierShipmentLifecycleContinuationInterface.php' );
$lifecycle_controller_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/Ajax/ShipmentLifecycleAjaxController.php' );
shipment_lifecycle_assert( str_contains( $dto_source, "'continuation_token'" ) && ! str_contains( $dto_source, "'attempt_id'" ) && ! str_contains( $dto_source, "'poll_purpose'" ), 'Lifecycle DTO source must serialize only transport-neutral field names.' );
shipment_lifecycle_assert( str_contains( $continuation_interface_source, '$continuation_token' ) && ! str_contains( $continuation_interface_source, '$attempt_id' ), 'Lifecycle continuation interface must use continuation_token naming.' );
shipment_lifecycle_assert( str_contains( $lifecycle_controller_source, "\$_POST['continuation_token']" ) && ! str_contains( $lifecycle_controller_source, "\$_POST['attempt_id']" ) && ! str_contains( $lifecycle_controller_source, "'poll_purpose'" ), 'Common admin lifecycle endpoint must accept continuation_token and purpose only.' );

$pickup_request = new ShipmentCreateRequest( 10, 'pickup_carrier', DeliveryType::PICKUP, 'pickup-rate', new Address(), new PickupPointSelection( 'pickup_carrier', 'service', 'PVZ-1', 'ПВЗ', '2026-08-30 12:00:00' ), array( new ShipmentPlace( 1, 1000, 20, 15, 10, Money::from_kopecks( 0 ) ) ), Money::from_kopecks( 10000 ) );
$pickup_errors = $pickup_request->validate();
shipment_lifecycle_assert( ! in_array( 'city or settlement is recommended', $pickup_errors, true ) && ! in_array( 'street and house or raw_address are required for courier delivery', $pickup_errors, true ), 'Pickup ShipmentCreateRequest validation must not apply courier recipient address requirements.' );

$courier_request = new ShipmentCreateRequest( 10, 'courier_carrier', DeliveryType::COURIER, 'courier-rate', new Address(), null, array( new ShipmentPlace( 1, 1000, 20, 15, 10, Money::from_kopecks( 0 ) ) ), Money::from_kopecks( 10000 ) );
$courier_errors = $courier_request->validate();
shipment_lifecycle_assert( in_array( 'city or settlement is recommended', $courier_errors, true ) && in_array( 'street and house or raw_address are required for courier delivery', $courier_errors, true ), 'Courier ShipmentCreateRequest validation must keep existing recipient address requirements.' );

shipment_lifecycle_expect_invalid(
	static fn (): ShipmentLifecycleResult => new ShipmentLifecycleResult( 'dpd_submission_required' ),
	'Carrier-specific lifecycle phase must be rejected.'
);
shipment_lifecycle_expect_invalid(
	static fn (): ShipmentLifecycleResult => new ShipmentLifecycleResult( ShipmentLifecycleResult::PHASE_SUBMISSION_REQUIRED, submit_required: true ),
	'Submit-required lifecycle must require continuation_token.'
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
