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
			$result = $this->parser->parse( $response, $options->mode, $safe_request );

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
				$this->safe_api_error_message( $exception->getMessage() ),
				$this->safe_field_errors( $context['field_errors'] ?? array() )
			);
			$this->log_failure( $result, $request );

			return $result;
		}
	}

	private function safe_api_error_message( string $message ): string {
		$message = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $message ) ?? $message;
		$message = preg_replace( '/\s+/u', ' ', $message ) ?? $message;
		$message = trim( $message );
		if ( function_exists( 'mb_substr' ) ) {
			$message = mb_substr( $message, 0, 500 );
		} else {
			$message = substr( $message, 0, 500 );
		}

		return '' !== trim( $message ) ? trim( $message ) : 'ПЭК вернул ошибку без безопасного описания.';
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
			$messages = array_values( array_filter( array_slice( $item['messages'], 0, 5 ), 'is_string' ) );
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
				'field_errors' => $result->field_errors,
			)
		);
	}
}
