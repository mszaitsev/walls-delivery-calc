# Packaging

Version: 0.151.2

Packaging code lives in `src/Packaging`. `PackagingBuilder` and `PackagingWeightCalculator` build shipment places from order/package data. Shipment allocation tests protect the bridge into shipment creation.

Manual delivery pricing does not duplicate packaging calculation. `CheckoutOrchestrator` applies the existing `PackagingWeightCalculator` for each `DeliveryService` before runtime carrier quoting; manual per-kg and weight-range modes use the resulting `Package::total_weight_g` as chargeable weight. When `include_packaging_weight=false`, the package keeps product weight semantics, including `0 g` for a physical zero-weight product; when it is enabled, the existing service packaging policy decides whether packaging weight is included as total weight or a package item.

WooCommerce product and variation saves bump the shared delivery rates cache version, so product weight and dimension updates cannot keep stale package-sensitive delivery calculations alive in checkout sessions.

Carrier-specific parcel conversions belong in carrier request builders, not in the generic packaging builder. A carrier may, however, supply optional generic `PackagingBuilderConfig` parcel limits through a carrier-owned factory. Without limits, all historical packing behavior remains unchanged. With limits, box formats are filtered by rotated fit, and the builder uses a deterministic bounded N-box path rather than emitting an oversized stacked fallback. An indivisible item outside the configured limit fails closed.

Ozon Delivery owns `OzonDeliveryPackagingBuilderFactory`, which configures a maximum parcel of 50x50x30 cm with rotation allowed. `box_40_40_40` is therefore excluded for Ozon. Each resulting `PackagingParcel` becomes one Ozon checkout pricing posting; content that needs more than two parcels remains valid, while a single item that cannot fit does not produce an oversized posting. Actual Ozon shipment creation does not persist or reuse this checkout breakdown: managers confirm real shipment places and item allocation in the Shipment modal.

Shipment creation should preserve enough package/place data for carrier payload reconstruction: weight, dimensions when available, declared value, and item allocation. Carrier request builders decide how those fields map to carrier parcels.

PEK quote light-cargo policy intentionally distinguishes product and packaging weight. The store surcharge threshold uses `Package::weight_g`, which is product/item weight before store packaging. Calculator transport weight continues to use `Package::total_weight_g`, so store packaging can increase billable calculator weight without changing light-cargo surcharge eligibility. The calculator payload keeps `isHP=false` and `sealingPositionsCount=0` for every weight because the bag/plombing adjustment is store-owned, configurable, and added after the PEK carrier `costTotal` is parsed.
