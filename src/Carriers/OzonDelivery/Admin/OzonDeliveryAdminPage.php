<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Admin;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryConnectionDiagnosticService;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliveryCredentials;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
defined( 'ABSPATH' ) || exit;
final class OzonDeliveryAdminPage {
	public const ACTIONS = array( 'save_ozon_delivery_settings', 'check_ozon_delivery_connection' );
	public function __construct( private OzonDeliveryCredentials $credentials, private OzonDeliverySettings $settings, private OzonDeliveryConnectionDiagnosticService $diagnostics ) {}
	public static function supports_action( string $action ): bool { return in_array( $action, self::ACTIONS, true ); }
	/** @param array<string,mixed> $post */ public function handle_action( string $action, array $post ): void { if ( 'save_ozon_delivery_settings' === $action ) { $this->credentials->save_from_admin( $post ); } elseif ( 'check_ozon_delivery_connection' === $action ) { $this->diagnostics->run(); } }
	public function render(): void { $diagnostic = $this->settings->last_diagnostic(); ?>
		<h2><?php echo esc_html( OzonDeliverySettings::TITLE ); ?></h2>
		<h3><?php echo esc_html__( 'Подключение к API', 'walls-delivery-calc' ); ?></h3>
		<form method="post" style="max-width:760px;"><?php wp_nonce_field( 'wdc_delivery_services' ); ?><input type="hidden" name="wdc_delivery_services_action" value="save_ozon_delivery_settings"><table class="form-table" role="presentation"><tr><th><label for="ozon_delivery_client_id">Client ID</label></th><td><input class="regular-text" id="ozon_delivery_client_id" name="ozon_delivery_client_id" value="<?php echo esc_attr( $this->credentials->client_id() ); ?>"></td></tr><tr><th><label for="ozon_delivery_client_secret">Client Secret</label></th><td><input class="regular-text" type="password" id="ozon_delivery_client_secret" name="ozon_delivery_client_secret" value=""><p class="description"><?php echo esc_html( $this->credentials->has_client_secret() ? 'Client Secret сохранён' : 'Client Secret не сохранён' ); ?></p><label><input type="checkbox" name="ozon_delivery_clear_client_secret" value="1"> <?php echo esc_html__( 'Очистить Client Secret', 'walls-delivery-calc' ); ?></label><?php if ( ! $this->credentials->encryption_ready() ) : ?><p class="description">APP_ENCRYPTION_KEY не задан: новый Client Secret не будет сохранён.</p><?php endif; ?></td></tr></table><?php submit_button( __( 'Сохранить настройки', 'walls-delivery-calc' ) ); ?></form>
		<h3><?php echo esc_html__( 'Проверка подключения', 'walls-delivery-calc' ); ?></h3><form method="post"><?php wp_nonce_field( 'wdc_delivery_services' ); ?><input type="hidden" name="wdc_delivery_services_action" value="check_ozon_delivery_connection"><?php submit_button( __( 'Проверить подключение', 'walls-delivery-calc' ), 'secondary' ); ?></form>
		<?php if ( array() !== $diagnostic ) : ?><table class="widefat striped" style="max-width:760px;"><tbody><tr><th>Статус</th><td><?php echo esc_html( ! empty( $diagnostic['success'] ) ? 'Успешно' : 'Ошибка' ); ?></td></tr><tr><th>OAuth token</th><td><?php echo esc_html( ! empty( $diagnostic['oauth_token_received'] ) ? 'Получен' : 'Не получен' ); ?></td></tr><tr><th>Проверка прикладного API</th><td>Не выполнялась</td></tr><tr><th>Сообщение</th><td><?php echo esc_html( (string) ( $diagnostic['message'] ?? '' ) ); ?></td></tr></tbody></table><?php endif; ?>
		<h3><?php echo esc_html__( 'Подтверждённая схема передачи', 'walls-delivery-calc' ); ?></h3><p><strong><?php echo esc_html__( 'Самостоятельная сдача.', 'walls-delivery-calc' ); ?></strong></p><p><?php echo esc_html__( 'Забор курьером со склада отправителя не используется.', 'walls-delivery-calc' ); ?></p>
	<?php }
}
