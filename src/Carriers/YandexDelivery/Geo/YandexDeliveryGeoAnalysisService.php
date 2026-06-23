<?php
/**
 * Read-only analysis helpers for saved Yandex geo mappings.
 *
 * @package WallsShop\WDC\Carriers\YandexDelivery\Geo
 */

declare(strict_types=1);

namespace WallsShop\WDC\Carriers\YandexDelivery\Geo;

use WallsShop\WDC\Locations\ValueObjects\Location;
use WallsShop\WDC\Locations\Storage\LocationRepository;

use function defined;
use function is_array;
use function is_scalar;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class YandexDeliveryGeoAnalysisService {
    private object $wpdb;

    /** @var array<int, Location|null> */
    private array $location_cache = [];

    public function __construct(
        private LocationRepository $locations,
        ?object $wpdb = null
    ) {
        if ( null === $wpdb ) {
            global $wpdb;
        }

        $this->wpdb = $wpdb;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function get_low_confidence_rows( float $max_confidence = 59.99, int $limit = 100 ): array {
        $rows   = $this->mapping_rows( $this->clamp_confidence( $max_confidence ), $this->clamp_limit( $limit ), true );
        $result = [];

        foreach ( $rows as $row ) {
            $result[] = $this->format_low_confidence_row( $row );
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    public function get_bucket_statistics(): array {
        $stats = $this->empty_bucket_statistics();

        foreach ( $this->mapping_rows() as $row ) {
            $bucket = $this->confidence_bucket( (float) ( $row['confidence'] ?? 0 ) );
            ++$stats[ $bucket ];
        }

        return $stats;
    }

    /**
     * @return array<string, int>
     */
    public function get_status_statistics(): array {
        $stats = [
            'mapped'           => 0,
            'multiple_matches' => 0,
            'needs_review'     => 0,
            'not_found'        => 0,
            'manual'           => 0,
            'error'            => 0,
        ];

        foreach ( $this->mapping_rows() as $row ) {
            $status = (string) ( $row['status'] ?? '' );

            if ( isset( $stats[ $status ] ) ) {
                ++$stats[ $status ];
            }
        }

        return $stats;
    }

    /**
     * @return array<int, array{region:string,count:int}>
     */
    public function get_top_regions( float $max_confidence = 59.99, int $limit = 10 ): array {
        return $this->top_location_field(
            $max_confidence,
            $limit,
            'region',
            static fn ( ?Location $location ): string => $location?->region_name ?: 'не указан'
        );
    }

    /**
     * @return array<int, array{type:string,count:int}>
     */
    public function get_top_settlement_types( float $max_confidence = 59.99, int $limit = 10 ): array {
        return $this->top_location_field(
            $max_confidence,
            $limit,
            'type',
            fn ( ?Location $location ): string => $this->settlement_type_label( $location )
        );
    }

    /**
     * @return array<int, array{pattern:string,count:int}>
     */
    public function get_top_matched_by_patterns( float $max_confidence = 59.99, int $limit = 20 ): array {
        $counts = [];

        foreach ( $this->mapping_rows( $this->clamp_confidence( $max_confidence ) ) as $row ) {
            foreach ( $this->matched_by_values( $row ) as $pattern ) {
                if ( '' === $pattern ) {
                    continue;
                }

                $counts[ $pattern ] = ( $counts[ $pattern ] ?? 0 ) + 1;
            }
        }

        return $this->format_counts( $counts, 'pattern', $limit );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function mapping_rows( ?float $max_confidence = null, int $limit = 0, bool $sort_lowest_first = false ): array {
        if ( $this->has_test_rows() ) {
            return $this->test_mapping_rows( $max_confidence, $limit, $sort_lowest_first );
        }

        if ( ! method_exists( $this->wpdb, 'get_results' ) ) {
            return [];
        }

        $table = $this->table_name();
        $args  = [];
        $sql   = "SELECT * FROM {$table}";

        if ( null !== $max_confidence ) {
            $sql    .= ' WHERE confidence <= %f';
            $args[] = $max_confidence;
        }

        $sql .= $sort_lowest_first ? ' ORDER BY confidence ASC, id DESC' : ' ORDER BY id ASC';

        if ( $limit > 0 ) {
            $sql    .= ' LIMIT %d';
            $args[] = $limit;
        }

        if ( ! empty( $args ) && method_exists( $this->wpdb, 'prepare' ) ) {
            $sql = $this->wpdb->prepare( $sql, ...$args );
        }

        $rows = $this->wpdb->get_results( $sql, ARRAY_A );

        return is_array( $rows ) ? $rows : [];
    }

    private function table_name(): string {
        return (string) $this->wpdb->prefix . 'wdc_yandex_delivery_geo_mappings';
    }

    private function has_test_rows(): bool {
        return isset( $this->wpdb->yandex_delivery_geo_mappings ) && is_array( $this->wpdb->yandex_delivery_geo_mappings );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function test_mapping_rows( ?float $max_confidence, int $limit, bool $sort_lowest_first ): array {
        $rows = [];

        foreach ( $this->wpdb->yandex_delivery_geo_mappings as $row ) {
            if ( null !== $max_confidence && (float) ( $row['confidence'] ?? 0 ) > $max_confidence ) {
                continue;
            }

            $rows[] = $row;
        }

        usort(
            $rows,
            static function ( array $a, array $b ) use ( $sort_lowest_first ): int {
                if ( $sort_lowest_first ) {
                    $confidence = (float) ( $a['confidence'] ?? 0 ) <=> (float) ( $b['confidence'] ?? 0 );

                    if ( 0 !== $confidence ) {
                        return $confidence;
                    }
                }

                return (int) ( $a['id'] ?? 0 ) <=> (int) ( $b['id'] ?? 0 );
            }
        );

        if ( $limit > 0 ) {
            $rows = array_slice( $rows, 0, $limit );
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function format_low_confidence_row( array $row ): array {
        $location_id = (int) ( $row['location_id'] ?? 0 );
        $location    = $this->location_for_id( $location_id );

        return [
            'location_id'   => $location_id,
            'display_name'  => $location?->resolved_display_name() ?: '',
            'yandex_geo_id' => isset( $row['yandex_geo_id'] ) ? (int) $row['yandex_geo_id'] : 0,
            'confidence'    => (float) ( $row['confidence'] ?? 0 ),
            'status'        => (string) ( $row['status'] ?? '' ),
            'matched_by'    => implode( ', ', $this->matched_by_values( $row ) ),
            'reason'        => $this->reason( $row ),
            'source_query'  => (string) ( $row['source_query'] ?? '' ),
        ];
    }

    private function location_for_id( int $location_id ): ?Location {
        if ( $location_id <= 0 ) {
            return null;
        }

        if ( array_key_exists( $location_id, $this->location_cache ) ) {
            return $this->location_cache[ $location_id ];
        }

        $this->location_cache[ $location_id ] = $this->locations->find_by_id( $location_id );

        return $this->location_cache[ $location_id ];
    }

    /**
     * @return array<int, string>
     */
    private function matched_by_values( array $row ): array {
        $raw = $this->decode_raw_json( $row );

        if ( ! isset( $raw['scoring']['matched_by'] ) || ! is_array( $raw['scoring']['matched_by'] ) ) {
            return [];
        }

        $values = [];

        foreach ( $raw['scoring']['matched_by'] as $value ) {
            if ( is_scalar( $value ) ) {
                $values[] = (string) $value;
            }
        }

        return $values;
    }

    private function reason( array $row ): string {
        $raw = $this->decode_raw_json( $row );

        if ( isset( $raw['scoring']['reason'] ) && is_scalar( $raw['scoring']['reason'] ) ) {
            return (string) $raw['scoring']['reason'];
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function decode_raw_json( array $row ): array {
        $raw_json = (string) ( $row['raw_json'] ?? '' );

        if ( '' === $raw_json ) {
            return [];
        }

        $decoded = json_decode( $raw_json, true );

        return is_array( $decoded ) ? $decoded : [];
    }

    /**
     * @return array<string, int>
     */
    private function empty_bucket_statistics(): array {
        return [
            '100'   => 0,
            '95_99' => 0,
            '80_94' => 0,
            '60_79' => 0,
            '40_59' => 0,
            '1_39'  => 0,
            '0'     => 0,
        ];
    }

    private function confidence_bucket( float $confidence ): string {
        if ( $confidence >= 100 ) {
            return '100';
        }

        if ( $confidence >= 95 ) {
            return '95_99';
        }

        if ( $confidence >= 80 ) {
            return '80_94';
        }

        if ( $confidence >= 60 ) {
            return '60_79';
        }

        if ( $confidence >= 40 ) {
            return '40_59';
        }

        if ( $confidence > 0 ) {
            return '1_39';
        }

        return '0';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function top_location_field( float $max_confidence, int $limit, string $key, callable $label_callback ): array {
        $counts = [];

        foreach ( $this->mapping_rows( $this->clamp_confidence( $max_confidence ) ) as $row ) {
            $label = (string) $label_callback( $this->location_for_id( (int) ( $row['location_id'] ?? 0 ) ) );

            if ( '' === $label ) {
                $label = 'не указан';
            }

            $counts[ $label ] = ( $counts[ $label ] ?? 0 ) + 1;
        }

        return $this->format_counts( $counts, $key, $limit );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function format_counts( array $counts, string $key, int $limit ): array {
        arsort( $counts );

        $result = [];

        foreach ( $counts as $label => $count ) {
            $result[] = [
                $key     => (string) $label,
                'count' => (int) $count,
            ];

            if ( count( $result ) >= $this->clamp_limit( $limit, 1, 100 ) ) {
                break;
            }
        }

        return $result;
    }

    private function settlement_type_label( ?Location $location ): string {
        if ( null === $location ) {
            return 'не указан';
        }

        $type = $location->resolved_place_type();
        $type = mb_strtolower( trim( str_replace( '.', '', $type ) ), 'UTF-8' );

        return match ( $type ) {
            'г' => 'город',
            'с' => 'село',
            'д' => 'деревня',
            'х' => 'хутор',
            'ст' => 'станица',
            'п', 'пос', 'поселок', 'посёлок' => 'поселок',
            'пгт' => 'пгт',
            '' => 'не указан',
            default => $type,
        };
    }

    private function clamp_confidence( float $confidence ): float {
        return max( 0.0, min( 100.0, $confidence ) );
    }

    private function clamp_limit( int $limit, int $min = 1, int $max = 500 ): int {
        return max( $min, min( $max, $limit ) );
    }
}