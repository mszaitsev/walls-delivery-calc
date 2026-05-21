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

		return array(
			'enable_new_checkout_shipping' => ! empty( $data['enable_new_checkout_shipping'] ),
			'checkout_sort_mode'           => $sort_mode,
			'show_checkout_debug_panel'    => ! empty( $data['show_checkout_debug_panel'] ),
			'enable_demo_carrier'          => ! empty( $data['enable_demo_carrier'] ),
		);
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
