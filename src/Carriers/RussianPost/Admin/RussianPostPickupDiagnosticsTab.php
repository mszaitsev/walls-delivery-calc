<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\DeliveryServices\Admin\DeliveryServicesAdminPage;
use WallsShop\WDC\Pickup\RussianPost\RussianPostPickupDiagnosticsService;

defined( 'ABSPATH' ) || exit;

final class RussianPostPickupDiagnosticsTab {
	public const TAB_KEY = 'russian_post_pickup_diagnostics';
	private const EXPORT_QUERY = 'wdc_pickup_diagnostics_export';
	private const NONCE_ACTION = 'wdc_russian_post_pickup_diagnostics_rebind';
	private const NONCE_NAME = 'wdc_pickup_diagnostics_nonce';

	public function __construct(
		private RussianPostPickupDiagnosticsService $diagnostics
	) {
	}

	public function register(): void {
		add_action( 'admin_init', array( $this, 'handle_csv_export' ), 0 );
	}

	public function render(): void {
		$problem = $this->current_problem();
		$page = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;
		$per_page = isset( $_GET['per_page'] ) ? max( 1, min( 100, (int) $_GET['per_page'] ) ) : RussianPostPickupDiagnosticsService::DEFAULT_PER_PAGE;
		$notice = $this->handle_rebind_post( $problem );
		$summary = $this->diagnostics->summary();
		$list = $this->diagnostics->list_problematic( $problem, $page, $per_page );
		$total_pages = max( 1, (int) ceil( $list['total'] / $per_page ) );
		?>
		<section class="wdc-russian-post-pickup-diagnostics">
			<h3><?php echo esc_html__( 'Диагностика базы ПВЗ', 'walls-delivery-calc' ); ?></h3>
			<p><a href="<?php echo esc_url( $this->pickup_tab_url() ); ?>"><?php echo esc_html__( 'Назад к ПВЗ', 'walls-delivery-calc' ); ?></a></p>
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
				<input type="hidden" name="page" value="<?php echo esc_attr( DeliveryServicesAdminPage::MENU_SLUG ); ?>">
				<input type="hidden" name="service" value="<?php echo esc_attr( RussianPostDomesticSettings::SERVICE_KEY ); ?>">
				<input type="hidden" name="tab" value="<?php echo esc_attr( self::TAB_KEY ); ?>">
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
				<a class="button" href="<?php echo esc_url( $this->diagnostics_url( array( 'problem' => $list['problem'], self::EXPORT_QUERY => '1' ) ) ); ?>"><?php echo esc_html__( 'Экспорт CSV', 'walls-delivery-calc' ); ?></a>
			</form>

			<form method="post" action="<?php echo esc_url( $this->diagnostics_url( array( 'problem' => $list['problem'], 'per_page' => $per_page, 'paged' => $page ) ) ); ?>" style="margin:16px 0;">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
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
					<a class="button" href="<?php echo esc_url( $this->diagnostics_url( array( 'problem' => $list['problem'], 'per_page' => $per_page, 'paged' => $page - 1 ) ) ); ?>"><?php echo esc_html__( 'Назад', 'walls-delivery-calc' ); ?></a>
				<?php endif; ?>
				<?php if ( $page < $total_pages ) : ?>
					<a class="button" href="<?php echo esc_url( $this->diagnostics_url( array( 'problem' => $list['problem'], 'per_page' => $per_page, 'paged' => $page + 1 ) ) ); ?>"><?php echo esc_html__( 'Вперед', 'walls-delivery-calc' ); ?></a>
				<?php endif; ?>
			</p>
		</section>
		<?php
	}

	public function handle_csv_export(): void {
		if ( ! $this->is_diagnostics_route() || ! isset( $_GET[ self::EXPORT_QUERY ] ) ) {
			return;
		}
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$csv = $this->diagnostics->export_csv( $this->current_problem() );
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $this->diagnostics->filename() . '"' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private function handle_rebind_post( string $problem ): string {
		if ( ! isset( $_POST['wdc_pickup_diagnostics_action'] ) || 'rebind' !== (string) $_POST['wdc_pickup_diagnostics_action'] ) {
			return '';
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return __( 'Недостаточно прав для rebind-действия.', 'walls-delivery-calc' );
		}
		if ( ! isset( $_POST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return __( 'Nonce не прошел проверку.', 'walls-delivery-calc' );
		}

		$apply = ! empty( $_POST['apply_rebind'] );
		$result = $apply ? $this->diagnostics->rebind_apply( $problem ) : $this->diagnostics->rebind_dry_run( $problem );
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

	private function is_diagnostics_route(): bool {
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : '';
		$service = isset( $_GET['service'] ) ? sanitize_key( wp_unslash( (string) $_GET['service'] ) ) : '';
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : '';

		return DeliveryServicesAdminPage::MENU_SLUG === $page
			&& RussianPostDomesticSettings::SERVICE_KEY === $service
			&& self::TAB_KEY === $tab;
	}

	private function current_problem(): string {
		return isset( $_GET['problem'] ) ? sanitize_key( wp_unslash( (string) $_GET['problem'] ) ) : RussianPostPickupDiagnosticsService::DEFAULT_PROBLEM;
	}

	/**
	 * @param array<string,mixed> $args
	 */
	private function diagnostics_url( array $args = array() ): string {
		return add_query_arg(
			array_merge(
				array(
					'page' => DeliveryServicesAdminPage::MENU_SLUG,
					'service' => RussianPostDomesticSettings::SERVICE_KEY,
					'tab' => self::TAB_KEY,
				),
				$args
			),
			admin_url( 'admin.php' )
		);
	}

	private function pickup_tab_url(): string {
		return add_query_arg(
			array(
				'page' => DeliveryServicesAdminPage::MENU_SLUG,
				'service' => RussianPostDomesticSettings::SERVICE_KEY,
				'tab' => 'russian_post_pickup',
			),
			admin_url( 'admin.php' )
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
