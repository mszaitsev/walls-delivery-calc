# WDC DPD Status Mapping

Version: 0.65.0.

The DPD status mapping feature is a settings-only dictionary and admin mapping layer. It does not request statuses from
DPD, does not schedule cron/sync work, does not update shipments, and does not add DPD live create, label or cancellation
flows.

## Source

The EventCode dictionary is taken from `docs/dpd/ws-integration-guide.docx`, section 5.5.4 "Справочник статусов заказа
EventCode, EventName и его параметров(ParamName)". In the DOCX OOXML this is the table with columns:

- `Номер статуса`
- `Код`
- `Наименование`
- `Параметр код`
- `Название`

The implementation stores 75 EventCode rows. For each row it keeps:

- EventCode, for example `3305`
- EventName, for example `Заказ выдан на ПВЗ`
- DPD status code from the `Код` column when present, for example `OrderReady`
- ParamName/parameter descriptions when listed in the documentation
- document comments where relevant, for example the `3304*` and `3305*` partial-delivery notes

## Code

- `src/Shipments/Dpd/DpdStatusMapping.php` owns the dictionary, defaults, saved mapping and resolver.
- `src/Infrastructure/Settings/SettingsRepository.php` registers `dpd_status_mapping` defaults in `wdc_core_settings`.
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` renders and saves the DPD admin tab.
- `src/Core/Plugin.php` registers `DpdStatusMapping` in the container.

Main methods:

- `DpdStatusMapping::statuses()` returns the full DPD EventCode dictionary.
- `DpdStatusMapping::default_mapping()` returns default EventCode to universal shipment-status mapping.
- `DpdStatusMapping::mapping()` returns defaults overlaid with saved admin overrides.
- `DpdStatusMapping::resolve($eventCode, $paramName = null)` returns a universal `DeliveryStatus`, with `unknown` as the
  safe fallback for unknown EventCode values.

`$paramName` is accepted for future event/parameter-specific mapping, but 0.65.0 resolves by EventCode only.

## Admin

Open `WDC → Службы доставки → DPD → Статусы DPD`.

The table shows:

- EventCode
- EventName
- ParamName / parameters
- universal shipment status select
- default universal shipment status

Use `Сохранить статусы DPD` to save overrides. Use `Сбросить к дефолтным значениям` to write the default mapping back to
settings.

## Default Mapping Policy

- Created/offer events: `created_in_carrier`
- Movement, customs and delivery-date-change events: `in_transit`
- Pickup-ready events: `ready_for_pickup`
- Courier handoff/delivery-in-progress events: `handed_to_courier`
- Final delivery events: `delivered`
- Return path events: `returning_to_sender` or `returned_to_sender`
- Cancellations: `cancelled`
- Refusal/problem/failure events: `rejected`
- Billing and notification-only events: `unknown`

## Tests

Run:

```bash
php tests/dpd/run-dpd-status-mapping-smoke.php
```

The smoke test covers dictionary completeness against section 5.5.4, EventName/default validity, saved override,
unknown fallback, ParamName preservation, admin tab render, select options, save/reset behavior and CDEK mapping
regression.
