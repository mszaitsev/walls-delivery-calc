<?php
declare(strict_types=1);

namespace WallsShop\WDC\Infrastructure\Queue;

use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class ActionScheduler {
	private Logger $logger;

	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	/**
	 * @param array<int|string, mixed> $args
	 */
	public function schedule_single( int $timestamp, string $hook, array $args = array(), string $group = 'walls-delivery-calc' ): ?int {
		if ( ! $this->available( __METHOD__ ) ) {
			return null;
		}

		$action_id = as_schedule_single_action( $timestamp, $hook, $args, $group );

		return is_numeric( $action_id ) ? (int) $action_id : null;
	}

	/**
	 * @param array<int|string, mixed> $args
	 */
	public function schedule_recurring( int $timestamp, int $interval, string $hook, array $args = array(), string $group = 'walls-delivery-calc' ): ?int {
		if ( ! $this->available( __METHOD__ ) ) {
			return null;
		}

		$action_id = as_schedule_recurring_action( $timestamp, $interval, $hook, $args, $group );

		return is_numeric( $action_id ) ? (int) $action_id : null;
	}

	/**
	 * @param array<int|string, mixed> $args
	 */
	public function unschedule( string $hook, array $args = array(), string $group = 'walls-delivery-calc' ): void {
		if ( ! $this->available( __METHOD__ ) ) {
			return;
		}

		as_unschedule_all_actions( $hook, $args, $group );
	}

	/**
	 * @param array<int|string, mixed> $args
	 */
	public function has_scheduled( string $hook, array $args = array(), string $group = 'walls-delivery-calc' ): bool {
		if ( ! $this->available( __METHOD__ ) ) {
			return false;
		}

		return (bool) as_has_scheduled_action( $hook, $args, $group );
	}

	private function available( string $method ): bool {
		$available = function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_unschedule_all_actions' )
			&& function_exists( 'as_has_scheduled_action' );

		if ( ! $available ) {
			$this->logger->warning( 'Action Scheduler is unavailable.', array( 'method' => $method ) );
		}

		return $available;
	}
}
