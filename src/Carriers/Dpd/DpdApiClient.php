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
	 * @return array{success:bool,message:string,details:array<string,mixed>}
	 */
	public function checkConnectionDryRun(): array {
		$credentials = $this->settings->credentials();
		$endpoints = DpdEndpoints::wsdl_map( $this->settings->environment() );
		$transport_available = $this->soap->is_available();
		$success = $credentials->is_complete() && $transport_available && isset( $endpoints[ DpdEndpoints::SERVICE_GEOGRAPHY ] );

		$parts = array();
		$parts[] = $credentials->is_complete() ? 'credentials configured' : 'credentials missing';
		$parts[] = $transport_available ? 'SOAP transport available' : 'SOAP transport unavailable';
		$parts[] = isset( $endpoints[ DpdEndpoints::SERVICE_GEOGRAPHY ] ) ? 'endpoints selected' : 'endpoints missing';

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
}

