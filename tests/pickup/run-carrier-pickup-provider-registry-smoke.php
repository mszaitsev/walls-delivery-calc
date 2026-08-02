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

$plugin = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
pickup_registry_assert( str_contains( $plugin, 'CarrierPickupPointProviderRegistry::class' ) && str_contains( $plugin, 'PekPickupPointProvider::class' ), 'Plugin.php must wire PEK pickup provider registry.' );
pickup_registry_assert( ! preg_match( '/CarrierPickupPointProviderRegistry\\(\\s*array\\([^)]*(Cdek|Dpd|Yandex|RussianPost)/s', $plugin ), 'Existing carriers must not be migrated into the new pickup provider registry.' );

$points_rest = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/Rest/PickupPointsRestController.php' );
$checkout_rest = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Pickup/Rest/CheckoutPickupPointRestController.php' );
foreach ( array( $points_rest, $checkout_rest ) as $source ) {
	pickup_registry_assert( ! str_contains( $source, "'pek'" ) && ! str_contains( $source, 'Pek' ), 'Public pickup REST controllers must not branch on PEK.' );
	pickup_registry_assert( ! str_contains( $source, 'CarrierPickupPointProviderRegistry' ), 'Public pickup REST controllers must not receive provider registry yet.' );
}

echo "Carrier pickup provider registry smoke OK\n";
