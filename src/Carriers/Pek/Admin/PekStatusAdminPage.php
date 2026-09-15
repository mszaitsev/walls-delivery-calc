<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Admin;

use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Shipments\Pek\PekStatusMapping;

defined( 'ABSPATH' ) || exit;

final class PekStatusAdminPage {
	public const TAB_KEY = 'pek_statuses';

	public function __construct(
		private PekStatusMapping $mapping
	) {
	}

	public function render_embedded( DeliveryService $service ): void {
		if ( PekSettings::SERVICE_KEY !== $service->service_key ) {
			return;
		}
		$mapping = $this->mapping->mapping();
		$defaults = PekStatusMapping::default_mapping();
		?>
		<form method="post" style="max-width: 1180px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_pek_statuses">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<h3><?php echo esc_html__( 'Статусы ПЭК', 'walls-delivery-calc' ); ?></h3>
			<p class="description"><?php echo esc_html__( 'Сопоставление определяет, какой универсальный статус WDC получает отправление при статусе ПЭК.', 'walls-delivery-calc' ); ?></p>
			<p class="description"><?php echo esc_html__( 'Настройка не изменяет исходный статус ПЭК и не меняет правила безопасности API: возможность отмены, факт приёмки груза и обработка аннулированных заявок определяются отдельно.', 'walls-delivery-calc' ); ?></p>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php echo esc_html__( 'Статус ПЭК', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'ПЭК до терминала', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'ПЭК курьером', 'walls-delivery-calc' ); ?></th>
						<th><?php echo esc_html__( 'Дефолт', 'walls-delivery-calc' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( PekStatusMapping::statuses() as $key => $status ) : ?>
						<?php
						$key = (string) $key;
						$pickup_default = (string) ( $defaults[ $key ]['pickup'] ?? DeliveryStatus::UNKNOWN );
						$courier_default = (string) ( $defaults[ $key ]['courier'] ?? DeliveryStatus::UNKNOWN );
						?>
						<tr>
							<td>
								<strong><?php echo esc_html( $status['label'] ); ?></strong>
								<?php if ( ! empty( $status['pattern'] ) ) : ?>
									<br><small><?php echo esc_html__( 'Шаблон статуса ПЭК', 'walls-delivery-calc' ); ?></small>
								<?php endif; ?>
								<br><code><?php echo esc_html( $key ); ?></code>
							</td>
							<td><?php $this->render_delivery_status_select( $key, 'pickup', (string) ( $mapping[ $key ]['pickup'] ?? $pickup_default ) ); ?></td>
							<td><?php $this->render_delivery_status_select( $key, 'courier', (string) ( $mapping[ $key ]['courier'] ?? $courier_default ) ); ?></td>
							<td>
								<?php echo esc_html( DeliveryStatus::label( $pickup_default ) . ' (' . $pickup_default . ')' ); ?>
								<br>
								<?php echo esc_html( DeliveryStatus::label( $courier_default ) . ' (' . $courier_default . ')' ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Сохранить статусы ПЭК', 'walls-delivery-calc' ) ); ?>
		</form>
		<?php
	}

	/**
	 * @param array<string,mixed> $post
	 */
	public function save_from_post( array $post ): void {
		$mapping = isset( $post[ PekStatusMapping::MAPPING_KEY ] ) && is_array( $post[ PekStatusMapping::MAPPING_KEY ] )
			? $this->mapping->sanitize_mapping( wp_unslash( $post[ PekStatusMapping::MAPPING_KEY ] ) )
			: PekStatusMapping::default_mapping();
		$this->mapping->save_mapping( $mapping );
	}

	private function render_delivery_status_select( string $key, string $delivery_type, string $selected ): void {
		$name = PekStatusMapping::MAPPING_KEY . '[' . $key . '][' . $delivery_type . ']';
		?>
		<select name="<?php echo esc_attr( $name ); ?>">
			<?php foreach ( DeliveryStatus::labels() as $code => $label ) : ?>
				<option value="<?php echo esc_attr( $code ); ?>" <?php selected( $selected, $code ); ?>>
					<?php echo esc_html( $label . ' (' . $code . ')' ); ?>
				</option>
			<?php endforeach; ?>
		</select>
		<?php
	}
}
