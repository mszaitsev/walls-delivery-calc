<?php
declare(strict_types=1);
define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
function wp_json_encode( mixed $value ): string|false { return json_encode( $value, JSON_UNESCAPED_UNICODE ); }
require_once dirname( __DIR__, 2 ) . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupParser.php';
use WallsShop\WDC\Carriers\OzonDelivery\Pickup\OzonDeliveryPickupParser;
function oz_pickup_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
$parser = new OzonDeliveryPickupParser();
$page = $parser->list_page( array( 'delivery_points' => array( array( 'delivery_point_id' => 10 ) ), 'next_cursor' => 'cursor-2' ) ); oz_pickup_assert( $page['ids'] === array( 10 ) && $page['next_cursor'] === 'cursor-2', 'list pagination contract must be parsed exactly.' );
try { $parser->list_page( array( 'delivery_points' => array(), 'next_cursor' => '' ) ); throw new RuntimeException( 'invalid cursor accepted' ); } catch ( RuntimeException $exception ) { oz_pickup_assert( 'pickup_cursor_invalid' === $exception->getMessage(), 'empty non-null cursor must fail closed.' ); }
$point = array( 'delivery_point_id' => 10, 'name' => 'ПВЗ', 'delivery_point_number' => 'X', 'type' => 'pvz', 'full_address' => 'ул. Тестовая, 1', 'coordinates' => array( 'latitude' => 55.0, 'longitude' => 82.0 ), 'schedule' => array(), 'is_active' => true, 'is_bulky' => false, 'restrictions' => array( 'max_weight_g' => 1000 ) );
$rows = $parser->info_page( array( 'delivery_points' => array( $point ) ) ); oz_pickup_assert( 1 === count( $rows ) && 10 === $rows[0]['point_id'] && '' !== $rows[0]['fingerprint'], 'allowlisted pickup point must normalize deterministically.' );
$point['coordinates']['latitude'] = 100; oz_pickup_assert( array() === $parser->info_page( array( 'delivery_points' => array( $point ) ) ), 'invalid coordinates must be rejected.' );
$root = dirname( __DIR__, 2 ); $api = file_get_contents( $root . '/src/Carriers/OzonDelivery/Api/OzonDeliveryApiClient.php' ) ?: ''; $importer = file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupImportService.php' ) ?: ''; $repository = file_get_contents( $root . '/src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupRepository.php' ) ?: ''; oz_pickup_assert( str_contains( $api, "'/v1/delivery-point/list'") && str_contains( $api, "'/v1/delivery-point/info'") && str_contains( $api, "'limit' => 100" ), 'official two-stage read-only pickup contract is required.' ); oz_pickup_assert( str_contains( $importer, 'pickup_pagination_invalid' ) && str_contains( $importer, 'pickup_duplicate_conflict' ) && str_contains( $repository, "state='obsolete'" ), 'snapshot importer must guard pagination, conflicts and atomic activation.' );
echo "Ozon Delivery pickup import smoke passed.\n";
