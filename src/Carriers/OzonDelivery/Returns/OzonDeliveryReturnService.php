<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\OzonDelivery\Returns;

use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiClient;
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

	/** @return array{shipment:array<string,mixed>,universal_status:string,message:string} */
	public function reconcile( object $order, array $shipment, array $outbound_statuses ): array {
		$now = $this->now();
		$order_number = $this->order_number( $order, $shipment );
		$postings = $this->postings( $shipment );
		$total = max( 1, count( $postings ) );
		$returns = $this->returns_by_place( $shipment );
		$place_states = array();
		$missing_expected = array();

		foreach ( $postings as $posting ) {
			$place = max( 1, (int) ( $posting['place_number'] ?? 0 ) );
			$raw = (string) ( $posting['last_raw_status'] ?? $outbound_statuses[ (string) ( $posting['posting_number'] ?? '' ) ] ?? '' );
			$normalized = OzonDeliveryShipmentStatusMapping::normalize( $raw );
			if ( 'canceled' !== $normalized ) {
				$place_states[] = array( 'state' => 'outbound_active', 'outbound_universal' => $this->status_mapper->universal( $raw ) );
				continue;
			}
			if ( isset( $returns[ $place ] ) && '' !== (string) ( $returns[ $place ]['return_number'] ?? '' ) ) {
				continue;
			}
			$handover = $this->handover_state( $posting );
			if ( false === $handover ) {
				$posting['return_state'] = 'cancelled_no_return';
				$place_states[] = array( 'state' => 'cancelled_no_return' );
				$shipment['ozon_postings'] = $this->replace_posting( $shipment['ozon_postings'] ?? array(), $posting );
				continue;
			}
			$missing_expected[ $this->external_ids->expected_return_external_id( $order_number, $place, $total ) ] = array(
				'place_number' => $place,
				'handover_unknown' => null === $handover,
			);
		}

		if ( array() !== $missing_expected ) {
			$search = $this->search_missing( array_keys( $missing_expected ), $missing_expected, $now );
			$shipment['ozon_return_search'] = $search['diagnostics'];
			foreach ( $search['matches'] as $place => $return ) {
				$returns[ (int) $place ] = $return;
			}
			if ( ! empty( $search['error'] ) ) {
				$shipment['ozon_returns'] = array_values( $returns );
				return array( 'shipment' => $shipment, 'universal_status' => DeliveryStatus::UNKNOWN, 'message' => 'Не удалось проверить возврат Ozon. Повторите обновление статуса позже.' );
			}
			foreach ( $missing_expected as $expected_id => $target ) {
				$place = (int) $target['place_number'];
				if ( ! isset( $returns[ $place ] ) ) {
					$place_states[] = array( 'state' => 'return_not_found' );
				}
			}
		}

		$returns = $this->refresh_return_info( $returns, $shipment, $now );
		$shipment['ozon_returns'] = array_values( $returns );
		foreach ( $postings as $posting ) {
			$place = max( 1, (int) ( $posting['place_number'] ?? 0 ) );
			if ( isset( $returns[ $place ] ) ) {
				$normalized = OzonDeliveryShipmentStatusMapping::normalize( (string) ( $returns[ $place ]['status'] ?? '' ) );
				$place_states[] = array( 'state' => 'received' === $normalized ? 'return_received' : 'return_found_active' );
			} elseif ( 'cancelled_no_return' === (string) ( $posting['return_state'] ?? '' ) ) {
				$place_states[] = array( 'state' => 'cancelled_no_return' );
			}
		}
		$universal = $this->lifecycle->aggregate( $place_states );

		return array( 'shipment' => $shipment, 'universal_status' => $universal, 'message' => 'Статус Ozon обновлён.' );
	}

	/** @param array<int|string,array<string,mixed>> $returns */
	private function refresh_return_info( array $returns, array &$shipment, string $now ): array {
		$numbers = array_values( array_filter( array_map( static fn( array $row ): string => (string) ( $row['return_number'] ?? '' ), $returns ) ) );
		if ( array() === $numbers ) {
			return $returns;
		}
		try {
			$info = $this->info_parser->parse( $this->api->return_info( $numbers ) );
		} catch ( \Throwable $exception ) {
			$shipment['ozon_return_search'] = array_merge(
				is_array( $shipment['ozon_return_search'] ?? null ) ? $shipment['ozon_return_search'] : array(),
				array( 'search_state' => 'info_error', 'checked_at' => $now, 'safe_error_message' => $this->safe_error( $exception->getMessage() ) )
			);
			return $returns;
		}
		$by_number = array();
		foreach ( $info as $row ) {
			$by_number[ (string) $row['return_number'] ] = $row;
		}
		foreach ( $returns as $place => $row ) {
			$number = (string) ( $row['return_number'] ?? '' );
			if ( isset( $by_number[ $number ] ) ) {
				$returns[ $place ] = array_merge( $row, $by_number[ $number ], array( 'place_number' => (int) ( $row['place_number'] ?? $place ), 'checked_at' => $now ) );
			}
		}

		return $returns;
	}

	/** @param array<int,string> $expected_ids @param array<string,array<string,mixed>> $targets @return array{matches:array<int,array<string,mixed>>,diagnostics:array<string,mixed>,error:bool} */
	private function search_missing( array $expected_ids, array $targets, string $now ): array {
		$expected = array_fill_keys( $expected_ids, true );
		$matches = array();
		$seen_numbers = array();
		$cursor = null;
		$pages = 0;
		$results = 0;
		$error = false;
		$state = 'not_found';
		try {
			do {
				++$pages;
				if ( $pages > self::SAFETY_PAGE_CAP ) {
					$error = true;
					$state = 'incomplete';
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
						$matches[ $place ] = array_merge( $return, array( 'place_number' => $place, 'checked_at' => $now ) );
					}
				}
				if ( count( $matches ) >= count( $expected ) ) {
					$state = 'found';
					break;
				}
				$cursor = $page['next_cursor'];
			} while ( '' !== $cursor );
		} catch ( \Throwable $exception ) {
			$error = true;
			$state = 'error';
		}

		return array(
			'matches' => $matches,
			'error' => $error,
			'diagnostics' => array(
				'search_state' => $state,
				'checked_at' => $now,
				'pages_scanned' => $pages,
				'results_scanned' => $results,
				'expected_count' => count( $expected ),
				'matched_count' => count( $matches ),
			),
		);
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
				$returns[ max( 1, (int) ( $return['place_number'] ?? 0 ) ) ] = $return;
			}
		}
		return $returns;
	}

	private function handover_state( array $posting ): ?bool {
		if ( array_key_exists( 'handover_seen', $posting ) ) {
			return (bool) $posting['handover_seen'];
		}
		$last = (string) ( $posting['last_universal_status'] ?? '' );
		if ( in_array( $last, array( DeliveryStatus::PENDING_CREATION_IN_CARRIER, DeliveryStatus::CREATED_IN_CARRIER ), true ) ) {
			return false;
		}
		if ( DeliveryStatus::is_valid( $last ) && DeliveryStatus::CANCELLED !== $last ) {
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
	private function replace_posting( array $postings, array $updated ): array {
		$number = (string) ( $updated['posting_number'] ?? '' );
		foreach ( $postings as $index => $posting ) {
			if ( is_array( $posting ) && $number === (string) ( $posting['posting_number'] ?? '' ) ) {
				$postings[ $index ] = $updated;
			}
		}
		return $postings;
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}

	private function safe_error( string $message ): string {
		return substr( preg_replace( '/\s+/u', ' ', trim( $message ) ) ?? '', 0, 200 );
	}
}
