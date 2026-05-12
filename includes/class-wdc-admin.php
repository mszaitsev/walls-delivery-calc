<?php
/**
 * Admin page.
 *
 * @package Walls_Delivery_Calc
 */

defined( 'ABSPATH' ) || exit;

class WDC_Admin {
	private WDC_Logger $logger;

	private WDC_Settings $settings;

	public function __construct( WDC_Logger $logger, WDC_Settings $settings ) {
		$this->logger = $logger;
		$this->settings = $settings;
	}

	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'handle_save' ) );
	}

	public function add_menu_page(): void {
		add_submenu_page(
			'woocommerce',
			esc_html__( 'Walls Delivery Calc', 'walls-delivery-calc' ),
			esc_html__( 'Walls Delivery Calc', 'walls-delivery-calc' ),
			'manage_options',
			'wdc-delivery-calc',
			array( $this, 'render_page' )
		);
	}

	public function handle_save(): void {
		if ( isset( $_POST['wdc_country_overrides_submit'] ) ) {
			$this->handle_country_overrides_save();
			return;
		}

		if ( isset( $_POST['wdc_countries_refresh_submit'] ) ) {
			$this->handle_countries_refresh();
			return;
		}

		if ( ! isset( $_POST['wdc_settings_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save these settings.', 'walls-delivery-calc' ) );
		}

		check_admin_referer( 'wdc_save_settings', 'wdc_settings_nonce' );

		$settings = isset( $_POST['wdc_settings'] ) && is_array( $_POST['wdc_settings'] )
			? wp_unslash( $_POST['wdc_settings'] )
			: array();

		$this->settings->update( $settings );
		$this->logger->log( 'info', 'Settings saved.' );

		$tab = isset( $_POST['wdc_active_tab'] ) ? sanitize_key( wp_unslash( $_POST['wdc_active_tab'] ) ) : 'general';
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'wdc-delivery-calc',
					'tab' => $tab,
					'updated' => 'true',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function handle_countries_refresh(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to refresh countries.', 'walls-delivery-calc' ) );
		}

		check_admin_referer( 'wdc_save_settings', 'wdc_settings_nonce' );

		$countries_client = new WDC_Russian_Post_Countries( null, $this->logger, $this->settings );
		$countries = $countries_client->refresh_countries();
		$diagnostics = $countries_client->get_last_diagnostics();
		$args = array(
			'page' => 'wdc-delivery-calc',
			'tab' => 'countries',
		);

		if ( ! empty( $countries ) ) {
			set_transient( $this->get_countries_refresh_error_key(), $diagnostics, MINUTE_IN_SECONDS );
			$args['countries_refreshed'] = 'true';
		} else {
			set_transient( $this->get_countries_refresh_error_key(), $diagnostics, MINUTE_IN_SECONDS );
			$args['countries_refresh_failed'] = 'true';
		}

		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php' ) ) );
		exit;
	}

	private function handle_country_overrides_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to save country overrides.', 'walls-delivery-calc' ) );
		}

		check_admin_referer( 'wdc_save_settings', 'wdc_settings_nonce' );

		$raw_overrides = isset( $_POST['wdc_country_overrides'] ) && is_array( $_POST['wdc_country_overrides'] )
			? wp_unslash( $_POST['wdc_country_overrides'] )
			: array();

		$settings = $this->settings->get();
		$settings['country_overrides'][ WDC_Settings::SERVICE_RUSSIAN_POST_WORLDWIDE_PARCEL ] = is_array( $raw_overrides ) ? $raw_overrides : array();
		$this->settings->update( $settings );

		$countries_client = new WDC_Russian_Post_Countries( null, $this->logger, $this->settings );
		$countries_client->rebuild_cached_effective_countries();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'wdc-delivery-calc',
					'tab' => 'countries',
					'country_overrides_saved' => 'true',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function get_countries_refresh_error_key(): string {
		return 'wdc_russian_post_countries_refresh_error_' . get_current_user_id();
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_countries_refresh_error(): array {
		$error = get_transient( $this->get_countries_refresh_error_key() );
		delete_transient( $this->get_countries_refresh_error_key() );

		return is_array( $error ) ? $error : array();
	}

	/**
	 * @param array<string, mixed> $stats Refresh diagnostics.
	 */
	private function render_countries_refresh_stats( array $stats ): void {
		?>
		<p>
			<strong><?php echo esc_html__( 'Raw countries:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['raw_country_count'] ?? 0 ) ); ?>
			&nbsp;|&nbsp;
			<strong><?php echo esc_html__( 'Matched:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['matched_country_count'] ?? 0 ) ); ?>
			&nbsp;|&nbsp;
			<strong><?php echo esc_html__( 'Enabled:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['enabled_country_count'] ?? 0 ) ); ?>
			&nbsp;|&nbsp;
			<strong><?php echo esc_html__( 'Skipped unmatched:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['skipped_unmatched_count'] ?? 0 ) ); ?>
			&nbsp;|&nbsp;
			<strong><?php echo esc_html__( 'Skipped no parcel:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['skipped_no_parcel_count'] ?? 0 ) ); ?>
			&nbsp;|&nbsp;
			<strong><?php echo esc_html__( 'Skipped parcel blocked:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['skipped_parcel_blocked_count'] ?? 0 ) ); ?>
			&nbsp;|&nbsp;
			<strong><?php echo esc_html__( 'Skipped RU:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['skipped_ru_count'] ?? 0 ) ); ?>
		</p>
		<?php
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'walls-delivery-calc' ) );
		}

		$tabs = $this->get_tabs();
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		$active_tab = array_key_exists( $active_tab, $tabs ) ? $active_tab : 'general';
		$settings = $this->settings->get();
		$countries_refresh_error = $this->get_countries_refresh_error();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Walls Delivery Calc', 'walls-delivery-calc' ); ?></h1>

			<p>
				<strong><?php echo esc_html__( 'WooCommerce:', 'walls-delivery-calc' ); ?></strong>
				<?php echo esc_html( class_exists( 'WooCommerce' ) ? __( 'active', 'walls-delivery-calc' ) : __( 'inactive', 'walls-delivery-calc' ) ); ?>
			</p>

			<?php if ( isset( $_GET['updated'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['updated'] ) ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Настройки сохранены.', 'walls-delivery-calc' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['countries_refreshed'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['countries_refreshed'] ) ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Справочник стран Почты России обновлен.', 'walls-delivery-calc' ); ?></p>
					<?php if ( ! empty( $countries_refresh_error ) ) : ?>
						<?php $this->render_countries_refresh_stats( $countries_refresh_error ); ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['country_overrides_saved'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['country_overrides_saved'] ) ) ) : ?>
				<div class="notice notice-success is-dismissible">
					<p><?php echo esc_html__( 'Ручные настройки стран сохранены.', 'walls-delivery-calc' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['countries_refresh_failed'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['countries_refresh_failed'] ) ) ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html__( 'Не удалось обновить справочник стран Почты России. Checkout продолжит работать через fallback.', 'walls-delivery-calc' ); ?></p>
					<?php if ( ! empty( $countries_refresh_error ) ) : ?>
						<p>
							<strong><?php echo esc_html__( 'Причина:', 'walls-delivery-calc' ); ?></strong>
							<?php echo esc_html( (string) ( $countries_refresh_error['last_error'] ?? 'unknown_error' ) ); ?>
						</p>
						<p>
							<strong><?php echo esc_html__( 'HTTP code:', 'walls-delivery-calc' ); ?></strong>
							<?php echo esc_html( (string) ( $countries_refresh_error['http_code'] ?? 0 ) ); ?>
						</p>
						<?php $this->render_countries_refresh_stats( $countries_refresh_error ); ?>
						<?php if ( ! empty( $countries_refresh_error['body_snippet'] ) ) : ?>
							<p>
								<strong><?php echo esc_html__( 'Response snippet:', 'walls-delivery-calc' ); ?></strong>
								<code><?php echo esc_html( (string) $countries_refresh_error['body_snippet'] ); ?></code>
							</p>
						<?php endif; ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $tab_key => $tab_label ) : ?>
					<a class="nav-tab <?php echo esc_attr( $active_tab === $tab_key ? 'nav-tab-active' : '' ); ?>" href="<?php echo esc_url( add_query_arg( array( 'page' => 'wdc-delivery-calc', 'tab' => $tab_key ), admin_url( 'admin.php' ) ) ); ?>">
						<?php echo esc_html( $tab_label ); ?>
					</a>
				<?php endforeach; ?>
			</nav>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=wdc-delivery-calc' ) ); ?>">
				<?php wp_nonce_field( 'wdc_save_settings', 'wdc_settings_nonce' ); ?>
				<input type="hidden" name="wdc_active_tab" value="<?php echo esc_attr( $active_tab ); ?>">

				<?php
				if ( 'general' === $active_tab ) {
					$this->render_general_tab( $settings );
				} elseif ( 'russian_post_international' === $active_tab ) {
					$this->render_russian_post_international_tab( $settings );
				} elseif ( 'packaging' === $active_tab ) {
					$this->render_packaging_tab( $settings );
				} elseif ( 'countries' === $active_tab ) {
					$this->render_countries_tab();
				} elseif ( 'logs' === $active_tab ) {
					$this->render_logs_tab();
				}
				?>

				<?php if ( in_array( $active_tab, array( 'general', 'russian_post_international', 'packaging' ), true ) ) : ?>
					<?php submit_button( __( 'Сохранить настройки', 'walls-delivery-calc' ), 'primary', 'wdc_settings_submit' ); ?>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @return array<string, string>
	 */
	private function get_tabs(): array {
		return array(
			'general' => __( 'Общие настройки', 'walls-delivery-calc' ),
			'russian_post_international' => __( 'Почта России — международная доставка', 'walls-delivery-calc' ),
			'packaging' => __( 'Упаковка', 'walls-delivery-calc' ),
			'countries' => __( 'Страны', 'walls-delivery-calc' ),
			'logs' => __( 'Логи', 'walls-delivery-calc' ),
		);
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function render_general_tab( array $settings ): void {
		?>
		<h2><?php echo esc_html__( 'Общие настройки', 'walls-delivery-calc' ); ?></h2>
		<table class="form-table" role="presentation">
			<tbody>
				<?php $this->render_yes_no_row( 'debug_enabled', __( 'Debug enabled', 'walls-delivery-calc' ), $settings['debug_enabled'] ); ?>
				<?php $this->render_yes_no_row( 'fallback_enabled', __( 'Fallback enabled', 'walls-delivery-calc' ), $settings['fallback_enabled'] ); ?>
				<tr>
					<th scope="row"><label for="wdc_currency"><?php echo esc_html__( 'Currency', 'walls-delivery-calc' ); ?></label></th>
					<td><input id="wdc_currency" type="text" name="wdc_settings[currency]" value="<?php echo esc_attr( (string) $settings['currency'] ); ?>"></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function render_russian_post_international_tab( array $settings ): void {
		$service = $settings['services'][ WDC_Settings::SERVICE_RUSSIAN_POST_WORLDWIDE_PARCEL ];
		?>
		<h2><?php echo esc_html__( 'Почта России — международная доставка', 'walls-delivery-calc' ); ?></h2>
		<p><?php echo esc_html__( 'Этот блок относится только к международной доставке Почтой России. Доставка по России будет отдельным сценарием.', 'walls-delivery-calc' ); ?></p>
		<table class="form-table" role="presentation">
			<tbody>
				<?php $this->render_service_yes_no_row( 'enabled', __( 'Enabled', 'walls-delivery-calc' ), $service['enabled'] ); ?>
				<tr>
					<th scope="row"><label for="wdc_origin_postcode"><?php echo esc_html__( 'Origin postcode', 'walls-delivery-calc' ); ?></label></th>
					<td><input id="wdc_origin_postcode" type="text" name="wdc_settings[services][russian_post_worldwide_parcel][origin_postcode]" value="<?php echo esc_attr( (string) $service['origin_postcode'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="wdc_object_code"><?php echo esc_html__( 'Object code', 'walls-delivery-calc' ); ?></label></th>
					<td><input id="wdc_object_code" type="number" min="0" name="wdc_settings[services][russian_post_worldwide_parcel][object_code]" value="<?php echo esc_attr( (string) $service['object_code'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="wdc_isavia"><?php echo esc_html__( 'Isavia', 'walls-delivery-calc' ); ?></label></th>
					<td><input id="wdc_isavia" type="number" min="0" max="1" name="wdc_settings[services][russian_post_worldwide_parcel][isavia]" value="<?php echo esc_attr( (string) $service['isavia'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="wdc_max_package_weight_g"><?php echo esc_html__( 'Max package weight, g', 'walls-delivery-calc' ); ?></label></th>
					<td><input id="wdc_max_package_weight_g" type="number" min="0" name="wdc_settings[services][russian_post_worldwide_parcel][max_package_weight_g]" value="<?php echo esc_attr( (string) $service['max_package_weight_g'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="wdc_formula_divider"><?php echo esc_html__( 'Formula divider', 'walls-delivery-calc' ); ?></label></th>
					<td><input id="wdc_formula_divider" type="number" step="0.01" name="wdc_settings[services][russian_post_worldwide_parcel][formula_divider]" value="<?php echo esc_attr( (string) $service['formula_divider'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="wdc_formula_add_rub"><?php echo esc_html__( 'Formula add, RUB', 'walls-delivery-calc' ); ?></label></th>
					<td><input id="wdc_formula_add_rub" type="number" step="0.01" name="wdc_settings[services][russian_post_worldwide_parcel][formula_add_rub]" value="<?php echo esc_attr( (string) $service['formula_add_rub'] ); ?>"></td>
				</tr>
				<?php $this->render_service_yes_no_row( 'cache_until_end_of_day', __( 'Cache until end of day', 'walls-delivery-calc' ), $service['cache_until_end_of_day'] ); ?>
				<tr>
					<th scope="row"><label for="wdc_fallback_label"><?php echo esc_html__( 'Fallback label', 'walls-delivery-calc' ); ?></label></th>
					<td><textarea id="wdc_fallback_label" class="large-text" rows="3" name="wdc_settings[services][russian_post_worldwide_parcel][fallback_label]"><?php echo esc_textarea( (string) $service['fallback_label'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="wdc_calculated_label_template"><?php echo esc_html__( 'Calculated label template', 'walls-delivery-calc' ); ?></label></th>
					<td><textarea id="wdc_calculated_label_template" class="large-text" rows="3" name="wdc_settings[services][russian_post_worldwide_parcel][calculated_label_template]"><?php echo esc_textarea( (string) $service['calculated_label_template'] ); ?></textarea></td>
				</tr>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param array<string, mixed> $settings Settings.
	 */
	private function render_packaging_tab( array $settings ): void {
		$tiers = is_array( $settings['packaging_tiers'] ) ? $settings['packaging_tiers'] : array();
		?>
		<h2><?php echo esc_html__( 'Упаковка', 'walls-delivery-calc' ); ?></h2>
		<table class="widefat striped" style="max-width: 760px;">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Вес корзины от, г', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Вес корзины до, г', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Вес упаковки, г', 'walls-delivery-calc' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $tiers as $index => $tier ) : ?>
					<tr>
						<td><input type="number" min="0" name="wdc_settings[packaging_tiers][<?php echo esc_attr( (string) $index ); ?>][from_weight_g]" value="<?php echo esc_attr( (string) $tier['from_weight_g'] ); ?>"></td>
						<td><input type="number" min="0" name="wdc_settings[packaging_tiers][<?php echo esc_attr( (string) $index ); ?>][to_weight_g]" value="<?php echo esc_attr( (string) $tier['to_weight_g'] ); ?>"></td>
						<td><input type="number" min="0" name="wdc_settings[packaging_tiers][<?php echo esc_attr( (string) $index ); ?>][packaging_weight_g]" value="<?php echo esc_attr( (string) $tier['packaging_weight_g'] ); ?>"></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_countries_tab(): void {
		$countries_client = new WDC_Russian_Post_Countries( null, $this->logger, $this->settings );
		$payload = $countries_client->get_cache_payload();
		$all_countries = isset( $payload['all_countries'] ) && is_array( $payload['all_countries'] ) ? $payload['all_countries'] : array();
		$stats = isset( $payload['stats'] ) && is_array( $payload['stats'] ) ? $payload['stats'] : array();
		$enabled_countries = array_values(
			array_filter(
				$all_countries,
				static function ( array $country ): bool {
					return ! empty( $country['effective_enabled'] );
				}
			)
		);
		$disabled_countries = array_values(
			array_filter(
				$all_countries,
				static function ( array $country ): bool {
					return empty( $country['effective_enabled'] );
				}
			)
		);
		$sort_by_name = static function ( array $a, array $b ): int {
			return strnatcasecmp( (string) ( $a['name'] ?? '' ), (string) ( $b['name'] ?? '' ) );
		};
		usort( $enabled_countries, $sort_by_name );
		usort( $disabled_countries, $sort_by_name );
		?>
		<h2><?php echo esc_html__( 'Страны', 'walls-delivery-calc' ); ?></h2>
		<p><?php echo esc_html__( 'Автоматическое сопоставление: WooCommerce ISO2 -> ISO2 Почты России. Ручной режим меняет только итоговую доступность.', 'walls-delivery-calc' ); ?></p>
		<p>
			<button type="submit" class="button button-secondary" name="wdc_countries_refresh_submit" value="1">
				<?php echo esc_html__( 'Обновить справочник стран Почты России', 'walls-delivery-calc' ); ?>
			</button>
		</p>

		<?php if ( empty( $all_countries ) ) : ?>
			<p><?php echo esc_html__( 'Справочник еще не загружен. Нажмите кнопку обновления.', 'walls-delivery-calc' ); ?></p>
			<?php
			return;
		endif;
		?>

		<h3><?php echo esc_html__( 'Summary', 'walls-delivery-calc' ); ?></h3>
		<p>
			<strong><?php echo esc_html__( 'Raw countries:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['raw_country_count'] ?? count( $all_countries ) ) ); ?>
			&nbsp;|&nbsp;
			<strong><?php echo esc_html__( 'Matched:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['matched_country_count'] ?? 0 ) ); ?>
			&nbsp;|&nbsp;
			<strong><?php echo esc_html__( 'Effective enabled:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['effective_enabled_count'] ?? count( $enabled_countries ) ) ); ?>
			&nbsp;|&nbsp;
			<strong><?php echo esc_html__( 'Skipped unmatched:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['skipped_unmatched_count'] ?? 0 ) ); ?>
			&nbsp;|&nbsp;
			<strong><?php echo esc_html__( 'Skipped no parcel:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['skipped_no_parcel_count'] ?? 0 ) ); ?>
			&nbsp;|&nbsp;
			<strong><?php echo esc_html__( 'Skipped parcel blocked:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['skipped_parcel_blocked_count'] ?? 0 ) ); ?>
			&nbsp;|&nbsp;
			<strong><?php echo esc_html__( 'Skipped RU:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['skipped_ru_count'] ?? 0 ) ); ?>
			&nbsp;|&nbsp;
			<strong><?php echo esc_html__( 'Last updated:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( ! empty( $payload['updated_at'] ) ? (string) $payload['updated_at'] : __( 'нет данных', 'walls-delivery-calc' ) ); ?>
		</p>

		<h3><?php echo esc_html__( 'Доступные страны', 'walls-delivery-calc' ); ?></h3>
		<?php $this->render_country_overrides_table( $enabled_countries ); ?>

		<h3><?php echo esc_html__( 'Недоступные / требуют проверки', 'walls-delivery-calc' ); ?></h3>
		<?php $this->render_country_overrides_table( $disabled_countries ); ?>

		<?php submit_button( __( 'Сохранить ручные настройки стран', 'walls-delivery-calc' ), 'primary', 'wdc_country_overrides_submit' ); ?>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $countries Countries.
	 */
	private function render_country_overrides_table( array $countries ): void {
		?>
		<table class="widefat striped" style="max-width: 1200px;">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'WooCommerce ISO', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Страна', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Код Почты России', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Авто-статус', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Ручной режим', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Итог', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Примечание', 'walls-delivery-calc' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $countries ) ) : ?>
					<tr>
						<td colspan="7"><?php echo esc_html__( 'Нет стран для отображения.', 'walls-delivery-calc' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $countries as $country ) : ?>
						<?php
						$override_key = (string) ( $country['override_key'] ?? ( ! empty( $country['iso2'] ) ? strtoupper( (string) $country['iso2'] ) : 'carrier:' . (string) ( $country['carrier_country_id'] ?? '' ) ) );
						$manual_status = (string) ( $country['manual_status'] ?? 'auto' );
						?>
						<tr>
							<td><?php echo esc_html( (string) ( $country['iso2'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $country['name'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $country['carrier_country_id'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( $this->get_country_status_label( (string) ( $country['auto_status'] ?? '' ) ) ); ?></td>
							<td>
								<input type="hidden" name="wdc_country_overrides[<?php echo esc_attr( $override_key ); ?>][carrier_country_id]" value="<?php echo esc_attr( (string) ( $country['carrier_country_id'] ?? '' ) ); ?>">
								<input type="hidden" name="wdc_country_overrides[<?php echo esc_attr( $override_key ); ?>][country_name]" value="<?php echo esc_attr( (string) ( $country['name'] ?? '' ) ); ?>">
								<select name="wdc_country_overrides[<?php echo esc_attr( $override_key ); ?>][enabled]">
									<option value="auto" <?php selected( $manual_status, 'auto' ); ?>><?php echo esc_html__( 'Авто', 'walls-delivery-calc' ); ?></option>
									<option value="yes" <?php selected( $manual_status, 'yes' ); ?>><?php echo esc_html__( 'Доставка есть', 'walls-delivery-calc' ); ?></option>
									<option value="no" <?php selected( $manual_status, 'no' ); ?>><?php echo esc_html__( 'Доставки нет', 'walls-delivery-calc' ); ?></option>
								</select>
							</td>
							<td><?php echo esc_html( $this->get_country_status_label( (string) ( $country['effective_reason'] ?? '' ) ) ); ?></td>
							<td><input type="text" class="regular-text" name="wdc_country_overrides[<?php echo esc_attr( $override_key ); ?>][note]" value="<?php echo esc_attr( (string) ( $country['note'] ?? '' ) ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function get_country_status_label( string $status ): string {
		$labels = array(
			'enabled' => __( 'Доступна', 'walls-delivery-calc' ),
			'unmatched' => __( 'Не сопоставлена', 'walls-delivery-calc' ),
			'no_parcel' => __( 'Нет parcel', 'walls-delivery-calc' ),
			'parcel_blocked' => __( 'Parcel заблокирован', 'walls-delivery-calc' ),
			'ru' => __( 'Россия исключена', 'walls-delivery-calc' ),
			'manual_enabled' => __( 'Включена вручную', 'walls-delivery-calc' ),
			'manual_disabled' => __( 'Отключена вручную', 'walls-delivery-calc' ),
			'auto_enabled' => __( 'Доступна автоматически', 'walls-delivery-calc' ),
		);

		return $labels[ $status ] ?? $status;
	}

	private function render_logs_tab(): void {
		?>
		<h2><?php echo esc_html__( 'Логи', 'walls-delivery-calc' ); ?></h2>
		<p><?php echo esc_html__( 'Логи будут добавлены на следующем этапе.', 'walls-delivery-calc' ); ?></p>
		<?php
	}

	private function render_yes_no_row( string $key, string $label, string $value ): void {
		?>
		<tr>
			<th scope="row"><label for="wdc_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="wdc_<?php echo esc_attr( $key ); ?>" name="wdc_settings[<?php echo esc_attr( $key ); ?>]">
					<option value="yes" <?php selected( $value, 'yes' ); ?>><?php echo esc_html__( 'yes', 'walls-delivery-calc' ); ?></option>
					<option value="no" <?php selected( $value, 'no' ); ?>><?php echo esc_html__( 'no', 'walls-delivery-calc' ); ?></option>
				</select>
			</td>
		</tr>
		<?php
	}

	private function render_service_yes_no_row( string $key, string $label, string $value ): void {
		?>
		<tr>
			<th scope="row"><label for="wdc_service_<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<select id="wdc_service_<?php echo esc_attr( $key ); ?>" name="wdc_settings[services][russian_post_worldwide_parcel][<?php echo esc_attr( $key ); ?>]">
					<option value="yes" <?php selected( $value, 'yes' ); ?>><?php echo esc_html__( 'yes', 'walls-delivery-calc' ); ?></option>
					<option value="no" <?php selected( $value, 'no' ); ?>><?php echo esc_html__( 'no', 'walls-delivery-calc' ); ?></option>
				</select>
			</td>
		</tr>
		<?php
	}
}
