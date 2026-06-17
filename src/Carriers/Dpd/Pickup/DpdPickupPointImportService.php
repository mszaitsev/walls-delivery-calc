<?php
declare(strict_types=1);

namespace WallsShop\WDC\Carriers\Dpd\Pickup;

use WallsShop\WDC\Carriers\Dpd\DpdApiClient;
use WallsShop\WDC\Carriers\Dpd\DpdException;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;

defined( 'ABSPATH' ) || exit;

final class DpdPickupPointImportService {
	public function __construct(
		private DpdApiClient $api,
		private DpdPickupPointNormalizer $normalizer,
		private DpdPickupPointRepository $repository,
		private DpdSettings $settings
	) {
	}

	public function import_parcel_shops(): DpdPickupPointImportReport {
		return $this->import_source(
			DpdPickupPointNormalizer::SOURCE_PARCEL_SHOPS,
			DpdPickupPointNormalizer::TYPE_PARCEL_SHOP,
			fn() => $this->api->getParcelShops( array( 'countryCode' => 'RU' ) )->body
		);
	}

	public function import_terminals_self_delivery(): DpdPickupPointImportReport {
		return $this->import_source(
			DpdPickupPointNormalizer::SOURCE_TERMINALS_SELF_DELIVERY,
			DpdPickupPointNormalizer::TYPE_TERMINAL_SELF_DELIVERY,
			fn() => $this->api->getTerminalsSelfDelivery2()->body
		);
	}

	public function import_all(): DpdPickupPointImportReport {
		$reports = array(
			$this->import_parcel_shops(),
			$this->import_terminals_self_delivery(),
		);
		$combined = DpdPickupPointImportReport::combine( $reports );
		$this->settings->save_pickup_import_report( $combined->to_array() );

		return $combined;
	}

	/**
	 * @param callable():mixed $fetch
	 */
	private function import_source( string $source, string $type, callable $fetch ): DpdPickupPointImportReport {
		$started = $this->now();
		$errors = array();
		$fetched = 0;
		$normalized_count = 0;
		$saved = 0;
		$skipped = 0;
		$marked_inactive = 0;
		try {
			$response = $fetch();
			$normalized = $this->normalizer->normalize_response( $response, $source, $type );
			$fetched = (int) $normalized['fetched_count'];
			$points = $normalized['points'];
			$normalized_count = count( $points );
			$skipped = (int) $normalized['skipped_invalid'];
			if ( 0 === $fetched ) {
				$errors[] = 'DPD pickup import returned no rows. Existing points were left unchanged.';
			} elseif ( 0 === $normalized_count ) {
				$errors[] = 'DPD pickup import returned rows, but no valid points were normalized. Existing points were left unchanged.';
			} else {
				$save = $this->repository->replace_all_for_source( $source, $points );
				$saved = (int) $save['saved'];
				$skipped += (int) $save['skipped_invalid'];
				$marked_inactive = (int) $save['marked_inactive'];
			}
		} catch ( DpdException $exception ) {
			$errors[] = $exception->getMessage();
		} catch ( \Throwable $exception ) {
			$errors[] = 'DPD pickup import failed: ' . $exception->getMessage();
		}

		$report = new DpdPickupPointImportReport(
			$source,
			$started,
			$this->now(),
			$fetched,
			$normalized_count,
			$saved,
			$skipped,
			$marked_inactive,
			$errors,
			array() === $errors ? 'DPD pickup points imported.' : 'DPD pickup points import failed.'
		);
		$this->settings->save_pickup_import_report( $report->to_array() );

		return $report;
	}

	private function now(): string {
		return function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
	}
}
