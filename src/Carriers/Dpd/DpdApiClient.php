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
