<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Api;

use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryConnectionDiagnosticService {
	public function __construct(
		private YandexDeliverySettings $settings,
		private YandexDeliveryApiClient $api
	) {
	}

	/** @return array{success:bool,status:string,message:string,details:array<string,mixed>} */
	public function checkPickupPoint(): array {
		$credentials = $this->settings->credentials();
		if ( ! $credentials->is_complete() ) {
			return $this->result( false, 'credentials_missing', 'Данные для активной среды не заполнены.', $this->settings->diagnostic_context() );
		}

		$payload = array(
			'source' => array(
				'platform_station_id' => $credentials->platform_station_id,
			),
		);

		try {
			$response = $this->api->pickupPointsList( $payload );
		} catch ( YandexDeliveryApiException $exception ) {
			$details = $this->settings->sanitize_for_diagnostics( $exception->details() );
			$http_code = (int) ( is_array( $details ) ? ( $details['http_code'] ?? 0 ) : 0 );
			$code = (string) ( is_array( $details ) ? ( $details['yandex_error_code'] ?? '' ) : '' );
			$message = $exception->getMessage();
			if ( in_array( $http_code, array( 401, 403 ), true ) ) {
				return $this->result( false, 'auth_failed', 'Яндекс.Доставка отклонила авторизацию: HTTP ' . $http_code . '.', is_array( $details ) ? $details : array() );
			}
			if ( $http_code >= 500 ) {
				return $this->result( false, 'server_error', 'Яндекс.Доставка временно недоступна: HTTP ' . $http_code . '.', is_array( $details ) ? $details : array() );
			}
			if ( is_array( $details ) && 'malformed_json' === (string) ( $details['error_code'] ?? '' ) ) {
				return $this->result( false, 'malformed_json', $message, $details );
			}
			if ( is_array( $details ) && 'empty_json' === (string) ( $details['error_code'] ?? '' ) ) {
				return $this->result( false, 'empty_json', $message, $details );
			}
			if ( '' !== $code ) {
				return $this->result( false, 'api_error', 'Яндекс.Доставка вернула ошибку: ' . $message, is_array( $details ) ? $details : array() );
			}

			return $this->result( false, 'transport_or_json_error', $message, is_array( $details ) ? $details : array() );
		}

		$body = is_array( $response['body'] ?? null ) ? $response['body'] : array();
		$point = $this->findPoint( $body, $credentials->platform_station_id );
		if ( array() === $point ) {
			return $this->result( false, 'point_not_found', 'Точка с указанным platform_station_id не найдена.', $this->settings->sanitize_for_diagnostics( array( 'response' => $body ) ) );
		}

		$type = (string) ( $point['type'] ?? '' );
		if ( 'pickup_point' !== $type ) {
			return $this->result( false, 'unsupported_point_type', 'Неподдерживаемый тип точки: ' . ( '' !== $type ? $type : 'не указан' ) . '.', $this->settings->sanitize_for_diagnostics( array( 'point' => $point ) ) );
		}

		if ( true !== (bool) ( $point['available_for_dropoff'] ?? false ) ) {
			return $this->result( false, 'dropoff_unavailable', 'Точка найдена, но available_for_dropoff=false.', $this->settings->sanitize_for_diagnostics( array( 'point' => $point ) ) );
		}

		return $this->result( true, 'success', 'Подключение проверено: точка найдена и доступна для drop-off.', $this->settings->sanitize_for_diagnostics( array( 'point' => $point ) ) );
	}

	/** @param array<string,mixed> $body */
	private function findPoint( array $body, string $station_id ): array {
		foreach ( $this->pointRows( $body ) as $point ) {
			if ( ! is_array( $point ) ) {
				continue;
			}
			$id = (string) ( $point['platform_station_id'] ?? $point['id'] ?? $point['station_id'] ?? '' );
			if ( $id === $station_id ) {
				return $point;
			}
		}

		return array();
	}

	/** @param array<string,mixed> $body @return array<int,mixed> */
	private function pointRows( array $body ): array {
		foreach ( array( 'pickup_points', 'points', 'items', 'results' ) as $key ) {
			if ( is_array( $body[ $key ] ?? null ) ) {
				return array_values( $body[ $key ] );
			}
		}
		if ( is_array( $body[0] ?? null ) ) {
			return array_values( $body );
		}

		return array();
	}

	/** @param array<string,mixed> $details @return array{success:bool,status:string,message:string,details:array<string,mixed>} */
	private function result( bool $success, string $status, string $message, array $details ): array {
		return array(
			'success' => $success,
			'status' => $status,
			'message' => $this->settings->redact( $message ),
			'details' => $this->settings->sanitize_for_diagnostics( $details ),
		);
	}
}

