<?php
declare(strict_types=1);

namespace WallsShop\WDC\Admin;

use WallsShop\WDC\Checkout\AddressSuggestions\AddressSuggestionSettings;
use WallsShop\WDC\Checkout\AddressSuggestions\DaDataTokenPool;
use WallsShop\WDC\Checkout\Sorting\RateSorter;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Locations\Fias\FiasCredentials;

defined( 'ABSPATH' ) || exit;

final class SettingsAdminPage {
	public const PAGE_SLUG = 'wdc-platform-settings';

	private const NONCE_ACTION = 'wdc_platform_settings';
	private const NONCE_NAME = 'wdc_platform_settings_nonce';

	public function __construct(
		private SettingsRepository $settings,
		private ?FiasCredentials $fias_credentials = null,
		private ?AddressSuggestionSettings $suggestion_settings = null,
		private ?DaDataTokenPool $token_pool = null
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
			<?php if ( $this->fias_credentials instanceof FiasCredentials && ! $this->fias_credentials->encryption_ready() ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html__( 'APP_ENCRYPTION_KEY не задан. API-токен ФИАС/ГАР не будет сохранен, пока ключ шифрования не настроен.', 'walls-delivery-calc' ); ?></p></div>
			<?php endif; ?>
			<?php if ( $this->suggestion_settings instanceof AddressSuggestionSettings && ! $this->suggestion_settings->encryption_ready() ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html__( 'Шифрование не настроено, токены DaData не могут быть сохранены.', 'walls-delivery-calc' ); ?></p></div>
			<?php endif; ?>
			<?php if ( ! empty( $values['dadata_suggestions_enabled'] ) && $this->suggestion_settings instanceof AddressSuggestionSettings && ! $this->suggestion_settings->has_any_configured_token() ) : ?>
				<div class="notice notice-warning"><p><?php echo esc_html__( 'Подсказки DaData включены, но токены не добавлены.', 'walls-delivery-calc' ); ?></p></div>
			<?php endif; ?>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
				<table class="form-table" role="presentation">
					<tbody>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Включить новую систему доставки', 'walls-delivery-calc' ); ?></th>
							<td><label><input type="checkbox" name="enable_new_checkout_shipping" value="1" <?php checked( ! empty( $values['enable_new_checkout_shipping'] ) ); ?>> <?php echo esc_html__( 'Регистрировать новый способ доставки и checkout-интерфейс.', 'walls-delivery-calc' ); ?></label></td>
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
							<td><label><input type="checkbox" name="show_checkout_debug_panel" value="1" <?php checked( ! empty( $values['show_checkout_debug_panel'] ) ); ?>> <?php echo esc_html__( 'Отладка скрыта по умолчанию.', 'walls-delivery-calc' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Включить тестовую ТК Demo', 'walls-delivery-calc' ); ?></th>
							<td><label><input type="checkbox" name="enable_demo_carrier" value="1" <?php checked( ! empty( $values['enable_demo_carrier'] ) ); ?>> <?php echo esc_html__( 'Использовать тестовые тарифы до подключения реальных перевозчиков.', 'walls-delivery-calc' ); ?></label></td>
						</tr>
						<tr><th colspan="2"><h2><?php echo esc_html__( 'Почта России — международная доставка', 'walls-delivery-calc' ); ?></h2></th></tr>
						<?php $rp = is_array( $values['russian_post_worldwide_parcel'] ?? null ) ? $values['russian_post_worldwide_parcel'] : array(); ?>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Включить сервис', 'walls-delivery-calc' ); ?></th>
							<td><label><input type="checkbox" name="russian_post_worldwide_parcel[enabled]" value="1" <?php checked( ! empty( $rp['enabled'] ) ); ?>> <?php echo esc_html__( 'Показывать международную доставку Почтой России для стран кроме RU.', 'walls-delivery-calc' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="wdc_rp_api_endpoint"><?php echo esc_html__( 'API endpoint тарифа', 'walls-delivery-calc' ); ?></label></th>
							<td><input id="wdc_rp_api_endpoint" class="regular-text" type="url" name="russian_post_worldwide_parcel[api_endpoint]" value="<?php echo esc_attr( (string) ( $rp['api_endpoint'] ?? '' ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wdc_rp_country_endpoint"><?php echo esc_html__( 'API endpoint стран', 'walls-delivery-calc' ); ?></label></th>
							<td><input id="wdc_rp_country_endpoint" class="regular-text" type="url" name="russian_post_worldwide_parcel[country_endpoint]" value="<?php echo esc_attr( (string) ( $rp['country_endpoint'] ?? '' ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wdc_rp_api_token"><?php echo esc_html__( 'API token', 'walls-delivery-calc' ); ?></label></th>
							<td><input id="wdc_rp_api_token" type="password" name="russian_post_worldwide_parcel[api_token]" value="" placeholder="<?php echo esc_attr( '' !== (string) ( $rp['api_token'] ?? '' ) ? '********' : 'Токен не задан' ); ?>" autocomplete="new-password"><p class="description"><?php echo esc_html__( 'Оставьте пустым, чтобы сохранить текущий токен. Токен не пишется в debug logs.', 'walls-delivery-calc' ); ?></p></td>
						</tr>
						<tr>
							<th scope="row"><label for="wdc_rp_timeout"><?php echo esc_html__( 'Таймаут API Почты России (сек)', 'walls-delivery-calc' ); ?></label></th>
							<td><input id="wdc_rp_timeout" type="number" name="russian_post_worldwide_parcel[timeout]" value="<?php echo esc_attr( (string) ( $rp['timeout'] ?? 20 ) ); ?>" min="1" max="60" step="1"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wdc_rp_max_weight"><?php echo esc_html__( 'Максимальный вес сервиса (г)', 'walls-delivery-calc' ); ?></label></th>
							<td><input id="wdc_rp_max_weight" type="number" name="russian_post_worldwide_parcel[max_package_weight_g]" value="<?php echo esc_attr( (string) ( $rp['max_package_weight_g'] ?? 19990 ) ); ?>" min="1" max="100000" step="1"></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Fallback', 'walls-delivery-calc' ); ?></th>
							<td><label><input type="checkbox" name="russian_post_worldwide_parcel[fallback_enabled]" value="1" <?php checked( ! empty( $rp['fallback_enabled'] ) ); ?>> <?php echo esc_html__( 'Показывать нулевой тариф при ошибке API, отсутствии тарифа или overweight.', 'walls-delivery-calc' ); ?></label><br><input class="regular-text" type="text" name="russian_post_worldwide_parcel[fallback_text]" value="<?php echo esc_attr( (string) ( $rp['fallback_text'] ?? 'Стоимость доставки рассчитает менеджер' ) ); ?>"></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Debug Почты России', 'walls-delivery-calc' ); ?></th>
							<td><label><input type="checkbox" name="russian_post_worldwide_parcel[debug]" value="1" <?php checked( ! empty( $rp['debug'] ) ); ?>> <?php echo esc_html__( 'Логировать endpoint, параметры без секретов, ответы, cache hit/miss, формулу и fallback reason.', 'walls-delivery-calc' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Подставлять область в поиск населенного пункта на checkout', 'walls-delivery-calc' ); ?></th>
							<td><label><input type="checkbox" name="include_region_in_checkout_city_picker_query" value="1" <?php checked( ! empty( $values['include_region_in_checkout_city_picker_query'] ) ); ?>> <?php echo esc_html__( 'При открытии выбора населенного пункта использовать область вместе с городом.', 'walls-delivery-calc' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="wdc_checkout_location_search_limit"><?php echo esc_html__( 'Максимум вариантов поиска населенного пункта на checkout', 'walls-delivery-calc' ); ?></label></th>
							<td><input id="wdc_checkout_location_search_limit" type="number" name="checkout_location_search_limit" value="<?php echo esc_attr( (string) ( $values['checkout_location_search_limit'] ?? 100 ) ); ?>" min="10" max="500" step="1"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wdc_checkout_location_region_limit"><?php echo esc_html__( 'Максимум вариантов в одной области', 'walls-delivery-calc' ); ?></label></th>
							<td><input id="wdc_checkout_location_region_limit" type="number" name="checkout_location_region_limit" value="<?php echo esc_attr( (string) ( $values['checkout_location_region_limit'] ?? 10 ) ); ?>" min="3" max="50" step="1"></td>
						</tr>
						<tr><th colspan="2"><h2><?php echo esc_html__( 'ФИАС/ГАР', 'walls-delivery-calc' ); ?></h2></th></tr>
						<tr><th colspan="2"><p><?php echo esc_html__( 'Интеграция с ФИАС/ГАР подготовлена. Runtime-нормализация через реальный API временно отключена.', 'walls-delivery-calc' ); ?></p></th></tr>
						<tr>
							<th scope="row"><label for="wdc_fias_api_token"><?php echo esc_html__( 'API-токен ФИАС/ГАР', 'walls-delivery-calc' ); ?></label></th>
							<td>
								<input id="wdc_fias_api_token" type="password" name="fias_api_token" value="" placeholder="<?php echo esc_attr( $this->fias_token_placeholder() ); ?>" autocomplete="new-password">
								<p class="description"><?php echo esc_html__( 'Оставьте поле пустым, чтобы сохранить текущий токен без изменений.', 'walls-delivery-calc' ); ?></p>
								<p><label><input type="checkbox" name="clear_fias_token" value="1"> <?php echo esc_html__( 'Удалить сохраненный токен ФИАС/ГАР', 'walls-delivery-calc' ); ?></label></p>
								<p><strong><?php echo esc_html__( 'Статус:', 'walls-delivery-calc' ); ?></strong> <?php echo esc_html( $this->fias_token_status() ); ?></p>
							</td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Включить API ФИАС/ГАР', 'walls-delivery-calc' ); ?></th>
							<td><label><input type="checkbox" name="fias_api_enabled" value="1" <?php checked( ! empty( $values['fias_api_enabled'] ) ); ?>> <?php echo esc_html__( 'Хранить настройки API. Runtime-нормализация временно отключена.', 'walls-delivery-calc' ); ?></label></td>
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
						<tr><th colspan="2"><h2><?php echo esc_html__( 'Подсказки адреса DaData', 'walls-delivery-calc' ); ?></h2></th></tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Включить подсказки DaData', 'walls-delivery-calc' ); ?></th>
							<td><label><input type="checkbox" name="dadata_suggestions_enabled" value="1" <?php checked( ! empty( $values['dadata_suggestions_enabled'] ) ); ?>> <?php echo esc_html__( 'Показывать визуальные подсказки адреса в checkout.', 'walls-delivery-calc' ); ?></label></td>
						</tr>
						<tr>
							<th scope="row"><label for="wdc_dadata_suggestions_timeout"><?php echo esc_html__( 'Таймаут подсказок DaData', 'walls-delivery-calc' ); ?></label></th>
							<td><input id="wdc_dadata_suggestions_timeout" type="number" name="dadata_suggestions_timeout" value="<?php echo esc_attr( (string) ( $values['dadata_suggestions_timeout'] ?? 3 ) ); ?>" min="1" max="10" step="1"></td>
						</tr>
						<tr>
							<th scope="row"><label for="wdc_dadata_suggestions_count"><?php echo esc_html__( 'Количество подсказок DaData', 'walls-delivery-calc' ); ?></label></th>
							<td><input id="wdc_dadata_suggestions_count" type="number" name="dadata_suggestions_count" value="<?php echo esc_attr( (string) ( $values['dadata_suggestions_count'] ?? 10 ) ); ?>" min="3" max="20" step="1"></td>
						</tr>
						<tr>
							<th scope="row"><?php echo esc_html__( 'Токены DaData', 'walls-delivery-calc' ); ?></th>
							<td><?php $this->render_dadata_tokens_table(); ?></td>
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

		$checkout_location_limit    = isset( $data['checkout_location_search_limit'] ) ? $this->absint( wp_unslash( (string) $data['checkout_location_search_limit'] ) ) : 100;
		$checkout_region_limit      = isset( $data['checkout_location_region_limit'] ) ? $this->absint( wp_unslash( (string) $data['checkout_location_region_limit'] ) ) : 10;
		$fias_timeout               = isset( $data['fias_api_timeout'] ) ? $this->absint( wp_unslash( (string) $data['fias_api_timeout'] ) ) : 3;
		$fias_daily_limit           = isset( $data['fias_api_daily_limit'] ) ? $this->absint( wp_unslash( (string) $data['fias_api_daily_limit'] ) ) : 10000;
		$fias_minute_limit          = isset( $data['fias_api_minute_limit'] ) ? $this->absint( wp_unslash( (string) $data['fias_api_minute_limit'] ) ) : 100;
		$dadata_suggestions_timeout = isset( $data['dadata_suggestions_timeout'] ) ? $this->absint( wp_unslash( (string) $data['dadata_suggestions_timeout'] ) ) : 3;
		$dadata_suggestions_count   = isset( $data['dadata_suggestions_count'] ) ? $this->absint( wp_unslash( (string) $data['dadata_suggestions_count'] ) ) : 10;

		$current = $this->settings->all();

		return array(
			'enable_new_checkout_shipping' => ! empty( $data['enable_new_checkout_shipping'] ),
			'checkout_sort_mode'           => $sort_mode,
			'show_checkout_debug_panel'    => ! empty( $data['show_checkout_debug_panel'] ),
			'enable_demo_carrier'          => ! empty( $data['enable_demo_carrier'] ),
			'include_region_in_checkout_city_picker_query' => ! array_key_exists( 'include_region_in_checkout_city_picker_query', $data ) ? false : ! empty( $data['include_region_in_checkout_city_picker_query'] ),
			'checkout_location_search_limit' => max( 10, min( 500, $checkout_location_limit > 0 ? $checkout_location_limit : 100 ) ),
			'checkout_location_region_limit' => max( 3, min( 50, $checkout_region_limit > 0 ? $checkout_region_limit : 10 ) ),
			'fias_api_enabled'             => ! empty( $data['fias_api_enabled'] ),
			'fias_api_timeout'             => max( 1, min( 15, $fias_timeout > 0 ? $fias_timeout : 3 ) ),
			'fias_api_daily_limit'         => max( 1, min( 1000000, $fias_daily_limit > 0 ? $fias_daily_limit : 10000 ) ),
			'fias_api_minute_limit'        => max( 1, min( 10000, $fias_minute_limit > 0 ? $fias_minute_limit : 100 ) ),
			'dadata_suggestions_enabled'   => ! empty( $data['dadata_suggestions_enabled'] ),
			'dadata_suggestions_timeout'   => max( 1, min( 10, $dadata_suggestions_timeout > 0 ? $dadata_suggestions_timeout : 3 ) ),
			'dadata_suggestions_count'     => max( 3, min( 20, $dadata_suggestions_count > 0 ? $dadata_suggestions_count : 10 ) ),
			'russian_post_worldwide_parcel' => $this->sanitize_russian_post_settings( is_array( $data['russian_post_worldwide_parcel'] ?? null ) ? $data['russian_post_worldwide_parcel'] : array(), is_array( $current['russian_post_worldwide_parcel'] ?? null ) ? $current['russian_post_worldwide_parcel'] : array() ),
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array<string,mixed> $current
	 * @return array<string,mixed>
	 */
	private function sanitize_russian_post_settings( array $data, array $current ): array {
		$token = array_key_exists( 'api_token', $data ) ? trim( wp_unslash( (string) $data['api_token'] ) ) : '';

		return array_merge(
			$current,
			array(
				'enabled'              => ! empty( $data['enabled'] ),
				'api_endpoint'         => $this->sanitize_url( wp_unslash( (string) ( $data['api_endpoint'] ?? ( $current['api_endpoint'] ?? '' ) ) ) ),
				'country_endpoint'     => $this->sanitize_url( wp_unslash( (string) ( $data['country_endpoint'] ?? ( $current['country_endpoint'] ?? '' ) ) ) ),
				'api_token'            => '' !== $token ? sanitize_text_field( $token ) : (string) ( $current['api_token'] ?? '' ),
				'timeout'              => max( 1, min( 60, $this->absint( wp_unslash( (string) ( $data['timeout'] ?? ( $current['timeout'] ?? 20 ) ) ) ) ) ),
				'debug'                => ! empty( $data['debug'] ),
				'max_package_weight_g' => max( 1, min( 100000, $this->absint( wp_unslash( (string) ( $data['max_package_weight_g'] ?? ( $current['max_package_weight_g'] ?? 19990 ) ) ) ) ) ),
				'fallback_enabled'     => ! empty( $data['fallback_enabled'] ),
				'fallback_text'        => sanitize_text_field( wp_unslash( (string) ( $data['fallback_text'] ?? ( $current['fallback_text'] ?? 'Стоимость доставки рассчитает менеджер' ) ) ) ),
			)
		);
	}

	private function sanitize_url( string $value ): string {
		return function_exists( 'esc_url_raw' ) ? esc_url_raw( $value ) : filter_var( trim( $value ), FILTER_SANITIZE_URL );
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
		$token_message = $this->handle_fias_token( $_POST );
		$token_message .= $this->handle_dadata_tokens( $_POST );

		return __( 'Настройки сохранены.', 'walls-delivery-calc' ) . $token_message;
	}

	private function render_dadata_tokens_table(): void {
		if ( ! $this->token_pool instanceof DaDataTokenPool ) {
			return;
		}

		$tokens = $this->token_pool->tokens();
		echo '<p class="description">' . esc_html__( 'Исходные токены не показываются после сохранения. Пустое поле токена сохраняет текущий токен без изменений.', 'walls-delivery-calc' ) . '</p>';
		echo '<p class="wdc-dadata-no-tokens" ' . ( array() === $tokens ? '' : 'style="display:none"' ) . '>' . esc_html__( 'Токены не добавлены. Нажмите «Добавить токен».', 'walls-delivery-calc' ) . '</p>';
		echo '<table class="widefat striped wdc-dadata-token-table"><thead><tr>';
		foreach ( array( 'Включен', 'Название', 'Токен', 'Суточный лимит запросов', 'Использовано сегодня', 'Осталось сегодня', 'Последняя попытка', 'Последний статус', 'Действия' ) as $heading ) {
			echo '<th>' . esc_html( $heading ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $tokens as $index => $token ) {
			$this->render_dadata_token_row( $token, (int) $index );
		}
		echo '</tbody></table>';
		echo '<p><button type="button" class="button" id="wdc-add-dadata-token">' . esc_html__( 'Добавить токен', 'walls-delivery-calc' ) . '</button></p>';
		?>
		<script>
		(function(){
			var button = document.getElementById('wdc-add-dadata-token');
			if (!button) { return; }
			button.addEventListener('click', function(){
				var tbody = document.querySelector('.wdc-dadata-token-table tbody');
				var empty = document.querySelector('.wdc-dadata-no-tokens');
				if (!tbody) { return; }
				var index = tbody.querySelectorAll('tr').length;
				var row = document.createElement('tr');
				row.innerHTML =
					'<td><input type="hidden" name="dadata_suggestions_tokens[id][' + index + ']" value=""><input type="checkbox" name="dadata_suggestions_tokens[enabled][' + index + ']" value="1" checked></td>' +
					'<td><input type="text" name="dadata_suggestions_tokens[label][' + index + ']" value="" placeholder="Основной"></td>' +
					'<td><input type="password" name="dadata_suggestions_tokens[token][' + index + ']" value="" autocomplete="new-password" placeholder="Новый токен"></td>' +
					'<td><input type="number" name="dadata_suggestions_tokens[daily_limit][' + index + ']" value="10000" min="1" max="1000000" step="1"></td>' +
					'<td>0</td><td>10000</td><td>-</td><td>-</td>' +
					'<td><label><input type="checkbox" name="dadata_suggestions_tokens[delete][' + index + ']" value="1"> Удалить</label></td>';
				tbody.appendChild(row);
				if (empty) { empty.style.display = 'none'; }
			});
		}());
		</script>
		<?php
	}

	/**
	 * @param array<string,mixed> $token
	 */
	private function render_dadata_token_row( array $token, int $index ): void {
		if ( ! $this->token_pool instanceof DaDataTokenPool ) {
			return;
		}

		$id = (string) ( $token['id'] ?? '' );
		$usage = $this->token_pool->usage_today( $id );
		$remaining = $this->token_pool->remaining_today( $token );
		$last_request = $this->token_pool->last_request_today( $id );
		$row_class = $remaining <= 0 ? ' class="wdc-dadata-token-exhausted"' : '';
		$index_attr = esc_attr( (string) $index );
		echo '<tr' . $row_class . '>';
		echo '<td><input type="hidden" name="dadata_suggestions_tokens[id][' . $index_attr . ']" value="' . esc_attr( $id ) . '"><input type="checkbox" name="dadata_suggestions_tokens[enabled][' . $index_attr . ']" value="1" ' . checked( ! empty( $token['enabled'] ), true, false ) . '></td>';
		echo '<td><input type="text" name="dadata_suggestions_tokens[label][' . $index_attr . ']" value="' . esc_attr( (string) ( $token['label'] ?? '' ) ) . '"></td>';
		echo '<td><code>' . esc_html( (string) ( $token['masked_token'] ?? '********' ) ) . '</code><br><input type="password" name="dadata_suggestions_tokens[token][' . $index_attr . ']" value="" autocomplete="new-password" placeholder="' . esc_attr( __( 'Заменить токен', 'walls-delivery-calc' ) ) . '"></td>';
		echo '<td><input type="number" name="dadata_suggestions_tokens[daily_limit][' . $index_attr . ']" value="' . esc_attr( (string) ( $token['daily_limit'] ?? 10000 ) ) . '" min="1" max="1000000" step="1"></td>';
		echo '<td>' . esc_html( (string) $usage ) . '</td>';
		echo '<td>' . esc_html( (string) $remaining ) . ( $remaining <= 0 ? '<br><strong>' . esc_html__( 'Лимит на сегодня исчерпан', 'walls-delivery-calc' ) . '</strong>' : '' ) . '</td>';
		echo '<td>' . esc_html( (string) ( $last_request['stage'] ?? '-' ) ) . '<br><small>' . esc_html( (string) ( $last_request['time'] ?? '' ) ) . '</small></td>';
		echo '<td>' . esc_html( (string) ( $last_request['status_code'] ?? '-' ) ) . '<br><small>' . esc_html( (string) ( $last_request['error_code'] ?? '' ) ) . '</small></td>';
		echo '<td><label><input type="checkbox" name="dadata_suggestions_tokens[delete][' . $index_attr . ']" value="1"> ' . esc_html__( 'Удалить', 'walls-delivery-calc' ) . '</label></td>';
		echo '</tr>';
	}

	private function fias_token_placeholder(): string {
		return $this->fias_credentials instanceof FiasCredentials && $this->fias_credentials->has_token() ? '********' : 'Токен не задан';
	}

	private function fias_token_status(): string {
		return $this->fias_credentials instanceof FiasCredentials && $this->fias_credentials->has_token() ? 'Токен сохранен' : 'Токен не задан';
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function handle_fias_token( array $data ): string {
		if ( ! $this->fias_credentials instanceof FiasCredentials ) {
			return '';
		}

		if ( ! empty( $data['clear_fias_token'] ) ) {
			$this->fias_credentials->clear_token();
			return ' ' . __( 'Токен ФИАС/ГАР удален.', 'walls-delivery-calc' );
		}

		if ( ! array_key_exists( 'fias_api_token', $data ) ) {
			return '';
		}

		$token = wp_unslash( (string) $data['fias_api_token'] );
		if ( '' === trim( $token ) ) {
			return '';
		}

		if ( ! $this->fias_credentials->save_token( $token ) ) {
			return ' ' . __( 'Токен ФИАС/ГАР не сохранен: настройте APP_ENCRYPTION_KEY.', 'walls-delivery-calc' );
		}

		return ' ' . __( 'Токен ФИАС/ГАР сохранен.', 'walls-delivery-calc' );
	}

	/**
	 * @param array<string,mixed> $data
	 */
	private function handle_dadata_tokens( array $data ): string {
		if ( ! $this->token_pool instanceof DaDataTokenPool || ! isset( $data['dadata_suggestions_tokens'] ) || ! is_array( $data['dadata_suggestions_tokens'] ) ) {
			return '';
		}

		if ( ! $this->suggestion_settings instanceof AddressSuggestionSettings || ! $this->suggestion_settings->encryption_ready() ) {
			return ' ' . __( 'Токены DaData не сохранены: настройте APP_ENCRYPTION_KEY.', 'walls-delivery-calc' );
		}

		$this->token_pool->save_tokens_from_admin( $data['dadata_suggestions_tokens'] );
		return ' ' . __( 'Токены DaData сохранены.', 'walls-delivery-calc' );
	}
}
