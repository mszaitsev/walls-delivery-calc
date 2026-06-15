<?php
declare(strict_types=1);

use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\RussianPost\RussianPostDomesticSettings;
use WallsShop\WDC\Core\Autoloader;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

function carrier_adapter_registry_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

final class CarrierAdapterRegistrySmokeAdapter implements CarrierShipmentAdapterInterface {
	public function __construct(
		private string $key,
		private array $presentation,
		private array $label_actions = array()
	) {
	}

	public function carrier_key(): string { return $this->key; }
	public function supports( ShipmentCreateRequest $request ): bool { return $request->carrier_key === $this->key; }
	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array { return array(); }
	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult { return new ShipmentCreateResult( true ); }
	public function presentation(): array { return $this->presentation; }
	public function status_payload( object $order, array $shipment ): array { return array_merge( $shipment, array( 'carrier_key' => $this->key ) ); }
	public function update_status( object $order, string $shipment_key = '' ): array { return array( 'success' => true, 'adapter' => $this->key ); }
	public function attach_manual( object $order, array $payload ): array { return array( 'success' => true, 'adapter' => $this->key ); }
	public function cancel_in_carrier( object $order, string $shipment_key = '' ): array { return array( 'success' => true, 'adapter' => $this->key ); }
	public function remove_from_order( object $order, string $shipment_key = '' ): array { return array( 'success' => true, 'adapter' => $this->key ); }
	public function label_actions( object $order, array $shipment ): array { return $this->label_actions; }
	public function supports_status_auto_sync(): bool { return true; }
	public function tracking_identifier( array $shipment ): string { return (string) ( $shipment['tracking_number'] ?? $shipment['barcode'] ?? $shipment['cdek_number'] ?? '' ); }
	public function auto_sync_throttle_microseconds(): int { return CdekSettings::CARRIER_KEY === $this->key ? 10000 : 0; }
}

$registry = new CarrierShipmentAdapterRegistry(
	array(
		new CarrierAdapterRegistrySmokeAdapter(
			CdekSettings::CARRIER_KEY,
			array(
				'carrier_label' => 'СДЭК',
				'status_title' => 'Статус СДЭК',
				'tracking_label' => 'Номер СДЭК',
			),
			array(
				array(
					'key' => 'download_label',
					'label' => 'Скачать этикетку',
					'type' => 'ajax_download',
					'visible' => true,
				),
			)
		),
		new CarrierAdapterRegistrySmokeAdapter(
			RussianPostDomesticSettings::CARRIER_KEY,
			array(
				'carrier_label' => 'Почта России',
				'status_title' => 'Статус Почты России',
				'tracking_label' => 'Отслеживание',
			)
		),
	)
);

carrier_adapter_registry_assert( $registry->has( CdekSettings::CARRIER_KEY ), 'Registry must contain CDEK adapter.' );
carrier_adapter_registry_assert( $registry->has( RussianPostDomesticSettings::CARRIER_KEY ), 'Registry must contain Russian Post adapter.' );
carrier_adapter_registry_assert( 'СДЭК' === $registry->get( CdekSettings::CARRIER_KEY )->presentation()['carrier_label'], 'CDEK adapter presentation must contain the CDEK label.' );
carrier_adapter_registry_assert( 'Почта России' === $registry->get( RussianPostDomesticSettings::CARRIER_KEY )->presentation()['carrier_label'], 'Russian Post adapter presentation must contain the Russian Post label.' );
carrier_adapter_registry_assert( 'Скачать этикетку' === $registry->get( CdekSettings::CARRIER_KEY )->label_actions( new stdClass(), array( 'cdek_number' => '10280157676' ) )[0]['label'], 'CDEK label action must expose the download label button.' );
carrier_adapter_registry_assert( 10000 === $registry->get( CdekSettings::CARRIER_KEY )->auto_sync_throttle_microseconds(), 'CDEK adapter must keep the 10ms auto-sync throttle.' );
carrier_adapter_registry_assert( 0 === $registry->get( RussianPostDomesticSettings::CARRIER_KEY )->auto_sync_throttle_microseconds(), 'Russian Post adapter must not inherit CDEK throttle.' );

$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
$autosync_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Application/ShipmentStatusAutoSyncService.php' );
$metabox_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/OrderShipmentsMetabox.php' );

carrier_adapter_registry_assert( str_contains( $plugin_source, 'CarrierShipmentAdapterRegistry::class' ), 'Plugin must register CarrierShipmentAdapterRegistry.' );
carrier_adapter_registry_assert( str_contains( $plugin_source, 'new CarrierShipmentAdapterRegistry( array( $this->container->get( RussianPostShipmentAdapter::class ), $this->container->get( CdekShipmentAdapter::class ) ) )' ), 'Plugin registry wiring must include Russian Post and CDEK adapters.' );
carrier_adapter_registry_assert( str_contains( $autosync_source, '$adapter->update_status' ) && ! str_contains( $autosync_source, 'switch ( $carrier_key )' ), 'Auto-sync must dispatch status updates through adapters, not a carrier switch.' );
carrier_adapter_registry_assert( str_contains( $autosync_source, '$adapter->tracking_identifier' ), 'Auto-sync tracking identifiers must come from adapters.' );
carrier_adapter_registry_assert( str_contains( $metabox_source, '$adapter->update_status' ) && str_contains( $metabox_source, '$adapter->cancel_in_carrier' ) && str_contains( $metabox_source, '$adapter->remove_from_order' ) && str_contains( $metabox_source, '$adapter->attach_manual' ), 'Shipment AJAX actions must delegate to carrier adapters.' );
carrier_adapter_registry_assert( ! str_contains( $metabox_source, 'CdekSettings::CARRIER_KEY === $shipment_key && $this->cdek_status_updates' ), 'Shipment AJAX actions must not keep the old CDEK direct dispatch branch.' );

echo "Carrier adapter registry smoke passed\n";
