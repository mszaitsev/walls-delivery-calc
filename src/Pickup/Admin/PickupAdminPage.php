<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupPointRepository;
use WallsShop\WDC\Pickup\Storage\PickupPointRepository;

defined( 'ABSPATH' ) || exit;

final class PickupAdminPage {
	private const PAGE_SLUG = 'wdc-platform-pickup';
	private const DEMO_CARRIER_KEYS = array( 'demo' );
	private const DEMO_PICKUP_CLEANUP_OPTION = 'wdc_demo_pickup_cleanup_done';

	public function __construct(
		private PluginEnvironment $environment,
		private PickupPointRepository $repository,
		private ?RussianPostPickupPointRepository $russian_post_repository = null,
		private ?RussianPostOtpravkaApiSettings $russian_post_settings = null
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	public function add_menu_page(): void {
		add_submenu_page( AdminMenu::MENU_SLUG, esc_html__( 'ПВЗ', 'walls-delivery-calc' ), esc_html__( 'ПВЗ', 'walls-delivery-calc' ), AdminMenu::CAPABILITY, self::PAGE_SLUG, array( $this, 'render_page' ) );
	}

	public function render_page(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$cleanup_count = null;
		if ( ! (bool) get_option( self::DEMO_PICKUP_CLEANUP_OPTION, false ) ) {
			$cleanup_count = $this->repository->delete_by_carrier_keys( self::DEMO_CARRIER_KEYS );
			update_option( self::DEMO_PICKUP_CLEANUP_OPTION, true, false );
		}

		$city    = isset( $_GET['pickup_city'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['pickup_city'] ) ) : '';
		$carrier = isset( $_GET['pickup_carrier'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['pickup_carrier'] ) ) : '';
		$points  = '' !== trim( $city ) && '' !== trim( $carrier ) ? $this->repository->search( $carrier, 'RU', $city ) : array();
		$grouped = $this->group_by_city( $points );
		?>
		<div class="wrap">
			<?php if ( null !== $cleanup_count ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Демо-данные ПВЗ очищены: %d', 'walls-delivery-calc' ), $cleanup_count ) ); ?></p></div>
			<?php endif; ?>
			<h1><?php echo esc_html__( 'Пункты выдачи заказов', 'walls-delivery-calc' ); ?></h1>
			<p><strong><?php echo esc_html__( 'Количество ПВЗ:', 'walls-delivery-calc' ); ?></strong> <?php echo esc_html( (string) $this->repository->count_all() ); ?></p>

			<?php $rp_counts = $this->russian_post_repository instanceof RussianPostPickupPointRepository ? $this->russian_post_repository->count_by_type() : array(); ?>
			<p><strong>Почта России active:</strong> <?php echo esc_html( (string) ( $this->russian_post_repository instanceof RussianPostPickupPointRepository ? $this->russian_post_repository->count_active() : 0 ) ); ?>; OPS: <?php echo esc_html( (string) ( $rp_counts['OPS'] ?? 0 ) ); ?>, PVZ: <?php echo esc_html( (string) ( $rp_counts['PVZ'] ?? 0 ) ); ?>, APS: <?php echo esc_html( (string) ( $rp_counts['APS'] ?? 0 ) ); ?><?php if ( $this->russian_post_settings instanceof RussianPostOtpravkaApiSettings ) : ?>; last import: <?php echo esc_html( $this->russian_post_settings->last_success_at() ?: '-' ); ?><?php endif; ?></p>

			<form method="get" style="margin-top: 16px;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<label>
					<span><?php echo esc_html__( 'Ключ перевозчика', 'walls-delivery-calc' ); ?></span>
					<input type="search" name="pickup_carrier" value="<?php echo esc_attr( $carrier ); ?>" placeholder="russian_post">
				</label>
				<label>
					<span><?php echo esc_html__( 'Поиск по городу', 'walls-delivery-calc' ); ?></span>
					<input type="search" name="pickup_city" value="<?php echo esc_attr( $city ); ?>" placeholder="Новосибирск">
				</label>
				<button class="button" type="submit"><?php echo esc_html__( 'Найти', 'walls-delivery-calc' ); ?></button>
			</form>

			<?php foreach ( $grouped as $group_city => $city_points ) : ?>
				<h2><?php echo esc_html( $group_city ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th><?php echo esc_html__( 'Код', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Адрес', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Время работы', 'walls-delivery-calc' ); ?></th></tr></thead>
					<tbody>
						<?php foreach ( $city_points as $point ) : ?>
							<tr><td><?php echo esc_html( $point->code ); ?></td><td><?php echo esc_html( $point->address ); ?></td><td><?php echo esc_html( $point->work_time ); ?></td></tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * @param array<int,PickupPoint> $points
	 * @return array<string,array<int,PickupPoint>>
	 */
	private function group_by_city( array $points ): array {
		$grouped = array();
		foreach ( $points as $point ) {
			$grouped[ $point->city ][] = $point;
		}
		ksort( $grouped );

		return $grouped;
	}
}
