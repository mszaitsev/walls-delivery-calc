<?php
declare(strict_types=1);

namespace WallsShop\WDC\Pickup\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupDiagnosticsService;
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
		private ?RussianPostOtpravkaApiSettings $russian_post_settings = null,
		private ?RussianPostPickupDiagnosticsService $diagnostics = null
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

		$view = isset( $_GET['wdc_pickup_view'] ) ? sanitize_key( wp_unslash( (string) $_GET['wdc_pickup_view'] ) ) : '';
		if ( 'diagnostics' === $view ) {
			$this->render_diagnostics_page();
			return;
		}

		$cleanup_count = null;
		if ( ! (bool) get_option( self::DEMO_PICKUP_CLEANUP_OPTION, false ) ) {
			$cleanup_count = $this->repository->delete_by_carrier_keys( self::DEMO_CARRIER_KEYS );
			update_option( self::DEMO_PICKUP_CLEANUP_OPTION, true, false );
		}

		$city = isset( $_GET['pickup_city'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['pickup_city'] ) ) : '';
		$carrier = isset( $_GET['pickup_carrier'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['pickup_carrier'] ) ) : '';
		$points = '' !== trim( $city ) && '' !== trim( $carrier ) ? $this->repository->search( $carrier, 'RU', $city ) : array();
		$grouped = $this->group_by_city( $points );
		?>
		<div class="wrap">
			<?php if ( null !== $cleanup_count ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( sprintf( __( 'Демо-данные ПВЗ очищены: %d', 'walls-delivery-calc' ), $cleanup_count ) ); ?></p></div>
			<?php endif; ?>
			<h1><?php echo esc_html__( 'Пункты выдачи заказов', 'walls-delivery-calc' ); ?></h1>
			<p><a class="button button-secondary" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&wdc_pickup_view=diagnostics' ) ); ?>"><?php echo esc_html__( 'Диагностика базы ПВЗ Почты России', 'walls-delivery-calc' ); ?></a></p>
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

	private function render_diagnostics_page(): void {
		if ( ! $this->diagnostics instanceof RussianPostPickupDiagnosticsService ) {
			?>
			<div class="wrap"><h1><?php echo esc_html__( 'Диагностика базы ПВЗ Почты России', 'walls-delivery-calc' ); ?></h1><div class="notice notice-error"><p><?php echo esc_html__( 'Сервис диагностики недоступен.', 'walls-delivery-calc' ); ?></p></div></div>
			<?php
			return;
		}

		$problem = isset( $_GET['problem'] ) ? sanitize_key( wp_unslash( (string) $_GET['problem'] ) ) : RussianPostPickupDiagnosticsService::DEFAULT_PROBLEM;
		$page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page = isset( $_GET['per_page'] ) ? max( 1, min( 100, (int) $_GET['per_page'] ) ) : RussianPostPickupDiagnosticsService::DEFAULT_PER_PAGE;

		if ( isset( $_GET['wdc_pickup_diagnostics_export'] ) ) {
			$csv = $this->diagnostics->export_csv( $problem );
			header( 'Content-Type: text/csv; charset=UTF-8' );
			header( 'Content-Disposition: attachment; filename="' . $this->diagnostics->filename() . '"' );
			echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			exit;
		}

		$notice = $this->handle_diagnostics_post( $problem );
		$summary = $this->diagnostics->summary();
		$list = $this->diagnostics->list_problematic( $problem, $page, $per_page );
		$total_pages = max( 1, (int) ceil( $list['total'] / $per_page ) );
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Диагностика базы ПВЗ Почты России', 'walls-delivery-calc' ); ?></h1>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ); ?>"><?php echo esc_html__( 'Назад к ПВЗ', 'walls-delivery-calc' ); ?></a></p>
			<?php if ( '' !== $notice ) : ?>
				<div class="notice notice-info is-dismissible"><p><?php echo esc_html( $notice ); ?></p></div>
			<?php endif; ?>

			<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0;">
				<?php foreach ( $this->summary_labels() as $key => $label ) : ?>
					<div style="background:#fff;border:1px solid #ccd0d4;padding:12px;">
						<strong style="display:block;font-size:20px;"><?php echo esc_html( null === ( $summary[ $key ] ?? null ) ? __( 'по фильтру', 'walls-delivery-calc' ) : (string) ( $summary[ $key ] ?? 0 ) ); ?></strong>
						<span><?php echo esc_html( $label ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<p class="description"><?php echo esc_html__( 'Подозрительные координаты считаются только при выборе одноименного фильтра.', 'walls-delivery-calc' ); ?></p>
			<?php if ( 'suspicious_coordinates' === $list['problem'] ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html__( 'Фильтр подозрительных координат выполняет сопоставление с населенными пунктами и может быть тяжелее остальных проверок.', 'walls-delivery-calc' ); ?></p></div>
			<?php endif; ?>

			<form method="get" style="margin:16px 0;display:flex;gap:8px;align-items:end;flex-wrap:wrap;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
				<input type="hidden" name="wdc_pickup_view" value="diagnostics">
				<label>
					<span><?php echo esc_html__( 'Проблема', 'walls-delivery-calc' ); ?></span><br>
					<select name="problem">
						<?php foreach ( $this->problem_labels() as $value => $label ) : ?>
							<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $list['problem'], $value ); ?>><?php echo esc_html( $label ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<label>
					<span><?php echo esc_html__( 'На странице', 'walls-delivery-calc' ); ?></span><br>
					<select name="per_page">
						<?php foreach ( array( 50, 100 ) as $value ) : ?>
							<option value="<?php echo esc_attr( (string) $value ); ?>" <?php selected( $per_page, $value ); ?>><?php echo esc_html( (string) $value ); ?></option>
						<?php endforeach; ?>
					</select>
				</label>
				<button class="button button-primary" type="submit"><?php echo esc_html__( 'Применить', 'walls-delivery-calc' ); ?></button>
				<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'wdc_pickup_view' => 'diagnostics', 'problem' => $list['problem'], 'wdc_pickup_diagnostics_export' => '1' ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Экспорт CSV', 'walls-delivery-calc' ); ?></a>
			</form>

			<form method="post" style="margin:16px 0;">
				<?php wp_nonce_field( 'wdc_russian_post_pickup_diagnostics_rebind', 'wdc_pickup_diagnostics_nonce' ); ?>
				<input type="hidden" name="wdc_pickup_diagnostics_action" value="rebind">
				<input type="hidden" name="problem" value="<?php echo esc_attr( $list['problem'] ); ?>">
				<label><input type="checkbox" name="apply_rebind" value="1"> <?php echo esc_html__( 'Применить изменения', 'walls-delivery-calc' ); ?></label>
				<button class="button" type="submit"><?php echo esc_html__( 'Переопределить населенный пункт для проблемных ПВЗ', 'walls-delivery-calc' ); ?></button>
				<p class="description"><?php echo esc_html__( 'Без галочки выполняется только dry-run summary.', 'walls-delivery-calc' ); ?></p>
			</form>

			<table class="widefat striped">
				<thead>
					<tr>
						<th>ID</th><th>point_code</th><th>postal_code</th><th>region</th><th>city</th><th>address</th><th>fias_location_guid</th><th>location_id</th><th>lat</th><th>lng</th><th>problem flags</th><th>distance_to_location_km</th><th>updated_at/imported_at</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( array() === $list['items'] ) : ?>
						<tr><td colspan="13"><?php echo esc_html__( 'Проблемные ПВЗ не найдены.', 'walls-delivery-calc' ); ?></td></tr>
					<?php endif; ?>
					<?php foreach ( $list['items'] as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $row['id'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['point_code'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['postcode'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['region_name'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['city_name'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['address'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['fias_location_guid'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['location_id'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['latitude'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['longitude'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( implode( ', ', array_map( 'strval', is_array( $row['problem_flags'] ?? null ) ? $row['problem_flags'] : array() ) ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['distance_to_location_km'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( trim( (string) ( $row['updated_at'] ?? '' ) . ' / ' . (string) ( $row['last_seen_at'] ?? '' ), ' /' ) ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<p style="margin-top:12px;">
				<?php echo esc_html( sprintf( __( 'Страница %1$d из %2$d, всего проблемных строк: %3$d', 'walls-delivery-calc' ), $page, $total_pages, $list['total'] ) ); ?>
				<?php if ( $page > 1 ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'wdc_pickup_view' => 'diagnostics', 'problem' => $list['problem'], 'per_page' => $per_page, 'paged' => $page - 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Назад', 'walls-delivery-calc' ); ?></a>
				<?php endif; ?>
				<?php if ( $page < $total_pages ) : ?>
					<a class="button" href="<?php echo esc_url( add_query_arg( array( 'page' => self::PAGE_SLUG, 'wdc_pickup_view' => 'diagnostics', 'problem' => $list['problem'], 'per_page' => $per_page, 'paged' => $page + 1 ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html__( 'Вперед', 'walls-delivery-calc' ); ?></a>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	private function handle_diagnostics_post( string $problem ): string {
		if ( ! isset( $_POST['wdc_pickup_diagnostics_action'] ) || 'rebind' !== (string) $_POST['wdc_pickup_diagnostics_action'] ) {
			return '';
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return __( 'Недостаточно прав для rebind-действия.', 'walls-delivery-calc' );
		}
		if ( ! isset( $_POST['wdc_pickup_diagnostics_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST['wdc_pickup_diagnostics_nonce'] ) ), 'wdc_russian_post_pickup_diagnostics_rebind' ) ) {
			return __( 'Nonce не прошел проверку.', 'walls-delivery-calc' );
		}

		$apply = ! empty( $_POST['apply_rebind'] );
		$result = $apply ? $this->diagnostics?->rebind_apply( $problem ) : $this->diagnostics?->rebind_dry_run( $problem );
		if ( ! is_array( $result ) ) {
			return '';
		}
		$skipped = is_array( $result['skipped'] ?? null ) ? $result['skipped'] : array();

		return sprintf(
			$apply ? __( 'Rebind применен: проверено %1$d, запланировано %2$d, обновлено %3$d, пропущено no_match=%4$d, ambiguous=%5$d.', 'walls-delivery-calc' ) : __( 'Dry-run rebind: проверено %1$d, можно обновить %2$d, обновлено %3$d, пропущено no_match=%4$d, ambiguous=%5$d.', 'walls-delivery-calc' ),
			(int) ( $result['checked'] ?? 0 ),
			(int) ( $result['planned'] ?? 0 ),
			(int) ( $result['updated'] ?? 0 ),
			(int) ( $skipped['no_match'] ?? 0 ),
			(int) ( $skipped['ambiguous'] ?? 0 )
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function summary_labels(): array {
		return array(
			'total' => __( 'Всего ПВЗ Почты России', 'walls-delivery-calc' ),
			'active' => __( 'Активных ПВЗ', 'walls-delivery-calc' ),
			'missing_coordinates' => __( 'Без координат', 'walls-delivery-calc' ),
			'zero_coordinates' => __( 'С нулевыми координатами', 'walls-delivery-calc' ),
			'missing_fias' => __( 'Без fias_location_guid', 'walls-delivery-calc' ),
			'missing_postal_code' => __( 'Без postal_code', 'walls-delivery-calc' ),
			'dummy_postal_code' => __( 'postal_code = 999999999', 'walls-delivery-calc' ),
			'missing_address' => __( 'Без адреса', 'walls-delivery-calc' ),
			'missing_city' => __( 'Без города/населенного пункта', 'walls-delivery-calc' ),
			'missing_region' => __( 'Без региона', 'walls-delivery-calc' ),
			'missing_location' => __( 'Без location_id', 'walls-delivery-calc' ),
			'suspicious_coordinates' => __( 'Подозрительные координаты', 'walls-delivery-calc' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function problem_labels(): array {
		return array(
			'all_problematic' => __( 'Все проблемные', 'walls-delivery-calc' ),
			'missing_coordinates' => __( 'Без координат', 'walls-delivery-calc' ),
			'zero_coordinates' => __( 'Нулевые координаты', 'walls-delivery-calc' ),
			'missing_fias' => __( 'Без FIAS', 'walls-delivery-calc' ),
			'missing_postal_code' => __( 'Без postal_code', 'walls-delivery-calc' ),
			'dummy_postal_code' => __( 'postal_code = 999999999', 'walls-delivery-calc' ),
			'missing_address' => __( 'Без адреса', 'walls-delivery-calc' ),
			'missing_city' => __( 'Без города', 'walls-delivery-calc' ),
			'missing_region' => __( 'Без региона', 'walls-delivery-calc' ),
			'missing_location' => __( 'Без location_id', 'walls-delivery-calc' ),
			'suspicious_coordinates' => __( 'Подозрительные координаты', 'walls-delivery-calc' ),
		);
	}
}
