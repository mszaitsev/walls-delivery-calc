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
	private const GAR_JOB_OPTION = 'wdc_gar_import_job';
	private const SNAPSHOT_EXPORT_JOB_OPTION = 'wdc_locations_snapshot_export_job';
	private const SNAPSHOT_IMPORT_JOB_OPTION = 'wdc_locations_snapshot_import_job';

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
		add_action( 'wp_ajax_wdc_gar_import_start', array( $this, 'ajax_gar_import_start' ) );
		add_action( 'wp_ajax_wdc_gar_import_step', array( $this, 'ajax_gar_import_step' ) );
		add_action( 'wp_ajax_wdc_gar_import_status', array( $this, 'ajax_gar_import_status' ) );
		add_action( 'wp_ajax_wdc_gar_import_cancel', array( $this, 'ajax_gar_import_cancel' ) );
		add_action( 'wp_ajax_wdc_locations_snapshot_export_start', array( $this, 'ajax_snapshot_export_start' ) );
		add_action( 'wp_ajax_wdc_locations_snapshot_export_step', array( $this, 'ajax_snapshot_export_step' ) );
		add_action( 'wp_ajax_wdc_locations_snapshot_import_start', array( $this, 'ajax_snapshot_import_start' ) );
		add_action( 'wp_ajax_wdc_locations_snapshot_import_step', array( $this, 'ajax_snapshot_import_step' ) );
		add_action( 'wp_ajax_wdc_location_details', array( $this, 'ajax_location_details' ) );
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

			<form id="wdc-gar-import-form" class="wdc-locations-import" method="post" enctype="multipart/form-data">
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
				<p class="description"><?php echo esc_html__( 'Поддерживается структура region_* → district_* → city_* → place_*. Поля district_* и city_* необязательны, неизвестные колонки игнорируются. Импорт заменит локальную базу населенных пунктов, регионов, алиасов и carrier mappings.', 'walls-delivery-calc' ); ?></p>
				<button class="button button-primary" type="button" id="wdc-gar-import-start"><?php echo esc_html__( 'Начать импорт', 'walls-delivery-calc' ); ?></button>
				<button class="button button-secondary" type="submit" name="wdc_locations_action" value="clear_all" onclick="return window.confirm('<?php echo esc_js( __( 'Удалить все населенные пункты и алиасы из локальной базы WDC?', 'walls-delivery-calc' ) ); ?>');"><?php echo esc_html__( 'Очистить базу населенных пунктов', 'walls-delivery-calc' ); ?></button>
				<div id="wdc-gar-import-progress" class="wdc-progress" hidden><progress value="0" max="100"></progress><pre></pre></div>
			</form>

			<form id="wdc-snapshot-form" class="wdc-locations-import" method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<h2><?php echo esc_html__( 'Экспорт / импорт подготовленной базы', 'walls-delivery-calc' ); ?></h2>
				<button class="button" type="button" id="wdc-snapshot-export-start"><?php echo esc_html__( 'Экспортировать snapshot', 'walls-delivery-calc' ); ?></button>
				<label>
					<span><?php echo esc_html__( 'JSONL snapshot', 'walls-delivery-calc' ); ?></span>
					<input type="file" name="wdc_locations_snapshot" accept=".jsonl,application/x-ndjson,application/json">
				</label>
				<button class="button button-secondary" type="button" id="wdc-snapshot-import-start"><?php echo esc_html__( 'Импортировать snapshot', 'walls-delivery-calc' ); ?></button>
				<div id="wdc-snapshot-progress" class="wdc-progress" hidden><progress value="0" max="100"></progress><pre></pre></div>
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
		<?php $this->render_progress_script(); ?>
		<?php
	}

	private function render_location_row( Location $location ): void {
		?>
		<div class="wdc-location-row">
			<strong><?php echo esc_html( $location->display_name ); ?></strong>
			<span><?php echo esc_html( $location->postal_code ); ?></span>
			<span><?php echo esc_html( $location->country_code ); ?></span>
			<button class="button button-small wdc-location-details-toggle" type="button" data-location-id="<?php echo esc_attr( (string) $location->id ); ?>"><?php echo esc_html__( 'Детали', 'walls-delivery-calc' ); ?></button>
			<div class="wdc-location-details" hidden></div>
		</div>
		<?php
	}

	private function render_progress_script(): void {
		$ajax_url = function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : 'admin-ajax.php';
		$nonce = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( self::NONCE_ACTION ) : 'test-nonce';
		?>
		<script>
		(function(){
			const ajaxUrl = <?php echo json_encode( $ajax_url ); ?>;
			const nonce = <?php echo json_encode( $nonce ); ?>;
			function post(action, data) {
				data = data || new FormData();
				data.append('action', action);
				data.append('<?php echo esc_js( self::NONCE_NAME ); ?>', nonce);
				return fetch(ajaxUrl, {method:'POST', body:data, credentials:'same-origin'}).then(r => r.json());
			}
			function render(box, job) {
				if (!box || !job) return;
				box.hidden = false;
				const progress = box.querySelector('progress');
				const text = box.querySelector('pre');
				const total = Number(job.rows_total_estimated || job.total_rows || job.stage_rows || 1);
				const done = Number(job.processed_rows || job.rows_exported || job.imported || job.rows_read || 0);
				progress.value = Math.min(100, Math.round(done / Math.max(1, total) * 100));
				text.textContent = JSON.stringify(job, null, 2);
			}
			function loop(action, box) {
				post(action).then(resp => {
					const job = resp && resp.data ? resp.data : {};
					render(box, job);
					if (job.phase && job.phase !== 'finished' && job.phase !== 'failed') {
						window.setTimeout(() => loop(action, box), 250);
					}
				});
			}
			const garStart = document.getElementById('wdc-gar-import-start');
			if (garStart) garStart.addEventListener('click', function(){
				if (!window.confirm('<?php echo esc_js( __( 'Импорт заменит локальную базу населенных пунктов. Продолжить?', 'walls-delivery-calc' ) ); ?>')) return;
				const form = document.getElementById('wdc-gar-import-form');
				const box = document.getElementById('wdc-gar-import-progress');
				post('wdc_gar_import_start', new FormData(form)).then(resp => { render(box, resp.data); loop('wdc_gar_import_step', box); });
			});
			const exportStart = document.getElementById('wdc-snapshot-export-start');
			if (exportStart) exportStart.addEventListener('click', function(){
				const box = document.getElementById('wdc-snapshot-progress');
				post('wdc_locations_snapshot_export_start').then(resp => { render(box, resp.data); loop('wdc_locations_snapshot_export_step', box); });
			});
			const importStart = document.getElementById('wdc-snapshot-import-start');
			if (importStart) importStart.addEventListener('click', function(){
				if (!window.confirm('<?php echo esc_js( __( 'Импорт snapshot заменит текущую локальную базу населенных пунктов и carrier mappings. Продолжить?', 'walls-delivery-calc' ) ); ?>')) return;
				const form = document.getElementById('wdc-snapshot-form');
				const box = document.getElementById('wdc-snapshot-progress');
				post('wdc_locations_snapshot_import_start', new FormData(form)).then(resp => { render(box, resp.data); loop('wdc_locations_snapshot_import_step', box); });
			});
			document.addEventListener('click', function(event){
				const button = event.target.closest('.wdc-location-details-toggle');
				if (!button) return;
				const panel = button.parentElement.querySelector('.wdc-location-details');
				if (!panel.hidden) { panel.hidden = true; return; }
				const data = new FormData();
				data.append('location_id', button.getAttribute('data-location-id') || '');
				post('wdc_location_details', data).then(resp => {
					const row = resp && resp.data ? resp.data : {};
					panel.innerHTML = '<table class="widefat striped"><tbody>' + Object.keys(row).map(k => '<tr><th>'+k+'</th><td>'+String(row[k] ?? '—')+'</td></tr>').join('') + '</tbody></table>';
					panel.hidden = false;
				});
			});
		})();
		</script>
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

	public function ajax_gar_import_start(): void {
		$this->guard_ajax();
		if ( ! $this->gar_importer instanceof GarPlacesCsvImporter ) {
			$this->send_json( array( 'phase' => 'failed', 'errors' => array( 'GAR CSV importer is unavailable.' ) ) );
		}
		$path = isset( $_POST['wdc_gar_places_path'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wdc_gar_places_path'] ) ) : '';
		if ( '' === $path ) {
			$path = $this->persist_upload( 'wdc_gar_places_csv', 'wdc-imports', 'gar_places.csv' );
		}
		$this->repository->clear_all();
		$job = $this->gar_importer->create_job( $path );
		update_option( self::GAR_JOB_OPTION, $job, false );
		$this->send_json( $job );
	}

	public function ajax_gar_import_step(): void {
		$this->guard_ajax();
		$job = get_option( self::GAR_JOB_OPTION, array() );
		$job = is_array( $job ) && $this->gar_importer instanceof GarPlacesCsvImporter ? $this->gar_importer->step_job( $job ) : array( 'phase' => 'failed', 'errors' => array( 'GAR import job is unavailable.' ) );
		update_option( self::GAR_JOB_OPTION, $job, false );
		$this->send_json( $job );
	}

	public function ajax_gar_import_status(): void {
		$this->guard_ajax();
		$job = get_option( self::GAR_JOB_OPTION, array( 'phase' => 'idle' ) );
		$this->send_json( is_array( $job ) ? $job : array( 'phase' => 'idle' ) );
	}

	public function ajax_gar_import_cancel(): void {
		$this->guard_ajax();
		delete_option( self::GAR_JOB_OPTION );
		$this->send_json( array( 'phase' => 'idle', 'cancelled' => true ) );
	}

	public function ajax_snapshot_export_start(): void {
		$this->guard_ajax();
		if ( ! $this->snapshot_exporter instanceof LocationsSnapshotExporter ) {
			$this->send_json( array( 'phase' => 'failed', 'errors' => array( 'Snapshot exporter is unavailable.' ) ) );
		}
		$path = $this->tmp_path( 'wdc-exports', 'locations-' . gmdate( 'Ymd-His' ) . '.jsonl' );
		$job = $this->snapshot_exporter->create_job( $path, $this->environment->version() );
		$job['download_path'] = $path;
		update_option( self::SNAPSHOT_EXPORT_JOB_OPTION, $job, false );
		$this->send_json( $job );
	}

	public function ajax_snapshot_export_step(): void {
		$this->guard_ajax();
		$job = get_option( self::SNAPSHOT_EXPORT_JOB_OPTION, array() );
		$job = is_array( $job ) && $this->snapshot_exporter instanceof LocationsSnapshotExporter ? $this->snapshot_exporter->step_job( $job ) : array( 'phase' => 'failed', 'errors' => array( 'Snapshot export job is unavailable.' ) );
		update_option( self::SNAPSHOT_EXPORT_JOB_OPTION, $job, false );
		$this->send_json( $job );
	}

	public function ajax_snapshot_import_start(): void {
		$this->guard_ajax();
		if ( ! $this->snapshot_importer instanceof LocationsSnapshotImporter ) {
			$this->send_json( array( 'phase' => 'failed', 'errors' => array( 'Snapshot importer is unavailable.' ) ) );
		}
		$path = $this->persist_upload( 'wdc_locations_snapshot', 'wdc-imports', 'locations-snapshot.jsonl' );
		$job = $this->snapshot_importer->create_job( $path );
		update_option( self::SNAPSHOT_IMPORT_JOB_OPTION, $job, false );
		$this->send_json( $job );
	}

	public function ajax_snapshot_import_step(): void {
		$this->guard_ajax();
		$job = get_option( self::SNAPSHOT_IMPORT_JOB_OPTION, array() );
		$job = is_array( $job ) && $this->snapshot_importer instanceof LocationsSnapshotImporter ? $this->snapshot_importer->step_job( $job ) : array( 'phase' => 'failed', 'errors' => array( 'Snapshot import job is unavailable.' ) );
		update_option( self::SNAPSHOT_IMPORT_JOB_OPTION, $job, false );
		$this->send_json( $job );
	}

	public function ajax_location_details(): void {
		$this->guard_ajax();
		$id = isset( $_POST['location_id'] ) ? (int) $_POST['location_id'] : 0;
		$row = $this->repository->find_raw_by_id( $id );
		$fields = array( 'id', 'gar_object_id', 'fias_id', 'gar_id', 'kladr_id', 'country_code', 'region_name', 'region_code', 'region_type', 'district_name', 'district_type', 'district_fias_id', 'district_kladr_id', 'district_gar_object_id', 'district_level', 'city_name', 'city_type', 'city_fias_id', 'city_kladr_id', 'settlement_name', 'settlement_type', 'place_name', 'place_type', 'place_level', 'display_name', 'searchable_text', 'postal_code', 'okato', 'oktmo', 'latitude', 'longitude', 'active', 'created_at', 'updated_at' );
		$data = array();
		foreach ( $fields as $field ) {
			$data[ $field ] = isset( $row[ $field ] ) ? (string) $row[ $field ] : '';
		}
		$this->send_json( $data );
	}

	private function guard_ajax(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			$this->send_json( array( 'phase' => 'failed', 'errors' => array( 'Forbidden.' ) ), false );
		}
		$nonce = isset( $_POST[ self::NONCE_NAME ] ) ? sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_NAME ] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, self::NONCE_ACTION ) ) {
			$this->send_json( array( 'phase' => 'failed', 'errors' => array( 'Invalid nonce.' ) ), false );
		}
	}

	private function persist_upload( string $field, string $dir, string $fallback_name ): string {
		if ( empty( $_FILES[ $field ]['tmp_name'] ) || ! is_string( $_FILES[ $field ]['tmp_name'] ) ) {
			throw new RuntimeException( 'Upload file is missing.' );
		}
		$target = $this->tmp_path( $dir, sanitize_file_name( (string) ( $_FILES[ $field ]['name'] ?? $fallback_name ) ) );
		if ( ! @move_uploaded_file( $_FILES[ $field ]['tmp_name'], $target ) ) {
			if ( ! @copy( $_FILES[ $field ]['tmp_name'], $target ) ) {
				throw new RuntimeException( 'Unable to persist uploaded file.' );
			}
		}

		return $target;
	}

	private function tmp_path( string $dir, string $name ): string {
		$uploads = function_exists( 'wp_upload_dir' ) ? wp_upload_dir() : array( 'basedir' => sys_get_temp_dir() );
		$base = rtrim( (string) ( $uploads['basedir'] ?? sys_get_temp_dir() ), '/\\' ) . DIRECTORY_SEPARATOR . $dir;
		if ( ! is_dir( $base ) ) {
			wp_mkdir_p( $base );
		}

		return $base . DIRECTORY_SEPARATOR . $name;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function send_json( array $data, bool $success = true ): void {
		if ( function_exists( 'wp_send_json_success' ) ) {
			$success ? wp_send_json_success( $data ) : wp_send_json_error( $data );
			return;
		}
		echo json_encode( array( 'success' => $success, 'data' => $data ), JSON_UNESCAPED_UNICODE );
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
