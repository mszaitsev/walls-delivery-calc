<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Api;

use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliveryCredentials;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliveryEndpoints;
use WallsShop\WDC\Carriers\YandexDelivery\YandexDeliverySettings;

defined( 'ABSPATH' ) || exit;

final class YandexDeliveryApiClient {
	public function __construct(
		private YandexDeliverySettings $settings,
		private YandexDeliveryHttpClientInterface $http
	) {
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function pickupPointsList( array $payload = array() ): array {
		return $this->authorizedJsonRequest( 'POST', YandexDeliveryEndpoints::PICKUP_POINTS_LIST_PATH, $payload );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array{http_code:int,file:string,size_bytes:int}
	 */
	public function pickupPointsListDownloadToFile( array $payload, string $target_file ): array {
		$credentials = $this->settings->credentials();
		if ( ! $credentials->is_complete() ) {
			throw new YandexDeliveryApiException(
				'Данные для входа Яндекс.Доставки не заполнены.',
				array_merge( $this->settings->diagnostic_context(), array( 'error_code' => 'credentials_missing' ) )
			);
		}
		if ( '' === trim( $target_file ) ) {
			throw new YandexDeliveryApiException( 'Не указан файл для сохранения JSON Яндекс.Доставки.', array( 'error_code' => 'target_file_missing' ) );
		}

		$response = $this->rawRequest(
			'POST',
			YandexDeliveryEndpoints::PICKUP_POINTS_LIST_PATH,
			$payload,
			$credentials,
			array(
				'stream' => true,
				'filename' => $target_file,
			)
		);
		$size = is_file( $target_file ) ? (int) filesize( $target_file ) : 0;
		if ( $response->status_code < 200 || $response->status_code >= 300 ) {
			if ( is_file( $target_file ) ) {
				@unlink( $target_file );
			}
			$data = $response->json();
			$message = is_array( $data ) && array() !== $data ? $this->extractErrorMessage( $data, $response->status_code ) : $response->body;
			throw new YandexDeliveryApiException(
				$this->safeMessage( $message, $response->status_code ),
				array(
					'http_code' => $response->status_code,
					'endpoint' => YandexDeliveryEndpoints::PICKUP_POINTS_LIST_PATH,
					'request' => $this->settings->sanitize_for_diagnostics( $payload ),
					'file' => $target_file,
					'size_bytes' => $size,
				)
			);
		}
		if ( $size <= 0 ) {
			throw new YandexDeliveryApiException(
				'Яндекс.Доставка вернула пустой JSON файл.',
				array(
					'http_code' => $response->status_code,
					'endpoint' => YandexDeliveryEndpoints::PICKUP_POINTS_LIST_PATH,
					'error_code' => 'empty_json_file',
					'file' => $target_file,
					'size_bytes' => $size,
				)
			);
		}

		return array(
			'http_code' => $response->status_code,
			'file' => $target_file,
			'size_bytes' => $size,
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function pickupPointsListRawJson( array $payload = array() ): string {
		$credentials = $this->settings->credentials();
		if ( ! $credentials->is_complete() ) {
			throw new YandexDeliveryApiException(
				'Данные для входа Яндекс.Доставки не заполнены.',
				array_merge( $this->settings->diagnostic_context(), array( 'error_code' => 'credentials_missing' ) )
			);
		}
		$response = $this->rawRequest( 'POST', YandexDeliveryEndpoints::PICKUP_POINTS_LIST_PATH, $payload, $credentials );
		if ( $response->status_code < 200 || $response->status_code >= 300 ) {
			$data = $response->json();
			$message = is_array( $data ) && array() !== $data ? $this->extractErrorMessage( $data, $response->status_code ) : $response->body;
			throw new YandexDeliveryApiException( $this->safeMessage( $message, $response->status_code ), array( 'http_code' => $response->status_code, 'endpoint' => YandexDeliveryEndpoints::PICKUP_POINTS_LIST_PATH, 'request' => $this->settings->sanitize_for_diagnostics( $payload ) ) );
		}
		$body = trim( $response->body );
		if ( '' === $body ) {
			throw new YandexDeliveryApiException( 'Яндекс.Доставка вернула пустой JSON.', array( 'endpoint' => YandexDeliveryEndpoints::PICKUP_POINTS_LIST_PATH, 'error_code' => 'empty_json' ) );
		}
		$first = $body[0];
		if ( '{' !== $first && '[' !== $first ) {
			throw new YandexDeliveryApiException( 'Яндекс.Доставка вернула ответ, не похожий на JSON.', array( 'endpoint' => YandexDeliveryEndpoints::PICKUP_POINTS_LIST_PATH, 'error_code' => 'not_json_like' ) );
		}
		$last = substr( $body, -1 );
		if ( ( '{' === $first && '}' !== $last ) || ( '[' === $first && ']' !== $last ) ) {
			throw new YandexDeliveryApiException( 'Яндекс.Доставка вернула незавершенный JSON.', array( 'endpoint' => YandexDeliveryEndpoints::PICKUP_POINTS_LIST_PATH, 'error_code' => 'truncated_json' ) );
		}

		return $response->body;
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function locationDetect( array $payload ): array {
		return $this->authorizedJsonRequest( 'POST', YandexDeliveryEndpoints::LOCATION_DETECT_PATH, $payload );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	private function authorizedJsonRequest( string $method, string $path, array $payload = array() ): array {
		$credentials = $this->settings->credentials();
		if ( ! $credentials->is_complete() ) {
			throw new YandexDeliveryApiException(
				'Данные для входа Яндекс.Доставки не заполнены.',
				array_merge( $this->settings->diagnostic_context(), array( 'error_code' => 'credentials_missing' ) )
			);
		}

		$response = $this->rawRequest( $method, $path, $payload, $credentials );
		$data = $response->json();
		if ( '' !== trim( $response->body ) && array() === $data ) {
			throw new YandexDeliveryApiException(
				'Яндекс.Доставка вернула некорректный JSON.',
				array(
					'http_code' => $response->status_code,
					'endpoint' => $path,
					'request' => $this->settings->sanitize_for_diagnostics( $payload ),
					'response' => array( '_raw' => $this->safeMessage( $response->body, $response->status_code ) ),
					'error_code' => 'malformed_json',
				)
			);
		}
		if ( array() === $data ) {
			throw new YandexDeliveryApiException(
				'Яндекс.Доставка вернула пустой JSON.',
				array(
					'http_code' => $response->status_code,
					'endpoint' => $path,
					'request' => $this->settings->sanitize_for_diagnostics( $payload ),
					'error_code' => 'empty_json',
				)
			);
		}
		if ( $response->status_code < 200 || $response->status_code >= 300 ) {
			$message = $this->extractErrorMessage( $data, $response->status_code );
			throw new YandexDeliveryApiException(
				$this->safeMessage( $message, $response->status_code ),
				array(
					'http_code' => $response->status_code,
					'endpoint' => $path,
					'request' => $this->settings->sanitize_for_diagnostics( $payload ),
					'response' => $this->settings->sanitize_for_diagnostics( $data ),
					'yandex_error_code' => $this->extractErrorCode( $data ),
					'yandex_error_message' => $this->safeMessage( $message, $response->status_code ),
				)
			);
		}

		return array(
			'http_code' => $response->status_code,
			'body' => $data,
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $extra_args
	 */
	private function rawRequest( string $method, string $path, array $payload, YandexDeliveryCredentials $credentials, array $extra_args = array() ): YandexDeliveryApiResponse {
		$args = array_merge(
			array(
				'timeout' => $this->settings->request_timeout(),
				'headers' => array(
					'Authorization' => 'Bearer ' . $credentials->bearer_token,
					'Accept' => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body' => $this->encodePayloadBody( $payload ),
			),
			$extra_args
		);

		try {
			return $this->http->request( $method, YandexDeliveryEndpoints::url( $this->settings->environment(), $path ), $args );
		} catch ( YandexDeliveryApiException $exception ) {
			throw new YandexDeliveryApiException(
				$this->safeMessage( $this->settings->redact( $exception->getMessage() ), 0 ),
				array_merge(
					$exception->details(),
					array(
						'endpoint' => $path,
						'request' => $this->settings->sanitize_for_diagnostics( $payload ),
					)
				),
				0,
				$exception
			);
		}
	}

	/** @param array<string,mixed> $payload */
	private function encodePayloadBody( array $payload ): string {
		if ( array() === $payload ) {
			return '{}';
		}

		return ( function_exists( 'wp_json_encode' ) ? wp_json_encode( $payload ) : json_encode( $payload ) ) ?: '{}';
	}

	/** @param array<string,mixed> $data */
	private function extractErrorMessage( array $data, int $status_code ): string {
		$error = is_array( $data['error'] ?? null ) ? $data['error'] : array();
		$message = (string) ( $data['message'] ?? $data['error_message'] ?? $data['error_description'] ?? $error['message'] ?? $error['text'] ?? '' );

		return '' !== trim( $message ) ? $message : 'HTTP ' . $status_code;
	}

	/** @param array<string,mixed> $data */
	private function extractErrorCode( array $data ): string {
		$error = is_array( $data['error'] ?? null ) ? $data['error'] : array();
		foreach ( array( $data['code'] ?? null, $data['error_code'] ?? null, $error['code'] ?? null, $data['error'] ?? null ) as $code ) {
			if ( is_scalar( $code ) ) {
				return (string) $code;
			}
		}

		return '';
	}

	private function safeMessage( string $message, int $status_code ): string {
		$message = $this->settings->redact( trim( preg_replace( '/\s+/', ' ', $message ) ?? $message ) );
		if ( '' === $message ) {
			$message = $status_code > 0 ? 'HTTP ' . $status_code : 'Ошибка транспорта Яндекс.Доставки.';
		}

		return substr( $message, 0, 180 );
	}
}
