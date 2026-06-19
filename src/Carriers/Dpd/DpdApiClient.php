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
				array( 'wrapper' => DpdSoapRequest::WRAPPER_ORDERS )
			);
		} catch ( DpdException $exception ) {
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
		$success = '' === $status || 'OK' === $status;
		if ( '' !== $error_message && 'OK' !== $status ) {
			$success = false;
		}

		return array_merge(
			$normalized,
			array(
				'success' => $success,
				'order' => $row,
				'error_code' => $success ? '' : 'dpd_business_error',
				'error_message' => $success ? '' : $this->safe_message( '' !== $error_message ? $error_message : 'DPD вернул ошибку создания заказа.' ),
			)
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

	private function safe_message( string $message ): string {
		$message = trim( preg_replace( '/\s+/', ' ', $message ) ?? $message );

		return substr( $message, 0, 180 );
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
