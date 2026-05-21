<?php
declare(strict_types=1);

namespace WallsShop\WDC\Admin;

use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;

defined( 'ABSPATH' ) || exit;

final class SettingsAdminPage {
	public const PAGE_SLUG = 'wdc-platform-settings';

	private const NONCE_ACTION = 'wdc_platform_settings';
	private const NONCE_NAME = 'wdc_platform_settings_nonce';

	public function __construct(
		private SettingsRepository $settings
	) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	public function add_menu_page(): void {
		add_submenu_page(
			AdminMenu::MENU_SLUG,
			esc_html__( 'Настройки', 'walls-delivery-calc' ),
			esc_html__( 'Настройки', 'walls-delivery-calc' ),
			AdminMenu::CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}

		$message = $this->handle_post();
		$values  = $this->settings->all();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Настройки', 'walls-delivery-calc' ); ?></h1>
			<?php if ( '' !== $message ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $message ); ?></p></div>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Включить новую систему доставки', 'walls-delivery-calc' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="enable_new_checkout_shipping" value="1" <?php checked( ! empty( $values['enable_new_checkout_shipping'] ) ); ?>>
									<?php echo esc_html__( 'Регистрировать новый способ доставки и checkout-интерфейс.', 'walls-delivery-calc' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wdc_checkout_sort_mode"><?php echo esc_html__( 'Режим сортировки вариантов доставки', 'walls-delivery-calc' ); ?></label></th>
							<td>
								<select id="wdc_checkout_sort_mode" name="checkout_sort_mode">
									<option value="<?php echo esc_attr( RateSorter::CHEAPEST ); ?>" <?php selected( (string) ( $values['checkout_sort_mode'] ?? RateSorter::CHEAPEST ), RateSorter::CHEAPEST ); ?>><?php echo esc_html__( 'По цене', 'walls-delivery-calc' ); ?></option>
									<option value="<?php echo esc_attr( RateSorter::FASTEST ); ?>" <?php selected( (string) ( $values['checkout_sort_mode'] ?? RateSorter::CHEAPEST ), RateSorter::FASTEST ); ?>><?php echo esc_html__( 'По сроку', 'walls-delivery-calc' ); ?></option>
								</select>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Показывать отладочный блок checkout администраторам', 'walls-delivery-calc' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="show_checkout_debug_panel" value="1" <?php checked( ! empty( $values['show_checkout_debug_panel'] ) ); ?>>
									<?php echo esc_html__( 'Отладка скрыта по умолчанию.', 'walls-delivery-calc' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Включить тестовую ТК Demo', 'walls-delivery-calc' ); ?></th>
							<td>
								<label>
									<input type="checkbox" name="enable_demo_carrier" value="1" <?php checked( ! empty( $values['enable_demo_carrier'] ) ); ?>>
									<?php echo esc_html__( 'Использовать тестовые тарифы до подключения реальных перевозчиков.', 'walls-delivery-calc' ); ?>
								</label>
							</td>
						</tr>
						<tr>
							<th scope="row"><label for="wdc_location_search_limit"><?php echo esc_html__( 'Лимит результатов поиска населенных пунктов', 'walls-delivery-calc' ); ?></label></th>
							<td>
								<input id="wdc_location_search_limit" type="number" name="location_search_limit" value="<?php echo esc_attr( (string) ( $values['location_search_limit'] ?? 100 ) ); ?>" min="10" max="300" step="1">
							</td>
						</tr>
						<tr>
							<th colspan="2"><h2><?php echo esc_html__( 'ФИАС/ГАР', 'walls-delivery-calc' ); ?></h2></th>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Включить API ФИАС/ГАР', 'walls-delivery-calc' ); ?></th>
							<td><label><input type="checkbox" name="fias_api_enabled" value="1" <?php checked( ! empty( $values['fias_api_enabled'] ) ); ?>> <?php echo esc_html__( 'Использовать внешний API после локального поиска.', 'walls-delivery-calc' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="wdc_fias_api_timeout"><?php echo esc_html__( 'Таймаут API ФИАС (сек)', 'walls-delivery-calc' ); ?></label></th>
							<td><input id="wdc_fias_api_timeout" type="number" name="fias_api_timeout" value="<?php echo esc_attr( (string) ( $values['fias_api_timeout'] ?? 3 ) ); ?>" min="1" max="15" step="1"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wdc_fias_api_daily_limit"><?php echo esc_html__( 'Суточный лимит запросов', 'walls-delivery-calc' ); ?></label></th>
							<td><input id="wdc_fias_api_daily_limit" type="number" name="fias_api_daily_limit" value="<?php echo esc_attr( (string) ( $values['fias_api_daily_limit'] ?? 10000 ) ); ?>" min="1" max="1000000" step="1"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wdc_fias_api_minute_limit"><?php echo esc_html__( 'Лимит запросов в минуту', 'walls-delivery-calc' ); ?></label></th>
							<td><input id="wdc_fias_api_minute_limit" type="number" name="fias_api_minute_limit" value="<?php echo esc_attr( (string) ( $values['fias_api_minute_limit'] ?? 100 ) ); ?>" min="1" max="10000" step="1"></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Включить fallback DaData', 'walls-delivery-calc' ); ?></th>
							<td><label><input type="checkbox" name="dadata_enabled" value="1" <?php checked( ! empty( $values['dadata_enabled'] ) ); ?>> <?php echo esc_html__( 'Зарезервировано для будущей интеграции DaData.', 'walls-delivery-calc' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="wdc_dadata_api_timeout"><?php echo esc_html__( 'Таймаут DaData', 'walls-delivery-calc' ); ?></label></th>
							<td><input id="wdc_dadata_api_timeout" type="number" name="dadata_api_timeout" value="<?php echo esc_attr( (string) ( $values['dadata_api_timeout'] ?? 3 ) ); ?>" min="1" max="15" step="1"></td>
						</tr>
					</tbody>
				</table>
				<?php submit_button( __( 'Сохранить настройки', 'walls-delivery-calc' ) ); ?>
			</form>
		</div>
		<?php
	}

	/**
	 * @param array<string,mixed> $data
	 * @return array<string,mixed>
	 */
	public function sanitize_settings( array $data ): array {
		$sort_mode = isset( $data['checkout_sort_mode'] ) ? sanitize_key( wp_unslash( (string) $data['checkout_sort_mode'] ) ) : RateSorter::CHEAPEST;
		if ( ! in_array( $sort_mode, array( RateSorter::CHEAPEST, RateSorter::FASTEST ), true ) ) {
			$sort_mode = RateSorter::CHEAPEST;
		}

		$limit             = isset( $data['location_search_limit'] ) ? $this->absint( wp_unslash( (string) $data['location_search_limit'] ) ) : 100;
		$fias_timeout      = isset( $data['fias_api_timeout'] ) ? $this->absint( wp_unslash( (string) $data['fias_api_timeout'] ) ) : 3;
		$fias_daily_limit  = isset( $data['fias_api_daily_limit'] ) ? $this->absint( wp_unslash( (string) $data['fias_api_daily_limit'] ) ) : 10000;
		$fias_minute_limit = isset( $data['fias_api_minute_limit'] ) ? $this->absint( wp_unslash( (string) $data['fias_api_minute_limit'] ) ) : 100;
		$dadata_timeout    = isset( $data['dadata_api_timeout'] ) ? $this->absint( wp_unslash( (string) $data['dadata_api_timeout'] ) ) : 3;

		return array(
			'enable_new_checkout_shipping' => ! empty( $data['enable_new_checkout_shipping'] ),
			'checkout_sort_mode'           => $sort_mode,
			'show_checkout_debug_panel'    => ! empty( $data['show_checkout_debug_panel'] ),
			'enable_demo_carrier'          => ! empty( $data['enable_demo_carrier'] ),
			'location_search_limit'         => max( 10, min( 300, $limit > 0 ? $limit : 100 ) ),
			'fias_api_enabled'             => ! empty( $data['fias_api_enabled'] ),
			'fias_api_timeout'             => max( 1, min( 15, $fias_timeout > 0 ? $fias_timeout : 3 ) ),
			'fias_api_daily_limit'         => max( 1, min( 1000000, $fias_daily_limit > 0 ? $fias_daily_limit : 10000 ) ),
			'fias_api_minute_limit'        => max( 1, min( 10000, $fias_minute_limit > 0 ? $fias_minute_limit : 100 ) ),
			'dadata_enabled'               => ! empty( $data['dadata_enabled'] ),
			'dadata_api_timeout'           => max( 1, min( 15, $dadata_timeout > 0 ? $dadata_timeout : 3 ) ),
		);
	}

	private function absint( mixed $value ): int {
		return function_exists( 'absint' ) ? absint( $value ) : abs( (int) $value );
	}

	private function handle_post(): string {
		if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST[ self::NONCE_NAME ] ) ) {
			return '';
		}

		if ( ! wp_verify_nonce( sanitize_text_field( wp_unslash( (string) $_POST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) || ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return '';
		}

		$this->settings->replace( array_merge( $this->settings->all(), $this->sanitize_settings( $_POST ) ) );

		return __( 'Настройки сохранены.', 'walls-delivery-calc' );
	}
}
