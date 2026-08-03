# Checkout

Version: 0.131.4

Checkout code lives in `src/Checkout` and frontend assets in `assets/frontend`. It maps WooCommerce packages into `QuoteRequest`, runs carriers through `CheckoutOrchestrator`, applies rules, sorts rates, persists selected pickup/courier metadata, and validates checkout input.

`CheckoutFeatureGate` is the single runtime policy for enabling the new WooCommerce checkout shipping method and frontend runtime. Its source of truth is the `enable_new_checkout_shipping` setting from the platform settings page. The checkout debug panel uses the same gate and additionally requires `show_checkout_debug_panel`.

## Delivery Lead Time Pipeline

Checkout and order-admin recalculation use the same runtime order:

1. carrier raw lead time;
2. shop processing calendar;
3. carrier working-day conversion;
4. delivery date rules;
5. planned date.

Carrier adapters return the raw carrier `DateRange`. `DeliveryLeadTimeNormalizer` adds the global `shop_processing_working_days` setting, default `2`, using `CalendarTypes::SHOP`, then converts carrier working days through `CalendarTypes::CARRIER_RU` only when the service-level `delivery_days_are_working` checkbox is enabled. That checkbox defaults to `false`.

The current calculation day is not counted for shop processing, and the handoff day is not counted when carrier working days are converted. Rules run only after the base duration is normalized into calendar days. The planned date is calculated after rules from the final minimum delivery-days boundary, so checkout comments and order metadata stay aligned with rule changes.

## CDEK EAEU Availability

The `cdek` delivery service owns CDEK availability for `RU`, `AM`, `BY`, `KZ`, and `KG`. Administrators configure those countries with the existing service-country checkboxes, persisted in `wdc_delivery_service_countries`; the global service enabled flag remains independent. Checkout must not create a `cdek_international` service or bypass the delivery-service country selection.

CDEK city resolution uses the quote country, city, optional postcode, optional region for local disambiguation, RU FIAS when present, and coordinates when available. Manual city input is allowed, but a CDEK quote is produced only after an unambiguous exact normalized `/v2/location/cities` match. Ambiguous, missing, or null API city results suppress only the CDEK quote and must not block other carriers.

CDEK pickup and courier branches are available for every enabled CDEK country when the API returns a supported tariff. Delivery modes `1` and `3` map to courier; modes `2` and `4` map to pickup. Modes `6` through `10` may remain diagnostic data but are not checkout delivery types. Pickup requires at least one country- and city-matching CDEK handout point; courier does not depend on pickup points.

When a CDEK manual city has a resolved CDEK city code but no canonical location coordinates, the pickup map may load points directly by city code and fit the initial viewport to the returned point coordinates. That viewport is presentation-only: it is not saved as destination coordinates, city context, location identity, or shipment data. Programmatic initial viewport changes suppress only their own map event burst; the first later user pan or zoom must load points normally, and any physical user interaction cancels pending initial auto-fit for that modal instance. Explicit viewport actions such as address search, geolocation, selected-point focus, and confirmed point selection claim the viewport, cancel pending provider fit, and load or render points directly without waiting for suppressed map events.

Checkout location search builds its preliminary pool in two tiers: direct own-name candidates from `place_name`, `city_name`, and `settlement_name` are fetched before broader hierarchy/context candidates from region, district, city, place, and settlement fields. Direct candidates are ordered consistently in production SQL and in-memory smokes as exact own-name, then prefix own-name, then display name. The final ranking remains in PHP, but exact city/place matches such as BY Minsk cannot be cut off by a large region-only SQL result set before scoring. Country filtering remains part of the query and does not rely on visual display names.

When a local DB location is selected, the picker option remains contextual, for example `г Минск - Минский р-н, Минская область`, but the WooCommerce city field receives only the own typed place, for example `г Минск`. Region, district, city, and place canonical values are submitted through `wdc_platform_location_*` hidden fields and the checkout runtime stores `city_context.city_name` and `settlement_name` without hierarchy text, so CDEK receives `Минск` rather than `Минский р-н, г Минск`. Hidden selected-location country must match the posted checkout country; mismatches are ignored server-side.

Changing the actual destination country after initial checkout load clears the active destination scope's city, state, postcode, selected local-location hidden fields, selected notice, picker search state, pickup selection, and cached quote state through the existing checkout recalculation flow. A first load with an existing country and repeated same-country events do not clear fields.

PEK destination pickup foundation is not checkout runtime yet. Version 0.131.4 provides a generic pickup-provider registry and PEK admin-only terminal diagnostics, but the public pickup REST controllers do not receive that registry, PEK checkout rates are not calculated, and no PEK terminal selection is stored in checkout session. This is deliberate: PEK terminal search is credentialed, rate-limited, requires canonical `location_id`, and depends on trusted cargo constraints that will be supplied by the future PEK quote/rate stage. The admin diagnostic uses one-place dimensions multiplied by `places_count`, accepts address fallback when canonical coordinates are partial/invalid, serializes PEK coordinate payloads as decimal strings, clears stale destination reports before explicit reruns, and reports terminal branch, division, and work time without treating PEK organizational `branchName` as checkout city. Malformed PEK zone/terminal contracts, malformed terminal IDs/text/coordinates/limits/schedules, and all-invalid terminal collections fail closed and are not cached as checkout-ready empty results. Checkout cargo/context integration remains a later stage.

## Canonical Requirements

- Rates are produced through WooCommerce shipping rates.
- A pickup delivery method does not automatically require a concrete pickup point. The canonical flag is `DeliveryRate::requires_pickup_point`; Jet Logistic returns a pickup rate with this flag disabled because Jet does not provide warehouse identifiers.
- The customer sees carrier, delivery type, delivery days/date, and final customer price.
- Pickup point UI appears only for pickup delivery methods.
- Courier address validation applies only when courier delivery is selected.
- Selected city, rate, tariff, pickup point, and courier address are preserved through checkout session/runtime state.
- Sorting can use price or delivery time.
- Manager recalculation in the order admin must save a clear order note with old/new delivery title and price.
- Planned checkout comments use `DeliveryRate::planned_delivery_comment` and the format `Доставка планируется* с 12 августа (среда).`.

Raw carrier quote responses are not order storage by default. Use diagnostics/logging for raw payloads and redact credentials.

Do not change checkout UX or tariff business logic while working on shipment framework docs unless a confirmed defect requires it.
