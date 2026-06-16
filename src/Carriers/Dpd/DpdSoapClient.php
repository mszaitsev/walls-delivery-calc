<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd;

defined( 'ABSPATH' ) || exit;

final class DpdSoapClient implements DpdSoapClientInterface {
	public function __construct(
		private int $default_timeout = DpdSettings::DEFAULT_REQUEST_TIMEOUT
	) {
	}

	/**
	 * @param array<string,mixed> $payload
	 * @param array<string,mixed> $options
	 */
	public function call( string $service, string $method, array $payload, DpdCredentials $credentials, array $options = array() ): DpdSoapResponse {
		if ( ! $this->is_available() ) {
			throw new DpdException( 'PHP SOAP extension is not available.' );
		}
		if ( ! $credentials->is_complete() ) {
			throw new DpdException( 'DPD credentials are incomplete.' );
		}

		$environment = (string) ( $options['environment'] ?? $credentials->environment );
		$wsdl = DpdEndpoints::wsdl( $service, $environment );
		$timeout = max( 1, min( 120, (int) ( $options['timeout'] ?? $this->default_timeout ) ) );
		$request = new DpdSoapRequest( $service, $method, $payload, $credentials, $options );

		try {
			$previous_timeout = ini_get( 'default_socket_timeout' );
			ini_set( 'default_socket_timeout', (string) $timeout );
			$client = new \SoapClient(
				$wsdl,
				array(
					'connection_timeout' => $timeout,
					'exceptions' => true,
					'trace' => false,
				)
			);
			$response = $client->__soapCall( $method, array( $request->payload_with_auth() ) );
		} catch ( \SoapFault $exception ) {
			throw new DpdException( 'DPD SOAP request failed: ' . $this->safe_message( $exception->getMessage() ), array( 'service' => $service, 'method' => $method ), 0, $exception );
		} catch ( \Throwable $exception ) {
			throw new DpdException( 'DPD SOAP transport failed: ' . $this->safe_message( $exception->getMessage() ), array( 'service' => $service, 'method' => $method ), 0, $exception );
		} finally {
			if ( isset( $previous_timeout ) && false !== $previous_timeout ) {
				ini_set( 'default_socket_timeout', (string) $previous_timeout );
			}
		}

		if ( null === $response ) {
			throw new DpdException( 'DPD SOAP response is empty.', array( 'service' => $service, 'method' => $method ) );
		}

		return new DpdSoapResponse( true, $response, array( 'service' => $service, 'method' => $method, 'wsdl' => $wsdl ) );
	}

	public function is_available(): bool {
		return class_exists( \SoapClient::class );
	}

	private function safe_message( string $message ): string {
		$message = trim( preg_replace( '/\s+/', ' ', $message ) ?? $message );

		return substr( $message, 0, 180 );
	}
}

