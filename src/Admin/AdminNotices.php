<?php
declare(strict_types=1);

namespace WallsShop\WDC\Admin;

use WallsShop\WDC\Core\RequirementsChecker;

defined( 'ABSPATH' ) || exit;

final class AdminNotices {
	private RequirementsChecker $requirements;

	public function __construct( RequirementsChecker $requirements ) {
		$this->requirements = $requirements;
	}

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	public function render(): void {
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
}
