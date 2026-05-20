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
		if ( isset( $_POST['wdc_preview_bulk_country_overrides'] ) ) {
			$this->handle_bulk_country_overrides_preview();
			return;
		}

		if ( isset( $_POST['wdc_apply_bulk_country_overrides'] ) ) {
			$this->handle_bulk_country_overrides_apply();
			return;
		}

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

	private function handle_bulk_country_overrides_preview(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to preview country overrides.', 'walls-delivery-calc' ) );
		}

		check_admin_referer( 'wdc_save_settings', 'wdc_settings_nonce' );

		$countries_client = new WDC_Russian_Post_Countries( null, $this->logger, $this->settings );
		$payload = $countries_client->get_cache_payload();
		$all_countries = isset( $payload['all_countries'] ) && is_array( $payload['all_countries'] ) ? $payload['all_countries'] : array();

		if ( empty( $all_countries ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'wdc-delivery-calc',
						'tab' => 'countries',
						'bulk_country_cache_missing' => 'true',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$enabled_text = $this->get_posted_bulk_country_text( 'wdc_bulk_country_enabled_text' );
		$disabled_text = $this->get_posted_bulk_country_text( 'wdc_bulk_country_disabled_text' );
		$preview = $this->build_bulk_country_overrides_preview( $enabled_text, $disabled_text, $all_countries );

		set_transient(
			$this->get_bulk_country_preview_key(),
			array(
				'enabled_text' => $enabled_text,
				'disabled_text' => $disabled_text,
				'rows' => $preview,
			),
			MINUTE_IN_SECONDS * 10
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'wdc-delivery-calc',
					'tab' => 'countries',
					'bulk_country_preview' => 'true',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function handle_bulk_country_overrides_apply(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to apply country overrides.', 'walls-delivery-calc' ) );
		}

		check_admin_referer( 'wdc_save_settings', 'wdc_settings_nonce' );

		$countries_client = new WDC_Russian_Post_Countries( null, $this->logger, $this->settings );
		$payload = $countries_client->get_cache_payload();
		$all_countries = isset( $payload['all_countries'] ) && is_array( $payload['all_countries'] ) ? $payload['all_countries'] : array();

		if ( empty( $all_countries ) ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'wdc-delivery-calc',
						'tab' => 'countries',
						'bulk_country_cache_missing' => 'true',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$enabled_text = $this->get_posted_bulk_country_text( 'wdc_bulk_country_enabled_text' );
		$disabled_text = $this->get_posted_bulk_country_text( 'wdc_bulk_country_disabled_text' );
		$preview = $this->build_bulk_country_overrides_preview( $enabled_text, $disabled_text, $all_countries );
		$preview_data = get_transient( $this->get_bulk_country_preview_key() );
		$preview_data = is_array( $preview_data ) ? $preview_data : array();

		if (
			! isset( $preview_data['enabled_text'], $preview_data['disabled_text'] )
			|| (string) $preview_data['enabled_text'] !== $enabled_text
			|| (string) $preview_data['disabled_text'] !== $disabled_text
		) {
			set_transient(
				$this->get_bulk_country_preview_key(),
				array(
					'enabled_text' => $enabled_text,
					'disabled_text' => $disabled_text,
					'rows' => $preview,
				),
				MINUTE_IN_SECONDS * 10
			);

			wp_safe_redirect(
				add_query_arg(
					array(
						'page' => 'wdc-delivery-calc',
						'tab' => 'countries',
						'bulk_country_preview' => 'true',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$settings = $this->settings->get();
		$service_key = WDC_Settings::SERVICE_RUSSIAN_POST_WORLDWIDE_PARCEL;
		$overrides = isset( $settings['country_overrides'][ $service_key ] ) && is_array( $settings['country_overrides'][ $service_key ] )
			? $settings['country_overrides'][ $service_key ]
			: array();
		$changed = 0;
		$skipped = 0;
		$note = sprintf(
			/* translators: %s: date. */
			__( 'изменено вручную %s', 'walls-delivery-calc' ),
			wp_date( 'd.m.Y' )
		);

		foreach ( $preview as $row ) {
			$action = (string) ( $row['action'] ?? '' );
			if ( ! in_array( $action, array( 'change_to_yes', 'change_to_no' ), true ) ) {
				++$skipped;
				continue;
			}

			$country = isset( $row['country'] ) && is_array( $row['country'] ) ? $row['country'] : array();
			$key = $this->get_bulk_country_override_key( $country );
			if ( '' === $key ) {
				++$skipped;
				continue;
			}

			$existing = isset( $overrides[ $key ] ) && is_array( $overrides[ $key ] ) ? $overrides[ $key ] : array();
			$overrides[ $key ] = array(
				'enabled' => 'change_to_yes' === $action ? 'yes' : 'no',
				'manual_iso2' => isset( $existing['manual_iso2'] ) ? (string) $existing['manual_iso2'] : (string) ( $country['manual_iso2'] ?? '' ),
				'carrier_country_id' => (string) ( $country['carrier_country_id'] ?? '' ),
				'country_name' => (string) ( $country['name'] ?? '' ),
				'note' => $note,
			);
			++$changed;
		}

		$settings['country_overrides'][ $service_key ] = $overrides;
		$this->settings->update( $settings );
		$countries_client->rebuild_cached_effective_countries();

		set_transient(
			$this->get_bulk_country_apply_result_key(),
			array(
				'changed' => $changed,
				'skipped' => $skipped,
			),
			MINUTE_IN_SECONDS
		);

		delete_transient( $this->get_bulk_country_preview_key() );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'wdc-delivery-calc',
					'tab' => 'countries',
					'bulk_country_applied' => 'true',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function get_countries_refresh_error_key(): string {
		return 'wdc_russian_post_countries_refresh_error_' . get_current_user_id();
	}

	private function get_bulk_country_preview_key(): string {
		return 'wdc_bulk_country_preview_' . get_current_user_id();
	}

	private function get_bulk_country_apply_result_key(): string {
		return 'wdc_bulk_country_apply_result_' . get_current_user_id();
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
			<strong><?php echo esc_html__( 'Manually matched:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['manually_matched_count'] ?? 0 ) ); ?>
			&nbsp;|&nbsp;
			<strong><?php echo esc_html__( 'Requires parcel.block check:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['requires_check_count'] ?? 0 ) ); ?>
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

			<?php if ( isset( $_GET['bulk_country_cache_missing'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['bulk_country_cache_missing'] ) ) ) : ?>
				<div class="notice notice-warning is-dismissible">
					<p><?php echo esc_html__( 'Сначала обновите справочник стран Почты России.', 'walls-delivery-calc' ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( isset( $_GET['bulk_country_applied'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['bulk_country_applied'] ) ) ) : ?>
				<?php
				$bulk_apply_result = get_transient( $this->get_bulk_country_apply_result_key() );
				delete_transient( $this->get_bulk_country_apply_result_key() );
				$bulk_apply_result = is_array( $bulk_apply_result ) ? $bulk_apply_result : array();
				?>
				<div class="notice notice-success is-dismissible">
					<p>
						<?php
						echo esc_html(
							sprintf(
								/* translators: 1: changed count, 2: skipped count. */
								__( 'Массовые настройки стран применены. Изменено: %1$d. Пропущено: %2$d.', 'walls-delivery-calc' ),
								absint( $bulk_apply_result['changed'] ?? 0 ),
								absint( $bulk_apply_result['skipped'] ?? 0 )
							)
						);
						?>
					</p>
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
				<tr>
					<th scope="row"><label for="wdc_shipping_discount_percent_from_items_total"><?php echo esc_html__( 'Вычитать из доставки процент от чистой стоимости товаров', 'walls-delivery-calc' ); ?></label></th>
					<td>
						<input id="wdc_shipping_discount_percent_from_items_total" type="number" min="0" max="100" step="0.01" name="wdc_settings[services][russian_post_worldwide_parcel][shipping_discount_percent_from_items_total]" value="<?php echo esc_attr( (string) $service['shipping_discount_percent_from_items_total'] ); ?>">
						<p class="description"><?php echo esc_html__( 'Чистая стоимость товаров — сумма товаров после промокодов и скидок, без доставки. Скидка округляется вниз до целого рубля. Итоговая доставка не может быть меньше 1 рубля.', 'walls-delivery-calc' ); ?></p>
					</td>
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
					return true === ( $country['effective_enabled'] ?? false );
				}
			)
		);
		$disabled_countries = array_values(
			array_filter(
				$all_countries,
				static function ( array $country ): bool {
					return true !== ( $country['effective_enabled'] ?? false );
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
			<strong><?php echo esc_html__( 'Manually matched:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['manually_matched_count'] ?? 0 ) ); ?>
			&nbsp;|&nbsp;
			<strong><?php echo esc_html__( 'Requires parcel.block check:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['requires_check_count'] ?? 0 ) ); ?>
			&nbsp;|&nbsp;
			<strong><?php echo esc_html__( 'Skipped RU:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( (string) ( $stats['skipped_ru_count'] ?? 0 ) ); ?>
			&nbsp;|&nbsp;
			<strong><?php echo esc_html__( 'Last updated:', 'walls-delivery-calc' ); ?></strong>
			<?php echo esc_html( ! empty( $payload['updated_at'] ) ? (string) $payload['updated_at'] : __( 'нет данных', 'walls-delivery-calc' ) ); ?>
		</p>

		<?php $this->render_country_tables_assets(); ?>
		<?php $this->render_bulk_country_overrides_block( $all_countries ); ?>

		<h3><?php echo esc_html__( 'Доступные страны', 'walls-delivery-calc' ); ?></h3>
		<?php $this->render_country_overrides_table( $enabled_countries, 'wdc-enabled-countries' ); ?>

		<h3><?php echo esc_html__( 'Недоступные / требуют проверки', 'walls-delivery-calc' ); ?></h3>
		<?php $this->render_country_overrides_table( $disabled_countries, 'wdc-disabled-countries' ); ?>

		<?php submit_button( __( 'Сохранить ручные настройки стран', 'walls-delivery-calc' ), 'primary', 'wdc_country_overrides_submit' ); ?>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $all_countries All countries from cache.
	 */
	private function render_bulk_country_overrides_block( array $all_countries ): void {
		$preview_data = get_transient( $this->get_bulk_country_preview_key() );
		$preview_data = is_array( $preview_data ) ? $preview_data : array();
		$has_preview = isset( $_GET['bulk_country_preview'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['bulk_country_preview'] ) );
		$enabled_text = isset( $preview_data['enabled_text'] ) ? (string) $preview_data['enabled_text'] : '';
		$disabled_text = isset( $preview_data['disabled_text'] ) ? (string) $preview_data['disabled_text'] : '';
		$preview_rows = isset( $preview_data['rows'] ) && is_array( $preview_data['rows'] ) ? $preview_data['rows'] : array();
		?>
		<hr>
		<h3><?php echo esc_html__( 'Массовая сверка стран', 'walls-delivery-calc' ); ?></h3>
		<?php if ( empty( $all_countries ) ) : ?>
			<p><?php echo esc_html__( 'Сначала обновите справочник стран Почты России.', 'walls-delivery-calc' ); ?></p>
			<?php return; ?>
		<?php endif; ?>
		<table class="form-table wdc-bulk-country-overrides" role="presentation">
			<tbody>
				<tr>
					<th scope="row"><label for="wdc_bulk_country_enabled_text"><?php echo esc_html__( 'Страны, куда доставка есть', 'walls-delivery-calc' ); ?></label></th>
					<td><textarea id="wdc_bulk_country_enabled_text" class="large-text code" rows="8" name="wdc_bulk_country_enabled_text"><?php echo esc_textarea( $enabled_text ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="wdc_bulk_country_disabled_text"><?php echo esc_html__( 'Страны, куда доставки нет', 'walls-delivery-calc' ); ?></label></th>
					<td><textarea id="wdc_bulk_country_disabled_text" class="large-text code" rows="8" name="wdc_bulk_country_disabled_text"><?php echo esc_textarea( $disabled_text ); ?></textarea></td>
				</tr>
			</tbody>
		</table>
		<?php submit_button( __( 'Предварительно проверить изменения', 'walls-delivery-calc' ), 'secondary', 'wdc_preview_bulk_country_overrides', false ); ?>

		<?php if ( $has_preview ) : ?>
			<?php $this->render_bulk_country_preview_table( $preview_rows ); ?>
			<?php submit_button( __( 'Применить изменения', 'walls-delivery-calc' ), 'primary', 'wdc_apply_bulk_country_overrides', false ); ?>
		<?php endif; ?>
		<hr>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $rows Preview rows.
	 */
	private function render_bulk_country_preview_table( array $rows ): void {
		?>
		<h4><?php echo esc_html__( 'Таблица изменений', 'walls-delivery-calc' ); ?></h4>
		<table class="widefat striped wdc-bulk-country-preview-table" style="max-width: 1400px;">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'Страна из списка', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Найденная страна', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Код Почты России', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'ISO', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Текущий итог', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Текущий ручной режим', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Целевой режим', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Действие', 'walls-delivery-calc' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr>
						<td colspan="8"><?php echo esc_html__( 'Нет строк для проверки.', 'walls-delivery-calc' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo esc_html( (string) ( $row['input'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['found_name'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['carrier_country_id'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $row['iso2'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( $this->get_bulk_country_effective_label( $row['current_effective'] ?? null ) ); ?></td>
							<td><?php echo esc_html( $this->get_bulk_country_manual_label( (string) ( $row['current_manual'] ?? '' ) ) ); ?></td>
							<td><?php echo esc_html( $this->get_bulk_country_target_label( (string) ( $row['target'] ?? '' ) ) ); ?></td>
							<td>
								<code><?php echo esc_html( (string) ( $row['action'] ?? '' ) ); ?></code><br>
								<?php echo esc_html( $this->get_bulk_country_action_label( (string) ( $row['action'] ?? '' ) ) ); ?>
							</td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * @param array<int, array<string, mixed>> $countries Countries.
	 */
	private function render_country_overrides_table( array $countries, string $table_id ): void {
		$wc_countries = $this->get_wc_countries_for_select();
		$effective_options = $this->get_effective_reason_options( $countries );
		?>
		<p class="wdc-country-table-filter">
			<label for="<?php echo esc_attr( $table_id ); ?>_filter"><?php echo esc_html__( 'Фильтр по итогу', 'walls-delivery-calc' ); ?></label>
			<select id="<?php echo esc_attr( $table_id ); ?>_filter" class="wdc-effective-filter" data-table-id="<?php echo esc_attr( $table_id ); ?>">
				<option value=""><?php echo esc_html__( 'Все', 'walls-delivery-calc' ); ?></option>
				<?php foreach ( $effective_options as $reason => $label ) : ?>
					<option value="<?php echo esc_attr( $reason ); ?>"><?php echo esc_html( $label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<table id="<?php echo esc_attr( $table_id ); ?>" class="widefat striped wdc-country-overrides-table" style="max-width: 1200px;">
			<thead>
				<tr>
					<th><?php echo esc_html__( 'WooCommerce ISO', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Ручное сопоставление WooCommerce', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Страна', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Код Почты России', 'walls-delivery-calc' ); ?></th>
					<th class="wdc-auto-status-column"><?php echo esc_html__( 'Авто-статус', 'walls-delivery-calc' ); ?></th>
					<th><?php echo esc_html__( 'Ручной режим', 'walls-delivery-calc' ); ?></th>
					<th><button type="button" class="button-link wdc-effective-sort" data-table-id="<?php echo esc_attr( $table_id ); ?>"><?php echo esc_html__( 'Итог', 'walls-delivery-calc' ); ?></button></th>
					<th><?php echo esc_html__( 'Примечание', 'walls-delivery-calc' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $countries ) ) : ?>
					<tr>
						<td colspan="8"><?php echo esc_html__( 'Нет стран для отображения.', 'walls-delivery-calc' ); ?></td>
					</tr>
				<?php else : ?>
					<?php foreach ( $countries as $country ) : ?>
						<?php
						$override_key = (string) ( $country['override_key'] ?? ( ! empty( $country['iso2'] ) ? strtoupper( (string) $country['iso2'] ) : 'carrier:' . (string) ( $country['carrier_country_id'] ?? '' ) ) );
						$manual_status = (string) ( $country['manual_status'] ?? 'auto' );
						$manual_iso2 = (string) ( $country['manual_iso2'] ?? '' );
						$auto_status = (string) ( $country['auto_status'] ?? '' );
						$is_ru = 'ru' === $auto_status;
						$is_unmatched = ! $is_ru && ( 'unmatched' === $auto_status || ! empty( $country['manually_matched'] ) );
						$effective_reason = (string) ( $country['effective_reason'] ?? '' );
						$effective_label = $this->get_country_status_label( $effective_reason );
						?>
						<tr data-effective-reason="<?php echo esc_attr( $effective_reason ); ?>" data-effective-label="<?php echo esc_attr( $effective_label ); ?>">
							<td><?php echo esc_html( (string) ( $country['iso2'] ?? '' ) ); ?></td>
							<td>
								<?php if ( $is_unmatched || $is_ru ) : ?>
									<select name="wdc_country_overrides[<?php echo esc_attr( $override_key ); ?>][manual_iso2]" <?php disabled( $is_ru ); ?>>
										<option value=""><?php echo esc_html__( 'Не сопоставлено', 'walls-delivery-calc' ); ?></option>
										<?php foreach ( $wc_countries as $iso2 => $wc_country_name ) : ?>
											<option value="<?php echo esc_attr( $iso2 ); ?>" <?php selected( $manual_iso2, $iso2 ); ?>>
												<?php echo esc_html( $iso2 . ' — ' . $wc_country_name ); ?>
											</option>
										<?php endforeach; ?>
									</select>
								<?php else : ?>
									<input type="hidden" name="wdc_country_overrides[<?php echo esc_attr( $override_key ); ?>][manual_iso2]" value="">
									<?php echo esc_html__( 'Авто', 'walls-delivery-calc' ); ?>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( (string) ( $country['name'] ?? '' ) ); ?></td>
							<td><?php echo esc_html( (string) ( $country['carrier_country_id'] ?? '' ) ); ?></td>
							<td>
								<?php echo esc_html( $this->get_country_status_label( $auto_status ) ); ?>
								<?php if ( 'requires_check' === $auto_status ) : ?>
									<br><small><?php echo esc_html__( 'Требует проверки: parcel.block=1, но тарифный расчет может работать', 'walls-delivery-calc' ); ?></small>
								<?php elseif ( $is_ru ) : ?>
									<br><small><?php echo esc_html__( 'Россия исключена из международной доставки', 'walls-delivery-calc' ); ?></small>
								<?php endif; ?>
							</td>
							<td>
								<input type="hidden" name="wdc_country_overrides[<?php echo esc_attr( $override_key ); ?>][carrier_country_id]" value="<?php echo esc_attr( (string) ( $country['carrier_country_id'] ?? '' ) ); ?>">
								<input type="hidden" name="wdc_country_overrides[<?php echo esc_attr( $override_key ); ?>][country_name]" value="<?php echo esc_attr( (string) ( $country['name'] ?? '' ) ); ?>">
								<select name="wdc_country_overrides[<?php echo esc_attr( $override_key ); ?>][enabled]" <?php disabled( $is_ru ); ?>>
									<option value="auto" <?php selected( $manual_status, 'auto' ); ?>><?php echo esc_html__( 'Авто', 'walls-delivery-calc' ); ?></option>
									<option value="yes" <?php selected( $manual_status, 'yes' ); ?>><?php echo esc_html__( 'Доставка есть', 'walls-delivery-calc' ); ?></option>
									<option value="no" <?php selected( $manual_status, 'no' ); ?>><?php echo esc_html__( 'Доставки нет', 'walls-delivery-calc' ); ?></option>
								</select>
							</td>
							<td><?php echo esc_html( $effective_label ); ?></td>
							<td><input type="text" class="regular-text" name="wdc_country_overrides[<?php echo esc_attr( $override_key ); ?>][note]" value="<?php echo esc_attr( (string) ( $country['note'] ?? '' ) ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
		<?php
	}

	private function render_country_tables_assets(): void {
		?>
		<style>
			.wdc-country-overrides-table .wdc-auto-status-column,
			.wdc-country-overrides-table td:nth-child(5) {
				min-width: 200px;
				white-space: normal;
			}
			.wdc-country-overrides-table td:nth-child(5) small {
				display: block;
				max-width: 240px;
				line-height: 1.35;
			}
			.wdc-country-table-filter {
				margin: 8px 0;
			}
			.wdc-country-table-filter label {
				margin-right: 8px;
			}
			.wdc-effective-sort {
				font-weight: 600;
			}
		</style>
		<script>
			(function() {
				function getTable(id) {
					return document.getElementById(id);
				}

				function getBodyRows(table) {
					if (!table || !table.tBodies.length) {
						return [];
					}

					return Array.prototype.slice.call(table.tBodies[0].rows);
				}

				document.addEventListener('change', function(event) {
					if (!event.target.classList.contains('wdc-effective-filter')) {
						return;
					}

					var table = getTable(event.target.getAttribute('data-table-id'));
					var selectedReason = event.target.value;
					getBodyRows(table).forEach(function(row) {
						if (!row.hasAttribute('data-effective-reason')) {
							return;
						}

						row.style.display = !selectedReason || row.getAttribute('data-effective-reason') === selectedReason ? '' : 'none';
					});
				});

				document.addEventListener('click', function(event) {
					if (!event.target.classList.contains('wdc-effective-sort')) {
						return;
					}

					var table = getTable(event.target.getAttribute('data-table-id'));
					if (!table || !table.tBodies.length) {
						return;
					}

					var direction = event.target.getAttribute('data-sort-direction') === 'asc' ? 'desc' : 'asc';
					event.target.setAttribute('data-sort-direction', direction);
					getBodyRows(table).sort(function(a, b) {
						var labelA = (a.getAttribute('data-effective-label') || '').toLocaleLowerCase();
						var labelB = (b.getAttribute('data-effective-label') || '').toLocaleLowerCase();
						return direction === 'asc' ? labelA.localeCompare(labelB) : labelB.localeCompare(labelA);
					}).forEach(function(row) {
						table.tBodies[0].appendChild(row);
					});
				});
			})();
		</script>
		<?php
	}

	private function get_posted_bulk_country_text( string $key ): string {
		return isset( $_POST[ $key ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ) : '';
	}

	private function normalize_bulk_country_name( string $name ): string {
		$name = preg_split( '/[\t;]/u', $name, 2 )[0] ?? '';
		$name = trim( $name );
		$name = str_replace( array( 'ё', 'Ё' ), array( 'е', 'Е' ), $name );
		$name = str_replace( array( '.', '"', "'", '«', '»', '„', '“', '”' ), ' ', $name );
		$name = preg_replace( '/\s+/u', ' ', $name );
		$name = is_string( $name ) ? trim( $name ) : '';

		if ( function_exists( 'mb_strtoupper' ) ) {
			return mb_strtoupper( $name, 'UTF-8' );
		}

		return strtoupper( $name );
	}

	/**
	 * @return array<string, array{input: string, normalized: string}>
	 */
	private function parse_bulk_country_lines( string $text ): array {
		$lines = preg_split( '/\R/u', $text );
		$parsed = array();

		if ( ! is_array( $lines ) ) {
			return $parsed;
		}

		foreach ( $lines as $line ) {
			$input = trim( (string) $line );
			$normalized = $this->normalize_bulk_country_name( $input );
			if ( '' === $normalized ) {
				continue;
			}

			$parsed[ $normalized ] = array(
				'input' => $input,
				'normalized' => $normalized,
			);
		}

		return $parsed;
	}

	/**
	 * @param array<int, array<string, mixed>> $all_countries All countries.
	 * @return array<string, array<int, array<string, mixed>>>
	 */
	private function build_bulk_country_name_index( array $all_countries ): array {
		$index = array();

		foreach ( $all_countries as $country ) {
			if ( ! is_array( $country ) ) {
				continue;
			}

			$normalized = $this->normalize_bulk_country_name( (string) ( $country['name'] ?? '' ) );
			if ( '' === $normalized ) {
				continue;
			}

			if ( ! isset( $index[ $normalized ] ) ) {
				$index[ $normalized ] = array();
			}

			$index[ $normalized ][] = $country;
		}

		return $index;
	}

	/**
	 * @param array<int, array<string, mixed>> $all_countries All countries.
	 * @return array<int, array<string, mixed>>
	 */
	private function build_bulk_country_overrides_preview( string $enabled_text, string $disabled_text, array $all_countries ): array {
		$enabled_lines = $this->parse_bulk_country_lines( $enabled_text );
		$disabled_lines = $this->parse_bulk_country_lines( $disabled_text );
		$index = $this->build_bulk_country_name_index( $all_countries );
		$rows = array();
		$normalized_names = array_unique( array_merge( array_keys( $enabled_lines ), array_keys( $disabled_lines ) ) );
		sort( $normalized_names, SORT_NATURAL | SORT_FLAG_CASE );

		foreach ( $normalized_names as $normalized ) {
			$in_enabled = isset( $enabled_lines[ $normalized ] );
			$in_disabled = isset( $disabled_lines[ $normalized ] );
			$line = $in_enabled ? $enabled_lines[ $normalized ] : $disabled_lines[ $normalized ];
			$target = $in_enabled ? 'yes' : 'no';

			if ( $in_enabled && $in_disabled ) {
				$rows[] = $this->create_bulk_country_preview_row( $line['input'], $target, 'conflict' );
				continue;
			}

			$matches = $index[ $normalized ] ?? array();
			if ( empty( $matches ) ) {
				$rows[] = $this->create_bulk_country_preview_row( $line['input'], $target, 'not_found' );
				continue;
			}

			if ( count( $matches ) > 1 ) {
				$rows[] = $this->create_bulk_country_preview_row(
					$line['input'],
					$target,
					'ambiguous',
					array(
						'found_name' => implode( ', ', array_map( static fn( array $country ): string => (string) ( $country['name'] ?? '' ), $matches ) ),
					)
				);
				continue;
			}

			$country = $matches[0];
			$target_enabled = 'yes' === $target;
			$current_effective = true === ( $country['effective_enabled'] ?? false );
			$current_manual = (string) ( $country['manual_status'] ?? 'auto' );
			$action = '';

			if ( in_array( $current_manual, array( 'yes', 'no' ), true ) ) {
				$action = $current_manual === $target ? 'no_change_manual_correct' : ( $target_enabled ? 'change_to_yes' : 'change_to_no' );
			} elseif ( $current_effective === $target_enabled ) {
				$action = 'no_change_auto_correct';
			} else {
				$action = $target_enabled ? 'change_to_yes' : 'change_to_no';
			}

			$rows[] = $this->create_bulk_country_preview_row(
				$line['input'],
				$target,
				$action,
				array(
					'country' => $country,
					'found_name' => (string) ( $country['name'] ?? '' ),
					'carrier_country_id' => (string) ( $country['carrier_country_id'] ?? '' ),
					'iso2' => (string) ( $country['iso2'] ?? '' ),
					'current_effective' => $current_effective,
					'current_manual' => $current_manual,
				)
			);
		}

		return $rows;
	}

	/**
	 * @param array<string, mixed> $extra Extra row data.
	 * @return array<string, mixed>
	 */
	private function create_bulk_country_preview_row( string $input, string $target, string $action, array $extra = array() ): array {
		return array_merge(
			array(
				'input' => $input,
				'found_name' => '',
				'carrier_country_id' => '',
				'iso2' => '',
				'current_effective' => null,
				'current_manual' => '',
				'target' => $target,
				'action' => $action,
			),
			$extra
		);
	}

	/**
	 * @param array<string, mixed> $country Country.
	 */
	private function get_bulk_country_override_key( array $country ): string {
		if ( ! empty( $country['iso2'] ) ) {
			return strtoupper( (string) $country['iso2'] );
		}

		if ( ! empty( $country['carrier_country_id'] ) ) {
			return 'carrier:' . (string) $country['carrier_country_id'];
		}

		return '';
	}

	private function get_bulk_country_effective_label( $value ): string {
		if ( true === $value ) {
			return __( 'Доставка есть', 'walls-delivery-calc' );
		}

		if ( false === $value ) {
			return __( 'Доставки нет', 'walls-delivery-calc' );
		}

		return '';
	}

	private function get_bulk_country_manual_label( string $status ): string {
		$labels = array(
			'auto' => __( 'Авто', 'walls-delivery-calc' ),
			'yes' => __( 'Доставка есть', 'walls-delivery-calc' ),
			'no' => __( 'Доставки нет', 'walls-delivery-calc' ),
		);

		return $labels[ $status ] ?? $status;
	}

	private function get_bulk_country_target_label( string $target ): string {
		return 'yes' === $target ? __( 'Доставка есть', 'walls-delivery-calc' ) : __( 'Доставки нет', 'walls-delivery-calc' );
	}

	private function get_bulk_country_action_label( string $action ): string {
		$labels = array(
			'no_change_auto_correct' => __( 'Не менять: авто уже верно', 'walls-delivery-calc' ),
			'no_change_manual_correct' => __( 'Не менять: ручной режим уже верен', 'walls-delivery-calc' ),
			'change_to_yes' => __( 'Изменить на "Доставка есть"', 'walls-delivery-calc' ),
			'change_to_no' => __( 'Изменить на "Доставки нет"', 'walls-delivery-calc' ),
			'not_found' => __( 'Не найдено', 'walls-delivery-calc' ),
			'conflict' => __( 'Конфликт: страна есть в обоих списках', 'walls-delivery-calc' ),
			'ambiguous' => __( 'Неоднозначное совпадение', 'walls-delivery-calc' ),
		);

		return $labels[ $action ] ?? $action;
	}

	/**
	 * @param array<int, array<string, mixed>> $countries Countries.
	 * @return array<string, string>
	 */
	private function get_effective_reason_options( array $countries ): array {
		$options = array();
		foreach ( $countries as $country ) {
			$reason = (string) ( $country['effective_reason'] ?? '' );
			if ( '' === $reason || isset( $options[ $reason ] ) ) {
				continue;
			}

			$options[ $reason ] = $this->get_country_status_label( $reason );
		}

		asort( $options, SORT_NATURAL | SORT_FLAG_CASE );

		return $options;
	}

	/**
	 * @return array<string, string>
	 */
	private function get_wc_countries_for_select(): array {
		if ( ! function_exists( 'WC' ) || ! is_object( WC() ) || empty( WC()->countries ) ) {
			return array();
		}

		$countries = WC()->countries->get_countries();
		if ( ! is_array( $countries ) ) {
			return array();
		}

		$prepared = array();
		foreach ( $countries as $iso2 => $name ) {
			if ( ! is_scalar( $iso2 ) || ! is_scalar( $name ) ) {
				continue;
			}

			$iso2 = strtoupper( sanitize_text_field( (string) $iso2 ) );
			if ( ! preg_match( '/^[A-Z]{2}$/', $iso2 ) ) {
				continue;
			}

			$prepared[ $iso2 ] = sanitize_text_field( (string) $name );
		}

		asort( $prepared, SORT_NATURAL | SORT_FLAG_CASE );

		return $prepared;
	}

	private function get_country_status_label( string $status ): string {
		$labels = array(
			'enabled' => __( 'Доступна', 'walls-delivery-calc' ),
			'unmatched' => __( 'Не сопоставлена', 'walls-delivery-calc' ),
			'no_parcel' => __( 'Нет данных по посылкам', 'walls-delivery-calc' ),
			'parcel_blocked' => __( 'Требует проверки', 'walls-delivery-calc' ),
			'requires_check' => __( 'Требует проверки', 'walls-delivery-calc' ),
			'ru' => __( 'Россия исключена', 'walls-delivery-calc' ),
			'manual_enabled' => __( 'Доступна вручную', 'walls-delivery-calc' ),
			'manual_disabled' => __( 'Отключена вручную', 'walls-delivery-calc' ),
			'auto_enabled' => __( 'Доступна', 'walls-delivery-calc' ),
			'auto_requires_check' => __( 'Доступна, требует проверки', 'walls-delivery-calc' ),
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
