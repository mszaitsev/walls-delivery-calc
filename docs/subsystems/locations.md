# Locations And Pickup

Version: 0.122.0

Locations, aliases, delivery codes, FIAS/GAR import, postcode enrichment, pickup repositories, and pickup REST live under `src/Locations`, `src/Pickup`, and carrier pickup namespaces.

Generic location services own normalized lookup. Carrier pickup services own carrier import formats and carrier pickup identifiers. Checkout and admin code should consume normalized search results instead of parsing carrier payloads directly.

## Pickup Styling Ownership

WDC owns the pickup modal, pickup list, selected pickup card, and pickup checkout controls. It must not override global WooCommerce button pseudo-elements or unrelated checkout buttons. External payment/shipping text such as `Оплата по счету от ИП/ООО` belongs to the external plugin or theme that renders it; WDC styles only its scoped `wdc-pickup-*` UI.
