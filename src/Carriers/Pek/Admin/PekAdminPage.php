<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Admin;

use WallsShop\WDC\Carriers\Pek\Api\PekConnectionDiagnosticService;
use WallsShop\WDC\Carriers\Pek\Api\PekSenderWarehouseService;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\DeliveryServices\DeliveryService;

defined( 'ABSPATH' ) || exit;

final class PekAdminPage {
	public const TAB_KEY = 'pek_settings';
	public const ACTIONS = array(
		'save_pek_settings',
		'check_pek_connection',
		'search_pek_sender_warehouse',
		'select_pek_sender_warehouse',
		'diagnose_pek_destination_pickup',
	);

	public function __construct(
		private PekSettings $settings,
		private PekCredentials $credentials,
		private PekConnectionDiagnosticService $diagnostics,
		private PekSenderWarehouseService $warehouses,
		private PekAdminNoticeStore $notices,
		private PekDestinationPickupDiagnosticService $destination_diagnostics,
		private PekDestinationPickupDiagnosticStore $destination_reports
	) {
	}

	public static function supports_action( string $action ): bool {
		return in_array( $action, self::ACTIONS, true );
	}

	/** @param array<string,mixed> $post */
	public function handle_action( string $action, DeliveryService $service, array $post ): void {
		if ( PekSettings::SERVICE_KEY !== $service->service_key ) {
			return;
		}
		$notice = array( 'type' => 'success', 'message' => 'Настройки ПЭК сохранены.' );
		try {
			if ( 'save_pek_settings' === $action ) {
				$this->settings->save_from_admin( $post );
				$this->warehouses->clear_last_search_for_current_user();
				if ( ! $this->credentials->save_from_admin( $post ) ) {
					$notice = array( 'type' => 'warning', 'message' => 'Настройки ПЭК сохранены, но API key не обновлён: задайте APP_ENCRYPTION_KEY.' );
				}
			} elseif ( 'check_pek_connection' === $action ) {
				$result = $this->diagnostics->run();
				$notice = array( 'type' => $result['success'] ? 'success' : 'warning', 'message' => (string) $result['message'] );
			} elseif ( 'search_pek_sender_warehouse' === $action ) {
				$result = $this->warehouses->search( $this->string_from_post( $post, 'pek_warehouse_search_address' ) );
				$notice = array( 'type' => $result['success'] ? 'success' : 'warning', 'message' => (string) $result['message'] );
			} elseif ( 'select_pek_sender_warehouse' === $action ) {
				$result = $this->warehouses->validate_and_select( $this->string_from_post( $post, 'pek_sender_warehouse_id' ) );
				$notice = array( 'type' => $result['success'] ? 'success' : 'error', 'message' => (string) $result['message'] );
			} elseif ( 'diagnose_pek_destination_pickup' === $action ) {
				$this->destination_reports->clear_for_current_user();
				$result = $this->destination_diagnostics->run( $post );
				$this->destination_reports->save_for_current_user( $result );
				$notice = array( 'type' => $result['success'] ? 'success' : 'warning', 'message' => (string) ( $result['message'] ?? 'Диагностика направления ПЭК выполнена.' ) );
			}
		} catch ( \Throwable $exception ) {
			$notice = array( 'type' => 'error', 'message' => 'Не удалось выполнить действие ПЭК: ' . $this->safe_message( $exception->getMessage() ) );
		}

		$this->notices->save_for_current_user( (string) $notice['type'], (string) $notice['message'] );
	}

	public function render_embedded( DeliveryService $service ): void {
		if ( PekSettings::SERVICE_KEY !== $service->service_key ) {
			return;
		}
		$notice = $this->notices->consume_for_current_user();
		$warehouse = $this->settings->sender_warehouse();
		$diagnostic = $this->settings->last_diagnostic();
		$search = $this->warehouses->last_search_for_current_user();
		$destination_report = $this->destination_reports->consume_for_current_user();
		?>
		<?php $this->render_notice( $notice ); ?>
		<h3><?php echo esc_html__( 'Настройки ПЭК', 'walls-delivery-calc' ); ?></h3>
		<form method="post" style="max-width: 980px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="save_pek_settings">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<table class="form-table" role="presentation">
				<?php $this->number_row( PekSettings::REQUEST_TIMEOUT_KEY, 'Таймаут HTTP, сек.', $this->settings->request_timeout(), 1, 60 ); ?>
				<?php $this->number_row( PekSettings::REQUESTS_PER_MINUTE_KEY, 'Локальный soft limit запросов в минуту', $this->settings->request_soft_limit_per_minute(), 1, 100 ); ?>
				<?php $this->text_row( PekSettings::LOGIN_KEY, 'Логин личного кабинета ПЭК', $this->credentials->login() ); ?>
				<tr>
					<th scope="row"><label for="pek_api_key"><?php echo esc_html__( 'API key ПЭК', 'walls-delivery-calc' ); ?></label></th>
					<td>
						<input class="regular-text" type="password" id="pek_api_key" name="pek_api_key" value="" placeholder="<?php echo esc_attr( $this->credentials->has_api_key() ? 'задано' : 'не задано' ); ?>">
						<label><input type="checkbox" name="pek_clear_api_key" value="1"> <?php echo esc_html__( 'Очистить сохранённый API key', 'walls-delivery-calc' ); ?></label>
						<?php if ( ! $this->credentials->encryption_ready() ) : ?>
							<p class="description"><?php echo esc_html__( 'APP_ENCRYPTION_KEY не задан: новый API key не будет сохранён.', 'walls-delivery-calc' ); ?></p>
						<?php endif; ?>
					</td>
				</tr>
				<?php $this->select_row( PekSettings::SENDER_LEGAL_FORM_KEY, 'Тип отправителя', (string) $this->settings->sender_legal_form(), array( '1' => 'Юридическое лицо', '2' => 'Индивидуальный предприниматель' ) ); ?>
				<?php $this->text_row( PekSettings::SENDER_FS_KEY, 'Сокращённая форма собственности', $this->settings->sender_fs() ); ?>
				<?php $this->text_row( PekSettings::SENDER_FULL_NAME_KEY, 'Полное название отправителя', $this->settings->sender_full_name() ); ?>
				<?php $this->text_row( PekSettings::SENDER_INN_KEY, 'ИНН', $this->settings->sender_inn() ); ?>
				<?php $this->text_row( PekSettings::SENDER_KPP_KEY, 'КПП', $this->settings->sender_kpp() ); ?>
				<?php $this->select_row( PekSettings::SENDER_REGISTRATION_COUNTRY_KEY, 'Страна регистрации', $this->settings->sender_registration_country(), $this->country_options() ); ?>
				<?php $this->text_row( PekSettings::SENDER_CONTACT_NAME_KEY, 'Контактное лицо', $this->settings->sender_contact_name() ); ?>
				<?php $this->text_row( PekSettings::SENDER_PHONE_KEY, 'Телефон', $this->settings->sender_phone() ); ?>
				<?php $this->text_row( PekSettings::SENDER_EMAIL_KEY, 'Email', $this->settings->sender_email() ); ?>
				<?php $this->text_row( PekSettings::CLIENT_CARD_KEY, 'Номер карты клиента ПЭК', $this->settings->client_card() ); ?>
				<?php $this->text_row( PekSettings::DEFAULT_CARGO_DESCRIPTION_KEY, 'Описание груза по умолчанию', $this->settings->default_cargo_description() ); ?>
				<?php $this->number_row( PekSettings::WAREHOUSE_SEARCH_RADIUS_KEY, 'Радиус поиска склада, км', $this->settings->warehouse_search_radius(), 1, 500 ); ?>
				<?php $this->number_row( PekSettings::WAREHOUSE_SEARCH_LIMIT_KEY, 'Лимит результатов поиска', $this->settings->warehouse_search_limit(), 1, 50 ); ?>
				<?php $this->number_row( PekSettings::DESTINATION_TERMINAL_SEARCH_RADIUS_KEY, 'Радиус поиска терминалов назначения, км', $this->settings->pek_destination_terminal_search_radius(), 1, 500 ); ?>
				<?php $this->number_row( PekSettings::DESTINATION_TERMINAL_SEARCH_LIMIT_KEY, 'Лимит терминалов назначения', $this->settings->pek_destination_terminal_search_limit(), 1, 100 ); ?>
				<?php $this->number_row( PekSettings::DESTINATION_TERMINAL_CACHE_TTL_KEY, 'TTL cache поиска терминалов, сек.', $this->settings->pek_destination_terminal_cache_ttl(), 60, 3600 ); ?>
				<?php $this->number_row( PekSettings::LOCATION_MAPPING_TTL_DAYS_KEY, 'TTL PEK location mapping, дней', $this->settings->pek_location_mapping_ttl_days(), 1, 365 ); ?>
				<?php $this->number_row( PekSettings::SMS_RELEASE_LIMIT_RUB_KEY, 'Договорный предел SMS-выдачи, руб.', $this->settings->sms_release_limit_rub(), 1, 999999999 ); ?>
			</table>
			<?php submit_button( __( 'Сохранить настройки ПЭК', 'walls-delivery-calc' ) ); ?>
		</form>

		<h3><?php echo esc_html__( 'Диагностика подключения', 'walls-delivery-calc' ); ?></h3>
		<form method="post">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="check_pek_connection">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<?php submit_button( __( 'Проверить подключение ПЭК', 'walls-delivery-calc' ), 'secondary' ); ?>
		</form>
		<?php $this->render_compact_report( $diagnostic ); ?>

		<h3><?php echo esc_html__( 'Склад самопривоза отправителя', 'walls-delivery-calc' ); ?></h3>
		<p class="description"><?php echo esc_html__( 'Выбранный склад является default для будущих отправлений. При создании конкретного отправления ограничения склада будут повторно проверяться по весу, объёму, габаритам и количеству мест. Позднее склад можно будет заменить в shipment modal; modal override на этом этапе ещё не реализован.', 'walls-delivery-calc' ); ?></p>
		<?php $this->render_warehouse_snapshot( $warehouse ); ?>
		<form method="post" style="max-width:760px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="search_pek_sender_warehouse">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<table class="form-table" role="presentation">
				<?php $this->text_row( 'pek_warehouse_search_address', 'Адрес поиска склада', (string) ( $search['requested']['address'] ?? '' ) ); ?>
			</table>
			<?php submit_button( __( 'Найти склады ПЭК', 'walls-delivery-calc' ), 'secondary' ); ?>
		</form>
		<?php $this->render_search_results( $service, $search ); ?>
		<h3><?php echo esc_html__( 'Диагностика направления и терминалов назначения', 'walls-delivery-calc' ); ?></h3>
		<p class="description"><?php echo esc_html__( 'Read-only диагностика: не включает PEK checkout rates, не сохраняет выбор терминала, не меняет canonical location и выполняет live PEK API calls только после нажатия кнопки.', 'walls-delivery-calc' ); ?></p>
		<form method="post" style="max-width:760px;">
			<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
			<input type="hidden" name="wdc_delivery_services_action" value="diagnose_pek_destination_pickup">
			<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
			<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
			<table class="form-table" role="presentation">
				<?php $this->number_row( 'pek_destination_location_id', 'Canonical location ID', 0, 1, 999999999 ); ?>
				<?php $this->decimal_row( 'pek_destination_weight_kg', 'Общий вес груза, кг', 1, 0.001, 100000, 0.001 ); ?>
				<?php $this->decimal_row( 'pek_destination_length_cm', 'Длина одного места, см', 10, 0.1, 2000, 0.1 ); ?>
				<?php $this->decimal_row( 'pek_destination_width_cm', 'Ширина одного места, см', 10, 0.1, 2000, 0.1 ); ?>
				<?php $this->decimal_row( 'pek_destination_height_cm', 'Высота одного места, см', 10, 0.1, 2000, 0.1 ); ?>
				<?php $this->decimal_row( 'pek_destination_max_place_weight_kg', 'Максимальный вес одного места, кг', 1, 0.001, 100000, 0.001 ); ?>
				<?php $this->number_row( 'pek_destination_places_count', 'Количество мест', 1, 1, 1000 ); ?>
			</table>
			<?php submit_button( __( 'Проверить направление и терминалы ПЭК', 'walls-delivery-calc' ), 'secondary' ); ?>
		</form>
		<?php $this->render_destination_diagnostic_report( $destination_report ); ?>
		<?php
	}

	/** @param array<string,mixed> $search */
	private function render_search_results( DeliveryService $service, array $search ): void {
		$items = is_array( $search['items'] ?? null ) ? $search['items'] : array();
		if ( array() === $items ) {
			return;
		}
		?>
		<table class="widefat striped" style="max-width: 1180px;">
			<thead><tr><th>Склад</th><th>Филиал</th><th>Тип</th><th>Адрес</th><th>Ограничения</th><th></th></tr></thead>
			<tbody>
				<?php foreach ( $items as $item ) : ?>
					<?php if ( ! is_array( $item ) ) { continue; } ?>
					<tr>
						<td><code><?php echo esc_html( (string) ( $item['warehouseId'] ?? '' ) ); ?></code><br><?php echo esc_html( (string) ( $item['divisionName'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $item['branchName'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $item['departmentType'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( (string) ( $item['address'] ?? '' ) ); ?></td>
						<td><?php echo esc_html( 'Вес: ' . (string) ( $item['maxWeight'] ?? '—' ) . ', объем: ' . (string) ( $item['maxVolume'] ?? '—' ) . ', габарит: ' . (string) ( $item['maxDimension'] ?? '—' ) ); ?></td>
						<td>
							<form method="post">
								<?php wp_nonce_field( 'wdc_delivery_services' ); ?>
								<input type="hidden" name="wdc_delivery_services_action" value="select_pek_sender_warehouse">
								<input type="hidden" name="id" value="<?php echo esc_attr( (string) $service->id ); ?>">
								<input type="hidden" name="service_key" value="<?php echo esc_attr( $service->service_key ); ?>">
								<input type="hidden" name="pek_sender_warehouse_id" value="<?php echo esc_attr( (string) ( $item['warehouseId'] ?? '' ) ); ?>">
								<button class="button button-secondary" type="submit"><?php echo esc_html__( 'Выбрать', 'walls-delivery-calc' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/** @param array<string,mixed> $snapshot */
	private function render_warehouse_snapshot( array $snapshot ): void {
		if ( array() === $snapshot ) {
			echo '<p>' . esc_html__( 'Склад самопривоза ПЭК не выбран.', 'walls-delivery-calc' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped" style="max-width:760px;"><tbody>';
		foreach ( array( 'warehouseId' => 'Warehouse ID', 'source' => 'Источник выбора', 'branchName' => 'Филиал', 'divisionName' => 'Отделение', 'departmentType' => 'Тип', 'address' => 'Адрес', 'branchTimezone' => 'Часовой пояс филиала', 'checked_at' => 'Проверено' ) as $key => $label ) {
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>' . esc_html( (string) ( $snapshot[ $key ] ?? '' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** @param array<string,mixed> $report */
	private function render_destination_diagnostic_report( array $report ): void {
		if ( array() === $report ) {
			return;
		}
		echo '<table class="widefat striped" style="max-width:1180px;"><tbody>';
		foreach ( array(
			'checked_at' => 'Проверено',
			'success' => 'Статус',
			'message' => 'Сообщение',
			'error_code' => 'Код ошибки',
			'api_error_message' => 'Ошибка ПЭК',
			'failure_stage' => 'Этап',
			'endpoint' => 'Endpoint',
			'http_status' => 'HTTP status',
		) as $key => $label ) {
			if ( ! array_key_exists( $key, $report ) || '' === (string) $this->destination_report_value( $report[ $key ], $key, $report ) ) {
				continue;
			}
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			if ( 'error_code' === $key ) {
				echo '<code>' . esc_html( $this->destination_report_value( $report[ $key ], $key, $report ) ) . '</code>';
			} else {
				echo esc_html( $this->destination_report_value( $report[ $key ], $key, $report ) );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';

		$location = is_array( $report['location'] ?? null ) ? $report['location'] : array();
		$this->render_destination_named_section(
			'Location',
			$location,
			array(
				'location_id' => 'Canonical location ID',
				'country' => 'Страна',
				'canonical_address' => 'Canonical address',
				'coordinates_available' => 'Координаты доступны',
				'resolution_method' => 'Resolution method',
				'mapping_state' => 'Mapping state',
				'precision' => 'Precision',
				'branch' => 'Branch',
				'zone' => 'Zone',
				'main_warehouse_id' => 'Main warehouse ID',
				'mapping_cache_hit' => 'Mapping cache hit',
			),
			array( 'main_warehouse_id' )
		);

		$terminals = is_array( $report['terminals'] ?? null ) ? $report['terminals'] : array();
		$this->render_destination_named_section(
			'Terminals',
			$terminals,
			array(
				'total_returned' => 'Total returned',
				'free_count' => 'Free count',
				'paid_count' => 'Paid count',
				'rejected_invalid' => 'Rejected invalid',
				'rejected_limits' => 'Rejected limits',
				'api_source' => 'API source',
				'query_fingerprint' => 'Query fingerprint',
			),
			array( 'query_fingerprint' )
		);
		if ( is_array( $terminals['points'] ?? null ) ) {
			$this->render_destination_points( $terminals['points'] );
		}

		$response_shape = is_array( $report['response_shape'] ?? null ) ? $report['response_shape'] : array();
		$this->render_destination_named_section(
			'Response shape',
			$response_shape,
			array(
				'root_type' => 'Root type',
				'root_keys' => 'Root keys',
				'root_count' => 'Root count',
				'free_departments_present' => 'Free departments present',
				'free_departments_type' => 'Free departments type',
				'free_departments_count' => 'Free departments count',
				'paid_departments_present' => 'Paid departments present',
				'paid_departments_type' => 'Paid departments type',
				'paid_departments_count' => 'Paid departments count',
			)
		);

		$rejections = is_array( $report['rejections'] ?? null ) ? $report['rejections'] : array();
		$this->render_destination_named_section(
			'Rejections',
			$rejections,
			array(
				'row_not_object' => 'row_not_object',
				'warehouse_id' => 'warehouse_id',
				'coordinates' => 'coordinates',
				'text_fields' => 'text_fields',
				'integer_fields' => 'integer_fields',
				'limits' => 'limits',
				'work_time' => 'work_time',
				'schedule' => 'schedule',
				'timezone' => 'timezone',
				'unknown' => 'unknown',
			)
		);
	}

	/** @param array<string,mixed> $values @param array<string,string> $labels @param array<int,string> $code_keys */
	private function render_destination_named_section( string $title, array $values, array $labels, array $code_keys = array() ): void {
		if ( array() === $values ) {
			return;
		}
		echo '<h4>' . esc_html( $title ) . '</h4>';
		echo '<table class="widefat striped" style="max-width:1180px;"><tbody>';
		foreach ( $labels as $key => $label ) {
			if ( ! array_key_exists( $key, $values ) ) {
				continue;
			}
			$value = $this->destination_report_value( $values[ $key ], $key );
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			if ( in_array( $key, $code_keys, true ) && '—' !== $value ) {
				echo '<code>' . esc_html( $value ) . '</code>';
			} else {
				echo esc_html( $value );
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** @param array<string,mixed> $report */
	private function destination_report_value( mixed $value, string $key = '', array $report = array() ): string {
		if ( 'success' === $key ) {
			return true === $value ? 'Успешно' : 'Ошибка';
		}
		if ( 'endpoint' === $key ) {
			$method = trim( (string) ( $report['method'] ?? '' ) );
			$endpoint = trim( (string) $value );
			return '' !== $method && '' !== $endpoint ? $method . ' ' . $endpoint : $endpoint;
		}
		if ( is_bool( $value ) ) {
			return $value ? 'да' : 'нет';
		}
		if ( null === $value || '' === $value || array() === $value ) {
			return '—';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) ) {
			$scalar = true;
			foreach ( $value as $item ) {
				if ( ! is_scalar( $item ) && null !== $item ) {
					$scalar = false;
					break;
				}
			}
			if ( $scalar ) {
				return implode( ', ', array_map( static fn( mixed $item ): string => null === $item || '' === $item ? '—' : (string) $item, $value ) );
			}
		}

		return '—';
	}

	/** @param array<int,mixed> $points */
	private function render_destination_points( array $points ): void {
		if ( array() === $points ) {
			return;
		}
		echo '<table class="widefat striped"><thead><tr><th>Warehouse ID</th><th>Источник</th><th>Тип</th><th>Филиал</th><th>Отделение</th><th>Адрес</th><th>Координаты</th><th>Время работы</th><th>Ограничения</th></tr></thead><tbody>';
		foreach ( array_slice( $points, 0, 20 ) as $point ) {
			if ( ! is_array( $point ) ) {
				continue;
			}
			$ref = is_array( $point['raw_reference'] ?? null ) ? $point['raw_reference'] : array();
			$limits = is_array( $ref['limits'] ?? null ) ? $ref['limits'] : array();
			echo '<tr><td><code>' . esc_html( (string) ( $point['code'] ?? '' ) ) . '</code></td><td>' . esc_html( (string) ( $ref['source'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $point['type'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $ref['branch_name'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $ref['division_name'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $point['address'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $point['latitude'] ?? '' ) . ', ' . (string) ( $point['longitude'] ?? '' ) ) . '</td><td>' . esc_html( (string) ( $point['work_time'] ?? '' ) ) . '</td><td>' . esc_html( $this->format_report_value( $limits, 'limits' ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** @param array<string,mixed> $report */
	private function render_compact_report( array $report ): void {
		if ( array() === $report ) {
			return;
		}
		echo '<table class="widefat striped" style="max-width:760px;"><tbody>';
		foreach ( $report as $key => $value ) {
			if ( 'checks' === (string) $key && is_array( $value ) ) {
				echo '<tr><th scope="row">' . esc_html( (string) $key ) . '</th><td>';
				$this->render_diagnostic_checks( $value );
				echo '</td></tr>';
				continue;
			}
			echo '<tr><th scope="row">' . esc_html( (string) $key ) . '</th><td>' . esc_html( $this->format_report_value( $value, (string) $key ) ) . '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/** @param array<string,mixed> $checks */
	private function render_diagnostic_checks( array $checks ): void {
		if ( array() === $checks ) {
			echo esc_html( '—' );
			return;
		}
		$labels = array(
			'products' => 'Справочник продуктов',
			'countries' => 'Справочник стран',
			'legal_forms' => 'Юридические формы',
			'warehouse_api' => 'Проверка выбранного склада',
			'warehouse_match' => 'Сопоставление выбранного склада',
		);
		echo '<table class="widefat striped" style="max-width:100%;"><tbody>';
		foreach ( $labels as $key => $label ) {
			if ( ! is_array( $checks[ $key ] ?? null ) ) {
				continue;
			}
			$check = $checks[ $key ];
			$status = (string) ( $check['status'] ?? ( ( $check['success'] ?? false ) ? 'passed' : 'failed' ) );
			$status_label = match ( $status ) {
				'passed' => 'Успешно',
				'failed' => 'Ошибка',
				'warning' => true === ( $check['informational'] ?? false ) ? 'Информация' : 'Предупреждение',
				'skipped' => 'Пропущено',
				default => 'Предупреждение',
			};
			$http_status = null !== ( $check['http_status'] ?? null ) && '' !== (string) $check['http_status'] ? ' HTTP ' . (string) $check['http_status'] : '';
			$line = trim( $status_label . ':' . $http_status . ' ' . (string) ( $check['message'] ?? '' ) );
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td>';
			echo '<code>' . esc_html( (string) ( $check['method'] ?? '' ) . ' ' . (string) ( $check['endpoint'] ?? '' ) ) . '</code><br>';
			echo esc_html( $line );
			if ( 'warehouse_match' === $key ) {
				echo '<br>' . esc_html(
					'Проверено филиалов: ' . (string) (int) ( $check['branches_checked'] ?? 0 )
					. ', отделений: ' . (string) (int) ( $check['divisions_checked'] ?? 0 )
					. ', складов: ' . (string) (int) ( $check['warehouses_checked'] ?? 0 )
				);
				if ( '' !== (string) ( $check['warehouse_id'] ?? '' ) ) {
					echo '<br>' . esc_html( 'warehouse ID: ' . (string) $check['warehouse_id'] );
				}
			}
			if ( '' !== (string) ( $check['error_code'] ?? '' ) ) {
				echo '<br><code>' . esc_html( (string) $check['error_code'] ) . '</code>';
			}
			if ( '' !== (string) ( $check['info_code'] ?? '' ) ) {
				echo '<br><code>' . esc_html( (string) $check['info_code'] ) . '</code>';
			}
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	private function format_report_value( mixed $value, string $key = '' ): string {
		if ( is_bool( $value ) ) {
			return $value ? 'да' : 'нет';
		}
		if ( null === $value || array() === $value ) {
			return '—';
		}
		if ( is_scalar( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) ) {
			if ( 'classifier_mismatches' === $key ) {
				$rows = array();
				foreach ( $value as $item ) {
					if ( is_array( $item ) ) {
						$rows[] = trim( (string) ( $item['country'] ?? '' ) ) . ': ожидался ' . trim( (string) ( $item['expected'] ?? '' ) ) . ', API вернул ' . trim( (string) ( $item['actual'] ?? '' ) );
					}
				}
				return array() !== $rows ? implode( '; ', $rows ) : '—';
			}
			$scalar = true;
			foreach ( $value as $item ) {
				if ( ! is_scalar( $item ) && null !== $item ) {
					$scalar = false;
					break;
				}
			}
			if ( $scalar ) {
				return implode( ', ', array_map( static fn( mixed $item ): string => null === $item ? '' : (string) $item, $value ) );
			}

			$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $value, JSON_UNESCAPED_UNICODE ) : json_encode( $value, JSON_UNESCAPED_UNICODE );
			return is_string( $json ) ? $json : '—';
		}

		return '—';
	}

	/** @param array<string,mixed> $notice */
	private function render_notice( array $notice ): void {
		if ( array() === $notice ) {
			return;
		}
		$type = in_array( (string) ( $notice['type'] ?? 'info' ), array( 'success', 'warning', 'error' ), true ) ? (string) $notice['type'] : 'info';
		echo '<div class="notice notice-' . esc_attr( $type ) . ' inline"><p>' . esc_html( (string) ( $notice['message'] ?? '' ) ) . '</p></div>';
	}

	private function text_row( string $name, string $label, string $value ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input class="regular-text" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '"></td></tr>';
	}

	private function number_row( string $name, string $label, int $value, int $min, int $max ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input class="small-text" type="number" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"></td></tr>';
	}

	private function decimal_row( string $name, string $label, float $value, float $min, float $max, float $step ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><input class="small-text" type="number" step="' . esc_attr( (string) $step ) . '" min="' . esc_attr( (string) $min ) . '" max="' . esc_attr( (string) $max ) . '" id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '"></td></tr>';
	}

	/** @param array<string,string> $options */
	private function select_row( string $name, string $label, string $value, array $options ): void {
		echo '<tr><th scope="row"><label for="' . esc_attr( $name ) . '">' . esc_html( $label ) . '</label></th><td><select id="' . esc_attr( $name ) . '" name="' . esc_attr( $name ) . '">';
		foreach ( $options as $option => $text ) {
			echo '<option value="' . esc_attr( $option ) . '" ' . selected( $value, $option, false ) . '>' . esc_html( $text ) . '</option>';
		}
		echo '</select></td></tr>';
	}

	/** @return array<string,string> */
	private function country_options(): array {
		$options = array();
		foreach ( PekSettings::COUNTRY_CLASSIFIER_CODES as $country => $code ) {
			$options[ $country ] = $country . ' (' . $code . ')';
		}

		return $options;
	}

	/** @param array<string,mixed> $post */
	private function string_from_post( array $post, string $key ): string {
		$value = (string) ( $post[ $key ] ?? '' );
		$value = function_exists( 'wp_unslash' ) ? wp_unslash( $value ) : $value;

		return trim( function_exists( 'sanitize_text_field' ) ? sanitize_text_field( $value ) : $value );
	}

	private function safe_message( string $message ): string {
		$message = preg_replace( '/Basic\s+[A-Za-z0-9+\/=]+/i', 'Basic [redacted]', $message ) ?? $message;
		$message = preg_replace( '/[A-Za-z0-9._~+\-\/]{24,}/', '[redacted]', $message ) ?? $message;

		return trim( $message );
	}
}
