<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\JetLogistic\Api;

use Throwable;
use WallsShop\WDC\Carriers\JetLogistic\Geography\JetLogisticGeographyRepository;
use WallsShop\WDC\Carriers\JetLogistic\JetLogisticCredentials;
use WallsShop\WDC\Carriers\JetLogistic\JetLogisticSettings;
use WallsShop\WDC\Carriers\JetLogistic\Quote\JetLogisticQuoteResponseParser;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusEventResolver;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusMapper;
use WallsShop\WDC\Carriers\JetLogistic\Status\JetLogisticStatusMappingRepository;

defined( 'ABSPATH' ) || exit;

final class JetLogisticApiDiagnosticService {
	public function __construct(
		private JetLogisticCredentials $credentials,
		private JetLogisticSettings $settings,
		private JetLogisticApiClient $api,
		private JetLogisticGeographyRepository $geography,
		private JetLogisticStatusMappingRepository $status_mappings,
		private ?JetLogisticQuoteResponseParser $quote_parser = null,
		private ?JetLogisticStatusEventResolver $status_events = null
	) {
		$this->quote_parser ??= new JetLogisticQuoteResponseParser();
		$this->status_events ??= new JetLogisticStatusEventResolver( new JetLogisticStatusMapper( $this->status_mappings ) );
	}

	/** @return array<string,mixed> */
	public function check_connection(): array {
		$base = $this->base_result( JetLogisticApiClient::METHOD_CALC_TRANSPORT );
		if ( ! $this->credentials->has_access_token() || '' === trim( $this->credentials->access_token() ) ) {
			return array_merge( $base, $this->failure( 'jet_token_missing', 'Невозможно проверить подключение: токен API Jet Logistic не задан.' ) );
		}
		$origin = $this->origin();
		if ( array() === $origin ) {
			return array_merge( $base, $this->failure( 'jet_origin_missing', 'Невозможно проверить подключение: выберите город отправления Jet Logistic.' ) );
		}
		$destination = $this->geography->first_active_diagnostic_destination( (string) ( $origin['source_identity'] ?? '' ) );
		if ( array() === $destination ) {
			return array_merge( $base, $this->failure( 'jet_geography_missing', 'Невозможно проверить подключение: сначала импортируйте и сопоставьте географию Jet Logistic.' ) );
		}

		try {
			$response = $this->api->calc_transport( $this->diagnostic_payload( $origin, $destination ) );
			$calculation = $this->quote_parser->parse( $response );
			return array_merge(
				$base,
				array(
					'success' => true,
					'status' => 'success',
					'code' => 'jet_connection_ok',
					'message' => 'Подключение Jet Logistic проверено: сервер принял токен и вернул ответ калькулятора.',
					'api_response' => $this->response_shape( $response ),
					'http_status' => 200,
					'details' => array(
						'currency' => 'RUB',
						'currency_source' => 'profile' === $calculation->currency_source ? 'профиль Jet' : 'ответ API',
						'city_to' => $calculation->city_to,
						'city_terminal_to' => $calculation->city_terminal_to,
					),
				)
			);
		} catch ( Throwable $exception ) {
			return array_merge( $base, $this->exception_result( $exception, 'Не удалось проверить подключение Jet Logistic.' ) );
		}
	}

	/** @return array<string,mixed> */
	public function check_tracking( string $tracking_number ): array {
		$base = $this->base_result( JetLogisticApiClient::METHOD_STATUS );
		$tracking_number = $this->sanitize_tracking_number( $tracking_number );
		if ( '' === $tracking_number ) {
			return array_merge( $base, $this->failure( 'jet_tracking_missing', 'Укажите номер груза Jet Logistic.' ) );
		}
		if ( ! $this->credentials->has_access_token() || '' === trim( $this->credentials->access_token() ) ) {
			return array_merge( $base, $this->failure( 'jet_token_missing', 'Невозможно проверить номер груза: токен API Jet Logistic не задан.' ) );
		}

		try {
			$response = $this->api->status( $tracking_number );
			$resolved = $this->status_events->resolve( is_array( $response['logs'] ?? null ) ? $response['logs'] : array() );
			$events = $resolved['events'];
			return array_merge(
				$base,
				array(
					'success' => true,
					'status' => 'success',
					'code' => 'jet_tracking_ok',
					'message' => 'Статус номера груза Jet Logistic проверен.',
					'api_response' => $this->response_shape( $response ),
					'http_status' => 200,
					'tracking_number' => $tracking_number,
					'events' => $events,
					'details' => $this->event_details( $events, $resolved['current_event'] ),
				)
			);
		} catch ( Throwable $exception ) {
			return array_merge( $base, $this->exception_result( $exception, 'Не удалось проверить номер груза Jet Logistic.' ) );
		}
	}

	/** @return array<string,mixed> */
	private function origin(): array {
		$identity = $this->settings->origin_source_identity();
		return '' !== $identity ? $this->geography->origin_by_source_identity( $identity ) : array();
	}

	/** @param array<string,mixed> $origin @param array<string,mixed> $destination @return array<string,mixed> */
	private function diagnostic_payload( array $origin, array $destination ): array {
		return array(
			'access_token' => $this->credentials->access_token(),
			'cityfrom' => (string) ( $origin['source_city'] ?? '' ),
			'cityto' => (string) ( $destination['source_city'] ?? '' ),
			'ves' => 1,
			'obm3' => 0.001,
			'dlina' => 0.1,
			'mest' => 1,
			'cost' => 1000,
			'naimenovanie' => 'ТЕКСТИЛЬ',
			'dops' => array( 'D_HARDPACK' => 0, 'D_EP' => 0, 'D_PB' => 0, 'D_VPP' => 0, 'D_SP' => 0, 'D_SDOC' => 0, 'D_EK' => 0 ),
		);
	}

	/** @return array<string,mixed> */
	private function base_result( string $endpoint ): array {
		return array(
			'checked_at' => current_time( 'mysql' ),
			'token_state' => $this->credentials->has_access_token() ? 'Токен задан' : 'Токен не задан',
			'endpoint' => $endpoint,
			'method' => 'POST',
			'http_status' => '',
			'api_response' => '',
		);
	}

	/** @return array<string,mixed> */
	private function failure( string $code, string $message ): array {
		return array( 'success' => false, 'status' => 'error', 'code' => $code, 'message' => $message );
	}

	/** @return array<string,mixed> */
	private function exception_result( Throwable $exception, string $message ): array {
		$context = $exception instanceof JetLogisticApiException ? $exception->context() : array();
		$code = $exception instanceof JetLogisticApiException ? $exception->error_code() : 'jet_invalid_response';
		return array(
			'success' => false,
			'status' => 'error',
			'code' => $this->safe_code( $code ),
			'message' => $message . ' Код: ' . $this->safe_code( $code ) . '.',
			'http_status' => $this->safe_http_status( $context['http_status'] ?? $context['status'] ?? '' ),
			'api_response' => $this->api_response_classification( $code ),
		);
	}

	private function sanitize_tracking_number( string $tracking_number ): string {
		return mb_substr( sanitize_text_field( wp_unslash( $tracking_number ) ), 0, 64, 'UTF-8' );
	}

	private function response_shape( array $response ): string {
		$keys = array_slice( array_map( 'strval', array_keys( $response ) ), 0, 10 );
		return array() === $keys ? 'Пустой объект ответа.' : 'Ключи ответа: ' . implode( ', ', $keys ) . '.';
	}

	/** @param array<int,array<string,string>> $events @param array<string,string> $current_event @return array<string,string> */
	private function event_details( array $events, array $current_event = array() ): array {
		$details = array();
		foreach ( array_slice( $events, 0, 5 ) as $index => $event ) {
			$rule = '' !== (string) ( $event['matched_rule'] ?? '' ) ? (string) $event['matched_rule'] : 'нет сопоставления';
			$status = '' !== (string) ( $event['universal_status'] ?? '' ) ? (string) $event['universal_status'] : 'не меняется';
			$details[ 'event_' . ( $index + 1 ) ] = trim( (string) ( $event['date'] ?? '' ) . ' ' . (string) ( $event['message'] ?? '' ) ) . ' | правило: ' . $rule . ' | статус: ' . $status;
		}
		if ( array() !== $current_event ) {
			$details['current_status'] = (string) ( $current_event['message'] ?? '' );
			$details['current_status_date'] = (string) ( $current_event['date'] ?? '' );
			$details['current_status_mapping'] = (string) ( $current_event['matched_rule'] ?? '' );
			$details['current_universal_status'] = (string) ( $current_event['universal_status'] ?? '' );
		}

		return $details;
	}

	private function api_response_classification( string $code ): string {
		return match ( $code ) {
			'jet_http_401' => 'HTTP 401: сервер отклонил авторизацию.',
			'jet_http_403' => 'HTTP 403: сервер запретил доступ.',
			'jet_http_timeout' => 'Тайм-аут HTTP-запроса.',
			'jet_http_network_error' => 'Сетевая ошибка HTTP-запроса.',
			'jet_invalid_json' => 'Ответ не является корректным JSON.',
			'jet_api_error' => 'Jet Logistic вернул ошибку API.',
			default => 'Ответ Jet Logistic не прошёл проверку.',
		};
	}

	private function safe_code( string $code ): string {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $code ) ) ?: 'jet_invalid_response';
	}

	private function safe_http_status( mixed $status ): int|string {
		return is_numeric( $status ) ? (int) $status : '';
	}
}
