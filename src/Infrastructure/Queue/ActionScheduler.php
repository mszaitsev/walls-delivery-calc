<?php
declare(strict_types=1);

namespace WallsShop\WDC\Infrastructure\Queue;

use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class ActionScheduler {
	public const STATE_UNAVAILABLE = 'unavailable';
	public const STATE_PENDING = 'pending';
	public const STATE_READY = 'ready';

	private Logger $logger;
	/** @var array<string,callable> */
	private array $ready_callbacks = array();
	/** @var array<string,true> */
	private array $completed_callbacks = array();
	/** @var array<string,true> */
	private array $logged_unavailable_methods = array();
	private bool $lifecycle_hooks_registered = false;
	private bool $initialization_window_closed = false;

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

	/**
	 * @param array<int|string, mixed> $args
	 */
	public function next_scheduled( string $hook, array $args = array(), string $group = 'walls-delivery-calc' ): ?int {
		if ( ! $this->available( __METHOD__ ) ) {
			return null;
		}

		$timestamp = as_next_scheduled_action( $hook, $args, $group );

		return is_numeric( $timestamp ) && (int) $timestamp > 0 ? (int) $timestamp : null;
	}

	public function when_initialized( string $owner, callable $callback ): void {
		if ( isset( $this->completed_callbacks[ $owner ] ) ) {
			return;
		}

		if ( self::STATE_READY === $this->readiness() ) {
			$this->completed_callbacks[ $owner ] = true;
			$callback();
			return;
		}

		$this->ready_callbacks[ $owner ] = $callback;
		$this->register_lifecycle_hooks();
		$this->warn_if_unavailable( __METHOD__ );
	}

	public function readiness(): string {
		if ( $this->api_functions_loaded()
			&& class_exists( '\\ActionScheduler' )
			&& method_exists( '\\ActionScheduler', 'is_initialized' )
			&& \ActionScheduler::is_initialized()
		) {
			return self::STATE_READY;
		}

		$init_completed = function_exists( 'did_action' )
			&& did_action( 'init' ) > 0
			&& ( ! function_exists( 'doing_action' ) || ! doing_action( 'init' ) );

		return $this->initialization_window_closed || $init_completed
			? self::STATE_UNAVAILABLE
			: self::STATE_PENDING;
	}

	public function close_initialization_window(): void {
		$this->initialization_window_closed = true;
		$this->run_ready_callbacks();
	}

	public function run_ready_callbacks(): void {
		if ( self::STATE_READY !== $this->readiness() ) {
			$this->warn_if_unavailable( __METHOD__ );
			return;
		}

		$callbacks = $this->ready_callbacks;
		$this->ready_callbacks = array();
		foreach ( $callbacks as $owner => $callback ) {
			if ( isset( $this->completed_callbacks[ $owner ] ) ) {
				continue;
			}
			$this->completed_callbacks[ $owner ] = true;
			$callback();
		}
	}

	private function available( string $method ): bool {
		if ( self::STATE_READY === $this->readiness() ) {
			return true;
		}

		$this->warn_if_unavailable( $method );
		return false;
	}

	private function api_functions_loaded(): bool {
		return function_exists( 'as_schedule_single_action' )
			&& function_exists( 'as_schedule_recurring_action' )
			&& function_exists( 'as_unschedule_all_actions' )
			&& function_exists( 'as_has_scheduled_action' )
			&& function_exists( 'as_next_scheduled_action' );
	}

	private function register_lifecycle_hooks(): void {
		if ( $this->lifecycle_hooks_registered || ! function_exists( 'add_action' ) ) {
			return;
		}
		$this->lifecycle_hooks_registered = true;
		add_action( 'action_scheduler_init', array( $this, 'run_ready_callbacks' ) );
		add_action( 'init', array( $this, 'close_initialization_window' ), 2 );
	}

	private function warn_if_unavailable( string $method ): void {
		if ( self::STATE_UNAVAILABLE !== $this->readiness() || isset( $this->logged_unavailable_methods[ $method ] ) ) {
			return;
		}
		$this->logged_unavailable_methods[ $method ] = true;
		$this->logger->warning( 'Action Scheduler is unavailable.', array( 'method' => $method ) );
	}
}
