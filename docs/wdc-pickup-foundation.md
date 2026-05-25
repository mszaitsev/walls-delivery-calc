# WDC Pickup Foundation

## Architecture

Pickup foundation adds storage and checkout runtime support for pickup delivery without maps, modal UI, carrier API calls, or shipment export. Legacy shipping code has been removed.

The first runtime path is:

1. Carrier rate exposes `delivery_type`, `requires_pickup_point`, or `requires_courier_address`.
2. Checkout renders a delivery type control below WDC platform shipping rates.
3. Pickup rates render a plain HTML pickup point `<select>`.
4. The selected delivery type and pickup point are stored in WooCommerce session.
5. Checkout orchestration receives the session context and filters rates by selected delivery type.
6. Order creation persists WDC shipping meta and pickup meta.

## Storage

`wp_wdc_pickup_points` is created by `database/migrations/0006_create_pickup_points_table.php`.

`PickupPointRepository` supports:

- `save(PickupPoint $point)`
- `save_many(array $points)`
- `find_by_code(string $carrier, string $code)`
- `search(string $carrier, string $country, string $city)`
- `count_all()`

The unique storage identity is `carrier_key + point_code`.

## Runtime Lookup

Checkout pickup selection reads points from `PickupPointRepository` by carrier, country, and city. Runtime no longer falls back to bundled demo JSON. Test pickup data lives under `tests/fixtures/demo`.

## Checkout Flow

`CheckoutDeliveryTypeSelector` uses `woocommerce_after_shipping_rate`.

Pickup rate behavior:

- renders delivery type radio
- renders pickup point select
- stores selected pickup point in WC session

Courier rate behavior:

- renders delivery type radio
- renders a notice that the checkout address is used

No frontend modal, map, REST endpoint, or AJAX API is introduced.

## Session Persistence

`CheckoutSessionManager` stores:

- selected delivery type
- selected pickup carrier
- selected pickup point selection
- calculated WDC rates

The selected pickup payload contains carrier, rate id, point code, address, comment, work time, and selection timestamp.

## Order Meta

`OrderShippingMetaPersister` stores existing WDC platform shipping metadata and, for pickup orders:

- `_wdc_platform_pickup_code`
- `_wdc_platform_pickup_address`
- `_wdc_platform_pickup_comment`
- `_wdc_platform_pickup_work_time`

## Future Map Integration

The current selector intentionally stays HTML-only. A future map integration can consume the same provider and repository layers, then write the same session payload.

## Future AJAX And Modal Integration

AJAX/modal work can be added later around the existing storage contract:

- provider endpoint for city point search
- modal point picker
- map/list synchronization
- checkout fragment refresh

The order meta and orchestration contracts should not need to change for that UI upgrade.
