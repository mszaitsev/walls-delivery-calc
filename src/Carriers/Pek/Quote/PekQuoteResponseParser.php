<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Pek\Quote;

use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\PekSettings;

defined( 'ABSPATH' ) || exit;

final class PekQuoteResponseParser {
	private const MAX_SERVICES = 100;

	/** @param array<string,mixed> $response @param array<string,mixed> $safe_request @param array<string,mixed> $response_meta */
	public function parse( array $response, string $mode, array $safe_request, array $response_meta = array() ): PekQuoteResult {
		$meta = $this->response_meta( $response_meta );
		if ( array_is_list( $response ) ) {
			throw $this->contract_exception( 'pek_unexpected_calculate_price_response', 'ПЭК вернул неожиданную структуру расчёта стоимости.', $response, $meta );
		}
		if ( array_key_exists( 'hasError', $response ) && ! is_bool( $response['hasError'] ) ) {
			throw $this->contract_exception( 'pek_quote_root_error', 'ПЭК вернул некорректный флаг ошибки расчёта.', $response, $meta );
		}
		if ( true === ( $response['hasError'] ?? false ) ) {
			throw new PekApiException( $this->safe_text( $response['errorMessage'] ?? 'ПЭК вернул ошибку расчёта стоимости.' ), array_merge( $meta, array( 'error_code' => 'pek_quote_root_error', 'failure_stage' => 'quote_calculator_logical', 'response_shape' => $this->response_shape( $response ) ) ) );
		}
		if ( '643' !== (string) ( $response['currencyCode'] ?? '' ) ) {
			throw $this->contract_exception( 'pek_quote_currency_mismatch', 'ПЭК вернул расчёт в неподдерживаемой валюте.', $response, $meta );
		}
		if ( ! is_array( $response['transfers'] ?? null ) || ! array_is_list( $response['transfers'] ) ) {
			throw $this->contract_exception( 'pek_quote_transfers_invalid', 'ПЭК вернул некорректный список вариантов перевозки.', $response, $meta );
		}

		$ltl = array();
		foreach ( $response['transfers'] as $transfer ) {
			if ( is_array( $transfer ) && ! array_is_list( $transfer ) && $this->is_ltl_type( $transfer['type'] ?? null ) ) {
				$ltl[] = $transfer;
			}
		}
		if ( array() === $ltl ) {
			throw $this->contract_exception( 'pek_quote_ltl_transfer_missing', 'ПЭК не вернул LTL transfer type=3.', $response, $meta );
		}
		if ( count( $ltl ) > 1 ) {
			throw $this->contract_exception( 'pek_quote_ltl_transfer_duplicate', 'ПЭК вернул несколько LTL transfer type=3.', $response, $meta );
		}
		$transfer = $ltl[0];
		if ( ! array_key_exists( 'hasError', $transfer ) || ! is_bool( $transfer['hasError'] ) ) {
			throw $this->contract_exception( 'pek_quote_ltl_transfer_error', 'ПЭК вернул некорректный флаг ошибки LTL transfer.', $response, $meta );
		}
		if ( true === $transfer['hasError'] ) {
			throw new PekApiException( $this->safe_text( $transfer['errorMessage'] ?? 'ПЭК вернул ошибку LTL transfer.' ), array_merge( $meta, array( 'error_code' => 'pek_quote_ltl_transfer_error', 'failure_stage' => 'quote_calculator_logical', 'response_shape' => $this->response_shape( $response ) ) ) );
		}

		$cost_kopecks = $this->cost_kopecks( $transfer['costTotal'] ?? null, $meta );
		$delivery_days = $this->delivery_days( $transfer['estDeliveryTime'] ?? null, $meta );
		$services = $this->normalize_services( $transfer['services'] ?? array(), $meta );

		return new PekQuoteResult(
			true,
			$mode,
			$cost_kopecks,
			'643',
			$delivery_days,
			$this->safe_text( $response['branchSenderUID'] ?? '' ),
			$this->safe_text( $response['branchSender'] ?? '' ),
			$this->safe_text( $response['branchReceiverUID'] ?? '' ),
			$this->safe_text( $response['branchReceiver'] ?? '' ),
			PekSettings::LTL_PRODUCT_TYPE,
			$services,
			$safe_request,
			array(
				'currencyCode' => '643',
				'branchSenderUID' => $this->safe_text( $response['branchSenderUID'] ?? '' ),
				'branchSender' => $this->safe_text( $response['branchSender'] ?? '' ),
				'branchReceiverUID' => $this->safe_text( $response['branchReceiverUID'] ?? '' ),
				'branchReceiver' => $this->safe_text( $response['branchReceiver'] ?? '' ),
				'commonTerms' => $this->safe_text( $response['commonTerms'] ?? '' ),
				'endpoint' => $meta['endpoint'],
				'method' => $meta['method'],
				'http_status' => $meta['http_status'],
			),
			'',
			'',
			'',
			$meta['endpoint'],
			$meta['method'],
			$meta['http_status'],
			'',
			array(),
			$cost_kopecks
		);
	}

	private function is_ltl_type( mixed $value ): bool {
		return ( is_int( $value ) || is_float( $value ) || is_string( $value ) ) && is_numeric( $value ) && (int) $value === PekSettings::LTL_PRODUCT_TYPE && (float) $value === (float) PekSettings::LTL_PRODUCT_TYPE;
	}

	private function cost_kopecks( mixed $value, array $meta ): int {
		if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) || ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value < 0 ) {
			throw new PekApiException( 'ПЭК вернул некорректную стоимость LTL.', array_merge( $meta, array( 'error_code' => 'pek_quote_cost_invalid', 'failure_stage' => 'quote_calculator_contract' ) ) );
		}

		return (int) round( (float) $value * 100 );
	}

	private function delivery_days( mixed $value, array $meta ): int {
		if ( ! is_int( $value ) && ! is_float( $value ) && ! is_string( $value ) || ! is_numeric( $value ) || ! is_finite( (float) $value ) || (float) $value < 0 || floor( (float) $value ) !== (float) $value ) {
			throw new PekApiException( 'ПЭК вернул некорректный срок LTL.', array_merge( $meta, array( 'error_code' => 'pek_quote_delivery_time_invalid', 'failure_stage' => 'quote_calculator_contract' ) ) );
		}

		return (int) $value;
	}

	/** @return array<int,array<string,mixed>> */
	private function normalize_services( mixed $value, array $meta, int $depth = 0, int &$count = 0 ): array {
		if ( null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) || ! array_is_list( $value ) || $depth > 3 ) {
			throw new PekApiException( 'ПЭК вернул некорректный список услуг расчёта.', array_merge( $meta, array( 'error_code' => 'pek_quote_services_invalid', 'failure_stage' => 'quote_calculator_contract' ) ) );
		}
		$result = array();
		foreach ( $value as $item ) {
			if ( ++$count > self::MAX_SERVICES || ! is_array( $item ) || array_is_list( $item ) ) {
				throw new PekApiException( 'ПЭК вернул некорректный список услуг расчёта.', array_merge( $meta, array( 'error_code' => 'pek_quote_services_invalid', 'failure_stage' => 'quote_calculator_contract' ) ) );
			}
			$row = array();
			foreach ( array( 'serviceType', 'senderCity', 'info' ) as $key ) {
				if ( array_key_exists( $key, $item ) && null !== $item[ $key ] ) {
					if ( ! is_string( $item[ $key ] ) ) {
						throw new PekApiException( 'ПЭК вернул некорректный список услуг расчёта.', array_merge( $meta, array( 'error_code' => 'pek_quote_services_invalid', 'failure_stage' => 'quote_calculator_contract' ) ) );
					}
					$text = $this->safe_text( $item[ $key ] );
					if ( '' !== $text ) {
						$row[ $key ] = $text;
					}
				}
			}
			if ( array_key_exists( 'insuranceTerm', $item ) && null !== $item['insuranceTerm'] ) {
				if ( ! is_bool( $item['insuranceTerm'] ) ) {
					throw new PekApiException( 'ПЭК вернул некорректный список услуг расчёта.', array_merge( $meta, array( 'error_code' => 'pek_quote_services_invalid', 'failure_stage' => 'quote_calculator_contract' ) ) );
				}
				$row['insuranceTerm'] = $item['insuranceTerm'];
			}
			if ( array_key_exists( 'cost', $item ) && null !== $item['cost'] ) {
				if ( ! is_numeric( $item['cost'] ) || ! is_finite( (float) $item['cost'] ) ) {
					throw new PekApiException( 'ПЭК вернул некорректный список услуг расчёта.', array_merge( $meta, array( 'error_code' => 'pek_quote_services_invalid', 'failure_stage' => 'quote_calculator_contract' ) ) );
				}
				$row['cost'] = round( (float) $item['cost'], 2 );
			}
			if ( array_key_exists( 'services', $item ) ) {
				$row['services'] = $this->normalize_services( $item['services'], $meta, $depth + 1, $count );
			}
			$result[] = $row;
		}

		return $result;
	}

	private function safe_text( mixed $value ): string {
		if ( null === $value || ! is_scalar( $value ) ) {
			return '';
		}
		$text = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', (string) $value ) ?? (string) $value;
		$text = preg_replace( '/\s+/u', ' ', $text ) ?? $text;
		$text = trim( $text );
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $text, 0, 500 );
		}

		return substr( $text, 0, 500 );
	}

	/** @param array<string,mixed> $response */
	/** @param array<string,mixed> $meta */
	private function contract_exception( string $code, string $message, array $response, array $meta ): PekApiException {
		return new PekApiException( $message, array_merge( $meta, array( 'error_code' => $code, 'failure_stage' => 'quote_calculator_contract', 'response_shape' => $this->response_shape( $response ) ) ) );
	}

	/** @param array<string,mixed> $meta @return array{endpoint:string,method:string,http_status:int|string} */
	private function response_meta( array $meta ): array {
		$endpoint = is_string( $meta['endpoint'] ?? null ) && '/calculator/calculateprice/' === $meta['endpoint'] ? $meta['endpoint'] : '/calculator/calculateprice/';
		$method = is_string( $meta['method'] ?? null ) && in_array( strtoupper( $meta['method'] ), array( 'GET', 'POST' ), true ) ? strtoupper( $meta['method'] ) : 'POST';
		$status = $meta['http_status'] ?? '';
		if ( '' !== $status && ( ! is_int( $status ) && ! ( is_string( $status ) && ctype_digit( $status ) ) ) ) {
			$status = '';
		}
		if ( '' !== $status ) {
			$status = (int) $status;
			if ( $status < 100 || $status > 599 ) {
				$status = '';
			}
		}

		return array( 'endpoint' => $endpoint, 'method' => $method, 'http_status' => $status );
	}

	/** @return array<string,mixed> */
	private function response_shape( mixed $value ): array {
		if ( ! is_array( $value ) ) {
			return array( 'root_type' => get_debug_type( $value ) );
		}
		if ( array_is_list( $value ) ) {
			return array( 'root_type' => 'list', 'root_count' => count( $value ) );
		}

		return array( 'root_type' => 'object', 'root_keys' => array_slice( array_map( 'strval', array_keys( $value ) ), 0, 30 ) );
	}
}
