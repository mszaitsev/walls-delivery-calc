# Locations And Pickup

Version: 0.124.1

Locations, aliases, delivery codes, FIAS/GAR import, postcode enrichment, pickup repositories, and pickup REST live under `src/Locations`, `src/Pickup`, and carrier pickup namespaces.

Generic location services own normalized lookup. Carrier pickup services own carrier import formats and carrier pickup identifiers. Checkout and admin code should consume normalized search results instead of parsing carrier payloads directly.

## Canonical Requirements

- City search uses the local locations database and should prefer exact and region-relevant matches.
- Fallback typed cities may be used at checkout but must not be silently promoted into the canonical locations database.
- Courier addresses are normalized when possible; fallback address text is allowed when the normalizer is unavailable or cannot match.
- Pickup selection stores only fields needed for checkout and carrier shipment creation.
- Pickup maps/lists must stay scoped to WDC UI and must not override global WooCommerce controls.

## Pickup Styling Ownership

WDC owns the pickup modal, pickup list, selected pickup card, and pickup checkout controls. It must not override global WooCommerce button pseudo-elements or unrelated checkout buttons. External payment/shipping text such as `Оплата по счету от ИП/ООО` belongs to the external plugin or theme that renders it; WDC styles only its scoped `wdc-pickup-*` UI.
