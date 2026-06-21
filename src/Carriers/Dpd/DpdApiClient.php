<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd;

defined( 'ABSPATH' ) || exit;

final class DpdApiClient {
	public function __construct(
		private DpdSettings $settings,
		private DpdSoapClientInterface $soap
	) {
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $options
	 */
	public function call( string $service, string $method, array $payload = array(), array $options = array() ): DpdSoapResponse {
		$credentials = $this->settings->credentials();
		$options = array_merge(
			array(
				'environment' => $this->settings->environment(),
				'timeout' => $this->settings->request_timeout(),
			),
			$options
		);

		return $this->soap->call( $service, $method, $payload, $credentials, $options );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function getCitiesCashPay( array $payload = array() ): array {
		return $this->normalize_response(
			$this->call( DpdEndpoints::SERVICE_GEOGRAPHY, 'getCitiesCashPay', $payload )
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function getPossibleExtraService( array $payload = array() ): array {
		return $this->normalize_response(
			$this->call( DpdEndpoints::SERVICE_GEOGRAPHY, 'getPossibleExtraService', $payload )
		);
	}

	/**
	 * @param array<string,mixed> $request
	 */
	public function getParcelShops( array $request = array() ): DpdSoapResponse {
		return $this->call(
			DpdEndpoints::SERVICE_GEOGRAPHY,
			'getParcelShops',
			$request,
			array( 'wrapper' => DpdSoapRequest::WRAPPER_REQUEST )
		);
	}

	public function getTerminalsSelfDelivery2(): DpdSoapResponse {
		return $this->call(
			DpdEndpoints::SERVICE_GEOGRAPHY,
			'getTerminalsSelfDelivery2',
			array(),
			array( 'wrapper' => DpdSoapRequest::WRAPPER_DIRECT )
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function getServiceCostByParcels2( array $payload ): DpdSoapResponse {
		return $this->call(
			DpdEndpoints::SERVICE_CALCULATOR,
			'getServiceCostByParcels2',
			$payload,
			array( 'wrapper' => DpdSoapRequest::WRAPPER_REQUEST )
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 */
	public function getServiceCostByParcels3( array $payload ): DpdSoapResponse {
		return $this->call(
			DpdEndpoints::SERVICE_CALCULATOR,
			'getServiceCostByParcels3',
			$payload,
			array( 'wrapper' => DpdSoapRequest::WRAPPER_REQUEST )
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function createOrder2( array $payload ): array {
		try {
			$response = $this->call(
				DpdEndpoints::SERVICE_ORDER,
				'createOrder2',
				$payload,
				array( 'wrapper' => DpdSoapRequest::WRAPPER_ORDERS, 'timeout' => $this->settings->order_create_timeout() )
			);
		} catch ( DpdException $exception ) {
			if ( $this->is_header_timeout( $exception->getMessage() ) ) {
				return array(
					'success' => false,
					'body' => array(),
					'meta' => array( 'service' => DpdEndpoints::SERVICE_ORDER, 'method' => 'createOrder2' ),
					'error_code' => 'dpd_order_create_uncertain',
					'error_message' => 'DPD не вернул ответ вовремя. Заказ мог быть создан в DPD. Проверьте личный кабинет DPD перед повторной отправкой.',
					'details' => $exception->context,
				);
			}

			return array(
				'success' => false,
				'body' => array(),
				'meta' => array( 'service' => DpdEndpoints::SERVICE_ORDER, 'method' => 'createOrder2' ),
				'error_code' => 'dpd_soap_error',
				'error_message' => $this->safe_message( $exception->getMessage() ),
				'details' => $exception->context,
			);
		} catch ( \Throwable $exception ) {
			return array(
				'success' => false,
				'body' => array(),
				'meta' => array( 'service' => DpdEndpoints::SERVICE_ORDER, 'method' => 'createOrder2' ),
				'error_code' => 'dpd_order_create_failed',
				'error_message' => $this->safe_message( $exception->getMessage() ),
				'details' => array(),
			);
		}

		$normalized = $this->normalize_response( $response );
		$body = is_array( $normalized['body'] ?? null ) ? $normalized['body'] : array();
		$row = $this->first_order_response_row( $body );
		$status = strtoupper( trim( (string) ( $row['status'] ?? $body['status'] ?? '' ) ) );
		$error_message = trim( (string) ( $row['errorMessage'] ?? $body['errorMessage'] ?? '' ) );
		$business_status = in_array( $status, array( 'OK', 'ORDERPENDING', 'ORDERDUPLICATE', 'ORDERERROR', 'ORDERCANCELLED' ), true );
		$success = ( '' === $status || $business_status ) && ! empty( $normalized['success'] );

		return array_merge(
			$normalized,
			array(
				'success' => $success,
				'order' => $row,
				'error_code' => $success ? '' : 'dpd_business_error',
				'error_message' => '' !== $error_message ? $this->safe_message( $error_message ) : ( $success ? '' : 'DPD вернул ошибку создания заказа.' ),
			)
		);
	}


	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function getOrderStatus( array $payload ): array {
		return $this->safe_wrapped_call(
			DpdEndpoints::SERVICE_ORDER,
			'getOrderStatus',
			$payload,
			array( 'wrapper' => DpdSoapRequest::WRAPPER_ORDER_STATUS, 'allow_business_status_response' => true )
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function getEvents( array $payload = array() ): array {
		unset( $payload['dateFromSpecified'], $payload['dateToSpecified'], $payload['maxRowCountSpecified'] );
		$payload['maxRowCount'] = 500;

		return $this->safe_wrapped_call(
			DpdEndpoints::SERVICE_EVENT_TRACKING,
			'getEvents',
			$payload,
			array( 'wrapper' => DpdSoapRequest::WRAPPER_REQUEST )
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function confirmEvents( array $payload ): array {
		return $this->safe_wrapped_call(
			DpdEndpoints::SERVICE_EVENT_TRACKING,
			'confirm',
			$payload,
			array( 'wrapper' => DpdSoapRequest::WRAPPER_REQUEST )
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function confirm( array $payload ): array {
		return $this->confirmEvents( $payload );
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function cancelOrder( array $payload ): array {
		return $this->safe_wrapped_call(
			DpdEndpoints::SERVICE_ORDER,
			'cancelOrder',
			$payload,
			array( 'wrapper' => DpdSoapRequest::WRAPPER_ORDERS )
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function getStatesByDPDOrder( array $payload ): array {
		return $this->safe_wrapped_call(
			DpdEndpoints::SERVICE_TRACING_1_1,
			'getStatesByDPDOrder',
			$payload,
			array( 'wrapper' => DpdSoapRequest::WRAPPER_REQUEST )
		);
	}
	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function getInvoiceFile( array $payload ): array {
		unset( $payload['parcelCount'], $payload['cargoValue'] );

		return $this->safe_wrapped_call(
			DpdEndpoints::SERVICE_ORDER,
			'getInvoiceFile',
			$payload,
			array( 'wrapper' => DpdSoapRequest::WRAPPER_REQUEST )
		);
	}

	/**
	 * @param array<string,mixed> $payload
	 * @return array<string,mixed>
	 */
	public function createLabelFile( array $payload ): array {
		return $this->safe_wrapped_call(
			DpdEndpoints::SERVICE_LABEL_PRINT,
			'createLabelFile',
			$payload,
			array( 'wrapper' => DpdSoapRequest::WRAPPER_GET_LABEL_FILE )
		);
	}
	/**
	 * @return array{success:bool,message:string,details:array<string,mixed>}
	 */
	public function checkConnectionDryRun(): array {
		$credentials = $this->settings->credentials();
		$endpoints = DpdEndpoints::wsdl_map( $this->settings->environment() );
		$transport_available = $this->soap->is_available();
		$success = $credentials->is_complete()
			&& $transport_available
			&& isset( $endpoints[ DpdEndpoints::SERVICE_GEOGRAPHY ], $endpoints[ DpdEndpoints::SERVICE_CALCULATOR ] );

		$parts = array();
		$parts[] = $credentials->is_complete() ? 'credentials configured' : 'credentials missing';
		$parts[] = $transport_available ? 'SOAP transport available' : 'SOAP transport unavailable';
		$parts[] = isset( $endpoints[ DpdEndpoints::SERVICE_GEOGRAPHY ] ) ? 'geography endpoint selected' : 'geography endpoint missing';
		$parts[] = isset( $endpoints[ DpdEndpoints::SERVICE_CALCULATOR ] ) ? 'calculator endpoint selected' : 'calculator endpoint missing';

		return array(
			'success' => $success,
			'message' => 'Dry diagnostic only; no DPD API call was executed. ' . implode( '; ', $parts ) . '.',
			'details' => array(
				'environment' => $this->settings->environment(),
				'transport_available' => $transport_available,
				'credentials_complete' => $credentials->is_complete(),
				'endpoints' => $endpoints,
			),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function normalize_response( DpdSoapResponse $response ): array {
		$body = $this->value_to_array( $response->body );

		return array(
			'success' => $response->success,
			'body' => is_array( $body ) ? $body : array(),
			'meta' => $response->meta,
		);
	}

	/**
	 * @param array<string,mixed> $body
	 * @return array<string,mixed>
	 */
	private function first_order_response_row( array $body ): array {
		foreach ( array( 'return', 'order', 'orders' ) as $key ) {
			$value = $body[ $key ] ?? null;
			if ( is_array( $value ) ) {
				if ( is_array( $value[0] ?? null ) ) {
					return $value[0];
				}
				return $value;
			}
		}

		return $body;
	}

	private function is_header_timeout( string $message ): bool {
		return str_contains( strtolower( $message ), 'error fetching http headers' );
	}

	private function safe_message( string $message ): string {
		$message = trim( preg_replace( '/\s+/', ' ', $message ) ?? $message );

		return substr( $message, 0, 180 );
	}


	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $options
	 * @return array<string,mixed>
	 */
	private function safe_wrapped_call( string $service, string $method, array $payload, array $options ): array {
		try {
			$response = $this->call( $service, $method, $payload, $options );
		} catch ( DpdException $exception ) {
			return array(
				'success' => false,
				'body' => array(),
				'meta' => array( 'service' => $service, 'method' => $method ),
				'error_code' => $this->is_header_timeout( $exception->getMessage() ) ? 'dpd_uncertain_timeout' : 'dpd_soap_error',
				'error_message' => $this->safe_message( $exception->getMessage() ),
				'details' => $exception->context,
			);
		} catch ( \Throwable $exception ) {
			return array(
				'success' => false,
				'body' => array(),
				'meta' => array( 'service' => $service, 'method' => $method ),
				'error_code' => 'dpd_transport_error',
				'error_message' => $this->safe_message( $exception->getMessage() ),
				'details' => array(),
			);
		}

		$normalized = $this->normalize_response( $response );
		$body = is_array( $normalized['body'] ?? null ) ? $normalized['body'] : array();
		$error_message = $this->first_error_message( $body );
		if ( '' !== $error_message ) {
			$normalized['error_message'] = $this->safe_message( $error_message );
			if ( empty( $options['allow_business_status_response'] ) ) {
				$normalized['success'] = false;
				$normalized['error_code'] = 'dpd_business_error';
			}
		}

		return $normalized;
	}

	/**
	 * @param array<string,mixed> $body
	 */
	private function first_error_message( array $body ): string {
		$walker = function ( mixed $value ) use ( &$walker ): string {
			if ( is_array( $value ) ) {
				if ( isset( $value['errorMessage'] ) && '' !== trim( (string) $value['errorMessage'] ) ) {
					return trim( (string) $value['errorMessage'] );
				}
				foreach ( $value as $item ) {
					$found = $walker( $item );
					if ( '' !== $found ) {
						return $found;
					}
				}
			}

			return '';
		};

		return $walker( $body );
	}

	/**
	 * @return mixed
	 */
	private function value_to_array( mixed $value ): mixed {
		if ( is_object( $value ) ) {
			$value = get_object_vars( $value );
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $key => $item ) {
				$value[ $key ] = $this->value_to_array( $item );
			}
		}

		return $value;
	}
}
