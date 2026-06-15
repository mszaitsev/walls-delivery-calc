<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Application\ShipmentOrderStatusMappingService;
use WallsShop\WDC\Shipments\Application\ShipmentStatusAutoSyncService;

defined( 'ABSPATH' ) || exit;

final class ShipmentStatusesAdminPage {
	public const PAGE_SLUG = 'wdc-shipment-statuses';

	private const NONCE_ACTION = 'wdc_shipment_statuses';
	private const NONCE_NAME = 'wdc_shipment_statuses_nonce';

	public function __construct(
		private SettingsRepository $settings,
		private ShipmentStatusAutoSyncService $auto_sync,
		private ShipmentOrderStatusMappingService $order_status_mapping
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	public function add_menu_page(): void {
		add_submenu_page(
			AdminMenu::MENU_SLUG,
			esc_html__( 'Статусы', 'walls-delivery-calc' ),
			esc_html__( 'Статусы', 'walls-delivery-calc' ),
			AdminMenu::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$message = $this->handle_post();
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( (string) $_GET['tab'] ) ) : 'main';
		if ( ! in_array( $tab, array( 'main', 'mapping', 'diagnostics' ), true ) ) {
			$tab = 'main';
		}
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Статусы отправлений', 'walls-delivery-calc' ); ?></h1>
			<?php if ( '' !== $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>
			<nav class="nav-tab-wrapper">
				<?php $this->tab_link( 'main', __( 'Основные', 'walls-delivery-calc' ), $tab ); ?>
				<?php $this->tab_link( 'mapping', __( 'Соответствие статусов', 'walls-delivery-calc' ), $tab ); ?>
				<?php $this->tab_link( 'diagnostics', __( 'Диагностика', 'walls-delivery-calc' ), $tab ); ?>
			</nav>
			<?php
			if ( 'mapping' === $tab ) {
				$this->render_order_mapping_tab();
			} elseif ( 'diagnostics' === $tab ) {
				$this->render_diagnostics_tab();
			} else {
				$this->render_main_tab();
			}
			?>
		</div>
		<?php
	}

	private function render_main_tab(): void {
		$selected_statuses = $this->auto_sync->selected_order_statuses();
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="wdc_statuses_action" value="save_settings">
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Автообновление статусов отправлений', 'walls-delivery-calc' ); ?></th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( ShipmentStatusAutoSyncService::ENABLED_KEY ); ?>" value="1" <?php checked( $this->auto_sync->enabled() ); ?>> <?php echo esc_html__( 'Включить автоматическое обновление статусов отправлений', 'walls-delivery-calc' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Периодичность', 'walls-delivery-calc' ); ?></th>
						<td>
							<p><?php echo esc_html__( 'Периодичность обновления: каждые 6 часов.', 'walls-delivery-calc' ); ?></p>
							<p class="description"><?php echo esc_html__( 'Настраиваемая периодичность будет добавлена позже.', 'walls-delivery-calc' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Статусы заказов WooCommerce для синхронизации', 'walls-delivery-calc' ); ?></th>
						<td><?php $this->render_order_status_checkboxes( $selected_statuses ); ?></td>
					</tr>
				</tbody>
			</table>
			<?php submit_button( __( 'Сохранить настройки', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function render_order_mapping_tab(): void {
		$mapping = $this->order_status_mapping->mapping();
		$order_statuses = $this->order_status_mapping->woo_order_statuses();
		?>
		<form method="post">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="wdc_statuses_action" value="save_mapping">
			<table class="form-table" role="presentation">
				<tbody>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Автоматическое изменение статусов заказов', 'walls-delivery-calc' ); ?></th>
						<td>
							<label><input type="checkbox" name="<?php echo esc_attr( ShipmentOrderStatusMappingService::ENABLED_KEY ); ?>" value="1" <?php checked( $this->order_status_mapping->enabled() ); ?>> <?php echo esc_html__( 'Включить автоматическое изменение статусов заказов', 'walls-delivery-calc' ); ?></label>
							<p class="description"><?php echo esc_html__( 'Если выключено, WDC не меняет статусы заказов, даже если соответствия заполнены.', 'walls-delivery-calc' ); ?></p>
						</td>
					</tr>
				</tbody>
			</table>
			<table class="widefat striped" style="max-width: 960px;">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Статус отправления', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Новый статус заказа', 'walls-delivery-calc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( DeliveryStatus::all() as $shipment_status ) : ?>
						<tr>
							<td>
								<strong><?php echo esc_html( DeliveryStatus::label( $shipment_status ) ); ?></strong>
								<br><code><?php echo esc_html( $shipment_status ); ?></code>
							</td>
							<td><?php $this->render_mapping_select( $shipment_status, (string) ( $mapping[ $shipment_status ] ?? '' ), $order_statuses ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Сохранить соответствия', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	private function render_diagnostics_tab(): void {
		$stats = $this->auto_sync->diagnostics();
		?>
		<form method="post" style="margin: 16px 0;">
			<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
			<input type="hidden" name="wdc_statuses_action" value="run_now">
			<?php submit_button( __( 'Запустить синхронизацию сейчас', 'walls-delivery-calc' ), 'primary', 'submit', false ); ?>
		</form>
		<table class="widefat striped" style="max-width: 760px;">
			<tbody>
				<?php $this->row( __( 'Последний запуск', 'walls-delivery-calc' ), (string) ( $stats['started_at'] ?? '' ) ); ?>
				<?php $this->row( __( 'Последнее завершение', 'walls-delivery-calc' ), (string) ( $stats['finished_at'] ?? '' ) ); ?>
				<?php $this->row( __( 'Тип запуска', 'walls-delivery-calc' ), (string) ( $stats['trigger_type'] ?? '' ) ); ?>
				<?php $this->row( __( 'Длительность', 'walls-delivery-calc' ), (string) ( (int) ( $stats['duration_ms'] ?? 0 ) ) . ' ms' ); ?>
				<?php $this->row( __( 'Заказов найдено', 'walls-delivery-calc' ), (string) (int) ( $stats['orders_scanned'] ?? 0 ) ); ?>
				<?php $this->row( __( 'Отправлений найдено', 'walls-delivery-calc' ), (string) (int) ( $stats['shipments_found'] ?? 0 ) ); ?>
				<?php $this->row( __( 'Обновлено', 'walls-delivery-calc' ), (string) (int) ( $stats['shipments_updated'] ?? 0 ) ); ?>
				<?php $this->row( __( 'Пропущено', 'walls-delivery-calc' ), (string) (int) ( $stats['shipments_skipped'] ?? 0 ) ); ?>
				<?php $this->row( __( 'Ошибок', 'walls-delivery-calc' ), (string) (int) ( $stats['shipments_failed'] ?? 0 ) ); ?>
			</tbody>
		</table>
		<h2><?php echo esc_html__( 'Ошибки (до 20 последних)', 'walls-delivery-calc' ); ?></h2>
		<table class="widefat striped" style="max-width: 760px; margin-top: 12px;">
			<tbody>
				<?php $this->row( __( 'Статусов заказов изменено', 'walls-delivery-calc' ), (string) (int) ( $stats['order_statuses_changed'] ?? 0 ) ); ?>
				<?php $this->row( __( 'Изменений статусов заказов пропущено', 'walls-delivery-calc' ), (string) (int) ( $stats['order_statuses_skipped'] ?? 0 ) ); ?>
				<?php $this->row( __( 'Ошибок изменения статусов заказов', 'walls-delivery-calc' ), (string) (int) ( $stats['order_status_change_errors'] ?? 0 ) ); ?>
			</tbody>
		</table>
		<?php $this->render_error_samples( is_array( $stats['error_samples'] ?? null ) ? $stats['error_samples'] : array() ); ?>
		<h2><?php echo esc_html__( 'Пропуски по причинам', 'walls-delivery-calc' ); ?></h2>
		<?php $this->render_key_value_table( is_array( $stats['skip_reasons'] ?? null ) ? $stats['skip_reasons'] : array() ); ?>
		<?php
	}

	/**
	 * @param array<int,string> $selected_statuses
	 */
	private function render_order_status_checkboxes( array $selected_statuses ): void {
		$statuses = function_exists( 'wc_get_order_statuses' ) ? wc_get_order_statuses() : array();
		if ( ! is_array( $statuses ) || array() === $statuses ) {
			echo '<p class="description">' . esc_html__( 'Статусы WooCommerce пока недоступны.', 'walls-delivery-calc' ) . '</p>';
			return;
		}

		foreach ( $statuses as $status_key => $status_label ) {
			$status_key = (string) $status_key;
			echo '<label style="display:block;margin:0 0 6px;">';
			echo '<input type="checkbox" name="' . esc_attr( ShipmentStatusAutoSyncService::ORDER_STATUSES_KEY ) . '[]" value="' . esc_attr( $status_key ) . '" ' . checked( in_array( $status_key, $selected_statuses, true ), true, false ) . '> ';
			echo esc_html( (string) $status_label . ' (' . $status_key . ')' );
			echo '</label>';
		}
	}

	/**
	 * @param array<string,string> $order_statuses
	 */
	private function render_mapping_select( string $shipment_status, string $selected_status, array $order_statuses ): void {
		if ( array() === $order_statuses ) {
			echo '<p class="description">' . esc_html__( 'Статусы WooCommerce пока недоступны.', 'walls-delivery-calc' ) . '</p>';
			return;
		}

		echo '<select name="' . esc_attr( ShipmentOrderStatusMappingService::MAPPING_KEY ) . '[' . esc_attr( $shipment_status ) . ']">';
		echo '<option value="">' . esc_html__( 'Не менять', 'walls-delivery-calc' ) . '</option>';
		foreach ( $order_statuses as $status_key => $status_label ) {
			$status_key = (string) $status_key;
			echo '<option value="' . esc_attr( $status_key ) . '" ' . selected( $selected_status, $status_key, false ) . '>' . esc_html( (string) $status_label . ' (' . $status_key . ')' ) . '</option>';
		}
		echo '</select>';
	}

	private function handle_post(): string {
		if ( 'POST' !== (string) ( $_SERVER['REQUEST_METHOD'] ?? 'GET' ) || ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return '';
		}
		if ( ! current_user_can( AdminMenu::CAPABILITY ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
			return '';
		}

		$action = isset( $_POST['wdc_statuses_action'] ) ? sanitize_key( wp_unslash( (string) $_POST['wdc_statuses_action'] ) ) : '';
		if ( 'run_now' === $action ) {
			$stats = $this->auto_sync->run( 'manual' );
			return sprintf(
				__( 'Обработано заказов: %1$d. Найдено отправлений: %2$d. Обновлено: %3$d. Ошибок: %4$d.', 'walls-delivery-calc' ),
				(int) ( $stats['orders_scanned'] ?? 0 ),
				(int) ( $stats['shipments_found'] ?? 0 ),
				(int) ( $stats['shipments_updated'] ?? 0 ),
				(int) ( $stats['shipments_failed'] ?? 0 )
			);
		}

		if ( 'save_mapping' === $action ) {
			$mapping = isset( $_POST[ ShipmentOrderStatusMappingService::MAPPING_KEY ] ) && is_array( $_POST[ ShipmentOrderStatusMappingService::MAPPING_KEY ] )
				? $this->order_status_mapping->sanitize_mapping( wp_unslash( $_POST[ ShipmentOrderStatusMappingService::MAPPING_KEY ] ) )
				: array();

			$this->settings->set(
				ShipmentOrderStatusMappingService::ENABLED_KEY,
				! empty( $_POST[ ShipmentOrderStatusMappingService::ENABLED_KEY ] )
			);
			$this->settings->set(
				ShipmentOrderStatusMappingService::MAPPING_KEY,
				$mapping
			);

			return __( 'Соответствия статусов сохранены.', 'walls-delivery-calc' );
		}

		if ( 'save_settings' !== $action ) {
			return '';
		}

		$statuses = isset( $_POST[ ShipmentStatusAutoSyncService::ORDER_STATUSES_KEY ] ) && is_array( $_POST[ ShipmentStatusAutoSyncService::ORDER_STATUSES_KEY ] )
			? array_map( static fn ( mixed $status ): string => sanitize_key( wp_unslash( (string) $status ) ), $_POST[ ShipmentStatusAutoSyncService::ORDER_STATUSES_KEY ] )
			: array();
		$statuses = array_values( array_unique( array_filter( $statuses ) ) );

		$this->settings->set(
			ShipmentStatusAutoSyncService::ENABLED_KEY,
			! empty( $_POST[ ShipmentStatusAutoSyncService::ENABLED_KEY ] )
		);
		$this->settings->set(
			ShipmentStatusAutoSyncService::ORDER_STATUSES_KEY,
			$statuses
		);

		return __( 'Настройки статусов сохранены.', 'walls-delivery-calc' );
	}

	private function tab_link( string $tab, string $label, string $current ): void {
		$url = add_query_arg( array( 'page' => self::PAGE_SLUG, 'tab' => $tab ), admin_url( 'admin.php' ) );
		echo '<a class="nav-tab ' . ( $tab === $current ? 'nav-tab-active' : '' ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
	}

	private function row( string $label, string $value ): void {
		echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( '' !== $value ? $value : '-' ) . '</td></tr>';
	}

	/**
	 * @param array<int,mixed> $samples
	 */
	private function render_error_samples( array $samples ): void {
		if ( array() === $samples ) {
			echo '<p class="description">' . esc_html__( 'Ошибок нет.', 'walls-delivery-calc' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width: 960px;"><thead><tr><th>Order ID</th><th>carrier_key</th><th>message</th></tr></thead><tbody>';
		foreach ( array_slice( $samples, -20 ) as $sample ) {
			$sample = is_array( $sample ) ? $sample : array();
			echo '<tr><td>' . esc_html( (string) ( $sample['order_id'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $sample['carrier_key'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $sample['message'] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * @param array<string,mixed> $rows
	 */
	private function render_key_value_table( array $rows ): void {
		if ( array() === $rows ) {
			echo '<p class="description">' . esc_html__( 'Пропусков нет.', 'walls-delivery-calc' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width: 760px;"><tbody>';
		foreach ( $rows as $key => $value ) {
			$this->row( (string) $key, (string) $value );
		}
		echo '</tbody></table>';
	}
}
