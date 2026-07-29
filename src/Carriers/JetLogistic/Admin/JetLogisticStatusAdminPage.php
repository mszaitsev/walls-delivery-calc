<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Admin;

use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusMappingRepository;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\Domain\Status\DeliveryStatus;

defined( 'ABSPATH' ) || exit;

final class JetLogisticStatusAdminPage {
	public function __construct( private JetLogisticStatusMappingRepository $repository ) {
	}

	/** @return array<string,mixed> */
	public function save_mapping_from_post( array $post ): array {
		$external = sanitize_text_field( wp_unslash( (string) ( $post['external_status'] ?? '' ) ) );
		$universal = sanitize_key( wp_unslash( (string) ( $post['universal_status'] ?? '' ) ) );
		$active = ! empty( $post['active'] );
		if ( '' === $external ) {
			return array( 'success' => false, 'message' => 'Укажите внешний статус Jet Logistic.' );
		}
		if ( '' !== $universal && ! DeliveryStatus::is_valid( $universal ) ) {
			return array( 'success' => false, 'message' => 'Выбран неизвестный универсальный статус.' );
		}
		$this->repository->save_mapping( $external, $universal, $active );

		return array( 'success' => true, 'message' => 'Сопоставление статуса Jet Logistic сохранено.' );
	}

	public function render_embedded( DeliveryService $service, array $notice = array() ): void {
		$rows = $this->repository->admin_rows();
		$this->render_notice( $notice );
		?>
		<h3><?php echo esc_html__( 'Сопоставление статусов Jet Logistic', 'walls-delivery-calc' ); ?></h3>
		<form method="post" style="max-width: 760px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_jet_status_mapping">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<p><label>Внешний статус <input class="regular-text" name="external_status"></label></p>
			<p><label>Универсальный статус <select name="universal_status"><option value="">Не сопоставлять</option><?php foreach ( DeliveryStatus::labels() as $status => $label ) : ?><option value="<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $label . ' (' . $status . ')' ); ?></option><?php endforeach; ?></select></label></p>
			<p><label><input type="checkbox" name="active" value="1" checked> Активно</label></p>
			<p><button class="button button-primary" type="submit"><?php echo esc_html__( 'Сохранить', 'walls-delivery-calc' ); ?></button></p>
		</form>
		<table class="widefat striped" style="max-width: 1180px;">
			<thead><tr><th>Внешний статус</th><th>Универсальный статус</th><th>Активно</th><th>Последнее событие</th><th>Количество</th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( (string) ( $row['external_status'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['universal_status'] ?? '' ) ); ?></td>
					<td><?php echo ! empty( $row['active'] ) ? 'да' : 'нет'; ?></td>
					<td><?php echo esc_html( (string) ( $row['last_seen'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['occurrence_count'] ?? 0 ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** @param array<string,mixed> $notice */
	private function render_notice( array $notice ): void {
		if ( array() === $notice ) {
			return;
		}
		$type = in_array( (string) ( $notice['type'] ?? 'info' ), array( 'success', 'warning', 'error' ), true ) ? (string) $notice['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p></div>';
	}
}
