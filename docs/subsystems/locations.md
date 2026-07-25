# Locations And Pickup

Version: 0.128.7

Locations, aliases, delivery codes, FIAS/GAR import, postcode enrichment, pickup repositories, and pickup REST live under `src/Locations`, `src/Pickup`, and carrier pickup namespaces.

Generic location services own normalized lookup. Carrier pickup services own carrier import formats and carrier pickup identifiers. Checkout and admin code should consume normalized search results instead of parsing carrier payloads directly.

## CDEK And DPD Geography

The shared `wdc_locations` table stores canonical locations for all supported countries. Russian locations continue to come from the GAR/FIAS pipeline, and DPD Geography keeps the existing RU matching flow. For CDEK EAEU support, valid DPD Geography rows for `AM`, `BY`, `KZ`, and `KG` are imported directly as foreign canonical locations without fake FIAS, GAR, or KLADR identifiers.

Foreign DPD imports use `dpd_city_id` in `wdc_location_delivery_codes` for idempotency and a country-aware place identity fallback before creating a new location. Same-named cities in different countries must remain separate. Manual checkout city resolution for CDEK does not insert CDEK city codes or free-text cities into `wdc_locations`; the resolved CDEK code lives only in calculation/session/shipment creation context.

## Canonical Requirements

- City search uses the local locations database and should prefer exact and region-relevant matches.
- Fallback typed cities may be used at checkout but must not be silently promoted into the canonical locations database.
- Courier addresses are normalized when possible; fallback address text is allowed when the normalizer is unavailable or cannot match.
- Pickup selection stores only fields needed for checkout and carrier shipment creation.
- Pickup maps/lists must stay scoped to WDC UI and must not override global WooCommerce controls.
- Russian Post pickup import/settings live under `Службы доставки → Почта России → ПВЗ / ОПС`; pickup database diagnostics live under `Службы доставки → Почта России → Диагностика базы ПВЗ`.

## Pickup Styling Ownership

WDC owns the pickup modal, pickup list, selected pickup card, and pickup checkout controls. It must not override global WooCommerce button pseudo-elements or unrelated checkout buttons. External payment/shipping text such as `Оплата по счету от ИП/ООО` belongs to the external plugin or theme that renders it; WDC styles only its scoped `wdc-pickup-*` UI.
