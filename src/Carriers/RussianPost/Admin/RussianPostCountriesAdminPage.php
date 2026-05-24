<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryMapping;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryMappingRepository;
use WallsShop\WDC\Carriers\RussianPost\RussianPostCountryMappingService;

defined( 'ABSPATH' ) || exit;

final class RussianPostCountriesAdminPage {
	public const PAGE_SLUG = 'wdc-russian-post-countries';
	private const NONCE_ACTION = 'wdc_russian_post_countries';
	private const NONCE_NAME = 'wdc_russian_post_countries_nonce';

	/** @var array<string,mixed> */
	private array $last_preview = array();

	public function __construct(
		private RussianPostCountryMappingRepository $repository,
		private RussianPostCountryMappingService $service
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	public function add_menu_page(): void {
		add_submenu_page( AdminMenu::MENU_SLUG, esc_html__( 'Почта России: страны', 'walls-delivery-calc' ), esc_html__( 'Почта России: страны', 'walls-delivery-calc' ), AdminMenu::CAPABILITY, self::PAGE_SLUG, array( $this, 'render_page' ) );
	}

	public function render_page(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$message = $this->handle_post();
		$filter  = isset( $_GET['rp_filter'] ) ? sanitize_key( wp_unslash( (string) $_GET['rp_filter'] ) ) : 'all';
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( (string) $_GET['s'] ) ) : '';
		$per_page = isset( $_GET['per_page'] ) ? absint( wp_unslash( (string) $_GET['per_page'] ) ) : 20;
		$page    = isset( $_GET['paged'] ) ? absint( wp_unslash( (string) $_GET['paged'] ) ) : 1;
		$list    = $this->repository->list( $filter, $search, $page, $per_page );
		$stats   = $this->repository->count_stats();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Почта России — страны', 'walls-delivery-calc' ); ?></h1>
			<?php if ( '' !== $message ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div><?php endif; ?>

			<form method="post" style="margin: 12px 0;">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<button class="button button-primary" type="submit" name="wdc_rp_country_action" value="refresh"><?php echo esc_html__( 'Обновить справочник стран Почты России', 'walls-delivery-calc' ); ?></button>
			</form>

			<?php $this->render_stats( $stats ); ?>
			<?php $this->render_bulk_forms(); ?>
			<?php $this->render_preview(); ?>
			<?php $this->render_filters( $filter, $search, $per_page ); ?>
			<?php $this->render_table( $list['items'] ); ?>
			<?php $this->render_pagination( (int) $list['total'], (int) $list['page'], (int) $list['per_page'], $filter, $search ); ?>
		</div>
		<?php
	}

	/**
	 * @param array<string,int|string> $stats
	 */
	private function render_stats( array $stats ): void {
		$rows = array(
			'Стран WooCommerce' => (int) ( $stats['total'] ?? 0 ),
			'Найдено сопоставлений' => (int) ( $stats['matched'] ?? 0 ),
			'Доступно по API' => (int) ( $stats['api_available'] ?? 0 ),
			'Включено эффективно' => (int) ( $stats['enabled'] ?? 0 ),
			'Ручное включение' => (int) ( $stats['manual_enabled'] ?? 0 ),
			'Ручное отключение' => (int) ( $stats['manual_disabled'] ?? 0 ),
			'Пропущено/не сопоставлено' => (int) ( $stats['skipped'] ?? 0 ),
			'Дата последней проверки' => (string) ( $stats['last_checked_at'] ?? '' ),
		);
		echo '<table class="widefat striped" style="max-width: 860px;"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( (string) $value ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function render_bulk_forms(): void {
		?>
		<style>
			.wdc-rp-bulk-columns {
				display: flex;
				gap: 24px;
				align-items: flex-start;
				flex-wrap: wrap;
				margin: 12px 0;
			}
			.wdc-rp-bulk-column {
				display: flex;
				flex-direction: column;
				gap: 6px;
			}
			.wdc-rp-bulk-column textarea {
				width: 360px;
				max-width: 100%;
			}
		</style>
		<h2><?php echo esc_html__( 'Bulk-вставка списков', 'walls-delivery-calc' ); ?></h2>
		<form method="post">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="wdc_rp_country_action" value="preview_bulk">
			<p><?php echo esc_html__( 'Формат: 1 страна = 1 строка.', 'walls-delivery-calc' ); ?></p>
			<div class="wdc-rp-bulk-columns">
				<div class="wdc-rp-bulk-column">
					<label for="wdc-rp-available-countries"><strong><?php echo esc_html__( 'Страны, куда доставка есть', 'walls-delivery-calc' ); ?></strong></label>
					<textarea id="wdc-rp-available-countries" name="available_countries" rows="7" cols="45" placeholder="АВСТРИЯ&#10;АЗЕРБАЙДЖАН&#10;АЛБАНИЯ"></textarea>
				</div>
				<div class="wdc-rp-bulk-column">
					<label for="wdc-rp-unavailable-countries"><strong><?php echo esc_html__( 'Страны, куда доставки нет', 'walls-delivery-calc' ); ?></strong></label>
					<textarea id="wdc-rp-unavailable-countries" name="unavailable_countries" rows="7" cols="45"></textarea>
				</div>
			</div>
			<button class="button" type="submit"><?php echo esc_html__( 'Проверить изменения', 'walls-delivery-calc' ); ?></button>
		</form>
		<?php
	}

	private function render_preview(): void {
		if ( array() === $this->last_preview ) {
			return;
		}
		if ( empty( $this->last_preview['success'] ) ) {
			echo '<div class="notice notice-error"><p>' . esc_html__( 'Одна страна попала в оба списка. Изменения не применены.', 'walls-delivery-calc' ) . '</p></div>';
			return;
		}
		$payload = base64_encode( wp_json_encode( $this->last_preview ) ?: '' );
		echo '<h2>' . esc_html__( 'Preview изменений', 'walls-delivery-calc' ) . '</h2>';
		foreach ( array( 'available' => 'Будет включено вручную', 'unavailable' => 'Будет выключено вручную' ) as $bucket => $title ) {
			$data = is_array( $this->last_preview[ $bucket ] ?? null ) ? $this->last_preview[ $bucket ] : array();
			echo '<h3>' . esc_html( $title ) . '</h3>';
			$this->render_preview_list( 'Изменятся', is_array( $data['changes'] ?? null ) ? $data['changes'] : array() );
			$this->render_preview_list( 'Уже в правильном состоянии', is_array( $data['unchanged'] ?? null ) ? $data['unchanged'] : array() );
		}
		$this->render_string_list( 'Не распознаны', is_array( $this->last_preview['unrecognized'] ?? null ) ? $this->last_preview['unrecognized'] : array() );
		echo '<form method="post">';
		wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME );
		echo '<input type="hidden" name="wdc_rp_country_action" value="apply_bulk">';
		echo '<input type="hidden" name="preview_payload" value="' . esc_attr( $payload ) . '">';
		echo '<button class="button button-primary" type="submit">' . esc_html__( 'Применить изменения', 'walls-delivery-calc' ) . '</button>';
		echo '</form>';
	}

	/**
	 * @param array<int,array<string,string>> $rows
	 */
	private function render_preview_list( string $title, array $rows ): void {
		echo '<p><strong>' . esc_html( $title ) . ':</strong> ' . esc_html( (string) count( $rows ) ) . '</p>';
		if ( array() === $rows ) {
			return;
		}
		echo '<ul>';
		foreach ( $rows as $row ) {
			echo '<li>' . esc_html( (string) ( $row['wc_country_code'] ?? '' ) . ' — ' . (string) ( $row['wc_country_name'] ?? '' ) ) . '</li>';
		}
		echo '</ul>';
	}

	/**
	 * @param array<int,string> $rows
	 */
	private function render_string_list( string $title, array $rows ): void {
		echo '<p><strong>' . esc_html( $title ) . ':</strong> ' . esc_html( (string) count( $rows ) ) . '</p>';
		if ( array() === $rows ) {
			return;
		}
		echo '<ul>';
		foreach ( $rows as $row ) {
			echo '<li>' . esc_html( $row ) . '</li>';
		}
		echo '</ul>';
	}

	private function render_filters( string $filter, string $search, int $per_page ): void {
		$options = array( 'all' => 'Все', 'enabled' => 'Итог включен', 'disabled' => 'Итог выключен', 'matched' => 'Сопоставлено', 'unmatched' => 'Не сопоставлено', 'manual_enabled' => 'Ручное включение', 'manual_disabled' => 'Ручное отключение', 'auto' => 'Auto' );
		echo '<form method="get" style="margin: 16px 0;">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE_SLUG ) . '">';
		echo '<select name="rp_filter">';
		foreach ( $options as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $filter, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		echo '<input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="' . esc_attr__( 'Поиск страны или кода', 'walls-delivery-calc' ) . '"> ';
		echo '<select name="per_page">';
		foreach ( array( 20, 50, 100 ) as $value ) {
			echo '<option value="' . esc_attr( (string) $value ) . '" ' . selected( $per_page, $value, false ) . '>' . esc_html( (string) $value ) . '</option>';
		}
		echo '</select> ';
		echo '<button class="button" type="submit">' . esc_html__( 'Фильтровать', 'walls-delivery-calc' ) . '</button>';
		echo '</form>';
	}

	/**
	 * @param array<int,RussianPostCountryMapping> $items
	 */
	private function render_table( array $items ): void {
		echo '<table class="widefat striped"><thead><tr>';
		foreach ( array( 'WooCommerce код', 'Страна WooCommerce', 'Страна Почты России', 'ID Почты России', 'ISO2 Почты', 'Сопоставлено', 'Источник сопоставления', 'API доступно', 'Parcel', 'Block', 'Ручной режим', 'Итог', 'Комментарий', 'Последняя проверка', 'Действия' ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $items as $item ) {
			echo '<tr>';
			echo '<td>' . esc_html( $item->wc_country_code ) . '</td>';
			echo '<td>' . esc_html( $item->wc_country_name ) . '</td>';
			echo '<td>' . esc_html( $item->rp_country_name ) . '</td>';
			echo '<td>' . esc_html( $item->rp_country_id ) . '</td>';
			echo '<td>' . esc_html( $item->rp_iso2 ) . '</td>';
			echo '<td>' . esc_html( $this->yes_no( $item->matched ) ) . '</td>';
			echo '<td>' . esc_html( $item->match_source ) . '</td>';
			echo '<td>' . esc_html( $this->yes_no( $item->api_available ) ) . '</td>';
			echo '<td>' . esc_html( $this->yes_no( $item->has_parcel ) ) . '</td>';
			echo '<td>' . esc_html( $this->yes_no( $item->parcel_block ) ) . '</td>';
			echo '<td>' . esc_html( $item->manual_mode ) . '</td>';
			echo '<td>' . esc_html( $this->yes_no( $item->effective_enabled ) ) . '</td>';
			echo '<td>' . esc_html( $item->manual_comment ) . '</td>';
			echo '<td>' . esc_html( (string) $item->last_checked_at ) . '</td>';
			echo '<td>' . $this->action_form( $item->wc_country_code ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	private function action_form( string $code ): string {
		$out = '<form method="post" style="display:flex;gap:4px;flex-wrap:wrap;">';
		$out .= wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME, true, false );
		$out .= '<input type="hidden" name="wdc_rp_country_action" value="manual_mode">';
		$out .= '<input type="hidden" name="wc_country_code" value="' . esc_attr( $code ) . '">';
		foreach ( array( RussianPostCountryMapping::MODE_AUTO => 'auto', RussianPostCountryMapping::MODE_ENABLED => 'включить', RussianPostCountryMapping::MODE_DISABLED => 'выключить' ) as $mode => $label ) {
			$out .= '<button class="button button-small" type="submit" name="manual_mode" value="' . esc_attr( $mode ) . '">' . esc_html( $label ) . '</button>';
		}
		$out .= '</form>';

		return $out;
	}

	private function render_pagination( int $total, int $page, int $per_page, string $filter, string $search ): void {
		$pages = (int) ceil( $total / max( 1, $per_page ) );
		if ( $pages <= 1 ) {
			return;
		}
		echo '<p>';
		for ( $i = 1; $i <= $pages; ++$i ) {
			$url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'rp_filter' => $filter, 's' => $search, 'per_page' => $per_page, 'paged' => $i ), admin_url( 'admin.php' ) );
			echo '<a class="button ' . ( $i === $page ? 'button-primary' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( (string) $i ) . '</a> ';
		}
		echo '</p>';
	}

	private function handle_post(): string {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return '';
		}
		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) || ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return '';
		}
		$action = isset( $_POST['wdc_rp_country_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['wdc_rp_country_action'] ) ) : '';
		if ( 'refresh' === $action ) {
			$stats = $this->service->refresh_from_api();
			return sprintf( __( 'Справочник обновлен. Сопоставлено: %d, включено: %d.', 'walls-delivery-calc' ), (int) $stats['matched'], (int) $stats['enabled'] );
		}
		if ( 'manual_mode' === $action ) {
			$code = isset( $_POST['wc_country_code'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['wc_country_code'] ) ) : '';
			$mode = isset( $_POST['manual_mode'] ) ? sanitize_key( wp_unslash( (string) $_POST['manual_mode'] ) ) : RussianPostCountryMapping::MODE_AUTO;
			$this->repository->set_manual_mode( $code, $mode, $this->manual_comment() );
			return __( 'Ручной режим сохранен.', 'walls-delivery-calc' );
		}
		if ( 'preview_bulk' === $action ) {
			$this->last_preview = $this->service->preview_bulk_lists( $this->textarea_lines( 'available_countries' ), $this->textarea_lines( 'unavailable_countries' ) );
			return __( 'Preview подготовлен.', 'walls-delivery-calc' );
		}
		if ( 'apply_bulk' === $action ) {
			$payload = isset( $_POST['preview_payload'] ) ? base64_decode( sanitize_text_field( wp_unslash( (string) $_POST['preview_payload'] ) ), true ) : '';
			$preview = is_string( $payload ) ? json_decode( $payload, true ) : array();
			$result = $this->service->apply_bulk_preview( is_array( $preview ) ? $preview : array() );
			return sprintf( __( 'Bulk-изменения применены: %d.', 'walls-delivery-calc' ), (int) ( $result['updated'] ?? 0 ) );
		}

		return '';
	}

	/**
	 * @return array<int,string>
	 */
	private function textarea_lines( string $key ): array {
		$value = isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( (string) $_POST[ $key ] ) ) : '';

		return preg_split( '/\R/u', $value ) ?: array();
	}

	private function manual_comment(): string {
		return 'изменено вручную ' . ( function_exists( 'wp_date' ) ? wp_date( 'd.m.Y' ) : gmdate( 'd.m.Y' ) );
	}

	private function yes_no( bool $value ): string {
		return $value ? 'да' : 'нет';
	}
}
