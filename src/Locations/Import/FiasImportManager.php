<?php
declare(strict_types=1);

namespace WallsShop\WDC\Locations\Import;

use RuntimeException;
use WallsShop\WDC\Core\PluginEnvironment;
use WallsShop\WDC\Infrastructure\Queue\ActionScheduler;
use WallsShop\WDC\Locations\Services\LocationAliasGenerator;
use WallsShop\WDC\Locations\Storage\LocationRepository;
use WallsShop\WDC\Locations\ValueObjects\Location;

defined( 'ABSPATH' ) || exit;

final class FiasImportManager {
	public const WEEKLY_HOOK = 'wdc_fias_prepared_import_check';

	public function __construct(
		private PluginEnvironment $environment,
		private LocationRepository $repository,
		private LocationAliasGenerator $alias_generator,
		private ActionScheduler $scheduler
	) {
	}

	public function register(): void {
		add_action( self::WEEKLY_HOOK, array( $this, 'check_prepared_dataset' ) );
		if ( ! $this->scheduler->has_scheduled( self::WEEKLY_HOOK ) ) {
			$this->scheduler->schedule_recurring( time() + $this->day(), $this->week(), self::WEEKLY_HOOK );
		}
	}

	public function check_prepared_dataset(): void {
		update_option( 'wdc_fias_prepared_import_last_check_at', current_time( 'mysql' ), false );
	}

	public function import_prepared_dataset( ?string $file = null, int $batch_size = 100 ): int {
		$file = $file ?? $this->environment->plugin_dir() . 'database/demo/fias-prepared-sample.json';
		if ( ! is_readable( $file ) ) {
			throw new RuntimeException( 'Prepared FIAS JSON file is not readable.' );
		}

		$data = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $data ) ) {
			throw new RuntimeException( 'Prepared FIAS JSON file must contain an array.' );
		}

		$imported = 0;
		$batch = array();
		foreach ( $data as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}

			$location = $this->row_to_location( $row );
			if ( array() !== $location->validate() ) {
				continue;
			}

			$batch[] = $location;
			if ( count( $batch ) >= max( 1, $batch_size ) ) {
				$imported += $this->flush_batch( $batch );
				$batch = array();
			}
		}

		if ( array() !== $batch ) {
			$imported += $this->flush_batch( $batch );
		}

		update_option( 'wdc_fias_prepared_import_last_import_at', current_time( 'mysql' ), false );

		return $imported;
	}

	private function flush_batch( array $locations ): int {
		$imported = 0;
		foreach ( $locations as $location ) {
			$id = $this->repository->save( $location );
			$this->repository->save_aliases( $id, $this->alias_generator->generate( $location ), 'generated' );
			++$imported;
		}

		return $imported;
	}

	private function row_to_location( array $row ): Location {
		$city       = trim( (string) ( $row['city_name'] ?? $row['city'] ?? '' ) );
		$settlement = trim( (string) ( $row['settlement_name'] ?? $row['settlement'] ?? '' ) );
		$region     = trim( (string) ( $row['region_name'] ?? '' ) );
		$name       = '' !== $settlement ? $settlement : $city;
		$display    = trim( (string) ( $row['display_name'] ?? '' ) );

		if ( '' === $display ) {
			$display = '' !== $region ? sprintf( '%s - %s', $name, $region ) : $name;
		}

		return new Location(
			null,
			$this->fias_id( $row['fias_id'] ?? '', $row['gar_id'] ?? '' ),
			trim( (string) ( $row['gar_id'] ?? '' ) ),
			strtoupper( trim( (string) ( $row['country_code'] ?? 'RU' ) ) ),
			$region,
			trim( (string) ( $row['region_code'] ?? '' ) ),
			$city,
			$settlement,
			trim( (string) ( $row['settlement_type'] ?? 'city' ) ),
			$display,
			trim( (string) ( $row['postcode'] ?? '' ) ),
			isset( $row['latitude'] ) && '' !== (string) $row['latitude'] ? (float) $row['latitude'] : null,
			isset( $row['longitude'] ) && '' !== (string) $row['longitude'] ? (float) $row['longitude'] : null,
			(bool) ( $row['active'] ?? true ),
			$this->gar_object_id( $row['gar_object_id'] ?? $row['gar_id'] ?? '' ),
			trim( (string) ( $row['kladr_id'] ?? '' ) ),
			'',
			trim( (string) ( $row['city_type'] ?? '' ) ),
			trim( (string) ( $row['city_fias_id'] ?? '' ) ),
			trim( (string) ( $row['city_kladr_id'] ?? '' ) ),
			trim( (string) ( $row['place_name'] ?? $settlement ?: $city ) ),
			trim( (string) ( $row['place_type'] ?? $row['settlement_type'] ?? 'city' ) ),
			isset( $row['place_level'] ) && '' !== (string) $row['place_level'] ? (int) $row['place_level'] : 0,
			trim( (string) ( $row['okato'] ?? '' ) ),
			trim( (string) ( $row['oktmo'] ?? '' ) ),
			trim( (string) ( $row['postal_code'] ?? $row['postcode'] ?? '' ) )
		);
	}

	private function gar_object_id( mixed $value ): int {
		$value = trim( (string) $value );
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}

		return '' !== $value ? (int) sprintf( '%u', crc32( $value ) ) : 0;
	}

	private function fias_id( mixed $value, mixed $fallback ): string {
		$value = trim( (string) $value );
		if ( '' !== $value ) {
			return $value;
		}

		$fallback = trim( (string) $fallback );
		return '' !== $fallback ? 'legacy-' . $fallback : '';
	}

	private function day(): int {
		return defined( 'DAY_IN_SECONDS' ) ? DAY_IN_SECONDS : 86400;
	}

	private function week(): int {
		return defined( 'WEEK_IN_SECONDS' ) ? WEEK_IN_SECONDS : 604800;
	}
}
