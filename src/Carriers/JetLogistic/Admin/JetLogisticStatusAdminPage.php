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

	public function save_mapping_from_post( array $post ): void {
		$external = sanitize_text_field( wp_unslash( (string) ( $post['external_status'] ?? '' ) ) );
		$universal = sanitize_key( wp_unslash( (string) ( $post['universal_status'] ?? '' ) ) );
		$active = ! empty( $post['active'] );
		if ( '' !== $external && ( '' === $universal || DeliveryStatus::is_valid( $universal ) ) ) {
			$this->repository->save_mapping( $external, $universal, $active );
		}
	}

	public function render_embedded( DeliveryService $service ): void {
		$rows = $this->repository->admin_rows();
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
			<thead><tr><th>Внешний статус</th><th>Универсальный статус</th><th>Активно</th><th>Last seen</th><th>Count</th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( (string) ( $row['external_status'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['universal_status'] ?? '' ) ); ?></td>
					<td><?php echo ! empty( $row['active'] ) ? 'yes' : 'no'; ?></td>
					<td><?php echo esc_html( (string) ( $row['last_seen'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['occurrence_count'] ?? 0 ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}
}
