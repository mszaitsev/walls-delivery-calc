<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Quote;

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\PekCredentials;
use WallsShop\WDC\Domain\Quote\QuoteRequest;
use WallsShop\WDC\Infrastructure\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class PekQuoteService {
	public function __construct(
		private PekCredentials $credentials,
		private PekApiClient $api,
		private PekQuoteRequestBuilder $request_builder,
		private PekQuoteResponseParser $parser,
		private PekQuoteMessageSanitizer $message_sanitizer,
		private ?Logger $logger = null
	) {
	}

	public function calculate( QuoteRequest $request, PekQuoteOptions $options ): PekQuoteResult {
		$payload = array();
		$safe_request = array();
		try {
			if ( ! $this->credentials->is_complete() ) {
				throw new PekApiException( 'Не заданы логин или API key ПЭК.', array( 'error_code' => 'pek_credentials_missing', 'failure_stage' => 'quote_calculator_contract' ) );
			}
			$payload = $this->request_builder->build( $request, $options );
			$safe_request = $this->request_builder->safe_request( $payload );
			$response = $this->api->calculate_price( $payload );
			$result = $this->parser->parse( $response, $options->mode, $safe_request, $this->api->last_response_meta() );

			return $result;
		} catch ( PekApiException $exception ) {
			$context = $exception->context();
			$result = new PekQuoteResult(
				false,
				$options->mode,
				0,
				'643',
				0,
				'',
				'',
				'',
				'',
				3,
				array(),
				$safe_request,
				array(
					'response_shape' => is_array( $context['response_shape'] ?? null ) ? $context['response_shape'] : array(),
				),
				(string) ( $context['error_code'] ?? 'pek_quote_failed' ),
				'Расчёт ПЭК завершился ошибкой.',
				(string) ( $context['failure_stage'] ?? 'quote_calculator_contract' ),
				(string) ( $context['endpoint'] ?? '/calculator/calculateprice/' ),
				(string) ( $context['method'] ?? 'POST' ),
				$context['http_status'] ?? '',
				$this->message_sanitizer->sanitize( $exception->getMessage() ),
				$this->safe_field_errors( $context['field_errors'] ?? array() )
			);
			$this->log_failure( $result, $request );

			return $result;
		}
	}

	/** @return array<int,array{field:string,messages:array<int,string>}> */
	private function safe_field_errors( mixed $value ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return array();
		}
		$result = array();
		foreach ( array_slice( $value, 0, 20 ) as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) || ! is_string( $item['field'] ?? null ) || ! is_array( $item['messages'] ?? null ) || ! array_is_list( $item['messages'] ) ) {
				continue;
			}
			$messages = array();
			foreach ( array_slice( $item['messages'], 0, 5 ) as $message ) {
				if ( is_string( $message ) ) {
					$messages[] = $this->message_sanitizer->sanitize_field_message( $message );
				}
			}
			$messages = array_values( array_unique( $messages ) );
			if ( array() !== $messages ) {
				$result[] = array( 'field' => (string) $item['field'], 'messages' => $messages );
			}
		}

		return $result;
	}

	private function log_failure( PekQuoteResult $result, QuoteRequest $request ): void {
		if ( null === $this->logger ) {
			return;
		}
		$this->logger->error(
			'PEK quote calculation failed.',
			array(
				'carrier' => 'pek',
				'mode' => $result->mode,
				'location_id' => (int) ( $request->customer_context['selected_location_id'] ?? 0 ),
				'country_code' => strtoupper( trim( $request->country_code ) ),
				'failure_stage' => $result->failure_stage,
				'error_code' => $result->error_code,
				'endpoint' => $result->endpoint,
				'method' => $result->method,
				'http_status' => $result->http_status,
				'response_shape' => $result->safe_response_meta['response_shape'] ?? array(),
				'field_error_fields' => $this->field_error_fields( $result->field_errors ),
				'field_error_count' => count( $result->field_errors ),
			)
		);
	}

	/** @param array<int,array{field:string,messages:array<int,string>}> $field_errors @return array<int,string> */
	private function field_error_fields( array $field_errors ): array {
		$fields = array();
		foreach ( array_slice( $field_errors, 0, 20 ) as $error ) {
			$field = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $error['field'] ) ?? $error['field'];
			$field = trim( preg_replace( '/\s+/u', ' ', $field ) ?? $field );
			if ( '' !== $field ) {
				$fields[] = function_exists( 'mb_substr' ) ? mb_substr( $field, 0, 100 ) : substr( $field, 0, 100 );
			}
		}

		return array_values( array_unique( $fields ) );
	}
}
