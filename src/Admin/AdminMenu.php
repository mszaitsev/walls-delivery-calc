<?php
declare(strict_types=1);

namespace WallsShop\WDC\Admin;

use WallsShop\WDC\Core\FeatureFlags;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Core\RequirementsChecker;

defined( 'ABSPATH' ) || exit;

final class AdminMenu {
	private PluginEnvironment $environment;

	private FeatureFlags $feature_flags;

	private RequirementsChecker $requirements;

	public function __construct( PluginEnvironment $environment, FeatureFlags $feature_flags, RequirementsChecker $requirements ) {
		$this->environment   = $environment;
		$this->feature_flags = $feature_flags;
		$this->requirements  = $requirements;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	public function add_menu_page(): void {
		add_submenu_page(
			'woocommerce',
			esc_html__( 'WDC Platform', 'walls-delivery-calc' ),
			esc_html__( 'WDC Platform', 'walls-delivery-calc' ),
			'manage_options',
			'wdc-platform',
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WDC Platform', 'walls-delivery-calc' ); ?></h1>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<?php $this->render_row( 'Plugin version', $this->environment->version() ); ?>
					<?php $this->render_row( 'PHP version', PHP_VERSION ); ?>
					<?php $this->render_row( 'WooCommerce version', '' !== $this->environment->wc_version() ? $this->environment->wc_version() : 'unknown' ); ?>
					<?php $this->render_row( 'HPOS status', $this->environment->hpos_enabled() ? 'enabled' : 'disabled' ); ?>
					<?php $this->render_row( 'Action Scheduler status', function_exists( 'as_schedule_single_action' ) ? 'available' : 'missing' ); ?>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Feature flags', 'walls-delivery-calc' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<?php foreach ( $this->feature_flags->all() as $flag => $enabled ) : ?>
						<?php $this->render_row( $flag, $enabled ? 'true' : 'false' ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Requirements', 'walls-delivery-calc' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<?php foreach ( $this->requirements->checks() as $check ) : ?>
						<?php $this->render_row( $check['label'], $check['ok'] ? 'ok' : $check['actual'] . ' / required ' . $check['required'] ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	private function render_row( string $label, string $value ): void {
		?>
		<tr>
			<th scope="row"><?php echo esc_html( $label ); ?></th>
			<td><?php echo esc_html( $value ); ?></td>
		</tr>
		<?php
	}
}
