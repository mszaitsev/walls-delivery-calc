<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Admin;

use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyRepository;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticCitiesCsvClient;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticCountrySyncService;
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
		private JetLogisticCountrySyncService $country_sync,
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
		if ( ! is_array( $file ) ) {
			return array( 'success' => false, 'message' => 'Файл cities.csv не выбран.' );
		}
		$error = (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE );
		if ( UPLOAD_ERR_OK !== $error ) {
			return array( 'success' => false, 'message' => $this->upload_error_message( $error ) );
		}
		$tmp_name = (string) ( $file['tmp_name'] ?? '' );
		if ( '' === $tmp_name || ! file_exists( $tmp_name ) || ! is_readable( $tmp_name ) ) {
			return array( 'success' => false, 'message' => 'Не удалось загрузить файл cities.csv. Выберите корректный CSV-файл и повторите попытку.' );
		}
		$size = filesize( $tmp_name );
		if ( false === $size || 0 === (int) $size ) {
			return array( 'success' => false, 'message' => 'Файл городов Jet Logistic пуст.' );
		}
		if ( (int) $size > JetLogisticCitiesCsvClient::MAX_RESPONSE_BYTES ) {
			return array( 'success' => false, 'message' => 'Размер файла городов Jet Logistic превышает допустимый лимит 20 МБ.' );
		}
		$csv = file_get_contents( $tmp_name );
		if ( false === $csv ) {
			return array( 'success' => false, 'message' => 'Не удалось прочитать загруженный файл cities.csv.' );
		}

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
		$previous = $this->overrides->find( $identity );
		if ( ! $this->overrides->save( $identity, $location_id, $location->country_code ) ) {
			return array( 'success' => false, 'message' => 'Не удалось сохранить ручное сопоставление Jet Logistic.' );
		}
		if ( ! $this->geography->apply_manual_override( $identity, $location_id, $location->country_code ) ) {
			if ( array() !== $previous ) {
				$this->overrides->save( (string) $previous['source_identity'], (int) $previous['location_id'], (string) $previous['country_code'] );
			} else {
				$this->overrides->delete( $identity );
			}
			return array( 'success' => false, 'message' => 'Не удалось применить ручное сопоставление к текущей географии Jet Logistic.' );
		}
		$this->country_sync->ensure_country_enabled( (string) $location->country_code );

		return array(
			'success' => true,
			'message' => 'Ручное сопоставление Jet Logistic применено.',
			'details' => array( 'source_identity' => $identity, 'location_id' => $location_id, 'country_code' => strtoupper( (string) $location->country_code ) ),
		);
	}

	public function render_embedded( DeliveryService $service, array $notice = array() ): void {
		$origins = $this->geography->active_origin_options();
		$rows = $this->geography->admin_rows( 100 );
		$location_display_names = $this->location_display_names_for_rows( $rows );
		$stats = $this->geography->match_status_counts();
		$has_token = $this->credentials->has_access_token();
		$this->render_notice( $notice );
		?>
		<style>
			.wdc-row-number { width: 50px; text-align: right; white-space: nowrap; }
			td.wdc-row-number { font-variant-numeric: tabular-nums; }
		</style>
		<h3><?php echo esc_html__( 'Настройки Jet Logistic', 'walls-delivery-calc' ); ?></h3>
		<form method="post" style="max-width: 760px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_jet_settings">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<table class="form-table" role="presentation">
				<tr><th scope="row">Тайм-аут HTTP, сек.</th><td><input type="number" min="1" max="60" name="<?php echo esc_attr( JetLogisticSettings::REQUEST_TIMEOUT_KEY ); ?>" value="<?php echo esc_attr( (string) $this->settings->request_timeout() ); ?>"></td></tr>
				<tr><th scope="row">Город отправления</th><td><select name="<?php echo esc_attr( JetLogisticSettings::ORIGIN_SOURCE_IDENTITY_KEY ); ?>"><option value="">Не выбран</option><?php foreach ( $origins as $origin ) : ?><option value="<?php echo esc_attr( (string) ( $origin['source_identity'] ?? '' ) ); ?>" <?php selected( $this->settings->origin_source_identity(), (string) ( $origin['source_identity'] ?? '' ) ); ?>><?php echo esc_html( (string) ( $origin['source_city'] ?? '' ) . ' ' . (string) ( $origin['country_code'] ?? '' ) ); ?></option><?php endforeach; ?></select></td></tr>
				<tr><th scope="row">Токен API Jet Logistic</th><td><input type="password" class="regular-text" name="jet_logistic_access_token" value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $has_token ? __( 'задано', 'walls-delivery-calc' ) : __( 'не задано', 'walls-delivery-calc' ) ); ?>"><p class="description"><?php echo esc_html__( 'Оставьте поле пустым, чтобы сохранить текущий токен.', 'walls-delivery-calc' ); ?> <?php echo esc_html( $has_token ? __( 'Токен сохранён.', 'walls-delivery-calc' ) : __( 'Токен не задан.', 'walls-delivery-calc' ) ); ?></p><label><input type="checkbox" name="jet_logistic_clear_access_token" value="1"> <?php echo esc_html__( 'Очистить сохранённый токен', 'walls-delivery-calc' ); ?></label></td></tr>
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
				<tr><th scope="row"><?php echo esc_html( $this->match_status_label( $status ) ); ?></th><td><?php echo esc_html( (string) ( $stats[ $status ] ?? 0 ) ); ?></td></tr>
			<?php endforeach; ?>
		</tbody></table>

		<h3><?php echo esc_html__( 'Ручное сопоставление', 'walls-delivery-calc' ); ?></h3>
		<table class="widefat striped" style="max-width: 1180px;">
			<thead><tr><th class="wdc-row-number">№</th><th>Идентификатор Jet</th><th>Город</th><th>Регион</th><th>Страна</th><th>Статус</th><th>Источник сопоставления</th><th>ID населённого пункта</th><th>Сопоставленный населённый пункт</th><th>Ручное сопоставление</th></tr></thead>
			<tbody>
			<?php foreach ( $rows as $index => $row ) : ?>
				<?php $location_id = (int) ( $row['location_id'] ?? 0 ); ?>
				<tr>
					<td class="wdc-row-number"><?php echo esc_html( (string) ( $index + 1 ) ); ?></td>
					<td><code><?php echo esc_html( (string) ( $row['source_identity'] ?? '' ) ); ?></code></td>
					<td><?php echo esc_html( (string) ( $row['source_city'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['source_region'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['country_code'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( $this->match_status_label( (string) ( $row['match_status'] ?? '' ) ) ); ?></td>
					<td><?php echo esc_html( $this->match_source_label( (string) ( $row['match_source'] ?? '' ) ) ); ?></td>
					<td><?php echo esc_html( (string) ( $row['location_id'] ?? '' ) ); ?></td>
					<td><?php echo esc_html( $location_id > 0 ? ( $location_display_names[ $location_id ] ?? '—' ) : '—' ); ?></td>
					<td><form method="post"><?php wp_nonce_field( 'wdc_delivery_services' ); ?><input type="hidden" name="wdc_delivery_services_action" value="save_jet_geography_override"><input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>"><input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>"><input type="hidden" name="source_identity" value="<?php echo esc_attr( (string) ( $row['source_identity'] ?? '' ) ); ?>"><input type="number" min="1" name="location_id" value="<?php echo esc_attr( $location_id > 0 ? (string) $location_id : '' ); ?>"> <button class="button button-secondary" type="submit"><?php echo esc_html__( 'Сохранить', 'walls-delivery-calc' ); ?></button></form></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
		if ( array() === $rows ) {
			echo '<p>' . esc_html__( 'География Jet ещё не импортирована.', 'walls-delivery-calc' ) . '</p>';
		}
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,string>
	 */
	private function location_display_names_for_rows( array $rows ): array {
		$location_ids = array_values(
			array_unique(
				array_filter(
					array_map( static fn( array $row ): int => (int) ( $row['location_id'] ?? 0 ), $rows ),
					static fn( int $location_id ): bool => $location_id > 0
				)
			)
		);
		$display_names = array();
		foreach ( $this->locations->find_map_by_ids( $location_ids ) as $location_id => $location ) {
			$display_names[ (int) $location_id ] = $this->location_display_name( $location );
		}

		return $display_names;
	}

	private function location_display_name( \WallsShop\WDC\Locations\ValueObjects\Location $location ): string {
		$display = trim( $location->display_name );
		if ( '' !== $display ) {
			return $display;
		}
		foreach ( array( $location->place_name, $location->settlement_name, $location->city_name ) as $value ) {
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return null !== $location->id ? 'ID ' . (string) $location->id : '—';
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

	private function upload_error_message( int $error ): string {
		return match ( $error ) {
			UPLOAD_ERR_NO_FILE => 'Файл cities.csv не выбран.',
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Размер файла превышает допустимый лимит.',
			UPLOAD_ERR_PARTIAL => 'Файл был загружен только частично.',
			UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE, UPLOAD_ERR_EXTENSION => 'Не удалось сохранить временный загруженный файл.',
			default => 'Не удалось загрузить файл cities.csv. Выберите корректный CSV-файл и повторите попытку.',
		};
	}

	private function notice_detail_label( string $key ): string {
		return match ( $key ) {
			'rows' => 'Строк импортировано',
			'matched' => 'Сопоставлено',
			'ambiguous' => 'Требует уточнения',
			'unmatched' => 'Не сопоставлено',
			'ignored' => 'Пропущено',
			'invalid' => 'Некорректных строк',
			'rows_read' => 'Строк прочитано',
			'rows_unique' => 'Уникальных строк',
			'duplicates' => 'Дубликатов',
			'duplicate_conflicts' => 'Конфликтующих дубликатов',
			'legacy_identity_conflicts' => 'Конфликтов прежних сопоставлений',
			'legacy_override_migration_failures' => 'Ошибок переноса прежних сопоставлений',
			'location_id' => 'ID населённого пункта',
			'source_identity' => 'Идентификатор Jet',
			'country_code' => 'Страна',
			default => $key,
		};
	}

	private function match_status_label( string $status ): string {
		return match ( $status ) {
			'matched' => 'сопоставлено',
			'ambiguous' => 'требует уточнения',
			'unmatched' => 'не сопоставлено',
			'ignored' => 'пропущено',
			'invalid' => 'некорректная строка',
			default => $status,
		};
	}

	private function match_source_label( string $source ): string {
		return match ( $source ) {
			'manual_override' => 'ручное сопоставление',
			'auto_exact' => 'автоматически: точное совпадение',
			'auto_alias' => 'автоматически: алиас',
			default => $source,
		};
	}
}
