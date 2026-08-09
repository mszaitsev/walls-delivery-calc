<?php
declare(strict_types=1);

namespace WallsShop\WDC\Shipments\Pek;

use WallsShop\WDC\Carriers\Pek\Api\PekApiClient;
use WallsShop\WDC\Carriers\Pek\Api\PekApiException;
use WallsShop\WDC\Carriers\Pek\PekSettings;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Logging\Logger;
use WallsShop\WDC\Shipments\Application\ShipmentActualCostResolver;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;

defined( 'ABSPATH' ) || exit;

final class PekShipmentAdapter implements CarrierShipmentAdapterInterface {
	public function __construct(
		private PekApiClient $api,
		private PekShipmentRequestBuilder $builder,
		private PekShipmentStatusService $statuses,
		private PekShipmentService $shipments,
		private PekShipmentButtonPolicy $buttons,
		private PekShipmentCreateResponseParser $create_responses,
		private ShipmentActualCostResolver $actual_cost_resolver,
		private ?Logger $logger = null
	) {
	}

	public function carrier_key(): string {
		return PekSettings::CARRIER_KEY;
	}

	public function supports( ShipmentCreateRequest $request ): bool {
		return PekSettings::CARRIER_KEY === $request->carrier_key;
	}

	/** @return array<string,mixed> */
	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array {
		try {
			$built = $this->builder->prepare( $this->order_from_request( $request ), $request, true );
			return array( 'method' => 'POST', 'path' => '/preregistration/submit/', 'body' => $built['preview'], 'errors' => array(), 'warnings' => is_array( $built['summary']['warnings'] ?? null ) ? $built['summary']['warnings'] : array() );
		} catch ( PekSmsReleaseValidationException $e ) {
			return array(
				'method' => 'POST',
				'path' => '/preregistration/submit/',
				'body' => array( 'sms_release_requested' => true, 'sms_release_confirmed' => false, 'sms_diagnostic' => $e->diagnostic() ),
				'errors' => array( $this->safe_error_message( $e ) ),
				'warnings' => $e->warnings(),
			);
		} catch ( PekApiException $e ) {
			return array(
				'method' => 'POST',
				'path' => '/preregistration/submit/',
				'body' => array( 'preparation_diagnostic' => $this->safe_preparation_diagnostic( $e, $request ) ),
				'errors' => array( $this->safe_error_message( $e ) ),
				'warnings' => array(),
			);
		} catch ( \Throwable $e ) {
			return array( 'method' => 'POST', 'path' => '/preregistration/submit/', 'body' => array(), 'errors' => array( $this->safe_error_message( $e ) ), 'warnings' => array() );
		}
	}

	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult {
		try {
			$order = $this->order_from_request( $request );
		} catch ( \Throwable ) {
			return new ShipmentCreateResult(
				false,
				error_code: 'pek_order_not_found',
				error_message: 'Не удалось загрузить заказ для создания заявки ПЭК.'
			);
		}

		return $this->create_for_order( $order, $request );
	}

	public function create_for_order( object $order, ShipmentCreateRequest $request ): ShipmentCreateResult {
		$built = null;
		$submitted = false;
		if ( ! $this->valid_creation_attempt_id( $request->meta['creation_attempt_id'] ?? null ) ) {
			return new ShipmentCreateResult(
				false,
				error_code: 'pek_creation_attempt_missing',
				error_message: 'Не удалось подготовить идентификатор попытки создания отправления. Обновите страницу заказа и повторите действие.'
			);
		}
		try {
			$built = $this->builder->prepare( $order, $request, true );
			$response = $this->api->preregistration_submit( $built['payload'] );
			$submitted = true;
			$parsed = $this->create_responses->parse( $response );
			$safe_summary = $this->safe_summary( $built['summary'] );
			return new ShipmentCreateResult(
				true,
				external_id: $parsed['document_id'],
				tracking_number: $parsed['cargo_code'],
				raw_reference: array_merge( $parsed, array( 'summary' => $safe_summary, 'http_status' => $this->api->last_response_meta()['http_status'] ?? '', 'correlation' => $safe_summary['correlation'] ?? '' ) )
			);
		} catch ( PekApiException $e ) {
			$context = $e->context();
			$stage = (string) ( $context['failure_stage'] ?? '' );
			$uncertain = is_array( $built ) && $this->is_uncertain_submit_exception( $context );
			return new ShipmentCreateResult(
				false,
				error_code: $uncertain ? 'pek_uncertain_submit' : (string) ( $context['error_code'] ?? 'pek_create_failed' ),
				error_message: $uncertain ? 'Результат создания заявки ПЭК не определён. Проверьте кабинет ПЭК перед повтором.' : 'ПЭК отклонил создание заявки.',
				raw_reference: $this->safe_create_failure_reference( $context, $e, is_array( $built ) ? $built : array(), $stage )
			);
		} catch ( \Throwable $e ) {
			$this->log( 'PEK shipment create failed.', $e );
			if ( $submitted ) {
				$meta = $this->api->last_response_meta();
				$safe_summary = is_array( $built ) ? $this->safe_summary( $built['summary'] ) : array();
				return new ShipmentCreateResult(
					false,
					error_code: 'pek_uncertain_submit',
					error_message: 'Результат создания заявки ПЭК не определён. Проверьте кабинет ПЭК перед повтором.',
					raw_reference: array(
						'failure_stage' => 'shipment_create_contract',
						'endpoint' => $meta['endpoint'] ?? '/preregistration/submit/',
						'method' => 'POST',
						'http_status' => $meta['http_status'] ?? '',
						'correlation' => (string) ( $safe_summary['correlation'] ?? '' ),
						'summary' => $safe_summary,
					)
				);
			}
			return new ShipmentCreateResult( false, error_code: 'pek_validation_failed', error_message: $this->safe_error_message( $e ) );
		}
	}

	/** @return array<string,string> */
	public function presentation(): array {
		return array(
			'carrier_label' => 'ПЭК',
			'tracking_label' => 'Код груза ПЭК',
			'create_button_label' => 'Создать отправление ПЭК',
			'manual_attach_button_label' => 'Внести код груза ПЭК вручную',
			'manual_attach_placeholder' => 'Код груза ПЭК',
			'manual_attach_help' => 'Введите код груза из кабинета ПЭК.',
			'cancel_button_label' => 'Отменить заявку ПЭК',
			'remove_button_label' => 'Удалить из заказа',
			'update_status_button_label' => 'Обновить статус',
			'created_toast' => 'Заявка ПЭК создана.',
			'error_fallback_message' => 'Не удалось получить статус ПЭК.',
		);
	}

	/** @param array<string,mixed> $shipment @return array<string,mixed> */
	public function status_payload( object $order, array $shipment ): array {
		$policy = $this->buttons->resolve( $shipment );
		$actual = $this->actual_cost_resolver->presentation_payload( $shipment, $order );

		return array_merge(
			array(
				'carrier_key' => PekSettings::CARRIER_KEY,
				'has_shipment' => array() !== $shipment,
				'can_create' => ! empty( $policy['create'] ),
				'can_attach_manual' => ! empty( $policy['manual_attach'] ),
				'external_status' => (string) ( $shipment['pek_cargo_status'] ?? $shipment['status_title'] ?? '' ),
				'external_status_id' => (string) ( $shipment['pek_cargo_status_id'] ?? '' ),
				'status_title' => (string) ( $shipment['status_title'] ?? '' ),
				'universal_status_code' => (string) ( $shipment['universal_status_code'] ?? '' ),
				'universal_status_label' => (string) ( $shipment['universal_status_label'] ?? '' ),
				'barcode' => $this->tracking_identifier( $shipment ),
				'can_update_status' => ! empty( $policy['update'] ),
				'can_cancel' => ! empty( $policy['cancel'] ) && $this->is_old_enough_for_cancel( $shipment ),
				'can_remove_from_order' => ! empty( $policy['remove'] ),
				'sms_release_requested' => ! empty( $shipment['sms_release_requested'] ),
				'sms_release_confirmed' => ! empty( $shipment['sms_release_confirmed'] ),
				'destination_mode' => (string) ( $shipment['shipment_mode'] ?? $shipment['delivery_type'] ?? '' ),
				'tracking_checked_at' => (string) ( $shipment['tracking_checked_at'] ?? '' ),
			),
			$actual
		);
	}

	/** @return array<string,mixed> */
	public function update_status( object $order, string $shipment_key = '' ): array {
		unset( $shipment_key );
		try {
			return $this->statuses->update( $order );
		} catch ( \RuntimeException | \InvalidArgumentException $e ) {
			$this->log_status_update_failure( $e );
			return array(
				'success' => false,
				'message' => 'Не удалось обновить статус ПЭК.',
				'error_code' => 'pek_status_update_failed',
				'diagnostic' => array(
					'carrier_key' => PekSettings::CARRIER_KEY,
					'stage' => 'status_normalization',
					'reason' => $this->safe_status_reason( $e->getMessage() ),
				),
			);
		}
	}

	/** @param array<string,mixed> $payload @return array<string,mixed> */
	public function attach_manual( object $order, array $payload ): array {
		return $this->shipments->attach_manual( $order, $payload );
	}

	/** @return array<string,mixed> */
	public function cancel_in_carrier( object $order, string $shipment_key = '' ): array {
		unset( $shipment_key );
		return $this->shipments->cancel_in_carrier( $order );
	}

	/** @return array<string,mixed> */
	public function remove_from_order( object $order, string $shipment_key = '' ): array {
		unset( $shipment_key );
		return $this->shipments->remove_local( $order );
	}

	public function supports_status_auto_sync(): bool {
		return true;
	}

	/** @param array<string,mixed> $shipment */
	public function tracking_identifier( array $shipment ): string {
		return trim( (string) ( $shipment['pek_cargo_code'] ?? $shipment['tracking_number'] ?? $shipment['barcode'] ?? '' ) );
	}

	public function auto_sync_throttle_microseconds(): int {
		return 1200000;
	}

	private function order_from_request( ShipmentCreateRequest $request ): object {
		$order = function_exists( 'wc_get_order' ) ? wc_get_order( $request->order_id ) : null;
		if ( ! is_object( $order ) ) {
			throw new \RuntimeException( 'Не удалось загрузить заказ для ПЭК.' );
		}

		return $order;
	}

	/** @param array<string,mixed> $shipment */
	private function is_old_enough_for_cancel( array $shipment ): bool {
		$created = $this->stored_timestamp( (string) ( $shipment['created_at'] ?? '' ) );
		$now = function_exists( 'current_datetime' ) ? current_datetime()->getTimestamp() : time();

		return null !== $created && $now - $created >= 600;
	}

	private function stored_timestamp( string $value ): ?int {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		$timezone = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
		foreach ( array( '!Y-m-d H:i:s', \DateTimeInterface::ATOM ) as $format ) {
			$date = \DateTimeImmutable::createFromFormat( $format, $value, $timezone );
			$errors = \DateTimeImmutable::getLastErrors();
			if ( $date instanceof \DateTimeImmutable && ( false === $errors || ( 0 === $errors['warning_count'] && 0 === $errors['error_count'] ) ) ) {
				return $date->getTimestamp();
			}
		}

		return null;
	}

	/** @param array<string,mixed> $context */
	private function is_uncertain_submit_exception( array $context ): bool {
		$stage = (string) ( $context['failure_stage'] ?? '' );
		if ( in_array( $stage, array( 'shipment_create_transport', 'shipment_create_contract' ), true ) ) {
			return true;
		}
		$status = (int) ( $context['http_status'] ?? 0 );
		if ( 408 === $status || ( $status >= 500 && $status <= 599 ) ) {
			return true;
		}

		return in_array( (string) ( $context['error_code'] ?? '' ), array( 'pek_http_500', 'pek_http_non_2xx' ), true ) && $status >= 500;
	}

	private function valid_creation_attempt_id( mixed $value ): bool {
		return is_string( $value ) && 1 === preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value );
	}

	private function log( string $message, \Throwable $e ): void {
		if ( $this->logger instanceof Logger ) {
			$this->logger->warning( $message, array( 'error' => $e->getMessage(), 'carrier_key' => PekSettings::CARRIER_KEY ) );
		}
	}

	private function log_status_update_failure( \Throwable $e ): void {
		if ( $this->logger instanceof Logger ) {
			$this->logger->warning(
				'PEK shipment status update failed.',
				array(
					'carrier_key' => PekSettings::CARRIER_KEY,
					'stage' => 'status_normalization',
					'error_code' => 'pek_status_update_failed',
					'reason' => $this->safe_status_reason( $e->getMessage() ),
				)
			);
		}
	}

	private function safe_status_reason( string $message ): string {
		$message = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $message ) ?? '';
		$message = trim( preg_replace( '/\s+/u', ' ', $message ) ?? '' );

		return '' !== $message ? substr( $message, 0, 200 ) : 'status_update_failed';
	}

	private function safe_error_message( \Throwable $e ): string {
		return $e instanceof \RuntimeException || $e instanceof \InvalidArgumentException
			? $e->getMessage()
			: 'Не удалось подготовить заявку ПЭК.';
	}

	private function safe_preparation_diagnostic( PekApiException $exception, ShipmentCreateRequest $request ): array {
		$context = $exception->context();
		$sensitive_values = $this->sensitive_values_from_request( $request );
		$stage = $this->preparation_stage( $context );

		return array(
			'stage' => $stage,
			'failure_stage' => is_string( $context['failure_stage'] ?? null ) ? $context['failure_stage'] : '',
			'endpoint' => is_string( $context['endpoint'] ?? null ) ? $context['endpoint'] : '',
			'method' => is_string( $context['method'] ?? null ) ? strtoupper( $context['method'] ) : '',
			'http_status' => $context['http_status'] ?? '',
			'error_code' => is_string( $context['error_code'] ?? null ) ? $context['error_code'] : 'pek_preparation_failed',
			'api_error_message' => $this->safe_api_error_message( $exception->getMessage(), $sensitive_values ),
			'field_errors' => $this->safe_field_errors( $context['field_errors'] ?? array(), $sensitive_values ),
			'response_shape' => is_array( $context['response_shape'] ?? null ) ? $context['response_shape'] : array(),
		);
	}

	/** @param array<string,mixed> $context */
	private function preparation_stage( array $context ): string {
		$stage = is_string( $context['preparation_stage'] ?? null ) ? $context['preparation_stage'] : '';
		$allowed = array( 'sender_warehouse', 'destination_pickup', 'destination_courier', 'counterpart' );
		if ( in_array( $stage, $allowed, true ) ) {
			return $stage;
		}
		$failure_stage = (string) ( $context['failure_stage'] ?? '' );
		$endpoint = (string) ( $context['endpoint'] ?? '' );
		if ( str_contains( $failure_stage, 'sender_warehouse' ) ) {
			return 'sender_warehouse';
		}
		if ( str_contains( $failure_stage, 'destination_terminal' ) || '/branches/nearestdepartments/' === $endpoint ) {
			return 'destination_pickup';
		}
		if ( str_contains( $failure_stage, 'destination_' ) || '/branches/findzonebyaddress/' === $endpoint ) {
			return 'destination_courier';
		}
		if ( str_contains( $failure_stage, 'counterpart' ) ) {
			return 'counterpart';
		}

		return '';
	}

	/** @param array<string,mixed> $context @param array<string,mixed> $built @return array<string,mixed> */
	private function safe_create_failure_reference( array $context, PekApiException $exception, array $built, string $stage ): array {
		$sensitive_values = $this->sensitive_values_from_built_request( $built );
		$reference = array(
			'failure_stage' => $stage,
			'endpoint' => is_string( $context['endpoint'] ?? null ) ? $context['endpoint'] : '/preregistration/submit/',
			'method' => 'POST',
			'http_status' => $context['http_status'] ?? '',
			'error_code' => is_string( $context['error_code'] ?? null ) ? $context['error_code'] : 'pek_create_failed',
			'api_error_message' => $this->safe_api_error_message( $exception->getMessage(), $sensitive_values ),
			'field_errors' => $this->safe_field_errors( $context['field_errors'] ?? array(), $sensitive_values ),
			'response_shape' => is_array( $context['response_shape'] ?? null ) ? $context['response_shape'] : array(),
			'correlation' => (string) ( $built['summary']['correlation'] ?? '' ),
			'summary' => is_array( $built['summary'] ?? null ) ? $this->safe_summary( $built['summary'] ) : array(),
		);
		$reference['diagnostic'] = array(
			'failure_stage' => $reference['failure_stage'],
			'endpoint' => $reference['endpoint'],
			'method' => $reference['method'],
			'http_status' => $reference['http_status'],
			'error_code' => $reference['error_code'],
			'api_error_message' => $reference['api_error_message'],
			'field_errors' => $reference['field_errors'],
			'response_shape' => $reference['response_shape'],
		);

		return $reference;
	}

	/** @param array<string,mixed> $built @return array<int,string> */
	private function sensitive_values_from_built_request( array $built ): array {
		$payload = is_array( $built['payload'] ?? null ) ? $built['payload'] : array();
		$values = array();
		$this->collect_sensitive_payload_values( $payload, $values );

		return array_values( array_unique( array_filter( $values, static fn( string $value ): bool => strlen( $value ) >= 3 ) ) );
	}

	/** @return array<int,string> */
	private function sensitive_values_from_request( ShipmentCreateRequest $request ): array {
		$values = array();
		foreach ( array( 'name', 'phone', 'email' ) as $key ) {
			if ( is_scalar( $request->recipient[ $key ] ?? null ) ) {
				$values[] = trim( (string) $request->recipient[ $key ] );
			}
		}

		return array_values( array_unique( array_filter( $values, static fn( string $value ): bool => strlen( $value ) >= 3 ) ) );
	}

	/** @param array<string|int,mixed> $value @param array<int,string> $values */
	private function collect_sensitive_payload_values( array $value, array &$values ): void {
		$sensitive_keys = array(
			'counterpartClientCard',
			'email',
			'firstName',
			'individual',
			'inn',
			'kpp',
			'lastName',
			'person',
			'personPhones',
			'phone',
			'sender',
			'title',
		);
		foreach ( $value as $key => $child ) {
			$key_string = (string) $key;
			$is_sensitive = in_array( $key_string, $sensitive_keys, true );
			if ( is_scalar( $child ) && $is_sensitive ) {
				$values[] = trim( (string) $child );
			}
			if ( is_array( $child ) ) {
				if ( $is_sensitive ) {
					$this->collect_all_scalar_values( $child, $values );
				} else {
					$this->collect_sensitive_payload_values( $child, $values );
				}
			}
		}
	}

	/** @param array<string|int,mixed> $value @param array<int,string> $values */
	private function collect_all_scalar_values( array $value, array &$values ): void {
		foreach ( $value as $child ) {
			if ( is_scalar( $child ) ) {
				$values[] = trim( (string) $child );
			} elseif ( is_array( $child ) ) {
				$this->collect_all_scalar_values( $child, $values );
			}
		}
	}

	/** @param array<int,string> $sensitive_values */
	private function safe_api_error_message( string $message, array $sensitive_values ): string {
		return $this->safe_diagnostic_text( $message, 500, 'ПЭК вернул ошибку без безопасного описания.', $sensitive_values );
	}

	/** @param array<int,string> $sensitive_values @return array<int,array{field:string,messages:array<int,string>}> */
	private function safe_field_errors( mixed $value, array $sensitive_values ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return array();
		}
		$result = array();
		$index_by_field = array();
		$total_messages = 0;
		foreach ( $value as $item ) {
			if ( ! is_array( $item ) || array_is_list( $item ) || ! is_string( $item['field'] ?? null ) ) {
				continue;
			}
			$field = $this->safe_diagnostic_text( $item['field'], 100, 'unknown_field', $sensitive_values );
			$messages = $this->safe_field_messages( $item['messages'] ?? null, $sensitive_values );
			if ( array() === $messages ) {
				continue;
			}
			if ( ! array_key_exists( $field, $index_by_field ) ) {
				if ( count( $result ) >= 20 ) {
					break;
				}
				$index_by_field[ $field ] = count( $result );
				$result[] = array( 'field' => $field, 'messages' => array() );
			}
			$index = $index_by_field[ $field ];
			foreach ( $messages as $message ) {
				if ( $total_messages >= 50 ) {
					break 2;
				}
				if ( count( $result[ $index ]['messages'] ) >= 5 || in_array( $message, $result[ $index ]['messages'], true ) ) {
					continue;
				}
				$result[ $index ]['messages'][] = $message;
				++$total_messages;
			}
		}

		return $result;
	}

	/** @param array<int,string> $sensitive_values @return array<int,string> */
	private function safe_field_messages( mixed $value, array $sensitive_values ): array {
		if ( ! is_array( $value ) || ! array_is_list( $value ) ) {
			return array();
		}
		$messages = array();
		foreach ( $value as $message ) {
			if ( ! is_string( $message ) ) {
				continue;
			}
			$message = $this->safe_diagnostic_text( $message, 500, 'ПЭК вернул ошибку поля без безопасного описания.', $sensitive_values );
			if ( ! in_array( $message, $messages, true ) ) {
				$messages[] = $message;
			}
			if ( count( $messages ) >= 5 ) {
				break;
			}
		}

		return $messages;
	}

	/** @param array<int,string> $sensitive_values */
	private function safe_diagnostic_text( string $message, int $max_length, string $fallback, array $sensitive_values ): string {
		$message = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $message ) ?? $message;
		$message = preg_replace( '/\s+/u', ' ', $message ) ?? $message;
		foreach ( $sensitive_values as $secret ) {
			if ( '' !== $secret ) {
				$message = str_replace( $secret, '[redacted]', $message );
			}
		}
		$message = preg_replace( '/Basic\s+[A-Za-z0-9+\/=]+/i', 'Basic [redacted]', $message ) ?? $message;
		$message = preg_replace( '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[redacted]', $message ) ?? $message;
		$message = preg_replace( '/(?<!\w)\+?7[\s().-]*\d[\d\s().-]{8,}\d(?!\w)/u', '[redacted]', $message ) ?? $message;
		$message = preg_replace( '/\b(?:CARD|CLIENTCARD|CLIENT_CARD|КАРТ[АЫ]?)[-_: ]*[A-Z0-9-]{3,}\b/iu', '[redacted]', $message ) ?? $message;
		$message = preg_replace( '/([?&])(?:api_key|apikey|token|password|authorization|login|phone|email|inn|kpp|card)=[^&\s]+/i', '$1[redacted]', $message ) ?? $message;
		$message = preg_replace( '/\b(?:api_key|apikey|token|password|authorization|login|phone|email|inn|kpp|card)\s*[:=]\s*["\']?[^"\'\s,;&]+/iu', '[redacted]', $message ) ?? $message;
		$message = preg_replace( '/"access_token"\s*:\s*"[^"]+"/i', '"access_token":"[redacted]"', $message ) ?? $message;
		$message = preg_replace( '/"token_type"\s*:\s*"[^"]+"/i', '"token_type":"[redacted]"', $message ) ?? $message;
		$message = trim( $message );
		if ( '' === $message ) {
			return $fallback;
		}
		if ( function_exists( 'mb_substr' ) ) {
			$message = mb_substr( $message, 0, $max_length );
		} else {
			$message = substr( $message, 0, $max_length );
		}

		return '' !== trim( $message ) ? trim( $message ) : $fallback;
	}

	/** @param array<string,mixed> $summary @return array<string,mixed> */
	private function safe_summary( array $summary ): array {
		$sender = is_array( $summary['sender_warehouse'] ?? null ) ? $summary['sender_warehouse'] : array();
		$cargo = is_array( $summary['cargo'] ?? null ) ? $summary['cargo'] : array();
		$sms = is_array( $summary['sms'] ?? null ) ? $summary['sms'] : array();

		return array(
			'correlation' => (string) ( $summary['correlation'] ?? '' ),
			'creation_attempt_present' => ! empty( $summary['creation_attempt_present'] ),
			'creation_attempt_generation' => (int) ( $summary['creation_attempt_generation'] ?? 0 ),
			'creation_attempt_state' => (string) ( $summary['creation_attempt_state'] ?? '' ),
			'creation_attempt_reused' => ! empty( $summary['creation_attempt_reused'] ),
			'creation_attempt_new' => ! empty( $summary['creation_attempt_new'] ),
			'sender_warehouse' => array(
				'warehouseId' => (string) ( $sender['warehouseId'] ?? '' ),
				'divisionName' => (string) ( $sender['divisionName'] ?? '' ),
				'branchName' => (string) ( $sender['branchName'] ?? '' ),
				'source' => (string) ( $sender['source'] ?? '' ),
			),
			'receiver_warehouse_id' => (string) ( $summary['receiver_warehouse_id'] ?? '' ),
			'receiver_branch_id' => (string) ( $summary['receiver_branch_id'] ?? '' ),
			'shipment_mode' => (string) ( $summary['shipment_mode'] ?? '' ),
			'declared_value_kopecks' => (int) ( $summary['declared_value_kopecks'] ?? 0 ),
			'product_weight_g' => (int) ( $summary['product_weight_g'] ?? 0 ),
			'sealing_requested' => ! empty( $summary['sealing_requested'] ),
			'sms' => array(
				'success' => ! empty( $sms['success'] ),
				'geography_confirmed' => ! empty( $sms['geography_confirmed'] ),
				'counterpart_service_confirmed' => ! empty( $sms['counterpart_service_confirmed'] ),
				'effective_limit_kopecks' => (int) ( $sms['effective_limit_kopecks'] ?? 0 ),
			),
			'cargo' => array(
				'place_count' => (int) ( $cargo['place_count'] ?? 0 ),
				'aggregate_weight_kg' => $cargo['aggregate_weight_kg'] ?? null,
				'aggregate_volume_m3' => $cargo['aggregate_volume_m3'] ?? null,
			),
		);
	}
}
