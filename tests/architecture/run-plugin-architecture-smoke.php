<?php
declare(strict_types=1);

defined( 'ABSPATH' ) || define( 'ABSPATH', dirname( __DIR__, 2 ) . DIRECTORY_SEPARATOR );

require_once dirname( __DIR__, 2 ) . '/src/Core/Autoloader.php';

( new WallsShop\WDC\Core\Autoloader( 'WallsShop\\WDC\\', dirname( __DIR__, 2 ) . '/src' ) )->register();

use WallsShop\WDC\Domain\Shipment\ShipmentCreateRequest;
use WallsShop\WDC\Domain\Shipment\ShipmentCreateResult;
use WallsShop\WDC\Shipments\Application\CarrierShipmentAdapterRegistry;
use WallsShop\WDC\Shipments\Contracts\CarrierShipmentAdapterInterface;
use WallsShop\WDC\Shipments\Documents\CarrierShipmentDocumentProviderInterface;
use WallsShop\WDC\Shipments\Documents\ShipmentBinaryDocument;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentAction;
use WallsShop\WDC\Shipments\Documents\ShipmentDocumentProviderRegistry;
use WallsShop\WDC\Shipments\Modal\CarrierShipmentModalExtensionInterface;
use WallsShop\WDC\Shipments\Modal\ShipmentModalExtensionRegistry;

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( mixed $value ): string {
		return strtolower( preg_replace( '/[^a-z0-9_\-]/', '', (string) $value ) ?? '' );
	}
}

function plugin_architecture_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

function plugin_architecture_root(): string {
	return dirname( __DIR__, 2 );
}

function plugin_architecture_path( string $relative_path ): string {
	return plugin_architecture_root() . '/' . str_replace( '/', DIRECTORY_SEPARATOR, $relative_path );
}

function plugin_architecture_source( string $relative_path ): string {
	$path = plugin_architecture_path( $relative_path );
	plugin_architecture_assert( is_file( $path ), 'Expected source file does not exist: ' . $relative_path );

	return (string) file_get_contents( $path );
}

/**
 * @return array<int,string>
 */
function plugin_architecture_php_files( string $relative_dir ): array {
	$root = plugin_architecture_path( $relative_dir );
	plugin_architecture_assert( is_dir( $root ), 'Expected source directory does not exist: ' . $relative_dir );
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	$files = array();
	foreach ( $iterator as $file ) {
		if ( $file instanceof SplFileInfo && 'php' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}
	sort( $files );

	return $files;
}

/**
 * @return array<int,string>
 */
function plugin_architecture_classes_in( string $relative_dir ): array {
	$classes = array();
	foreach ( plugin_architecture_php_files( $relative_dir ) as $file ) {
		$source = (string) file_get_contents( $file );
		$namespace = '';
		if ( preg_match( '/namespace\s+([^;]+);/', $source, $namespace_match ) ) {
			$namespace = trim( $namespace_match[1] );
		}
		if ( '' === $namespace ) {
			continue;
		}
		if ( preg_match_all( '/(?<!new\s)(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)\b/', $source, $class_matches ) ) {
			foreach ( $class_matches[1] as $short_name ) {
				$class = $namespace . '\\' . $short_name;
				if ( class_exists( $class ) ) {
					$classes[] = $class;
				}
			}
		}
	}
	sort( $classes );

	return array_values( array_unique( $classes ) );
}

/**
 * @return array<int,string>
 */
function plugin_architecture_implementations( string $interface ): array {
	$classes = array();
	foreach ( plugin_architecture_classes_in( 'src/Shipments' ) as $class ) {
		$reflection = new ReflectionClass( $class );
		if ( ! $reflection->isAbstract() && $reflection->implementsInterface( $interface ) ) {
			$classes[] = $class;
		}
	}
	sort( $classes );

	return $classes;
}

/**
 * @return array<string,bool>
 */
function plugin_architecture_public_methods_for_interface( string $interface ): array {
	$methods = array();
	$reflection = new ReflectionClass( $interface );
	foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
		$methods[ $method->getName() ] = true;
	}

	return $methods;
}

/**
 * Existing guarded adapter hooks that are not official adapter contract methods yet.
 *
 * These exceptions are intentionally explicit: production call sites must not
 * expand the adapter public API whitelist automatically.
 *
 * @return array<class-string,array<string,string>>
 */
function plugin_architecture_adapter_public_api_exceptions(): array {
	return array(
		\WallsShop\WDC\Shipments\Dpd\DpdShipmentAdapter::class => array(
			'begin_registration' => 'DPD lifecycle bootstrap hook used by the create AJAX controller.',
		),
		\WallsShop\WDC\Shipments\YandexDelivery\YandexShipmentAdapter::class => array(
			'create_for_order' => 'Yandex order-aware creation hook used by ShipmentCreationService.',
			'mark_polling_exhausted' => 'Yandex polling exhaustion hook used by the status AJAX controller.',
		),
		\WallsShop\WDC\Shipments\Pek\PekShipmentAdapter::class => array(
			'create_for_order' => 'PEK order-aware creation hook used by ShipmentCreationService for canonical declared value and recipient data.',
		),
	);
}

/**
 * @return array<string,bool>
 */
function plugin_architecture_guarded_adapter_methods(): array {
	$methods = array();
	foreach ( plugin_architecture_php_files( 'src/Shipments' ) as $file ) {
		$source = (string) file_get_contents( $file );
		if ( preg_match_all( '/method_exists\s*\(\s*\$adapter\s*,\s*[\'"]([A-Za-z_][A-Za-z0-9_]*)[\'"]\s*\)/', $source, $matches ) ) {
			foreach ( $matches[1] as $method ) {
				$methods[ $method ] = true;
			}
		}
	}
	ksort( $methods );

	return $methods;
}

function plugin_architecture_source_path_for( ReflectionClass $class ): string {
	$file = $class->getFileName();
	plugin_architecture_assert( is_string( $file ) && '' !== $file, 'Class must have a source file: ' . $class->getName() );
	$root = plugin_architecture_root() . DIRECTORY_SEPARATOR;

	return str_replace( DIRECTORY_SEPARATOR, '/', str_starts_with( $file, $root ) ? substr( $file, strlen( $root ) ) : $file );
}

/**
 * @return array<int,string>
 */
function plugin_architecture_generic_js_files(): array {
	$root = plugin_architecture_path( 'assets/admin/shipments' );
	$files = glob( $root . DIRECTORY_SEPARATOR . '*.js' ) ?: array();
	sort( $files );

	return $files;
}

/**
 * @return array<int,string>
 */
function plugin_architecture_js_files( string $relative_dir ): array {
	$root = plugin_architecture_path( $relative_dir );
	plugin_architecture_assert( is_dir( $root ), 'Expected JS directory does not exist: ' . $relative_dir );
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	$files = array();
	foreach ( $iterator as $file ) {
		if ( $file instanceof SplFileInfo && 'js' === strtolower( $file->getExtension() ) ) {
			$files[] = $file->getPathname();
		}
	}
	sort( $files );

	return $files;
}

function plugin_architecture_remove_js_function( string $source, string $function_name ): string {
	$needle = 'function ' . $function_name . '(';
	$start = strpos( $source, $needle );
	if ( false === $start ) {
		return $source;
	}
	$brace = strpos( $source, '{', $start );
	if ( false === $brace ) {
		return $source;
	}
	$depth = 0;
	$length = strlen( $source );
	for ( $i = $brace; $i < $length; $i++ ) {
		$char = $source[ $i ];
		if ( '{' === $char ) {
			$depth++;
		} elseif ( '}' === $char ) {
			$depth--;
			if ( 0 === $depth ) {
				return substr( $source, 0, $start ) . "\n/* architecture smoke: legacy pickup block intentionally excluded */\n" . substr( $source, $i + 1 );
			}
		}
	}

	return $source;
}

function plugin_architecture_source_for_generic_js_check( string $source, string $relative_path ): string {
	if ( 'assets/admin/shipments/shipment-picker.js' !== $relative_path ) {
		return $source;
	}

	return plugin_architecture_remove_js_function(
		plugin_architecture_remove_js_function( $source, 'pickupPointTitle' ),
		'senderPickupContext'
	);
}

function plugin_architecture_assert_no_carrier_key_branch( string $source, string $label ): void {
	$patterns = array(
		'/\bcarrier(?:_key|Key)?\s*(?:===|!==|==|!=)\s*[\'"][a-z0-9_\-]+[\'"]/',
		'/switch\s*\(\s*[^)]*carrier(?:_key|Key)?[^)]*\)/',
		'/\bcase\s+[\'"][a-z0-9_\-]+[\'"]\s*:/',
	);
	foreach ( $patterns as $pattern ) {
		plugin_architecture_assert( 1 !== preg_match( $pattern, $source ), $label . ' must not branch on carrier keys.' );
	}
}

final class PluginArchitectureSmokeAdapter implements CarrierShipmentAdapterInterface {
	public function __construct( private string $key ) {}
	public function carrier_key(): string { return $this->key; }
	public function supports( ShipmentCreateRequest $request ): bool { return $request->carrier_key === $this->key; }
	public function build_safe_payload_preview( ShipmentCreateRequest $request ): array { unset( $request ); return array(); }
	public function create( ShipmentCreateRequest $request ): ShipmentCreateResult { unset( $request ); return new ShipmentCreateResult( true ); }
	public function presentation(): array { return array(); }
	public function status_payload( object $order, array $shipment ): array { unset( $order ); return $shipment; }
	public function update_status( object $order, string $shipment_key = '' ): array { unset( $order, $shipment_key ); return array( 'success' => true ); }
	public function attach_manual( object $order, array $payload ): array { unset( $order, $payload ); return array( 'success' => true ); }
	public function cancel_in_carrier( object $order, string $shipment_key = '' ): array { unset( $order, $shipment_key ); return array( 'success' => true ); }
	public function remove_from_order( object $order, string $shipment_key = '' ): array { unset( $order, $shipment_key ); return array( 'success' => true ); }
	public function supports_status_auto_sync(): bool { return false; }
	public function tracking_identifier( array $shipment ): string { return (string) ( $shipment['tracking_number'] ?? '' ); }
	public function auto_sync_throttle_microseconds(): int { return 0; }
}

final class PluginArchitectureSmokeProvider implements CarrierShipmentDocumentProviderInterface {
	public function __construct( private string $key ) {}
	public function carrier_key(): string { return $this->key; }
	public function actions( object $order, array $shipment ): array { unset( $order, $shipment ); return array( new ShipmentDocumentAction( 'download_label', 'Download label' ) ); }
	public function download( object $order, array $shipment, string $action_key ): ShipmentBinaryDocument {
		unset( $order, $shipment, $action_key );
		return new ShipmentBinaryDocument( '%PDF-1.4 architecture', 'application/pdf', 'architecture.pdf' );
	}
}

final class PluginArchitectureSmokeModalExtension implements CarrierShipmentModalExtensionInterface {
	public function __construct( private string $key ) {}
	public function carrier_key(): string { return $this->key; }
	public function modal_context( object $order, array $draft ): array { unset( $order, $draft ); return array(); }
	public function render_fields( object $order, array $draft, array $context ): void { unset( $order, $draft, $context ); }
	public function render_pickup_fields( object $order, array $draft, array $context ): void { unset( $order, $draft, $context ); }
	public function render_courier_fields( object $order, array $draft, array $context ): void { unset( $order, $draft, $context ); }
}

$adapter_interface = CarrierShipmentAdapterInterface::class;
$provider_interface = CarrierShipmentDocumentProviderInterface::class;
$adapters = plugin_architecture_implementations( $adapter_interface );
$providers = plugin_architecture_implementations( $provider_interface );

plugin_architecture_assert( array() !== $adapters, 'At least one shipment adapter implementation must be discoverable.' );
plugin_architecture_assert( array() !== $providers, 'At least one shipment document provider implementation must be discoverable.' );

$adapter_contract_methods = plugin_architecture_public_methods_for_interface( $adapter_interface );
$adapter_public_api_exceptions = plugin_architecture_adapter_public_api_exceptions();
plugin_architecture_assert( ! isset( $adapter_contract_methods['document_actions'] ), 'Adapter interface must not contain adapter-level document action method.' );

foreach ( $adapters as $adapter_class ) {
	$reflection = new ReflectionClass( $adapter_class );
	$source = (string) file_get_contents( (string) $reflection->getFileName() );
	$allowed = $adapter_contract_methods;
	foreach ( $reflection->getInterfaceNames() as $interface ) {
		foreach ( plugin_architecture_public_methods_for_interface( $interface ) as $method => $_ ) {
			$allowed[ $method ] = true;
		}
	}
	$parent = $reflection->getParentClass();
	if ( $parent instanceof ReflectionClass ) {
		foreach ( $parent->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
			$allowed[ $method->getName() ] = true;
		}
	}
	foreach ( $adapter_public_api_exceptions[ $adapter_class ] ?? array() as $method => $_reason ) {
		$allowed[ $method ] = true;
		plugin_architecture_assert( $reflection->hasMethod( $method ) && $reflection->getMethod( $method )->isPublic(), $adapter_class . ' adapter public API exception must remain an existing public method: ' . $method );
	}
	foreach ( $adapter_contract_methods as $method => $_ ) {
		plugin_architecture_assert( $reflection->hasMethod( $method ), $adapter_class . ' must implement adapter contract method ' . $method . '.' );
	}
	foreach ( $reflection->getMethods( ReflectionMethod::IS_PUBLIC ) as $method ) {
		if ( $method->isConstructor() || $method->isDestructor() || str_starts_with( $method->getName(), '__' ) ) {
			continue;
		}
		if ( $method->getDeclaringClass()->getName() !== $reflection->getName() ) {
			continue;
		}
		plugin_architecture_assert( isset( $allowed[ $method->getName() ] ), $adapter_class . ' exposes public method outside adapter contract or guarded extension point: ' . $method->getName() );
	}
	plugin_architecture_assert( ! $reflection->hasMethod( 'document_actions' ), $adapter_class . ' must not expose adapter-level document action method.' );
	foreach ( array( 'function document_actions', 'ShipmentDocumentAction', 'download_url', 'admin_post_', 'document_actions' ) as $forbidden ) {
		plugin_architecture_assert( ! str_contains( $source, $forbidden ), plugin_architecture_source_path_for( $reflection ) . ' must not contain document metadata/download pattern: ' . $forbidden );
	}
	foreach ( array( 'save_for_carrier', 'delete_for_carrier', 'OrderShipmentRepository::META_KEY', 'update_meta_data' ) as $forbidden_persistence ) {
		plugin_architecture_assert( ! str_contains( $source, $forbidden_persistence ), plugin_architecture_source_path_for( $reflection ) . ' must not perform direct shipment persistence: ' . $forbidden_persistence );
	}
}

$official_guarded_adapter_methods = $adapter_contract_methods;
foreach ( $adapter_public_api_exceptions as $methods ) {
	foreach ( $methods as $method => $_reason ) {
		$official_guarded_adapter_methods[ $method ] = true;
	}
}
foreach ( plugin_architecture_guarded_adapter_methods() as $method => $_ ) {
	plugin_architecture_assert( isset( $official_guarded_adapter_methods[ $method ] ), 'Guarded adapter method_exists() call must target an official adapter method or an explicit smoke exception: ' . $method );
}

$provider_contract_methods = plugin_architecture_public_methods_for_interface( $provider_interface );
foreach ( $providers as $provider_class ) {
	$reflection = new ReflectionClass( $provider_class );
	foreach ( array( 'carrier_key', 'actions', 'download' ) as $method ) {
		plugin_architecture_assert( isset( $provider_contract_methods[ $method ] ) && $reflection->hasMethod( $method ), $provider_class . ' must implement provider method ' . $method . '.' );
	}
}

$creation_source = plugin_architecture_source( 'src/Shipments/Application/ShipmentCreationService.php' );
plugin_architecture_assert( ! str_contains( $creation_source, 'switch (' ) && ! str_contains( $creation_source, 'case ' ), 'ShipmentCreationService must not contain carrier switch logic.' );
plugin_architecture_assert( 1 !== preg_match( '/\b[A-Za-z0-9_]+Settings::CARRIER_KEY\b/', $creation_source ), 'ShipmentCreationService must not depend on concrete carrier keys.' );
plugin_architecture_assert( str_contains( $creation_source, 'CarrierShipmentAdapterRegistry' ) && str_contains( $creation_source, 'CarrierShipmentPersistenceMapperInterface' ), 'ShipmentCreationService must use adapter registry and persistence mapper contracts.' );

$metabox_source = plugin_architecture_source( 'src/Shipments/Admin/OrderShipmentsMetabox.php' );
$payload_builder_source = plugin_architecture_source( 'src/Shipments/Admin/Ajax/ShipmentAdminCarrierUiPayloadBuilder.php' );
$download_service_source = plugin_architecture_source( 'src/Shipments/Documents/ShipmentDocumentDownloadService.php' );
foreach ( array( 'OrderShipmentsMetabox' => $metabox_source, 'ShipmentAdminCarrierUiPayloadBuilder' => $payload_builder_source ) as $owner => $source ) {
	plugin_architecture_assert( str_contains( $source, 'ShipmentDocumentProviderRegistry' ) && str_contains( $source, '$provider->actions( $order, $shipment )' ) && str_contains( $source, "\$row['download_url'] = \$this->document_downloads->download_url" ), $owner . ' must build document_actions from provider actions and protected URLs.' );
}
plugin_architecture_assert( str_contains( $download_service_source, 'public function download_url' ) && str_contains( $download_service_source, "add_action( 'admin_post_' . self::ACTION" ) && str_contains( $download_service_source, 'current_user_can' ) && str_contains( $download_service_source, 'wp_verify_nonce' ) && str_contains( $download_service_source, '$provider->actions( $order, $shipment )' ) && str_contains( $download_service_source, '$action->visible' ) && str_contains( $download_service_source, '$provider->download( $order, $shipment, $action_key )' ), 'ShipmentDocumentDownloadService must own protected URLs, authorization, visibility re-check, and binary download orchestration.' );

foreach ( array(
	array( new CarrierShipmentAdapterRegistry(), new PluginArchitectureSmokeAdapter( 'arch' ), new PluginArchitectureSmokeAdapter( 'arch' ) ),
	array( new ShipmentDocumentProviderRegistry(), new PluginArchitectureSmokeProvider( 'arch' ), new PluginArchitectureSmokeProvider( 'arch' ) ),
	array( new ShipmentModalExtensionRegistry(), new PluginArchitectureSmokeModalExtension( 'arch' ), new PluginArchitectureSmokeModalExtension( 'arch' ) ),
) as $case ) {
	$registry = $case[0];
	$registry->register( $case[1] );
	try {
		$registry->register( $case[2] );
		plugin_architecture_assert( false, get_class( $registry ) . ' must reject duplicate carrier keys.' );
	} catch ( InvalidArgumentException ) {
	}
}

$plugin_source = plugin_architecture_source( 'src/Core/Plugin.php' );
foreach ( plugin_architecture_php_files( 'src' ) as $file ) {
	$relative = str_replace( '\\', '/', substr( $file, strlen( plugin_architecture_root() ) + 1 ) );
	$source = (string) file_get_contents( $file );
	if ( 'src/Core/Plugin.php' === $relative ) {
		continue;
	}
	plugin_architecture_assert( 1 !== preg_match( '/(?:\$this->container|\$container)->register\s*\(/', $source ), 'Container register() composition wiring using the current container syntax must stay in Plugin.php, found in ' . $relative );
}
plugin_architecture_assert( str_contains( $plugin_source, 'CarrierShipmentAdapterRegistry::class' ) && str_contains( $plugin_source, 'ShipmentDocumentProviderRegistry::class' ) && str_contains( $plugin_source, 'ShipmentModalExtensionRegistry::class' ), 'Composition root must register shipment registries.' );
$carrier_registry_block = substr( $plugin_source, (int) strpos( $plugin_source, 'CarrierRegistry::class' ), 1000 );
plugin_architecture_assert( is_file( plugin_architecture_path( 'src/Carriers/Runtime/PekCarrier.php' ) ) && str_contains( $carrier_registry_block, 'PekCarrier::class' ), 'PEK checkout runtime must register PekCarrier in CarrierRegistry.' );
plugin_architecture_assert( str_contains( $plugin_source, 'PekSettings::class' ) && str_contains( $plugin_source, 'PekApiClient::class' ) && str_contains( $plugin_source, 'PekSenderWarehouseSearchCache::class' ) && str_contains( $plugin_source, 'PekAdminNoticeStore::class' ) && str_contains( $plugin_source, 'PekAdminPage::class' ), 'Plugin.php must own PEK foundation DI wiring.' );
plugin_architecture_assert( is_file( plugin_architecture_path( 'src/Pickup/Providers/CarrierPickupPointProviderInterface.php' ) ) && is_file( plugin_architecture_path( 'src/Pickup/Providers/CarrierPickupPointProviderRegistry.php' ) ), 'Carrier pickup provider contract and registry must exist.' );
plugin_architecture_assert( str_contains( $plugin_source, 'CarrierPickupPointProviderRegistry::class' ) && str_contains( $plugin_source, 'PekPickupPointProvider::class' ), 'Plugin.php must register the pickup provider registry with the PEK provider.' );
plugin_architecture_assert( ! str_contains( $plugin_source, 'CdekPickupPointProvider' ) && ! str_contains( $plugin_source, 'DpdPickupPointProvider' ) && ! str_contains( $plugin_source, 'YandexDeliveryPickupPointProvider' ) && ! str_contains( $plugin_source, 'RussianPostPickupPointProvider' ), 'Stage 2 must not migrate existing carriers into the new pickup provider registry.' );
$selection_query_source = plugin_architecture_source( 'src/Pickup/Providers/CarrierPickupPointSelectionQuery.php' );
plugin_architecture_assert( ! str_contains( $selection_query_source, 'fresh_validation_required' ), 'CarrierPickupPointSelectionQuery must not expose unused fresh_validation_required flag; resolve_selection is always fresh.' );
$pickup_points_rest_source = plugin_architecture_source( 'src/Pickup/Rest/PickupPointsRestController.php' );
$checkout_pickup_rest_source = plugin_architecture_source( 'src/Pickup/Rest/CheckoutPickupPointRestController.php' );
$checkout_orchestrator_source = plugin_architecture_source( 'src/Checkout/Runtime/CheckoutOrchestrator.php' );
$quote_cache_source = plugin_architecture_source( 'src/Checkout/Cache/QuoteCache.php' );
$checkout_validation_source = plugin_architecture_source( 'src/Checkout/WooCommerce/CheckoutValidation.php' );
plugin_architecture_assert( str_contains( $pickup_points_rest_source, 'CheckoutPickupPointProviderQueryResolver' ) && str_contains( $pickup_points_rest_source, 'registry_points_response' ) && str_contains( $pickup_points_rest_source, 'provider_query_resolver' ) && str_contains( $pickup_points_rest_source, '->resolve(' ), 'Public pickup points REST must use trusted server rate context for registry-backed PEK provider search.' );
plugin_architecture_assert( str_contains( $checkout_pickup_rest_source, 'save_registry_backed_selection' ) && str_contains( $checkout_pickup_rest_source, 'resolve_selection' ) && str_contains( $checkout_pickup_rest_source, 'requires_rate_refresh' ) && strpos( $checkout_pickup_rest_source, 'save_registry_backed_selection( $request' ) < strpos( $checkout_pickup_rest_source, "'cdek' === \$carrier" ), 'Checkout pickup save must fresh-validate registry-backed PEK selections before legacy browser-payload fallback.' );
plugin_architecture_assert( str_contains( $checkout_validation_source, 'PekSettings::PICKUP_FAMILY' ) && str_contains( $checkout_validation_source, 'valid_pickup_selection_for_checkout( $family )' ) && ! str_contains( $checkout_validation_source, 'selection_from_posted_fields( $data, $point_id, $point_code, $rate );' . PHP_EOL . "\t\tif ( \$is_pek_family" ), 'Checkout POST must not reconstruct PEK terminal selections from hidden field payloads.' );
plugin_architecture_assert( ! str_contains( $checkout_orchestrator_source, 'PekCarrier' ) && ! str_contains( $checkout_orchestrator_source, "'pek'" ) && str_contains( $checkout_orchestrator_source, 'CarrierQuoteCacheContextProviderInterface' ), 'CheckoutOrchestrator must keep PEK out of special-case branches and consume only the generic optional carrier quote-cache context contract.' );
plugin_architecture_assert( str_contains( $quote_cache_source, 'carrier_context' ) && str_contains( $quote_cache_source, 'pickup_selection_cache_context' ) && str_contains( $quote_cache_source, '$destination->raw_address' ) && str_contains( $quote_cache_source, '$request->package->packaging_weight_g' ), 'QuoteCache must include generic destination, package and pickup-selection fingerprints plus optional carrier context.' );
plugin_architecture_assert( is_file( plugin_architecture_path( 'database/migrations/0048_create_pek_location_mappings.php' ) ) && is_file( plugin_architecture_path( 'database/migrations/0049_create_pek_terminals.php' ) ) && is_file( plugin_architecture_path( 'database/migrations/0050_repair_pek_foundation_schema.php' ) ) && is_file( plugin_architecture_path( 'database/migrations/0051_migrate_pek_mapping_precision_column.php' ) ), 'PEK geography/pickup migrations 0048, 0049, schema integrity recovery migration 0050, and mapping precision compatibility migration 0051 must exist.' );
$pek_mapping_repository_source = plugin_architecture_source( 'src/Carriers/Pek/Geography/PekLocationMappingRepository.php' );
$pek_terminal_repository_source = plugin_architecture_source( 'src/Carriers/Pek/Pickup/PekTerminalRepository.php' );
$pek_schema_integrity_source = plugin_architecture_source( 'src/Carriers/Pek/Installation/PekSchemaIntegrityService.php' );
$pek_location_resolver_source = plugin_architecture_source( 'src/Carriers/Pek/Geography/PekLocationResolver.php' );
$pek_api_client_source = plugin_architecture_source( 'src/Carriers/Pek/Api/PekApiClient.php' );
$pek_destination_request_source = plugin_architecture_source( 'src/Carriers/Pek/Pickup/PekDestinationTerminalRequest.php' );
$pek_terminal_cache_source = plugin_architecture_source( 'src/Carriers/Pek/Pickup/PekDestinationTerminalSearchCache.php' );
	$pek_terminal_service_source = plugin_architecture_source( 'src/Carriers/Pek/Pickup/PekTerminalService.php' );
	$pek_destination_store_source = plugin_architecture_source( 'src/Carriers/Pek/Admin/PekDestinationPickupDiagnosticStore.php' );
	$pek_admin_page_source = plugin_architecture_source( 'src/Carriers/Pek/Admin/PekAdminPage.php' );
	$pek_quote_options_source = plugin_architecture_source( 'src/Carriers/Pek/Quote/PekQuoteOptions.php' );
	$pek_quote_cargo_builder_source = plugin_architecture_source( 'src/Carriers/Pek/Quote/PekQuoteCargoBuilder.php' );
	$pek_quote_request_builder_source = plugin_architecture_source( 'src/Carriers/Pek/Quote/PekQuoteRequestBuilder.php' );
	$pek_quote_response_parser_source = plugin_architecture_source( 'src/Carriers/Pek/Quote/PekQuoteResponseParser.php' );
	$pek_quote_service_source = plugin_architecture_source( 'src/Carriers/Pek/Quote/PekQuoteService.php' );
	$pek_quote_message_sanitizer_source = plugin_architecture_source( 'src/Carriers/Pek/Quote/PekQuoteMessageSanitizer.php' );
	$pek_light_cargo_surcharge_policy_source = plugin_architecture_source( 'src/Carriers/Pek/Quote/PekLightCargoSurchargePolicy.php' );
	$pek_quote_result_source = plugin_architecture_source( 'src/Carriers/Pek/Quote/PekQuoteResult.php' );
	$pek_settings_source = plugin_architecture_source( 'src/Carriers/Pek/PekSettings.php' );
	$pek_quote_store_source = plugin_architecture_source( 'src/Carriers/Pek/Admin/PekQuoteDiagnosticStore.php' );
	$pek_quote_diagnostic_source = plugin_architecture_source( 'src/Carriers/Pek/Admin/PekQuoteDiagnosticService.php' );
	$pek_mapping_migration_source = plugin_architecture_source( 'database/migrations/0048_create_pek_location_mappings.php' );
$pek_terminal_migration_source = plugin_architecture_source( 'database/migrations/0049_create_pek_terminals.php' );
$pek_schema_repair_migration_source = plugin_architecture_source( 'database/migrations/0050_repair_pek_foundation_schema.php' );
$pek_precision_migration_source = plugin_architecture_source( 'database/migrations/0051_migrate_pek_mapping_precision_column.php' );
plugin_architecture_assert( str_contains( $pek_mapping_repository_source, 'function install_schema' ) && str_contains( $pek_terminal_repository_source, 'function install_schema' ), 'PEK repositories must expose explicit install_schema methods for migrations.' );
plugin_architecture_assert( str_contains( $pek_mapping_migration_source, '->install_schema()' ) && str_contains( $pek_terminal_migration_source, '->install_schema()' ), 'PEK schemas must be installed by migrations 0048/0049.' );
plugin_architecture_assert( str_contains( $plugin_source, 'PekSchemaIntegrityService::class' ), 'Plugin.php must own PEK schema integrity service registration.' );
plugin_architecture_assert( str_contains( $pek_schema_repair_migration_source, 'PekSchemaIntegrityService' ) && str_contains( $pek_schema_repair_migration_source, '->repair()' ) && ! str_contains( $pek_schema_repair_migration_source, 'CREATE TABLE' ), 'Migration 0050 must delegate idempotent PEK schema repair and must not duplicate SQL definitions.' );
plugin_architecture_assert( str_contains( $pek_precision_migration_source, 'SHOW COLUMNS FROM' ) && str_contains( $pek_precision_migration_source, 'mapping_precision' ) && str_contains( $pek_precision_migration_source, '`precision`' ) && ! str_contains( $pek_precision_migration_source, 'CREATE TABLE' ), 'Migration 0051 must inspect/backfill mapping_precision compatibility without duplicating CREATE TABLE schema.' );
plugin_architecture_assert( str_contains( $pek_schema_integrity_source, 'SHOW TABLES LIKE %s' ) && str_contains( $pek_schema_integrity_source, 'esc_like' ) && str_contains( $pek_schema_integrity_source, '->install_schema()' ) && str_contains( $pek_schema_integrity_source, 'PEK schema postcondition failed: location mappings table missing.' ) && str_contains( $pek_schema_integrity_source, 'PEK schema postcondition failed: terminals table missing.' ), 'PEK schema integrity service must check table existence safely, install only missing schemas, and verify table-specific postconditions.' );
foreach ( array(
	'0048 migration' => $pek_mapping_migration_source,
	'0049 migration' => $pek_terminal_migration_source,
	'0050 migration' => $pek_schema_repair_migration_source,
	'0051 migration' => $pek_precision_migration_source,
	'PEK schema integrity service' => $pek_schema_integrity_source,
) as $pek_schema_owner => $source ) {
	plugin_architecture_assert( ! preg_match( '/\b(?:DROP|TRUNCATE|DELETE)\s+/i', $source ), $pek_schema_owner . ' must not perform destructive PEK schema recovery.' );
	plugin_architecture_assert( ! str_contains( $source, 'PekApiClient' ) && ! str_contains( $source, 'PekHttpClientInterface' ) && ! str_contains( $source, '/branches/' ), $pek_schema_owner . ' must not call PEK API during migration/schema recovery.' );
}
foreach ( array(
	'PekLocationMappingRepository' => $pek_mapping_repository_source,
	'PekTerminalRepository' => $pek_terminal_repository_source,
) as $repository_name => $repository_source ) {
	plugin_architecture_assert( ! str_contains( $repository_source, 'create_schema_if_needed' ), $repository_name . ' must not keep runtime create_schema_if_needed ownership.' );
	plugin_architecture_assert( ! str_contains( $repository_source, '$this->install_schema' ) && ! str_contains( $repository_source, '->install_schema' ), $repository_name . ' runtime methods must not call install_schema.' );
	plugin_architecture_assert( 1 === substr_count( $repository_source, 'function install_schema' ) && 1 === substr_count( $repository_source, 'dbDelta( $this->schema() )' ), $repository_name . ' must keep dbDelta only inside the explicit install_schema method.' );
	plugin_architecture_assert( str_contains( $repository_source, 'dbDelta unavailable' ) && str_contains( $repository_source, 'throw_on_sql_error' ), $repository_name . ' installer must fail closed on unavailable dbDelta and SQL errors.' );
}
plugin_architecture_assert( ! preg_match( '/^\s*precision\s+/mi', $pek_mapping_repository_source ) && str_contains( $pek_mapping_repository_source, 'mapping_precision varchar(16) NULL' ), 'PEK location mapping physical schema must avoid reserved precision column and use mapping_precision.' );
plugin_architecture_assert( str_contains( $pek_mapping_repository_source, 'domain_row_to_db' ) && str_contains( $pek_mapping_repository_source, 'db_row_to_domain' ) && str_contains( $pek_mapping_repository_source, "\$db_row['mapping_precision']" ) && str_contains( $pek_mapping_repository_source, "unset( \$db_row['precision']" ), 'PEK location mapping repository must translate domain precision to physical mapping_precision for SQL payloads.' );
plugin_architecture_assert( str_contains( $plugin_source, 'run_migrations_safely' ) && str_contains( $plugin_source, 'render_migration_failure_notice' ) && str_contains( $plugin_source, 'Database migration failed.' ) && str_contains( $plugin_source, 'catch ( \\Throwable $exception )' ), 'Plugin boot must handle migration failures with logging/admin notice instead of uncaught site-wide fatal.' );
plugin_architecture_assert( ! str_contains( $pek_mapping_repository_source, 'strtotime(' ) && ! str_contains( $pek_mapping_repository_source, "current_time( 'timestamp'" ) && ! str_contains( $pek_mapping_repository_source, "current_time('timestamp'" ), 'PEK location mapping freshness must not use strtotime or WordPress offset timestamps.' );
plugin_architecture_assert( str_contains( $pek_location_resolver_source, 'pek_invalid_response_country' ) && str_contains( $pek_location_resolver_source, 'pek_unexpected_address_precision' ) && str_contains( $pek_location_resolver_source, 'incomplete_zone_context' ), 'PEK location resolver must keep strict method-specific zone and response country diagnostics.' );
plugin_architecture_assert( str_contains( $pek_location_resolver_source, 'MAPPING_CONTRACT_VERSION = 2' ) && str_contains( $pek_location_resolver_source, 'pek_mapping_contract_version' ) && ! str_contains( $pek_location_resolver_source, 'WDC_VERSION' ), 'PEK location mapping fingerprint must include independent contract version and must not depend on plugin version.' );
plugin_architecture_assert( str_contains( $pek_location_resolver_source, 'persisted_mapping_is_usable' ) && str_contains( $pek_location_resolver_source, 'persisted_address_mapping_is_usable' ) && str_contains( $pek_location_resolver_source, 'persisted_coordinate_mapping_is_usable' ) && str_contains( $pek_location_resolver_source, 'normalized_coordinate' ), 'PEK resolver must structurally validate persisted mappings before fresh hits and stale fallback.' );
plugin_architecture_assert( str_contains( $pek_location_resolver_source, "'address' === \$method" ) && str_contains( $pek_location_resolver_source, "'empty' !== \$coordinate_state" ) && str_contains( $pek_location_resolver_source, "'main_warehouse_id'" ) && str_contains( $pek_location_resolver_source, "'exact' === \$precision" ), 'PEK persisted address mapping validator must reject coordinates and require main warehouse for resolved/near mappings.' );
plugin_architecture_assert( str_contains( $pek_location_resolver_source, "'coordinates' === \$method" ) && str_contains( $pek_location_resolver_source, "\$this->normalized_coordinate( (float) \$location->latitude )" ) && str_contains( $pek_location_resolver_source, "\$this->normalized_coordinate( (float) \$coords['latitude'] )" ), 'PEK persisted coordinate mapping validator must compare persisted coordinates to canonical location coordinates.' );
plugin_architecture_assert( ! str_contains( $pek_location_resolver_source, "\$row['precision']" ) && ! str_contains( $pek_location_resolver_source, "\$row['Precision']" ) && ! str_contains( $pek_location_resolver_source, "\$row['address']" ) && str_contains( $pek_location_resolver_source, "'GeoData'" ) && str_contains( $pek_location_resolver_source, "\$geo['precision']" ) && str_contains( $pek_location_resolver_source, 'pek_missing_address_precision' ), 'PEK address precision must be read only from documented GeoData.precision and must not use top-level address aliases.' );
plugin_architecture_assert( str_contains( $pek_location_resolver_source, 'pek_invalid_findzone_address_geodata' ) && str_contains( $pek_location_resolver_source, 'pek_invalid_findzone_address_object' ) && str_contains( $pek_location_resolver_source, 'pek_invalid_findzone_formatted_address' ) && str_contains( $pek_location_resolver_source, "'' !== \$main_warehouse_id" ), 'PEK location resolver must strictly validate GeoData/Address/formatted and require mainWarehouseId for address exact/near.' );
plugin_architecture_assert( str_contains( $pek_api_client_source, 'expect_find_zone_by_coordinates_response' ) && str_contains( $pek_api_client_source, 'pek_unexpected_findzone_coordinates' ) && str_contains( $pek_api_client_source, 'expect_find_zone_by_address_response' ) && str_contains( $pek_api_client_source, 'pek_unexpected_findzone_address' ), 'PEK API client must enforce typed zone response roots at the typed boundary.' );
plugin_architecture_assert( str_contains( $pek_api_client_source, 'pek_unexpected_destination_nearest_departments' ) && str_contains( $pek_api_client_source, 'array_is_list( $value[\'freeDepartments\']' ) && str_contains( $pek_api_client_source, 'array_is_list( $value[\'paidDepartments\']' ), 'PEK API client must validate documented nearestdepartments collections as JSON lists at the typed boundary.' );
plugin_architecture_assert( str_contains( $pek_api_client_source, 'response_shape' ) && str_contains( $pek_api_client_source, 'failure_stage' ) && str_contains( $pek_api_client_source, 'root_keys' ) && ! str_contains( $pek_api_client_source, 'raw_response' ), 'PEK API client contract exceptions must expose safe response shape/stage diagnostics without raw response.' );
plugin_architecture_assert( str_contains( $pek_destination_request_source, "'address' => trim( \$this->address )" ) && str_contains( $pek_destination_request_source, 'coordinate_string' ) && str_contains( $pek_destination_request_source, 'sprintf( \'%.7F\'' ) && ! str_contains( $pek_destination_request_source, "else {\n\t\t\t\$payload['address']" ), 'PEK destination nearestdepartments request must always include address and add stringified coordinates at the API boundary without coordinate/address exclusivity.' );
plugin_architecture_assert( str_contains( $pek_terminal_cache_source, 'FORMAT_VERSION = 2' ) && str_contains( $pek_terminal_cache_source, 'delete_transient' ) && str_contains( $pek_terminal_cache_source, 'PickupPoint::from_array' ) && str_contains( $pek_terminal_cache_source, 'PekSettings::CARRIER_KEY' ) && str_contains( $pek_terminal_cache_source, 'safe_point_array' ) && str_contains( $pek_terminal_cache_source, 'safe_raw_reference' ), 'PEK destination terminal cache must use format 2 and validate/project cached PEK PickupPoint payloads.' );
plugin_architecture_assert( str_contains( $pek_terminal_service_source, 'pek_destination_address_missing' ) && str_contains( $pek_terminal_service_source, "'payload' => \$request_payload" ) && str_contains( $pek_terminal_service_source, 'has_usable_mapping_coordinates' ) && str_contains( $pek_terminal_service_source, 'schedule_short_work_days' ) && str_contains( $pek_terminal_service_source, 'schedule_holiday_days' ), 'PEK terminal service must fail closed on blank destination address, fingerprint the full request payload and normalize terminal schedules.' );
$forbidden_pek_warehouse_cast = '(string) ( $row[' . "'warehouseId'" . ']';
plugin_architecture_assert( ! str_contains( $pek_terminal_service_source, $forbidden_pek_warehouse_cast ) && str_contains( $pek_terminal_service_source, 'required_text( $row, \'warehouseId\'' ) && str_contains( $pek_terminal_service_source, 'normalize_limit' ) && str_contains( $pek_terminal_service_source, 'pek_destination_terminal_rows_invalid' ) && str_contains( $pek_terminal_service_source, '$this->last_report = array();' ), 'PEK terminal service must strictly validate terminal IDs/limits, reject all-invalid responses, and reset last_report.' );
plugin_architecture_assert( str_contains( $pek_terminal_service_source, 'rejection_reasons' ) && str_contains( $pek_terminal_service_source, 'destination_terminal_normalization' ) && str_contains( $pek_terminal_service_source, 'invalid_row_reason' ), 'PEK terminal service must preserve safe rejection reason diagnostics through normalization failures.' );
plugin_architecture_assert( str_contains( $pek_destination_store_source, 'function clear_for_current_user' ) && str_contains( $pek_destination_store_source, 'sanitize_value' ) && str_contains( $pek_admin_page_source, 'clear_for_current_user();' ) && strpos( $pek_admin_page_source, 'clear_for_current_user();' ) < strpos( $pek_admin_page_source, 'destination_diagnostics->run' ), 'PEK destination diagnostic report must be cleared before a new explicit diagnostic run and sanitized recursively.' );
plugin_architecture_assert( str_contains( $pek_destination_store_source, "'failure_stage'" ) && str_contains( $pek_destination_store_source, "'response_shape'" ) && str_contains( $pek_destination_store_source, "'rejections'" ) && str_contains( $pek_destination_store_source, "'api_error_message'" ) && str_contains( $pek_destination_store_source, "'field_errors'" ) && str_contains( $pek_destination_store_source, 'sanitize_field_errors' ) && str_contains( $pek_destination_store_source, "'raw_error'" ) && str_contains( $pek_destination_store_source, "'authorization'" ) && str_contains( $pek_destination_store_source, "'api_key'" ), 'PEK destination diagnostic store must allowlist safe diagnostic keys including api_error_message/field_errors and keep recursive unsafe-key filtering.' );
plugin_architecture_assert( str_contains( $pek_admin_page_source, 'Код ошибки' ) && str_contains( $pek_admin_page_source, 'Ошибка ПЭК' ) && str_contains( $pek_admin_page_source, 'Ошибки полей ПЭК' ) && str_contains( $pek_admin_page_source, 'Response shape' ) && str_contains( $pek_admin_page_source, 'Rejections' ) && str_contains( $pek_admin_page_source, 'render_destination_named_section' ) && str_contains( $pek_admin_page_source, 'render_destination_field_errors' ) && ! str_contains( $pek_admin_page_source, 'format_report_value( $report[ $section ]' ), 'PEK destination admin report must render named diagnostic sections, api_error_message and escaped field_errors instead of positional array output.' );
$pek_destination_diagnostic_service_source = plugin_architecture_source( 'src/Carriers/Pek/Admin/PekDestinationPickupDiagnosticService.php' );
plugin_architecture_assert( str_contains( $pek_destination_diagnostic_service_source, 'Logger' ) && str_contains( $pek_destination_diagnostic_service_source, 'PEK destination pickup diagnostic failed.' ) && str_contains( $pek_destination_diagnostic_service_source, 'safe_api_error_message' ) && str_contains( $pek_destination_diagnostic_service_source, 'safe_field_errors' ) && str_contains( $pek_destination_diagnostic_service_source, 'field_errors' ) && str_contains( $pek_destination_diagnostic_service_source, 'response_shape' ) && ! str_contains( $pek_destination_diagnostic_service_source, 'raw_response' ), 'PEK destination diagnostic failures must use project Logger with safe structured context and redacted api_error_message/field_errors.' );
plugin_architecture_assert( str_contains( $pek_api_client_source, 'logical_error_message' ) && str_contains( $pek_api_client_source, 'api_error_part' ) && str_contains( $pek_api_client_source, 'extract_safe_field_errors' ) && str_contains( $pek_api_client_source, 'MAX_FIELD_ERRORS = 20' ) && str_contains( $pek_api_client_source, 'MAX_FIELD_MESSAGES = 5' ) && str_contains( $pek_api_client_source, 'MAX_TOTAL_FIELD_MESSAGES = 50' ) && ! str_contains( $pek_api_client_source, "(string) ( \$error['title']" ) && ! str_contains( $pek_api_client_source, "(string) ( \$error['message']" ), 'PEK API client must assemble logical error messages and field_errors without string-casting malformed title/message values or storing raw error objects.' );
plugin_architecture_assert( str_contains( $pek_api_client_source, 'public function calculate_price' ) && str_contains( $pek_api_client_source, 'public function last_response_meta' ) && str_contains( $pek_api_client_source, "'/calculator/calculateprice/' !== \$path" ) && str_contains( $pek_api_client_source, "'endpoint' => \$endpoint" ) && str_contains( $pek_api_client_source, "'method' => \$method" ) && str_contains( $pek_api_client_source, "'http_status' => \$status" ) && str_contains( $pek_api_client_source, 'pek_unexpected_calculate_price_response' ) && str_contains( $pek_api_client_source, 'quote_calculator_logical' ), 'PEK API client must expose typed POST /calculator/calculateprice/, defer calculator root hasError to parser, and expose safe response metadata.' );
plugin_architecture_assert( str_contains( $pek_quote_options_source, "MODE_PICKUP = 'pickup'" ) && str_contains( $pek_quote_options_source, "MODE_COURIER = 'courier'" ) && str_contains( $pek_quote_options_source, 'pek_quote_receiver_warehouse_missing' ) && str_contains( $pek_quote_options_source, 'pek_quote_delivery_coordinates_invalid' ), 'PEK quote options must validate pickup/courier modes, warehouses, delivery address and coordinate pairs.' );
plugin_architecture_assert( str_contains( $pek_quote_request_builder_source, "'currencyCode' => '643'" ) && str_contains( $pek_quote_request_builder_source, "'types' => array( PekSettings::LTL_PRODUCT_TYPE )" ) && str_contains( $pek_quote_request_builder_source, "'isInsurance' => true" ) && str_contains( $pek_quote_request_builder_source, "'whoMakesCalculation' => array( 1, 3 )" ) && str_contains( $pek_quote_request_builder_source, 'sender_warehouse()' ) && ! str_contains( $pek_quote_request_builder_source, 'transportingTypes' ) && ! str_contains( $pek_quote_request_builder_source, 'senderCityId' ) && ! str_contains( $pek_quote_request_builder_source, 'receiverCityId' ) && ! str_contains( $pek_quote_request_builder_source, 'overSize' ), 'PEK quote request builder must use calculator LTL contract, mandatory insurance/counterpart, configured sender warehouse and no deprecated fields.' );
plugin_architecture_assert( str_contains( $pek_quote_cargo_builder_source, '$product_weight_g = max( 0, $package->weight_g )' ) && str_contains( $pek_quote_cargo_builder_source, '$total_weight_g = $package->total_weight_g > 0 ? $package->total_weight_g : $package->get_total_weight_g()' ) && str_contains( $pek_quote_cargo_builder_source, "'isHP' => false" ) && str_contains( $pek_quote_cargo_builder_source, "'sealingPositionsCount' => 0" ) && ! str_contains( $pek_quote_cargo_builder_source, 'LIGHT_CARGO_PRODUCT_WEIGHT_THRESHOLD_G' ) && ! str_contains( $pek_quote_cargo_builder_source, "'isHP' => true" ) && str_contains( $pek_quote_request_builder_source, "'cargo_policy' => \$this->cargo_builder->last_diagnostics()" ) && ! preg_match( '/\b(?:bag|packagingType|packageType)\b/i', $pek_quote_request_builder_source . "\n" . $pek_quote_cargo_builder_source ), 'PEK quote cargo builder must always send isHP=false and sealingPositionsCount=0 while keeping total calculator weight and without undocumented bag aliases.' );
plugin_architecture_assert( str_contains( $pek_light_cargo_surcharge_policy_source, '$package->weight_g' ) && ! str_contains( $pek_light_cargo_surcharge_policy_source, 'total_weight_g' ) && str_contains( $pek_light_cargo_surcharge_policy_source, 'light_cargo_weight_limit_g()' ) && str_contains( $pek_light_cargo_surcharge_policy_source, '$product_weight_g >= $weight_limit_g' ) && str_contains( $pek_light_cargo_surcharge_policy_source, 'light_cargo_bag' ) && str_contains( $pek_light_cargo_surcharge_policy_source, 'light_cargo_sealing' ) && ! preg_match( '/Мешок малый|Пломбировка.*str_contains|serviceType|services_contain/i', $pek_light_cargo_surcharge_policy_source ), 'PEK light-cargo surcharge policy must use product weight, strict lower-than limit, closed surcharge codes and no carrier service-name matching.' );
plugin_architecture_assert( str_contains( $pek_settings_source, 'LIGHT_CARGO_BAG_PRICE_RUB_KEY' ) && str_contains( $pek_settings_source, 'LIGHT_CARGO_SEALING_PRICE_RUB_KEY' ) && str_contains( $pek_settings_source, 'LIGHT_CARGO_WEIGHT_LIMIT_G_KEY' ) && str_contains( $pek_settings_source, "self::LIGHT_CARGO_BAG_PRICE_RUB_KEY => '70'" ) && str_contains( $pek_settings_source, "self::LIGHT_CARGO_SEALING_PRICE_RUB_KEY => '20'" ) && str_contains( $pek_settings_source, 'self::LIGHT_CARGO_WEIGHT_LIMIT_G_KEY => 3000' ), 'PEK settings must own light-cargo surcharge defaults 70/20/3000 without migration.' );
plugin_architecture_assert( str_contains( $pek_quote_result_source, 'carrier_price_kopecks' ) && str_contains( $pek_quote_result_source, 'light_cargo_surcharge_kopecks' ) && str_contains( $pek_quote_result_source, 'with_light_cargo_surcharge' ) && str_contains( $pek_quote_service_source, 'with_light_cargo_surcharge' ) && str_contains( $plugin_source, 'PekLightCargoSurchargePolicy::class' ), 'PEK quote result/service must keep carrier price separate from final adjusted price and wire surcharge policy through DI.' );
plugin_architecture_assert( str_contains( $pek_quote_response_parser_source, 'pek_quote_ltl_transfer_missing' ) && str_contains( $pek_quote_response_parser_source, 'pek_quote_ltl_transfer_duplicate' ) && str_contains( $pek_quote_response_parser_source, "array_key_exists( 'hasError', \$transfer )" ) && str_contains( $pek_quote_response_parser_source, "is_bool( \$transfer['hasError'] )" ) && str_contains( $pek_quote_response_parser_source, '$this->response_meta' ) && str_contains( $pek_quote_response_parser_source, "array_key_exists( 'insuranceTerm', \$item )" ) && str_contains( $pek_quote_response_parser_source, "is_bool( \$item['insuranceTerm'] )" ) && str_contains( $pek_quote_response_parser_source, "! is_string( \$item[ \$key ] )" ) && ! str_contains( $pek_quote_response_parser_source, 'is_scalar( $item[ $key ] )' ) && str_contains( $pek_quote_response_parser_source, "PekSettings::LTL_PRODUCT_TYPE" ) && str_contains( $pek_quote_response_parser_source, 'cost_kopecks' ) && str_contains( $pek_quote_response_parser_source, 'normalize_services' ) && ! str_contains( $pek_quote_response_parser_source, 'raw_response' ), 'PEK quote response parser must strictly select LTL type=3, require transfer hasError, preserve response metadata, require string service text fields, and normalize Boolean insuranceTerm without raw response storage.' );
plugin_architecture_assert( str_contains( $pek_quote_message_sanitizer_source, 'PekCredentials' ) && str_contains( $pek_quote_message_sanitizer_source, 'PekSettings' ) && str_contains( $pek_quote_message_sanitizer_source, 'sanitize_field_name' ) && str_contains( $pek_quote_message_sanitizer_source, 'unknown_field' ) && str_contains( $pek_quote_message_sanitizer_source, 'client_card()' ) && str_contains( $pek_quote_message_sanitizer_source, 'sender_inn()' ) && str_contains( $pek_quote_message_sanitizer_source, 'sender_kpp()' ) && str_contains( $pek_quote_message_sanitizer_source, 'redact_key_value_fragments' ) && str_contains( $pek_quote_message_sanitizer_source, 'base64_encode' ), 'PEK quote message sanitizer must be carrier-owned and redact actual credentials/counterpart values plus key/value credential fragments from messages and field names.' );
plugin_architecture_assert( str_contains( $pek_quote_service_source, 'public function calculate' ) && str_contains( $pek_quote_service_source, 'PekQuoteMessageSanitizer' ) && str_contains( $pek_quote_service_source, 'message_sanitizer->sanitize' ) && str_contains( $pek_quote_service_source, 'message_sanitizer->sanitize_field_message' ) && str_contains( $pek_quote_service_source, 'message_sanitizer->sanitize_field_name' ) && str_contains( $pek_quote_service_source, 'index_by_field' ) && str_contains( $pek_quote_service_source, 'field_error_fields' ) && str_contains( $pek_quote_service_source, 'field_error_count' ) && ! str_contains( $pek_quote_service_source, "'api_error_message' =>" ) && ! str_contains( $pek_quote_service_source, "'field_errors' => \$result->field_errors" ) && ! str_contains( $pek_quote_service_source, 'raw_field' ) && ! str_contains( $pek_quote_service_source, 'original_field' ) && str_contains( $pek_quote_service_source, 'PekQuoteResult' ) && ! str_contains( $pek_quote_service_source, 'DeliveryRate' ) && ! str_contains( $pek_quote_service_source, 'QuoteCache' ), 'PEK quote service must sanitize field names/messages, merge sanitized field errors, minimize logger context, return reusable PekQuoteResult and stay outside checkout DeliveryRate/cache integration.' );
plugin_architecture_assert( str_contains( $pek_quote_store_source, 'wdc_pek_quote_diag_' ) && str_contains( $pek_quote_store_source, 'function result' ) && str_contains( $pek_quote_store_source, 'function services' ) && str_contains( $pek_quote_store_source, 'is_bool( $value )' ) && ! str_contains( $pek_quote_store_source, 'empty( $value )' ) && str_contains( $pek_quote_store_source, "'field_errors'" ) && str_contains( $pek_quote_store_source, "'raw_response'" ) && str_contains( $pek_quote_store_source, "'authorization'" ) && str_contains( $pek_quote_store_source, "'counterpartclientcard'" ), 'PEK quote diagnostic store must be user-scoped, preserve false Boolean values, project services through an allowlist and sanitize raw response/request/credential/counterpart data.' );
plugin_architecture_assert( str_contains( $pek_quote_diagnostic_source, 'diagnostic_address' ) && str_contains( $pek_quote_diagnostic_source, 'CarrierPickupPointSelectionQuery' ) && str_contains( $pek_quote_diagnostic_source, 'main_warehouse_id' ) && str_contains( $pek_quote_diagnostic_source, 'default_planned_datetime' ), 'PEK quote diagnostic must support pickup warehouse fallback/fresh explicit selection, courier address source and sender-timezone planned datetime defaults.' );
plugin_architecture_assert( str_contains( $pek_admin_page_source, 'diagnose_pek_quote' ) && str_contains( $pek_admin_page_source, 'render_quote_diagnostic_report' ) && str_contains( $pek_admin_page_source, 'quote_reports->clear_for_current_user();' ) && strpos( $pek_admin_page_source, 'quote_reports->clear_for_current_user();' ) < strpos( $pek_admin_page_source, 'quote_diagnostics->run' ) && str_contains( $pek_admin_page_source, 'esc_html( (string) $item[\'field\'] )' ), 'PEK admin quote diagnostic must be explicit-action only, clear stale reports before rerun and escape PEK field names.' );
plugin_architecture_assert( ! is_file( plugin_architecture_path( 'database/migrations/0050_create_pek_geography_hardening.php' ) ) && ! is_file( plugin_architecture_path( 'src/Carriers/Pek/Pickup/PekTerminalRowNormalizer.php' ) ), 'PEK hardening must not add unrelated migrations or new production normalizer classes.' );
foreach ( plugin_architecture_php_files( 'src' ) as $file ) {
	$relative = str_replace( '\\', '/', substr( $file, strlen( plugin_architecture_root() ) + 1 ) );
	if ( 'src/Core/Plugin.php' === $relative ) {
		continue;
	}
	$source = (string) file_get_contents( $file );
	plugin_architecture_assert( ! preg_match( '/register\s*\([^)]*Pek[A-Za-z0-9_\\\\]*::class/', $source ), 'PEK container registrations must stay in Plugin.php, found in ' . $relative );
}

$removed_checkout_diagnostic_page_needles = array(
	'Checkout' . 'SimulationPage',
	'wdc-checkout-' . 'simulation',
	'checkout-' . 'simulation.css',
);
foreach ( array( 'src', 'assets/admin' ) as $dir ) {
	$root = plugin_architecture_path( $dir );
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
			continue;
		}
		$source = (string) file_get_contents( $file->getPathname() );
		$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( plugin_architecture_root() ) + 1 ) );
		foreach ( $removed_checkout_diagnostic_page_needles as $needle ) {
			plugin_architecture_assert( ! str_contains( $source, $needle ), 'Removed checkout diagnostic page reference must be absent from ' . $relative );
		}
	}
}

$removed_pickup_standalone_page_needles = array(
	'Pickup' . 'AdminPage',
	'wdc_pickup_' . 'view',
	'page=wdc-platform-' . 'pickup',
	"'wdc-platform-" . "pickup'",
	'"wdc-platform-' . 'pickup"',
	"PAGE_SLUG = 'wdc-platform-" . "pickup'",
);
foreach ( array( 'src', 'assets/admin' ) as $dir ) {
	$root = plugin_architecture_path( $dir );
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
			continue;
		}
		$source = (string) file_get_contents( $file->getPathname() );
		$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( plugin_architecture_root() ) + 1 ) );
		foreach ( $removed_pickup_standalone_page_needles as $needle ) {
			plugin_architecture_assert( ! str_contains( $source, $needle ), 'Removed pickup standalone page reference must be absent from ' . $relative );
		}
	}
}

$checkout_selector_source = plugin_architecture_source( 'src/Checkout/WooCommerce/CheckoutDeliveryTypeSelector.php' );
$checkout_sort_source = (string) file_get_contents( plugin_architecture_path( 'assets/frontend/checkout-sort.js' ) );
$shipping_registrar_source = plugin_architecture_source( 'src/Checkout/WooCommerce/ShippingMethodRegistrar.php' );
plugin_architecture_assert(
	str_contains( $checkout_selector_source, 'wdc-platform-pickup-point' )
	&& str_contains( $checkout_sort_source, 'wdc-platform-pickup-point' )
	&& str_contains( $shipping_registrar_source, 'wdc-platform-pickup-foundation' ),
	'Checkout pickup frontend identifiers must preserve their established class and style handle.'
);

$js_source = '';
foreach ( plugin_architecture_generic_js_files() as $file ) {
	$source = (string) file_get_contents( $file );
	$js_source .= "\n" . $source;
	$relative = str_replace( '\\', '/', substr( $file, strlen( plugin_architecture_root() ) + 1 ) );
	plugin_architecture_assert_no_carrier_key_branch( plugin_architecture_source_for_generic_js_check( $source, $relative ), $relative );
}
plugin_architecture_assert( str_contains( $metabox_source . $payload_builder_source, 'document_actions' ) && str_contains( $js_source, 'documentActions' ), 'Canonical document_actions wire key and documentActions JS state must exist.' );
$legacy_document_payload_key = 'label_' . 'actions';
foreach ( array( 'src', 'tests/shipments', 'tests/architecture' ) as $dir ) {
	foreach ( plugin_architecture_php_files( $dir ) as $file ) {
		$source = (string) file_get_contents( $file );
		plugin_architecture_assert( ! str_contains( $source, $legacy_document_payload_key ), 'Legacy document payload alias must be absent from ' . str_replace( '\\', '/', substr( $file, strlen( plugin_architecture_root() ) + 1 ) ) );
	}
}
foreach ( plugin_architecture_js_files( 'assets/admin/shipments' ) as $file ) {
	$source = (string) file_get_contents( $file );
	plugin_architecture_assert( ! str_contains( $source, $legacy_document_payload_key ), 'Legacy document payload alias must be absent from ' . str_replace( '\\', '/', substr( $file, strlen( plugin_architecture_root() ) + 1 ) ) );
}

$draft_factory_source = plugin_architecture_source( 'src/Shipments/Application/OrderShipmentDraftFactory.php' );
$shipment_creation_source = plugin_architecture_source( 'src/Shipments/Application/ShipmentCreationService.php' );
$shipment_metabox_source = plugin_architecture_source( 'src/Shipments/Admin/OrderShipmentsMetabox.php' );
$pek_request_builder_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentRequestBuilder.php' );
$pek_cargo_builder_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentCargoBuilder.php' );
$pek_recipient_builder_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentRecipientBuilder.php' );
$pek_adapter_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentAdapter.php' );
$pek_service_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentService.php' );
$pek_sms_source = plugin_architecture_source( 'src/Shipments/Pek/PekSmsReleaseAvailabilityService.php' );
$pek_sender_warehouse_source = plugin_architecture_source( 'src/Carriers/Pek/Api/PekSenderWarehouseService.php' );
$pek_sender_resolver_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentSenderWarehouseResolver.php' );
$generic_picker_source = plugin_architecture_source( 'assets/admin/shipments/shipment-picker.js' );
$pek_picker_source = plugin_architecture_source( 'assets/admin/shipments/extensions/pek.js' );
plugin_architecture_assert( str_contains( $draft_factory_source, 'create_pek_request_from_order' ) && str_contains( $draft_factory_source, 'create_pek_request_from_admin_data' ), 'PEK shipment request creation must be wired in the carrier-aware draft factory.' );
plugin_architecture_assert( ! str_contains( $shipment_creation_source, 'PekSettings::CARRIER_KEY' ) && ! str_contains( $shipment_creation_source, "'pek'" ) && ! str_contains( $shipment_creation_source, '"pek"' ), 'ShipmentCreationService must not gain a PEK carrier branch.' );
plugin_architecture_assert( ! str_contains( $shipment_metabox_source, 'PekSettings::CARRIER_KEY' ), 'OrderShipmentsMetabox must not gain a PEK render branch.' );
plugin_architecture_assert( str_contains( $pek_request_builder_source, "'common' => array(" ) && str_contains( $pek_request_builder_source, "'sender' => \$this->sender_payload" ) && str_contains( $pek_request_builder_source, "'cargos' => array(" ), 'PEK preregistration submit payload must use root common/sender/cargos hierarchy.' );
plugin_architecture_assert( ! str_contains( $pek_request_builder_source, "'payer' => 'sender'" ) && ! str_contains( $pek_request_builder_source, '"payer": "sender"' ) && str_contains( $pek_request_builder_source, "'payer' => array( 'type' => 1 )" ), 'PEK services must use documented numeric payer object.' );
plugin_architecture_assert( ! str_contains( $pek_request_builder_source . $pek_cargo_builder_source, "'smsRelease'" ) && ! str_contains( $pek_request_builder_source . $pek_cargo_builder_source, '"smsRelease"' ), 'PEK submit payload must not send invented smsRelease field.' );
plugin_architecture_assert( str_contains( $pek_cargo_builder_source, "'cargoPlaceList' => \$places" ) && ! str_contains( $pek_cargo_builder_source, "'position' =>" ) && ! str_contains( $pek_cargo_builder_source, "'cargoDescription'" ) && ! str_contains( $pek_cargo_builder_source, "'cost' =>" ), 'PEK cargo places and declared cost must use the official cargo common shape without legacy aliases.' );
plugin_architecture_assert( str_contains( $pek_recipient_builder_source, "'personPhones' => array(" ) && str_contains( $pek_recipient_builder_source, "'individual' => array_filter" ) && str_contains( $pek_recipient_builder_source, "'addressStock'" ) && ! str_contains( $pek_recipient_builder_source, "'identityCard'" ) && ! str_contains( $pek_recipient_builder_source, "'passport'" ) && ! str_contains( $pek_recipient_builder_source, "'name' => \$name" ), 'PEK physical receiver must use documented individual/personPhones/addressStock shape without passport aliases.' );
plugin_architecture_assert( str_contains( $pek_adapter_source, "'documentId'" ) && str_contains( $pek_adapter_source, "'cargoCode'" ) && ! str_contains( $pek_adapter_source, "'cargoBarCode'" ) && ! str_contains( $pek_adapter_source, "'positionBarCodes'" ), 'PEK create parser must use preregistration response fixture fields, not cargos/status aliases.' );
plugin_architecture_assert( str_contains( $pek_sender_warehouse_source, 'function validate_snapshot' ) && str_contains( $pek_sender_resolver_source, 'validate_snapshot' ) && ! str_contains( $pek_sender_resolver_source, 'validate_and_select' ), 'PEK shipment sender warehouse validation must be fresh and non-mutating.' );
plugin_architecture_assert( str_contains( $generic_picker_source, 'window.wdcShipmentPickupPicker' ) && str_contains( $pek_picker_source, 'window.wdcShipmentPickupPicker' ) && str_contains( $pek_picker_source, 'picker.open(form' ) && ! str_contains( $generic_picker_source, "carrier === 'pek'" ) && ! str_contains( $pek_picker_source, 'wdc:shipment-pickup-search-open' ), 'PEK sender warehouse picker must consume a working generic picker API without a generic PEK branch.' );
plugin_architecture_assert( str_contains( $pek_request_builder_source, 'PekShipmentDestinationResolver' ) && str_contains( $pek_request_builder_source, 'PekShipmentProductWeightResolver' ), 'PEK preview/create path must run fresh destination and product-weight resolvers.' );
plugin_architecture_assert( str_contains( $pek_sms_source, "specialCondition'") && str_contains( $pek_sms_source, 'CODMaxSum' ) && str_contains( $pek_sms_source, 'MoneyParser::numeric_to_kopecks' ), 'PEK SMS availability must scope CODMaxSum to the SMS special-condition row and parse money strictly.' );
plugin_architecture_assert( str_contains( $pek_service_source, 'ShipmentActualCostService' ) && str_contains( $pek_service_source, 'apply_carrier_cost' ), 'PEK manual attach must merge actual cost through the shared service.' );
plugin_architecture_assert( str_contains( $pek_service_source, '$this->statuses->fetch' ) && str_contains( $pek_service_source, 'pek_take_on_stock_datetime' ), 'PEK cancellation must fresh-check acceptance before API cancellation.' );
foreach ( plugin_architecture_php_files( 'src' ) as $file ) {
	$relative = str_replace( '\\', '/', substr( $file, strlen( plugin_architecture_root() ) + 1 ) );
	$source = (string) file_get_contents( $file );
	plugin_architecture_assert( ! str_contains( strtolower( $source ), 'cancelandreturncargo' ), 'PEK return API must not be present in production source: ' . $relative );
	plugin_architecture_assert( ! str_contains( $source, 'pek_actual_cost_' ), 'PEK actual cost must use shared canonical fields only: ' . $relative );
}
plugin_architecture_assert( ! is_dir( plugin_architecture_path( 'src/Shipments/Pek/Storage' ) ) && ! is_file( plugin_architecture_path( 'database/migrations/0060_create_pek_shipments.php' ) ), 'PEK shipment correction must not add shipment storage or PEK shipment migrations.' );

foreach ( array( 'admin_post_cdek_barcode_pdf', 'admin_post_dpd_documents_zip', 'admin_post_yandex_label_pdf', 'ACTION_CDEK_BARCODE_PDF', 'ACTION_DPD_DOCUMENTS_ZIP', 'ACTION_YANDEX_LABEL_PDF' ) as $old_handler ) {
	foreach ( plugin_architecture_php_files( 'src' ) as $file ) {
		plugin_architecture_assert( ! str_contains( (string) file_get_contents( $file ), $old_handler ), 'Old per-carrier document handler must be absent: ' . $old_handler );
	}
}

$docs_readme = plugin_architecture_source( 'docs/README.md' );
plugin_architecture_assert( preg_match_all( '/\[[^\]]+\]\(([^)]+\.md)\)/', $docs_readme, $doc_matches ) > 0, 'docs/README.md must link canonical markdown documents.' );
foreach ( $doc_matches[1] as $doc_link ) {
	if ( str_starts_with( $doc_link, 'http' ) ) {
		continue;
	}
	$doc_path = plugin_architecture_path( 'docs/' . $doc_link );
	plugin_architecture_assert( is_file( $doc_path ), 'Canonical docs link must exist: docs/' . $doc_link );
}

$plugin_main = plugin_architecture_source( 'walls-delivery-calc.php' );
plugin_architecture_assert( 1 === preg_match( '/Version:\s*([0-9]+\.[0-9]+\.[0-9]+)/', $plugin_main, $header_match ), 'Plugin header version must be present.' );
plugin_architecture_assert( 1 === preg_match( "/define\(\s*'WDC_VERSION'\s*,\s*'([0-9]+\.[0-9]+\.[0-9]+)'\s*\)/", $plugin_main, $constant_match ), 'WDC_VERSION constant must be present.' );
plugin_architecture_assert( $header_match[1] === $constant_match[1], 'Plugin header version and WDC_VERSION must match.' );

$order_recalculation_controller = plugin_architecture_source( 'src/Orders/Admin/OrderDeliveryRecalculationAdminController.php' );
foreach ( array(
	'new SettingsRepository',
	'new RussianPostPickupPointRepository',
	'new OrderDeliveryAddressNormalizationService',
	'new OrderDeliveryReplacementService',
	'new DeliveryDateFormatter',
	'new OrderShipmentRepository',
	'new YandexDeliveryCheckoutPickupPointFormatter',
	'new RussianPostPickupPointTypeSettings',
	'new DpdPickupPointScheduleFormatter',
) as $forbidden_controller_new ) {
	plugin_architecture_assert( ! str_contains( $order_recalculation_controller, $forbidden_controller_new ), 'Order delivery recalculation controller must not self-construct dependency: ' . $forbidden_controller_new );
}

$calculation_builder_source = plugin_architecture_source( 'src/Orders/Application/DeliveryCalculationDataBuilder.php' );
$checkout_persister_source = plugin_architecture_source( 'src/Checkout/WooCommerce/OrderShippingMetaPersister.php' );
$replacement_service_source = plugin_architecture_source( 'src/Orders/Application/OrderDeliveryReplacementService.php' );
plugin_architecture_assert( str_contains( $calculation_builder_source, 'function lead_time_audit_lines' ), 'DeliveryCalculationDataBuilder must own lead-time audit formatting.' );
plugin_architecture_assert( ! str_contains( $checkout_persister_source, 'function lead_time_audit_lines' ) && ! str_contains( $replacement_service_source, 'function lead_time_audit_lines' ), 'Checkout/admin persistence services must not duplicate lead-time audit formatting.' );
plugin_architecture_assert( str_contains( $calculation_builder_source, 'private RuleFormulaFormatter $rule_formula_formatter' ), 'DeliveryCalculationDataBuilder must receive RuleFormulaFormatter through constructor DI.' );
plugin_architecture_assert( ! str_contains( $calculation_builder_source, 'new RuleFormulaFormatter' ), 'DeliveryCalculationDataBuilder must not construct RuleFormulaFormatter inline.' );

$rules_admin_source = plugin_architecture_source( 'src/Rules/Admin/RulesAdminPage.php' );
plugin_architecture_assert( ! preg_match( '/dpd|yandex_delivery|cdek|russian_post/i', $rules_admin_source ), 'RulesAdminPage must stay carrier-agnostic for service simulation.' );

$delivery_services_admin_source = plugin_architecture_source( 'src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
plugin_architecture_assert( str_contains( $delivery_services_admin_source, 'simulate_runtime_carrier_service_rules' ) && str_contains( $delivery_services_admin_source, 'DpdQuoteCarrier' ) && str_contains( $delivery_services_admin_source, 'YandexDeliveryCarrier' ), 'DPD and Yandex rule simulation must be wired through the shared service simulation runner.' );

$actual_cost_ajax_source = plugin_architecture_source( 'src/Shipments/Admin/Ajax/ShipmentActualCostAjaxController.php' );
$shipment_metabox_source = plugin_architecture_source( 'src/Shipments/Admin/OrderShipmentsMetabox.php' );
$shipment_events_source = plugin_architecture_source( 'assets/admin/shipments/shipment-events.js' );
$shipment_status_source = plugin_architecture_source( 'assets/admin/shipments/shipment-status.js' );
plugin_architecture_assert( str_contains( $actual_cost_ajax_source, 'handle_save' ) && str_contains( $actual_cost_ajax_source, 'handle_clear' ) && str_contains( $shipment_metabox_source, 'wdc_save_shipment_actual_cost' ) && str_contains( $shipment_metabox_source, 'wdc_clear_shipment_actual_cost' ), 'Manual actual shipment cost AJAX controller must live in the common shipment namespace.' );
plugin_architecture_assert( str_contains( $shipment_events_source, 'data-wdc-save-actual-cost' ) && str_contains( $shipment_events_source, 'data-wdc-clear-actual-cost' ) && str_contains( $shipment_status_source, 'data-wdc-shipment-actual-cost-control' ) && str_contains( $shipment_status_source, 'has_actual_cost' ), 'Common shipment JS must own manual actual cost controls.' );
plugin_architecture_assert( ! str_contains( $shipment_status_source, 'data-wdc-actual-cost-state' ) && ! str_contains( $shipment_status_source, 'data-wdc-actual-cost-source' ), 'Common shipment JS must not render duplicated actual-cost state/source rows.' );
$shipment_payload_builder_source = plugin_architecture_source( 'src/Shipments/Admin/Ajax/ShipmentAdminCarrierUiPayloadBuilder.php' );
plugin_architecture_assert( str_contains( $shipment_metabox_source, 'ShipmentActualCostResolver' ) && str_contains( $shipment_metabox_source, 'enrich_status_payload' ), 'OrderShipmentsMetabox must use the shared actual-cost status presenter for initial render.' );
plugin_architecture_assert( str_contains( $shipment_payload_builder_source, 'ShipmentActualCostResolver' ) && str_contains( $shipment_payload_builder_source, 'enrich_status_payload' ), 'ShipmentAdminCarrierUiPayloadBuilder must use the shared actual-cost status presenter for AJAX payloads.' );
plugin_architecture_assert( ! str_contains( $shipment_payload_builder_source, 'with_actual_cost_defaults' ) && ! preg_match( '/private\s+function\s+positive_int_or_null/', $shipment_payload_builder_source ) && ! preg_match( '/private\s+function\s+positive_int_or_null/', $shipment_metabox_source ), 'Admin shipment UI must not keep local actual-cost normalization helpers.' );
plugin_architecture_assert( 1 === preg_match( '/private\s+function\s+status_payload_for_carrier\s*\([^)]*array\s+\$shipment[^)]*\).*?public\s+function\s+carrier_ui_payload/s', $shipment_payload_builder_source, $status_payload_method_match ) && ! str_contains( $status_payload_method_match[0], 'find_by_carrier(' ) && ! str_contains( $status_payload_method_match[0], 'carrier_adapter(' ) && ! str_contains( $status_payload_method_match[0], 'adapter->status_payload(' ), 'ShipmentAdminCarrierUiPayloadBuilder fallback status payload must use the selected shipment snapshot and must not contain adapter dispatch.' );
plugin_architecture_assert( str_contains( $shipment_payload_builder_source, '$adapter = $this->carrier_adapter( $carrier_key )' ) && str_contains( $shipment_payload_builder_source, '? $adapter->status_payload( $order, $shipment )' ) && str_contains( $shipment_payload_builder_source, ': $this->status_payload_for_carrier( $order, $carrier_key, $shipment )' ), 'ShipmentAdminCarrierUiPayloadBuilder carrier_ui_payload() must own the adapter/fallback dispatch.' );
$actual_cost_legacy_button_text = 'Очистить ' . 'ручную';
$actual_cost_legacy_message_text = 'Ручная фактическая стоимость ' . 'очищена';
plugin_architecture_assert( ! str_contains( $shipment_metabox_source, $actual_cost_legacy_button_text ) && ! str_contains( $actual_cost_ajax_source, $actual_cost_legacy_message_text ), 'Actual cost clear wording must apply to any source, not only manual values.' );

$actual_cost_production_sources = array();
foreach ( plugin_architecture_php_files( 'src' ) as $file ) {
	$relative = str_replace( '\\', '/', substr( $file, strlen( plugin_architecture_root() ) + 1 ) );
	$actual_cost_production_sources[ $relative ] = (string) file_get_contents( $file );
}
foreach ( $actual_cost_production_sources as $relative => $source ) {
	foreach ( array(
		'?ShipmentActualCostResolver',
		'ShipmentActualCostResolver|null',
		'?ShipmentActualCostService',
		'ShipmentActualCostService|null',
		'?ShipmentActualCostComparisonService',
		'ShipmentActualCostComparisonService|null',
		'?ShipmentBaseApiCostResolver',
		'ShipmentBaseApiCostResolver|null',
	) as $forbidden_actual_cost_dependency ) {
		plugin_architecture_assert( ! str_contains( $source, $forbidden_actual_cost_dependency ), 'Actual-cost production dependency must not be nullable/fallback in ' . $relative . ': ' . $forbidden_actual_cost_dependency );
	}
	if ( 'src/Core/Plugin.php' !== $relative ) {
		foreach ( array(
			'new ShipmentActualCostResolver',
			'new ShipmentActualCostService',
			'new ShipmentActualCostComparisonService',
			'new ShipmentBaseApiCostResolver',
		) as $forbidden_actual_cost_new ) {
			plugin_architecture_assert( ! str_contains( $source, $forbidden_actual_cost_new ), 'Actual-cost service/resolver must only be built in Plugin.php, not in ' . $relative . ': ' . $forbidden_actual_cost_new );
		}
	}
}
plugin_architecture_assert( str_contains( $actual_cost_production_sources['src/Core/Plugin.php'] ?? '', 'ShipmentActualCostResolver::class' ) && str_contains( $actual_cost_production_sources['src/Core/Plugin.php'] ?? '', 'ShipmentActualCostService::class' ), 'Plugin.php must own actual-cost service/resolver registrations.' );

$analytics_sources = array();
foreach ( array( 'src/Shipments/Analytics', 'src/Shipments/Admin/ShipmentCostAnalyticsAdminSection.php' ) as $analytics_path ) {
	$absolute = plugin_architecture_path( $analytics_path );
	if ( is_dir( $absolute ) ) {
		foreach ( plugin_architecture_php_files( $analytics_path ) as $file ) {
			$analytics_sources[ str_replace( '\\', '/', substr( $file, strlen( plugin_architecture_root() ) + 1 ) ) ] = (string) file_get_contents( $file );
		}
	} elseif ( is_file( $absolute ) ) {
		$analytics_sources[ $analytics_path ] = (string) file_get_contents( $absolute );
	}
}
plugin_architecture_assert( array() !== $analytics_sources, 'Shipment cost analytics subsystem must exist.' );
foreach ( $analytics_sources as $relative => $source ) {
	foreach ( array( 'CdekSettings::CARRIER_KEY', 'DpdSettings::CARRIER_KEY', 'YandexDeliverySettings::CARRIER_KEY', 'RussianPostDomesticSettings::CARRIER_KEY' ) as $forbidden_carrier_constant ) {
		plugin_architecture_assert( ! str_contains( $source, $forbidden_carrier_constant ), 'Analytics must not hardcode carrier constants in ' . $relative );
	}
	foreach ( array( 'CdekApiClient', 'DpdApiClient', 'YandexDeliveryApiClient', 'RussianPostOtpravkaApiClient', 'RussianPostTrackingApiClient' ) as $forbidden_api_client ) {
		plugin_architecture_assert( ! str_contains( $source, $forbidden_api_client ), 'Analytics must not depend on carrier API clients in ' . $relative );
	}
	foreach ( array( 'save_for_carrier', 'update_meta_data', '->save(', 'apply_carrier_cost', 'manual_set', '->clear(' ) as $forbidden_write ) {
		plugin_architecture_assert( ! str_contains( $source, $forbidden_write ), 'Analytics must be read-only in ' . $relative . ': ' . $forbidden_write );
	}
	plugin_architecture_assert( ! preg_match( '/switch\s*\([^)]*carrier/i', $source ) && ! preg_match( '/match\s*\([^)]*carrier/i', $source ) && ! preg_match( '/carrier_key\s*={2,3}\s*[\'"][a-z0-9_\-]+[\'"]/i', $source ), 'Analytics must not branch by concrete carrier key in ' . $relative );
	plugin_architecture_assert( ! str_contains( $source, 'wp_posts' ) && ! str_contains( $source, 'wp_postmeta' ), 'Analytics must not depend on legacy order SQL tables in ' . $relative );
}
plugin_architecture_assert( str_contains( $analytics_sources['src/Shipments/Admin/ShipmentCostAnalyticsAdminSection.php'] ?? '', 'carrier_options' ), 'Analytics admin section must use registry-driven carrier options.' );
$analytics_query_source = $analytics_sources['src/Shipments/Analytics/ShipmentCostAnalyticsQuery.php'] ?? '';
plugin_architecture_assert( ! preg_match( '/[\'"]limit[\'"]\s*=>\s*-1/', $analytics_query_source ), 'Shipment cost analytics query must not request unlimited orders.' );
plugin_architecture_assert( ! preg_match( '/[\'"]return[\'"]\s*=>\s*[\'"]objects[\'"]/', $analytics_query_source ), 'Shipment cost analytics query must not request full order objects for the range scan.' );
plugin_architecture_assert( ! str_contains( $analytics_query_source, 'wc_get_orders' ) && ! str_contains( $analytics_query_source, 'function batches' ), 'Shipment cost analytics query must use the read-model table, not WooCommerce order scans.' );
$analytics_service_source = $analytics_sources['src/Shipments/Analytics/ShipmentCostAnalyticsService.php'] ?? '';
plugin_architecture_assert( ! str_contains( $analytics_service_source, 'order_batch_size' ) && ! str_contains( $analytics_service_source, 'function all_rows' ) && ! str_contains( $analytics_service_source, 'usort(' ) && ! str_contains( $analytics_service_source, 'array_slice(' ), 'Shipment cost analytics service must not keep the old runtime scan/sort/pagination pipeline.' );
$analytics_builder_source = $analytics_sources['src/Shipments/Analytics/ShipmentCostAnalyticsRecordBuilder.php'] ?? '';
plugin_architecture_assert( str_contains( $analytics_builder_source, 'OrderAnalyticsShipmentSelector' ), 'Shipment cost analytics record builder must use the selected-shipment selector.' );
plugin_architecture_assert( isset( $analytics_sources['src/Shipments/Analytics/Storage/ShipmentCostAnalyticsRepository.php'], $analytics_sources['src/Shipments/Analytics/Storage/ShipmentCostAnalyticsTable.php'], $analytics_sources['src/Shipments/Analytics/ShipmentCostAnalyticsIndexer.php'] ), 'Shipment cost analytics must have table, repository, and indexer production owners.' );
$plugin_source = (string) file_get_contents( plugin_architecture_path( 'src/Core/Plugin.php' ) );
foreach ( array( 'before_delete_' . 'post', 'trashed_' . 'post', 'untrashed_' . 'post' ) as $generic_post_hook ) {
	plugin_architecture_assert( ! str_contains( $plugin_source, $generic_post_hook ), 'Shipment cost analytics must not register generic WordPress post lifecycle hooks: ' . $generic_post_hook );
}
$analytics_scan_source = implode( "\n", $analytics_sources );
$forbidden_rebuild_word = 'back' . 'fill';
foreach ( array( $forbidden_rebuild_word, 'rebuild ' . 'analytics', 'analytics ' . 'import' ) as $forbidden_rebuild ) {
	plugin_architecture_assert( ! str_contains( strtolower( $analytics_scan_source ), $forbidden_rebuild ), 'Shipment cost analytics must not implement historical rebuild/import flow.' );
}

$rp_cost_legacy_key = 'russian_post_' . 'actual_cost_';
$actual_cost_legacy_source = 'legacy_' . 'import';
foreach ( array( 'src', 'tests', 'docs' ) as $legacy_scan_dir ) {
	$directory = plugin_architecture_path( $legacy_scan_dir );
	$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $iterator as $file ) {
		if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
			continue;
		}
		$relative = str_replace( '\\', '/', substr( $file->getPathname(), strlen( plugin_architecture_root() ) + 1 ) );
		$source = (string) file_get_contents( $file->getPathname() );
		plugin_architecture_assert( ! str_contains( $source, $rp_cost_legacy_key ), 'Russian Post legacy actual-cost fields must not exist in ' . $relative );
		plugin_architecture_assert( ! str_contains( $source, $actual_cost_legacy_source ), 'Legacy actual-cost source must not exist in ' . $relative );
	}
}

foreach ( array( 'src', 'tests' ) as $actual_cost_dir ) {
	foreach ( plugin_architecture_php_files( $actual_cost_dir ) as $file ) {
		$relative = str_replace( '\\', '/', substr( $file, strlen( plugin_architecture_root() ) + 1 ) );
		if ( str_contains( $relative, 'run-russian-post' ) ) {
			continue;
		}
		$source = (string) file_get_contents( $file );
		plugin_architecture_assert( ! preg_match( '/(cdek|dpd|yandex)_actual_(cost|price)/', $source ), 'Carrier-prefixed actual cost key must not exist in ' . $relative );
	}
}

$manifest_path = 'tests/shipments/regression/shipment-regression-manifest.php';
$manifest = require plugin_architecture_path( $manifest_path );
plugin_architecture_assert( is_array( $manifest ), 'Regression manifest must return an array.' );
$registered = false;
foreach ( $manifest as $id => $entry ) {
	if ( is_array( $entry ) && 'tests/architecture/run-plugin-architecture-smoke.php' === (string) ( $entry['path'] ?? '' ) ) {
		$registered = true;
		plugin_architecture_assert( in_array( 'framework', (array) ( $entry['groups'] ?? array() ), true ) || in_array( 'architecture', (array) ( $entry['groups'] ?? array() ), true ), 'Plugin architecture smoke must be in framework or architecture group.' );
		plugin_architecture_assert( 'framework.plugin-architecture' === (string) $id || str_contains( (string) $id, 'architecture' ), 'Plugin architecture smoke manifest id must be architecture-oriented.' );
	}
}
plugin_architecture_assert( $registered, 'Plugin architecture smoke must be registered in ' . $manifest_path . '.' );

$jet_key = 'jet_' . 'logistic';
$shipment_creation_source = (string) file_get_contents( plugin_architecture_path( 'src/Shipments/Application/ShipmentCreationService.php' ) );
plugin_architecture_assert( ! str_contains( $shipment_creation_source, $jet_key ) && ! str_contains( $shipment_creation_source, 'JetLogistic' ), 'Jet Logistic must not add carrier persistence or create-flow branching to ShipmentCreationService.' );
$generic_shipment_sources = array();
foreach ( array( 'src/Shipments/Application', 'src/Shipments/Admin', 'src/Shipments/Storage', 'src/Shipments/Documents', 'src/Shipments/Modal' ) as $path ) {
	foreach ( plugin_architecture_php_files( $path ) as $file ) {
		$relative = str_replace( '\\', '/', substr( $file, strlen( plugin_architecture_root() ) + 1 ) );
		$generic_shipment_sources[ $relative ] = (string) file_get_contents( $file );
	}
}
foreach ( $generic_shipment_sources as $relative => $source ) {
	plugin_architecture_assert( ! str_contains( $source, $jet_key ) && ! str_contains( $source, 'JetLogistic' ), 'Generic Shipment Framework must not branch on Jet Logistic in ' . $relative );
	if ( in_array( $relative, array( 'src/Shipments/Application/OrderShipmentDraftFactory.php', 'src/Shipments/Admin/Ajax/ShipmentAddressAjaxController.php' ), true ) ) {
		continue;
	}
	plugin_architecture_assert( ! str_contains( $source, "'pek'" ) && ! str_contains( $source, 'Pek' ), 'PEK foundation must not be registered or branched in generic Shipment Framework source ' . $relative );
}
foreach ( plugin_architecture_js_files( 'assets/admin/shipments' ) as $file ) {
	$relative = str_replace( '\\', '/', substr( $file, strlen( plugin_architecture_root() ) + 1 ) );
	$source = (string) file_get_contents( $file );
	if ( 'assets/admin/shipments/extensions/pek.js' === $relative ) {
		plugin_architecture_assert( str_contains( $source, "carrierKey: 'pek'" ) && ! str_contains( $source, 'wp.ajax.post' ), 'PEK shipment JS must be a carrier-owned hook extension without create/status/document AJAX.' );
		continue;
	}
	plugin_architecture_assert( ! str_contains( $source, $jet_key ) && ! str_contains( $source, 'JetLogistic' ), 'Generic shipment JS must not contain Jet Logistic branches in ' . $relative );
	plugin_architecture_assert( ! str_contains( $source, "'pek'" ) && ! str_contains( $source, 'Pek' ), 'PEK foundation must not add generic shipment JS branches in ' . $relative );
}
$plugin_source_for_jet = (string) file_get_contents( plugin_architecture_path( 'src/Core/Plugin.php' ) );
plugin_architecture_assert( str_contains( $plugin_source_for_jet, 'JetLogisticCarrier::class' ) && str_contains( $plugin_source_for_jet, 'JetLogisticShipmentAdapter::class' ), 'Plugin.php must own Jet Logistic runtime and shipment adapter wiring.' );
$plugin_lines_for_jet = preg_split( '/\R/', $plugin_source_for_jet ) ?: array();
foreach ( $plugin_lines_for_jet as $line ) {
	if ( str_contains( $line, 'ShipmentDocumentProviderRegistry::class' ) || str_contains( $line, 'ShipmentModalExtensionRegistry::class' ) || str_contains( $line, 'ShipmentCreationService::class' ) ) {
		plugin_architecture_assert( ! str_contains( $line, 'JetLogistic' ), 'Jet Logistic must not register documents, modal extension, or create-flow persistence mapper.' );
	}
}

$checkout_provider_resolver_source = plugin_architecture_source( 'src/Pickup/Providers/CheckoutPickupPointProviderQueryResolver.php' );
plugin_architecture_assert( str_contains( $checkout_provider_resolver_source, "\$rate['rate_meta']" ) && str_contains( $checkout_provider_resolver_source, "\$rate['meta']" ), 'Checkout pickup provider resolver must read production rate_meta before legacy meta.' );
plugin_architecture_assert( strpos( $checkout_provider_resolver_source, "\$rate['carrier_key']" ) < strpos( $checkout_provider_resolver_source, "\$meta['carrier_key']" ) && strpos( $checkout_provider_resolver_source, "\$rate['pickup_family']" ) < strpos( $checkout_provider_resolver_source, "\$meta['pickup_family']" ), 'Checkout pickup provider resolver must prefer production top-level rate envelope for carrier and family.' );
plugin_architecture_assert( str_contains( $checkout_provider_resolver_source, "true !== \$requires_pickup" ) && str_contains( $checkout_provider_resolver_source, "'pickup' !== \$rate_delivery_type" ), 'Checkout pickup provider resolver must reject non-pickup/courier rate envelopes.' );
plugin_architecture_assert( str_contains( $checkout_provider_resolver_source, "\$rate_service !== \$carrier_key" ), 'Checkout pickup provider resolver must require service key to match carrier key.' );
plugin_architecture_assert( str_contains( $checkout_provider_resolver_source, "\$meta['pickup_provider_query']" ) && ! str_contains( $checkout_provider_resolver_source, "array_param" ) && ! str_contains( $checkout_provider_resolver_source, "get_param" ), 'Checkout pickup provider resolver must use stored rate metadata and not browser request payload.' );
plugin_architecture_assert( str_contains( $checkout_provider_resolver_source, "destination_fingerprint" ) && str_contains( $checkout_provider_resolver_source, "'' === trim" ), 'Checkout pickup provider resolver must reject empty destination fingerprints.' );
plugin_architecture_assert( str_contains( $checkout_provider_resolver_source, 'private function valid_coordinates' ) && str_contains( $checkout_provider_resolver_source, 'null === $latitude && null === $longitude' ), 'Checkout pickup provider resolver must accept address-only null/null coordinates.' );
plugin_architecture_assert( str_contains( $checkout_provider_resolver_source, 'null === $latitude || null === $longitude' ) && str_contains( $checkout_provider_resolver_source, '! is_numeric( $latitude )' ), 'Checkout pickup provider resolver must reject partial and non-numeric coordinates.' );
plugin_architecture_assert( str_contains( $checkout_provider_resolver_source, 'is_finite( $latitude )' ) && str_contains( $checkout_provider_resolver_source, '$latitude >= -90' ) && str_contains( $checkout_provider_resolver_source, '$longitude <= 180' ), 'Checkout pickup provider resolver must bound numeric coordinates and reject non-finite values.' );

$wc_session_bootstrapper_source = plugin_architecture_source( 'src/Checkout/WooCommerce/WooCommerceSessionBootstrapper.php' );
plugin_architecture_assert( str_contains( $wc_session_bootstrapper_source, 'final class WooCommerceSessionBootstrapper' ) && str_contains( $wc_session_bootstrapper_source, 'public function ensure(): bool' ), 'Shared WooCommerce session bootstrapper must exist for REST checkout session reads.' );
plugin_architecture_assert( str_contains( $wc_session_bootstrapper_source, 'WC_Session_Handler' ) && str_contains( $wc_session_bootstrapper_source, 'set_customer_session_cookie' ) && str_contains( $wc_session_bootstrapper_source, 'WC_Customer' ), 'WooCommerce session bootstrapper must initialize session handler, cookie, and customer without controller-local duplication.' );
plugin_architecture_assert( ! str_contains( $wc_session_bootstrapper_source, 'session_start' ) && ! str_contains( $wc_session_bootstrapper_source, '$_SESSION' ), 'WooCommerce session bootstrapper must not use native PHP sessions.' );
$pickup_points_rest_source = plugin_architecture_source( 'src/Pickup/Rest/PickupPointsRestController.php' );
plugin_architecture_assert( str_contains( $pickup_points_rest_source, 'WooCommerceSessionBootstrapper' ) && strpos( $pickup_points_rest_source, '$this->session_bootstrapper->ensure()' ) < strpos( $pickup_points_rest_source, '$this->provider_query_resolver->resolve' ), 'Pickup points REST must bootstrap WooCommerce session before trusted registry resolver.' );
plugin_architecture_assert( str_contains( $pickup_points_rest_source, 'provider_session_unavailable' ) && str_contains( $pickup_points_rest_source, 'Checkout session is unavailable.' ) && str_contains( $pickup_points_rest_source, ', 503' ), 'Pickup points REST must distinguish WooCommerce session bootstrap failure with provider_session_unavailable 503.' );
plugin_architecture_assert( ! str_contains( $pickup_points_rest_source, 'pickup_provider_query' ) || ! str_contains( $pickup_points_rest_source, "param( \$request, 'pickup_provider_query'" ), 'Pickup points REST must not accept pickup_provider_query from browser request.' );
$pickup_api_js_source = plugin_architecture_source( 'assets/frontend/pickup-map/wdc-pickup-api.js' );
plugin_architecture_assert( ! str_contains( $pickup_api_js_source, 'pickup_provider_query' ) && ! str_contains( $pickup_api_js_source, 'weight_g' ) && ! str_contains( $pickup_api_js_source, 'volume_cm3' ), 'Browser pickup API must not send trusted provider snapshots or cargo authority.' );
$checkout_pickup_rest_source = plugin_architecture_source( 'src/Pickup/Rest/CheckoutPickupPointRestController.php' );
plugin_architecture_assert( str_contains( $checkout_pickup_rest_source, "destination_fingerprint( \$method_id )" ) && str_contains( $checkout_pickup_rest_source, "provider_rate_context_missing" ), 'Checkout pickup save must enforce trusted destination fingerprint from stored rate context.' );
plugin_architecture_assert( ! str_contains( $checkout_pickup_rest_source, "param( \$request, 'address'" ) && ! str_contains( $checkout_pickup_rest_source, "param( \$request, 'latitude'" ), 'Checkout pickup save must not promote browser address/coordinates into trusted provider context.' );
plugin_architecture_assert( str_contains( $checkout_pickup_rest_source, 'WooCommerceSessionBootstrapper' ) && str_contains( $checkout_pickup_rest_source, '$this->session_bootstrapper->ensure()' ) && ! str_contains( $checkout_pickup_rest_source, 'function ensure_woocommerce_session' ) && ! str_contains( $checkout_pickup_rest_source, 'new \WC_Session_Handler' ), 'Checkout pickup REST must use shared bootstrapper and not duplicate controller-local WC session creation.' );
$pek_formatter_source = plugin_architecture_source( 'src/Carriers/Pek/Pickup/PekCheckoutPickupPointFormatter.php' );
plugin_architecture_assert( str_contains( $pek_formatter_source, 'Собственный пункт выдачи ПЭК' ) && str_contains( $pek_formatter_source, 'Партнерский пункт выдачи ПЭК' ) && str_contains( $pek_formatter_source, 'Возможна небольшая доплата за доставку в этот пункт' ), 'PEK pickup formatter must expose free/paid customer titles and paid warning.' );
plugin_architecture_assert( str_contains( $pek_formatter_source, 'public_point_name' ) && str_contains( $pek_formatter_source, 'looks_like_internal_identifier' ) && str_contains( $pek_formatter_source, '/^[0-9a-f]{8}-' ), 'PEK pickup formatter must filter internal UUIDs from public point names.' );
plugin_architecture_assert( str_contains( $pek_formatter_source, "'provider_destination_fingerprint' =>" ) && str_contains( $checkout_pickup_rest_source, "'provider_destination_fingerprint' =>" ), 'PEK provider formatter and save projection must carry provider_destination_fingerprint.' );
$checkout_session_source = plugin_architecture_source( 'src/Checkout/WooCommerce/CheckoutSessionManager.php' );
plugin_architecture_assert( str_contains( $checkout_session_source, 'safe_provider_destination_fingerprint' ) && str_contains( $checkout_session_source, "\$selection['provider_destination_fingerprint']" ) && str_contains( $checkout_session_source, "\$snapshot['provider_destination_fingerprint']" ), 'Checkout session normalization must preserve provider fingerprint separately from generic destination fingerprint.' );
$pek_context_source = plugin_architecture_source( 'src/Carriers/Pek/Checkout/PekCheckoutQuoteContextResolver.php' );
plugin_architecture_assert( str_contains( $pek_context_source, 'provider_destination_fingerprint' ) && str_contains( $pek_context_source, 'looks_like_provider_fingerprint' ) && str_contains( $pek_context_source, 'hash_equals( $destination_fingerprint, $stored_fingerprint )' ), 'PEK checkout resolver must validate selected terminals by provider fingerprint, with SHA-only legacy fallback.' );
$pek_carrier_source_for_cache = plugin_architecture_source( 'src/Carriers/Runtime/PekCarrier.php' );
$quote_cache_source_for_pickup = plugin_architecture_source( 'src/Checkout/Cache/QuoteCache.php' );
plugin_architecture_assert( str_contains( $pek_carrier_source_for_cache, 'pek_selection_provider_destination_fingerprint' ) && str_contains( $quote_cache_source_for_pickup, 'provider_destination_fingerprint' ), 'PEK and generic quote cache context must include selected point provider fingerprint.' );
plugin_architecture_assert( str_contains( $pek_carrier_source_for_cache, "'api_base_price_rub' => \$result->price_kopecks / 100" ) && str_contains( $pek_carrier_source_for_cache, "'pek_carrier_base_price_rub' => \$result->carrier_price_kopecks / 100" ) && str_contains( $pek_carrier_source_for_cache, "'pek_carrier_price_kopecks' => \$result->carrier_price_kopecks" ), 'PEK carrier must expose adjusted API base and preserve carrier-only cost separately.' );
plugin_architecture_assert( ! str_contains( $pek_carrier_source_for_cache, 'CheckoutSessionManager' ) && ! str_contains( $pek_carrier_source_for_cache, 'WC()->session' ), 'PEK carrier must not mutate WooCommerce session directly.' );
plugin_architecture_assert( str_contains( $pek_carrier_source_for_cache, 'pickup_preliminary_options' ) && str_contains( $pek_carrier_source_for_cache, 'pek_selected_terminal_quote_failed' ) && str_contains( $pek_carrier_source_for_cache, 'pickup_rejection_meta' ) && str_contains( $pek_carrier_source_for_cache, 'pickup_selection_rejected' ), 'PEK carrier must attempt explicit preliminary recovery and mark selected pickup rejection with generic metadata.' );
plugin_architecture_assert( str_contains( $pek_context_source, 'preliminary_pickup_options' ) && str_contains( $pek_context_source, 'selected_pickup_options' ), 'PEK checkout context resolver must share preliminary pickup policy between initial quote and recovery.' );
$new_shipping_method_source = plugin_architecture_source( 'src/Checkout/WooCommerce/NewShippingMethod.php' );
plugin_architecture_assert( str_contains( $new_shipping_method_source, 'handle_rejected_pickup_selection_rate' ) && str_contains( $new_shipping_method_source, 'clear_pickup_selection_for_family' ) && str_contains( $new_shipping_method_source, 'carrier_selected_pickup_quote_failed' ), 'Generic WooCommerce shipping method must clear rejected pickup selections by family.' );
plugin_architecture_assert( str_contains( $new_shipping_method_source, 'rate_without_transient_render_meta' ) && str_contains( $new_shipping_method_source, 'transient_pickup_rejection_keys' ) && str_contains( $new_shipping_method_source, 'preserve_shipping_method_choice' ), 'Generic rejected pickup recovery must preserve recovered shipping method choice and strip transient rejection metadata before session storage.' );
plugin_architecture_assert( ! str_contains( $new_shipping_method_source, 'wc_add_notice( $message, ' ) && ! str_contains( $new_shipping_method_source, 'wc_has_notice( $message, ' ), 'Rejected pickup recovery must not use global WooCommerce notices.' );
$checkout_rate_renderer_source = plugin_architecture_source( 'src/Checkout/WooCommerce/CheckoutRateRenderer.php' );
$checkout_delivery_type_selector_source = plugin_architecture_source( 'src/Checkout/WooCommerce/CheckoutDeliveryTypeSelector.php' );
plugin_architecture_assert( str_contains( $checkout_rate_renderer_source, 'wdc-pickup-inline-notice' ) && str_contains( $checkout_rate_renderer_source, 'pickup_selection_rejected_message' ) && str_contains( $checkout_delivery_type_selector_source, 'wdc-pickup-inline-notice' ), 'Rejected pickup recovery message must render inline inside the affected pickup shipping method.' );
$order_meta_persister_source = plugin_architecture_source( 'src/Checkout/WooCommerce/OrderShippingMetaPersister.php' );
plugin_architecture_assert( str_contains( $order_meta_persister_source, 'transient_pickup_rejection_keys' ) && str_contains( $order_meta_persister_source, 'unset( $meta[ $key ]' ), 'Order rate meta sanitizer must not persist transient rejected pickup render fields.' );
$calculation_builder_source = plugin_architecture_source( 'src/Orders/Application/DeliveryCalculationDataBuilder.php' );
plugin_architecture_assert( str_contains( $calculation_builder_source, 'base_price_adjustment_lines' ) && str_contains( $calculation_builder_source, 'Добавлен мешок и пломбировка' ) && str_contains( $calculation_builder_source, 'Добавлен мешок' ) && str_contains( $calculation_builder_source, 'Добавлена пломбировка' ), 'Delivery calculation builder must render PEK base adjustment formula notes for both/bag-only/sealing-only cases.' );
plugin_architecture_assert( str_contains( $calculation_builder_source, 'array() !== $audit || $round || $minimum || array() !== $base_adjustments' ) && str_contains( $calculation_builder_source, 'insert_base_price_adjustment_lines' ), 'Delivery calculation builder must render surcharge notes even without regular rules.' );
plugin_architecture_assert( str_contains( $calculation_builder_source, "'applied_rules' => \$audit" ) && ! str_contains( $calculation_builder_source, "'applied_rules' => \$base_adjustments" ), 'PEK surcharge note must not be inserted into applied_rules.' );
plugin_architecture_assert( str_contains( $calculation_builder_source, "'price_delta_rub' => \$final - \$api_base" ) && ! str_contains( $calculation_builder_source, 'pek_light_cargo_surcharge_kopecks +=' ), 'Rule delta must be calculated from adjusted base and builder must not add PEK surcharges again.' );
$pickup_map_js_source = plugin_architecture_source( 'assets/frontend/pickup-map/wdc-pickup-map.js' );
plugin_architecture_assert( str_contains( $pickup_map_js_source, 'typeLabel !== title' ) && str_contains( $pickup_map_js_source, "carrier === 'pek'" ), 'Generic pickup map must hide duplicate title/type rows and avoid displaying PEK technical UUID codes.' );
$pickup_checkout_js_source = plugin_architecture_source( 'assets/frontend/pickup-map/wdc-pickup-checkout.js' );
plugin_architecture_assert( str_contains( $pickup_checkout_js_source, 'function hasAuthoritativePickupSelections(response)' ) && str_contains( $pickup_checkout_js_source, '? extractPickupSelections(response)' ) && str_contains( $pickup_checkout_js_source, ': mergeSelectedPickupPoints(selectedPickupPoints, extractPickupSelections(response))' ), 'Checkout pickup frontend must treat explicit state selection buckets as authoritative and replace stale local selected points.' );
plugin_architecture_assert( str_contains( $pickup_checkout_js_source, 'var pickupInlineNotices = {};' ) && str_contains( $pickup_checkout_js_source, 'pickupInlineNotices[family]' ) && str_contains( $pickup_checkout_js_source, 'shippingMethodFamily(' ), 'Checkout pickup frontend must keep rejected pickup inline notices in a generic in-memory map keyed by normalized pickup family.' );
plugin_architecture_assert( str_contains( $pickup_checkout_js_source, 'function capturePickupInlineNotice' ) && str_contains( $pickup_checkout_js_source, 'function restorePickupInlineNotice' ) && str_contains( $pickup_checkout_js_source, 'data-wdc-pickup-inline-notice-source' ), 'Checkout pickup frontend must capture one-render server notice events and restore them after checkout DOM replacement without recapturing memory-rendered text.' );
plugin_architecture_assert( substr_count( $pickup_checkout_js_source, 'syncPickupInlineNotices();' ) >= 3 && str_contains( $pickup_checkout_js_source, "window.jQuery(document.body).on('updated_checkout'" ), 'Checkout pickup inline notice latch must survive repeated updated_checkout renders.' );
plugin_architecture_assert( str_contains( $pickup_checkout_js_source, 'var authoritativePickupSelections = {};' ) && str_contains( $pickup_checkout_js_source, 'authoritativePickupStateRevision++' ) && str_contains( $pickup_checkout_js_source, 'mergePickupSelectionsFromResponse(state, { authoritativeState: true })' ), 'Checkout pickup inline notice latch must use post-calculation /checkout/state as the authoritative source for selected-point success.' );
plugin_architecture_assert( str_contains( $pickup_checkout_js_source, 'function reconcilePickupInlineNoticesWithState(state)' ) && str_contains( $pickup_checkout_js_source, 'authoritativeSelectedPointForFamily(family)' ) && str_contains( $pickup_checkout_js_source, 'clearPickupInlineNotice(family)' ), 'Checkout pickup inline notice latch must clear only from authoritative state reconciliation.' );
plugin_architecture_assert( str_contains( $pickup_checkout_js_source, 'function removeLocalPickupSelection(family)' ) && str_contains( $pickup_checkout_js_source, 'delete selectedPickupPoints[family]' ) && str_contains( $pickup_checkout_js_source, 'delete window.wdcPickupCheckout.pickupSelections[family]' ), 'Authoritative empty pickup state must remove stale local selected points by family.' );
plugin_architecture_assert( ! str_contains( $pickup_checkout_js_source, 'hasSuccessfulPickupSelection' ) && ! str_contains( $pickup_checkout_js_source, 'isContainerSelectionComplete(container, family)) {' ), 'Checkout pickup inline notice latch must not clear from DOM hidden fields or local selectedPickupPoints alone.' );
plugin_architecture_assert( str_contains( $pickup_checkout_js_source, 'function bootContainers()' ) && str_contains( $pickup_checkout_js_source, 'bootContainers();' ) && str_contains( $pickup_checkout_js_source, 'refreshCheckoutContext();' ), 'updated_checkout must initialize containers/notices before authoritative state reconciliation and not restore stale selection first.' );
plugin_architecture_assert( str_contains( $pickup_checkout_js_source, 'clearPickupInlineNoticesForDestinationChange' ) && str_contains( $pickup_checkout_js_source, 'destinationFingerprint(context)' ), 'Checkout pickup inline notice latch must be bound to destination fingerprint and clear on destination changes.' );
plugin_architecture_assert( str_contains( $pickup_checkout_js_source, 'previousMethod' ) && str_contains( $pickup_checkout_js_source, 'clearPickupInlineNotice(shippingMethodFamily(previousMethod))' ), 'Checkout pickup inline notice latch must clear on genuine shipping method family changes.' );
plugin_architecture_assert( ! str_contains( $pickup_checkout_js_source, 'localStorage' ) && ! str_contains( $pickup_checkout_js_source, 'sessionStorage' ) && ! str_contains( $pickup_checkout_js_source, 'document.cookie' ), 'Checkout pickup inline notice latch must not use browser storage or cookies.' );
plugin_architecture_assert( ! str_contains( $pickup_checkout_js_source, 'pickupInlineNoticeInput' ) && ! str_contains( $pickup_checkout_js_source, 'wdc_pickup_inline_notice' ), 'Checkout pickup inline notice latch must not serialize notices into hidden form fields.' );
plugin_architecture_assert( ! preg_match( '/setTimeout\s*\([^;]*(pickupInlineNotice|pickupInlineNotices)/s', $pickup_checkout_js_source ), 'Checkout pickup inline notice latch must not auto-hide via timeout.' );
plugin_architecture_assert( ! str_contains( $pickup_checkout_js_source, 'pek:pickup' ) && ! str_contains( $pickup_checkout_js_source, "carrier === 'pek'" ), 'Checkout pickup inline notice latch must not contain a PEK-specific frontend branch.' );

$pek_planned_resolver_source = plugin_architecture_source( 'src/Carriers/Pek/Quote/PekQuotePlannedDateTimeResolver.php' );
plugin_architecture_assert( str_contains( $pek_planned_resolver_source, 'private ?string $resolved = null' ) && str_contains( $pek_planned_resolver_source, 'null !== $this->resolved' ) && str_contains( $pek_planned_resolver_source, '$this->resolved =' ), 'PEK plannedDateTime resolver must memoize per service instance.' );
plugin_architecture_assert( ! str_contains( $pek_planned_resolver_source, 'static $resolved' ) && ! str_contains( $pek_planned_resolver_source, 'set_transient' ) && ! str_contains( $pek_planned_resolver_source, 'update_option' ), 'PEK plannedDateTime memoization must not use static/global persistence.' );

$checkout_orchestrator_source = plugin_architecture_source( 'src/Checkout/Runtime/CheckoutOrchestrator.php' );
plugin_architecture_assert( ! str_contains( $checkout_orchestrator_source, 'PekCarrier' ) && ! str_contains( $checkout_orchestrator_source, "'pek'" ), 'CheckoutOrchestrator must not contain a PEK-specific branch.' );
$migration_files = glob( plugin_architecture_path( 'src/Infrastructure/Migrations/*.php' ) ) ?: array();
foreach ( $migration_files as $migration_file ) {
	$name = basename( $migration_file );
	plugin_architecture_assert( ! preg_match( '/005[2-9]_.*pek/i', $name ), 'Patch 0.133.9 must not add a new PEK migration: ' . $name );
}

echo "Plugin architecture smoke passed.\n";
