<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Admin;

use RuntimeException;
use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Checkout\Locations\CheckoutLocationSearch;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Fias\FiasCredentials;
use WallsShop\WDC\Locations\Fias\FiasRateLimiter;
use WallsShop\WDC\Locations\Gar\GarSyncManager;
use WallsShop\WDC\Locations\Import\FiasImportManager;
use WallsShop\WDC\Locations\Import\GarPlacesCsvImporter;
use WallsShop\WDC\Locations\Import\LocationImportService;
use WallsShop\WDC\Locations\Import\LocationIncrementalUpdateService;
use WallsShop\WDC\Locations\Import\LocationsSnapshotExporter;
use WallsShop\WDC\Locations\Import\LocationsSnapshotImporter;
use WallsShop\WDC\Locations\Services\LocationSearchService;
use WallsShop\WDC\Locations\Services\LocationCountryIndexService;
use WallsShop\WDC\Locations\Services\LocationAliasGenerator;
use WallsShop\WDC\Locations\Services\LocationDisplayNameFormatter;
use WallsShop\WDC\Locations\Coordinates\LocationCoordinatesDadataBatchUpdater;
use WallsShop\WDC\Locations\Postcodes\DaDataPostcodeClient;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class LocationsAdminPage {
	private const PAGE_SLUG = 'wdc-platform-locations';
	private const NONCE_ACTION = 'wdc_locations_admin';
	private const NONCE_NAME = 'wdc_locations_nonce';
	private const GAR_JOB_OPTION = 'wdc_gar_import_job';
	private const INCREMENTAL_UPDATE_JOB_OPTION = 'wdc_locations_incremental_update_job';
	private const SNAPSHOT_EXPORT_JOB_OPTION = 'wdc_locations_snapshot_export_job';
	private const SNAPSHOT_IMPORT_JOB_OPTION = 'wdc_locations_snapshot_import_job';
	private const DISPLAY_RULES_OPTION = 'wdc_location_type_display_rules';
	private const DISPLAY_REBUILD_JOB_OPTION = 'wdc_locations_display_name_rebuild_job';
	private const DADATA_POSTCODE_JOB_OPTION = 'wdc_dadata_postcode_fill_job';
	private const DADATA_COORDINATES_JOB_OPTION = 'wdc_dadata_coordinates_fill_job';
	private const DADATA_POSTCODE_MARKER = '999999999';

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
		private ?LocationsSnapshotImporter $snapshot_importer = null,
		private ?DaDataPostcodeClient $postcode_client = null,
		private ?LocationCoordinatesDadataBatchUpdater $coordinates_updater = null,
		private ?LocationCountryIndexService $country_index = null,
		private ?LocationIncrementalUpdateService $incremental_update = null
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wdc_gar_import_start', array( $this, 'ajax_gar_import_start' ) );
		add_action( 'wp_ajax_wdc_gar_import_step', array( $this, 'ajax_gar_import_step' ) );
		add_action( 'wp_ajax_wdc_gar_import_status', array( $this, 'ajax_gar_import_status' ) );
		add_action( 'wp_ajax_wdc_gar_import_cancel', array( $this, 'ajax_gar_import_cancel' ) );
		add_action( 'wp_ajax_wdc_locations_incremental_update_start', array( $this, 'ajax_incremental_update_start' ) );
		add_action( 'wp_ajax_wdc_locations_incremental_update_step', array( $this, 'ajax_incremental_update_step' ) );
		add_action( 'wp_ajax_wdc_locations_incremental_update_prepare', array( $this, 'ajax_incremental_update_prepare' ) );
		add_action( 'wp_ajax_wdc_locations_incremental_update_apply', array( $this, 'ajax_incremental_update_apply' ) );
		add_action( 'wp_ajax_wdc_locations_snapshot_export_start', array( $this, 'ajax_snapshot_export_start' ) );
		add_action( 'wp_ajax_wdc_locations_snapshot_export_step', array( $this, 'ajax_snapshot_export_step' ) );
		add_action( 'wp_ajax_wdc_locations_snapshot_import_start', array( $this, 'ajax_snapshot_import_start' ) );
		add_action( 'wp_ajax_wdc_locations_snapshot_import_step', array( $this, 'ajax_snapshot_import_step' ) );
		add_action( 'wp_ajax_wdc_location_details', array( $this, 'ajax_location_details' ) );
		add_action( 'wp_ajax_wdc_locations_display_name_rebuild_start', array( $this, 'ajax_display_name_rebuild_start' ) );
		add_action( 'wp_ajax_wdc_locations_display_name_rebuild_step', array( $this, 'ajax_display_name_rebuild_step' ) );
		add_action( 'wp_ajax_wdc_locations_display_name_rebuild_status', array( $this, 'ajax_display_name_rebuild_status' ) );
		add_action( 'wp_ajax_wdc_locations_display_name_rebuild_cancel', array( $this, 'ajax_display_name_rebuild_cancel' ) );
		add_action( 'wp_ajax_wdc_dadata_postcode_fill_start', array( $this, 'ajax_dadata_postcode_fill_start' ) );
		add_action( 'wp_ajax_wdc_dadata_postcode_fill_step', array( $this, 'ajax_dadata_postcode_fill_step' ) );
		add_action( 'wp_ajax_wdc_dadata_postcode_fill_status', array( $this, 'ajax_dadata_postcode_fill_status' ) );
		add_action( 'wp_ajax_wdc_dadata_postcode_fill_cancel', array( $this, 'ajax_dadata_postcode_fill_cancel' ) );
		add_action( 'wp_ajax_wdc_dadata_postcode_clear_markers', array( $this, 'ajax_dadata_postcode_clear_markers' ) );
		add_action( 'wp_ajax_wdc_dadata_coordinates_fill_start', array( $this, 'ajax_dadata_coordinates_fill_start' ) );
		add_action( 'wp_ajax_wdc_dadata_coordinates_fill_step', array( $this, 'ajax_dadata_coordinates_fill_step' ) );
		add_action( 'wp_ajax_wdc_dadata_coordinates_fill_status', array( $this, 'ajax_dadata_coordinates_fill_status' ) );
		add_action( 'wp_ajax_wdc_dadata_coordinates_fill_cancel', array( $this, 'ajax_dadata_coordinates_fill_cancel' ) );
		add_action( 'wp_ajax_wdc_dadata_coordinates_fill_reset', array( $this, 'ajax_dadata_coordinates_fill_reset' ) );
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
		$search_page = isset( $_GET['location_search_page'] ) ? max( 1, (int) $_GET['location_search_page'] ) : 1;
		$per_page = isset( $_GET['location_per_page'] ) ? (int) $_GET['location_per_page'] : 20;
		$show_deep_counts = $this->should_show_deep_counts();
		$total_locations = $this->repository->count_all();
		$paginated = '' !== trim( $query ) ? ( new CheckoutLocationSearch( $this->search_service ) )->search_paginated( $query, $search_page, $per_page ) : array( 'items' => array(), 'total' => 0, 'page' => 1, 'per_page' => 20, 'total_pages' => 0 );
		$grouped = $this->group_locations_by_region( $paginated['items'] );
		$deep_counts_url = $this->deep_counts_url( $query, $search_page, $per_page );
		?>
		<div class="wrap wdc-locations-admin">
			<h1><?php echo esc_html__( 'Населенные пункты', 'walls-delivery-calc' ); ?></h1>
			<?php if ( '' !== $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>

			<div class="wdc-locations-summary">
				<p><strong><?php echo esc_html__( 'Страны в базе:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( $show_deep_counts ? $this->country_summary_label() : __( 'по запросу', 'walls-delivery-calc' ) ); ?></span></p>
				<p><strong><?php echo esc_html__( 'Населенных пунктов:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( (string) $total_locations ); ?></span></p>
				<p><strong><?php echo esc_html__( 'Регионов/областей:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( $show_deep_counts ? (string) $this->repository->count_regions() : __( 'по запросу', 'walls-delivery-calc' ) ); ?></span></p>
				<p><strong><?php echo esc_html__( 'ФИАС/ГАР API-токен:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( $this->fias_credentials instanceof FiasCredentials && $this->fias_credentials->has_token() ? 'задан' : 'не задан' ); ?></span></p>
				<p><strong><?php echo esc_html__( 'Runtime-нормализация:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html__( 'временно отключена до проверки API', 'walls-delivery-calc' ); ?></span></p>
				<p><strong><?php echo esc_html__( 'Источник населенных пунктов:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html__( 'локальная база', 'walls-delivery-calc' ); ?></span></p>
				<p><strong><?php echo esc_html__( 'FIAS limiter:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( $this->limiter_label() ); ?></span></p>
				<p><strong><?php echo esc_html__( 'GAR sync:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( $this->gar_status_label() ); ?></span></p>
				<p><strong><?php echo esc_html__( 'Aliases:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( $show_deep_counts ? (string) $this->repository->count_aliases() : __( 'по запросу', 'walls-delivery-calc' ) ); ?></span></p>
				<?php if ( ! $show_deep_counts ) : ?>
					<p><a class="button" href="<?php echo esc_attr( $deep_counts_url ); ?>"><?php echo esc_html__( 'Показать подробные счетчики', 'walls-delivery-calc' ); ?></a></p>
				<?php endif; ?>
			</div>

			<div class="wdc-locations-import wdc-dadata-postcode-fill">
				<h2><?php echo esc_html__( 'Заполнение информации через DaData', 'walls-delivery-calc' ); ?></h2>
				<div class="wdc-locations-summary">
					<p><strong><?php echo esc_html__( 'Всего населенных пунктов:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( (string) $total_locations ); ?></span></p>
					<?php if ( $show_deep_counts ) : ?>
						<p><strong><?php echo esc_html__( 'postal_code заполнен:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( (string) $this->repository->count_with_postal_code() ); ?></span></p>
						<p><strong><?php echo esc_html__( 'postal_code отсутствует:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( (string) $this->repository->count_without_postal_code() ); ?></span></p>
						<p><strong><?php echo esc_html__( 'координаты есть:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( (string) $this->repository->count_locations_with_coordinates() ); ?></span></p>
						<p><strong><?php echo esc_html__( 'координат нет:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( (string) $this->repository->count_locations_missing_coordinates() ); ?></span></p>
						<p><strong><?php echo esc_html__( 'technical no-index marker count:', 'walls-delivery-calc' ); ?></strong> <span><?php echo esc_html( (string) $this->repository->count_technical_no_index_marker() ); ?></span></p>
					<?php else : ?>
						<p class="description"><?php echo esc_html__( 'Подробные счетчики postal_code, координат и technical marker считаются только по запросу.', 'walls-delivery-calc' ); ?></p>
					<?php endif; ?>
				</div>
				<div class="wdc-dadata-actions">
					<div class="wdc-dadata-action-row">
						<button class="button button-primary" type="button" id="wdc-dadata-postcode-fill-start"><?php echo esc_html__( 'Получить индексы через DaData', 'walls-delivery-calc' ); ?></button>
						<button class="button button-secondary" type="button" id="wdc-dadata-postcode-clear-markers"><?php echo esc_html__( 'Удалить технические значения 999999999', 'walls-delivery-calc' ); ?></button>
					</div>
					<div class="wdc-dadata-action-row">
						<button class="button button-secondary" type="button" id="wdc-dadata-coordinates-fill-start"><?php echo esc_html__( 'Получить координаты через DaData', 'walls-delivery-calc' ); ?></button>
						<button class="button button-secondary" type="button" id="wdc-dadata-coordinates-fill-reset"><?php echo esc_html__( 'Обнулить задачу координат', 'walls-delivery-calc' ); ?></button>
					</div>
				</div>
				<div id="wdc-dadata-postcode-progress" class="wdc-progress" hidden>
					<progress value="0" max="100"></progress>
					<p class="wdc-progress-summary"></p>
					<details open>
						<summary><?php echo esc_html__( 'JSON status', 'walls-delivery-calc' ); ?></summary>
						<pre></pre>
					</details>
				</div>
				<div id="wdc-dadata-coordinates-progress" class="wdc-progress" hidden>
					<progress value="0" max="100"></progress>
					<p class="wdc-progress-summary"></p>
					<details open>
						<summary><?php echo esc_html__( 'JSON status', 'walls-delivery-calc' ); ?></summary>
						<pre></pre>
					</details>
				</div>
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

			<form id="wdc-incremental-update-form" class="wdc-locations-import" method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<h2><?php echo esc_html__( 'Обновление базы населенных пунктов', 'walls-delivery-calc' ); ?></h2>
				<label>
					<span><?php echo esc_html__( 'Новый gar_places.csv', 'walls-delivery-calc' ); ?></span>
					<input type="file" name="wdc_gar_places_update_csv" accept=".csv,text/csv">
				</label>
				<label>
					<span><?php echo esc_html__( 'Путь к CSV на сервере', 'walls-delivery-calc' ); ?></span>
					<input type="text" name="wdc_gar_places_update_path" placeholder="/path/to/gar_places.csv">
				</label>
				<p class="description"><?php echo esc_html__( 'CSV загружается в staging-копию. Текущие wp_wdc_locations и wp_wdc_location_aliases не меняются до финального подтверждения.', 'walls-delivery-calc' ); ?></p>
				<button class="button button-primary" type="button" id="wdc-incremental-update-start"><?php echo esc_html__( 'Загрузить новый GAR CSV', 'walls-delivery-calc' ); ?></button>
				<button class="button button-secondary" type="button" id="wdc-incremental-update-prepare" hidden><?php echo esc_html__( 'Подготовить обновленную базу', 'walls-delivery-calc' ); ?></button>
				<button class="button button-primary" type="button" id="wdc-incremental-update-apply" hidden><?php echo esc_html__( 'Применить новую базу', 'walls-delivery-calc' ); ?></button>
				<div id="wdc-incremental-update-progress" class="wdc-progress" hidden>
					<progress value="0" max="100"></progress>
					<div class="wdc-incremental-update-analysis"></div>
					<pre></pre>
				</div>
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

			<form class="wdc-locations-import wdc-location-type-rules" method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<h2><?php echo esc_html__( 'Отображение типов населенных пунктов', 'walls-delivery-calc' ); ?></h2>
				<input type="hidden" name="wdc_locations_action" value="save_type_rules">
				<?php $this->render_type_rules_table(); ?>
				<p><button class="button button-primary" type="submit"><?php echo esc_html__( 'Сохранить правила отображения', 'walls-delivery-calc' ); ?></button></p>
			</form>

			<div class="wdc-locations-import wdc-display-name-rebuild">
				<h2><?php echo esc_html__( 'Обработка display_name', 'walls-delivery-calc' ); ?></h2>
				<p class="description"><?php echo esc_html__( 'Пакетно пересобирает display_name, searchable_text и GAR aliases с учетом текущих правил отображения типов.', 'walls-delivery-calc' ); ?></p>
				<button class="button button-primary" type="button" id="wdc-display-name-rebuild-start"><?php echo esc_html__( 'Пересобрать display_name', 'walls-delivery-calc' ); ?></button>
				<div id="wdc-display-name-rebuild-progress" class="wdc-progress" hidden>
					<progress value="0" max="100"></progress>
					<p class="wdc-progress-summary"></p>
					<details open>
						<summary><?php echo esc_html__( 'JSON status', 'walls-delivery-calc' ); ?></summary>
						<pre></pre>
					</details>
				</div>
			</div>

			<form class="wdc-locations-search" method="get">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<label>
					<span><?php echo esc_html__( 'Поиск населенных пунктов', 'walls-delivery-calc' ); ?></span>
					<input type="search" name="location_query" value="<?php echo esc_attr( $query ); ?>" placeholder="<?php echo esc_attr__( 'Новос', 'walls-delivery-calc' ); ?>">
				</label>
				<label>
					<span><?php echo esc_html__( 'Показывать по', 'walls-delivery-calc' ); ?></span>
					<select name="location_per_page" onchange="this.form.location_search_page.value='1'; this.form.submit();">
						<?php foreach ( array( 10, 20, 50, 100 ) as $value ) : ?>
							<option value="<?php echo esc_attr( (string) $value ); ?>" <?php echo (int) $paginated['per_page'] === $value ? 'selected' : ''; ?>><?php echo esc_html( (string) $value ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<input type="hidden" name="location_search_page" value="<?php echo esc_attr( (string) $paginated['page'] ); ?>">
				<button class="button" type="submit"><?php echo esc_html__( 'Найти', 'walls-delivery-calc' ); ?></button>
			</form>

			<?php if ( '' !== trim( $query ) ) : ?>
				<div class="wdc-locations-results">
					<?php $this->render_search_pagination( $query, $paginated ); ?>
					<?php if ( array() === $grouped ) : ?>
						<p><?php echo esc_html__( 'Населенные пункты не найдены.', 'walls-delivery-calc' ); ?></p>
					<?php endif; ?>
					<?php foreach ( $grouped as $group ) : ?>
						<section class="wdc-locations-region">
							<h2><?php echo esc_html( (string) $group['label'] ); ?></h2>
							<?php foreach ( $group['locations'] as $location ) : ?>
								<?php $this->render_location_row( $location ); ?>
							<?php endforeach; ?>
						</section>
					<?php endforeach; ?>
					<?php $this->render_search_pagination( $query, $paginated ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php $this->render_progress_script(); ?>
		<?php
	}

	private function should_show_deep_counts(): bool {
		return isset( $_GET['wdc_locations_deep_counts'] ) && '1' === sanitize_key( (string) wp_unslash( $_GET['wdc_locations_deep_counts'] ) );
	}

	private function deep_counts_url( string $query, int $search_page, int $per_page ): string {
		$params = array(
			'page'                      => self::PAGE_SLUG,
			'wdc_locations_deep_counts' => '1',
		);
		if ( '' !== trim( $query ) ) {
			$params['location_query']       = $query;
			$params['location_search_page'] = (string) max( 1, $search_page );
			$params['location_per_page']    = (string) $per_page;
		}

		return '?' . http_build_query( $params, '', '&' );
	}

	private function country_summary_label(): string {
		if ( ! $this->country_index instanceof LocationCountryIndexService ) {
			return __( 'нет данных', 'walls-delivery-calc' );
		}

		$countries = $this->country_index->countries_with_counts();
		if ( array() === $countries ) {
			return __( 'нет данных', 'walls-delivery-calc' );
		}

		return implode( ', ', array_map( array( $this, 'country_summary_item' ), $countries ) );
	}

	/**
	 * @param array{country_code:string,country_name:string,count:int} $country
	 */
	private function country_summary_item( array $country ): string {
		$country_code = strtoupper( trim( (string) ( $country['country_code'] ?? '' ) ) );
		$country_name = trim( (string) ( $country['country_name'] ?? '' ) );
		$label = '' !== $country_name ? $country_code . ' ' . $country_name : $country_code;

		return sprintf( '%s (%s)', $label, $this->format_count( (int) ( $country['count'] ?? 0 ) ) );
	}

	private function format_count( int $count ): string {
		return function_exists( 'number_format_i18n' ) ? number_format_i18n( $count ) : number_format( $count, 0, '.', ' ' );
	}

	private function render_location_row( Location $location ): void {
		?>
		<div class="wdc-location-row">
			<div class="wdc-location-row-main">
				<button class="button button-small wdc-location-details-toggle" type="button" data-location-id="<?php echo esc_attr( (string) $location->id ); ?>"><?php echo esc_html__( 'Детали', 'walls-delivery-calc' ); ?></button>
				<strong class="wdc-location-title"><?php echo esc_html( $location->display_name ); ?></strong>
				<span class="wdc-location-postal"><?php echo esc_html( $location->postal_code ); ?></span>
				<span class="wdc-location-country"><?php echo esc_html( $location->country_code ); ?></span>
			</div>
			<div class="wdc-location-details" hidden></div>
		</div>
		<?php
	}

	/**
	 * @param array<int,Location> $locations
	 * @return array<int,array{sort_key:string,label:string,locations:array<int,Location>}>
	 */
	private function group_locations_by_region( array $locations ): array {
		$grouped = array();
		$formatter = LocationDisplayNameFormatter::from_rules( $this->type_display_rules() );
		foreach ( $locations as $location ) {
			$sort_key = '' !== $location->region_name ? $location->region_name : __( 'Регион не указан', 'walls-delivery-calc' );
			if ( ! isset( $grouped[ $sort_key ] ) ) {
				$label = '' !== $location->region_name ? $formatter->format_region_group_header( $location ) : $sort_key;
				$grouped[ $sort_key ] = array(
					'sort_key'  => $sort_key,
					'label'     => $label,
					'locations' => array(),
				);
			}
			$grouped[ $sort_key ]['locations'][] = $location;
		}
		ksort( $grouped );
		return array_values( $grouped );
	}

	/**
	 * @param array{items:array<int,Location>, total:int, page:int, per_page:int, total_pages:int} $paginated
	 */
	private function render_search_pagination( string $query, array $paginated ): void {
		$total = (int) $paginated['total'];
		$page = (int) $paginated['page'];
		$per_page = (int) $paginated['per_page'];
		$total_pages = (int) $paginated['total_pages'];
		$from = $total > 0 ? ( ( $page - 1 ) * $per_page ) + 1 : 0;
		$to = min( $total, $page * $per_page );
		?>
		<div class="wdc-locations-pagination">
			<span><?php echo esc_html( sprintf( __( 'Найдено всего: %d', 'walls-delivery-calc' ), $total ) ); ?></span>
			<span><?php echo esc_html( sprintf( __( '%1$d–%2$d из %3$d', 'walls-delivery-calc' ), $from, $to, $total ) ); ?></span>
			<?php foreach ( array(
				array( 'target' => 1, 'label' => '« Первая' ),
				array( 'target' => max( 1, $page - 1 ), 'label' => '‹ Предыдущая' ),
				array( 'target' => min( max( 1, $total_pages ), $page + 1 ), 'label' => 'Следующая ›' ),
				array( 'target' => max( 1, $total_pages ), 'label' => 'Последняя »' ),
			) as $link ) : ?>
				<a class="button button-small <?php echo (int) $link['target'] === $page ? 'disabled' : ''; ?>" href="<?php echo esc_attr( $this->search_page_url( $query, (int) $link['target'], $per_page ) ); ?>"><?php echo esc_html( $link['label'] ); ?></a>
			<?php endforeach; ?>
			<span class="wdc-page-numbers">
				<?php foreach ( $this->pagination_numbers( $page, $total_pages ) as $item ) : ?>
					<?php if ( 'ellipsis' === $item ) : ?>
						<span class="wdc-page-ellipsis">…</span>
					<?php else : ?>
						<a class="button button-small wdc-page-number <?php echo (int) $item === $page ? 'current' : ''; ?>" href="<?php echo esc_attr( $this->search_page_url( $query, (int) $item, $per_page ) ); ?>"><?php echo esc_html( (string) $item ); ?></a>
					<?php endif; ?>
				<?php endforeach; ?>
			</span>
		</div>
		<?php
	}

	/**
	 * @return array<int,int|string>
	 */
	private function pagination_numbers( int $page, int $total_pages ): array {
		if ( $total_pages <= 1 ) {
			return array( 1 );
		}

		if ( $total_pages <= 9 ) {
			return range( 1, $total_pages );
		}

		$pages = array( 1 );
		$start = max( 2, $page - 2 );
		$end = min( $total_pages - 1, $page + 2 );
		if ( $start > 2 ) {
			$pages[] = 'ellipsis';
		}
		for ( $index = $start; $index <= $end; $index++ ) {
			$pages[] = $index;
		}
		if ( $end < $total_pages - 1 ) {
			$pages[] = 'ellipsis';
		}
		$pages[] = $total_pages;

		return $pages;
	}

	private function search_page_url( string $query, int $page, int $per_page ): string {
		$args = array(
			'page'                 => self::PAGE_SLUG,
			'location_query'       => $query,
			'location_search_page' => max( 1, $page ),
			'location_per_page'    => $per_page,
		);
		$url = 'admin.php?' . http_build_query( $args );
		return function_exists( 'admin_url' ) ? admin_url( $url ) : $url;
	}

	private function render_type_rules_table(): void {
		$rules = $this->type_display_rules();
		$types = $this->repository->distinct_location_types();
		$labels = array( 'region' => 'Регион', 'city' => 'Город', 'place' => 'Населенный пункт' );
		foreach ( $labels as $scope => $label ) :
			$scope_types = array_values( array_unique( array_merge( $types[ $scope ] ?? array(), array_keys( $rules[ $scope ] ?? array() ) ) ) );
			sort( $scope_types );
			?>
			<details class="wdc-type-rules-group">
				<summary><?php echo esc_html( sprintf( '%1$s — %2$d типов', $label, count( $scope_types ) ) ); ?></summary>
			<table class="widefat striped wdc-type-rules-table">
				<thead><tr><th><?php echo esc_html__( 'Тип в базе', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Отображать как', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Позиция', 'walls-delivery-calc' ); ?></th></tr></thead>
				<tbody>
				<?php foreach ( $scope_types as $type ) : $rule = $rules[ $scope ][ $type ] ?? array(); ?>
					<tr>
						<th><?php echo esc_html( $type ); ?><input type="hidden" name="type_rules[<?php echo esc_attr( $scope ); ?>][<?php echo esc_attr( $type ); ?>][source]" value="<?php echo esc_attr( $type ); ?>"></th>
						<td><input type="text" name="type_rules[<?php echo esc_attr( $scope ); ?>][<?php echo esc_attr( $type ); ?>][display]" value="<?php echo esc_attr( (string) ( $rule['display'] ?? $type ) ); ?>"></td>
						<td>
							<select name="type_rules[<?php echo esc_attr( $scope ); ?>][<?php echo esc_attr( $type ); ?>][position]">
								<?php foreach ( array( 'before' => 'Перед названием', 'after' => 'После названия', 'hidden' => 'Не показывать' ) as $value => $text ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php echo (string) ( $rule['position'] ?? $this->default_type_position( $scope ) ) === $value ? 'selected' : ''; ?>><?php echo esc_html( $text ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				<?php endforeach; ?>
				<?php if ( array() === $scope_types ) : ?>
					<tr><td colspan="3"><?php echo esc_html__( 'Типы пока не найдены в базе.', 'walls-delivery-calc' ); ?></td></tr>
				<?php endif; ?>
				</tbody>
			</table>
			</details>
			<?php
		endforeach;
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
				const total = Number(job.rows_total_estimated || job.total_rows || job.stage_rows || job.total || 1);
				const done = Number(job.processed_rows || job.rows_exported || job.imported || job.rows_read || job.processed || 0);
				progress.value = Math.min(100, Math.round(done / Math.max(1, total) * 100));
				const summary = box.querySelector('.wdc-progress-summary');
				if (summary) summary.textContent = 'status: ' + (job.status || job.phase || '') + ', phase: ' + (job.phase || '') + ', processed: ' + (job.processed || done || 0) + ' / ' + (job.total || total || 0) + ', updated: ' + (job.updated || 0) + ', marked_no_index: ' + (job.marked_no_index || 0) + ', skipped: ' + (job.skipped || 0) + ', failed: ' + (job.failed || 0) + ', errors: ' + (job.errors || 0) + ', consecutive_errors: ' + (job.consecutive_errors || 0) + ', priority: ' + (job.current_priority || '') + ', mode: ' + (job.resume_strategy || '') + ', skip_reason: ' + (job.last_skip_reason || '') + ', aliases: ' + (job.aliases_updated || 0);
				text.textContent = JSON.stringify(job, null, 2);
			}
			function loop(action, box, delay) {
				post(action).then(resp => {
					const job = resp && resp.data ? resp.data : {};
					render(box, job);
					if (job.phase && job.phase !== 'finished' && job.phase !== 'failed' && job.phase !== 'canceled') {
						window.setTimeout(() => loop(action, box, delay), delay || 250);
					}
				});
			}
			function safeText(value) {
				if (value === null || value === undefined || value === '') return '—';
				if (typeof value === 'object') {
					try { return JSON.stringify(value); } catch (e) { return String(value); }
				}
				return String(value);
			}
			function renderDetailsTable(row) {
				const table = document.createElement('table');
				table.className = 'widefat striped';
				const tbody = document.createElement('tbody');
				Object.keys(row || {}).forEach(function(key){
					const tr = document.createElement('tr');
					const th = document.createElement('th');
					const td = document.createElement('td');
					th.textContent = key;
					td.textContent = safeText(row[key]);
					tr.appendChild(th);
					tr.appendChild(td);
					tbody.appendChild(tr);
				});
				table.appendChild(tbody);
				return table;
			}
			function incrementalKey(row) {
				if (row && row.key) return row.key;
				if (row && row.fias_id) return 'f:' + row.fias_id;
				if (row && row.gar_object_id) return 'g:' + row.gar_object_id;
				return '';
			}
			function renderIncrementalTable(title, type, rows) {
				const details = document.createElement('details');
				details.open = true;
				const summary = document.createElement('summary');
				summary.textContent = title + ' (' + (rows || []).length + ')';
				details.appendChild(summary);
				const table = document.createElement('table');
				table.className = 'widefat striped';
				const tbody = document.createElement('tbody');
				(rows || []).forEach(function(row){
					const key = incrementalKey(row);
					if (!key) return;
					const tr = document.createElement('tr');
					const checkTd = document.createElement('td');
					const checkbox = document.createElement('input');
					checkbox.type = 'checkbox';
					checkbox.checked = true;
					checkbox.setAttribute('data-wdc-incremental-key', key);
					checkbox.setAttribute('data-wdc-incremental-type', type);
					checkTd.appendChild(checkbox);
					const idTd = document.createElement('td');
					idTd.textContent = safeText(row.fias_id || row.gar_object_id || key);
					const nameTd = document.createElement('td');
					if (type === 'changed' && row.changes) {
						nameTd.textContent = Object.keys(row.changes).map(function(field){ return field + ': ' + safeText(row.changes[field].old) + ' -> ' + safeText(row.changes[field].new); }).join('; ');
					} else if (type === 'changed') {
						nameTd.textContent = safeText(row.old_display_name) + ' -> ' + safeText(row.new_display_name);
					} else {
						nameTd.textContent = safeText(row.display_name);
					}
					const postTd = document.createElement('td');
					postTd.textContent = safeText(row.postal_code || row.new_postal_code || row.old_postal_code);
					tr.appendChild(checkTd);
					tr.appendChild(idTd);
					tr.appendChild(nameTd);
					tr.appendChild(postTd);
					tbody.appendChild(tr);
				});
				table.appendChild(tbody);
				details.appendChild(table);
				return details;
			}
			function renderIncrementalAnalysis(box, job) {
				render(box, job);
				const analysis = box ? box.querySelector('.wdc-incremental-update-analysis') : null;
				const prepare = document.getElementById('wdc-incremental-update-prepare');
				const apply = document.getElementById('wdc-incremental-update-apply');
				if (!analysis || !job) return;
				while (analysis.firstChild) analysis.removeChild(analysis.firstChild);
				if (job.phase === 'analysis' || job.phase === 'candidate_ready' || job.phase === 'candidate_failed' || job.phase === 'applied') {
					const summary = document.createElement('p');
					summary.textContent = 'Текущая база: ' + safeText(job.current_count) + '; Новый GAR: ' + safeText(job.staging_count) + '; Новых: ' + safeText(job.new_count) + '; Удаляемых: ' + safeText(job.removed_count) + '; Измененных: ' + safeText(job.changed_count) + '; Candidate: ' + safeText(job.candidate_count || '');
					analysis.appendChild(summary);
					const samples = job.samples || {};
					analysis.appendChild(renderIncrementalTable('Новые населенные пункты', 'new', samples.new || []));
					analysis.appendChild(renderIncrementalTable('Удаляемые населенные пункты', 'removed', samples.removed || []));
					analysis.appendChild(renderIncrementalTable('Измененные населенные пункты', 'changed', samples.changed || []));
				}
				if (prepare) prepare.hidden = job.phase !== 'analysis';
				if (apply) apply.hidden = job.phase !== 'candidate_ready';
			}
			function collectIncrementalSelection() {
				const data = new FormData();
				document.querySelectorAll('[data-wdc-incremental-key]:checked').forEach(function(input){
					data.append('selected[' + input.getAttribute('data-wdc-incremental-type') + '][]', input.getAttribute('data-wdc-incremental-key'));
				});
				return data;
			}
			const garStart = document.getElementById('wdc-gar-import-start');
			if (garStart) garStart.addEventListener('click', function(){
				if (!window.confirm('<?php echo esc_js( __( 'Импорт заменит локальную базу населенных пунктов. Продолжить?', 'walls-delivery-calc' ) ); ?>')) return;
				const form = document.getElementById('wdc-gar-import-form');
				const box = document.getElementById('wdc-gar-import-progress');
				post('wdc_gar_import_start', new FormData(form)).then(resp => { render(box, resp.data); loop('wdc_gar_import_step', box); });
			});
			const incrementalStart = document.getElementById('wdc-incremental-update-start');
			if (incrementalStart) incrementalStart.addEventListener('click', function(){
				const form = document.getElementById('wdc-incremental-update-form');
				const box = document.getElementById('wdc-incremental-update-progress');
				post('wdc_locations_incremental_update_start', new FormData(form)).then(resp => {
					renderIncrementalAnalysis(box, resp.data);
					const poll = function(){
						post('wdc_locations_incremental_update_step').then(stepResp => {
							const job = stepResp && stepResp.data ? stepResp.data : {};
							renderIncrementalAnalysis(box, job);
							if (job.phase === 'staging' || job.phase === 'diff') window.setTimeout(poll, 250);
						});
					};
					poll();
				});
			});
			const incrementalPrepare = document.getElementById('wdc-incremental-update-prepare');
			if (incrementalPrepare) incrementalPrepare.addEventListener('click', function(){
				const box = document.getElementById('wdc-incremental-update-progress');
				post('wdc_locations_incremental_update_prepare', collectIncrementalSelection()).then(resp => { renderIncrementalAnalysis(box, resp.data); });
			});
			const incrementalApply = document.getElementById('wdc-incremental-update-apply');
			if (incrementalApply) incrementalApply.addEventListener('click', function(){
				if (!window.confirm('<?php echo esc_js( __( 'Применить candidate через атомарную замену таблиц locations и aliases?', 'walls-delivery-calc' ) ); ?>')) return;
				const box = document.getElementById('wdc-incremental-update-progress');
				post('wdc_locations_incremental_update_apply').then(resp => { renderIncrementalAnalysis(box, resp.data); });
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
			const rebuildStart = document.getElementById('wdc-display-name-rebuild-start');
			if (rebuildStart) rebuildStart.addEventListener('click', function(){
				const box = document.getElementById('wdc-display-name-rebuild-progress');
				post('wdc_locations_display_name_rebuild_start').then(resp => { render(box, resp.data); loop('wdc_locations_display_name_rebuild_step', box); });
			});
			const postcodeStart = document.getElementById('wdc-dadata-postcode-fill-start');
			if (postcodeStart) postcodeStart.addEventListener('click', function(){
				const box = document.getElementById('wdc-dadata-postcode-progress');
				post('wdc_dadata_postcode_fill_start').then(resp => { render(box, resp.data); loop('wdc_dadata_postcode_fill_step', box, 2000 + Math.floor(Math.random() * 2001)); });
			});
			const coordinatesStart = document.getElementById('wdc-dadata-coordinates-fill-start');
			if (coordinatesStart) coordinatesStart.addEventListener('click', function(){
				const box = document.getElementById('wdc-dadata-coordinates-progress');
				post('wdc_dadata_coordinates_fill_start').then(resp => { render(box, resp.data); loop('wdc_dadata_coordinates_fill_step', box, 2000 + Math.floor(Math.random() * 2001)); });
			});
			const coordinatesReset = document.getElementById('wdc-dadata-coordinates-fill-reset');
			if (coordinatesReset) coordinatesReset.addEventListener('click', function(){
				if (!window.confirm('<?php echo esc_js( __( 'Обнулить прогресс задачи координат? Уже записанные координаты в базе не удаляются.', 'walls-delivery-calc' ) ); ?>')) return;
				const box = document.getElementById('wdc-dadata-coordinates-progress');
				post('wdc_dadata_coordinates_fill_reset').then(resp => { render(box, resp.data); });
			});
			const postcodeClear = document.getElementById('wdc-dadata-postcode-clear-markers');
			if (postcodeClear) postcodeClear.addEventListener('click', function(){
				const box = document.getElementById('wdc-dadata-postcode-progress');
				post('wdc_dadata_postcode_clear_markers').then(resp => { render(box, resp.data); });
			});
			document.addEventListener('click', function(event){
				const button = event.target.closest('.wdc-location-details-toggle');
				if (!button) return;
				const row = button.closest('.wdc-location-row');
				const panel = row ? row.querySelector('.wdc-location-details') : null;
				if (!panel) return;
				if (!panel.hidden) { panel.hidden = true; return; }
				const data = new FormData();
				data.append('location_id', button.getAttribute('data-location-id') || '');
				post('wdc_location_details', data).then(resp => {
					const row = resp && resp.data ? resp.data : {};
					while (panel.firstChild) {
						panel.removeChild(panel.firstChild);
					}
					panel.appendChild(renderDetailsTable(row));
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
		if ( ! in_array( $action, array( 'import_gar_csv', 'clear_all', 'export_snapshot', 'import_snapshot', 'save_type_rules' ), true ) ) {
			return '';
		}

		if ( 'save_type_rules' === $action ) {
			$rules = $this->sanitize_type_rules( $_POST['type_rules'] ?? array() );
			$this->update_option( self::DISPLAY_RULES_OPTION, $rules );
			return __( 'Правила отображения типов сохранены.', 'walls-delivery-calc' );
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

	/**
	 * @param mixed $raw
	 * @return array<string,array<int,string>>
	 */
	private function sanitize_incremental_selection( mixed $raw ): array {
		$result = array( 'new' => array(), 'removed' => array(), 'changed' => array() );
		if ( ! is_array( $raw ) ) {
			return $result;
		}
		foreach ( $result as $type => $values ) {
			foreach ( is_array( $raw[ $type ] ?? null ) ? $raw[ $type ] : array() as $value ) {
				$key = sanitize_text_field( wp_unslash( (string) $value ) );
				if ( preg_match( '/^f:[A-Za-z0-9\\-]{1,64}$/', $key ) || preg_match( '/^g:[0-9]{1,20}$/', $key ) ) {
					$result[ $type ][] = $key;
				}
			}
			$result[ $type ] = array_values( array_unique( $result[ $type ] ) );
		}

		return $result;
	}

	/**
	 * @return array<string,array<string,array{display:string,position:string}>>
	 */
	private function type_display_rules(): array {
		$rules = $this->get_option( self::DISPLAY_RULES_OPTION, array() );
		return is_array( $rules ) ? $this->sanitize_type_rules( $rules ) : array();
	}

	/**
	 * @param mixed $raw
	 * @return array<string,array<string,array{display:string,position:string}>>
	 */
	private function sanitize_type_rules( mixed $raw ): array {
		$result = array( 'region' => array(), 'city' => array(), 'place' => array() );
		if ( ! is_array( $raw ) ) {
			return $result;
		}

		foreach ( array( 'region', 'city', 'place' ) as $scope ) {
			foreach ( is_array( $raw[ $scope ] ?? null ) ? $raw[ $scope ] : array() as $source => $rule ) {
				$source = sanitize_text_field( wp_unslash( (string) $source ) );
				if ( '' === $source || ! is_array( $rule ) ) {
					continue;
				}
				$position = sanitize_key( wp_unslash( (string) ( $rule['position'] ?? $this->default_type_position( $scope ) ) ) );
				if ( ! in_array( $position, array( 'before', 'after', 'hidden' ), true ) ) {
					$position = $this->default_type_position( $scope );
				}
				$result[ $scope ][ $source ] = array(
					'display'  => sanitize_text_field( wp_unslash( (string) ( $rule['display'] ?? $source ) ) ),
					'position' => $position,
				);
			}
		}

		return $result;
	}

	private function default_type_position( string $scope ): string {
		return 'region' === $scope ? 'after' : 'before';
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
		$this->update_option( self::GAR_JOB_OPTION, $job );
		$this->send_json( $job );
	}

	public function ajax_gar_import_step(): void {
		$this->guard_ajax();
		$job = $this->get_option( self::GAR_JOB_OPTION, array() );
		$job = is_array( $job ) && $this->gar_importer instanceof GarPlacesCsvImporter ? $this->gar_importer->step_job( $job ) : array( 'phase' => 'failed', 'errors' => array( 'GAR import job is unavailable.' ) );
		$this->update_option( self::GAR_JOB_OPTION, $job );
		$this->send_json( $job );
	}

	public function ajax_gar_import_status(): void {
		$this->guard_ajax();
		$job = $this->get_option( self::GAR_JOB_OPTION, array( 'phase' => 'idle' ) );
		$this->send_json( is_array( $job ) ? $job : array( 'phase' => 'idle' ) );
	}

	public function ajax_gar_import_cancel(): void {
		$this->guard_ajax();
		$this->delete_option( self::GAR_JOB_OPTION );
		$this->send_json( array( 'phase' => 'idle', 'cancelled' => true ) );
	}

	public function ajax_incremental_update_start(): void {
		$this->guard_ajax();
		if ( ! $this->incremental_update instanceof LocationIncrementalUpdateService ) {
			$this->send_json( array( 'phase' => 'failed', 'errors' => array( 'Incremental update service is unavailable.' ) ) );
		}
		$path = isset( $_POST['wdc_gar_places_update_path'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wdc_gar_places_update_path'] ) ) : '';
		if ( '' === $path ) {
			$path = $this->persist_upload( 'wdc_gar_places_update_csv', 'wdc-imports', 'gar_places-update.csv' );
		}
		$job = $this->incremental_update->create_job( $path );
		$this->update_option( self::INCREMENTAL_UPDATE_JOB_OPTION, $job );
		$this->send_json( $job );
	}

	public function ajax_incremental_update_step(): void {
		$this->guard_ajax();
		$job = $this->get_option( self::INCREMENTAL_UPDATE_JOB_OPTION, array() );
		$job = is_array( $job ) && $this->incremental_update instanceof LocationIncrementalUpdateService ? $this->incremental_update->step_job( $job ) : array( 'phase' => 'failed', 'errors' => array( 'Incremental update job is unavailable.' ) );
		$this->update_option( self::INCREMENTAL_UPDATE_JOB_OPTION, $job );
		$this->send_json( $job );
	}

	public function ajax_incremental_update_prepare(): void {
		$this->guard_ajax();
		$job = $this->get_option( self::INCREMENTAL_UPDATE_JOB_OPTION, array() );
		$selected = $this->sanitize_incremental_selection( $_POST['selected'] ?? array() );
		$job = is_array( $job ) && $this->incremental_update instanceof LocationIncrementalUpdateService ? $this->incremental_update->prepare_candidate( $job, $selected ) : array( 'phase' => 'failed', 'errors' => array( 'Incremental update job is unavailable.' ) );
		$this->update_option( self::INCREMENTAL_UPDATE_JOB_OPTION, $job );
		$this->send_json( $job );
	}

	public function ajax_incremental_update_apply(): void {
		$this->guard_ajax();
		$job = $this->get_option( self::INCREMENTAL_UPDATE_JOB_OPTION, array() );
		$job = is_array( $job ) && $this->incremental_update instanceof LocationIncrementalUpdateService ? $this->incremental_update->apply_candidate( $job ) : array( 'phase' => 'failed', 'errors' => array( 'Incremental update job is unavailable.' ) );
		$this->update_option( self::INCREMENTAL_UPDATE_JOB_OPTION, $job );
		$this->send_json( $job );
	}

	public function ajax_snapshot_export_start(): void {
		$this->guard_ajax();
		if ( ! $this->snapshot_exporter instanceof LocationsSnapshotExporter ) {
			$this->send_json( array( 'phase' => 'failed', 'errors' => array( 'Snapshot exporter is unavailable.' ) ) );
		}
		$path = $this->tmp_path( 'wdc-exports', 'locations-' . gmdate( 'Ymd-His' ) . '.jsonl' );
		$job = $this->snapshot_exporter->create_job( $path, $this->environment->version() );
		$job['download_path'] = $path;
		$this->update_option( self::SNAPSHOT_EXPORT_JOB_OPTION, $job );
		$this->send_json( $job );
	}

	public function ajax_snapshot_export_step(): void {
		$this->guard_ajax();
		$job = $this->get_option( self::SNAPSHOT_EXPORT_JOB_OPTION, array() );
		$job = is_array( $job ) && $this->snapshot_exporter instanceof LocationsSnapshotExporter ? $this->snapshot_exporter->step_job( $job ) : array( 'phase' => 'failed', 'errors' => array( 'Snapshot export job is unavailable.' ) );
		$this->update_option( self::SNAPSHOT_EXPORT_JOB_OPTION, $job );
		$this->send_json( $job );
	}

	public function ajax_snapshot_import_start(): void {
		$this->guard_ajax();
		if ( ! $this->snapshot_importer instanceof LocationsSnapshotImporter ) {
			$this->send_json( array( 'phase' => 'failed', 'errors' => array( 'Snapshot importer is unavailable.' ) ) );
		}
		$path = $this->persist_upload( 'wdc_locations_snapshot', 'wdc-imports', 'locations-snapshot.jsonl' );
		$job = $this->snapshot_importer->create_job( $path );
		$this->update_option( self::SNAPSHOT_IMPORT_JOB_OPTION, $job );
		$this->send_json( $job );
	}

	public function ajax_snapshot_import_step(): void {
		$this->guard_ajax();
		$job = $this->get_option( self::SNAPSHOT_IMPORT_JOB_OPTION, array() );
		$job = is_array( $job ) && $this->snapshot_importer instanceof LocationsSnapshotImporter ? $this->snapshot_importer->step_job( $job ) : array( 'phase' => 'failed', 'errors' => array( 'Snapshot import job is unavailable.' ) );
		$this->update_option( self::SNAPSHOT_IMPORT_JOB_OPTION, $job );
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

	public function ajax_display_name_rebuild_start(): void {
		$this->guard_ajax();
		$job = array(
			'job_id'          => md5( 'display-name-' . microtime( true ) ),
			'total'           => $this->repository->count_all(),
			'processed'       => 0,
			'updated'         => 0,
			'aliases_updated' => 0,
			'last_id'         => 0,
			'phase'           => 'running',
			'errors'          => array(),
			'started_at'      => current_time( 'mysql' ),
			'updated_at'      => current_time( 'mysql' ),
			'current_batch'   => 0,
		);
		$this->update_option( self::DISPLAY_REBUILD_JOB_OPTION, $job );
		$this->send_json( $job );
	}

	public function ajax_display_name_rebuild_step(): void {
		$this->guard_ajax();
		$job = $this->get_option( self::DISPLAY_REBUILD_JOB_OPTION, array() );
		$job = is_array( $job ) ? $this->step_display_name_rebuild_job( $job ) : array( 'phase' => 'failed', 'errors' => array( 'Display name rebuild job is unavailable.' ) );
		$this->update_option( self::DISPLAY_REBUILD_JOB_OPTION, $job );
		$this->send_json( $job );
	}

	public function ajax_display_name_rebuild_status(): void {
		$this->guard_ajax();
		$job = $this->get_option( self::DISPLAY_REBUILD_JOB_OPTION, array( 'phase' => 'idle' ) );
		$this->send_json( is_array( $job ) ? $job : array( 'phase' => 'idle' ) );
	}

	public function ajax_display_name_rebuild_cancel(): void {
		$this->guard_ajax();
		$job = $this->get_option( self::DISPLAY_REBUILD_JOB_OPTION, array() );
		$job = is_array( $job ) ? $job : array();
		$job['phase'] = 'canceled';
		$job['updated_at'] = current_time( 'mysql' );
		$this->update_option( self::DISPLAY_REBUILD_JOB_OPTION, $job );
		$this->send_json( $job );
	}

	public function ajax_dadata_postcode_fill_start(): void {
		$this->guard_ajax();
		$job = array(
			'job_id'             => md5( 'dadata-postcode-' . microtime( true ) ),
			'phase'              => 'running',
			'total'              => $this->repository->count_without_postal_code(),
			'processed'          => 0,
			'updated'            => 0,
			'marked_no_index'    => 0,
			'skipped'            => 0,
			'errors'             => 0,
			'consecutive_errors' => 0,
			'last_id'            => 0,
			'current_priority'   => 'cities',
			'tokens_exhausted'   => false,
			'started_at'         => current_time( 'mysql' ),
			'updated_at'         => current_time( 'mysql' ),
			'last_error'         => '',
			'last_location_id'   => 0,
			'last_fias_id'       => '',
			'last_place_name'    => '',
			'reason'             => '',
		);
		$this->update_option( self::DADATA_POSTCODE_JOB_OPTION, $job );
		$this->send_json( $job );
	}

	public function ajax_dadata_postcode_fill_step(): void {
		$this->guard_ajax();
		$job = $this->get_option( self::DADATA_POSTCODE_JOB_OPTION, array() );
		$job = is_array( $job ) ? $this->step_dadata_postcode_job( $job ) : $this->dadata_postcode_failed_job( 'DaData postcode fill job is unavailable.' );
		$this->update_option( self::DADATA_POSTCODE_JOB_OPTION, $job );
		$this->send_json( $job );
	}

	public function ajax_dadata_postcode_fill_status(): void {
		$this->guard_ajax();
		$job = $this->get_option( self::DADATA_POSTCODE_JOB_OPTION, array( 'phase' => 'idle' ) );
		$this->send_json( is_array( $job ) ? $job : array( 'phase' => 'idle' ) );
	}

	public function ajax_dadata_postcode_fill_cancel(): void {
		$this->guard_ajax();
		$job = $this->get_option( self::DADATA_POSTCODE_JOB_OPTION, array() );
		$job = is_array( $job ) ? $job : array();
		$job['phase'] = 'canceled';
		$job['updated_at'] = current_time( 'mysql' );
		$this->update_option( self::DADATA_POSTCODE_JOB_OPTION, $job );
		$this->send_json( $job );
	}

	public function ajax_dadata_coordinates_fill_start(): void {
		$this->guard_ajax();
		$existing = $this->get_option( self::DADATA_COORDINATES_JOB_OPTION, array() );
		if ( is_array( $existing ) && 'running' === (string) ( $existing['phase'] ?? '' ) ) {
			$this->send_json( $existing );
			return;
		}
		if ( is_array( $existing ) && $this->is_dadata_coordinates_limit_exhausted_job( $existing ) ) {
			$now = current_time( 'mysql' );
			$existing['phase'] = 'running';
			$existing['status'] = 'running';
			$existing['reason'] = '';
			$existing['stopped_reason'] = '';
			$existing['tokens_exhausted'] = false;
			$existing['last_error'] = '';
			$existing['message'] = '';
			$existing['last_dadata_message'] = '';
			$existing['current_batch'] = array();
			$existing['resumed_at'] = $now;
			$existing['updated_at'] = $now;
			$this->update_option( self::DADATA_COORDINATES_JOB_OPTION, $existing );
			$this->send_json( $existing );
			return;
		}

		$resume_after_id = 0;
		$total = $this->repository->count_locations_missing_coordinates();
		$now = current_time( 'mysql' );
		$job = array(
			'job_id'           => md5( 'dadata-coordinates-' . microtime( true ) ),
			'phase'            => $total > 0 ? 'running' : 'finished',
			'status'           => $total > 0 ? 'running' : 'finished',
			'resume_after_id'  => $resume_after_id,
			'resume_strategy'  => 'from_start_missing_only',
			'total'            => $total,
			'processed'        => 0,
			'updated'          => 0,
			'skipped'          => 0,
			'skipped_empty_query' => 0,
			'skipped_no_dadata_success' => 0,
			'skipped_no_coordinates' => 0,
			'skipped_invalid_coordinates' => 0,
			'failed'           => 0,
			'errors'           => 0,
			'last_id'          => $resume_after_id,
			'cursor'           => $resume_after_id,
			'current_priority' => 'cities',
			'current_batch'    => array(),
			'started_at'       => $now,
			'finished_at'      => $total > 0 ? '' : $now,
			'updated_at'       => $now,
			'last_error'       => '',
			'last_skip_reason' => '',
			'last_dadata_message' => '',
			'last_location_id' => 0,
			'last_place_name'  => '',
			'last_query'       => '',
		);
		if ( 0 === $total ) {
			$job['message'] = __( 'Нет населенных пунктов без координат.', 'walls-delivery-calc' );
		}
		$this->update_option( self::DADATA_COORDINATES_JOB_OPTION, $job );
		$this->send_json( $job );
	}

	public function ajax_dadata_coordinates_fill_step(): void {
		$this->guard_ajax();
		$job = $this->get_option( self::DADATA_COORDINATES_JOB_OPTION, array() );
		$job = is_array( $job ) ? $this->step_dadata_coordinates_job( $job ) : $this->dadata_coordinates_failed_job( 'DaData coordinates fill job is unavailable.' );
		$this->update_option( self::DADATA_COORDINATES_JOB_OPTION, $job );
		$this->send_json( $job );
	}

	public function ajax_dadata_coordinates_fill_status(): void {
		$this->guard_ajax();
		$job = $this->get_option( self::DADATA_COORDINATES_JOB_OPTION, array( 'phase' => 'idle', 'status' => 'idle' ) );
		$this->send_json( is_array( $job ) ? $job : array( 'phase' => 'idle', 'status' => 'idle' ) );
	}

	public function ajax_dadata_coordinates_fill_cancel(): void {
		$this->guard_ajax();
		$job = $this->get_option( self::DADATA_COORDINATES_JOB_OPTION, array() );
		$job = is_array( $job ) ? $job : array();
		$job['phase'] = 'canceled';
		$job['status'] = 'canceled';
		$job['updated_at'] = current_time( 'mysql' );
		$this->update_option( self::DADATA_COORDINATES_JOB_OPTION, $job );
		$this->send_json( $job );
	}

	public function ajax_dadata_coordinates_fill_reset(): void {
		$this->guard_ajax();
		$this->delete_option( self::DADATA_COORDINATES_JOB_OPTION );
		$this->send_json(
			array(
				'phase' => 'idle',
				'status' => 'idle',
				'processed' => 0,
				'updated' => 0,
				'skipped' => 0,
				'failed' => 0,
				'errors' => 0,
				'message' => __( 'Прогресс задачи координат обнулен. Уже записанные координаты не удалялись.', 'walls-delivery-calc' ),
				'updated_at' => current_time( 'mysql' ),
			)
		);
	}

	public function ajax_dadata_postcode_clear_markers(): void {
		$this->guard_ajax();
		$cleared = $this->repository->clear_postal_code_marker( self::DADATA_POSTCODE_MARKER );
		$this->send_json(
			array(
				'phase' => 'finished',
				'cleared' => $cleared,
				'message' => sprintf( __( 'Очищено технических значений: %d.', 'walls-delivery-calc' ), $cleared ),
				'total' => $this->repository->count_without_postal_code(),
				'processed' => $cleared,
				'updated_at' => current_time( 'mysql' ),
			)
		);
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function step_dadata_postcode_job( array $job ): array {
		if ( 'running' !== (string) ( $job['phase'] ?? '' ) ) {
			return $job;
		}

		if ( ! $this->postcode_client instanceof DaDataPostcodeClient ) {
			return $this->fail_dadata_postcode_job( $job, 'DaData postcode client is unavailable.' );
		}

		$limit = random_int( 10, 20 );
		$phase = (string) ( $job['current_priority'] ?? 'cities' );
		$batch = 'cities' === $phase
			? $this->repository->next_postcode_batch( true, $limit, (int) ( $job['last_id'] ?? 0 ) )
			: $this->repository->random_postcode_batch_for_non_cities( $limit );

		if ( array() === $batch && 'cities' === $phase ) {
			$job['current_priority'] = 'others';
			$job['last_id'] = 0;
			$batch = $this->repository->random_postcode_batch_for_non_cities( $limit );
		}

		if ( array() === $batch ) {
			$job['phase'] = 'finished';
			$job['updated_at'] = current_time( 'mysql' );
			return $job;
		}

		foreach ( $batch as $location ) {
			$location_id = (int) ( $location['id'] ?? 0 );
			$fias_id = (string) ( $location['fias_id'] ?? '' );
			$place_name = (string) ( $location['place_name'] ?? $location['settlement_name'] ?? '' );
			$job['last_location_id'] = $location_id;
			$job['last_fias_id'] = $fias_id;
			$job['last_place_name'] = $place_name;
			if ( 'cities' === (string) ( $job['current_priority'] ?? '' ) ) {
				$job['last_id'] = max( (int) ( $job['last_id'] ?? 0 ), $location_id );
			}

			if ( $location_id <= 0 || '' === trim( $fias_id ) || '' !== trim( (string) ( $location['postal_code'] ?? '' ) ) ) {
				++$job['skipped'];
				continue;
			}

			++$job['processed'];
			$result = $this->postcode_client->find_postal_code( $location );
			if ( ! empty( $result['tokens_exhausted'] ) ) {
				$job['phase'] = 'finished';
				$job['reason'] = 'daily_limit_exhausted';
				$job['tokens_exhausted'] = true;
				$job['last_error'] = __( 'Суточный лимит всех токенов DaData исчерпан. Продолжите обработку позже повторным нажатием кнопки.', 'walls-delivery-calc' );
				break;
			}

			if ( empty( $result['success'] ) ) {
				++$job['errors'];
				++$job['consecutive_errors'];
				$job['last_error'] = (string) ( $result['error_message'] ?? $result['error_code'] ?? 'DaData error.' );
				if ( (int) $job['consecutive_errors'] >= 30 ) {
					$job['phase'] = 'failed';
					$job['last_error'] = __( 'DaData стабильно возвращает ошибки или неподходящие данные. Работа остановлена после 30 ошибок подряд.', 'walls-delivery-calc' );
					break;
				}
				continue;
			}

			$postcode = trim( (string) ( $result['postal_code'] ?? '' ) );
			if ( '' === $postcode ) {
				$postcode = self::DADATA_POSTCODE_MARKER;
				if ( $this->repository->update_postal_code( $location_id, $postcode ) ) {
					++$job['marked_no_index'];
				}
			} elseif ( $this->repository->update_postal_code( $location_id, $postcode ) ) {
				++$job['updated'];
			}

			$job['consecutive_errors'] = 0;
		}

		$job['updated_at'] = current_time( 'mysql' );
		return $job;
	}

	/**
	 * @return array<string,mixed>
	 */
	private function dadata_postcode_failed_job( string $message ): array {
		return array(
			'phase' => 'failed',
			'processed' => 0,
			'updated' => 0,
			'marked_no_index' => 0,
			'skipped' => 0,
			'errors' => 1,
			'consecutive_errors' => 1,
			'tokens_exhausted' => false,
			'last_error' => $message,
			'updated_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function fail_dadata_postcode_job( array $job, string $message ): array {
		$job['phase'] = 'failed';
		$job['last_error'] = $message;
		$job['errors'] = (int) ( $job['errors'] ?? 0 ) + 1;
		$job['consecutive_errors'] = (int) ( $job['consecutive_errors'] ?? 0 ) + 1;
		$job['updated_at'] = current_time( 'mysql' );
		return $job;
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function step_dadata_coordinates_job( array $job ): array {
		if ( ! $this->coordinates_updater instanceof LocationCoordinatesDadataBatchUpdater ) {
			return $this->fail_dadata_coordinates_job( $job, 'DaData coordinates updater is unavailable.' );
		}

		return $this->coordinates_updater->step( $job, random_int( 20, 30 ) );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function dadata_coordinates_failed_job( string $message ): array {
		return array(
			'phase' => 'failed',
			'status' => 'failed',
			'processed' => 0,
			'updated' => 0,
			'skipped' => 0,
			'failed' => 1,
			'errors' => 1,
			'last_error' => $message,
			'updated_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function fail_dadata_coordinates_job( array $job, string $message ): array {
		$job['phase'] = 'failed';
		$job['status'] = 'failed';
		$job['last_error'] = $message;
		$job['failed'] = (int) ( $job['failed'] ?? 0 ) + 1;
		$job['errors'] = (int) ( $job['errors'] ?? 0 ) + 1;
		$job['updated_at'] = current_time( 'mysql' );
		return $job;
	}

	/**
	 * @param array<string,mixed> $job
	 */
	private function is_dadata_coordinates_limit_exhausted_job( array $job ): bool {
		$phase = (string) ( $job['phase'] ?? '' );
		$status = (string) ( $job['status'] ?? '' );
		if ( 'finished' !== $phase && 'finished' !== $status ) {
			return false;
		}

		return 'daily_limit_exhausted' === (string) ( $job['reason'] ?? '' )
			|| 'daily_limit_exhausted' === (string) ( $job['stopped_reason'] ?? '' )
			|| ! empty( $job['tokens_exhausted'] );
	}

	/**
	 * @param array<string,mixed> $job
	 * @return array<string,mixed>
	 */
	private function step_display_name_rebuild_job( array $job ): array {
		if ( 'running' !== (string) ( $job['phase'] ?? '' ) ) {
			return $job;
		}

		try {
			$formatter = LocationDisplayNameFormatter::from_rules( $this->type_display_rules() );
			$alias_generator = new LocationAliasGenerator();
			$locations = $this->repository->find_batch_after_id( (int) ( $job['last_id'] ?? 0 ), 500 );
			$aliases = array();
			$updated = 0;
			$last_id = (int) ( $job['last_id'] ?? 0 );
			foreach ( $locations as $location ) {
				$last_id = max( $last_id, (int) $location->id );
				$display = $formatter->format_location( $location );
				if ( '' === $display ) {
					$display = $location->resolved_display_name();
				}
				if ( $this->repository->update_display_fields( $location, $display ) ) {
					++$updated;
				}
				if ( null !== $location->id && $location->id > 0 ) {
					$aliases[ (int) $location->id ] = $alias_generator->generate( Location::from_array( array_merge( $location->to_array(), array( 'display_name' => $display ) ) ) );
				}
			}
			$aliases_updated = $this->repository->bulk_save_aliases( $aliases, 'gar_import' );
			$job['processed'] = (int) ( $job['processed'] ?? 0 ) + count( $locations );
			$job['updated'] = (int) ( $job['updated'] ?? 0 ) + $updated;
			$job['aliases_updated'] = (int) ( $job['aliases_updated'] ?? 0 ) + $aliases_updated;
			$job['last_id'] = $last_id;
			$job['current_batch'] = count( $locations );
			if ( array() === $locations || (int) $job['processed'] >= (int) ( $job['total'] ?? 0 ) ) {
				$job['phase'] = 'finished';
			}
		} catch ( RuntimeException $exception ) {
			$job['phase'] = 'failed';
			$job['errors'][] = $exception->getMessage();
		}

		$job['updated_at'] = current_time( 'mysql' );
		return $job;
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

	private function get_option( string $key, mixed $default = false ): mixed {
		return function_exists( 'get_option' ) ? get_option( $key, $default ) : $default;
	}

	private function update_option( string $key, mixed $value ): bool {
		return function_exists( 'update_option' ) ? update_option( $key, $value, false ) : true;
	}

	private function delete_option( string $key ): bool {
		return function_exists( 'delete_option' ) ? delete_option( $key ) : true;
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
