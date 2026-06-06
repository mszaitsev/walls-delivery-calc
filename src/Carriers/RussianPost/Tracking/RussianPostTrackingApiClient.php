<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\RussianPost\Tracking;

use SimpleXMLElement;
use WallsShop\WDC\Carriers\RussianPost\Otpravka\RussianPostOtpravkaApiSettings;

defined( 'ABSPATH' ) || exit;

final class RussianPostTrackingApiClient {
	private const ENDPOINT = 'https://tracking.russianpost.ru/rtm34';

	public function __construct( private RussianPostOtpravkaApiSettings $settings ) {
	}

	/**
	 * @return array<string,mixed>
	 */
	public function get_operation_history( string $barcode ): array {
		$barcode = trim( $barcode );
		$login = $this->settings->tracking_login();
		$password = $this->settings->tracking_password();
		if ( '' === $login || '' === $password ) {
			return $this->failure( 0, $barcode, 'Не заполнены Tracking API credentials.' );
		}
		if ( ! function_exists( 'wp_remote_post' ) ) {
			return $this->failure( 0, $barcode, 'Не удалось получить статус Почты России.' );
		}

		$response = wp_remote_post(
			self::ENDPOINT,
			array(
				'timeout' => $this->settings->timeout(),
				'headers' => array(
					'Content-Type' => 'application/soap+xml;charset=UTF-8',
					'Accept' => 'application/soap+xml, text/xml, */*',
				),
				'body' => $this->request_body( $barcode, $login, $password ),
			)
		);
		if ( function_exists( 'is_wp_error' ) && is_wp_error( $response ) ) {
			return $this->failure( 0, $barcode, 'Не удалось получить статус Почты России.' );
		}

		$http_code = function_exists( 'wp_remote_retrieve_response_code' ) ? (int) wp_remote_retrieve_response_code( $response ) : 0;
		$body = function_exists( 'wp_remote_retrieve_body' ) ? (string) wp_remote_retrieve_body( $response ) : '';
		if ( $http_code < 200 || $http_code >= 300 ) {
			return $this->failure( $http_code, $barcode, 'Не удалось получить статус Почты России.', $body );
		}
		if ( '' === trim( $body ) ) {
			return $this->failure( $http_code, $barcode, 'Не удалось получить статус Почты России.', $body );
		}

		$previous = libxml_use_internal_errors( true );
		$xml = simplexml_load_string( $body );
		$errors = libxml_get_errors();
		libxml_clear_errors();
		libxml_use_internal_errors( $previous );
		if ( ! $xml instanceof SimpleXMLElement ) {
			return $this->failure( $http_code, $barcode, 'Не удалось разобрать ответ Почты России.', $body, array( 'xml_errors' => array_map( static fn ( \LibXMLError $error ): string => trim( $error->message ), $errors ) ) );
		}

		$fault = $this->soap_fault_message( $xml );
		if ( '' !== $fault ) {
			return $this->failure( $http_code, $barcode, 'Почта России вернула SOAP Fault: ' . $fault, $body );
		}

		$records = array_map( array( $this, 'record_from_xml' ), $this->elements_by_local_name( $xml, 'historyRecord' ) );
		$records = array_values( array_filter( $records, static fn ( array $record ): bool => '' !== $record['operation_type_id'] || '' !== $record['operation_attr_id'] || '' !== $record['oper_date'] ) );
		if ( array() === $records ) {
			return $this->failure( $http_code, $barcode, 'Почта России вернула пустую историю операций.', $body );
		}

		$latest = $this->latest_record( $records );

		return array(
			'success' => true,
			'http_code' => $http_code,
			'barcode' => $barcode,
			'records' => $records,
			'latest_record' => $latest,
			'raw_response_snapshot' => $this->snapshot( $body ),
			'error_message' => '',
		);
	}

	private function request_body( string $barcode, string $login, string $password ): string {
		return '<?xml version="1.0" encoding="UTF-8"?>'
			. '<soap12:Envelope xmlns:soap12="http://www.w3.org/2003/05/soap-envelope" xmlns:oper="http://russianpost.org/operationhistory" xmlns:data="http://russianpost.org/operationhistory/data" xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/">'
			. '<soap12:Header/>'
			. '<soap12:Body>'
			. '<oper:getOperationHistory>'
			. '<data:OperationHistoryRequest>'
			. '<data:Barcode>' . $this->xml( $barcode ) . '</data:Barcode>'
			. '<data:MessageType>0</data:MessageType>'
			. '<data:Language>RUS</data:Language>'
			. '</data:OperationHistoryRequest>'
			. '<data:AuthorizationHeader soapenv:mustUnderstand="1">'
			. '<data:login>' . $this->xml( $login ) . '</data:login>'
			. '<data:password>' . $this->xml( $password ) . '</data:password>'
			. '</data:AuthorizationHeader>'
			. '</oper:getOperationHistory>'
			. '</soap12:Body>'
			. '</soap12:Envelope>';
	}

	/**
	 * @return array<string,string>
	 */
	private function record_from_xml( SimpleXMLElement $record ): array {
		return array(
			'oper_date' => $this->path_text( $record, array( 'OperationParameters', 'OperDate' ) ),
			'operation_index' => $this->path_text( $record, array( 'AddressParameters', 'OperationAddress', 'Index' ) ),
			'operation_address' => $this->path_text( $record, array( 'AddressParameters', 'OperationAddress', 'Description' ) ),
			'operation_type_id' => $this->path_text( $record, array( 'OperationParameters', 'OperType', 'Id' ) ),
			'operation_type_name' => $this->path_text( $record, array( 'OperationParameters', 'OperType', 'Name' ) ),
			'operation_attr_id' => $this->path_text( $record, array( 'OperationParameters', 'OperAttr', 'Id' ) ),
			'operation_attr_name' => $this->path_text( $record, array( 'OperationParameters', 'OperAttr', 'Name' ) ),
		);
	}

	/**
	 * @param array<int,array<string,string>> $records
	 * @return array<string,string>
	 */
	private function latest_record( array $records ): array {
		usort(
			$records,
			static function ( array $a, array $b ): int {
				$a_time = strtotime( (string) ( $a['oper_date'] ?? '' ) ) ?: 0;
				$b_time = strtotime( (string) ( $b['oper_date'] ?? '' ) ) ?: 0;

				return $b_time <=> $a_time;
			}
		);

		return $records[0] ?? array();
	}

	/**
	 * @param array<int,string> $path
	 */
	private function path_text( SimpleXMLElement $element, array $path ): string {
		$current = $element;
		foreach ( $path as $name ) {
			$next = $this->first_child_by_local_name( $current, $name );
			if ( ! $next instanceof SimpleXMLElement ) {
				return '';
			}
			$current = $next;
		}

		return trim( (string) $current );
	}

	private function first_child_by_local_name( SimpleXMLElement $element, string $name ): ?SimpleXMLElement {
		foreach ( $element->children() as $child ) {
			if ( $child->getName() === $name ) {
				return $child;
			}
		}
		foreach ( $element->getNamespaces( true ) as $namespace ) {
			foreach ( $element->children( $namespace ) as $child ) {
				if ( $child->getName() === $name ) {
					return $child;
				}
			}
		}

		return null;
	}

	/**
	 * @return array<int,SimpleXMLElement>
	 */
	private function elements_by_local_name( SimpleXMLElement $element, string $name ): array {
		$found = array();
		if ( $element->getName() === $name ) {
			$found[] = $element;
		}
		foreach ( $element->children() as $child ) {
			array_push( $found, ...$this->elements_by_local_name( $child, $name ) );
		}
		foreach ( $element->getNamespaces( true ) as $namespace ) {
			foreach ( $element->children( $namespace ) as $child ) {
				array_push( $found, ...$this->elements_by_local_name( $child, $name ) );
			}
		}

		return $found;
	}

	private function soap_fault_message( SimpleXMLElement $xml ): string {
		$faults = $this->elements_by_local_name( $xml, 'Fault' );
		if ( array() === $faults ) {
			return '';
		}
		$fault = $faults[0];
		foreach ( array( array( 'Reason', 'Text' ), array( 'faultstring' ), array( 'detail' ) ) as $path ) {
			$value = $this->path_text( $fault, $path );
			if ( '' !== $value ) {
				return $value;
			}
		}

		return 'ошибка SOAP';
	}

	private function snapshot( string $body ): string {
		return mb_substr( preg_replace( '/<password>.*?<\/password>/is', '<password>***</password>', $body ) ?? $body, 0, 8000 );
	}

	/**
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function failure( int $http_code, string $barcode, string $message, string $body = '', array $extra = array() ): array {
		return array_merge(
			array(
				'success' => false,
				'http_code' => $http_code,
				'barcode' => $barcode,
				'records' => array(),
				'latest_record' => array(),
				'raw_response_snapshot' => '' !== $body ? $this->snapshot( $body ) : '',
				'error_message' => $message,
			),
			$extra
		);
	}

	private function xml( string $value ): string {
		return htmlspecialchars( $value, ENT_XML1 | ENT_COMPAT, 'UTF-8' );
	}
}
