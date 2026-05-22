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
use WallsShop\WDC\Locations\Import\GarPlacesCsvImporter;
use WallsShop\WDC\Locations\Import\LocationImportService;
use WallsShop\WDC\Locations\Import\LocationsSnapshotExporter;
use WallsShop\WDC\Locations\Import\LocationsSnapshotImporter;
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
		private ?FiasCredentials $fias_credentials = null,
		private ?GarPlacesCsvImporter $gar_importer = null,
		private ?LocationsSnapshotExporter $snapshot_exporter = null,
		private ?LocationsSnapshotImporter $snapshot_importer = null
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

			<form class="wdc-locations-import" method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<h2><?php echo esc_html__( 'Импорт GAR/ФИАС CSV', 'walls-delivery-calc' ); ?></h2>
				<label>
					<span><?php echo esc_html__( 'Файл gar_places.csv', 'walls-delivery-calc' ); ?></span>
					<input type="file" name="wdc_gar_places_csv" accept=".csv,text/csv">
				</label>
				<label>
					<span><?php echo esc_html__( 'Путь к CSV на сервере', 'walls-delivery-calc' ); ?></span>
					<input type="text" name="wdc_gar_places_path" placeholder="/path/to/gar_places.csv">
				</label>
				<p class="description"><?php echo esc_html__( 'Импорт заменит локальную базу населенных пунктов, регионов, алиасов и carrier mappings. Для большого файла может потребоваться увеличить лимиты PHP.', 'walls-delivery-calc' ); ?></p>
				<button class="button button-primary" type="submit" name="wdc_locations_action" value="import_gar_csv" onclick="return window.confirm('<?php echo esc_js( __( 'Импорт заменит локальную базу населенных пунктов. Продолжить?', 'walls-delivery-calc' ) ); ?>');"><?php echo esc_html__( 'Импортировать населенные пункты', 'walls-delivery-calc' ); ?></button>
				<button class="button button-secondary" type="submit" name="wdc_locations_action" value="clear_all" onclick="return window.confirm('<?php echo esc_js( __( 'Удалить все населенные пункты и алиасы из локальной базы WDC?', 'walls-delivery-calc' ) ); ?>');"><?php echo esc_html__( 'Очистить базу населенных пунктов', 'walls-delivery-calc' ); ?></button>
			</form>

			<form class="wdc-locations-import" method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<h2><?php echo esc_html__( 'Экспорт / импорт подготовленной базы', 'walls-delivery-calc' ); ?></h2>
				<button class="button" type="submit" name="wdc_locations_action" value="export_snapshot"><?php echo esc_html__( 'Экспортировать snapshot', 'walls-delivery-calc' ); ?></button>
				<label>
					<span><?php echo esc_html__( 'JSONL snapshot', 'walls-delivery-calc' ); ?></span>
					<input type="file" name="wdc_locations_snapshot" accept=".jsonl,application/x-ndjson,application/json">
				</label>
				<button class="button button-secondary" type="submit" name="wdc_locations_action" value="import_snapshot" onclick="return window.confirm('<?php echo esc_js( __( 'Импорт snapshot заменит текущую локальную базу населенных пунктов и carrier mappings. Продолжить?', 'walls-delivery-calc' ) ); ?>');"><?php echo esc_html__( 'Импортировать snapshot', 'walls-delivery-calc' ); ?></button>
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
			<span><?php echo esc_html( '' !== $location->postal_code ? $location->postal_code : $location->postcode ); ?></span>
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
		if ( ! in_array( $action, array( 'import_gar_csv', 'clear_all', 'export_snapshot', 'import_snapshot' ), true ) ) {
			return '';
		}

		if ( 'clear_all' === $action ) {
			try {
				$stats = $this->repository->clear_all();
			} catch ( RuntimeException ) {
				return __( 'Не удалось очистить базу населенных пунктов. Подробности см. в логах.', 'walls-delivery-calc' );
			}

			return sprintf(
				__( 'База населенных пунктов очищена. Удалено: населенных пунктов — %s, алиасов — %s, регионов — %s, carrier mappings — %s.', 'walls-delivery-calc' ),
				$this->deleted_count_label( $stats['locations_deleted'] ),
				$this->deleted_count_label( $stats['aliases_deleted'] ),
				$this->deleted_count_label( $stats['regions_deleted'] ),
				$this->deleted_count_label( $stats['carrier_codes_deleted'] )
			);
		}

		if ( 'export_snapshot' === $action ) {
			if ( $this->snapshot_exporter instanceof LocationsSnapshotExporter ) {
				$this->snapshot_exporter->stream_download( $this->environment->version() );
				exit;
			}

			return __( 'Snapshot exporter is unavailable.', 'walls-delivery-calc' );
		}

		if ( 'import_snapshot' === $action ) {
			if ( ! $this->snapshot_importer instanceof LocationsSnapshotImporter ) {
				return __( 'Snapshot importer is unavailable.', 'walls-delivery-calc' );
			}

			$file = $this->uploaded_file_path( 'wdc_locations_snapshot' );
			if ( '' === $file ) {
				return __( 'Выберите snapshot-файл для импорта.', 'walls-delivery-calc' );
			}

			try {
				$imported = $this->snapshot_importer->import_from_file( $file );
			} catch ( RuntimeException $exception ) {
				return $exception->getMessage();
			}

			return sprintf( __( 'Snapshot импортирован. Строк: %d.', 'walls-delivery-calc' ), $imported );
		}

		if ( ! $this->gar_importer instanceof GarPlacesCsvImporter ) {
			return __( 'GAR CSV importer is unavailable.', 'walls-delivery-calc' );
		}

		$path = isset( $_POST['wdc_gar_places_path'] ) ? sanitize_text_field( wp_unslash( $_POST['wdc_gar_places_path'] ) ) : '';
		if ( '' === $path ) {
			$path = $this->uploaded_file_path( 'wdc_gar_places_csv' );
		}

		if ( '' === $path ) {
			return __( 'Укажите путь к gar_places.csv или загрузите файл.', 'walls-delivery-calc' );
		}

		$this->repository->clear_all();
		$result = $this->gar_importer->import_from_file( $path );
		if ( ! $result->success ) {
			return implode( ' ', $result->errors );
		}

		return sprintf(
			__( 'GAR CSV импортирован. Прочитано: %1$d, staging: %2$d, регионов: %3$d, населенных пунктов: %4$d, алиасов: %5$d, пропущено: %6$d.', 'walls-delivery-calc' ),
			$result->rows_read,
			$result->stage_rows,
			$result->regions_imported,
			$result->locations_imported,
			$result->aliases_imported,
			$result->skipped_rows
		);
	}

	private function uploaded_file_path( string $field ): string {
		if ( empty( $_FILES[ $field ]['tmp_name'] ) || ! is_string( $_FILES[ $field ]['tmp_name'] ) ) {
			return '';
		}

		return sanitize_text_field( wp_unslash( $_FILES[ $field ]['tmp_name'] ) );
	}

	private function limiter_label(): string {
		if ( ! $this->fias_limiter instanceof FiasRateLimiter ) {
			return 'n/a';
		}

		$stats = $this->fias_limiter->stats();
		return sprintf( '%d/%d minute, %d/%d day', $stats['minute_count'], $stats['minute_limit'], $stats['day_count'], $stats['daily_limit'] );
	}

	private function deleted_count_label( ?int $count ): string {
		return null === $count ? '0' : (string) $count;
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
