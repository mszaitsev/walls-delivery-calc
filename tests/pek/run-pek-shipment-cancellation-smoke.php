<?php
declare(strict_types=1);

use WallsShop\WDC\Domain\Status\DeliveryStatus;
use WallsShop\WDC\Infrastructure\Settings\SettingsRepository;
use WallsShop\WDC\Shipments\Pek\PekShipmentButtonPolicy;
use WallsShop\WDC\Shipments\Pek\PekStatusMapping;

function pek_cancel_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$root = dirname( __DIR__, 2 );
defined( 'ABSPATH' ) || define( 'ABSPATH', $root . DIRECTORY_SEPARATOR );
require_once $root . '/src/Core/Autoloader.php';
( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', $root . '/src' ) )->register();

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $key, mixed $default = false ): mixed {
		return $GLOBALS['wdc_pek_cancel_options'][ $key ] ?? $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( string $key, mixed $value, bool $autoload = true ): bool {
		unset( $autoload );
		$GLOBALS['wdc_pek_cancel_options'][ $key ] = $value;
		return true;
	}
}

$service = file_get_contents( $root . '/src/Shipments/Pek/PekShipmentService.php' ) ?: '';
$api = file_get_contents( $root . '/src/Carriers/Pek/Api/PekApiClient.php' ) ?: '';
$all_php = '';
$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src' ) );
foreach ( $iterator as $file ) {
	if ( $file instanceof SplFileInfo && 'php' === $file->getExtension() ) {
		$all_php .= "\n" . ( file_get_contents( $file->getPathname() ) ?: '' );
	}
}

pek_cancel_assert( str_contains( $service, 'order_cancellation' ), 'Cancellation must use /order/cancellation/ wrapper.' );
pek_cancel_assert( str_contains( $api, '/order/cancellation/' ), 'PEK API client must expose order cancellation.' );
pek_cancel_assert( str_contains( $service, 'current_datetime()->getTimestamp()' ) && str_contains( $service, 'DateTimeImmutable::createFromFormat' ), 'Cancellation must use timezone-safe timestamp comparison.' );
pek_cancel_assert( str_contains( $service, '$this->statuses->fetch' ), 'Cancellation must fresh-check status before API call.' );
pek_cancel_assert( str_contains( $service, 'pek_take_on_stock_datetime' ), 'Cancellation must inspect cargo acceptance timestamp.' );
pek_cancel_assert( str_contains( $service, 'is_pre_acceptance_status' ), 'Cancellation must require explicit PEK pre-acceptance allowlist.' );
pek_cancel_assert( str_contains( $service, 'delete_for_carrier' ), 'Successful cancellation must remove local shipment.' );
pek_cancel_assert( ! str_contains( strtolower( $all_php ), 'cancelandreturncargo' ), 'Return API must not be present.' );

$GLOBALS['wdc_pek_cancel_options'] = array();
$mapping = new PekStatusMapping( new SettingsRepository() );
pek_cancel_assert( $mapping->is_pre_acceptance_status( 'Оформлен' ), 'Оформлен must be pre-acceptance cancelable candidate.' );
pek_cancel_assert( ! $mapping->is_pre_acceptance_status( 'UNKNOWN' ), 'UNKNOWN must not be pre-acceptance.' );
pek_cancel_assert( ! $mapping->is_pre_acceptance_status( 'Принят к перевозке' ), 'Accepted cargo must not be pre-acceptance.' );
pek_cancel_assert( ! $mapping->is_pre_acceptance_status( 'Принят на ПВЗ' ), 'PVZ accepted cargo must not be pre-acceptance.' );
$override = PekStatusMapping::default_mapping();
$override['в пути']['pickup'] = DeliveryStatus::CREATED_IN_CARRIER;
$override['аннулировано до приемки груза']['pickup'] = DeliveryStatus::UNKNOWN;
$mapping->save_mapping( $override );
pek_cancel_assert( DeliveryStatus::CREATED_IN_CARRIER === $mapping->map( 'В пути' ) && ! $mapping->is_pre_acceptance_status( 'В пути' ), 'Editable PEK mapping must not make in-transit cargo pre-acceptance cancellable.' );
pek_cancel_assert( DeliveryStatus::UNKNOWN === $mapping->map( 'Аннулировано до приемки груза' ) && $mapping->is_cancelled_status( 'Аннулировано до приемки груза' ), 'Editable PEK mapping must not change immutable cancellation truth.' );

$buttons = new PekShipmentButtonPolicy( $mapping );
$pending = $buttons->resolve( array( 'universal_status_code' => DeliveryStatus::PENDING_CREATION_IN_CARRIER, 'pending_creation_in_carrier' => true ) );
pek_cancel_assert( false === $pending['create'] && true === $pending['manual_attach'] && false === $pending['update'] && false === $pending['cancel'] && true === $pending['remove'], 'Pending PEK shipment must allow manual reconciliation and local remove only.' );

echo "PEK shipment cancellation smoke passed.\n";
