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

$ozon_plugin_source = plugin_architecture_source( 'src/Core/Plugin.php' );
$ozon_admin_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Admin/OzonDeliveryAdminPage.php' );
$ozon_transport_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Api/WpOzonDeliveryHttpClient.php' );
$ozon_cache_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Api/OzonDeliveryTokenCache.php' );
$ozon_pickup_import_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupImportService.php' );
$ozon_pickup_repository_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupRepository.php' );
$ozon_pickup_lock_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupImportLock.php' );
$ozon_pickup_scheduler_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupScheduler.php' );
$ozon_pickup_parser_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupParser.php' );
$ozon_pickup_schedule_formatter_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupScheduleFormatter.php' );
$ozon_pickup_schedule_migration_source = plugin_architecture_source( 'database/migrations/0054_change_ozon_delivery_pickup_schedule_storage.php' );
$ozon_pickup_geo_migration_source = plugin_architecture_source( 'database/migrations/0055_add_ozon_delivery_pickup_geo_lookup_index.php' );
$ozon_pickup_provider_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Pickup/OzonDeliveryPickupPointProvider.php' );
$ozon_quote_service_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Quote/OzonDeliveryQuoteService.php' );
$ozon_packaging_factory_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Quote/OzonDeliveryPackagingBuilderFactory.php' );
$ozon_api_client_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Api/OzonDeliveryApiClient.php' );
$ozon_shipment_adapter_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentAdapter.php' );
$ozon_shipment_service_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentService.php' );
$ozon_shipment_builder_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentCreateRequestBuilder.php' );
$ozon_courier_address_normalizer_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Shipments/OzonDeliveryCourierAddressNormalizer.php' );
$ozon_shipment_values_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentAllocationValueResolver.php' );
$ozon_shipment_modal_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentModalExtension.php' );
$ozon_shipment_documents_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentDocumentProvider.php' );
$ozon_shipment_mapper_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentPersistenceMapper.php' );
$ozon_shipment_preflight_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentPreflightQuoteService.php' );
$ozon_shipment_action_policy_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentActionPolicy.php' );
$ozon_shipment_creation_policy_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentCreationStatusPolicy.php' );
$ozon_shipment_external_id_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentExternalIdResolver.php' );
$ozon_shipment_info_parser_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentInfoParser.php' );
$ozon_shipment_status_mapping_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentStatusMapping.php' );
$ozon_shipment_status_mapper_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Shipments/OzonDeliveryShipmentStatusMapper.php' );
$ozon_return_service_source = plugin_architecture_source( 'src/Carriers/OzonDelivery/Returns/OzonDeliveryReturnService.php' );
$order_shipping_meta_persister_source = plugin_architecture_source( 'src/Checkout/WooCommerce/OrderShippingMetaPersister.php' );
$order_delivery_customer_comments_display_source = plugin_architecture_source( 'src/Checkout/WooCommerce/OrderDeliveryCustomerCommentsDisplay.php' );
$order_structured_address_source = plugin_architecture_source( 'src/Shipments/Application/OrderStructuredAddress.php' );
$order_structured_address_reader_source = plugin_architecture_source( 'src/Shipments/Application/OrderStructuredAddressReader.php' );
$shipment_draft_factory_source = plugin_architecture_source( 'src/Shipments/Application/OrderShipmentDraftFactory.php' );
$shipment_create_ajax_source = plugin_architecture_source( 'src/Shipments/Admin/Ajax/ShipmentCreateAjaxController.php' );
$shipment_preview_ajax_source = plugin_architecture_source( 'src/Shipments/Admin/Ajax/ShipmentPreviewAjaxController.php' );
$shipment_create_request_source = plugin_architecture_source( 'src/Domain/Shipment/ShipmentCreateRequest.php' );
$shipment_modal_mapper_source = plugin_architecture_source( 'src/Shipments/Application/ShipmentModalRequestMapper.php' );
$pickup_map_source = plugin_architecture_source( 'assets/frontend/pickup-map/wdc-pickup-map.js' );
$pickup_checkout_source = plugin_architecture_source( 'assets/frontend/pickup-map/wdc-pickup-checkout.js' );
$pickup_checkout_php_source = plugin_architecture_source( 'src/Checkout/WooCommerce/PickupMapCheckout.php' );
$pickup_checkout_rest_source = plugin_architecture_source( 'src/Pickup/Rest/CheckoutPickupPointRestController.php' );
plugin_architecture_assert( str_contains( $ozon_plugin_source, 'OzonDeliveryAdminPage::class' ) && str_contains( $ozon_plugin_source, 'OzonDeliveryAccessTokenService::class' ) && str_contains( $ozon_plugin_source, 'OzonDeliveryTokenCache::class' ), 'Ozon production wiring must remain carrier-owned in Plugin.php.' );
plugin_architecture_assert( str_contains( $ozon_cache_source, 'EncryptionService' ) && str_contains( $ozon_cache_source, 'encrypted_access_token' ) && ! str_contains( $ozon_cache_source, 'refresh_token' ), 'Ozon token cache must be encrypted and must not retain refresh tokens.' );
plugin_architecture_assert( ! str_contains( $ozon_plugin_source . $ozon_cache_source, "'grant_type' => 'refresh_token'" ), 'Ozon foundation must not add refresh flow.' );
plugin_architecture_assert( ! str_contains( plugin_architecture_source( 'src/Checkout/Runtime/CheckoutOrchestrator.php' ), 'Ozon' ) && ! str_contains( plugin_architecture_source( 'src/Carriers/Registry/CarrierRegistry.php' ), 'Ozon' ) && str_contains( $ozon_plugin_source, 'OzonDeliveryCarrier::class' ), 'Ozon checkout runtime must be registered through Plugin.php without Ozon branches in generic checkout or CarrierRegistry.' );
$orchestrator_source = plugin_architecture_source( 'src/Checkout/Runtime/CheckoutOrchestrator.php' );
$customer_snapshot_source = plugin_architecture_source( 'src/Checkout/Comments/DeliveryCustomerCommentSnapshotBuilder.php' );
$customer_normalizer_source = plugin_architecture_source( 'src/Checkout/Comments/DeliveryCustomerCommentNormalizer.php' );
$customer_renderer_source = plugin_architecture_source( 'src/Checkout/Comments/DeliveryCustomerCommentRenderer.php' );
$replacement_source = plugin_architecture_source( 'src/Orders/Application/OrderDeliveryReplacementService.php' );
$metabox_source = plugin_architecture_source( 'src/Orders/Admin/OrderDeliveryMetabox.php' );
plugin_architecture_assert( ! is_file( plugin_architecture_path( 'src/Carriers/Contracts/CarrierCustomerCommentProviderInterface.php' ) ) && ! is_file( plugin_architecture_path( 'src/Carriers/OzonDelivery/Checkout/OzonDeliveryCustomerCommentProvider.php' ) ) && ! is_file( plugin_architecture_path( 'src/Carriers/JetLogistic/Checkout/JetLogisticCustomerCommentProvider.php' ) ) && ! str_contains( $orchestrator_source, 'CarrierCustomerCommentProviderInterface' ) && ! str_contains( $orchestrator_source, 'rate_with_carrier_customer_comments' ) && ! str_contains( $ozon_plugin_source, 'OzonDeliveryCustomerCommentProvider::class' ) && ! str_contains( $ozon_plugin_source, 'JetLogisticCustomerCommentProvider::class' ), 'Carrier-specific customer-comment persistence providers must be removed; final DeliveryRate metadata is the sole source for order persistence.' );
plugin_architecture_assert( str_contains( $ozon_plugin_source, 'DeliveryCustomerCommentNormalizer::class' ) && str_contains( $ozon_plugin_source, 'DeliveryCustomerCommentSnapshotBuilder::class' ) && str_contains( $ozon_plugin_source, 'DeliveryCustomerCommentRenderer::class' ) && str_contains( $orchestrator_source, 'rate_with_customer_comment_snapshot' ) && str_contains( $orchestrator_source, 'rate_with_materialized_customer_comment_templates' ) && str_contains( $customer_snapshot_source, 'customer_link_comments' ) && str_contains( $customer_snapshot_source, 'planned_delivery_comment' ) && str_contains( $customer_normalizer_source, "'type' => 'link'" ) && str_contains( $customer_renderer_source, 'esc_url' ), 'Unified customer comments must be DI-wired, built after final rate processing, support structured links without raw HTML, and include planned delivery comments.' );
plugin_architecture_assert( ! str_contains( $order_shipping_meta_persister_source, 'pickup_snapshot_with_customer_comments' ) && str_contains( $order_shipping_meta_persister_source, 'pickup_snapshot_without_customer_comments' ) && ! str_contains( $replacement_source, 'pickup_snapshot_with_customer_comments' ) && str_contains( $replacement_source, 'pickup_snapshot_without_customer_comments' ) && str_contains( plugin_architecture_source( 'src/Pickup/Presentation/PickupPointCardRenderer.php' ), 'customer_comments' ) && str_contains( plugin_architecture_source( 'src/Pickup/Presentation/PickupPointCardRenderer.php' ), 'wdc-pickup-point-card__customer-comment' ), 'New order creation/admin recalculation must not duplicate customer comments in pickup snapshots, while pickup card keeps legacy snapshot fallback rendering.' );
plugin_architecture_assert( ! str_contains( $order_shipping_meta_persister_source, 'jet_logistic' ) && ! str_contains( $order_shipping_meta_persister_source, 'JetLogistic' ) && ! str_contains( $order_shipping_meta_persister_source, 'ozon_delivery' ) && ! str_contains( $order_shipping_meta_persister_source, 'OzonDelivery' ) && ! str_contains( $order_delivery_customer_comments_display_source, 'jet_logistic' ) && ! str_contains( $order_delivery_customer_comments_display_source, 'JetLogistic' ) && ! str_contains( $order_delivery_customer_comments_display_source, 'ozon_delivery' ) && ! str_contains( $order_delivery_customer_comments_display_source, 'OzonDelivery' ) && str_contains( $order_delivery_customer_comments_display_source, '_wdc_platform_customer_comments' ) && ! str_contains( $order_delivery_customer_comments_display_source, '_wdc_platform_requires_pickup_point' ) && str_contains( $metabox_source, '_wdc_platform_customer_comments' ), 'Generic customer comment persistence/display/admin rendering must be carrier-neutral and read the canonical _wdc_platform_customer_comments source without pickup suppression.' );
plugin_architecture_assert( ! str_contains( $ozon_admin_source, 'wp_remote_request' ) && ! str_contains( $ozon_admin_source, 'Redirect URI' ), 'Ozon admin must not own transport or authorization-code flow.' );
plugin_architecture_assert( str_contains( $ozon_transport_source, 'MAX_REDIRECTS = 3' ) && str_contains( $ozon_transport_source, "'redirection' => 0" ), 'Ozon transport must own bounded DDoS redirect handling.' );
plugin_architecture_assert( str_contains( $ozon_plugin_source, 'OzonDeliveryPickupScheduler::class' ) && str_contains( $ozon_pickup_import_source, 'pickup_pagination_invalid' ) && str_contains( $ozon_pickup_repository_source, 'generation_id' ), 'Ozon pickup persistence and importer must remain carrier-owned and wired only through Plugin.php.' );
plugin_architecture_assert( str_contains( $ozon_plugin_source, 'OzonDeliveryPickupPointProvider::class' ) && str_contains( $ozon_pickup_provider_source, 'CarrierPickupPointProviderInterface' ) && str_contains( $ozon_pickup_provider_source, 'find_active_in_area' ) && ! str_contains( $ozon_pickup_provider_source, 'OzonDeliveryApiClient' ), 'Ozon pickup provider must be carrier-owned, registry-wired and local-database-only.' );
plugin_architecture_assert( str_contains( $ozon_plugin_source, 'OzonDeliveryPackagingBuilderFactory::class' ) && str_contains( $ozon_plugin_source, 'OzonDeliveryPackagingBuilderFactory::class )->create()' ) && str_contains( $ozon_packaging_factory_source, '50.0, 50.0, 30.0' ) && str_contains( $ozon_quote_service_source, 'catch ( PackagingException $exception )' ) && str_contains( $ozon_quote_service_source, 'ozon_package_item_oversize' ) && ! str_contains( plugin_architecture_source( 'src/Packaging/PackagingBuilder.php' ), 'ozon_delivery' ), 'Ozon parcel limits must be carrier-configured through DI; generic PackagingBuilder must remain carrier-neutral and fail closed for an indivisible oversize item.' );
plugin_architecture_assert( str_contains( $ozon_pickup_provider_source, "'requires_rate_refresh' => true" ) && str_contains( plugin_architecture_source( 'src/Pickup/Rest/PickupPointsRestController.php' ), 'registry_boolean_value' ) && ! str_contains( plugin_architecture_source( 'src/Pickup/Rest/PickupPointsRestController.php' ), 'ozon_delivery' ), 'Ozon provider must declare repricing through generic requires_rate_refresh projection without Ozon REST branches.' );
plugin_architecture_assert( str_contains( $ozon_plugin_source, "OzonDeliverySettings::CARRIER_KEY => array( \$this->container->get( OzonDeliveryPickupPointProvider::class ), 'query_from_snapshot' )" ) && str_contains( $ozon_pickup_provider_source, 'function query_from_snapshot' ) && str_contains( $ozon_pickup_provider_source, 'function place_passes' ) && str_contains( $ozon_pickup_provider_source, 'function places_from_cargo' ) && str_contains( $ozon_pickup_provider_source, '$query->cargo->max_place_weight_g > 0 ? $query->cargo->max_place_weight_g : $query->cargo->weight_g' ) && str_contains( $ozon_pickup_provider_source, 'rsort( $parcel_mm, SORT_NUMERIC )' ) && str_contains( $ozon_quote_service_source, 'function cargo_places' ) && str_contains( $ozon_quote_service_source, "'ozon_delivery_places' => \$query->cargo->places" ), 'Ozon multi-box pickup compatibility must be carrier-owned, preserve per-place cargo in the trusted snapshot, use per-place weight, and compare dimensions with rotation.' );
plugin_architecture_assert( str_contains( $ozon_pickup_provider_source, 'function last_search_diagnostics' ) && str_contains( $ozon_pickup_provider_source, 'rows_in_bbox' ) && str_contains( $ozon_pickup_provider_source, 'max_weight_rejected' ) && str_contains( $ozon_pickup_provider_source, 'dimension_rejected' ) && str_contains( $ozon_quote_service_source, 'function representative_missing_details' ) && str_contains( $ozon_quote_service_source, "'pickup_diagnostics' => \$this->pickup_provider->last_search_diagnostics()" ) && str_contains( plugin_architecture_source( 'src/Carriers/Runtime/OzonDeliveryCarrier.php' ), 'function safe_exception_log_context' ), 'Ozon representative-point failures must expose only carrier-owned aggregate pickup diagnostics for live analysis.' );
plugin_architecture_assert( str_contains( plugin_architecture_source( 'src/Carriers/Runtime/OzonDeliveryCarrier.php' ), "Ozon Delivery checkout quote calculated." ) && str_contains( plugin_architecture_source( 'src/Carriers/Runtime/OzonDeliveryCarrier.php' ), 'function safe_success_log_context' ) && str_contains( plugin_architecture_source( 'src/Carriers/Runtime/OzonDeliveryCarrier.php' ), 'function safe_success_rows' ) && ! str_contains( plugin_architecture_source( 'src/Carriers/Runtime/OzonDeliveryCarrier.php' ), "\$result->meta['raw_response']" ) && ! str_contains( plugin_architecture_source( 'src/Carriers/Runtime/OzonDeliveryCarrier.php' ), "\$result->meta['recipient']" ), 'Successful Ozon quotes must use one carrier-owned allowlisted diagnostic context without raw API or recipient data.' );
plugin_architecture_assert( str_contains( $ozon_quote_service_source, "'pickup_provider_query' => \$this->query_snapshot( \$query, \$request )" ) && str_contains( $ozon_quote_service_source, "'destination_fingerprint' => \$fingerprint" ) && str_contains( $ozon_quote_service_source, "'provider_destination_fingerprint' => \$fingerprint" ) && str_contains( $ozon_quote_service_source, "'reload_on_viewport_change' => false" ) && str_contains( $ozon_quote_service_source, "'prefetch_points' => false" ) && str_contains( $ozon_quote_service_source, 'function destination_fingerprint' ), 'Ozon quote service must carry the generic destination fingerprint, fixed-area map capability, and disabled point-prefetch capability in the trusted pickup provider snapshot.' );
plugin_architecture_assert( str_contains( $ozon_api_client_source, 'function order_create' ) && str_contains( $ozon_api_client_source, "'Idempotency-Key' => \$idempotency_key" ) && str_contains( $ozon_api_client_source, 'function posting_approve' ) && str_contains( $ozon_api_client_source, 'function posting_info' ) && str_contains( $ozon_api_client_source, 'function posting_cancel' ) && str_contains( $ozon_api_client_source, 'function posting_label' ) && str_contains( $ozon_api_client_source, 'function return_search' ) && str_contains( $ozon_api_client_source, "'/v1/return/search'" ) && str_contains( $ozon_api_client_source, 'function return_info' ) && str_contains( $ozon_api_client_source, "'/v1/return/info'" ) && str_contains( $ozon_api_client_source, 'authorized_empty_success_request' ), 'Ozon shipment API client must expose typed endpoint wrappers including official return/search and return/info while preserving empty successful approve/cancel responses.' );
plugin_architecture_assert( str_contains( $ozon_plugin_source, 'OzonDeliveryShipmentAdapter::class' ) && str_contains( $ozon_plugin_source, 'OzonDeliveryShipmentPersistenceMapper::class' ) && str_contains( $ozon_plugin_source, 'OzonDeliveryShipmentDocumentProvider::class' ) && str_contains( $ozon_plugin_source, 'OzonDeliveryShipmentModalExtension::class' ) && str_contains( $ozon_plugin_source, 'OzonDeliveryShipmentPreflightQuoteService::class' ) && str_contains( $ozon_plugin_source, 'OzonDeliveryShipmentService::class' ) && str_contains( $ozon_plugin_source, 'OzonDeliveryShipmentStatusMapper::class' ) && str_contains( $ozon_plugin_source, 'OzonDeliveryShipmentInfoParser::class' ) && str_contains( $ozon_shipment_info_parser_source, 'OzonDeliveryShipmentInfoParseException' ) && str_contains( $ozon_shipment_info_parser_source, 'duplicate_count' ) && str_contains( $ozon_shipment_info_parser_source, 'unexpected_count' ) && str_contains( $ozon_shipment_info_parser_source, 'missing_count' ) && str_contains( $ozon_shipment_info_parser_source, "'ozon_posting_info_incomplete'" ) && str_contains( $ozon_shipment_service_source, 'ozon_status_read_error' ) && str_contains( $ozon_shipment_service_source, 'OzonDeliveryShipmentInfoParseException' ) && str_contains( $ozon_shipment_service_source, 'info_parser->parse' ) && str_contains( $ozon_plugin_source, 'CarrierShipmentAdapterRegistry::class' ) && str_contains( $ozon_plugin_source, 'ShipmentDocumentProviderRegistry::class' ) && str_contains( $ozon_plugin_source, 'ShipmentModalExtensionRegistry::class' ), 'Ozon shipments must be wired through existing Shipment Framework registries in Plugin.php and fail closed on incomplete posting/info responses.' );
plugin_architecture_assert( str_contains( $order_structured_address_source, "_wdc_platform_structured_recipient_address" ) && str_contains( $order_structured_address_reader_source, 'legacy_recipient_address' ) && str_contains( $order_shipping_meta_persister_source, 'trusted_structured_recipient_address_snapshot' ) && str_contains( $order_shipping_meta_persister_source, 'trusted_dadata_address_evidence' ) && ! str_contains( $order_shipping_meta_persister_source, '_wdc_ozon_dadata_address' ), 'Structured recipient address persistence must be generic, order-level, and sourced only from trusted checkout DaData evidence.' );
plugin_architecture_assert( str_contains( $ozon_plugin_source, 'OzonDeliveryCourierAddressNormalizer::class' ) && str_contains( $shipment_create_ajax_source, 'maybe_prepare_ozon_courier_address' ) && str_contains( $shipment_preview_ajax_source, 'maybe_prepare_ozon_courier_address' ) && str_contains( $ozon_courier_address_normalizer_source, 'AddressSuggestionService' ) && ! str_contains( $ozon_courier_address_normalizer_source, 'wp_remote_' ), 'Ozon courier shipment modal must reuse the existing server-side DaData suggestion service through DI and revalidate before preview/create.' );
plugin_architecture_assert( str_contains( $shipment_draft_factory_source, 'ozon_delivery_type_from_order' ) && str_contains( $shipment_draft_factory_source, 'courier_address_snapshot' ) && str_contains( $shipment_draft_factory_source, 'Сценарий доставки Ozon изменился' ) && str_contains( $ozon_shipment_builder_source, "'courier' => \$courier_delivery" ) && str_contains( $ozon_shipment_builder_source, 'courier_shipment_method_id' ) && str_contains( $ozon_shipment_builder_source, "'zip_code'" ) && str_contains( $ozon_shipment_builder_source, "'house_number'" ) && str_contains( $ozon_shipment_preflight_source, 'checkout_delivery' ) && str_contains( $ozon_shipment_preflight_source, "'coordinates' => \$delivery['courier']['coordinates']" ), 'Ozon courier shipments must be mode-authoritative from order state, build official delivery.courier create payload, and derive checkout preflight from the same canonical create body.' );
plugin_architecture_assert( str_contains( $ozon_shipment_adapter_source, 'return $this->service->attach_manual( $order, $payload );' ) && str_contains( $ozon_shipment_service_source, 'public function attach_manual' ) && str_contains( $ozon_shipment_service_source, '$this->api->posting_info( array( $posting_number ) )' ) && str_contains( $ozon_shipment_service_source, 'MANUAL_ATTACH_ACTUAL_COST_SOURCE_DETAIL' ) && str_contains( $ozon_shipment_service_source, 'estimated_delivery_cost' ) && str_contains( $ozon_shipment_service_source, 'estimated_insurance_cost' ) && str_contains( $ozon_shipment_service_source, 'ShipmentActualCost' ) && ! str_contains( $ozon_shipment_service_source, "'not_available_in_posting_info_contract'" ), 'Ozon manual attach must use the existing adapter/service path, validate through posting/info, and persist only documented posting/info delivery plus insurance as canonical actual cost.' );
plugin_architecture_assert( str_contains( $ozon_shipment_service_source, 'order_create(' ) && str_contains( $ozon_shipment_service_source, 'approve_postings' ) && str_contains( $ozon_shipment_service_source, 'continue_approval' ) && str_contains( $ozon_shipment_service_source, 'CONTINUATION_TOKEN' ) && ! str_contains( plugin_architecture_source( 'src/Shipments/Application/ShipmentCreationService.php' ), 'approve' ), 'Ozon approve must remain a carrier-owned create orchestration, not a generic Shipment Framework action.' );
plugin_architecture_assert( str_contains( $ozon_shipment_service_source, '$this->preflight->quote' ) && strpos( $ozon_shipment_service_source, '$this->preflight->quote' ) < strpos( $ozon_shipment_service_source, 'order_create(' ) && str_contains( $ozon_shipment_preflight_source, 'order_checkout' ) && str_contains( $ozon_shipment_preflight_source, 'OzonDeliveryQuoteParser' ) && str_contains( $ozon_shipment_preflight_source, "SOURCE_DETAIL = 'ozon_order_checkout_pre_create'" ) && str_contains( $ozon_shipment_mapper_source, 'actual_cost_candidate' ), 'Ozon shipment create must run carrier-owned /order/checkout preflight before /order/create and persist actual cost through the common candidate contract.' );
plugin_architecture_assert( str_contains( $ozon_shipment_service_source, 'post_approve_status_snapshot' ) && str_contains( $ozon_shipment_service_source, 'posting_info( $numbers )' ) && str_contains( $ozon_shipment_service_source, 'DeliveryStatus::CREATED_IN_CARRIER' ) && str_contains( $ozon_shipment_mapper_source, 'ozon_statuses' ), 'Ozon create+approve must enrich the persisted shipment with a post-approve status snapshot without introducing a generic approve action.' );
plugin_architecture_assert( str_contains( $ozon_shipment_creation_policy_source, "PURPOSE = 'creation_confirmation'" ) && str_contains( $ozon_shipment_creation_policy_source, "STATUS_STARTED = 'creation_confirmation_started'" ) && str_contains( $ozon_shipment_creation_policy_source, 'ready_for_shipping' ) && str_contains( $ozon_shipment_creation_policy_source, 'forming_failed' ) && str_contains( $ozon_shipment_service_source, 'creation_confirmation_required' ) && str_contains( $ozon_shipment_adapter_source, 'OzonDeliveryShipmentCreationStatusPolicy::PURPOSE' ) && str_contains( plugin_architecture_source( 'src/Shipments/Admin/Ajax/ShipmentCreateAjaxController.php' ), "'poll_required' => ! empty( \$result->raw_reference['poll_required'] )" ), 'Ozon create confirmation must use a carrier-owned raw status policy and the shared Shipment polling lifecycle after create+approve.' );
plugin_architecture_assert( str_contains( $ozon_shipment_adapter_source, 'OzonDeliveryShipmentActionPolicy::for_shipment' ) && str_contains( $ozon_shipment_service_source, 'OzonDeliveryShipmentActionPolicy::all_cancelled' ) && str_contains( $ozon_shipment_service_source, "'auto_poll' => true" ) && str_contains( $ozon_shipment_service_source, "'poll_interval_ms' => 5000" ) && str_contains( $ozon_shipment_service_source, "'poll_max_attempts' => 14" ) && str_contains( $ozon_shipment_service_source, 'delete_for_carrier' ) && str_contains( $ozon_shipment_service_source, '$accepted_postings' ) && str_contains( $ozon_shipment_service_source, '$failed_postings' ) && str_contains( $ozon_shipment_service_source, "'cancel_attempt'" ) && str_contains( $ozon_shipment_action_policy_source, 'ready_for_shipping' ) && str_contains( $ozon_shipment_action_policy_source, 'all_cancelled' ) && str_contains( $ozon_shipment_action_policy_source, "'cancellation_started'" ) && str_contains( $ozon_shipment_action_policy_source, 'has_identifier' ) && str_contains( $ozon_shipment_adapter_source, "'cancellation_exhausted'" ) && str_contains( $ozon_shipment_adapter_source, 'Локальные данные Ozon удалены из заказа' ), 'Ozon cancellation must use carrier-owned raw status action policy, shared 5s x 14 polling lifecycle, track accepted/failed cancel postings, allow explicit local cleanup by saved Ozon identifiers, and automatic cleanup only after all postings are CANCELED.' );
plugin_architecture_assert( str_contains( $ozon_plugin_source, 'OzonDeliveryReturnService::class' ) && str_contains( $ozon_plugin_source, 'OzonDeliveryReturnSearchParser::class' ) && str_contains( $ozon_plugin_source, 'OzonDeliveryReturnInfoParser::class' ) && str_contains( $ozon_plugin_source, 'OzonDeliveryReturnLifecycleResolver::class' ) && str_contains( $ozon_plugin_source, 'OzonDeliveryShipmentExternalIdResolver::class' ) && str_contains( $ozon_return_service_source, 'return_search' ) && str_contains( $ozon_return_service_source, 'return_info' ) && str_contains( $ozon_return_service_source, 'next_cursor' ) && str_contains( $ozon_return_service_source, 'return_external_id' ) && str_contains( $ozon_return_service_source, 'SAFETY_PAGE_CAP' ) && str_contains( $ozon_return_service_source, 'expects_return_discovery' ) && str_contains( $ozon_return_service_source, 'sync_posting_return_states' ) && str_contains( $ozon_return_service_source, 'normalized_search_diagnostics' ) && str_contains( $ozon_shipment_service_source, 'handover_seen' ) && str_contains( $ozon_shipment_service_source, 'OzonDeliveryReturnService' ), 'Ozon external CANCELED return reconciliation must be carrier-owned, DI-wired, paginated by official next_cursor, exact return_external_id matched, handover-aware, and rediscover unresolved return states from persisted place evidence.' );
plugin_architecture_assert( str_contains( $ozon_admin_source, 'render_statuses' ) && str_contains( $ozon_admin_source, 'SHIPMENT_STATUS_MAPPING_KEY' ) && str_contains( $ozon_admin_source, 'DeliveryStatus::all()' ) && str_contains( $ozon_admin_source, 'Сохранить соответствия' ) && ! str_contains( $ozon_admin_source, 'Доступное действие' ) && str_contains( plugin_architecture_source( 'src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' ), "\$tabs['ozon_statuses'] = 'Статусы Ozon'" ), 'Ozon status admin tab must render editable carrier-owned raw-status to universal-status mapping.' );
plugin_architecture_assert( str_contains( $ozon_shipment_status_mapping_source, 'MOVING' ) && str_contains( $ozon_shipment_status_mapping_source, 'AT_PICKUP_POINT' ) && str_contains( $ozon_shipment_status_mapping_source, 'AT_THE_PICK_UP_POINT' ) && str_contains( $ozon_shipment_status_mapping_source, 'RECEIVED' ) && str_contains( $ozon_shipment_status_mapping_source, 'UTILIZATION' ) && str_contains( $ozon_shipment_status_mapping_source, 'UTILIZED' ) && str_contains( $ozon_shipment_status_mapping_source, 'WRITTEN_OFF' ) && str_contains( $ozon_shipment_status_mapping_source, 'LOOKING_FOR' ), 'Ozon status catalog must include official return statuses from operation and status schemas.' );
plugin_architecture_assert( str_contains( $ozon_shipment_status_mapper_source, 'OzonDeliverySettings $settings' ) && str_contains( $ozon_shipment_status_mapper_source, 'shipment_status_mapping' ) && str_contains( $ozon_shipment_status_mapper_source, 'save_from_admin' ) && str_contains( $ozon_shipment_service_source, '$this->status_mapper()->aggregate' ) && str_contains( $ozon_shipment_action_policy_source, 'has_return_lifecycle' ) && str_contains( $ozon_shipment_action_policy_source, 'raw_statuses_from_shipment' ) && str_contains( $ozon_shipment_action_policy_source, 'return_found_active' ), 'Ozon runtime status mapping must use settings-backed mapper while action policy keeps carrier-cancel safety on raw statuses plus persisted return lifecycle markers.' );
plugin_architecture_assert( ! str_contains( $ozon_shipment_service_source . $ozon_shipment_adapter_source, 'setInterval' ) && ! str_contains( $ozon_shipment_service_source . $ozon_shipment_adapter_source, 'wdc_ozon_cancel_poll' ), 'Ozon must not introduce a carrier-specific polling timer or cancel-poll AJAX endpoint.' );
plugin_architecture_assert( str_contains( $ozon_shipment_builder_source, '$request->places' ) && str_contains( $ozon_shipment_builder_source, '$request->meta[\'shipment_item_rows\']' ) && str_contains( $ozon_shipment_builder_source, 'phone_number' ) && str_contains( $ozon_shipment_builder_source, 'full_name' ) && str_contains( $ozon_shipment_builder_source, 'delivery_point_id' ) && str_contains( $ozon_shipment_builder_source, 'posting_external_id' ) && str_contains( $ozon_shipment_builder_source, 'shipment_method_id' ) && str_contains( $ozon_shipment_builder_source, 'declared_value' ) && str_contains( $ozon_shipment_builder_source, 'dimensions' ) && ! str_contains( $ozon_shipment_builder_source, 'PackagingBuilder' ) && ! str_contains( $ozon_shipment_builder_source, 'PackagingResult' ) && ! str_contains( $ozon_shipment_builder_source, 'ozon_delivery_places' ), 'Ozon shipment create request must consume actual modal places and item assignments, not checkout Packaging.' );
plugin_architecture_assert( ! str_contains( $ozon_shipment_builder_source . $ozon_shipment_external_id_source, "'wdc-'" ) && ! str_contains( $ozon_shipment_builder_source . $ozon_shipment_external_id_source, "'wdc-order-'" ) && str_contains( $ozon_shipment_builder_source, 'get_order_number' ) && str_contains( $ozon_shipment_builder_source, 'OzonDeliveryShipmentExternalIdResolver' ) && str_contains( $ozon_shipment_external_id_source, 'expected_return_external_id' ) && str_contains( $ozon_shipment_external_id_source, "\$base . '-'" ), 'Ozon human external IDs must use shared WooCommerce order-number resolver with hyphenated multi-place IDs and no technical wdc prefixes or creation UUIDs.' );
plugin_architecture_assert( str_contains( $shipment_modal_mapper_source, "'split_parent'" ) && ! str_contains( $shipment_modal_mapper_source, "'order_item_id' =>" ), 'Shipment modal split_parent must remain generic split metadata, while the removed Ozon order_item_id workaround must not be persisted as a separate mapper field.' );
plugin_architecture_assert( str_contains( $shipment_create_request_source, 'DeliveryType::COURIER === $this->delivery_type' ) && ! str_contains( $shipment_create_request_source, 'ozon_delivery' ), 'Generic ShipmentCreateRequest address validation must be delivery-type based and must not add Ozon-specific branches.' );
plugin_architecture_assert( str_contains( $ozon_shipment_values_source, "'amount'" ) && str_contains( $ozon_shipment_values_source, "'cost'" ) && str_contains( $ozon_shipment_values_source, "'unit_price_kopecks'" ) && str_contains( $ozon_shipment_values_source, 'shipment_modal_quantity_times_unit_price' ) && ! str_contains( $ozon_shipment_values_source, 'get_items' ) && ! str_contains( $ozon_shipment_values_source, 'get_total' ) && ! str_contains( $ozon_shipment_values_source, 'get_sku' ) && ! str_contains( $ozon_shipment_values_source, 'get_product_id' ), 'Ozon shipment declared value must be calculated from actual Shipment modal quantity and unit price, without Woo order item, product, or SKU matching.' );
plugin_architecture_assert( ! str_contains( $ozon_shipment_builder_source, "'items'" ) && ! str_contains( $ozon_shipment_builder_source, "'products'" ) && ! str_contains( $ozon_shipment_builder_source, "'sku'" ) && ! str_contains( $ozon_shipment_builder_source, "'item_key'" ), 'Ozon shipment create payload must not send modal product lists, SKU, item_key, or internal allocation rows to Ozon.' );
plugin_architecture_assert( str_contains( $ozon_shipment_service_source, 'first_place_rejection' ) && str_contains( $ozon_shipment_service_source, 'Грузоместо %d превышает допустимый вес ПВЗ Ozon.' ) && str_contains( $ozon_shipment_service_source, 'Грузоместо %d превышает допустимые размеры выбранного ПВЗ Ozon.' ) && str_contains( $ozon_shipment_modal_source, 'Ограничения выбранного ПВЗ Ozon' ) && str_contains( $ozon_shipment_modal_source, 'max_weight_g' ) && str_contains( $ozon_shipment_modal_source, 'max_length_mm' ), 'Ozon shipment create and modal extension must use selected point-specific pickup restrictions.' );
plugin_architecture_assert( str_contains( $ozon_shipment_documents_source, 'ozon_label_' ) && str_contains( $ozon_shipment_documents_source, 'posting_label' ) && str_contains( $ozon_shipment_documents_source, 'Скачать этикетку' ) && str_contains( $ozon_shipment_mapper_source, 'ozon_postings' ) && str_contains( $ozon_shipment_mapper_source, 'pending_creation_in_carrier' ), 'Ozon shipment persistence/documents must preserve multi-posting references and expose labels per actual place.' );
plugin_architecture_assert( ! str_contains( $ozon_pickup_repository_source, 'raw_json' ) && ! str_contains( $ozon_pickup_import_source, 'wp_remote_request' ), 'Ozon pickup importer must not retain raw API payload or own HTTP transport.' );
plugin_architecture_assert( str_contains( $ozon_pickup_lock_source, 'max( $now + self::TTL, $current_expiry + 1 )' ) && str_contains( $ozon_pickup_lock_source, '$current_expiry <= $now' ) && str_contains( $ozon_pickup_scheduler_source, 'pickup_lock_renew_failed' ) && str_contains( $ozon_pickup_scheduler_source, '$this->lock->owns( $owner )' ), 'Ozon pickup lock renewal must advance same-second leases and fail the matching job without releasing a foreign lock.' );
plugin_architecture_assert( str_contains( $ozon_plugin_source, 'OzonDeliveryPickupScheduleFormatter::class' ) && str_contains( $ozon_pickup_parser_source, 'schedule_formatter->format' ) && ! str_contains( $ozon_pickup_parser_source, 'schedule_json' ) && str_contains( $ozon_pickup_repository_source, 'cleanup_obsolete_points' ), 'Ozon pickup schedules must be formatter-owned and obsolete points must be cleaned after activation.' );
plugin_architecture_assert( str_contains( $ozon_pickup_schedule_migration_source, 'ADD COLUMN schedule' ) && str_contains( $ozon_pickup_schedule_migration_source, 'DROP COLUMN schedule_json' ) && ! str_contains( $ozon_pickup_schedule_migration_source, 'UPDATE ' ), 'Ozon schedule migration must replace legacy storage without backfill.' );
plugin_architecture_assert( str_contains( $ozon_pickup_geo_migration_source, 'return static function (): void' ) && str_contains( $ozon_pickup_geo_migration_source, 'global $wpdb' ) && str_contains( $ozon_pickup_geo_migration_source, 'active_geo_lookup' ) && str_contains( $ozon_pickup_geo_migration_source, 'postcondition' ) && 0 === ( new ReflectionFunction( require plugin_architecture_path( 'database/migrations/0055_add_ozon_delivery_pickup_geo_lookup_index.php' ) ) )->getNumberOfRequiredParameters(), 'Ozon 0055 migration must use the no-argument MigrationManager callback contract and verify active_geo_lookup.' );
plugin_architecture_assert( str_contains( $pickup_checkout_php_source, "'pickupRateCapabilities' => \$pickup_rate_capabilities" ) && str_contains( $pickup_checkout_php_source, 'private function pickup_rate_capabilities()' ) && str_contains( $pickup_checkout_php_source, "'prefetch_points'" ) && ! str_contains( $pickup_checkout_php_source, "'reload_on_viewport_change' => \$provider_capabilities" ) && str_contains( $pickup_checkout_rest_source, "'pickup_rate_capabilities' => \$pickup_rate_capabilities" ) && str_contains( $pickup_checkout_rest_source, 'private function pickup_rate_capabilities()' ) && str_contains( $pickup_checkout_rest_source, "'prefetch_points'" ) && str_contains( $pickup_checkout_source, 'pickupRateCapabilities = normalizePickupRateCapabilities' ) && str_contains( $pickup_checkout_source, 'function mergePickupRateCapabilitiesFromResponse(response)' ) && str_contains( $pickup_checkout_source, 'function withRateCapabilities(context, method)' ) && str_contains( $pickup_checkout_source, 'var contextPromise = refreshCheckoutContextOnce(700, { returnContext: true })' ) && str_contains( $pickup_checkout_source, 'return freshContext || initialContext();' ) && str_contains( $pickup_checkout_source, 'var modal = window.WDCPickupModal.create(labels);' ) && str_contains( $pickup_checkout_source, 'var map = window.WDCPickupMap.create' ) && ! str_contains( $pickup_checkout_source, 'function refreshModalContext(method)' ) && ! str_contains( $pickup_checkout_source, 'function setPickupOpenButtonsLoading(container, loading)' ) && str_contains( $pickup_checkout_source, 'withRateCapabilities(withPrefetch(withCarrierContext(resolvedContext, method), method), method)' ) && str_contains( $pickup_checkout_source, 'function prefetchIdentity(context, method)' ) && str_contains( $pickup_checkout_source, 'function prefetchIdentityMatches(cached, current)' ) && str_contains( $pickup_checkout_source, 'function pointPrefetchAllowed(method)' ) && str_contains( $pickup_checkout_source, "Object.prototype.hasOwnProperty.call(item, 'prefetch_points')" ) && str_contains( $pickup_checkout_source, 'capabilities.prefetch_points === false' ) && str_contains( $pickup_map_source, 'var fixedDatasetLoaded = false' ) && str_contains( $pickup_map_source, 'true !== options.forceRemote' ) && str_contains( $pickup_map_source, 'function listFollowsViewport()' ) && str_contains( $pickup_map_source, 'if (!viewportReloadRequired() && visiblePoints.length)' ) && ! str_contains( $pickup_map_source, 'ozon_delivery' ) && ! str_contains( $pickup_checkout_source, "carrier === 'ozon_delivery'" ), 'Generic pickup map must use the last live-confirmed stable opening lifecycle, keep active rate capabilities separate from destination context, disable point prefetch by generic capability, and preserve fixed-area viewport behavior without Ozon-specific JS branches.' );
foreach ( plugin_architecture_php_files( 'src/Carriers/OzonDelivery' ) as $ozon_production_file ) { $ozon_production_source = (string) file_get_contents( $ozon_production_file ); plugin_architecture_assert( ! str_contains( $ozon_production_source, 'dbDelta(' ) && ! str_contains( $ozon_production_source, 'CREATE TABLE' ) && ! str_contains( $ozon_production_source, 'ALTER TABLE' ) && ! str_contains( $ozon_production_source, 'wp-admin/includes/upgrade.php' ), 'Ozon production classes must not contain schema DDL: ' . basename( $ozon_production_file ) ); }
plugin_architecture_assert( ! str_contains( $ozon_pickup_repository_source, 'create_schema' ), 'Ozon pickup repository must not expose runtime schema management.' );

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
		\WallsShop\WDC\Carriers\OzonDelivery\Shipments\OzonDeliveryShipmentAdapter::class => array(
			'create_for_order' => 'Ozon order-aware create+approve hook used by ShipmentCreationService.',
			'mark_polling_exhausted' => 'Ozon cancellation polling exhaustion hook used by the status AJAX controller.',
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
plugin_architecture_assert( str_contains( $plugin_source, 'PekSettings::class' ) && str_contains( $plugin_source, 'PekApiClient::class' ) && str_contains( $plugin_source, 'PekSenderWarehouseSearchCache::class' ) && str_contains( $plugin_source, 'PekAdminNoticeStore::class' ) && str_contains( $plugin_source, 'PekAdminPage::class' ) && str_contains( $plugin_source, 'PekStatusAdminPage::class' ), 'Plugin.php must own PEK foundation and status admin DI wiring.' );
plugin_architecture_assert( is_file( plugin_architecture_path( 'src/Pickup/Providers/CarrierPickupPointProviderInterface.php' ) ) && is_file( plugin_architecture_path( 'src/Pickup/Providers/CarrierPickupPointProviderRegistry.php' ) ), 'Carrier pickup provider contract and registry must exist.' );
plugin_architecture_assert( str_contains( $plugin_source, 'CarrierPickupPointProviderRegistry::class' ) && str_contains( $plugin_source, 'PekPickupPointProvider::class' ), 'Plugin.php must register the pickup provider registry with the PEK provider.' );
plugin_architecture_assert( str_contains( $plugin_source, 'OzonDeliveryPickupPointProvider::class' ) && str_contains( $plugin_source, 'OzonDeliveryCarrier::class' ), 'Plugin.php must register the Ozon pickup provider and runtime carrier through canonical registries.' );
plugin_architecture_assert( ! str_contains( $plugin_source, 'CdekPickupPointProvider' ) && ! str_contains( $plugin_source, 'DpdPickupPointProvider' ) && ! str_contains( $plugin_source, 'YandexDeliveryPickupPointProvider' ) && ! str_contains( $plugin_source, 'RussianPostPickupPointProvider' ), 'Stage 2 must not migrate existing carriers into the new pickup provider registry.' );
$selection_query_source = plugin_architecture_source( 'src/Pickup/Providers/CarrierPickupPointSelectionQuery.php' );
plugin_architecture_assert( ! str_contains( $selection_query_source, 'fresh_validation_required' ), 'CarrierPickupPointSelectionQuery must not expose unused fresh_validation_required flag; resolve_selection is always fresh.' );
$pickup_points_rest_source = plugin_architecture_source( 'src/Pickup/Rest/PickupPointsRestController.php' );
$checkout_pickup_rest_source = plugin_architecture_source( 'src/Pickup/Rest/CheckoutPickupPointRestController.php' );
$checkout_orchestrator_source = plugin_architecture_source( 'src/Checkout/Runtime/CheckoutOrchestrator.php' );
$quote_cache_source = plugin_architecture_source( 'src/Checkout/Cache/QuoteCache.php' );
$checkout_validation_source = plugin_architecture_source( 'src/Checkout/WooCommerce/CheckoutValidation.php' );
plugin_architecture_assert( str_contains( $pickup_points_rest_source, 'CheckoutPickupPointProviderQueryResolver' ) && str_contains( $pickup_points_rest_source, 'registry_points_response' ) && str_contains( $pickup_points_rest_source, 'provider_query_resolver' ) && str_contains( $pickup_points_rest_source, '->resolve(' ), 'Public pickup points REST must use trusted server rate context for registry-backed PEK provider search.' );
plugin_architecture_assert( str_contains( $pickup_points_rest_source, 'registry_point_payload' ) && ! str_contains( $pickup_points_rest_source, 'ozon_delivery' ) && ! str_contains( $checkout_pickup_rest_source, 'ozon_delivery' ), 'Generic pickup REST must render provider payloads without Ozon-specific branches.' );
plugin_architecture_assert( str_contains( $checkout_pickup_rest_source, 'save_registry_backed_selection' ) && str_contains( $checkout_pickup_rest_source, 'resolve_selection' ) && str_contains( $checkout_pickup_rest_source, 'requires_rate_refresh' ) && strpos( $checkout_pickup_rest_source, 'save_registry_backed_selection( $request' ) < strpos( $checkout_pickup_rest_source, "'cdek' === \$carrier" ), 'Checkout pickup save must fresh-validate registry-backed PEK selections before legacy browser-payload fallback.' );
plugin_architecture_assert( str_contains( $checkout_validation_source, 'PekSettings::PICKUP_FAMILY' ) && str_contains( $checkout_validation_source, 'valid_pickup_selection_for_checkout( $family )' ) && ! str_contains( $checkout_validation_source, 'selection_from_posted_fields( $data, $point_id, $point_code, $rate );' . PHP_EOL . "\t\tif ( \$is_pek_family" ), 'Checkout POST must not reconstruct PEK terminal selections from hidden field payloads.' );
plugin_architecture_assert( ! str_contains( $checkout_orchestrator_source, 'PekCarrier' ) && ! str_contains( $checkout_orchestrator_source, "'pek'" ) && str_contains( $checkout_orchestrator_source, 'CarrierQuoteCacheContextProviderInterface' ), 'CheckoutOrchestrator must keep PEK out of special-case branches and consume only the generic optional carrier quote-cache context contract.' );
plugin_architecture_assert( str_contains( $quote_cache_source, 'carrier_context' ) && str_contains( $quote_cache_source, 'pickup_selection_cache_context' ) && str_contains( $quote_cache_source, '$destination->raw_address' ) && str_contains( $quote_cache_source, '$request->package->packaging_weight_g' ), 'QuoteCache must include generic destination, package and pickup-selection fingerprints plus optional carrier context.' );
plugin_architecture_assert( is_file( plugin_architecture_path( 'database/migrations/0056_add_jet_logistic_default_status_mappings.php' ) ) && ! is_file( plugin_architecture_path( 'templates' . '/.gitkeep' ) ), 'Jet default status data migration must exist and the unused template placeholder must be removed.' );
$jet_status_mapping_repository_source = plugin_architecture_source( 'src/Carriers/JetLogistic/Status/JetLogisticStatusMappingRepository.php' );
$jet_default_status_migration_source = plugin_architecture_source( 'database/migrations/0056_add_jet_logistic_default_status_mappings.php' );
plugin_architecture_assert( str_contains( $jet_status_mapping_repository_source, 'DEFAULT_MAPPINGS' ) && str_contains( $jet_status_mapping_repository_source, 'Доставка груза на склад приемки' ) && str_contains( $jet_status_mapping_repository_source, 'Отправка груза со склада приемки' ) && str_contains( $jet_status_mapping_repository_source, 'DeliveryStatus::IN_TRANSIT' ) && str_contains( $jet_default_status_migration_source, '->ensure_default_mappings()' ) && ! str_contains( $jet_default_status_migration_source, 'create_schema' ) && ! str_contains( $jet_default_status_migration_source, 'dbDelta' ), 'Jet default status mappings must be repository-owned and 0056 must be a data-only migration.' );
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
$pek_country_policy_source = plugin_architecture_source( 'src/Carriers/Pek/PekCountryPolicy.php' );
$pek_checkout_context_source = plugin_architecture_source( 'src/Carriers/Pek/Checkout/PekCheckoutQuoteContextResolver.php' );
$pek_shipment_adapter_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentAdapter.php' );
$pek_shipment_button_policy_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentButtonPolicy.php' );
$checkout_provider_query_resolver_source = plugin_architecture_source( 'src/Pickup/Providers/CheckoutPickupPointProviderQueryResolver.php' );
plugin_architecture_assert( str_contains( $pek_country_policy_source, "SENDER_COUNTRY = 'RU'" ) && str_contains( $pek_country_policy_source, "INTERNATIONAL_RECEIVER_COUNTRIES = array( 'AM', 'BY', 'KG', 'KZ' )" ) && str_contains( $pek_country_policy_source, 'supports_calculation_direction' ) && str_contains( $pek_country_policy_source, 'allows_automatic_shipment_create' ), 'PEK direction support must be owned by PekCountryPolicy with RU sender and AM/BY/KG/KZ receiver matrix.' );
plugin_architecture_assert( str_contains( $pek_quote_request_builder_source, 'supports_calculation_direction' ) && str_contains( $pek_quote_request_builder_source, "'needArrangeTransportationDocuments' => false" ) && ! str_contains( $pek_quote_request_builder_source, "'needArrangeTransportationDocuments' => true" ), 'PEK quote builder must use direction policy and never enable accompanying-documents service.' );
plugin_architecture_assert( str_contains( $pek_checkout_context_source, "'country=' . strtoupper( \$location->country_code )" ) && str_contains( $pek_checkout_context_source, 'strtoupper( trim( $location->country_code ) )' ) && str_contains( $pek_checkout_context_source, "'country_code' => \$query->normalized_country_code()" ), 'PEK checkout context must include receiver country in destination fingerprint and pickup provider query.' );
plugin_architecture_assert( str_contains( $plugin_source, 'PekSettings::CARRIER_KEY => array( $this->container->get( PekCheckoutQuoteContextResolver::class ), \'query_from_snapshot\' )' ) && str_contains( $checkout_provider_query_resolver_source, 'carrier_snapshot_resolvers' ) && str_contains( $checkout_provider_query_resolver_source, 'valid_carrier_snapshot_envelope' ), 'Checkout pickup map resolver must delegate PEK snapshot rehydration to the PEK-owned context through Plugin.php DI.' );
plugin_architecture_assert( str_contains( $pek_checkout_context_source, '$this->locations->find_by_id( $location_id )' ) && str_contains( $pek_checkout_context_source, '$country_code !== $canonical_country' ) && str_contains( $pek_checkout_context_source, '$this->address_builder->build( $location )' ) && str_contains( $pek_checkout_context_source, 'valid_snapshot_coordinates' ) && str_contains( $pek_checkout_context_source, 'fallback_address_fingerprint' ) && ! str_contains( $pek_checkout_context_source, "'fallback_address' =>" ) && ! str_contains( $pek_checkout_context_source, "'raw_address' =>" ) && ! str_contains( $pek_checkout_context_source, "'full_address' =>" ), 'PEK query_from_snapshot must rehydrate canonical location/address server-side and keep raw address out of safe snapshots.' );
plugin_architecture_assert( str_contains( $pek_terminal_service_source, "'country_code' => \$query_country" ) && str_contains( $pek_terminal_service_source, '$mapping_usable_state && $query_country !== $mapping_country' ) && str_contains( $pek_terminal_service_source, 'pek_destination_country_mismatch' ) && str_contains( $pek_terminal_service_source, 'terminal_search_authority' ), 'PEK terminal cache/search must remain country-aware, reject usable query/mapping country mismatch, and allow unsupported diagnostics to fall back to canonical query authority.' );
plugin_architecture_assert( str_contains( $pek_shipment_adapter_source, 'allows_automatic_create' ) && str_contains( $pek_shipment_adapter_source, 'pek_international_auto_create_disabled' ) && str_contains( $pek_shipment_adapter_source, 'international_manual_attach_message' ) && strpos( $pek_shipment_adapter_source, 'allows_automatic_create' ) < strpos( $pek_shipment_adapter_source, 'preregistration_submit' ), 'PEK shipment adapter must stop foreign automatic create before preregistration submit.' );
plugin_architecture_assert( str_contains( $pek_shipment_button_policy_source, 'allows_automatic_shipment_create' ) && str_contains( $pek_shipment_button_policy_source, "'cancel' => \$can_cancel" ), 'PEK cancellation button policy must keep cancellation mutation unavailable outside automatic-create directions.' );
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
$pek_parser_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentCreateResponseParser.php' );
$pek_destination_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentDestinationResolver.php' );
$pek_declared_value_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentDeclaredValueResolver.php' );
$pek_status_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentStatusService.php' );
$pek_status_normalizer_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentStatusResponseNormalizer.php' );
$pek_service_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentService.php' );
$pek_manual_context_source = plugin_architecture_source( 'src/Shipments/Pek/PekManualAttachContextResolver.php' );
$pek_status_mapping_source = plugin_architecture_source( 'src/Shipments/Pek/PekStatusMapping.php' );
$pek_button_policy_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentButtonPolicy.php' );
$pek_courier_address_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentCourierAddressResolver.php' );
$pek_document_provider_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentDocumentProvider.php' );
$pek_sms_source = plugin_architecture_source( 'src/Shipments/Pek/PekSmsReleaseAvailabilityService.php' );
$pek_settings_source = plugin_architecture_source( 'src/Carriers/Pek/PekSettings.php' );
$pek_phone_normalizer_source = plugin_architecture_source( 'src/Carriers/Pek/PekRuPhoneNormalizer.php' );
$pek_admin_source = plugin_architecture_source( 'src/Carriers/Pek/Admin/PekAdminPage.php' );
$pek_status_admin_source = plugin_architecture_source( 'src/Carriers/Pek/Admin/PekStatusAdminPage.php' );
$pek_credentials_source = plugin_architecture_source( 'src/Carriers/Pek/PekCredentials.php' );
$pek_sender_counterpart_source = plugin_architecture_source( 'src/Shipments/Pek/PekSenderCounterpartService.php' );
$pek_sender_warehouse_source = plugin_architecture_source( 'src/Carriers/Pek/Api/PekSenderWarehouseService.php' );
$pek_sender_resolver_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentSenderWarehouseResolver.php' );
$checkout_persister_source = plugin_architecture_source( 'src/Checkout/WooCommerce/OrderShippingMetaPersister.php' );
$shipment_address_ajax_source_for_pek = plugin_architecture_source( 'src/Shipments/Admin/Ajax/ShipmentAddressAjaxController.php' );
$generic_picker_source = plugin_architecture_source( 'assets/admin/shipments/shipment-picker.js' );
$pek_picker_source = plugin_architecture_source( 'assets/admin/shipments/extensions/pek.js' );
plugin_architecture_assert( str_contains( $draft_factory_source, 'create_pek_request_from_order' ) && str_contains( $draft_factory_source, 'create_pek_request_from_admin_data' ), 'PEK shipment request creation must be wired in the carrier-aware draft factory.' );
plugin_architecture_assert( str_contains( $pek_status_mapping_source, 'SettingsRepository $settings' ) && str_contains( $plugin_source, 'new PekStatusMapping( $this->container->get( SettingsRepository::class ) )' ) && str_contains( plugin_architecture_source( 'src/Infrastructure/Settings/SettingsRepository.php' ), "'pek_status_mapping' => PekStatusMapping::default_mapping()" ), 'PEK status mapping must be SettingsRepository-backed without a migration/table.' );
plugin_architecture_assert( str_contains( $pek_status_mapping_source, 'public static function statuses' ) && str_contains( $pek_status_mapping_source, 'public static function default_mapping' ) && str_contains( $pek_status_mapping_source, 'sanitize_mapping' ) && str_contains( $pek_status_mapping_source, 'ISSUED_PLACES_PATTERN_KEY' ) && str_contains( $pek_status_mapping_source, "'pickup'" ) && str_contains( $pek_status_mapping_source, "'courier'" ), 'PEK status mapping must expose a fixed catalog, defaults, sanitize path, pattern row and separate pickup/courier mappings.' );
plugin_architecture_assert( str_contains( $pek_status_mapping_source, 'is_cancelled_status' ) && str_contains( $pek_status_mapping_source, "'аннулировано до приемки груза' === self::normalize_status" ) && str_contains( $pek_status_mapping_source, 'is_pre_acceptance_status' ) && str_contains( $pek_status_mapping_source, 'is_accepted_status' ) && str_contains( $pek_status_mapping_source, 'is_terminal_status' ), 'PEK cancellation, acceptance, terminal and pre-acceptance carrier facts must remain immutable raw-status predicates.' );
plugin_architecture_assert( str_contains( $pek_status_admin_source, 'Статусы ПЭК' ) && str_contains( $pek_status_admin_source, 'ПЭК до терминала' ) && str_contains( $pek_status_admin_source, 'ПЭК курьером' ) && str_contains( $pek_status_admin_source, 'DeliveryStatus::labels()' ) && str_contains( $pek_status_admin_source, 'save_pek_statuses' ) && ! str_contains( $pek_status_admin_source, 'PekApiClient' ) && ! str_contains( $pek_status_admin_source, 'cargo_status' ) && ! str_contains( $pek_status_admin_source, 'statustables' ), 'PEK status admin tab must render/save local SettingsRepository mappings without PEK API calls.' );
plugin_architecture_assert( ! str_contains( $pek_adapter_source, 'order_stub' ) && str_contains( $pek_adapter_source, 'public function create( ShipmentCreateRequest $request ): ShipmentCreateResult' ) && str_contains( $pek_adapter_source, '$this->order_from_request( $request )' ), 'PEK adapter direct create contract must load a real order and must not call an undefined order stub.' );
plugin_architecture_assert( str_contains( $pek_adapter_source, 'safe_create_failure_reference' ) && str_contains( $pek_adapter_source, 'safe_api_error_message' ) && str_contains( $pek_adapter_source, 'safe_field_errors' ) && str_contains( $pek_adapter_source, 'response_shape' ) && str_contains( $pek_adapter_source, 'sensitive_values_from_built_request' ) && ! str_contains( $pek_adapter_source, "'raw_response'" ) && ! str_contains( $pek_adapter_source, "'raw_request'" ), 'PEK deterministic create rejection must project only closed, redacted safe diagnostics without raw request/response persistence.' );
plugin_architecture_assert( ! str_contains( $shipment_creation_source, 'PekSettings::CARRIER_KEY' ) && ! str_contains( $shipment_creation_source, "'pek'" ) && ! str_contains( $shipment_creation_source, '"pek"' ), 'ShipmentCreationService must not gain a PEK carrier branch.' );
plugin_architecture_assert( ! str_contains( $shipment_metabox_source, 'PekSettings::CARRIER_KEY' ), 'OrderShipmentsMetabox must not gain a PEK render branch.' );
plugin_architecture_assert( str_contains( $pek_request_builder_source, "'common' => \$common" ) && str_contains( $pek_request_builder_source, "'sender' => \$this->sender_payload" ) && str_contains( $pek_request_builder_source, "'cargos' => array(" ), 'PEK preregistration submit payload must use root common/sender/cargos hierarchy.' );
plugin_architecture_assert( ! str_contains( $pek_request_builder_source, "'payer' => 'sender'" ) && ! str_contains( $pek_request_builder_source, '"payer": "sender"' ) && str_contains( $pek_request_builder_source, "'payer' => array( 'type' => 1 )" ), 'PEK services must use documented numeric payer object.' );
plugin_architecture_assert( ! str_contains( $pek_request_builder_source . $pek_cargo_builder_source, "'smsRelease'" ) && ! str_contains( $pek_request_builder_source . $pek_cargo_builder_source, '"smsRelease"' ), 'PEK submit payload must not send invented smsRelease field.' );
plugin_architecture_assert( str_contains( $pek_cargo_builder_source, "'cargoPlaceList' => \$places" ) && ! str_contains( $pek_cargo_builder_source, "'position' =>" ) && ! str_contains( $pek_cargo_builder_source, "'cargoDescription'" ) && ! str_contains( $pek_cargo_builder_source, "'cost' =>" ) && ! str_contains( $pek_cargo_builder_source, 'declaredCost' ), 'PEK cargo places must use the official cargo common shape without declaredCost or legacy aliases.' );
plugin_architecture_assert( str_contains( $draft_factory_source, 'pek_transport_weight_g' ) && str_contains( $draft_factory_source, "\$package['final_weight_g'] ?? \$package['package_weight_with_packaging_g']" ) && str_contains( $draft_factory_source, "'pek_product_weight_g' => max( 0, \$product_weight )" ), 'PEK factory must keep transport weight with packaging distinct from product-only sealing weight and prefer final_weight_g.' );
plugin_architecture_assert( str_contains( $checkout_persister_source, "'block_type'" ) && str_contains( $checkout_persister_source, "'flat_type'" ) && str_contains( $checkout_persister_source, "'house_type_full'" ) && str_contains( $checkout_persister_source, "'block_type_full'" ) && str_contains( $checkout_persister_source, "'flat_type_full'" ) && str_contains( plugin_architecture_source( 'assets/frontend/checkout-address-suggestions.js' ), "'dadata_flat_type_full'" ), 'Generic checkout order persister must preserve short/full DaData house/block/flat type fields without a PEK-specific branch.' );
plugin_architecture_assert( str_contains( $plugin_source, 'new OrderShippingMetaPersister( $this->container->get( CheckoutSessionManager::class ), $this->container->get( DeliveryDateFormatter::class ), $this->container->get( DeliveryCalculationDataBuilder::class ), $this->container->get( LocationRepository::class )' ), 'Plugin.php must inject LocationRepository into the generic order shipping meta persister.' );
plugin_architecture_assert( str_contains( $checkout_persister_source, 'trusted_location_id_for_rate' ) && str_contains( $checkout_persister_source, "\$map['_wdc_platform_location_id'] = \$trusted_location_id" ) && str_contains( $checkout_persister_source, "'location_id'" ) && str_contains( $checkout_persister_source, "\$order_meta['_wdc_platform_location_id']" ) && str_contains( $checkout_persister_source, 'location_matches_destination_country' ), 'Generic checkout order persister must persist validated canonical WDC location_id into order meta and calculation destination.' );
plugin_architecture_assert( str_contains( $checkout_persister_source, '$session_id > 0 && $rate_id > 0 && $session_id !== $rate_id' ) && str_contains( $checkout_persister_source, '! $location->active' ) && str_contains( $checkout_persister_source, '$location->country_code' ), 'Generic checkout order persister must fail closed on conflicting IDs, inactive locations, and country mismatch.' );
plugin_architecture_assert( ! str_contains( $checkout_persister_source, "\$data['wdc_platform_location_id']" ) && ! str_contains( $checkout_persister_source, "_wdc_platform_rate_meta']" ), 'Generic checkout order persister must not trust browser location_id or add historical rate-meta recovery.' );
plugin_architecture_assert( str_contains( $pek_cargo_builder_source, 'hundredths_kg' ) && str_contains( $pek_cargo_builder_source, 'hundredths_m3' ) && str_contains( $pek_cargo_builder_source, '$raw_volume_sum_cm3 +=' ) && ! str_contains( $pek_cargo_builder_source, 'ceil( $value * 100' ) && ! str_contains( $pek_cargo_builder_source, 'function ceil2' ) && ! str_contains( $pek_cargo_builder_source, 'count( $request->places ) > 50' ), 'PEK cargo measures must use integer-scaled conversion, sum raw volume before rounding, and must not cap places at 50.' );
plugin_architecture_assert( str_contains( $pek_recipient_builder_source, "'title' => \$title" ) && str_contains( $pek_recipient_builder_source, "'person' => \$title" ) && str_contains( $pek_recipient_builder_source, "'personPhones' => array(" ) && str_contains( $pek_recipient_builder_source, "'firstName' => \$name['firstName']" ) && str_contains( $pek_recipient_builder_source, "'lastName' => \$name['lastName']" ) && ! str_contains( strtolower( $pek_recipient_builder_source ), 'patronymic' ) && ! str_contains( $pek_recipient_builder_source, 'middleName' ) && ! str_contains( $pek_recipient_builder_source, 'secondName' ) && str_contains( $pek_recipient_builder_source, "'addressStock'" ) && str_contains( $pek_recipient_builder_source, 'PekShipmentCourierAddressResolver' ) && str_contains( $pek_courier_address_source, 'house' ) && str_contains( $pek_courier_address_source, 'улицей и номером дома' ) && ! str_contains( $pek_recipient_builder_source, "'identityCard'" ) && ! str_contains( $pek_recipient_builder_source, "'passport'" ) && ! str_contains( $pek_recipient_builder_source, "'phone' => \$phone," ), 'PEK physical receiver must use only firstName/lastName title/person/individual/personPhones/addressStock shape and avoid patronymic, passport aliases, or scalar phone.' );
plugin_architecture_assert( str_contains( $pek_parser_source, "'documentId'" ) && str_contains( $pek_parser_source, "'cargoCode'" ) && str_contains( $pek_parser_source, 'string_identifier' ) && str_contains( $pek_parser_source, 'is_string( $value )' ) && ! str_contains( $pek_parser_source, "'cargoBarCode'" ) && ! str_contains( $pek_parser_source, "'positionBarCodes'" ), 'PEK create parser must validate response identifier types before casting and use preregistration fields, not cargos/status aliases.' );
plugin_architecture_assert( ! str_contains( $pek_request_builder_source, "'transporting' => array( 'enabled'" ), 'PEK mandatory transporting service must not send enabled flag.' );
plugin_architecture_assert( ! str_contains( $pek_declared_value_source, 'wc_prices_include_tax' ), 'PEK declared value must not depend on current WooCommerce tax setting.' );
plugin_architecture_assert( str_contains( $pek_destination_source, 'PekLocationResolver' ) && str_contains( $pek_destination_source, '$this->locations->resolve_delivery_address_for_shipment' ) && str_contains( $pek_location_resolver_source, 'resolve_delivery_address_for_shipment' ) && str_contains( $pek_location_resolver_source, 'find_zone_by_address( $address )' ) && str_contains( $pek_destination_source, "'fresh_address_zone'" ) && ! str_contains( $pek_destination_source, '$this->locations->resolve_for_shipment' ) && ! str_contains( $pek_destination_source, 'find_zone_by_coordinates' ) && ! str_contains( $pek_destination_source, "\$request->meta['pek_receiver_branch_id'] ?? '' )" ), 'PEK courier shipment destination must use actual recipient address findzone authority and must not fall back to stale/meta branch IDs or canonical coordinates.' );
plugin_architecture_assert( str_contains( $pek_adapter_source, "'method' => 'POST'" ) && str_contains( $pek_adapter_source, "'path' => '/preregistration/submit/'" ) && str_contains( $pek_adapter_source, "'body' => \$built['preview']" ), 'PEK preview must return canonical method/path/body/errors/warnings envelope.' );
plugin_architecture_assert( str_contains( $pek_adapter_source, "'can_create' => ! empty( \$policy['create'] )" ) && str_contains( $pek_adapter_source, "'can_attach_manual' => ! empty( \$policy['manual_attach'] )" ), 'PEK status payload must expose generic create and manual-attach capabilities.' );
plugin_architecture_assert( str_contains( $pek_adapter_source, '$submitted' ) && str_contains( $pek_adapter_source, "error_code: 'pek_uncertain_submit'" ) && str_contains( $pek_adapter_source, 'is_uncertain_submit_exception' ) && str_contains( $pek_adapter_source, '$status >= 500' ) && str_contains( $pek_adapter_source, 'private function safe_summary' ) && ! str_contains( $pek_adapter_source, "'address' =>" ), 'PEK post-submit parser/5xx failures must become uncertain and pending summary must exclude address/PII.' );
plugin_architecture_assert( strpos( $pek_status_source, "unset( \$status['actual_cost_candidate'] );" ) < strpos( $pek_status_source, 'save_for_carrier' ), 'PEK status service must remove actual-cost candidate before persisting shipment status.' );
plugin_architecture_assert( str_contains( $shipment_address_ajax_source_for_pek, 'ShipmentModalRequestMapper' ) && str_contains( $shipment_address_ajax_source_for_pek, 'PickupCargoConstraints' ) && str_contains( $pek_sender_warehouse_source, 'search( string $address, ?PickupCargoConstraints $constraints = null' ), 'PEK sender warehouse picker must use server-validated cargo constraints.' );
plugin_architecture_assert( str_contains( $pek_sender_warehouse_source, 'function validate_snapshot' ) && str_contains( $pek_sender_resolver_source, 'validate_snapshot' ) && ! str_contains( $pek_sender_resolver_source, 'validate_and_select' ), 'PEK shipment sender warehouse validation must be fresh and non-mutating.' );
plugin_architecture_assert( str_contains( $draft_factory_source, 'pek_service_variants' ) && str_contains( $draft_factory_source, 'ПЭК до терминала' ) && str_contains( $draft_factory_source, 'ПЭК курьером' ) && str_contains( $draft_factory_source, 'Сценарий доставки ПЭК изменился' ), 'PEK draft factory must expose one trusted modal scenario and reject browser delivery mode changes.' );
plugin_architecture_assert( str_contains( $draft_factory_source, 'pek_sender_warehouse_override_source' ) && str_contains( $draft_factory_source, 'pek_sender_warehouse_override_id' ) && str_contains( $draft_factory_source, 'pek_warehouse_uuid' ) && str_contains( $draft_factory_source, 'Выбранный склад самопривоза ПЭК потерял актуальность' ) && str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'Sender warehouse override ID without explicit source must be ignored' ), 'PEK sender warehouse modal override must require an explicit source/canonical UUID pair and must not silently fall back to default when the pair is incomplete.' );
plugin_architecture_assert( str_contains( $pek_sender_warehouse_source, 'normalize_warehouse_id' ) && str_contains( $pek_sender_warehouse_source, 'validate_for_shipment' ) && str_contains( $pek_sender_warehouse_source, 'nearest_exact_match' ) && str_contains( $pek_sender_warehouse_source, 'constraints_fingerprint' ) && str_contains( $pek_sender_warehouse_source, "'/branches/nearestdepartments/'" ) && str_contains( $pek_sender_warehouse_source, 'warehouse_id_hash' ) && str_contains( $pek_sender_warehouse_source, 'matched_id_hash' ), 'PEK sender warehouse validation must use nearestdepartments authority with canonical UUIDs, constraints fingerprinting, and safe ID hashes.' );
plugin_architecture_assert( str_contains( $pek_sender_warehouse_source, 'paid_count' ) && str_contains( $pek_sender_warehouse_source, 'free_count' ) && str_contains( $pek_sender_warehouse_source, 'exact_match_count' ) && str_contains( $pek_sender_warehouse_source, "'nearest_cached_selection'" ) && str_contains( $pek_sender_warehouse_source, "'nearest_fresh_revalidation'" ) && str_contains( $pek_sender_warehouse_source, "normalize_department_list( is_array( \$response['freeDepartments']" ), 'PEK sender warehouse validation must diagnose nearest lookup safely and accept only freeDepartments for sender self-delivery.' );
plugin_architecture_assert( strpos( $pek_sender_warehouse_source, 'nearest_departments(' ) < strpos( $pek_sender_warehouse_source, 'save_for_current_user( $result )' ) && strpos( $pek_sender_warehouse_source, 'save_for_current_user( $result )' ) < strpos( $pek_sender_warehouse_source, 'clear_last_search_for_current_user()' ) && str_contains( $pek_sender_warehouse_source, 'persisted_snapshot_access_fallback' ) && str_contains( $pek_sender_warehouse_source, 'fallback_allowed_for_exception' ) && str_contains( $pek_sender_warehouse_source, 'pek_http_403' ), 'PEK sender warehouse search must replace cache only after successful nearestdepartments and validation may fallback only from trusted persisted snapshot on non-authoritative read-only API failures.' );
plugin_architecture_assert( str_contains( $shipment_address_ajax_source_for_pek, 'safe_pek_sender_warehouse_diagnostic' ) && str_contains( $shipment_address_ajax_source_for_pek, 'wp_send_json_error' ) && str_contains( $shipment_address_ajax_source_for_pek, 'Не удалось получить список складов ПЭК' ), 'PEK sender warehouse admin AJAX search failures must return controlled safe JSON instead of uncaught PekApiException.' );
plugin_architecture_assert( str_contains( $generic_picker_source, 'window.wdcShipmentPickupPicker' ) && str_contains( $generic_picker_source, 'settings.onChoose(point) === false' ) && str_contains( $pek_picker_source, 'window.wdcShipmentPickupPicker' ) && str_contains( $pek_picker_source, 'handleClick: function (event)' ) && str_contains( $pek_picker_source, 'picker.open(form' ) && str_contains( $pek_picker_source, 'data-wdc-pek-sender-warehouse-context' ) && str_contains( $pek_picker_source, 'isCanonicalWarehouseId' ) && ! str_contains( $generic_picker_source, "carrier === 'pek'" ) && ! str_contains( $pek_picker_source, 'wdc:shipment-pickup-search-open' ) && ! str_contains( $pek_picker_source, 'addEventListener(\'click\'' ), 'PEK sender warehouse picker must consume a working generic picker API through carrier handleClick, require warehouseId, and avoid a generic PEK branch or duplicate modal listeners.' );
plugin_architecture_assert( str_contains( $pek_request_builder_source, 'PekShipmentDestinationResolver' ) && str_contains( $pek_request_builder_source, 'PekShipmentProductWeightResolver' ), 'PEK preview/create path must run fresh destination and product-weight resolvers.' );
$pek_carrier_source_for_identity = plugin_architecture_source( 'src/Carriers/Runtime/PekCarrier.php' );
plugin_architecture_assert( str_contains( $pek_carrier_source_for_identity, "\$meta['location_id'] = \$location_id" ) && str_contains( $pek_carrier_source_for_identity, "\$meta['destination_fingerprint'] = \$destination_fingerprint" ), 'PEK checkout rates must persist trusted destination identity in generic rate_meta for pickup and courier.' );
$pek_order_request_block = substr( $draft_factory_source, (int) strpos( $draft_factory_source, 'private function create_pek_request_from_order' ), 5000 );
plugin_architecture_assert( ! str_contains( $pek_order_request_block, '_wdc_platform_location_id' ) && str_contains( $pek_order_request_block, "\$rate_meta['location_id']" ) && str_contains( $pek_order_request_block, "\$calculation['destination']['location_id']" ), 'PEK draft factory must read trusted rate_meta/calculation location identity and must not rely on dead _wdc_platform_location_id order meta.' );
plugin_architecture_assert( str_contains( $pek_destination_source, 'resolve_canonical_location_identity' ) && str_contains( $pek_destination_source, 'selected_location_fias_fallback' ) && str_contains( $pek_destination_source, 'find_by_fias_id' ) && strpos( $pek_destination_source, 'resolve_canonical_location_identity' ) < strpos( $pek_destination_source, 'resolve_delivery_address_for_shipment' ), 'PEK courier destination must recover canonical identity from persisted selected-location FIAS before findzonebyaddress when numeric rate identity is missing.' );
plugin_architecture_assert( str_contains( $pek_sms_source, "specialCondition'") && str_contains( $pek_sms_source, 'CODMaxSum' ) && str_contains( $pek_sms_source, 'MoneyParser::numeric_to_kopecks' ), 'PEK SMS availability must scope CODMaxSum to the SMS special-condition row and parse money strictly.' );
plugin_architecture_assert( str_contains( $pek_sms_source, '(string) $declared_value_kopecks' ) && ! str_contains( $pek_sms_source, 'min( $declared_value_kopecks' ), 'PEK SMS cache key must include exact declared value without artificial cap.' );
plugin_architecture_assert( str_contains( $pek_sms_source, 'available_types' ) && str_contains( $pek_sms_source, '! is_int( $type )' ) && ! str_contains( $pek_sms_source, "array_map( 'intval'" ), 'PEK availableTypesOfDelivery must use strict integer list validation without intval coercion.' );
plugin_architecture_assert( str_contains( $pek_sms_source, 'specialConditionsWithParams' ) && str_contains( $pek_sms_source, 'array_is_list' ) && str_contains( $pek_sms_source, '1 !== count( $matches )' ) && ! str_contains( $pek_sms_source, "'parameters'" ), 'PEK SMS specialConditionsWithParams must be a strict list, require exactly one SMS UID row, and avoid unconfirmed parameters alias.' );
plugin_architecture_assert( str_contains( $pek_sms_source, 'catch ( PekApiException $exception )' ) && str_contains( $pek_sms_source, 'api_diagnostic' ) && str_contains( $pek_sms_source, 'contract_diagnostic' ) && str_contains( $pek_sms_source, "'sms_geography'" ) && str_contains( $pek_sms_source, "'sms_private_token'" ) && str_contains( $pek_sms_source, "'sms_connected_services'" ) && str_contains( $pek_sms_source, "'sms_service_contract'" ) && str_contains( $pek_sms_source, "'sms_limit_contract'" ) && str_contains( $pek_sms_source, "'sms_business_unavailable'" ), 'PEK SMS availability failures must retain safe staged diagnostics instead of collapsing all read-only failures into one generic error.' );
plugin_architecture_assert( str_contains( $pek_sms_source, "'api_error_message'" ) && str_contains( $pek_sms_source, "'field_errors'" ) && str_contains( $pek_sms_source, "'response_shape'" ) && str_contains( $pek_sms_source, 'safe_response_shape' ) && str_contains( $pek_sms_source, 'redact_active_private_token' ) && str_contains( $pek_sms_source, 'PekQuoteMessageSanitizer' ) && ! str_contains( $pek_sms_source, "'raw_response'" ) && ! str_contains( $pek_sms_source, "'raw_request'" ) && ! str_contains( $pek_sms_source, "'request_body'" ), 'PEK SMS diagnostics must use the shared safe sanitizer and a closed allowlist without raw response/request persistence.' );
plugin_architecture_assert( ! str_contains( $pek_sms_source, 'persisted_snapshot_access_fallback' ) && ! str_contains( $pek_sms_source, 'fallback_used' ) && ! str_contains( $pek_sms_source, 'Используются ранее подтверждённые данные' ), 'PEK SMS availability must remain fail-closed and must not gain a stale-authorization fallback.' );
plugin_architecture_assert( str_contains( $pek_request_builder_source, 'PekSmsReleaseValidationException' ) && str_contains( $pek_request_builder_source, "'sms_diagnostic'" ) && str_contains( $pek_adapter_source, 'PekSmsReleaseValidationException' ) && str_contains( $pek_adapter_source, "'sms_release_confirmed' => false" ) && str_contains( $pek_adapter_source, "'sms_diagnostic' => \$e->diagnostic()" ), 'PEK safe preview must expose SMS staged diagnostics without building a preregistration submit payload after SMS precondition failure.' );
$shipment_preview_source = plugin_architecture_source( 'assets/admin/shipments/shipment-preview.js' );
plugin_architecture_assert( str_contains( $shipment_preview_source, "dispatchShipmentCarrierHook('afterPreviewUpdated'" ) && str_contains( $pek_picker_source, 'afterPreviewUpdated' ) && str_contains( $pek_picker_source, 'smsStageLabel' ) && str_contains( $pek_picker_source, 'Проверка выдачи по СМС' ) && str_contains( $pek_picker_source, 'sms_diagnostic' ) && str_contains( $pek_picker_source, 'field_errors' ) && str_contains( $pek_picker_source, 'response_shape' ) && ! str_contains( $pek_picker_source, 'innerHTML' ), 'PEK shipment modal UI must render safe staged SMS diagnostics through a generic preview hook without raw HTML interpretation.' );
plugin_architecture_assert( str_contains( $pek_courier_address_source, 'destination_scope' ) && str_contains( $pek_courier_address_source, 'dadata_matches_current_woo' ) && str_contains( $pek_courier_address_source, 'get_\' . $scope . \'_country' ) && str_contains( $pek_courier_address_source, 'settlement' ) && str_contains( $pek_courier_address_source, 'город\\s+федерального\\s+значения' ), 'PEK courier address resolver must bind DaData to current Woo destination scope, avoid pre-validation RU hardcode, preserve settlement, and deduplicate federal cities.' );
plugin_architecture_assert( str_contains( $pek_courier_address_source, 'normalize_house_component' ) && str_contains( $pek_courier_address_source, 'canonical_block_type' ) && str_contains( $pek_courier_address_source, "'_' . \$scope . '_dadata_block_type'" ), 'PEK courier resolver must consume explicit DaData block type when building the house component.' );
plugin_architecture_assert( str_contains( $pek_courier_address_source, "?<block_type>" ) && str_contains( $pek_courier_address_source, 'стр. 2' ) || str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'дом 10, стр. 2' ), 'PEK courier parser must cover comma-separated corpus/structure address forms.' );
plugin_architecture_assert( str_contains( $pek_courier_address_source, 'canonical_unit_type' ) && str_contains( $pek_courier_address_source, "'_' . \$scope . '_dadata_flat_type'" ) && str_contains( $pek_courier_address_source, "'офис' => 'офис'" ) && str_contains( $pek_courier_address_source, "'пом', 'помещение' => 'помещение'" ), 'PEK courier resolver must use flat_type and must not format office/premise as apartment.' );
plugin_architecture_assert( str_contains( $pek_courier_address_source, "_dadata_house_type_full" ) && str_contains( $pek_courier_address_source, "_dadata_block_type_full" ) && str_contains( $pek_courier_address_source, "_dadata_flat_type_full" ) && str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'PEK resolver must prefer full DaData unit type over short compatibility value' ), 'PEK courier resolver must prefer full DaData unit type values over short compatibility values.' );
plugin_architecture_assert( str_contains( $pek_courier_address_source, "?<unit_type>" ) && str_contains( $pek_courier_address_source, "\$matches['unit_type']" ) && str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'Woo address_2 office must remain office' ) && str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'Woo address_2 premise must remain premise' ), 'PEK raw Woo address fallback must preserve office/premise/apartment unit type.' );
plugin_architecture_assert( str_contains( $pek_courier_address_source, 'canonical_house_type' ) && str_contains( $pek_courier_address_source, 'Не удалось однозначно определить тип дома' ) && str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'Unsupported house_type must fail with safe public message' ), 'PEK courier resolver must not ignore unsupported non-empty house_type values.' );
plugin_architecture_assert( str_contains( $pek_settings_source, '$candidate = array(' ) && strpos( $pek_settings_source, 'foreach ( $candidate as $key => $value )' ) > strpos( $pek_settings_source, 'Некорректный email отправителя ПЭК' ), 'PEK settings save must validate candidate values before writing settings.' );
plugin_architecture_assert( str_contains( $pek_settings_source, "'/^\\d{10}$/'" ) && str_contains( $pek_settings_source, "'/^\\d{12}$/'" ) && ! str_contains( $pek_settings_source, "preg_replace( '/\\D+/', '', (string) ( \$input[ self::SENDER_INN_KEY ]" ), 'PEK sender INN/KPP settings must be rejected when malformed instead of sanitized into validity.' );
plugin_architecture_assert( str_contains( $pek_phone_normalizer_source, "preg_match( '/^[\\d+ ()\\-]+$/'" ) && str_contains( $pek_phone_normalizer_source, "preg_replace( '/[ ()\\-]+/'" ) && str_contains( $pek_phone_normalizer_source, "'/^8(\\d{10})$/'" ) && str_contains( $pek_settings_source, 'PekRuPhoneNormalizer' ) && str_contains( $pek_recipient_builder_source, 'PekRuPhoneNormalizer' ) && str_contains( $pek_request_builder_source, 'PekRuPhoneNormalizer' ), 'PEK sender and recipient phones must share a carrier-owned normalizer that rejects letters/control characters before RU normalization.' );
plugin_architecture_assert( strpos( $pek_phone_normalizer_source, "preg_match( '/[\\x00-\\x1F\\x7F-\\x9F]/u', \$raw )" ) < strpos( $pek_phone_normalizer_source, '$raw = trim( $raw );' ) && str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'Malformed recipient phone must fail before trimming/stripping' ), 'PEK phone normalizer must reject control characters before trimming.' );
plugin_architecture_assert( ! str_contains( $pek_settings_source . $pek_recipient_builder_source . $pek_request_builder_source, '?PekRuPhoneNormalizer' ) && ! str_contains( $pek_settings_source . $pek_recipient_builder_source . $pek_request_builder_source, 'new PekRuPhoneNormalizer' ), 'PEK phone normalizer must be a required DI dependency in settings, request builder, and recipient builder.' );
plugin_architecture_assert( str_contains( $pek_credentials_source, 'account_login_hash' ) && str_contains( $pek_sender_counterpart_source, "'account_login_hash'" ) && str_contains( $pek_request_builder_source, "snapshot['account_login_hash']" ), 'PEK counterpart snapshot must bind to the current PEK account login hash.' );
plugin_architecture_assert( str_contains( $pek_admin_source, '$old_login_hash' ) && str_contains( $pek_admin_source, 'save_sender_counterpart( \'\', array() )' ), 'PEK admin settings save must invalidate counterpart verification when the PEK login changes.' );
plugin_architecture_assert( str_contains( $pek_sender_counterpart_source, "preg_match( '/^\\d+$/'" ) && str_contains( $pek_sender_counterpart_source, "preg_match( '/^\\d{9}$/'" ) && ! str_contains( $pek_sender_counterpart_source, 'preg_replace( \'/\\D+/\', \'\', $legal' ), 'PEK counterpart API INN/KPP must be strictly validated instead of cleaned into validity.' );
plugin_architecture_assert( str_contains( $pek_destination_source, 'coordinate_pair' ) && str_contains( $pek_destination_source, 'strict_float' ) && str_contains( $pek_destination_source, 'is_bool( $value )' ) && str_contains( $pek_destination_source, 'is_finite' ) && str_contains( $pek_destination_source, 'Некорректные координаты пункта ПЭК' ), 'PEK shipment pickup coordinates must use strict pair validation before final numeric serialization.' );
plugin_architecture_assert( str_contains( $pek_admin_source, 'Склад можно заменить для конкретного отправления' ) && str_contains( $pek_admin_source, 'documented service' ) && ! str_contains( $pek_admin_source, 'modal override на этом этапе ещё не реализован' ), 'PEK admin copy must reflect implemented sender warehouse modal override and documented shipment sealing semantics.' );
plugin_architecture_assert( str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'shipping Dadata' ) || str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'shipping DaData' ), 'PEK integration smoke must cover effective courier destination scope and DaData authority.' );
plugin_architecture_assert( str_contains( $pek_service_source, 'PekManualAttachContextResolver' ) && str_contains( $pek_service_source, "\$this->statuses->fetch( \$code, (string) \$context['delivery_type'] )" ) && str_contains( $pek_manual_context_source, 'OrderShipmentDraftFactory' ) && str_contains( $pek_manual_context_source, 'OrderShipmentRepository' ), 'PEK manual attach must restore delivery/context server-side and must not require browser delivery_type.' );
plugin_architecture_assert( str_contains( $pek_service_source, 'ShipmentActualCostService' ) && str_contains( $pek_service_source, 'apply_carrier_cost' ) && strpos( $pek_service_source, "unset( \$status['actual_cost_candidate'] );" ) < strpos( $pek_service_source, 'save_for_carrier' ), 'PEK manual attach must merge actual cost through the shared service after removing object candidates from persisted data.' );
plugin_architecture_assert( str_contains( $pek_service_source, '$this->statuses->fetch' ) && str_contains( $pek_service_source, 'pek_take_on_stock_datetime' ) && str_contains( $pek_service_source, 'is_pre_acceptance_status' ) && str_contains( $pek_status_mapping_source, 'is_pre_acceptance_status' ), 'PEK cancellation must fresh-check acceptance and require explicit pre-acceptance status before API cancellation.' );
plugin_architecture_assert( str_contains( $pek_button_policy_source, 'PENDING_CREATION_IN_CARRIER' ) && 1 === substr_count( $pek_button_policy_source, 'universal_status_code' ) && str_contains( $pek_button_policy_source, '$pending_status' ) && str_contains( $pek_button_policy_source, "'manual_attach' => true" ) && str_contains( $pek_button_policy_source, "'remove' => true" ) && str_contains( $pek_button_policy_source, 'is_pre_acceptance_status' ) && str_contains( $pek_button_policy_source, 'is_accepted_status' ) && str_contains( $pek_button_policy_source, 'is_terminal_status' ) && ! str_contains( $pek_button_policy_source, 'DeliveryStatus::terminal()' ), 'PEK pending creation record may read universal status, but cancellation/accepted/terminal button policy must use immutable external-status facts instead of editable universal mapping.' );
plugin_architecture_assert( str_contains( $pek_service_source, 'pek_reconciled_pending_correlation' ) && str_contains( $pek_service_source, 'original_created_at' ) && str_contains( $pek_service_source, "'pending_creation_in_carrier' => false" ), 'PEK manual reconciliation must preserve correlation/created_at and clear active pending state.' );
plugin_architecture_assert( str_contains( $pek_document_provider_source, "\$shipment['pek_cargo_codes']" ) && ! str_contains( $pek_document_provider_source, "count( is_array( \$shipment['pek_position_barcodes']" ), 'PEK type=multiple document action must not be based on position barcode count.' );
plugin_architecture_assert( str_contains( $pek_request_builder_source, "if ( '' !== \$client_card )" ) && ! str_contains( $pek_request_builder_source, "'counterpartClientCard' => \$this->settings->client_card()" ), 'PEK request builder must not serialize empty counterpartClientCard.' );
plugin_architecture_assert( str_contains( $pek_courier_address_source, "'' !== trim( \$woo->raw_address )" ) && str_contains( $pek_courier_address_source, "'' === \$parsed['street'] || '' === \$parsed['house']" ), 'PEK courier resolver must fail closed when current Woo address cannot prove street and house instead of authorizing old DaData.' );
plugin_architecture_assert( str_contains( $pek_courier_address_source, 'same_region_name' ) && str_contains( $pek_courier_address_source, 'normalize_region_name' ), 'PEK courier DaData binding must include current order region comparison.' );
plugin_architecture_assert( str_contains( $pek_courier_address_source, 'has_meaningful_shipping_destination' ) && ! str_contains( $pek_courier_address_source, "\$shipping_filled = '' !== trim( \$shipping->country_code )" ), 'PEK courier destination scope must not treat shipping country alone as a filled destination.' );
plugin_architecture_assert( str_contains( $pek_courier_address_source, 'return \'\';' ) && str_contains( $pek_courier_address_source, 'normalize_block' ), 'PEK courier address resolver must not append untyped DaData block values ambiguously.' );
plugin_architecture_assert( str_contains( $pek_destination_source, 'LocationRepository' ) && str_contains( $pek_destination_source, 'assert_location_matches_request' ) && str_contains( $pek_destination_source, 'LOCATION_MISMATCH_MESSAGE' ) && strpos( $pek_destination_source, 'assert_location_matches_request' ) < strpos( $pek_destination_source, 'resolve_delivery_address_for_shipment' ), 'PEK courier address must be bound to canonical location before fresh PEK actual-address branch resolution.' );
plugin_architecture_assert( str_contains( $pek_destination_source, 'location_level' ) && str_contains( $pek_destination_source, 'city_matches_location' ) && str_contains( $pek_destination_source, 'settlement_matches_location' ), 'PEK destination resolver must distinguish city-level and settlement-level canonical locations explicitly.' );
plugin_architecture_assert( str_contains( $pek_destination_source, 'private function identifier_match' ) && str_contains( $pek_destination_source, 'return null;' ) && str_contains( $pek_destination_source, 'if ( false === $id_match )' ) && str_contains( $pek_destination_source, '$this->same_location_name( $request_city' ) && str_contains( $pek_destination_source, '$this->same_settlement_name( $request_settlement' ), 'PEK destination resolver must reject explicit FIAS mismatches and use bounded name fallback only when an ID is absent.' );
plugin_architecture_assert( str_contains( $pek_destination_source, 'selected_location_matches' ) && str_contains( $pek_destination_source, 'courier_selected_location_fias_id' ) && str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'Selected platform FIAS mismatch must stop' ), 'PEK selected platform FIAS must participate in courier location binding.' );
plugin_architecture_assert( str_contains( $pek_courier_address_source, '_wdc_platform_city_fias_id' ) && str_contains( $pek_courier_address_source, 'courier_order_city_fias_id' ) && str_contains( $pek_destination_source, 'order_city_fias_fallback' ) && ! str_contains( $pek_destination_source, '_wdc_platform_fias_id' ) && ! str_contains( $pek_destination_source, '_shipping_dadata_fias_id' ) && ! str_contains( $pek_destination_source, '_billing_dadata_fias_id' ) && str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'generic order city FIAS' ), 'PEK historical courier identity recovery must use explicit generic city FIAS and must not treat house/full-address DaData FIAS as locality authority.' );
plugin_architecture_assert( str_contains( $pek_courier_address_source, 'normalize_from_woo( Address $woo, object $order, string $scope )' ) && str_contains( $pek_courier_address_source, "evidence( \$normalized, 'parsed_address_1', \$order, \$scope )" ) && str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'Stale DaData rejected + Woo fallback must still enforce selected-location FIAS' ), 'PEK Woo fallback address sources must preserve selected-location FIAS evidence and enforce it before PEK calls.' );
plugin_architecture_assert( ! str_contains( $pek_destination_source, 'address->fias_id' ) && str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'House/full-address FIAS must not be confused with settlement FIAS' ), 'PEK destination binding must not use full-address/house FIAS as settlement locality FIAS.' );
plugin_architecture_assert( str_contains( $pek_destination_source, 'same_settlement_name' ) && str_contains( $pek_destination_source, 'normalize_settlement_name' ) && str_contains( $pek_destination_source, 'поселение|пос' ) && str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'Settlement typed-name fallback must match bounded project settlement prefixes' ), 'PEK settlement name fallback must be bounded and separate from generic city normalization.' );
plugin_architecture_assert( str_contains( $pek_request_builder_source, 'courier_location_match' ) && str_contains( $pek_request_builder_source, 'courier_branch_source' ) && str_contains( $pek_request_builder_source, 'courier_address_hash' ) && str_contains( $pek_request_builder_source, 'courier_location_level' ) && str_contains( $pek_request_builder_source, 'courier_address_precision' ) && str_contains( $pek_request_builder_source, 'courier_pek_formatted_address_hash' ), 'PEK safe preview must expose courier destination binding evidence without raw address.' );
plugin_architecture_assert( str_contains( plugin_architecture_source( 'src/Shipments/Pek/PekShipmentModalExtension.php' ), 'courier_destination_summary' ) && str_contains( plugin_architecture_source( 'src/Shipments/Pek/PekShipmentModalExtension.php' ), "\$recipient_address['raw_address']" ) && str_contains( plugin_architecture_source( 'src/Shipments/Pek/PekShipmentModalExtension.php' ), "\$pickup_point['address']" ) && str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-modal-smoke.php' ), 'billing fallback destination' ), 'PEK shipment modal must display the server-built draft request destination, including billing fallback and selected pickup point.' );
plugin_architecture_assert( str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'PEK recipient must use shipping first and last names when present' ) && str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'Partial shipping recipient name must not be mixed with billing fallback' ) && str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'PEK recipient payload must not contain unsupported name key' ), 'PEK behavioral tests must prove first/last-only recipient identity and no shipping/billing name mixing.' );
plugin_architecture_assert( str_contains( $pek_status_source, 'PekShipmentStatusResponseNormalizer' ) && str_contains( $pek_status_normalizer_source, 'required_string' ) && str_contains( $pek_status_normalizer_source, 'optional_bool' ) && str_contains( $pek_status_normalizer_source, 'string_list' ) && str_contains( $pek_status_normalizer_source, 'MoneyParser::numeric_to_kopecks' ), 'PEK shipment status responses must pass a strict typed boundary before normalization/persistence.' );
plugin_architecture_assert( str_contains( $plugin_source, 'PekShipmentStatusResponseNormalizer::class' ) && str_contains( $pek_status_source, 'PekShipmentStatusResponseNormalizer $normalizer' ) && ! str_contains( $pek_status_source, 'new PekShipmentStatusResponseNormalizer' ), 'PEK status normalizer must be registered in Plugin.php and injected as a required dependency.' );
plugin_architecture_assert( str_contains( $pek_status_normalizer_source, 'createFromFormat( \'!\' . $format' ) && str_contains( $pek_status_normalizer_source, '$parsed->format( $format ) === $value' ) && str_contains( $pek_status_normalizer_source, 'getLastErrors' ), 'PEK status DateTime parsing must use exact formats with round-trip validation.' );
plugin_architecture_assert( ! str_contains( $pek_status_normalizer_source, 'strtotime' ) && ! str_contains( $pek_status_normalizer_source, 'new \\DateTimeImmutable( $value' ) && str_contains( $pek_status_normalizer_source, 'invalid_status_date' ), 'PEK status DateTime parsing must not use fuzzy parsing and malformed dates must expose bounded diagnostics.' );
plugin_architecture_assert( str_contains( $pek_status_normalizer_source, "if ( 'deliveryPlanDate' === \$field )" ) && str_contains( $pek_status_normalizer_source, "'Y-m-d\\\\TH:i:s.v'" ), 'PEK deliveryPlanDate milliseconds compatibility must remain exact and field-specific.' );
plugin_architecture_assert( str_contains( $pek_status_normalizer_source, "'field' => \$field" ) && str_contains( $pek_status_normalizer_source, "'value_type' => get_debug_type( \$value )" ) && str_contains( $pek_status_normalizer_source, 'strlen( $value ) <= 128' ), 'PEK status date diagnostics must expose only field, value type, and bounded scalar value.' );
plugin_architecture_assert( str_contains( $pek_status_normalizer_source, 'add_if_present' ) && str_contains( $pek_status_normalizer_source, 'array_key_exists( $source_key, $source )' ), 'PEK status optional fields must be omitted when absent and merged only when present.' );
plugin_architecture_assert( str_contains( $pek_status_normalizer_source, '1 !== count( $response[\'cargos\'] )' ), 'PEK status normalizer must reject zero, duplicate, foreign, or malformed sibling cargo rows.' );
plugin_architecture_assert( str_contains( $pek_status_normalizer_source, '! is_bool( $value )' ) && ! str_contains( $pek_status_normalizer_source, '(bool) $value' ), 'PEK shipment status booleans must not coerce string false into true.' );
plugin_architecture_assert( str_contains( $pek_status_source, 'can_fallback_to_basic' ) && str_contains( $pek_status_source, 'PekApiException' ) && ! str_contains( $pek_status_source, 'catch ( \\Throwable )' ), 'PEK status service must not silently fallback to basic status for malformed expanded responses.' );
plugin_architecture_assert( str_contains( $pek_status_normalizer_source, '-1 === $value' ) && str_contains( $pek_status_normalizer_source, "'-1' === \$value" ) && str_contains( $pek_status_normalizer_source, '-3 === $value && $this->is_cancelled_before_acceptance_status( $cargo_status )' ) && str_contains( $pek_status_normalizer_source, "'аннулировано до приемки груза'" ) && str_contains( $pek_status_source, '$clear_status_id' ) && str_contains( $pek_status_source, "unset( \$shipment['pek_cargo_status_id'] )" ) && ! str_contains( $pek_status_normalizer_source, '$value < 0' ), 'PEK status normalizer must treat only exact -1 sentinels and cancelled-status integer -3 as non-persisted live sentinels.' );
plugin_architecture_assert( str_contains( $plugin_source, 'PekShipmentStatusService::class' ) && str_contains( $plugin_source, 'ShipmentCreationAttemptService::class' ) && str_contains( $pek_status_source, 'private ShipmentCreationAttemptService $attempts' ) && ! str_contains( $pek_status_source, '?ShipmentCreationAttemptService' ) && ! str_contains( $pek_status_source, 'ShipmentCreationAttemptService $attempts = null' ) && ! str_contains( $pek_status_source, 'instanceof ShipmentCreationAttemptService' ) && strpos( $pek_status_source, 'mark_terminal_before_delete( $order, $shipment, \'cancelled\' )' ) < strpos( $pek_status_source, 'delete_for_carrier( $order, PekSettings::CARRIER_KEY )' ) && str_contains( $pek_status_source, '$this->mapping->is_cancelled_status( $external_status )' ) && ! str_contains( $pek_status_source, "DeliveryStatus::CANCELLED === (string) ( \$status['universal_status_code'] ?? '' )" ) && str_contains( $pek_status_source, "'terminal_source' => 'status_update'" ) && ! str_contains( $pek_status_source, 'order_cancellation(' ), 'PEK status update must require ShipmentCreationAttemptService and perform backend CANCELLED cleanup from immutable raw carrier truth before repository deletion and without carrier mutation.' );
$pek_cancellation_service_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentService.php' );
plugin_architecture_assert( str_contains( $pek_cancellation_service_source, 'cancellation_confirmation' ) && str_contains( $pek_cancellation_service_source, 'fresh_status_reconciliation' ) && str_contains( $pek_cancellation_service_source, 'cancellation_uncertain' ) && 1 === substr_count( $pek_cancellation_service_source, 'order_cancellation( array( $code ) )' ), 'PEK cancellation must mutate once and use read-only fresh status reconciliation for ambiguous outcomes.' );
plugin_architecture_assert( str_contains( $pek_cancellation_service_source, 'fresh_status_precheck' ) && strpos( $pek_cancellation_service_source, 'is_cancelled_status( $external_status' ) < strpos( $pek_cancellation_service_source, 'order_cancellation( array( $code ) )' ) && str_contains( $pek_cancellation_service_source, '$this->mapping->is_cancelled_status( $status )' ), 'PEK already-cancelled precheck must use immutable raw carrier truth and reconcile terminal state before invoking cancellation mutation.' );
plugin_architecture_assert( str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'Basic fallback must preserve expanded-only receiver flags and actual cost' ), 'PEK integration smoke must cover basic fallback preserving expanded-only metadata.' );
plugin_architecture_assert( str_contains( $pek_sms_source, '$matches = 0;' ) && ! str_contains( $pek_sms_source, "return true;\n\t\t\t}" ), 'PEK SMS geography parser must validate every row before accepting the SMS UID.' );
plugin_architecture_assert( str_contains( $pek_sender_counterpart_source, 'failed_verification' ) && str_contains( $pek_sender_counterpart_source, "save_sender_counterpart( '', array() )" ), 'Failed PEK counterpart verification must clear old GUID/snapshot.' );
plugin_architecture_assert( str_contains( $pek_sender_counterpart_source, 'row_legal_form' ) && str_contains( $pek_sender_counterpart_source, 'array( 1, 2, 3 )' ) && str_contains( $pek_sender_counterpart_source, "if ( 3 === \$legal_form )" ) && strpos( $pek_sender_counterpart_source, "if ( 3 === \$legal_form )" ) < strpos( $pek_sender_counterpart_source, 'normalize_legal_row' ), 'PEK sender counterpart verification must allow official legalForm=3 and skip physical rows before legal/IP normalization.' );
plugin_architecture_assert( str_contains( $pek_sender_counterpart_source, 'physical_rows' ) && str_contains( $pek_sender_counterpart_source, 'unsupported_legal_form' ) && str_contains( $pek_sender_counterpart_source, 'legal_form_type' ), 'PEK sender counterpart verification must count physical rows safely and fail closed for unknown/malformed legalForm discriminators.' );
plugin_architecture_assert( str_contains( $pek_sender_counterpart_source, 'private_token' ) && str_contains( $pek_sender_counterpart_source, 'counterpart_api' ) && str_contains( $pek_sender_counterpart_source, 'counterpart_logical' ) && str_contains( $pek_sender_counterpart_source, 'counterpart_contract' ), 'PEK sender counterpart verification must expose distinct safe stages for token/API/logical/contract failures.' );
plugin_architecture_assert( str_contains( $pek_sender_counterpart_source, "array( 'stage', 'endpoint', 'method', 'http_status', 'error_code', 'reason', 'row_index'" ) || ( str_contains( $pek_sender_counterpart_source, 'safe_token' ) && str_contains( $pek_sender_counterpart_source, 'safe_endpoint' ) && str_contains( $pek_sender_counterpart_source, 'safe_method' ) ), 'PEK sender counterpart diagnostic must use a closed safe allowlist rather than raw response values.' );
plugin_architecture_assert( str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'PRIVATE_PASSPORT_NUMBER' ) && str_contains( plugin_architecture_source( 'tests/pek/run-pek-shipment-integration-smoke.php' ), 'Mixed physical + legal counterparty list must verify configured legal entity' ) && str_contains( plugin_architecture_source( 'tests/pek/run-pek-admin-routing-smoke.php' ), 'Counterpart admin notice must not render PII/raw response values' ), 'PEK counterpart tests must prove legalForm=3 PII is never persisted, returned, or rendered.' );
$pek_credentials_encrypt_pos = strpos( $pek_credentials_source, '$encrypted = $this->encryption->encrypt( $key );' );
$pek_credentials_login_write_pos = strpos( $pek_credentials_source, '$this->settings->set( PekSettings::LOGIN_KEY, $login );', $pek_credentials_encrypt_pos );
$pek_credentials_key_write_pos = strpos( $pek_credentials_source, '$this->settings->set( PekSettings::API_KEY_ENCRYPTED_KEY, $encrypted );', $pek_credentials_encrypt_pos );
plugin_architecture_assert( false !== $pek_credentials_encrypt_pos && false !== $pek_credentials_login_write_pos && false !== $pek_credentials_key_write_pos && $pek_credentials_encrypt_pos < $pek_credentials_login_write_pos && $pek_credentials_encrypt_pos < $pek_credentials_key_write_pos, 'PEK credentials save must encrypt requested API key before writing login/key.' );
plugin_architecture_assert( str_contains( $pek_admin_source, '$credentials_saved' ) && str_contains( $pek_admin_source, 'credentials не изменены' ) && str_contains( $pek_admin_source, '$credentials_saved && $old_login_hash' ), 'PEK admin save must compare account hash only after successful credentials save.' );
plugin_architecture_assert( is_file( plugin_architecture_path( 'tests/pek/run-pek-shipment-integration-smoke.php' ) ), 'Full PEK shipment integration smoke must exist.' );
foreach ( plugin_architecture_php_files( 'src' ) as $file ) {
	$relative = str_replace( '\\', '/', substr( $file, strlen( plugin_architecture_root() ) + 1 ) );
	$source = (string) file_get_contents( $file );
	plugin_architecture_assert( ! str_contains( strtolower( $source ), 'cancelandreturncargo' ), 'PEK return API must not be present in production source: ' . $relative );
	plugin_architecture_assert( ! str_contains( $source, 'pek_actual_cost_' ), 'PEK actual cost must use shared canonical fields only: ' . $relative );
	if ( str_starts_with( $relative, 'src/Shipments/Pek/' ) ) {
		plugin_architecture_assert( ! str_contains( $source, 'declaredCost' ), 'PEK shipment production source must not contain declaredCost: ' . $relative );
	}
}
foreach ( glob( plugin_architecture_path( 'tests/pek/fixtures/*.json' ) ) ?: array() as $fixture_path ) {
	plugin_architecture_assert( ! str_contains( (string) file_get_contents( $fixture_path ), 'declaredCost' ), 'PEK shipment fixtures must not contain declaredCost: ' . basename( $fixture_path ) );
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
	'new CarrierPickupPointProviderRegistry',
	'new PekCheckoutQuoteContextResolver',
	'new PekCheckoutPickupPointFormatter',
	'new PekApiClient',
) as $forbidden_controller_new ) {
	plugin_architecture_assert( ! str_contains( $order_recalculation_controller, $forbidden_controller_new ), 'Order delivery recalculation controller must not self-construct dependency: ' . $forbidden_controller_new );
}

$calculation_builder_source = plugin_architecture_source( 'src/Orders/Application/DeliveryCalculationDataBuilder.php' );
$checkout_persister_source = plugin_architecture_source( 'src/Checkout/WooCommerce/OrderShippingMetaPersister.php' );
$replacement_service_source = plugin_architecture_source( 'src/Orders/Application/OrderDeliveryReplacementService.php' );
$order_recalculation_service_source = plugin_architecture_source( 'src/Orders/Application/OrderDeliveryRecalculationService.php' );
$order_recalculation_js = plugin_architecture_source( 'assets/admin/order-delivery-recalculation.js' );
plugin_architecture_assert( str_contains( $order_recalculation_controller, 'private CarrierPickupPointProviderRegistry $pickup_providers' ) && str_contains( $order_recalculation_controller, 'private PekCheckoutQuoteContextResolver $pek_quote_context' ) && str_contains( $order_recalculation_controller, 'private PekCheckoutPickupPointFormatter $pek_formatter' ), 'Order recalculation controller must receive PEK pickup provider dependencies through DI.' );
plugin_architecture_assert( str_contains( $order_recalculation_controller, 'private PekCountryPolicy $pek_countries' ) && str_contains( $plugin_source, '$this->container->get( PekCountryPolicy::class ), $this->environment->plugin_url()' ) && ! str_contains( $order_recalculation_controller, 'new PekCountryPolicy' ) && ! str_contains( $order_recalculation_controller, '?PekCountryPolicy' ), 'Order recalculation controller must receive PekCountryPolicy as required DI.' );
plugin_architecture_assert( str_contains( $order_recalculation_controller, 'PekSettings::CARRIER_KEY === $carrier' ) && str_contains( $order_recalculation_controller, 'pek_pickup_points' ) && str_contains( $order_recalculation_controller, 'query_from_snapshot' ) && str_contains( $order_recalculation_controller, '$provider->search( $query )' ), 'PEK order-admin pickup search must use the registry-backed provider and existing query snapshot.' );
plugin_architecture_assert( str_contains( $order_recalculation_controller, 'registry_pickup_points_from_rate' ) && str_contains( $order_recalculation_controller, "\$meta['pickup_provider_query']" ) && str_contains( $order_recalculation_controller, '$this->pickup_providers->get( $carrier )' ) && str_contains( $order_recalculation_controller, 'registry_point_payload' ), 'Order-admin recalculated pickup search must use selected-rate provider snapshots through the generic registry before Russian Post fallback.' );
plugin_architecture_assert( ! str_contains( $order_recalculation_controller, "|| 'RU' !== strtoupper( trim( (string) ( \$snapshot['country_code'] ?? '' ) ) )" ) && str_contains( $order_recalculation_controller, 'supports_calculation_direction' ) && str_contains( $order_recalculation_controller, 'current_country_code_for_pickup' ) && str_contains( $order_recalculation_controller, '$snapshot_country !== $current_country' ), 'PEK order-admin pickup search must validate snapshot/current countries through PekCountryPolicy without a RU-only guard.' );
plugin_architecture_assert( ! str_contains( $order_recalculation_controller, "'diagnostic' =>" ) && ! str_contains( $order_recalculation_service_source, 'pek_diagnostic' ) && ! str_contains( $checkout_orchestrator_source, "'service_scan'" ) && ! str_contains( $checkout_orchestrator_source, "'carrier_quotes'" ) && ! str_contains( $checkout_orchestrator_source, 'carrier_quote_diagnostic' ), 'Temporary order-admin diagnostic.pek and generic service-scan/carrier-quote instrumentation must remain removed.' );
$pek_branch_pos = strpos( $order_recalculation_controller, 'PekSettings::CARRIER_KEY === $carrier' );
$rp_fallback_pos = strpos( $order_recalculation_controller, '$rows = $this->pickup_rows_for_location' );
plugin_architecture_assert( false !== $pek_branch_pos && false !== $rp_fallback_pos && $pek_branch_pos < $rp_fallback_pos, 'PEK pickup search must be routed before the Russian Post fallback.' );
plugin_architecture_assert( str_contains( $replacement_service_source, 'canonical_pek_pickup_for_save' ) && str_contains( $replacement_service_source, 'CarrierPickupPointSelectionQuery' ) && str_contains( $replacement_service_source, 'resolve_selection' ) && str_contains( $replacement_service_source, 'PekCheckoutPickupPointFormatter' ), 'PEK order-admin save must fresh-resolve and format canonical pickup selection through provider registry.' );
plugin_architecture_assert( str_contains( $order_recalculation_js, 'requiresRateRefresh' ) && str_contains( $order_recalculation_js, "restorePekPickup: 'pek' === carrier" ) && str_contains( $order_recalculation_js, 'function restorePekPickupPreview' ), 'Order recalculation JS must refresh and restore PEK pickup selection after terminal choice.' );
plugin_architecture_assert( str_contains( $calculation_builder_source, 'function lead_time_audit_lines' ), 'DeliveryCalculationDataBuilder must own lead-time audit formatting.' );
plugin_architecture_assert( ! str_contains( $checkout_persister_source, 'function lead_time_audit_lines' ) && ! str_contains( $replacement_service_source, 'function lead_time_audit_lines' ), 'Checkout/admin persistence services must not duplicate lead-time audit formatting.' );
plugin_architecture_assert( str_contains( $calculation_builder_source, 'private RuleFormulaFormatter $rule_formula_formatter' ), 'DeliveryCalculationDataBuilder must receive RuleFormulaFormatter through constructor DI.' );
plugin_architecture_assert( ! str_contains( $calculation_builder_source, 'new RuleFormulaFormatter' ), 'DeliveryCalculationDataBuilder must not construct RuleFormulaFormatter inline.' );

$rules_admin_source = plugin_architecture_source( 'src/Rules/Admin/RulesAdminPage.php' );
plugin_architecture_assert( ! preg_match( '/dpd|yandex_delivery|cdek|russian_post/i', $rules_admin_source ), 'RulesAdminPage must stay carrier-agnostic for service simulation.' );

$delivery_services_admin_source = plugin_architecture_source( 'src/DeliveryServices/Admin/DeliveryServicesAdminPage.php' );
plugin_architecture_assert( str_contains( $delivery_services_admin_source, 'simulate_runtime_carrier_service_rules' ) && str_contains( $delivery_services_admin_source, 'DpdQuoteCarrier' ) && str_contains( $delivery_services_admin_source, 'YandexDeliveryCarrier' ), 'DPD and Yandex rule simulation must be wired through the shared service simulation runner.' );
plugin_architecture_assert( str_contains( $delivery_services_admin_source, 'save_pek_statuses' ) && str_contains( $delivery_services_admin_source, 'PekStatusAdminPage::TAB_KEY' ) && str_contains( $delivery_services_admin_source, 'render_pek_statuses_tab' ), 'Delivery services admin must expose the PEK statuses tab, save action and redirect wiring.' );

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
$attempt_service_source = plugin_architecture_source( 'src/Shipments/Application/ShipmentCreationAttemptService.php' );
$pek_correlation_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentCorrelationResolver.php' );
$pek_request_builder_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentRequestBuilder.php' );
$pek_adapter_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentAdapter.php' );
$pek_destination_resolver_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentDestinationResolver.php' );
$order_draft_factory_source = plugin_architecture_source( 'src/Shipments/Application/OrderShipmentDraftFactory.php' );
$plugin_attempt_source = plugin_architecture_source( 'src/Core/Plugin.php' );
$shipment_repository_source = plugin_architecture_source( 'src/Shipments/Storage/OrderShipmentRepository.php' );
$pek_shipment_service_source = plugin_architecture_source( 'src/Shipments/Pek/PekShipmentService.php' );
$pek_country_policy_source = plugin_architecture_source( 'src/Carriers/Pek/PekCountryPolicy.php' );
$pek_country_policy_dependents = array(
	'src/Carriers/Runtime/PekCarrier.php' => plugin_architecture_source( 'src/Carriers/Runtime/PekCarrier.php' ),
	'src/Carriers/Pek/Checkout/PekCheckoutQuoteContextResolver.php' => plugin_architecture_source( 'src/Carriers/Pek/Checkout/PekCheckoutQuoteContextResolver.php' ),
	'src/Carriers/Pek/Quote/PekQuoteRequestBuilder.php' => plugin_architecture_source( 'src/Carriers/Pek/Quote/PekQuoteRequestBuilder.php' ),
	'src/Shipments/Pek/PekShipmentAdapter.php' => $pek_adapter_source,
	'src/Shipments/Pek/PekShipmentButtonPolicy.php' => plugin_architecture_source( 'src/Shipments/Pek/PekShipmentButtonPolicy.php' ),
	'src/Shipments/Pek/PekManualAttachContextResolver.php' => plugin_architecture_source( 'src/Shipments/Pek/PekManualAttachContextResolver.php' ),
	'src/Shipments/Pek/PekShipmentService.php' => $pek_shipment_service_source,
);
plugin_architecture_assert( str_contains( $attempt_service_source, 'final class ShipmentCreationAttemptService' ) && str_contains( $attempt_service_source, "_wdc_shipment_creation_attempts" ) && str_contains( $attempt_service_source, 'reserve_for_request' ), 'Creation attempt owner must be a generic Shipment Framework service.' );
plugin_architecture_assert( str_contains( $plugin_attempt_source, 'ShipmentCreationAttemptService::class' ) && str_contains( $plugin_attempt_source, '$this->container->get( ShipmentCreationAttemptService::class )' ), 'Plugin DI must inject the generic creation attempt service into ShipmentCreationService.' );
plugin_architecture_assert( str_contains( $plugin_source, 'PekCountryPolicy::class' ) && str_contains( $plugin_source, 'fn(): PekCountryPolicy => new PekCountryPolicy()' ), 'Plugin.php must register the single PEK country policy service.' );
plugin_architecture_assert( str_contains( $pek_country_policy_source, "SENDER_COUNTRY = 'RU'" ) && str_contains( $pek_country_policy_source, "INTERNATIONAL_RECEIVER_COUNTRIES = array( 'AM', 'BY', 'KG', 'KZ' )" ) && str_contains( $pek_country_policy_source, 'supports_calculation_direction' ) && str_contains( $pek_country_policy_source, 'PekSettings::PLANNED_COUNTRIES' ) && str_contains( $pek_country_policy_source, 'allows_automatic_shipment_create' ), 'PekCountryPolicy must own the RU sender international direction matrix and shipment-create policy.' );
foreach ( $pek_country_policy_dependents as $relative => $source ) {
	plugin_architecture_assert( str_contains( $source, 'PekCountryPolicy $countries' ) && ! str_contains( $source, '?PekCountryPolicy' ) && ! str_contains( $source, 'PekCountryPolicy $countries = null' ) && ! str_contains( $source, 'new PekCountryPolicy()' ), 'Production PEK country policy dependency must be required DI without hidden fallback in ' . $relative );
}
plugin_architecture_assert( ! str_contains( $shipment_creation_source, "PekSettings::CARRIER_KEY" ) && ! str_contains( $shipment_creation_source, "'pek'" ), 'ShipmentCreationService must not branch on PEK for attempt lifecycle.' );
plugin_architecture_assert( str_contains( $shipment_creation_source, 'shipment_creation_attempt_dependency_missing' ), 'ShipmentCreationService create path must fail closed when the safety-critical attempt service is unavailable.' );
plugin_architecture_assert( str_contains( $attempt_service_source, 'create_lock_key( int $order_id, string $carrier_key )' ) && ! str_contains( $attempt_service_source, '$this->scope_key( $request->carrier_key, $this->service_key( $request ) ) );' ), 'Create mutation lock must be scoped by order and carrier, not service key.' );
plugin_architecture_assert( str_contains( $attempt_service_source, 'DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s' ) && str_contains( $attempt_service_source, '$current !== $value' ), 'Create lock release/takeover must be token-owned compare-and-delete.' );
plugin_architecture_assert( str_contains( $attempt_service_source, 'invalidate_lock_option_cache_after_cas' ) && str_contains( $attempt_service_source, 'wp_cache_delete( $key, \'options\' )' ) && str_contains( $attempt_service_source, 'unset( $notoptions[ $key ] )' ) && ! str_contains( $attempt_service_source, '$notoptions[ $key ] = true' ), 'Successful direct SQL CAS delete must clear positive option cache and remove the lock key from notoptions instead of publishing negative cache.' );
plugin_architecture_assert( str_contains( $attempt_service_source, 'acquire_attempt_meta_lock' ) && str_contains( $attempt_service_source, 'ATTEMPT_META_LOCK_TTL_SECONDS' ), 'Attempt record reservation must serialize order-level read-modify-write updates.' );
plugin_architecture_assert( str_contains( $attempt_service_source, 'assert_transition_allowed' ) && str_contains( $attempt_service_source, 'STATE_TERMINAL => array()' ), 'Attempt state transitions must be explicitly bounded.' );
plugin_architecture_assert( ! str_contains( $shipment_repository_source, 'ShipmentCreationAttemptService' ) && ! str_contains( $shipment_repository_source, '_wdc_shipment_creation_attempts' ) && ! str_contains( $shipment_repository_source, 'creation_attempt_id' ), 'OrderShipmentRepository must not own creation-attempt lifecycle state.' );
plugin_architecture_assert( str_contains( $pek_shipment_service_source, 'mark_active_for_shipment' ) && str_contains( $pek_shipment_service_source, 'mark_terminal_before_delete' ), 'PEK manual attach and terminal delete paths must use the generic attempt lifecycle collaborator.' );
plugin_architecture_assert( ! str_contains( $pek_shipment_service_source, 'instanceof ShipmentCreationAttemptService' ), 'PEK shipment service must not silently fallback around required ShipmentCreationAttemptService DI.' );
$pek_cancel_body = substr( $pek_shipment_service_source, (int) strpos( $pek_shipment_service_source, 'function cancel_in_carrier' ), (int) strpos( $pek_shipment_service_source, 'function remove_local' ) - (int) strpos( $pek_shipment_service_source, 'function cancel_in_carrier' ) );
plugin_architecture_assert( str_contains( $pek_cancel_body, 'receiver_country_for_cancellation' ) && str_contains( $pek_cancel_body, 'Международные отправления ПЭК отменяются вручную в кабинете ПЭК.' ) && strpos( $pek_cancel_body, 'receiver_country_for_cancellation' ) < strpos( $pek_cancel_body, 'tracking_identifier' ) && strpos( $pek_cancel_body, 'allows_automatic_shipment_create' ) < strpos( $pek_cancel_body, 'statuses->fetch' ) && strpos( $pek_cancel_body, 'allows_automatic_shipment_create' ) < strpos( $pek_cancel_body, 'order_cancellation' ), 'PEK cancellation service boundary must fail closed by receiver country before status fetch or cancellation mutation.' );
plugin_architecture_assert( str_contains( $pek_country_policy_dependents['src/Shipments/Pek/PekManualAttachContextResolver.php'], 'has_legacy_ru_evidence' ) && ! str_contains( $pek_country_policy_dependents['src/Shipments/Pek/PekManualAttachContextResolver.php'], "return '' !== \$country ? \$country : 'RU'" ), 'PEK manual attach country authority must not silently fallback to RU without affirmative legacy evidence.' );
plugin_architecture_assert( ! str_contains( $pek_correlation_source, 'random_bytes' ) && ! str_contains( $pek_correlation_source, 'wp_generate_uuid4' ) && ! str_contains( $pek_correlation_source, 'microtime' ) && ! str_contains( $pek_correlation_source, 'time()' ), 'PekShipmentCorrelationResolver must not generate random attempt IDs.' );
plugin_architecture_assert( ! str_contains( $pek_request_builder_source, 'random_bytes' ) && ! str_contains( $pek_request_builder_source, 'wp_generate_uuid4' ) && ! str_contains( $pek_request_builder_source, 'creation_attempt_id =' ), 'PekShipmentRequestBuilder must not allocate creation attempts.' );
plugin_architecture_assert( str_contains( $pek_request_builder_source, "'hardPacking' => array( 'enabled' => false )" ) && str_contains( $pek_request_builder_source, "'strapping' => array( 'enabled' => false )" ) && str_contains( $pek_request_builder_source, "'documentsReturning' => array( 'enabled' => false )" ), 'PEK request builder must always send explicit disabled optional service objects confirmed by preregistration validation.' );
plugin_architecture_assert( str_contains( $pek_request_builder_source, "'delivery' => DeliveryType::COURIER === \$request->delivery_type" ) && str_contains( $pek_request_builder_source, "? array( 'enabled' => true, 'payer' => array( 'type' => 1 ) )" ) && str_contains( $pek_request_builder_source, ": array( 'enabled' => false )" ), 'PEK request builder must send pickup delivery=false and courier delivery=true with sender payer.' );
plugin_architecture_assert( str_contains( $pek_request_builder_source, "'sealing' => \$sealing" ) && str_contains( $pek_request_builder_source, "? array( 'enabled' => true, 'payer' => array( 'type' => 1 ) )" ) && str_contains( $pek_request_builder_source, ": array( 'enabled' => false )" ) && ! str_contains( $pek_request_builder_source, "\$services['sealing']" ) && ! str_contains( $pek_request_builder_source, "'storing'" ) && ! str_contains( $pek_request_builder_source, "'bag'" ) && ! str_contains( $pek_request_builder_source, "'smallBag'" ) && ! str_contains( $pek_request_builder_source, "'packageType'" ) && ! str_contains( $pek_request_builder_source, "'packagingType'" ), 'PEK services fix must always serialize sealing enabled/disabled and must not add storing or undocumented bag/package aliases.' );
plugin_architecture_assert( str_contains( $pek_request_builder_source, "'planned_date_sent' => false" ) && ! str_contains( $pek_request_builder_source, "'plannedDate'" ), 'PEK preregistration must not send plannedDate until a reviewed planned-date policy exists.' );
plugin_architecture_assert( str_contains( $pek_adapter_source, 'pek_creation_attempt_missing' ) && str_contains( $pek_adapter_source, 'valid_creation_attempt_id' ) && strpos( $pek_adapter_source, 'pek_creation_attempt_missing' ) < strpos( $pek_adapter_source, 'preregistration_submit' ), 'PEK mutation create must require generic creation_attempt_id before submit.' );
plugin_architecture_assert( str_contains( $pek_adapter_source, 'log_status_update_failure' ) && str_contains( $pek_adapter_source, 'status_normalization' ) && str_contains( $pek_adapter_source, 'Не удалось обновить статус ПЭК.' ) && str_contains( $pek_adapter_source, '\\RuntimeException | \\InvalidArgumentException $e' ), 'PEK adapter status update must return controlled carrier/domain errors without swallowing arbitrary Throwable.' );
plugin_architecture_assert( str_contains( $pek_adapter_source, 'PekApiException $e' ) && str_contains( $pek_adapter_source, "'preparation_diagnostic'" ) && str_contains( $pek_adapter_source, "'destination_pickup'" ) && str_contains( $pek_adapter_source, 'safe_preparation_diagnostic' ), 'PEK safe preview must retain pre-SMS PekApiException diagnostics instead of returning an empty body.' );
plugin_architecture_assert( str_contains( $pek_destination_resolver_source, 'trusted_pickup_selection_fallback' ) && str_contains( $pek_destination_resolver_source, 'persisted_checkout_selection_access_fallback' ) && str_contains( $pek_destination_resolver_source, 'PICKUP_FALLBACK_WARNING' ), 'PEK pickup shipment preparation must have a bounded exact-selection access fallback.' );
plugin_architecture_assert( str_contains( $pek_destination_resolver_source, "'provider_resolve_selection'" ) && str_contains( $pek_destination_resolver_source, "'provider_destination_fingerprint'" ) && str_contains( $pek_destination_resolver_source, '$fingerprint !== $request_fingerprint' ) && str_contains( $pek_destination_resolver_source, '$fingerprint !== $query_fingerprint' ) && str_contains( $pek_destination_resolver_source, "'branch_id'" ) && str_contains( $pek_destination_resolver_source, 'pickup_snapshot_limits_pass' ), 'PEK pickup destination fallback must require server-side selection source, matching provider fingerprint, branch identity, and local cargo constraints.' );
plugin_architecture_assert( ! str_contains( $pek_destination_resolver_source, "param( \$request" ) && ! str_contains( $pek_destination_resolver_source, "\$_POST" ) && ! str_contains( $pek_destination_resolver_source, "warehouse_id_from_browser" ), 'PEK pickup destination fallback must not accept browser-supplied terminal authority.' );
plugin_architecture_assert( str_contains( $order_draft_factory_source, "'pek_pickup_selected_snapshot'" ) && str_contains( $order_draft_factory_source, "'_wdc_pickup_point_snapshot'" ) && str_contains( $order_draft_factory_source, "'validation_source'" ) && str_contains( $order_draft_factory_source, "'provider_destination_fingerprint'" ), 'Order shipment draft factory must project persisted server-side PEK pickup selection evidence for shipment preparation fallback.' );
$calculation_source_for_attempt = plugin_architecture_source( 'src/Orders/Application/DeliveryCalculationDataBuilder.php' );
$rate_runtime_source_for_attempt = plugin_architecture_source( 'src/Carriers/Runtime/PekCarrier.php' );
plugin_architecture_assert( ! str_contains( $calculation_source_for_attempt, 'creation_attempt_id' ) && ! str_contains( $rate_runtime_source_for_attempt, 'creation_attempt_id' ), 'Creation attempt ID must not be delivery calculation or checkout rate metadata.' );
$generic_shipment_sources = array();
foreach ( array( 'src/Shipments/Application', 'src/Shipments/Admin', 'src/Shipments/Storage', 'src/Shipments/Documents', 'src/Shipments/Modal' ) as $path ) {
	foreach ( plugin_architecture_php_files( $path ) as $file ) {
		$relative = str_replace( '\\', '/', substr( $file, strlen( plugin_architecture_root() ) + 1 ) );
		$generic_shipment_sources[ $relative ] = (string) file_get_contents( $file );
	}
}
foreach ( $generic_shipment_sources as $relative => $source ) {
	if ( 'src/Shipments/Application/OrderShipmentDraftFactory.php' === $relative ) {
		plugin_architecture_assert( str_contains( $source, 'function supports_order' ) && str_contains( $source, 'function supports_carrier_key' ), 'Order shipment draft factory must expose the shipment-supported carrier contract.' );
		plugin_architecture_assert( str_contains( $source, 'if ( RussianPostDomesticSettings::CARRIER_KEY === $carrier_key )' ) && str_contains( $source, 'create_russian_post_domestic_request_from_order' ), 'Order shipment draft factory must handle domestic Russian Post through an explicit carrier branch.' );
		plugin_architecture_assert( str_contains( $source, "throw new \\RuntimeException( 'Shipment carrier is not supported for this order.' )" ), 'Order shipment draft factory must fail closed for unsupported carriers instead of falling back to Russian Post.' );
	} elseif ( 'src/Shipments/Admin/OrderShipmentsMetabox.php' === $relative ) {
		plugin_architecture_assert( str_contains( $source, '! $this->drafts->supports_order( $order )' ) && str_contains( $source, 'Добавьте стандартную службу доставки' ) && ! str_contains( $source, 'custom_delivery' ), 'Order shipments metabox must render the generic unsupported-service empty state through the factory contract.' );
	} else {
		plugin_architecture_assert( ! str_contains( $source, $jet_key ) && ! str_contains( $source, 'JetLogistic' ), 'Generic Shipment Framework must not branch on Jet Logistic in ' . $relative );
	}
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
$wc_package_mapper_source = plugin_architecture_source( 'src/Checkout/WooCommerce/WooCommercePackageMapper.php' );
plugin_architecture_assert( str_contains( $wc_package_mapper_source, 'resolve_checkout_fields' ) && str_contains( $wc_package_mapper_source, 'backend_resolved' ) && str_contains( $wc_package_mapper_source, 'private ?CheckoutLocationSearch $location_search' ) && ! str_contains( $wc_package_mapper_source, 'new CheckoutLocationSearch' ) && ! str_contains( $wc_package_mapper_source, 'new LocationSearchService' ) && ! str_contains( $wc_package_mapper_source, 'jet_logistic' ) && ! str_contains( $wc_package_mapper_source, 'JetLogistic' ), 'WooCommerce checkout backend location recovery must stay generic, injected, and must not branch on Jet Logistic.' );
plugin_architecture_assert( str_contains( $plugin_source_for_jet, '$this->container->get( CheckoutLocationSearch::class )' ), 'WooCommercePackageMapper must receive canonical CheckoutLocationSearch from Plugin.php DI.' );
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
$pek_pickup_provider_source = plugin_architecture_source( 'src/Carriers/Pek/Pickup/PekPickupPointProvider.php' );
$pek_terminal_service_source = plugin_architecture_source( 'src/Carriers/Pek/Pickup/PekTerminalService.php' );
$pek_carrier_source_for_cache = plugin_architecture_source( 'src/Carriers/Runtime/PekCarrier.php' );
$quote_cache_source_for_pickup = plugin_architecture_source( 'src/Checkout/Cache/QuoteCache.php' );
plugin_architecture_assert( str_contains( $pek_carrier_source_for_cache, 'pek_selection_provider_destination_fingerprint' ) && str_contains( $quote_cache_source_for_pickup, 'provider_destination_fingerprint' ), 'PEK and generic quote cache context must include selected point provider fingerprint.' );
plugin_architecture_assert( str_contains( $pek_carrier_source_for_cache, "'api_base_price_rub' => \$result->price_kopecks / 100" ) && str_contains( $pek_carrier_source_for_cache, "'pek_carrier_base_price_rub' => \$result->carrier_price_kopecks / 100" ) && str_contains( $pek_carrier_source_for_cache, "'pek_carrier_price_kopecks' => \$result->carrier_price_kopecks" ), 'PEK carrier must expose adjusted API base and preserve carrier-only cost separately.' );
plugin_architecture_assert( ! str_contains( $pek_carrier_source_for_cache, 'CheckoutSessionManager' ) && ! str_contains( $pek_carrier_source_for_cache, 'WC()->session' ), 'PEK carrier must not mutate WooCommerce session directly.' );
plugin_architecture_assert( str_contains( $pek_carrier_source_for_cache, 'pickup_preliminary_options' ) && str_contains( $pek_carrier_source_for_cache, 'pek_selected_terminal_quote_failed' ) && str_contains( $pek_carrier_source_for_cache, 'pickup_rejection_meta' ) && str_contains( $pek_carrier_source_for_cache, 'pickup_selection_rejected' ), 'PEK carrier must attempt explicit preliminary recovery and mark selected pickup rejection with generic metadata.' );
plugin_architecture_assert( str_contains( $pek_context_source, 'preliminary_pickup_options' ) && str_contains( $pek_context_source, 'selected_pickup_options' ), 'PEK checkout context resolver must share preliminary pickup policy between initial quote and recovery.' );
plugin_architecture_assert( str_contains( $pek_pickup_provider_source, 'function last_report' ) && str_contains( $pek_terminal_service_source, "'/branches/nearestdepartments/'" ) && str_contains( $pek_terminal_service_source, "'api_error_message' => \$exception->getMessage()" ), 'PEK pickup provider must expose terminal service last_report with safe nearestdepartments API diagnostics.' );
plugin_architecture_assert( str_contains( $pek_context_source, 'pickup_options_error_from_exception' ) && str_contains( $pek_context_source, 'pickup_provider_context' ) && str_contains( $pek_context_source, "'endpoint'" ) && str_contains( $pek_context_source, "'http_status'" ) && str_contains( $pek_context_source, "'api_error_message'" ) && str_contains( $pek_context_source, "'cache_hit'" ) && str_contains( $pek_context_source, "'api_source'" ) && str_contains( $pek_context_source, "'pek_checkout_pickup_points_missing'" ), 'PEK checkout context must propagate safe pickup provider diagnostics while preserving HTTP 200 empty-list no-terminals semantics.' );
plugin_architecture_assert( str_contains( $pek_carrier_source_for_cache, 'log_pickup_options_error' ) && str_contains( $pek_carrier_source_for_cache, 'PEK checkout pickup preliminary options unavailable.' ) && str_contains( $pek_carrier_source_for_cache, "'endpoint' =>" ) && str_contains( $pek_carrier_source_for_cache, "'http_status' =>" ), 'PEK checkout pickup absence must log safe provider root-cause diagnostics without changing customer-facing availability.' );
$new_shipping_method_source = plugin_architecture_source( 'src/Checkout/WooCommerce/NewShippingMethod.php' );
plugin_architecture_assert( str_contains( $new_shipping_method_source, 'handle_rejected_pickup_selection_rate' ) && str_contains( $new_shipping_method_source, 'clear_pickup_selection_for_family' ) && str_contains( $new_shipping_method_source, 'carrier_selected_pickup_quote_failed' ), 'Generic WooCommerce shipping method must clear rejected pickup selections by family.' );
plugin_architecture_assert( str_contains( $new_shipping_method_source, 'rate_without_transient_render_meta' ) && str_contains( $new_shipping_method_source, 'transient_pickup_rejection_keys' ) && str_contains( $new_shipping_method_source, 'preserve_shipping_method_choice' ), 'Generic rejected pickup recovery must preserve recovered shipping method choice and strip transient rejection metadata before session storage.' );
plugin_architecture_assert( ! str_contains( $new_shipping_method_source, 'wc_add_notice( $message, ' ) && ! str_contains( $new_shipping_method_source, 'wc_has_notice( $message, ' ), 'Rejected pickup recovery must not use global WooCommerce notices.' );
$checkout_rate_renderer_source = plugin_architecture_source( 'src/Checkout/WooCommerce/CheckoutRateRenderer.php' );
$checkout_validation_source = plugin_architecture_source( 'src/Checkout/WooCommerce/CheckoutValidation.php' );
$checkout_delivery_type_selector_source = plugin_architecture_source( 'src/Checkout/WooCommerce/CheckoutDeliveryTypeSelector.php' );
plugin_architecture_assert( str_contains( $checkout_rate_renderer_source, 'wdc-pickup-inline-notice' ) && str_contains( $checkout_rate_renderer_source, 'pickup_selection_rejected_message' ) && str_contains( $checkout_delivery_type_selector_source, 'wdc-pickup-inline-notice' ), 'Rejected pickup recovery message must render inline inside the affected pickup shipping method.' );
plugin_architecture_assert( str_contains( $checkout_validation_source, 'selected_rate_requires_pickup_point' ) && str_contains( $checkout_validation_source, 'requires_pickup_point' ), 'Checkout validation must use the generic requires_pickup_point capability before requiring a pickup selection.' );
plugin_architecture_assert( ! str_contains( $checkout_validation_source, 'jet_logistic' ) && ! str_contains( $checkout_validation_source, 'jet_logistic_pickup' ) && ! str_contains( $checkout_validation_source, 'JetLogistic' ), 'Checkout pickup validation must not contain a Jet Logistic-specific bypass.' );
$order_replacement_source = plugin_architecture_source( 'src/Orders/Application/OrderDeliveryReplacementService.php' );
$order_recalculation_source = plugin_architecture_source( 'src/Orders/Application/OrderDeliveryRecalculationService.php' );
$order_recalculation_renderer_source = plugin_architecture_source( 'src/Orders/Admin/OrderDeliveryRateRenderer.php' );
$order_recalculation_js_source = plugin_architecture_source( 'assets/admin/order-delivery-recalculation.js' );
$order_recalculation_css_source = plugin_architecture_source( 'assets/admin/order-delivery-recalculation.css' );
$shipment_button_policy_source = plugin_architecture_source( 'src/Shipments/Application/ShipmentMetaboxButtonPolicy.php' );
plugin_architecture_assert( str_contains( $order_replacement_source, 'requires_pickup_point( $rate )' ) && str_contains( $order_replacement_source, 'order_recalculation_requires_address( $rate )' ) && str_contains( $order_recalculation_source, 'order_recalculation_requires_address' ) && str_contains( $order_recalculation_renderer_source, 'data-requires-admin-address' ) && str_contains( $order_recalculation_js_source, 'rateRequiresAdminAddress' ), 'Order recalculation save/UI must use generic pickup and admin-address capability metadata.' );
plugin_architecture_assert( ! str_contains( $order_replacement_source, 'jet_logistic' ) && ! str_contains( $order_replacement_source, 'JetLogistic' ) && ! str_contains( $order_recalculation_js_source, 'jet_logistic' ) && ! str_contains( $order_recalculation_js_source, 'JetLogistic' ) && ! str_contains( $shipment_button_policy_source, 'jet_logistic' ) && ! str_contains( $shipment_button_policy_source, 'JetLogistic' ), 'Generic order recalculation and shipment button policy must not contain Jet Logistic-specific branches.' );
plugin_architecture_assert( str_contains( $order_recalculation_renderer_source, 'data-wdc-order-delivery-compact-summary' ) && str_contains( $order_recalculation_js_source, 'data-wdc-order-delivery-view-toggle' ) && str_contains( $order_recalculation_js_source, "node.dataset.view = nextView" ), 'Order recalculation compact/full mode must be generic modal presentation state.' );
plugin_architecture_assert( str_contains( $order_recalculation_source, 'compact_delivery_comment' ) && str_contains( $order_recalculation_source, 'compact_crossed_price_html' ) && str_contains( $order_recalculation_source, '$cheapest = $rates[0]' ), 'Order recalculation grouped compact summaries must be owned by backend payload from the cheapest tariff.' );
plugin_architecture_assert( str_contains( $order_recalculation_source, '1 === count( $group_rates )' ) && str_contains( $order_recalculation_source, 'single_tariff_method_payload' ) && str_contains( $order_recalculation_source, "\$payload['selected_tariff_object'] = \$rate->tariff_key" ), 'Order recalculation must collapse one-rate final tariff groups without losing tariff identity.' );
plugin_architecture_assert( str_contains( $order_recalculation_js_source, 'function openDeliveryRecalculationModal' ) && str_contains( $order_recalculation_js_source, 'openDeliveryRecalculationModal( closestBox( openButton ) )' ) && str_contains( $order_recalculation_js_source, 'function updateLocationGate' ) && str_contains( $order_recalculation_js_source, 'positiveLocationId( location.id || location.location_id )' ), 'Order recalculation modal opening must be separated from preview and gated by canonical location id.' );
plugin_architecture_assert( str_contains( $order_recalculation_css_source, '--wdc-order-compact-title-width' ) && str_contains( $order_recalculation_css_source, '--wdc-order-compact-price-width' ) && str_contains( $order_recalculation_css_source, 'width: max-content' ) && str_contains( $order_recalculation_js_source, 'function updateCompactLayout' ) && str_contains( $order_recalculation_js_source, '.wdc-order-delivery-rate__prices' ), 'Order recalculation compact layout must use shared measured title and price columns.' );
plugin_architecture_assert( str_contains( $order_replacement_source, 'copy_billing_recipient_to_empty_shipping_fields' ) && ! str_contains( $order_replacement_source, 'shipping_phone' ) && ! str_contains( $order_replacement_source, 'billing_phone' ), 'Order recalculation first-save recipient fallback must copy only billing first/last names into empty shipping fields.' );
$order_meta_persister_source = plugin_architecture_source( 'src/Checkout/WooCommerce/OrderShippingMetaPersister.php' );
plugin_architecture_assert( str_contains( $order_meta_persister_source, 'transient_pickup_rejection_keys' ) && str_contains( $order_meta_persister_source, 'unset( $meta[ $key ]' ), 'Order rate meta sanitizer must not persist transient rejected pickup render fields.' );
$calculation_builder_source = plugin_architecture_source( 'src/Orders/Application/DeliveryCalculationDataBuilder.php' );
plugin_architecture_assert( str_contains( $calculation_builder_source, 'base_price_adjustment_lines' ) && str_contains( $calculation_builder_source, 'Добавлен мешок и пломбировка' ) && str_contains( $calculation_builder_source, 'Добавлен мешок' ) && str_contains( $calculation_builder_source, 'Добавлена пломбировка' ), 'Delivery calculation builder must render PEK base adjustment formula notes for both/bag-only/sealing-only cases.' );
plugin_architecture_assert( str_contains( $calculation_builder_source, 'array() !== $audit || $round || $minimum || array() !== $base_adjustments' ) && str_contains( $calculation_builder_source, 'insert_base_price_adjustment_lines' ), 'Delivery calculation builder must render surcharge notes even without regular rules.' );
plugin_architecture_assert( str_contains( $calculation_builder_source, "'applied_rules' => \$audit" ) && ! str_contains( $calculation_builder_source, "'applied_rules' => \$base_adjustments" ), 'PEK surcharge note must not be inserted into applied_rules.' );
plugin_architecture_assert( str_contains( $calculation_builder_source, "'price_delta_rub' => \$final - \$api_base" ) && ! str_contains( $calculation_builder_source, 'pek_light_cargo_surcharge_kopecks +=' ), 'Rule delta must be calculated from adjusted base and builder must not add PEK surcharges again.' );
$pickup_map_js_source = plugin_architecture_source( 'assets/frontend/pickup-map/wdc-pickup-map.js' );
plugin_architecture_assert( str_contains( $pickup_map_js_source, 'typeLabel !== title' ) && str_contains( $pickup_map_js_source, "carrier === 'pek'" ), 'Generic pickup map must hide duplicate title/type rows and avoid displaying PEK technical UUID codes.' );
$pickup_map_css_source = plugin_architecture_source( 'assets/frontend/pickup-map/wdc-pickup-map.css' );
plugin_architecture_assert( str_contains( $pickup_map_js_source, 'function createLoadingOverlay' ) && str_contains( $pickup_map_js_source, 'activeLoadingRequestId' ) && str_contains( $pickup_map_js_source, 'aria-busy' ) && str_contains( $pickup_map_js_source, 'Загружаем пункты выдачи…' ) && str_contains( $pickup_map_css_source, '.wdc-pickup-map__loading' ) && str_contains( $pickup_map_css_source, '.wdc-pickup-list__loading' ) && ! str_contains( $pickup_map_js_source, 'ozon_delivery' ) && ! str_contains( $pickup_map_css_source, 'ozon_delivery' ), 'Generic pickup map loader must be carrier-neutral, accessible, and guarded by the current request lifecycle.' );
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
