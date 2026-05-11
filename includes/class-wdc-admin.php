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
			'manage_woocommerce',
			'wdc-delivery-calc',
			array( $this, 'render_page' )
		);
	}

	public function handle_save(): void {
		if ( ! isset( $_POST['wdc_settings_submit'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
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

	public function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'walls-delivery-calc' ) );
		}

		$tabs = $this->get_tabs();
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'general';
		$active_tab = array_key_exists( $active_tab, $tabs ) ? $active_tab : 'general';
		$settings = $this->settings->get();
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
		$service = $settings['services'][ WDC_Settings::SERVICE_RUSSIAN_POST_INTERNATIONAL_PARCEL ];
		?>
		<h2><?php echo esc_html__( 'Почта России — международная доставка', 'walls-delivery-calc' ); ?></h2>
		<table class="form-table" role="presentation">
			<tbody>
				<?php $this->render_service_yes_no_row( 'enabled', __( 'Enabled', 'walls-delivery-calc' ), $service['enabled'] ); ?>
				<tr>
					<th scope="row"><label for="wdc_origin_postcode"><?php echo esc_html__( 'Origin postcode', 'walls-delivery-calc' ); ?></label></th>
					<td><input id="wdc_origin_postcode" type="text" name="wdc_settings[services][russian_post_international_parcel][origin_postcode]" value="<?php echo esc_attr( (string) $service['origin_postcode'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="wdc_object_code"><?php echo esc_html__( 'Object code', 'walls-delivery-calc' ); ?></label></th>
					<td><input id="wdc_object_code" type="number" min="0" name="wdc_settings[services][russian_post_international_parcel][object_code]" value="<?php echo esc_attr( (string) $service['object_code'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="wdc_isavia"><?php echo esc_html__( 'Isavia', 'walls-delivery-calc' ); ?></label></th>
					<td><input id="wdc_isavia" type="number" min="0" max="1" name="wdc_settings[services][russian_post_international_parcel][isavia]" value="<?php echo esc_attr( (string) $service['isavia'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="wdc_max_package_weight_g"><?php echo esc_html__( 'Max package weight, g', 'walls-delivery-calc' ); ?></label></th>
					<td><input id="wdc_max_package_weight_g" type="number" min="0" name="wdc_settings[services][russian_post_international_parcel][max_package_weight_g]" value="<?php echo esc_attr( (string) $service['max_package_weight_g'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="wdc_formula_divider"><?php echo esc_html__( 'Formula divider', 'walls-delivery-calc' ); ?></label></th>
					<td><input id="wdc_formula_divider" type="number" step="0.01" name="wdc_settings[services][russian_post_international_parcel][formula_divider]" value="<?php echo esc_attr( (string) $service['formula_divider'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="wdc_formula_add_rub"><?php echo esc_html__( 'Formula add, RUB', 'walls-delivery-calc' ); ?></label></th>
					<td><input id="wdc_formula_add_rub" type="number" step="0.01" name="wdc_settings[services][russian_post_international_parcel][formula_add_rub]" value="<?php echo esc_attr( (string) $service['formula_add_rub'] ); ?>"></td>
				</tr>
				<?php $this->render_service_yes_no_row( 'cache_until_end_of_day', __( 'Cache until end of day', 'walls-delivery-calc' ), $service['cache_until_end_of_day'] ); ?>
				<tr>
					<th scope="row"><label for="wdc_fallback_label"><?php echo esc_html__( 'Fallback label', 'walls-delivery-calc' ); ?></label></th>
					<td><textarea id="wdc_fallback_label" class="large-text" rows="3" name="wdc_settings[services][russian_post_international_parcel][fallback_label]"><?php echo esc_textarea( (string) $service['fallback_label'] ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="wdc_calculated_label_template"><?php echo esc_html__( 'Calculated label template', 'walls-delivery-calc' ); ?></label></th>
					<td><textarea id="wdc_calculated_label_template" class="large-text" rows="3" name="wdc_settings[services][russian_post_international_parcel][calculated_label_template]"><?php echo esc_textarea( (string) $service['calculated_label_template'] ); ?></textarea></td>
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
		?>
		<h2><?php echo esc_html__( 'Страны', 'walls-delivery-calc' ); ?></h2>
		<p><?php echo esc_html__( 'Сопоставление стран WooCommerce со справочником Почты России будет добавлено на следующем этапе.', 'walls-delivery-calc' ); ?></p>
		<?php
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
				<select id="wdc_service_<?php echo esc_attr( $key ); ?>" name="wdc_settings[services][russian_post_international_parcel][<?php echo esc_attr( $key ); ?>]">
					<option value="yes" <?php selected( $value, 'yes' ); ?>><?php echo esc_html__( 'yes', 'walls-delivery-calc' ); ?></option>
					<option value="no" <?php selected( $value, 'no' ); ?>><?php echo esc_html__( 'no', 'walls-delivery-calc' ); ?></option>
				</select>
			</td>
		</tr>
		<?php
	}
}
