<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Admin;

use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Pickup\Services\DemoPickupProvider;
use WallsShop\WDC\Pickup\Storage\PickupPointRepository;

defined( 'ABSPATH' ) || exit;

final class PickupAdminPage {
	private const PAGE_SLUG = 'wdc-platform-pickup';
	private const NONCE_ACTION = 'wdc_pickup_import_demo';
	private const NONCE_NAME = 'wdc_pickup_nonce';

	public function __construct(
		private PluginEnvironment $environment,
		private PickupPointRepository $repository,
		private DemoPickupProvider $provider
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	public function add_menu_page(): void {
		add_submenu_page( 'woocommerce', esc_html__( 'WDC Pickup', 'walls-delivery-calc' ), esc_html__( 'WDC Pickup', 'walls-delivery-calc' ), 'manage_options', self::PAGE_SLUG, array( $this, 'render_page' ) );
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$message = $this->handle_post();
		$city    = isset( $_GET['pickup_city'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['pickup_city'] ) ) : '';
		$points  = '' !== trim( $city ) ? $this->repository->search( 'demo', 'RU', $city ) : array();
		$grouped = $this->group_by_city( $points );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'WDC Pickup', 'walls-delivery-calc' ); ?></h1>
			<?php if ( '' !== $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<p><strong><?php echo esc_html__( 'Pickup points count:', 'walls-delivery-calc' ); ?></strong> <?php echo esc_html( (string) $this->repository->count_all() ); ?></p>

			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<button class="button" type="submit" name="wdc_pickup_action" value="import_demo"><?php echo esc_html__( 'Import demo pickup points', 'walls-delivery-calc' ); ?></button>
				<button class="button button-primary" type="submit" name="wdc_pickup_action" value="reimport_demo"><?php echo esc_html__( 'Reimport demo pickup points', 'walls-delivery-calc' ); ?></button>
			</form>

			<form method="get" style="margin-top: 16px;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<label>
					<span><?php echo esc_html__( 'Search by city', 'walls-delivery-calc' ); ?></span>
					<input type="search" name="pickup_city" value="<?php echo esc_attr( $city ); ?>" placeholder="Novosibirsk">
				</label>
				<button class="button" type="submit"><?php echo esc_html__( 'Search', 'walls-delivery-calc' ); ?></button>
			</form>

			<?php foreach ( $grouped as $group_city => $city_points ) : ?>
				<h2><?php echo esc_html( $group_city ); ?></h2>
				<table class="widefat striped">
					<thead><tr><th><?php echo esc_html__( 'Code', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Address', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Work time', 'walls-delivery-calc' ); ?></th></tr></thead>
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

	private function handle_post(): string {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return '';
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) || ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$action = isset( $_POST['wdc_pickup_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['wdc_pickup_action'] ) ) : '';
		if ( ! in_array( $action, array( 'import_demo', 'reimport_demo' ), true ) ) {
			return '';
		}

		if ( 'reimport_demo' === $action ) {
			$this->repository->delete_all();
		}

		$count = $this->repository->save_many( $this->provider->load_points() );

		return sprintf( __( 'Demo pickup points imported: %d.', 'walls-delivery-calc' ), $count );
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
