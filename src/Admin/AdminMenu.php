<?php
declare(strict_types=1);

namespace WallsShop\WDC\Admin;

use WallsShop\WDC\Checkout\Cache\DeliveryQuoteCacheManager;
use WallsShop\WDC\Core\FeatureFlags;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Core\RequirementsChecker;

defined( 'ABSPATH' ) || exit;

final class AdminMenu {
	public const MENU_SLUG = 'wdc-platform';
	public const CAPABILITY = 'manage_woocommerce';
	private const NONCE_ACTION = 'wdc_clear_delivery_quote_cache';
	private const NONCE_NAME = 'wdc_clear_delivery_quote_cache_nonce';
	private const POST_ACTION = 'clear_delivery_quote_cache';

	private PluginEnvironment $environment;

	private FeatureFlags $feature_flags;

	private RequirementsChecker $requirements;

	public function __construct(
		PluginEnvironment $environment,
		FeatureFlags $feature_flags,
		RequirementsChecker $requirements,
		private ?DeliveryQuoteCacheManager $quote_cache_manager = null
	) {
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

		$message = $this->handle_post();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Калькулятор доставок', 'walls-delivery-calc' ); ?></h1>
			<?php if ( '' !== $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>
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

			<h2><?php echo esc_html__( 'Очистка кеша доставки', 'walls-delivery-calc' ); ?></h2>
			<p><?php echo esc_html__( 'Удаляет кеш рассчитанных тарифов доставки. Полезно при тестировании изменений тарифов и индексов.', 'walls-delivery-calc' ); ?></p>
			<form method="post">
				<input type="hidden" name="wdc_overview_action" value="<?php echo esc_attr( self::POST_ACTION ); ?>">
				<input type="hidden" name="<?php echo esc_attr( self::NONCE_NAME ); ?>" value="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>">
				<button class="button button-secondary" type="submit"><?php echo esc_html__( 'Очистить кеш тарифов доставки', 'walls-delivery-calc' ); ?></button>
			</form>
		</div>
		<?php
	}

	private function handle_post(): string {
		if ( 'POST' !== (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) ) {
			return '';
		}
		$action = isset( $_POST['wdc_overview_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['wdc_overview_action'] ) ) : '';
		if ( self::POST_ACTION !== $action ) {
			return '';
		}
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return '';
		}
		$nonce = isset( $_POST[ self::NONCE_NAME ] ) ? (string) wp_unslash( $_POST[ self::NONCE_NAME ] ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			return '';
		}

		$deleted = $this->quote_cache_manager instanceof DeliveryQuoteCacheManager ? $this->quote_cache_manager->clear_all_quote_cache() : 0;

		return sprintf( __( 'Кеш тарифов доставки очищен. Удалено записей: %d', 'walls-delivery-calc' ), $deleted );
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
