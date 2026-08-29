<?php
declare(strict_types=1);

$root = dirname( __DIR__, 2 );
function oz_checkout_assert( bool $condition, string $message ): void { if ( ! $condition ) { throw new RuntimeException( $message ); } }
$plugin = file_get_contents( $root . '/src/Core/Plugin.php' ) ?: '';
$carrier = file_get_contents( $root . '/src/Carriers/Runtime/OzonDeliveryCarrier.php' ) ?: '';
$service = file_get_contents( $root . '/src/Carriers/OzonDelivery/Quote/OzonDeliveryQuoteService.php' ) ?: '';
$api = file_get_contents( $root . '/src/Carriers/OzonDelivery/Api/OzonDeliveryApiClient.php' ) ?: '';
$orchestrator = file_get_contents( $root . '/src/Checkout/Runtime/CheckoutOrchestrator.php' ) ?: '';
$pickup_js = file_get_contents( $root . '/assets/frontend/pickup-map/wdc-pickup-map.js' ) ?: '';
$pickup_rest = file_get_contents( $root . '/src/Pickup/Rest/CheckoutPickupPointRestController.php' ) ?: '';
oz_checkout_assert( str_contains( $plugin, 'OzonDeliveryCarrier::class' ) && str_contains( $plugin, 'OzonDeliveryQuoteService::class' ) && str_contains( $plugin, 'OzonDeliveryPickupPointProvider::class' ), 'Ozon runtime carrier, quote service and pickup provider must be wired.' );
oz_checkout_assert( str_contains( $carrier, 'pricing_live_confirmed()' ) && str_contains( $carrier, 'supports_courier_delivery: false' ) && str_contains( $carrier, "public const RATE_ID = 'ozon_delivery:pickup'" ), 'Ozon checkout carrier must be pickup-only and live-gated.' );
oz_checkout_assert( str_contains( $carrier, "public const TARIFF_KEY = 'pickup'") && str_contains( $carrier, "public const TARIFF_NAME = 'Ozon до ПВЗ'" ) && str_contains( $carrier, "'pickup_family' => OzonDeliverySettings::PICKUP_FAMILY" ), 'Ozon checkout rate must expose pickup service family and buyer title.' );
oz_checkout_assert( str_contains( $service, 'representative_point' ) && str_contains( $service, 'resolve_selection' ) && str_contains( $service, 'ozon_selected_point_stale' ) && str_contains( $service, 'pickup_provider_query' ), 'Ozon checkout must support representative quote, selected-point repricing and stale selection fail-closed.' );
oz_checkout_assert( str_contains( $api, 'order_checkout' ) && str_contains( $api, "'/v1/order/checkout'" ) && ! str_contains( $service, 'pickup_list' ) && ! str_contains( $service, 'pickup_info' ), 'Checkout pricing must call only order checkout and not catalog APIs.' );
oz_checkout_assert( ! str_contains( $orchestrator, 'Ozon' ) && ! str_contains( $pickup_js, 'ozon_delivery' ) && ! str_contains( $pickup_rest, 'ozon_delivery' ), 'Checkout orchestrator, generic pickup JS and generic pickup REST must remain carrier-neutral.' );
oz_checkout_assert( ! str_contains( $plugin, 'OzonDeliveryShipment' ) && ! str_contains( $plugin, 'OzonDeliveryShipmentAdapter' ) && ! str_contains( $plugin, 'OzonDeliveryDocument' ), 'Shipment Framework must not gain Ozon shipment mutations in this stage.' );
echo "Ozon Delivery checkout smoke passed.\n";
