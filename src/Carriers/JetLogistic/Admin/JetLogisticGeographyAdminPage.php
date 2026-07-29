<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Admin;

use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyRepository;
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
		private JetLogisticGeographyOverrideRepository $overrides,
		private JetLogisticGeographyRepository $geography,
		private LocationRepository $locations,
		private JetLogisticSettings $settings,
		private JetLogisticCredentials $credentials
	) {
	}

	public function save_settings_from_post( array $post ): void {
		$this->settings->save_from_admin( $post );
		$token = trim( (string) ( $post['jet_logistic_access_token'] ?? '' ) );
		if ( '' !== $token ) {
			$this->credentials->save_access_token( sanitize_text_field( wp_unslash( $token ) ) );
		}
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

	public function save_override_from_post( array $post ): void {
		$identity = sanitize_text_field( wp_unslash( (string) ( $post['source_identity'] ?? '' ) ) );
		$location_id = max( 0, (int) ( $post['location_id'] ?? 0 ) );
		$location = $this->locations->find_by_id( $location_id );
		if ( '' !== $identity && null !== $location && $location->active ) {
			$this->overrides->save( $identity, $location_id, $location->country_code );
		}
	}

	public function render_embedded( DeliveryService $service ): void {
		$origins = $this->geography->active_origin_options();
		$rows = $this->geography->admin_rows( 100 );
		$stats = $this->geography->match_status_counts();
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
				<tr><th scope="row">Jet API access token</th><td><input type="password" class="regular-text" name="jet_logistic_access_token" value="" autocomplete="new-password"></td></tr>
			</table>
			<?php submit_button( __( 'Сохранить настройки Jet', 'walls-delivery-calc' ) ); ?>
		</form>

		<h3><?php echo esc_html__( 'Импорт географии', 'walls-delivery-calc' ); ?></h3>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="import_jet_geography_csv">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<p><input type="file" name="cities_csv" accept=".csv,text/csv"> <?php submit_button( __( 'Загрузить cities.csv', 'walls-delivery-calc' ), 'primary', 'submit', false ); ?></p>
		</form>

		<h3><?php echo esc_html__( 'Статистика сопоставления', 'walls-delivery-calc' ); ?></h3>
		<table class="widefat striped" style="max-width: 760px;"><tbody>
			<?php foreach ( array( 'matched', 'ambiguous', 'unmatched', 'ignored', 'invalid' ) as $status ) : ?>
				<tr><th scope="row"><?php echo esc_html( $status ); ?></th><td><?php echo esc_html( (string) ( $stats[ $status ] ?? 0 ) ); ?></td></tr>
			<?php endforeach; ?>
		</tbody></table>

		<h3><?php echo esc_html__( 'Ручное сопоставление', 'walls-delivery-calc' ); ?></h3>
		<table class="widefat striped" style="max-width: 1180px;">
			<thead><tr><th>Source</th><th>Город</th><th>Регион</th><th>Страна</th><th>Status</th><th>Location ID</th><th>Override</th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><code><?php echo esc_html( (string) ( $row['source_identity'] ?? '' ) ); ?></code></td>
					<td><?php echo esc_html( (string) ( $row['source_city'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['source_region'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['country_code'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['match_status'] ?? '' ) ); ?></td>
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
}
