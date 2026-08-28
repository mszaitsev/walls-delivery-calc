<?php
declare(strict_types=1);
namespace WallsShop\WDC\Carriers\OzonDelivery\Pickup;
use WallsShop\WDC\Carriers\OzonDelivery\Api\OzonDeliveryApiClient;
defined( 'ABSPATH' ) || exit;
final class OzonDeliveryPickupImportService {
	private const MAX_PAGES = 50000;
	private const MAX_ROWS = 5000000;
	public function __construct( private OzonDeliveryApiClient $api, private OzonDeliveryPickupParser $parser, private OzonDeliveryPickupRepository $repository ) {}
	public function start( string $job_id ): ?int { return $this->repository->start( $job_id ); }
	public function fail_job( string $job_id, string $code, string $message ): void { $generation = $this->repository->generation_by_job( $job_id ); if ( is_array( $generation ) && 'building' === (string) $generation['state'] ) { $this->repository->fail( (int) $generation['id'], $code, $message ); } }
	/** @return array{complete:bool,failed:bool} */
	public function run_step( string $job_id ): array {
		$generation = $this->repository->generation_by_job( $job_id ); if ( ! is_array( $generation ) || 'building' !== $generation['state'] ) { return array( 'complete' => true, 'failed' => 'failed' === ( $generation['state'] ?? '' ) ); }
		try {
			$page = $this->parser->list_page( $this->api->pickup_list( (string) ( $generation['cursor_value'] ?? '' ) ) );
			if ( (int) $generation['page_count'] >= self::MAX_PAGES || (int) $generation['downloaded_count'] + count( $page['ids'] ) > self::MAX_ROWS || ( null !== $page['next_cursor'] && $page['next_cursor'] === (string) ( $generation['cursor_value'] ?? '' ) ) ) { throw new \RuntimeException( 'pickup_pagination_invalid' ); }
			$details = array(); if ( array() !== $page['ids'] ) { $details = $this->parser->info_page( $this->api->pickup_info( $page['ids'] ) ); }
			$by_id = array(); foreach ( $details as $row ) { $by_id[(int) $row['point_id']] = $row; }
			$accepted = 0; $rejected = 0; $duplicates = 0; foreach ( $page['ids'] as $id ) { if ( ! isset( $by_id[$id] ) ) { ++$rejected; continue; } $existing = $this->repository->find_in_generation( (int) $generation['id'], $id ); if ( is_array( $existing ) ) { if ( hash_equals( (string) $existing['fingerprint'], (string) $by_id[$id]['fingerprint'] ) ) { ++$duplicates; continue; } throw new \RuntimeException( 'pickup_duplicate_conflict' ); } if ( ! $this->repository->insert_point( (int) $generation['id'], $by_id[$id] ) ) { throw new \RuntimeException( 'pickup_persistence_failed' ); } ++$accepted; }
			$patch = array( 'cursor_value' => $page['next_cursor'], 'page_count' => (int) $generation['page_count'] + 1, 'downloaded_count' => (int) $generation['downloaded_count'] + count( $page['ids'] ), 'accepted_count' => (int) $generation['accepted_count'] + $accepted, 'rejected_count' => (int) $generation['rejected_count'] + $rejected, 'duplicate_count' => (int) $generation['duplicate_count'] + $duplicates );
			$this->repository->update_generation( (int) $generation['id'], $patch );
			if ( null === $page['next_cursor'] ) { $this->repository->update_generation( (int) $generation['id'], array( 'state' => 'ready' ) ); if ( ! $this->repository->activate( (int) $generation['id'] ) ) { throw new \RuntimeException( 'pickup_activation_rejected' ); } return array( 'complete' => true, 'failed' => false ); }
			return array( 'complete' => false, 'failed' => false );
		} catch ( \Throwable $exception ) { $this->repository->fail( (int) $generation['id'], preg_replace( '/[^a-z0-9_]/', '', strtolower( $exception->getMessage() ) ) ?: 'pickup_import_failed', 'Не удалось обновить справочник ПВЗ Ozon Delivery.' ); return array( 'complete' => true, 'failed' => true ); }
	}
}
