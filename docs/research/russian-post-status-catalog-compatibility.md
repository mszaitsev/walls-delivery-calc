# Russian Post Status Catalog Compatibility

Version: 1.0.12

The native catalog was reconciled on 2026-09-12 with the official Russian Post tracking dictionary at <https://tracking.pochta.ru/support/dictionaries/operation_codes> and its public dictionary response. It contains 490 `operation_type_id:operation_attr_id` pairs.

The 486 pairs already supported in 1.0.10 retain the same universal WDC status. Their carrier terminal metadata is unchanged except for the explicit `2:25` lifecycle correction described below. Four newly published pairs are present for administration and diagnostics but default to `unknown`, matching the safe 1.0.10 behavior for previously unknown operations:

- `4:20` — «Досылка почты — Переадресация Бокс-сервис»;
- `4:21` — «Досылка почты — Досыл с уведомлением»;
- `8:94` — «Обработка — Определен метод доставки \"электронный\"»;
- `8:95` — «Обработка — Определен метод доставки \"на бумаге\"».

## Lifecycle correction: `2:25`

The official dictionary reports `2:25` («Вручение — Адресату по QR коду») as terminal. WDC 1.0.10 mapped it to `delivered` with `terminal=false`, and 1.0.11 deliberately preserved that compatibility exception while introducing the status-mapping UI. Starting with 1.0.12, WDC follows the official catalog: the universal status remains `delivered`, while carrier terminal metadata is `true`. This is an intentional shipment-lifecycle behavior correction, covered by mapper and common-autosync regression tests.

Administrators may override only the universal `DeliveryStatus`. Native terminal metadata is catalog-owned and is never persisted as a user setting.
