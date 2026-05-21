<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Admin;

use RuntimeException;
use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Fias\FiasCredentials;
use WallsShop\WDC\Locations\Fias\FiasRateLimiter;
use WallsShop\WDC\Locations\Gar\GarSyncManager;
use WallsShop\WDC\Locations\Import\FiasImportManager;
use WallsShop\WDC\Locations\Import\LocationImportService;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class LocationsAdminPage {
	private const PAGE_SLUG = 'wdc-platform-locations';
	private const NONCE_ACTION = 'wdc_locations_import_demo';
	private const NONCE_NAME = 'wdc_locations_nonce';

	public function __construct(
		private PluginEnvironment $environment,
		private LocationRepository $repository,
		private LocationSearchService $search_service,
		private LocationImportService $import_service,
		private ?FiasRateLimiter $fias_limiter = null,
		private ?GarSyncManager $gar_sync = null,
		private ?FiasImportManager $fias_import = null,
		private ?SettingsRepository $settings = null,
		private ?FiasCredentials $fias_credentials = null
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function add_menu_page(): void {
		add_submenu_page( AdminMenu::MENU_SLUG, esc_html__( 'Населенные пункты', 'walls-delivery-calc' ), esc_html__( 'Населенные пункты', 'walls-delivery-calc' ), AdminMenu::CAPABILITY, self::PAGE_SLUG, array( $this, 'render_page' ) );
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( ! str_contains( $hook_suffix, self::PAGE_SLUG ) ) {
			return;
		}

		wp_enqueue_style( 'wdc-locations-admin', $this->environment->plugin_url() . 'assets/admin/locations-admin.css', array(), $this->environment->version() );
	}

	public function render_page(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$message = $this->handle_post();
		$query   = isset( $_GET['location_query'] ) ? sanitize_text_field( wp_unslash( $_GET['location_query'] ) ) : '';
		$grouped = '' !== trim( $query ) ? $this->search_service->grouped( $query ) : array();
		?>
		<div class="wrap wdc-locations-admin">
			<h1><?php echo esc_html__( 'Населенные пункты', 'walls-delivery-calc' ); ?></h1>
			<?php if ( '' !== $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<div class="wdc-locations-summary">
				<p><strong><?php echo esc_html__( 'Населенных пунктов:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( (string) $this->repository->count_all() ); ?></span></p>
				<p><strong><?php echo esc_html__( 'Регионов/областей:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( (string) $this->repository->count_regions() ); ?></span></p>
				<p><strong><?php echo esc_html__( 'ФИАС/ГАР API-токен:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( $this->fias_credentials instanceof FiasCredentials && $this->fias_credentials->has_token() ? 'задан' : 'не задан' ); ?></span></p>
				<p><strong><?php echo esc_html__( 'Runtime-нормализация:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html__( 'временно отключена до проверки API', 'walls-delivery-calc' ); ?></span></p>
				<p><strong><?php echo esc_html__( 'Источник населенных пунктов:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html__( 'локальная база', 'walls-delivery-calc' ); ?></span></p>
				<p><strong><?php echo esc_html__( 'FIAS limiter:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( $this->limiter_label() ); ?></span></p>
				<p><strong><?php echo esc_html__( 'GAR sync:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( $this->gar_status_label() ); ?></span></p>
				<p><strong><?php echo esc_html__( 'Aliases:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( (string) $this->repository->count_aliases() ); ?></span></p>
			</div>

			<form class="wdc-locations-import" method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<button class="button" type="submit" name="wdc_locations_action" value="import_demo"><?php echo esc_html__( 'Импортировать демо-данные', 'walls-delivery-calc' ); ?></button>
				<button class="button button-primary" type="submit" name="wdc_locations_action" value="reimport_demo"><?php echo esc_html__( 'Переимпортировать демо-данные', 'walls-delivery-calc' ); ?></button>
				<button class="button" type="submit" name="wdc_locations_action" value="import_fias_prepared"><?php echo esc_html__( 'Import prepared FIAS dataset', 'walls-delivery-calc' ); ?></button>
			</form>

			<form class="wdc-locations-search" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<label>
					<span><?php echo esc_html__( 'Поиск населенных пунктов', 'walls-delivery-calc' ); ?></span>
					<input type="search" name="location_query" value="<?php echo esc_attr( $query ); ?>" placeholder="<?php echo esc_attr__( 'Новос', 'walls-delivery-calc' ); ?>">
				</label>
				<button class="button" type="submit"><?php echo esc_html__( 'Найти', 'walls-delivery-calc' ); ?></button>
			</form>

			<?php if ( '' !== trim( $query ) ) : ?>
				<div class="wdc-locations-results">
					<?php if ( array() === $grouped ) : ?>
						<p><?php echo esc_html__( 'Населенные пункты не найдены.', 'walls-delivery-calc' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $grouped as $region => $locations ) : ?>
						<section class="wdc-locations-region">
							<h2><?php echo esc_html( $region ); ?></h2>
							<?php foreach ( $locations as $location ) : ?>
								<?php $this->render_location_row( $location ); ?>
							<?php endforeach; ?>
						</section>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	private function render_location_row( Location $location ): void {
		?>
		<div class="wdc-location-row">
			<strong><?php echo esc_html( $location->display_name ); ?></strong>
			<span><?php echo esc_html( $location->postcode ); ?></span>
			<span><?php echo esc_html( $location->country_code ); ?></span>
		</div>
		<?php
	}

	private function handle_post(): string {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return '';
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) || ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return '';
		}

		$action = isset( $_POST['wdc_locations_action'] ) ? sanitize_key( wp_unslash( $_POST['wdc_locations_action'] ) ) : '';
		if ( ! in_array( $action, array( 'import_demo', 'reimport_demo', 'import_fias_prepared' ), true ) ) {
			return '';
		}

		if ( 'import_fias_prepared' === $action ) {
			if ( ! $this->fias_import instanceof FiasImportManager ) {
				return 'Prepared FIAS importer is unavailable.';
			}

			try {
				$imported = $this->fias_import->import_prepared_dataset();
			} catch ( RuntimeException $exception ) {
				return $exception->getMessage();
			}

			return sprintf( 'Imported prepared FIAS locations: %d.', $imported );
		}

		if ( 'reimport_demo' === $action ) {
			$this->repository->delete_all();
		}

		try {
			$imported = $this->import_service->import_from_json_file( $this->environment->plugin_dir() . 'database/demo/locations-demo.json' );
		} catch ( RuntimeException $exception ) {
			return $exception->getMessage();
		}

		return sprintf( __( 'Импортировано демо-населенных пунктов: %d.', 'walls-delivery-calc' ), $imported );
	}

	private function limiter_label(): string {
		if ( ! $this->fias_limiter instanceof FiasRateLimiter ) {
			return 'n/a';
		}

		$stats = $this->fias_limiter->stats();
		return sprintf( '%d/%d minute, %d/%d day', $stats['minute_count'], $stats['minute_limit'], $stats['day_count'], $stats['daily_limit'] );
	}

	private function gar_status_label(): string {
		if ( ! $this->gar_sync instanceof GarSyncManager ) {
			return 'n/a';
		}

		$status = $this->gar_sync->status();
		if ( array() === $status ) {
			return 'not checked';
		}

		return ! empty( $status['pending'] ) ? 'pending changes detected' : 'no pending changes';
	}
}
