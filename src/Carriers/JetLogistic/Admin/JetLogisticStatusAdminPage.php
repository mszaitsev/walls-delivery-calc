<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Admin;

use WallsShop\WDC\Carriers\JetLogistic\Api\JetLogisticApiDiagnosticService;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusMappingRepository;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\Domain\Status\DeliveryStatus;

defined( 'ABSPATH' ) || exit;

final class JetLogisticStatusAdminPage {
	public function __construct(
		private JetLogisticStatusMappingRepository $repository,
		private ?JetLogisticApiDiagnosticService $diagnostics = null
	) {
	}

	/** @return array<string,mixed> */
	public function create_mapping_from_post( array $post ): array {
		$validation = $this->validate_mapping_post( $post );
		if ( array() !== $validation['error'] ) {
			return $validation['error'];
		}
		if ( array() !== $this->repository->find_by_normalized_status( $validation['external_status'] ) ) {
			return array( 'success' => false, 'message' => 'Правило с такой фразой уже существует.' );
		}
		if ( ! $this->repository->create_mapping( $validation['external_status'], $validation['universal_status'] ) ) {
			return array( 'success' => false, 'message' => 'Не удалось сохранить сопоставление статуса Jet Logistic.' );
		}

		return array( 'success' => true, 'message' => 'Сопоставление статуса Jet Logistic сохранено.' );
	}

	/** @return array<string,mixed> */
	public function update_mapping_from_post( array $post ): array {
		$mapping_id = max( 0, (int) ( $post['mapping_id'] ?? 0 ) );
		if ( $mapping_id <= 0 || array() === $this->repository->find_by_id( $mapping_id ) ) {
			return array( 'success' => false, 'message' => 'Сопоставление статуса Jet Logistic не найдено.' );
		}
		$validation = $this->validate_mapping_post( $post );
		if ( array() !== $validation['error'] ) {
			return $validation['error'];
		}
		$duplicate = $this->repository->find_by_normalized_status( $validation['external_status'] );
		if ( array() !== $duplicate && (int) ( $duplicate['id'] ?? 0 ) !== $mapping_id ) {
			return array( 'success' => false, 'message' => 'Правило с такой фразой уже существует.' );
		}
		if ( ! $this->repository->update_mapping( $mapping_id, $validation['external_status'], $validation['universal_status'] ) ) {
			return array( 'success' => false, 'message' => 'Не удалось сохранить сопоставление статуса Jet Logistic.' );
		}

		return array( 'success' => true, 'message' => 'Сопоставление статуса Jet Logistic сохранено.' );
	}

	/** @return array<string,mixed> */
	public function delete_mapping_from_post( array $post ): array {
		$mapping_id = max( 0, (int) ( $post['mapping_id'] ?? 0 ) );
		if ( $mapping_id <= 0 || array() === $this->repository->find_by_id( $mapping_id ) ) {
			return array( 'success' => false, 'message' => 'Сопоставление статуса Jet Logistic не найдено.' );
		}
		if ( ! $this->repository->delete_mapping( $mapping_id ) ) {
			return array( 'success' => false, 'message' => 'Не удалось удалить сопоставление статуса Jet Logistic.' );
		}

		return array( 'success' => true, 'message' => 'Сопоставление статуса Jet Logistic удалено.' );
	}

	/** @return array<string,mixed> */
	public function check_tracking_from_post( array $post ): array {
		if ( ! $this->diagnostics instanceof JetLogisticApiDiagnosticService ) {
			return array( 'success' => false, 'message' => 'Компонент диагностики статусов Jet Logistic недоступен.' );
		}

		return $this->diagnostics->check_tracking( (string) ( $post['jet_tracking_number'] ?? '' ) );
	}

	/** @return array{external_status:string,universal_status:string,error:array<string,mixed>} */
	private function validate_mapping_post( array $post ): array {
		$external = sanitize_text_field( wp_unslash( (string) ( $post['external_status'] ?? '' ) ) );
		$universal = sanitize_key( wp_unslash( (string) ( $post['universal_status'] ?? '' ) ) );
		if ( '' === trim( $external ) ) {
			return array( 'external_status' => '', 'universal_status' => '', 'error' => array( 'success' => false, 'message' => 'Укажите фразу в статусе Jet Logistic.' ) );
		}
		if ( mb_strlen( trim( $external ), 'UTF-8' ) > 255 ) {
			return array( 'external_status' => '', 'universal_status' => '', 'error' => array( 'success' => false, 'message' => 'Фраза в статусе Jet Logistic не должна быть длиннее 255 символов.' ) );
		}
		if ( '' === $universal ) {
			return array( 'external_status' => '', 'universal_status' => '', 'error' => array( 'success' => false, 'message' => 'Выберите универсальный статус.' ) );
		}
		if ( ! DeliveryStatus::is_valid( $universal ) ) {
			return array( 'external_status' => '', 'universal_status' => '', 'error' => array( 'success' => false, 'message' => 'Выбран неизвестный универсальный статус.' ) );
		}

		return array( 'external_status' => trim( $external ), 'universal_status' => $universal, 'error' => array() );
	}

	public function render_embedded( DeliveryService $service, array $notice = array() ): void {
		$rows = $this->repository->admin_rows();
		$this->render_notice( $notice );
		?>
		<h3><?php echo esc_html__( 'Сопоставление статусов Jet Logistic', 'walls-delivery-calc' ); ?></h3>
		<p class="description"><?php echo esc_html__( 'Сопоставление сработает, если указанная фраза встречается в сообщении Jet Logistic. Регистр и различие «ё/е» не учитываются. Если в одном сообщении совпадут несколько правил, используется самая длинная фраза.', 'walls-delivery-calc' ); ?></p>
		<form method="post" style="max-width: 760px; margin-bottom: 16px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="check_jet_tracking">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<p><label><?php echo esc_html__( 'Номер груза Jet', 'walls-delivery-calc' ); ?> <input class="regular-text" name="jet_tracking_number" maxlength="64"></label> <button class="button button-secondary" type="submit"><?php echo esc_html__( 'Проверить статус', 'walls-delivery-calc' ); ?></button></p>
			<p class="description"><?php echo esc_html__( 'Проверка не изменяет заказ, отправление или правила сопоставления.', 'walls-delivery-calc' ); ?></p>
		</form>
		<form method="post" style="max-width: 760px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="create_jet_status_mapping">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<p><label><?php echo esc_html__( 'Фраза в статусе Jet', 'walls-delivery-calc' ); ?> <input class="regular-text" name="external_status"></label></p>
			<p><label><?php echo esc_html__( 'Универсальный статус', 'walls-delivery-calc' ); ?> <?php $this->render_status_select( '' ); ?></label></p>
			<p><button class="button button-primary" type="submit"><?php echo esc_html__( 'Добавить', 'walls-delivery-calc' ); ?></button></p>
		</form>
		<table class="widefat striped" style="max-width: 1180px;">
			<thead><tr><th><?php echo esc_html__( 'Фраза в статусе Jet', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Универсальный статус', 'walls-delivery-calc' ); ?></th><th><?php echo esc_html__( 'Действия', 'walls-delivery-calc' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<?php $form_id = 'wdc-jet-status-mapping-' . (int) ( $row['id'] ?? 0 ); ?>
				<tr>
					<td>
						<form id="<?php echo esc_attr( $form_id ); ?>" method="post" class="wdc-jet-status-row-form">
							<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
							<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
							<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
							<input type="hidden" name="mapping_id" value="<?php echo esc_attr( (string) ( $row['id'] ?? 0 ) ); ?>">
							<input class="regular-text" name="external_status" value="<?php echo esc_attr( (string) ( $row['external_status'] ?? '' ) ); ?>">
						</form>
					</td>
					<td><?php $this->render_status_select( (string) ( $row['universal_status'] ?? '' ), $form_id ); ?></td>
					<td>
						<button class="button button-secondary" type="submit" form="<?php echo esc_attr( $form_id ); ?>" name="wdc_delivery_services_action" value="update_jet_status_mapping"><?php echo esc_html__( 'Сохранить', 'walls-delivery-calc' ); ?></button>
						<button class="button button-link-delete" type="submit" form="<?php echo esc_attr( $form_id ); ?>" name="wdc_delivery_services_action" value="delete_jet_status_mapping" onclick="return confirm('Удалить это сопоставление статуса Jet Logistic?');"><?php echo esc_html__( 'Удалить', 'walls-delivery-calc' ); ?></button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_status_select( string $selected_status, string $form_id = '' ): void {
		echo '<select name="universal_status"' . ( '' !== $form_id ? ' form="' . esc_attr( $form_id ) . '"' : '' ) . '>';
		echo '<option value="">' . esc_html__( 'Выберите универсальный статус', 'walls-delivery-calc' ) . '</option>';
		foreach ( DeliveryStatus::labels() as $status => $label ) {
			echo '<option value="' . esc_attr( (string) $status ) . '"' . selected( $selected_status, (string) $status, false ) . '>' . esc_html( $label . ' (' . $status . ')' ) . '</option>';
		}
		echo '</select>';
	}

	/** @param array<string,mixed> $notice */
	private function render_notice( array $notice ): void {
		if ( array() === $notice ) {
			return;
		}
		$type = in_array( (string) ( $notice['type'] ?? 'info' ), array( 'success', 'warning', 'error' ), true ) ? (string) $notice['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p>';
		$details = is_array( $notice['details'] ?? null ) ? $notice['details'] : array();
		if ( array() !== $details ) {
			echo '<ul>';
			foreach ( $details as $key => $value ) {
				if ( is_scalar( $value ) ) {
					echo '<li>' . esc_html( $this->notice_detail_label( (string) $key ) . ': ' . (string) $value ) . '</li>';
				}
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	private function notice_detail_label( string $key ): string {
		return match ( $key ) {
			'checked_at' => 'Проверено',
			'token_state' => 'Токен',
			'endpoint' => 'API endpoint',
			'method' => 'HTTP method',
			'http_status' => 'HTTP status',
			'api_response' => 'Ответ API',
			'code' => 'Код',
			'tracking_number' => 'Номер груза Jet',
			'event_1' => 'Событие 1',
			'event_2' => 'Событие 2',
			'event_3' => 'Событие 3',
			'event_4' => 'Событие 4',
			'event_5' => 'Событие 5',
			default => $key,
		};
	}
}
