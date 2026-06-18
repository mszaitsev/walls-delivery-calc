# WDC DPD Status Mapping

Version: 0.65.1.

The DPD status mapping feature is a settings-only dictionary and admin mapping layer. It does not request statuses from DPD, does not schedule cron/sync work, does not update shipments, and does not add DPD live create, label or cancellation flows.

## Source

The EventCode dictionary is taken from `docs/dpd/ws-integration-guide.docx`, section 5.5.4 "Справочник статусов заказа EventCode, EventName и его параметров(ParamName)". In the DOCX OOXML this is the table with columns:

- `Номер статуса`
- `Код`
- `Описание`
- `Название`

The implementation stores 75 EventCode rows. For each row it keeps:

- EventCode, for example `3305`
- EventName, for example `Заказ выдан на ПВЗ`
- DPD marker/code name from the `Код` column when present, for example `OrderReady`
- document comments where relevant, for example the `3304*` and `3305*` partial-delivery notes

ParamName/event parameters from the DPD document are intentionally not stored in `DpdStatusMapping::statuses()` and are not shown in the admin mapping table. Universal shipment status resolution is EventCode-based.

## Code

Main class: `src/Shipments/Dpd/DpdStatusMapping.php`.

Main methods:

- `DpdStatusMapping::statuses()` returns the full DPD EventCode dictionary.
- `DpdStatusMapping::default_mapping()` returns default EventCode to universal shipment-status mapping.
- `DpdStatusMapping::mapping()` returns defaults overlaid with saved admin overrides.
- `DpdStatusMapping::resolve($eventCode, $paramName = null)` returns a universal `DeliveryStatus`, with `unknown` as the safe fallback for unknown EventCode values. The second argument is accepted for backward compatibility but is ignored.

Universal status `pending_creation_in_carrier` is defined in `src/Domain/Status/DeliveryStatus.php` with label `Попытка создания в ТК`. It is ordered before `created_in_carrier` in `DeliveryStatus::all()`.

## Admin

Open `WDC → Службы доставки → DPD → Статусы DPD`.

The table shows:

- EventCode
- EventName
- DPD marker/code name
- universal shipment status select
- default universal shipment status

Use `Сохранить статусы DPD` to save overrides. Use `Сбросить к дефолтным значениям` to write the updated default mapping back to settings.

## Default Mapping Policy

Selected defaults in 0.65.1:

- `pending_creation_in_carrier`: `1001`, `1101`, `1201`, `1301`
- `unknown`: `2402`, `2408`, `2410`, `2411`, `2501`, `2701`, `2801`, `2901`, `2904`, `3301`, `3302`, `3401`
- `in_transit`: `2202`, `2210`, `2301`, `2304`, `2401`, `2407`, `3701`, `3303`, `3501`, `3601`
- `returning_to_sender`: `2404`, `2405`, `2406`, `2416`
- `delivered`: `3304`, `3305`, `3308`
- `ready_for_pickup`: `2201`, `2209`
- `returned_to_sender`: `3306`

Other EventCode defaults keep the existing reasonable 0.65.0 behavior where it does not conflict with the safer 0.65.1 rules.

## Tests

`tests/dpd/run-dpd-status-mapping-smoke.php` covers dictionary completeness against section 5.5.4, EventName/default validity, absence of ParamName/parameters in runtime rows and admin UI, new universal status ordering/select presence, saved override, invalid saved fallback, unknown EventCode fallback, admin tab render, save/reset behavior and CDEK mapping regression.

`tests/shipments/run-shipment-status-smoke.php` covers the new universal status ordering in the shared shipment-status flow alongside Russian Post mapping checks.