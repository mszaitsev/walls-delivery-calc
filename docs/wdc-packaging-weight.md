# WDC Packaging Weight

Version: 0.21.6.

Packaging weight is a global calculator setting, not a carrier-specific Russian Post setting.

Admin path:

`Калькулятор доставок -> Правила расчета -> Упаковка`

Storage key:

`packaging_weight_tiers` in `SettingsRepository`.

Each tier stores:

- `cart_weight_from_g`
- `cart_weight_to_g`
- `packaging_weight_g`

No default tiers are created. If the setting is empty or no tier matches the products weight, packaging weight is `0`.

Ranges are inclusive and sorted by `cart_weight_from_g`. Invalid rows are rejected in admin: negative values are clamped to `0`, incomplete rows are not saved, `to < from` is invalid, and overlapping ranges are invalid.

## Service Controls

Every delivery service has:

- `include_packaging_weight`, default `true`
- `packaging_weight_mode`, default `total_weight`

`total_weight` adds the matched packaging weight to the package total weight. It does not add a package item.

`package_item` adds a virtual item:

- SKU: `WDC_PACKAGING`
- name: `Упаковка`
- quantity: `1`
- weight: matched packaging weight
- dimensions: `1 x 1 x 1 cm`
- price: `0`

The tier is selected from products weight before packaging is applied.

## Russian Post

Russian Post international uses `total_weight`. Runtime applies packaging before the carrier quote, so the API receives products weight plus packaging when the service option is enabled. Overweight checks and weight-based rules see the package after packaging has been applied.

The Russian Post service simulation labels the input as products weight and displays products weight, packaging weight, final API weight, and packaging mode.
