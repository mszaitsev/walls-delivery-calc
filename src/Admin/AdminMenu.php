<?php
declare(strict_types=1);

namespace WallsShop\WDC\Admin;

use WallsShop\WDC\Core\FeatureFlags;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Core\RequirementsChecker;

defined( 'ABSPATH' ) || exit;

final class AdminMenu {
	public const MENU_SLUG = 'wdc-platform';
	public const CAPABILITY = 'manage_woocommerce';

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
		add_menu_page(
			esc_html__( 'Калькулятор доставок', 'walls-delivery-calc' ),
			esc_html__( 'Калькулятор доставок', 'walls-delivery-calc' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' ),
			'dashicons-location-alt',
			56
		);

		add_submenu_page(
			self::MENU_SLUG,
			esc_html__( 'Обзор', 'walls-delivery-calc' ),
			esc_html__( 'Обзор', 'walls-delivery-calc' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}

		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Калькулятор доставок', 'walls-delivery-calc' ); ?></h1>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<?php $this->render_row( __( 'Версия плагина', 'walls-delivery-calc' ), $this->environment->version() ); ?>
					<?php $this->render_row( __( 'Версия PHP', 'walls-delivery-calc' ), PHP_VERSION ); ?>
					<?php $this->render_row( __( 'Версия WooCommerce', 'walls-delivery-calc' ), '' !== $this->environment->wc_version() ? $this->environment->wc_version() : __( 'не определена', 'walls-delivery-calc' ) ); ?>
					<?php $this->render_row( __( 'Статус HPOS', 'walls-delivery-calc' ), $this->environment->hpos_enabled() ? __( 'включен', 'walls-delivery-calc' ) : __( 'выключен', 'walls-delivery-calc' ) ); ?>
					<?php $this->render_row( __( 'Статус Action Scheduler', 'walls-delivery-calc' ), function_exists( 'as_schedule_single_action' ) ? __( 'доступен', 'walls-delivery-calc' ) : __( 'не найден', 'walls-delivery-calc' ) ); ?>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Флаги функций', 'walls-delivery-calc' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<?php foreach ( $this->feature_flags->all() as $flag => $enabled ) : ?>
						<?php $this->render_row( $flag, $enabled ? 'true' : 'false' ); ?>
					<?php endforeach; ?>
				</tbody>
			</table>

			<h2><?php echo esc_html__( 'Требования', 'walls-delivery-calc' ); ?></h2>
			<table class="widefat striped" style="max-width: 760px;">
				<tbody>
					<?php foreach ( $this->requirements->checks() as $check ) : ?>
						<?php $this->render_row( $check['label'], $check['ok'] ? __( 'ок', 'walls-delivery-calc' ) : $check['actual'] . ' / ' . __( 'требуется', 'walls-delivery-calc' ) . ' ' . $check['required'] ); ?>
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
