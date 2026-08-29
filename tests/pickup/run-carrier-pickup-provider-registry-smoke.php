<?php
declare(strict_types=1);

define( 'ABSPATH', dirname( __DIR__, 2 ) . '/' );
require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Domain\Pickup\PickupPoint;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderInterface;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointProviderRegistry;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointQuery;
use WallsShop\WDC\Pickup\Providers\CarrierPickupPointSelectionQuery;
use WallsShop\WDC\Pickup\Providers\PickupCargoConstraints;

function pickup_registry_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class PickupRegistrySmokeProvider implements CarrierPickupPointProviderInterface {
	public function __construct( private string $key ) {}
	public function carrier_key(): string { return $this->key; }
	public function search( CarrierPickupPointQuery $query ): array { unset( $query ); return array( new PickupPoint( $this->key, 'p1', 'Address', 'City' ) ); }
	public function resolve_selection( CarrierPickupPointSelectionQuery $query ): ?PickupPoint { return new PickupPoint( $this->key, $query->point_code, 'Address', 'City' ); }
}

$registry = new CarrierPickupPointProviderRegistry( array( new PickupRegistrySmokeProvider( 'pek' ) ) );
pickup_registry_assert( $registry->has( 'pek' ), 'Registry must expose PEK provider.' );
pickup_registry_assert( $registry->get( 'pek' ) instanceof CarrierPickupPointProviderInterface, 'Registry get must return provider.' );
pickup_registry_assert( null === $registry->get( 'unknown' ), 'Unknown provider must return null.' );
pickup_registry_assert( array( 'pek' ) === array_keys( $registry->all() ), 'Registry all must be keyed by carrier.' );

foreach ( array( '', 'bad key', 'ПЭК' ) as $bad_key ) {
	try {
		new CarrierPickupPointProviderRegistry( array( new PickupRegistrySmokeProvider( $bad_key ) ) );
		pickup_registry_assert( false, 'Invalid pickup provider key must be rejected.' );
	} catch ( InvalidArgumentException ) {
	}
}
try {
	new CarrierPickupPointProviderRegistry( array( new PickupRegistrySmokeProvider( 'pek' ), new PickupRegistrySmokeProvider( 'pek' ) ) );
	pickup_registry_assert( false, 'Duplicate pickup provider key must be rejected.' );
} catch ( InvalidArgumentException ) {
}

$coordinate_pair_errors = ( new CarrierPickupPointQuery( 'pek', 0, 'RU', '', 55.0, null, new PickupCargoConstraints(), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 ) )->validate();
pickup_registry_assert( in_array( 'coordinates must contain both latitude and longitude', $coordinate_pair_errors, true ), 'Carrier pickup query must reject incomplete coordinate pair.' );
pickup_registry_assert( array() !== ( new CarrierPickupPointQuery( 'pek', 0, 'RU', '', 91.0, 82.0, new PickupCargoConstraints(), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 ) )->validate(), 'Carrier pickup query must reject invalid latitude.' );
pickup_registry_assert( array() !== ( new CarrierPickupPointQuery( 'pek', 0, 'RU', '', 55.0, 181.0, new PickupCargoConstraints(), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 ) )->validate(), 'Carrier pickup query must reject invalid longitude.' );
pickup_registry_assert( ! property_exists( new CarrierPickupPointSelectionQuery( new CarrierPickupPointQuery( 'pek', 1, 'RU', '', null, null, new PickupCargoConstraints(), CarrierPickupPointQuery::PURPOSE_DESTINATION_PICKUP, 50, 50 ), 'p1' ), 'fresh_validation_required' ), 'Selection query must not expose unused fresh_validation_required flag.' );

$plugin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
pickup_registry_assert( str_contains( $plugin, 'CarrierPickupPointProviderRegistry::class' ) && str_contains( $plugin, 'PekPickupPointProvider::class' ) && str_contains( $plugin, 'OzonDeliveryPickupPointProvider::class' ), 'Plugin.php must wire PEK and Ozon pickup providers through the canonical registry.' );
pickup_registry_assert( ! preg_match( '/CarrierPickupPointProviderRegistry\\(\\s*array\\([^)]*(Cdek|Dpd|Yandex|RussianPost)/s', $plugin ), 'Existing carriers must not be migrated into the new pickup provider registry.' );

$points_rest = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/Rest/PickupPointsRestController.php' );
$checkout_rest = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/Rest/CheckoutPickupPointRestController.php' );
pickup_registry_assert( str_contains( $points_rest, 'CarrierPickupPointProviderRegistry' ) && str_contains( $points_rest, 'CheckoutPickupPointProviderQueryResolver' ) && str_contains( $points_rest, 'registry_points_response' ), 'Public pickup points REST must use registry-backed trusted query context for PEK checkout.' );
pickup_registry_assert( str_contains( $checkout_rest, 'CarrierPickupPointProviderRegistry' ) && str_contains( $checkout_rest, 'save_registry_backed_selection' ) && str_contains( $checkout_rest, 'resolve_selection' ), 'Checkout pickup save REST must fresh-validate registry-backed PEK selections.' );
pickup_registry_assert( ! preg_match( '/CarrierPickupPointProviderRegistry\\(\\s*array\\([^)]*(Cdek|Dpd|Yandex|RussianPost)/s', $plugin ), 'Existing carriers must still not be migrated into the registry.' );
pickup_registry_assert( strpos( $checkout_rest, 'save_registry_backed_selection( $request' ) < strpos( $checkout_rest, "'cdek' === \$carrier" ), 'Registry-backed save must run before legacy browser-payload fallback.' );

echo "Carrier pickup provider registry smoke OK\n";
