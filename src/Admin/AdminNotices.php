<?php
declare(strict_types=1);

namespace WallsShop\WDC\Admin;

use WallsShop\WDC\Calendar\Services\CalendarService;
use WallsShop\WDC\Core\RequirementsChecker;

defined( 'ABSPATH' ) || exit;

final class AdminNotices {
	private RequirementsChecker $requirements;

	private ?CalendarService $calendar_service;

	public function __construct( RequirementsChecker $requirements, ?CalendarService $calendar_service = null ) {
		$this->requirements     = $requirements;
		$this->calendar_service = $calendar_service;
	}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	public function render(): void {
		if ( ! $this->requirements->passes() ) {
			$this->render_requirements_notice();
		}

		$this->render_calendar_attention_notice();
	}

	private function render_requirements_notice(): void {
		if ( $this->requirements->passes() ) {
			return;
		}

		$failed = array_filter(
			$this->requirements->checks(),
			static fn( array $check ): bool => ! $check['ok']
		);

		if ( empty( $failed ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p><strong><?php echo esc_html__( 'WDC Platform requirements need attention:', 'walls-delivery-calc' ); ?></strong></p>
			<ul>
				<?php foreach ( $failed as $check ) : ?>
					<li>
						<?php
						echo esc_html(
							sprintf(
								'%1$s: %2$s (required: %3$s)',
								$check['label'],
								$check['actual'],
								$check['required']
							)
						);
						?>
					</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php
	}

	private function render_calendar_attention_notice(): void {
		if ( null === $this->calendar_service || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( empty( $this->calendar_service->attention_required() ) ) {
			return;
		}

		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php echo esc_html__( 'WDC calendar_attention_required:', 'walls-delivery-calc' ); ?></strong>
				<?php echo esc_html__( 'A next-year calendar was generated automatically. Please review and save it in WDC Calendars.', 'walls-delivery-calc' ); ?>
			</p>
		</div>
		<?php
	}
}
