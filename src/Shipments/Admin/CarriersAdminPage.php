<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Admin;

use WallsShop\WDC\Admin\AdminMenu;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;

defined( 'ABSPATH' ) || exit;

final class CarriersAdminPage {
	public const MENU_SLUG = 'wdc-carriers';

	public function __construct( private RussianPostOtpravkaApiSettings $otpravka_settings ) {
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
		add_action( 'admin_init', array( $this, 'handle_post' ) );
	}

	public function add_menu_page(): void {
		add_submenu_page(
			AdminMenu::MENU_SLUG,
			__( 'Перевозчики', 'walls-delivery-calc' ),
			__( 'Перевозчики', 'walls-delivery-calc' ),
			AdminMenu::CAPABILITY,
			self::MENU_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function handle_post(): void {
		if ( ! is_admin() || ! current_user_can( AdminMenu::CAPABILITY ) || 'save_russian_post_otpravka' !== (string) ( $_POST['wdc_carriers_action'] ?? '' ) ) {
			return;
		}
		check_admin_referer( 'wdc_carriers' );
		$this->otpravka_settings->save_from_admin( $_POST );
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&updated=1' ) );
		exit;
	}

	public function render_page(): void {
		if ( ! current_user_can( AdminMenu::CAPABILITY ) ) {
			return;
		}
		$values = $this->otpravka_settings->values();
		$postoffice_codes = $this->otpravka_settings->postoffice_codes();
		?>
		<div class="wrap">
			<h1><?php echo esc_html__( 'Перевозчики', 'walls-delivery-calc' ); ?></h1>
			<?php if ( ! empty( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html__( 'Настройки перевозчика сохранены.', 'walls-delivery-calc' ); ?></p></div>
			<?php endif; ?>
			<nav class="nav-tab-wrapper"><a class="nav-tab nav-tab-active" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ); ?>"><?php echo esc_html__( 'Почта России', 'walls-delivery-calc' ); ?></a></nav>
			<form method="post" style="max-width: 820px; margin-top:16px;">
				<?php wp_nonce_field( 'wdc_carriers' ); ?>
				<input type="hidden" name="wdc_carriers_action" value="save_russian_post_otpravka">
				<h2><?php echo esc_html__( 'API Отправка', 'walls-delivery-calc' ); ?></h2>
				<p class="description"><?php echo esc_html__( 'Эти реквизиты используются для ПВЗ/ОПС и ручного создания отправлений. Секреты не выводятся обратно в HTML.', 'walls-delivery-calc' ); ?></p>
				<table class="form-table" role="presentation">
					<tr><th scope="row">AccessToken</th><td><input class="regular-text" type="password" name="russian_post_otpravka_access_token" value="" placeholder="<?php echo esc_attr( $this->otpravka_settings->has_access_token() ? 'задано' : 'не задано' ); ?>"><label style="display:block;margin-top:6px;"><input type="checkbox" name="russian_post_otpravka_clear_access_token" value="1"> <?php echo esc_html__( 'очистить сохраненный AccessToken', 'walls-delivery-calc' ); ?></label></td></tr>
					<tr><th scope="row"><label for="russian_post_otpravka_login"><?php echo esc_html__( 'Логин', 'walls-delivery-calc' ); ?></label></th><td><input class="regular-text" id="russian_post_otpravka_login" name="russian_post_otpravka_login" value="<?php echo esc_attr( (string) ( $values[ RussianPostOtpravkaApiSettings::LOGIN_KEY ] ?? '' ) ); ?>"></td></tr>
					<tr><th scope="row"><?php echo esc_html__( 'Пароль', 'walls-delivery-calc' ); ?></th><td><input class="regular-text" type="password" name="russian_post_otpravka_password" value="" placeholder="<?php echo esc_attr( $this->otpravka_settings->has_password() ? 'задано' : 'не задано' ); ?>"><label style="display:block;margin-top:6px;"><input type="checkbox" name="russian_post_otpravka_clear_password" value="1"> <?php echo esc_html__( 'очистить сохраненный пароль', 'walls-delivery-calc' ); ?></label></td></tr>
					<tr><th scope="row"><label for="russian_post_otpravka_timeout"><?php echo esc_html__( 'Таймаут API, сек.', 'walls-delivery-calc' ); ?></label></th><td><input class="small-text" id="russian_post_otpravka_timeout" name="russian_post_otpravka_timeout" type="number" min="30" max="300" value="<?php echo esc_attr( (string) ( $values[ RussianPostOtpravkaApiSettings::TIMEOUT_KEY ] ?? 120 ) ); ?>"></td></tr>
					<tr>
						<th scope="row"><?php echo esc_html__( 'Индексы места приема', 'walls-delivery-calc' ); ?></th>
						<td>
							<div data-wdc-postoffice-codes>
								<?php foreach ( $postoffice_codes as $code ) : ?>
									<p data-wdc-postoffice-code-row><input class="small-text" name="<?php echo esc_attr( RussianPostOtpravkaApiSettings::POSTOFFICE_CODES_KEY ); ?>[]" pattern="\d{6}" maxlength="6" value="<?php echo esc_attr( $code ); ?>"> <button type="button" class="button" data-wdc-remove-postoffice-code><?php echo esc_html__( 'Удалить', 'walls-delivery-calc' ); ?></button></p>
								<?php endforeach; ?>
							</div>
							<p><button type="button" class="button" data-wdc-add-postoffice-code><?php echo esc_html__( 'Добавить индекс', 'walls-delivery-calc' ); ?></button></p>
							<p class="description"><?php echo esc_html__( 'Используются в модалке создания отправления как postoffice-code. Допустимы только 6 цифр; если список пуст, применяется 630005.', 'walls-delivery-calc' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Сохранить Почту России', 'walls-delivery-calc' ) ); ?>
			</form>
			<script>
			(function () {
				var root = document.querySelector('[data-wdc-postoffice-codes]');
				if (!root) return;
				document.addEventListener('click', function (event) {
					var add = event.target.closest('[data-wdc-add-postoffice-code]');
					if (add) {
						var row = document.createElement('p');
						row.setAttribute('data-wdc-postoffice-code-row', '');
						row.innerHTML = '<input class="small-text" name="<?php echo esc_js( RussianPostOtpravkaApiSettings::POSTOFFICE_CODES_KEY ); ?>[]" pattern="\\d{6}" maxlength="6" value=""> <button type="button" class="button" data-wdc-remove-postoffice-code><?php echo esc_js( __( 'Удалить', 'walls-delivery-calc' ) ); ?></button>';
						root.appendChild(row);
						row.querySelector('input').focus();
						return;
					}
					var remove = event.target.closest('[data-wdc-remove-postoffice-code]');
					if (remove) {
						var row = remove.closest('[data-wdc-postoffice-code-row]');
						if (row && root.querySelectorAll('[data-wdc-postoffice-code-row]').length > 1) row.remove();
					}
				});
			})();
			</script>
		</div>
		<?php
	}
}
