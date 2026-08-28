<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Api;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliveryCredentials;
use WallsShop\WDC\Carriers\OzonDelivery\OzonDeliverySettings;
defined( 'ABSPATH' ) || exit;
final class OzonDeliveryConnectionDiagnosticService {
	public function __construct( private OzonDeliveryCredentials $credentials, private OzonDeliveryAccessTokenService $tokens, private OzonDeliverySettings $settings ) {}
	/** @return array<string,mixed> */ public function run(): array {
		$result = array( 'success' => false, 'checked_at' => gmdate( 'c' ), 'credentials_present' => $this->credentials->is_complete(), 'oauth_token_received' => false, 'application_api_checked' => false, 'http_status' => 0, 'error_code' => '', 'message' => 'Не заполнены Client ID или Client Secret.' );
		if ( ! $result['credentials_present'] ) { $this->settings->save_last_diagnostic( $result ); return $result; }
		try { $this->tokens->obtain(); $result['success'] = true; $result['oauth_token_received'] = true; $result['message'] = 'Авторизация Ozon Delivery выполнена успешно. Прикладные методы API на этом этапе не проверяются.'; } catch ( OzonDeliveryApiException $e ) { $result['http_status'] = $e->http_status; $result['error_code'] = $e->safe_code; $result['message'] = $e->getMessage(); }
		$this->settings->save_last_diagnostic( $result ); return $result;
	}
}
