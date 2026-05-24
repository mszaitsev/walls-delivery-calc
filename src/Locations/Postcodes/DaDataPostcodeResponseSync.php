<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Postcodes;

use WallsShop\WDC\Locations\Storage\LocationRepository;

defined( 'ABSPATH' ) || exit;

final class DaDataPostcodeResponseSync {
	public function __construct(
		private LocationRepository $repository
	) {
	}

	/**
	 * @param array<string,mixed> $decoded
	 * @return array{checked:int,matched:int,updated:int,skipped_empty_postal_code:int,skipped_missing_fias_id:int,not_found:int}
	 */
	public function sync_from_dadata_response( array $decoded ): array {
		$summary = $this->empty_summary();
		$suggestions = is_array( $decoded['suggestions'] ?? null ) ? $decoded['suggestions'] : $decoded;
		foreach ( $suggestions as $suggestion ) {
			if ( ! is_array( $suggestion ) ) {
				continue;
			}
			$data = is_array( $suggestion['data'] ?? null ) ? $suggestion['data'] : $suggestion;
			$this->sync_suggestion_data_to_summary( $data, $summary );
		}

		return $summary;
	}

	/**
	 * @param array<string,mixed> $data
	 */
	public function sync_from_suggestion_data( array $data ): bool {
		$summary = $this->empty_summary();
		$this->sync_suggestion_data_to_summary( $data, $summary );
		return $summary['updated'] > 0;
	}

	/**
	 * @return array{checked:int,matched:int,updated:int,skipped_empty_postal_code:int,skipped_missing_fias_id:int,not_found:int}
	 */
	private function empty_summary(): array {
		return array(
			'checked' => 0,
			'matched' => 0,
			'updated' => 0,
			'skipped_empty_postal_code' => 0,
			'skipped_missing_fias_id' => 0,
			'not_found' => 0,
		);
	}

	/**
	 * @param array<string,mixed> $data
	 * @param array{checked:int,matched:int,updated:int,skipped_empty_postal_code:int,skipped_missing_fias_id:int,not_found:int} $summary
	 */
	private function sync_suggestion_data_to_summary( array $data, array &$summary ): void {
		++$summary['checked'];
		$fias_id = trim( (string) ( $data['fias_id'] ?? '' ) );
		if ( '' === $fias_id ) {
			++$summary['skipped_missing_fias_id'];
			return;
		}

		$postal_code = trim( (string) ( $data['postal_code'] ?? '' ) );
		if ( '' === $postal_code ) {
			++$summary['skipped_empty_postal_code'];
			return;
		}

		if ( ! preg_match( '/^\d{6}$/', $postal_code ) ) {
			return;
		}

		if ( null === $this->repository->find_by_fias_id( $fias_id ) ) {
			++$summary['not_found'];
			return;
		}

		++$summary['matched'];
		$updated = $this->repository->update_postal_code_by_fias_id( $fias_id, $postal_code );
		if ( $updated > 0 ) {
			$summary['updated'] += $updated;
		}
	}
}
