# Packaging

Version: 0.132.4

Packaging code lives in `src/Packaging`. `PackagingBuilder` and `PackagingWeightCalculator` build shipment places from order/package data. Shipment allocation tests protect the bridge into shipment creation.

Carrier-specific parcel conversions belong in carrier request builders, not in the generic packaging builder.

Shipment creation should preserve enough package/place data for carrier payload reconstruction: weight, dimensions when available, declared value, and item allocation. Carrier request builders decide how those fields map to carrier parcels.

PEK quote light-cargo policy intentionally distinguishes product and packaging weight. The PEK `<3000` g sealing threshold uses `Package::weight_g`, which is product/item weight before store packaging. Calculator transport weight continues to use `Package::total_weight_g`, so store packaging can increase billable calculator weight without changing the PEK light-cargo threshold decision. The calculator payload keeps `isHP=false` for this mandatory-light-cargo policy because official PEK docs define `isHP` as protective transport packaging, not as a small-bag selector.
