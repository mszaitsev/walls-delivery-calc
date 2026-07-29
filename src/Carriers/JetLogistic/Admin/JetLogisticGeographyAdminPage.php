<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Admin;

use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyRepository;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticCitiesCsvClient;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyImportService;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyOverrideRepository;
use WallsShop\WDC\Carriers\JetLogistic\JetLogisticCredentials;
use WallsShop\WDC\Carriers\JetLogistic\JetLogisticSettings;
use WallsShop\WDC\DeliveryServices\DeliveryService;
use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class JetLogisticGeographyAdminPage {
	public function __construct(
		private JetLogisticGeographyImportService $imports,
		private JetLogisticCitiesCsvClient $cities,
		private JetLogisticGeographyOverrideRepository $overrides,
		private JetLogisticGeographyRepository $geography,
		private LocationRepository $locations,
		private JetLogisticSettings $settings,
		private JetLogisticCredentials $credentials
	) {
	}

	/** @return array<string,mixed> */
	public function save_settings_from_post( array $post ): array {
		$this->settings->save_from_admin( $post );
		if ( ! empty( $post['jet_logistic_clear_access_token'] ) ) {
			$this->credentials->clear_access_token();
		} else {
			$token = trim( (string) ( $post['jet_logistic_access_token'] ?? '' ) );
			if ( '' !== $token ) {
			$this->credentials->save_access_token( sanitize_text_field( wp_unslash( $token ) ) );
			}
		}

		return array( 'success' => true, 'message' => 'Настройки Jet Logistic сохранены.' );
	}

	/** @return array<string,mixed> */
	public function import_remote_csv(): array {
		return $this->imports->import_csv( $this->cities->fetch( JetLogisticCitiesCsvClient::DEFAULT_URL ) );
	}

	/** @return array<string,mixed> */
	public function import_uploaded_csv( array $files ): array {
		$file = $files['cities_csv'] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || empty( $file['tmp_name'] ) ) {
			return array( 'success' => false, 'message' => 'Jet Logistic CSV upload failed.' );
		}
		$csv = (string) file_get_contents( (string) $file['tmp_name'] );

		return $this->imports->import_csv( $csv );
	}

	/** @return array<string,mixed> */
	public function save_override_from_post( array $post ): array {
		$identity = sanitize_text_field( wp_unslash( (string) ( $post['source_identity'] ?? '' ) ) );
		$location_id = max( 0, (int) ( $post['location_id'] ?? 0 ) );
		if ( '' === $identity || array() === $this->geography->find_by_source_identity( $identity ) ) {
			return array( 'success' => false, 'message' => 'Строка географии Jet Logistic не найдена. Повторите импорт cities.csv.' );
		}
		$location = $this->locations->find_by_id( $location_id );
		if ( null === $location || ! $location->active ) {
			return array( 'success' => false, 'message' => 'Выбранный населённый пункт не найден или неактивен.' );
		}
		if ( ! $this->overrides->save( $identity, $location_id, $location->country_code ) ) {
			return array( 'success' => false, 'message' => 'Не удалось сохранить ручное сопоставление Jet Logistic.' );
		}
		if ( ! $this->geography->apply_manual_override( $identity, $location_id, $location->country_code ) ) {
			$this->overrides->delete( $identity );
			return array( 'success' => false, 'message' => 'Не удалось применить ручное сопоставление к текущей географии Jet Logistic.' );
		}

		return array(
			'success' => true,
			'message' => 'Ручное сопоставление применено.',
			'details' => array( 'source_identity' => $identity, 'location_id' => $location_id ),
		);
	}

	public function render_embedded( DeliveryService $service, array $notice = array() ): void {
		$origins = $this->geography->active_origin_options();
		$rows = $this->geography->admin_rows( 100 );
		$stats = $this->geography->match_status_counts();
		$has_token = $this->credentials->has_access_token();
		$this->render_notice( $notice );
		?>
		<h3><?php echo esc_html__( 'Настройки Jet Logistic', 'walls-delivery-calc' ); ?></h3>
		<form method="post" style="max-width: 760px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_jet_settings">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<table class="form-table" role="presentation">
				<tr><th scope="row">HTTP timeout</th><td><input type="number" min="1" max="60" name="<?php echo esc_attr( JetLogisticSettings::REQUEST_TIMEOUT_KEY ); ?>" value="<?php echo esc_attr( (string) $this->settings->request_timeout() ); ?>"></td></tr>
				<tr><th scope="row">Origin city</th><td><select name="<?php echo esc_attr( JetLogisticSettings::ORIGIN_SOURCE_IDENTITY_KEY ); ?>"><option value="">Не выбран</option><?php foreach ( $origins as $origin ) : ?><option value="<?php echo esc_attr( (string) ( $origin['source_identity'] ?? '' ) ); ?>" <?php selected( $this->settings->origin_source_identity(), (string) ( $origin['source_identity'] ?? '' ) ); ?>><?php echo esc_html( (string) ( $origin['source_city'] ?? '' ) . ' ' . (string) ( $origin['country_code'] ?? '' ) ); ?></option><?php endforeach; ?></select></td></tr>
				<tr><th scope="row">Jet API access token</th><td><input type="password" class="regular-text" name="jet_logistic_access_token" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $has_token ? __( 'задано', 'walls-delivery-calc' ) : __( 'не задано', 'walls-delivery-calc' ) ); ?>"><p class="description"><?php echo esc_html__( 'Оставьте поле пустым, чтобы сохранить текущий токен.', 'walls-delivery-calc' ); ?> <?php echo esc_html( $has_token ? __( 'Токен сохранён.', 'walls-delivery-calc' ) : __( 'Токен не задан.', 'walls-delivery-calc' ) ); ?></p><label><input type="checkbox" name="jet_logistic_clear_access_token" value="1"> <?php echo esc_html__( 'Очистить сохранённый токен', 'walls-delivery-calc' ); ?></label></td></tr>
			</table>
			<?php submit_button( __( 'Сохранить настройки Jet', 'walls-delivery-calc' ) ); ?>
		</form>

		<h3><?php echo esc_html__( 'Импорт географии', 'walls-delivery-calc' ); ?></h3>
		<form method="post">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="import_jet_geography_remote">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<p><button class="button button-primary" type="submit"><?php echo esc_html__( 'Скачать и импортировать cities.csv', 'walls-delivery-calc' ); ?></button></p>
			<p class="description">Источник: <a href="<?php echo esc_url( JetLogisticCitiesCsvClient::DEFAULT_URL ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( JetLogisticCitiesCsvClient::DEFAULT_URL ); ?></a></p>
		</form>
		<h4><?php echo esc_html__( 'Ручная загрузка файла', 'walls-delivery-calc' ); ?></h4>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="import_jet_geography_csv">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<p><input type="file" name="cities_csv" accept=".csv,text/csv"> <?php submit_button( __( 'Загрузить cities.csv с компьютера', 'walls-delivery-calc' ), 'primary', 'submit', false ); ?></p>
		</form>

		<h3><?php echo esc_html__( 'Статистика сопоставления', 'walls-delivery-calc' ); ?></h3>
		<table class="widefat striped" style="max-width: 760px;"><tbody>
			<?php foreach ( array( 'matched', 'ambiguous', 'unmatched', 'ignored', 'invalid' ) as $status ) : ?>
				<tr><th scope="row"><?php echo esc_html( $status ); ?></th><td><?php echo esc_html( (string) ( $stats[ $status ] ?? 0 ) ); ?></td></tr>
			<?php endforeach; ?>
		</tbody></table>

		<h3><?php echo esc_html__( 'Ручное сопоставление', 'walls-delivery-calc' ); ?></h3>
		<table class="widefat striped" style="max-width: 1180px;">
			<thead><tr><th>Source</th><th>Город</th><th>Регион</th><th>Страна</th><th>Status</th><th>Match source</th><th>Location ID</th><th>Override</th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><code><?php echo esc_html( (string) ( $row['source_identity'] ?? '' ) ); ?></code></td>
					<td><?php echo esc_html( (string) ( $row['source_city'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['source_region'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['country_code'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['match_status'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['match_source'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['location_id'] ?? '' ) ); ?></td>
					<td><form method="post"><?php wp_nonce_field( 'wdc_delivery_services' ); ?><input type="hidden" name="wdc_delivery_services_action" value="save_jet_geography_override"><input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>"><input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>"><input type="hidden" name="source_identity" value="<?php echo esc_attr( (string) ( $row['source_identity'] ?? '' ) ); ?>"><input type="number" min="1" name="location_id" value="<?php echo esc_attr( (string) ( $row['location_id'] ?? '' ) ); ?>"> <button class="button button-secondary" type="submit"><?php echo esc_html__( 'Сохранить', 'walls-delivery-calc' ); ?></button></form></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'География Jet ещё не импортирована.', 'walls-delivery-calc' ) . '</p>';
		}
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
					echo '<li>' . esc_html( (string) $key . ': ' . (string) $value ) . '</li>';
				}
			}
			echo '</ul>';
		}
		echo '</div>';
	}
}
