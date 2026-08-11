<?php
declare(strict_types=1);

require_once __DIR__ . '/admin-js-bundle-source.php';

use WallsShop\WDC\Carriers\Cdek\CdekSettings;
use WallsShop\WDC\Carriers\Dpd\DpdSettings;
use WallsShop\WDC\Carriers\JetLogistic\JetLogisticSettings;
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
		private array $presentation
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
		new CarrierAdapterRegistrySmokeAdapter(
			DpdSettings::CARRIER_KEY,
			array(
				'carrier_label' => 'DPD',
				'status_title' => 'Статус DPD',
				'tracking_label' => 'Номер DPD',
			)
		),
		new CarrierAdapterRegistrySmokeAdapter(
			JetLogisticSettings::CARRIER_KEY,
			array(
				'carrier_label' => 'Jet Logistic',
				'status_title' => 'Статус Jet Logistic',
				'tracking_label' => 'Номер груза Jet Logistic',
			)
		),
	)
);

carrier_adapter_registry_assert( $registry->has( CdekSettings::CARRIER_KEY ), 'Registry must contain CDEK adapter.' );
carrier_adapter_registry_assert( $registry->has( RussianPostDomesticSettings::CARRIER_KEY ), 'Registry must contain Russian Post adapter.' );
carrier_adapter_registry_assert( $registry->has( DpdSettings::CARRIER_KEY ), 'Registry must contain DPD adapter for manual create preparation.' );
carrier_adapter_registry_assert( $registry->has( JetLogisticSettings::CARRIER_KEY ), 'Registry must contain Jet Logistic adapter for manual attach/status.' );
carrier_adapter_registry_assert( 'СДЭК' === $registry->get( CdekSettings::CARRIER_KEY )->presentation()['carrier_label'], 'CDEK adapter presentation must contain the CDEK label.' );
carrier_adapter_registry_assert( 'Почта России' === $registry->get( RussianPostDomesticSettings::CARRIER_KEY )->presentation()['carrier_label'], 'Russian Post adapter presentation must contain the Russian Post label.' );
carrier_adapter_registry_assert( 'DPD' === $registry->get( DpdSettings::CARRIER_KEY )->presentation()['carrier_label'], 'DPD adapter presentation must contain the DPD label.' );
carrier_adapter_registry_assert( 10000 === $registry->get( CdekSettings::CARRIER_KEY )->auto_sync_throttle_microseconds(), 'CDEK adapter must keep the 10ms auto-sync throttle.' );
carrier_adapter_registry_assert( 0 === $registry->get( RussianPostDomesticSettings::CARRIER_KEY )->auto_sync_throttle_microseconds(), 'Russian Post adapter must not inherit CDEK throttle.' );

$plugin_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Core/Plugin.php' );
$autosync_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Application/ShipmentStatusAutoSyncService.php' );
$payload_builder_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/Ajax/ShipmentAdminCarrierUiPayloadBuilder.php' );
$status_controller_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/Ajax/ShipmentStatusAjaxController.php' );
$removal_controller_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/Ajax/ShipmentRemovalAjaxController.php' );
$manual_controller_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Admin/Ajax/ShipmentManualAttachAjaxController.php' );
$russian_post_adapter_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/RussianPost/RussianPostShipmentAdapter.php' );
$shipments_js = wdc_shipment_admin_js_bundle_source();

carrier_adapter_registry_assert( str_contains( $plugin_source, 'CarrierShipmentAdapterRegistry::class' ), 'Plugin must register CarrierShipmentAdapterRegistry.' );
carrier_adapter_registry_assert( str_contains( $plugin_source, 'new CarrierShipmentAdapterRegistry( array( $this->container->get( RussianPostShipmentAdapter::class ), $this->container->get( CdekShipmentAdapter::class ), $this->container->get( DpdShipmentAdapter::class ), $this->container->get( YandexShipmentAdapter::class ), $this->container->get( JetLogisticShipmentAdapter::class ), $this->container->get( PekShipmentAdapter::class ) ) )' ), 'Plugin registry wiring must include Russian Post, CDEK, DPD, Yandex, Jet and PEK adapters.' );
carrier_adapter_registry_assert( str_contains( $plugin_source, 'new ShipmentCreationService( $this->container->get( OrderShipmentRepository::class ), array( $this->container->get( RussianPostShipmentAdapter::class ), $this->container->get( CdekShipmentAdapter::class ), $this->container->get( DpdShipmentAdapter::class ), $this->container->get( YandexShipmentAdapter::class ), $this->container->get( PekShipmentAdapter::class ) )' ) && str_contains( $plugin_source, 'array( $this->container->get( RussianPostShipmentPersistenceMapper::class ), $this->container->get( CdekShipmentPersistenceMapper::class ), $this->container->get( DpdShipmentPersistenceMapper::class ), $this->container->get( YandexShipmentPersistenceMapper::class ), $this->container->get( PekShipmentPersistenceMapper::class ) )' ) && ! str_contains( $plugin_source, 'new ShipmentCreationService( $this->container->get( OrderShipmentRepository::class ), array( $this->container->get( RussianPostShipmentAdapter::class ), $this->container->get( CdekShipmentAdapter::class ), $this->container->get( DpdShipmentAdapter::class ), $this->container->get( YandexShipmentAdapter::class ) ), $this->container->get( Logger::class ), $this->container->get( CarrierShipmentAdapterRegistry::class ), null' ), 'ShipmentCreationService live-create wiring must include all create adapters, all persistence mappers and no mapper placeholder.' );
carrier_adapter_registry_assert( str_contains( $autosync_source, '$adapter->update_status' ) && ! str_contains( $autosync_source, 'switch ( $carrier_key )' ), 'Auto-sync must dispatch status updates through adapters, not a carrier switch.' );
carrier_adapter_registry_assert( str_contains( $autosync_source, '$adapter->tracking_identifier' ), 'Auto-sync tracking identifiers must come from adapters.' );
carrier_adapter_registry_assert( str_contains( $status_controller_source, '$adapter->update_status' ) && str_contains( $removal_controller_source, '$adapter->cancel_in_carrier' ) && str_contains( $removal_controller_source, '$adapter->remove_from_order' ) && str_contains( $manual_controller_source, '$adapter->attach_manual' ), 'Shipment AJAX actions must delegate to carrier adapters.' );
carrier_adapter_registry_assert( ! str_contains( $payload_builder_source, 'CdekSettings::CARRIER_KEY === $shipment_key && $this->cdek_status_updates' ), 'Shipment AJAX actions must not keep the old CDEK direct dispatch branch.' );
carrier_adapter_registry_assert( str_contains( $payload_builder_source, 'public function carrier_ui_payload' ), 'Shipment AJAX actions must return one fresh carrier UI payload after create/update/cancel/remove/manual attach.' );
carrier_adapter_registry_assert( str_contains( $payload_builder_source, "'has_shipment' => ! empty( \$status['has_shipment'] )" ) && str_contains( $payload_builder_source, "'can_update_status' => ! empty( \$status['can_update_status'] )" ) && str_contains( $payload_builder_source, "'can_cancel' => ! empty( \$status['can_cancel'] )" ) && str_contains( $payload_builder_source, "'can_remove_from_order' => ! empty( \$status['can_remove_from_order'] )" ), 'Carrier UI payload must expose normalized button flags for JS.' );
carrier_adapter_registry_assert( str_contains( $russian_post_adapter_source, "'has_shipment' => array() !== \$shipment" ) && str_contains( $russian_post_adapter_source, "'can_update_status' => array() !== \$shipment" ) && str_contains( $russian_post_adapter_source, "'can_cancel' => \$can_cancel" ) && str_contains( $russian_post_adapter_source, "'can_remove_from_order' => array() !== \$shipment && ! \$can_cancel" ), 'Russian Post adapter status payload must keep update/cancel/remove flags for AJAX responses.' );
$legacy_document_payload_key = 'label_' . 'actions';
carrier_adapter_registry_assert( str_contains( $shipments_js, 'function shipmentStatusFromResponse' ) && str_contains( $shipments_js, "['carrier_key', 'presentation', 'document_actions', 'has_shipment', 'can_create', 'can_attach_manual', 'can_update_status', 'can_cancel', 'can_remove_from_order']" ) && str_contains( $shipments_js, 'documentActions: Array.isArray(statusPayload.document_actions)' ) && ! str_contains( $shipments_js, $legacy_document_payload_key ), 'Shipment JS must normalize adapter UI payload flags and map canonical document_actions payload into documentActions state.' );
carrier_adapter_registry_assert( ! str_contains( $shipments_js, 'isCdek' ) && ! str_contains( $shipments_js, 'isRussianPost' ) && ! str_contains( $shipments_js, "carrier_key === 'cdek'" ) && ! str_contains( $shipments_js, "carrier_key === 'russian_post" ), 'Shipment JS must not branch on CDEK/Russian Post carrier keys for action buttons.' );
$adapter_interface_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/src/Shipments/Contracts/CarrierShipmentAdapterInterface.php' );
carrier_adapter_registry_assert( ! str_contains( $adapter_interface_source, 'document_actions' ), 'CarrierShipmentAdapterInterface must not declare adapter-level document actions.' );
foreach ( array( 'src/Shipments/Cdek/CdekShipmentAdapter.php', 'src/Shipments/Dpd/DpdShipmentAdapter.php', 'src/Shipments/RussianPost/RussianPostShipmentAdapter.php', 'src/Shipments/YandexDelivery/YandexShipmentAdapter.php' ) as $adapter_path ) {
	$adapter_source = (string) file_get_contents( dirname( __DIR__, 2 ) . '/' . $adapter_path );
	carrier_adapter_registry_assert( ! str_contains( $adapter_source, 'function document_actions' ), $adapter_path . ' must not declare adapter-level document actions.' );
}

echo "Carrier adapter registry smoke passed\n";
