# Packaging

Version: 0.124.18

Packaging code lives in `src/Packaging`. `PackagingBuilder` and `PackagingWeightCalculator` build shipment places from order/package data. Shipment allocation tests protect the bridge into shipment creation.

Carrier-specific parcel conversions belong in carrier request builders, not in the generic packaging builder.

Shipment creation should preserve enough package/place data for carrier payload reconstruction: weight, dimensions when available, declared value, and item allocation. Carrier request builders decide how those fields map to carrier parcels.
