# WDC Carrier Foundation

## Carrier architecture

The runtime delivery layer lives under `src/Carriers` and `src/Checkout`. As of `0.20.0`, the legacy `includes/*` shipping method is removed and the `src` WooCommerce shipping method is the active runtime path.

Carrier adapters implement `CarrierAdapterInterface`:

- identity and capabilities are explicit domain objects;
- country support is checked before quoting;
- `quote()` returns a `DeliveryQuote`;
- shipment creation and status sync are intentionally out of scope.

`CarrierRegistry` owns adapter registration and lookup. It can return all adapters, enabled adapters, and enabled adapters for a destination country.

## Orchestration pipeline

`CheckoutOrchestrator` is the runtime pipeline:

1. collect carriers from the registry;
2. load quotes from cache when enabled;
3. quote each carrier through `CarrierExecutionGuard`;
4. collect `DeliveryRate` objects;
5. apply rules through `RuleAppliedRateBuilder`;
6. remove disabled rates from visible checkout output;
7. sort rates;
8. add a fallback rate when no visible rates remain.

The richer `CheckoutCalculationResult` carries rates, fallback state, cache hit count, rule audit, and carrier errors. `calculate_rates()` remains a simple rates-only convenience method.

## Registered carriers

`CarrierRegistry` registers Russian Post international through `RussianPostInternationalCarrier`. Demo carriers are not registered in runtime; smoke tests use local fixtures under `tests/fixtures`.

## Rule integration

`RuleAppliedRateBuilder` maps an immutable `DeliveryRate` into a `RuleEvaluationContext`, applies `RuleEngine`, and returns a rebuilt `DeliveryRate` plus audit rows.

Supported rule effects:

- final price;
- crossed price for promo shipping;
- disabled state and disabled reason;
- comments;
- delivery days.

The admin simulation uses in-memory sample rules and does not load runtime demo datasets.

## Cache

`QuoteCache` is owned by `src/Checkout/Cache` and no longer wraps the removed `WDC_Cache` class. It uses a runtime namespace and in-memory storage for smoke tests.

The cache key is derived from:

`country / city / weight / order_total / carrier / delivery_type / date`

TTL is until the end of the current day. `invalidate_all()` bumps a namespace token.

## Sorting

`RateSorter` supports:

- `cheapest`: final price, then delivery days;
- `fastest`: minimum delivery days, then final price.

## Fallback

`FallbackRateFactory` creates a visible, zero-price `DeliveryRate` with `delivery_type=unknown`:

`Нет видимых доступных вариантов доставки, обратитесь к менеджеру магазина`

Fallback is used only when no visible available rates remain after carrier execution and rules.

## Runtime guards

`CarrierExecutionGuard` catches carrier exceptions and records carrier errors. Timeout handling is currently an elapsed-time abstraction; no async runtime is introduced.

`CheckoutLogger` logs carrier start, carrier finish, rates count, fallback use, and cache hit/miss without customer PII.

## WooCommerce bridge

`WooCommerceRateMapper` maps `DeliveryRate` to a WooCommerce shipping-rate array:

- `id`;
- `label`;
- `cost`;
- `meta_data`.

`NewShippingMethod` is only a skeleton and is not registered into production checkout runtime. The `new_shipping_method_enabled` feature flag defaults to `false`.

## Future real carriers

Real integrations can be added by implementing `CarrierAdapterInterface` and registering adapters in `CarrierRegistry`. Russian Post, CDEK, DPD, Yandex, pickup maps, shipment export, status sync, frontend autocomplete, REST, AJAX, and template overrides remain future work.
