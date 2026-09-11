# Russian Post Status Catalog Compatibility

Version: 1.0.11

The 1.0.11 native catalog was reconciled on 2026-09-12 with the official Russian Post tracking dictionary at <https://tracking.pochta.ru/support/dictionaries/operation_codes> and its public dictionary response. It contains 490 `operation_type_id:operation_attr_id` pairs.

The 486 pairs already supported in 1.0.10 retain exactly the same universal WDC status and carrier terminal metadata. Four newly published pairs are present for administration and diagnostics but default to `unknown`, matching the safe 1.0.10 behavior for previously unknown operations:

- `4:20` — «Досылка почты — Переадресация Бокс-сервис»;
- `4:21` — «Досылка почты — Досыл с уведомлением»;
- `8:94` — «Обработка — Определен метод доставки \"электронный\"»;
- `8:95` — «Обработка — Определен метод доставки \"на бумаге\"».

## Compatibility exception: `2:25`

The current official dictionary reports `2:25` («Вручение — Адресату по QR коду») as terminal. WDC 1.0.10 mapped it to `delivered` but retained `terminal=false`. Version 1.0.11 deliberately preserves both values. Changing the terminal flag could stop subsequent common-autosync polling and alter shipment lifecycle eligibility, so that correction is outside the status-mapping UI scope and requires a separate lifecycle regression task.

Administrators may override only the universal `DeliveryStatus`. Native terminal metadata is catalog-owned and is never persisted as a user setting.
