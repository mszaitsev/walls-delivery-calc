<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Returns;

use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiClient;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiException;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentExternalIdResolver;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentStatusMapping;
use WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentStatusMapper;
use WallsShop\WDC\Domain\Status\DeliveryStatus;

defined( 'ABSPATH' ) || exit;

final class OzonDeliveryReturnService {
	private const PAGE_LIMIT = 100;
	private const SAFETY_PAGE_CAP = 50;

	public function __construct(
		private OzonDeliveryApiClient $api,
		private OzonDeliveryShipmentExternalIdResolver $external_ids,
		private OzonDeliveryReturnSearchParser $search_parser,
		private OzonDeliveryReturnInfoParser $info_parser,
		private OzonDeliveryReturnLifecycleResolver $lifecycle,
		private OzonDeliveryShipmentStatusMapper $status_mapper
	) {}

	/** @param array<string,mixed> $shipment @param array<string,string> $outbound_statuses */
	public function should_reconcile( array $shipment, array $outbound_statuses ): bool {
		foreach ( $outbound_statuses as $status ) {
			if ( 'canceled' === OzonDeliveryShipmentStatusMapping::normalize( $status ) ) {
				return true;
			}
		}
		foreach ( is_array( $shipment['ozon_returns'] ?? null ) ? $shipment['ozon_returns'] : array() as $return ) {
			if ( is_array( $return ) && '' !== trim( (string) ( $return['return_number'] ?? '' ) ) ) {
				return true;
			}
		}
		$search = is_array( $shipment['ozon_return_search'] ?? null ) ? $shipment['ozon_return_search'] : array();
		if ( in_array( (string) ( $search['search_state'] ?? '' ), array( 'not_found', 'incomplete', 'error', 'info_error' ), true ) ) {
			return true;
		}
		foreach ( is_array( $shipment['ozon_postings'] ?? null ) ? $shipment['ozon_postings'] : array() as $posting ) {
			if ( is_array( $posting ) && in_array( (string) ( $posting['return_state'] ?? '' ), array( 'return_not_found', 'return_search_error', 'return_info_error', 'return_unknown', 'return_found_active' ), true ) ) {
				return true;
			}
		}
		$universal = (string) ( $shipment['universal_status_code'] ?? $shipment['status'] ?? '' );
		return DeliveryStatus::RETURNING_TO_SENDER === $universal || ( DeliveryStatus::UNKNOWN === $universal && array() !== $search );
	}

	/** @return array{shipment:array<string,mixed>,universal_status:string,success:bool,retryable:bool,message:string,error_code:string} */
	public function reconcile( object $order, array $shipment, array $outbound_statuses ): array {
		$now = $this->now();
		$order_number = $this->order_number( $order, $shipment );
		$postings = $this->postings( $shipment );
		$total = max( 1, count( $postings ) );
		$returns = $this->returns_by_place( $shipment );
		$place_states = array();
		$missing_expected = array();
		$success = true;
		$retryable = false;
		$message = 'Статус Ozon обновлён.';
		$error_code = '';

		foreach ( $postings as $posting ) {
			$place = max( 1, (int) ( $posting['place_number'] ?? 0 ) );
			$number = (string) ( $posting['posting_number'] ?? '' );
			$raw = (string) ( $outbound_statuses[ $number ] ?? $posting['last_raw_status'] ?? '' );
			if ( isset( $returns[ $place ] ) && '' !== (string) ( $returns[ $place ]['return_number'] ?? '' ) ) {
				continue;
			}
			if ( 'canceled' === OzonDeliveryShipmentStatusMapping::normalize( $raw ) ) {
				$missing_expected[ $this->external_ids->expected_return_external_id( $order_number, $place, $total ) ] = array(
					'place_number' => $place,
					'handover_state' => $this->handover_state( $posting ),
				);
				continue;
			}
			$place_states[ $place ] = array(
				'state' => 'outbound_active',
				'outbound_raw_status' => $raw,
				'outbound_universal' => $this->status_mapper->universal( $raw ),
			);
		}

		if ( array() !== $missing_expected ) {
			$search = $this->search_missing( array_keys( $missing_expected ), $missing_expected, $now );
			$shipment['ozon_return_search'] = $search['diagnostics'];
			foreach ( $search['matches'] as $place => $return ) {
				$returns[ (int) $place ] = $return;
			}
			if ( ! $search['success'] ) {
				$success = false;
				$retryable = true;
				$error_code = $search['error_code'];
				$message = 'Не удалось проверить возврат Ozon. Повторите обновление статуса позже.';
			}
			foreach ( $missing_expected as $expected_id => $target ) {
				$place = (int) $target['place_number'];
				if ( isset( $returns[ $place ] ) ) {
					continue;
				}
				if ( ! $search['success'] ) {
					$place_states[ $place ] = array( 'state' => 'return_search_error' );
					continue;
				}
				if ( false === $target['handover_state'] ) {
					$place_states[ $place ] = array( 'state' => 'cancelled_no_return' );
					$shipment['ozon_postings'] = $this->mark_posting_return_state( is_array( $shipment['ozon_postings'] ?? null ) ? $shipment['ozon_postings'] : array(), $place, 'cancelled_no_return' );
				} else {
					$place_states[ $place ] = array( 'state' => 'return_not_found' );
					$shipment['ozon_postings'] = $this->mark_posting_return_state( is_array( $shipment['ozon_postings'] ?? null ) ? $shipment['ozon_postings'] : array(), $place, 'return_not_found' );
				}
			}
		}

		$info = $this->refresh_return_info( $returns, $shipment, $now );
		$returns = $info['returns'];
		if ( ! $info['success'] ) {
			$success = false;
			$retryable = true;
			$error_code = $info['error_code'];
			$message = 'Не удалось обновить возврат Ozon. Повторите обновление статуса позже.';
			foreach ( $info['missing_numbers'] as $missing_number ) {
				$missing_place = $this->place_for_return_number( $returns, $missing_number );
				if ( null !== $missing_place && ! isset( $place_states[ $missing_place ] ) ) {
					$place_states[ $missing_place ] = array( 'state' => 'return_info_error' );
				}
			}
		}
		$shipment['ozon_returns'] = array_values( $returns );

		foreach ( $postings as $posting ) {
			$place = max( 1, (int) ( $posting['place_number'] ?? 0 ) );
			if ( isset( $place_states[ $place ] ) && 'return_info_error' === (string) ( $place_states[ $place ]['state'] ?? '' ) ) {
				continue;
			} elseif ( isset( $returns[ $place ] ) ) {
				$place_states[ $place ] = $this->return_place_state( $returns[ $place ] );
			} elseif ( isset( $place_states[ $place ] ) ) {
				continue;
			} elseif ( 'cancelled_no_return' === (string) ( $posting['return_state'] ?? '' ) ) {
				$place_states[ $place ] = array( 'state' => 'cancelled_no_return' );
			} elseif ( in_array( (string) ( $posting['return_state'] ?? '' ), array( 'return_not_found', 'return_search_error', 'return_info_error', 'return_unknown' ), true ) ) {
				$place_states[ $place ] = array( 'state' => (string) $posting['return_state'] );
			}
		}
		ksort( $place_states );
		$shipment['ozon_return_place_states'] = array_values( $place_states );
		$universal = $this->lifecycle->aggregate( array_values( $place_states ) );

		return array(
			'shipment' => $shipment,
			'universal_status' => $universal,
			'success' => $success,
			'retryable' => $retryable,
			'message' => $message,
			'error_code' => $error_code,
		);
	}

	/** @param array<int|string,array<string,mixed>> $returns @return array{returns:array<int,array<string,mixed>>,success:bool,retryable:bool,error_code:string,missing_numbers:array<int,string>} */
	private function refresh_return_info( array $returns, array &$shipment, string $now ): array {
		$numbers = array_values( array_unique( array_filter( array_map( static fn( array $row ): string => trim( (string) ( $row['return_number'] ?? '' ) ), $returns ) ) ) );
		if ( array() === $numbers ) {
			return array( 'returns' => $returns, 'success' => true, 'retryable' => false, 'error_code' => '', 'missing_numbers' => array() );
		}
		try {
			$info = $this->info_parser->parse( $this->api->return_info( $numbers ) );
		} catch ( \Throwable $exception ) {
			$shipment['ozon_return_search'] = array_merge( is_array( $shipment['ozon_return_search'] ?? null ) ? $shipment['ozon_return_search'] : array(), $this->error_diagnostics( 'info_error', $now, 'return_info_failed', $exception ) );
			return array( 'returns' => $returns, 'success' => false, 'retryable' => true, 'error_code' => 'return_info_failed', 'missing_numbers' => array() );
		}
		$by_number = array();
		$duplicates = array();
		foreach ( $info as $row ) {
			$number = (string) $row['return_number'];
			if ( isset( $by_number[ $number ] ) ) {
				$duplicates[ $number ] = true;
				continue;
			}
			$by_number[ $number ] = $row;
		}
		$missing = array_values( array_filter( $numbers, static fn( string $number ): bool => ! isset( $by_number[ $number ] ) || isset( $duplicates[ $number ] ) ) );
		foreach ( $returns as $place => $row ) {
			$number = (string) ( $row['return_number'] ?? '' );
			if ( isset( $by_number[ $number ] ) && ! isset( $duplicates[ $number ] ) ) {
				$returns[ $place ] = array_merge( $row, $this->with_return_universal( $by_number[ $number ] ), array( 'place_number' => (int) ( $row['place_number'] ?? $place ), 'checked_at' => $now ) );
			}
		}
		if ( array() !== $missing ) {
			$shipment['ozon_return_search'] = array_merge( is_array( $shipment['ozon_return_search'] ?? null ) ? $shipment['ozon_return_search'] : array(), array(
				'search_state' => 'info_error',
				'checked_at' => $now,
				'safe_error_code' => 'return_info_incomplete',
				'safe_error_message' => 'Ozon Delivery вернул неполную информацию о возвратах.',
			) );
			return array( 'returns' => $returns, 'success' => false, 'retryable' => true, 'error_code' => 'return_info_incomplete', 'missing_numbers' => $missing );
		}

		return array( 'returns' => $returns, 'success' => true, 'retryable' => false, 'error_code' => '', 'missing_numbers' => array() );
	}

	/** @param array<int,string> $expected_ids @param array<string,array<string,mixed>> $targets @return array{matches:array<int,array<string,mixed>>,diagnostics:array<string,mixed>,success:bool,retryable:bool,error_code:string} */
	private function search_missing( array $expected_ids, array $targets, string $now ): array {
		$expected = array_fill_keys( $expected_ids, true );
		$matches = array();
		$seen_numbers = array();
		$seen_cursors = array();
		$cursor = null;
		$pages = 0;
		$results = 0;
		$state = 'not_found';
		$error_code = '';
		try {
			do {
				++$pages;
				if ( $pages > self::SAFETY_PAGE_CAP ) {
					$state = 'incomplete';
					$error_code = 'return_search_page_cap';
					break;
				}
				$page = $this->search_parser->parse( $this->api->return_search( $cursor, self::PAGE_LIMIT ) );
				foreach ( $page['returns'] as $return ) {
					++$results;
					$number = (string) $return['return_number'];
					if ( isset( $seen_numbers[ $number ] ) ) {
						continue;
					}
					$seen_numbers[ $number ] = true;
					$external_id = trim( (string) ( $return['return_external_id'] ?? '' ) );
					if ( isset( $expected[ $external_id ] ) ) {
						$place = (int) $targets[ $external_id ]['place_number'];
						$matches[ $place ] = array_merge( $this->with_return_universal( $return ), array( 'place_number' => $place, 'checked_at' => $now ) );
					}
				}
				if ( count( $matches ) >= count( $expected ) ) {
					$state = 'found';
					break;
				}
				$cursor = $page['next_cursor'];
				if ( '' !== $cursor ) {
					if ( isset( $seen_cursors[ $cursor ] ) ) {
						$state = 'incomplete';
						$error_code = 'return_search_cursor_repeated';
						break;
					}
					$seen_cursors[ $cursor ] = true;
				}
			} while ( '' !== $cursor );
		} catch ( \Throwable $exception ) {
			$diagnostics = array_merge(
				$this->base_diagnostics( 'error', $now, $pages, $results, count( $expected ), count( $matches ) ),
				$this->exception_diagnostics( $exception, $exception instanceof \UnexpectedValueException ? 'return_search_response_invalid' : 'return_search_failed' )
			);
			return array( 'matches' => $matches, 'success' => false, 'retryable' => true, 'error_code' => (string) $diagnostics['safe_error_code'], 'diagnostics' => $diagnostics );
		}
		$success = ! in_array( $state, array( 'incomplete', 'error' ), true );
		$diagnostics = $this->base_diagnostics( $state, $now, $pages, $results, count( $expected ), count( $matches ) );
		if ( '' !== $error_code ) {
			$diagnostics['safe_error_code'] = $error_code;
			$diagnostics['safe_error_message'] = 'Поиск возвратов Ozon не завершён.';
		}

		return array( 'matches' => $matches, 'success' => $success, 'retryable' => ! $success, 'error_code' => $error_code, 'diagnostics' => $diagnostics );
	}

	/** @return array<string,mixed> */
	private function return_place_state( array $return ): array {
		$raw = (string) ( $return['status'] ?? 'UNKNOWN' );
		$universal = (string) ( $return['universal_status'] ?? $this->status_mapper->universal( $raw ) );
		if ( DeliveryStatus::UNKNOWN === $universal ) {
			return array( 'state' => 'return_unknown', 'return_raw_status' => $raw, 'return_universal' => $universal );
		}
		if ( DeliveryStatus::RETURNED_TO_SENDER === $universal ) {
			return array( 'state' => 'return_resolved', 'return_raw_status' => $raw, 'return_universal' => $universal );
		}
		return array( 'state' => 'return_found_active', 'return_raw_status' => $raw, 'return_universal' => $universal );
	}

	/** @return array<string,mixed> */
	private function with_return_universal( array $return ): array {
		$raw = (string) ( $return['status'] ?? 'UNKNOWN' );
		$return['normalized_status'] = OzonDeliveryShipmentStatusMapping::normalize( $raw );
		$return['universal_status'] = $this->status_mapper->universal( $raw );
		return $return;
	}

	/** @return array<int,array<string,mixed>> */
	private function postings( array $shipment ): array {
		$postings = is_array( $shipment['ozon_postings'] ?? null ) ? array_values( array_filter( $shipment['ozon_postings'], 'is_array' ) ) : array();
		usort( $postings, static fn( array $a, array $b ): int => (int) ( $a['place_number'] ?? 0 ) <=> (int) ( $b['place_number'] ?? 0 ) );
		return $postings;
	}

	/** @return array<int,array<string,mixed>> */
	private function returns_by_place( array $shipment ): array {
		$returns = array();
		foreach ( is_array( $shipment['ozon_returns'] ?? null ) ? $shipment['ozon_returns'] : array() as $return ) {
			if ( is_array( $return ) ) {
				$returns[ max( 1, (int) ( $return['place_number'] ?? 0 ) ) ] = $this->with_return_universal( $return );
			}
		}
		return $returns;
	}

	private function handover_state( array $posting ): ?bool {
		if ( true === ( $posting['handover_seen'] ?? null ) ) {
			return true;
		}
		if ( true === ( $posting['handover_unknown'] ?? null ) ) {
			return null;
		}
		if ( array_key_exists( 'handover_seen', $posting ) && false === (bool) $posting['handover_seen'] ) {
			return false;
		}
		$last = (string) ( $posting['last_universal_status'] ?? '' );
		if ( in_array( $last, array( DeliveryStatus::PENDING_CREATION_IN_CARRIER, DeliveryStatus::CREATED_IN_CARRIER ), true ) ) {
			return false;
		}
		if ( DeliveryStatus::is_valid( $last ) && ! in_array( $last, array( DeliveryStatus::UNKNOWN, DeliveryStatus::CANCELLED ), true ) ) {
			return true;
		}
		return null;
	}

	private function order_number( object $order, array $shipment ): string {
		if ( method_exists( $order, 'get_order_number' ) ) {
			return (string) $order->get_order_number();
		}
		return (string) ( $shipment['ozon_order_external_id'] ?? 'order' );
	}

	/** @param array<int,mixed> $postings */
	private function mark_posting_return_state( array $postings, int $place, string $state ): array {
		foreach ( $postings as $index => $posting ) {
			if ( is_array( $posting ) && $place === max( 1, (int) ( $posting['place_number'] ?? 0 ) ) ) {
				$posting['return_state'] = $state;
				$postings[ $index ] = $posting;
			}
		}
		return $postings;
	}

	/** @param array<int,array<string,mixed>> $returns */
	private function place_for_return_number( array $returns, string $number ): ?int {
		foreach ( $returns as $place => $return ) {
			if ( $number === (string) ( $return['return_number'] ?? '' ) ) {
				return (int) $place;
			}
		}
		return null;
	}

	/** @return array<string,mixed> */
	private function base_diagnostics( string $state, string $now, int $pages, int $results, int $expected, int $matched ): array {
		return array(
			'search_state' => $state,
			'checked_at' => $now,
			'pages_scanned' => $pages,
			'results_scanned' => $results,
			'expected_count' => $expected,
			'matched_count' => $matched,
			'safe_error_code' => '',
			'safe_error_message' => '',
		);
	}

	/** @return array<string,mixed> */
	private function error_diagnostics( string $state, string $now, string $fallback_code, \Throwable $exception ): array {
		return array_merge(
			array( 'search_state' => $state, 'checked_at' => $now ),
			$this->exception_diagnostics( $exception, $fallback_code )
		);
	}

	/** @return array<string,mixed> */
	private function exception_diagnostics( \Throwable $exception, string $fallback_code ): array {
		$diagnostics = array(
			'safe_error_code' => $fallback_code,
			'safe_error_message' => $this->safe_error( $exception->getMessage() ),
		);
		if ( $exception instanceof OzonDeliveryApiException ) {
			$diagnostics['safe_error_code'] = '' !== $exception->safe_code ? $exception->safe_code : $fallback_code;
			$diagnostics['http_status'] = $exception->http_status;
			$diagnostics['retryable'] = $exception->retryable;
		}
		return $diagnostics;
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function safe_error( string $message ): string {
		return substr( preg_replace( '/\s+/u', ' ', trim( $message ) ) ?? '', 0, 200 );
	}
}
