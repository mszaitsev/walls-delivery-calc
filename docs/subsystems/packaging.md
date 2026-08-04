# Packaging

Version: 0.133.1

Packaging code lives in `src/Packaging`. `PackagingBuilder` and `PackagingWeightCalculator` build shipment places from order/package data. Shipment allocation tests protect the bridge into shipment creation.

Carrier-specific parcel conversions belong in carrier request builders, not in the generic packaging builder.

Shipment creation should preserve enough package/place data for carrier payload reconstruction: weight, dimensions when available, declared value, and item allocation. Carrier request builders decide how those fields map to carrier parcels.

PEK quote light-cargo policy intentionally distinguishes product and packaging weight. The store surcharge threshold uses `Package::weight_g`, which is product/item weight before store packaging. Calculator transport weight continues to use `Package::total_weight_g`, so store packaging can increase billable calculator weight without changing light-cargo surcharge eligibility. The calculator payload keeps `isHP=false` and `sealingPositionsCount=0` for every weight because the bag/plombing adjustment is store-owned, configurable, and added after the PEK carrier `costTotal` is parsed.
