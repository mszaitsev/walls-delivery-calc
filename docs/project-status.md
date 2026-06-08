# Project Status

0.41.12 note: the order-admin delivery recalculation save-flow now separates pickup and courier address requirements correctly. Pickup saves require selected rate + selected pickup point and write the selected pickup address to WooCommerce shipping address while keeping pickup details in WDC pickup meta and out of order notes. Courier saves expose a manager address-normalization block in the modal and are blocked until a normalized address is supplied. The pickup map's manual address search now geocodes the typed address through the existing DaData/address-normalization wrapper instead of placing a temporary marker on a pickup point fallback. Saved calculation data keeps package/API/rules/result details from rate meta, API base price remains distinct from final cost, method titles include delivery text, and note city-change detection uses canonical location identity to avoid duplicate/same-city old/new lines.

0.41.11 note: order-admin delivery recalculation save UX was refined. Pickup saves now require selected rate + selected pickup point only; manager-entered address normalization is no longer required for pickup and pickup shipping address is not replaced by pickup address. If the selected location was not changed, the modal preselects the order's current pickup point from WDC pickup meta. The pickup map picker now syncs marker clicks with the side list, scrolls the active row into view, supports a temporary search pin for manual address/postcode matches, and keeps the picker dialog within the visible viewport with a separately scrolling list.

0.41.9 note: the order-admin delivery recalculation scenario is now complete. Preview/recalculation stays available for orders with shipments or unusual shipping item state, but saving the selected delivery is guarded separately: save is blocked for multiple shipping items and any registered shipment marker. Valid saves create a missing WooCommerce shipping item or replace the single existing one, rewrite WDC platform/calculation/pickup meta, update shipping address, recalculate totals, add a private Russian order note, and reload the admin page. Pickup saves require selected pickup point; the pickup point address is not written to WooCommerce shipping address or order notes.

0.41.8 note: the order-admin delivery recalculation pickup map initial load now requests `mode=location` and loads all available pickup points for the selected settlement instead of searching by the settlement base postcode. The admin pickup endpoint resolves location mode through location id/FIAS/GAR, city+region, display/city, and only then postcode fallback. Manual `mode=search` remains available for explicit administrator postcode/address/city searches. The order is not mutated: no delivery save, shipping item replacement, totals recalculation, shipping address update, order note, or `_wdc_delivery_calculation_data` update.

0.41.7 note: the order-admin delivery recalculation pickup selector now uses the existing pickup map provider assets instead of a table-only popup. Pickup rates open a picker with map, markers, status area and side list; marker/list selection updates only the modal `selectedPickupPoint` JS state. If the map provider is unavailable, the picker remains usable as a list and shows an explanatory fallback message. The order is not mutated: no delivery save, shipping item replacement, totals recalculation, shipping address update, order note, or `_wdc_delivery_calculation_data` update. The picker also now uses `escapeAttribute()` for `data-index` attributes.

0.41.6 note: order-admin delivery recalculation preview now supports preview-only pickup point selection for pickup rates. The modal shows pickup controls only for rates marked `requires_pickup_point`, opens an admin pickup search picker backed by the existing Russian Post pickup repository, stores the chosen point only in JS modal state as `selectedPickupPoint`, and clears it when a courier rate is selected. The admin pickup endpoint is protected by nonce/capability checks and the same shipment block. The order is not mutated: no delivery save, shipping item replacement, totals recalculation, shipping address update, order note, or `_wdc_delivery_calculation_data` update.

0.41.4 note: order-admin delivery recalculation preview now supports a modal-only settlement override. The modal shows the current order settlement, searches settlements through the existing checkout location search payload, sends the selected location only in the preview AJAX request, and recalculates rates for that destination with an explicit `Расчет выполнен для: ...` status. The order is not mutated: no shipping address update, delivery save, shipping item replacement, totals recalculation, pickup selection, order note, or `_wdc_delivery_calculation_data` update.

0.41.2 note: order-admin delivery recalculation preview now opens in a custom admin modal instead of rendering inline in the `Калькулятор доставок` metabox. The metabox keeps only the `Пересчитать доставку` button when recalculation is allowed; the modal contains header/body/footer, close controls, status/loading/error area, rates content area, and a disabled save placeholder for the next patch. The preview-only boundary is unchanged: no delivery save, pickup selection, shipping item replacement, totals recalculation, shipping address change, order note, or `_wdc_delivery_calculation_data` update.

0.41.0 note: the first order-admin delivery recalculation foundation is implemented as preview-only UI. `Калькулятор доставок` can request a recalculation from the WooCommerce order, WDC builds a `QuoteRequest` from order items/address/meta, runs all available services through `CheckoutOrchestrator`, renders pickup/courier rates and Russian Post domestic tariff groups, and blocks preview when `_wdc_shipments` indicates an already created shipment. This version intentionally does not save delivery changes, replace shipping items, recalculate totals, change shipping address, select pickup points, override locations, create order notes, or update `_wdc_delivery_calculation_data`.

0.40.0 note: documentation audit branch. No runtime functionality was added. The status below was refreshed against the current codebase, profile documents, migrations, assets and smoke-test entrypoints after the 0.39.x Russian Post shipment/status work.

0.39.5 note: the same Russian Post actual-cost lookup now runs after ordinary automatic shipment creation from the order metabox. After a successful create response with barcode/tracking number, WDC safely calls `GET /1.0/backlog/search?query={barcode}`, extracts `total-rate-wo-vat + total-vat` through the shared actual-cost extractor, and stores source `backlog_search_after_create`. Lookup errors or missing totals do not fail shipment creation, do not create order notes, and do not show warnings.

0.39.4 note: the WooCommerce order shipment metabox shows the real Russian Post shipment price when manual tracking attach finds the parcel through `GET /1.0/backlog/search?query={barcode}`. WDC stores `total-rate-wo-vat + total-vat` from that response in `_wdc_shipments`, formats it as `Цена: {amount} руб.`, and compares it with the checkout `Базовая стоимость API` from `_wdc_delivery_calculation_data`; up to 3% over base is ok/green, more than 3% is warning/red, and missing base cost is neutral.

0.39.3 note: the Russian Post courier calculation postcode fill tool on `WDC -> Локации` runs each backend step as sequential rate-limited probes at about 6 requests/sec. Steps are capped at 18 probes or 3 seconds, the browser keeps one active AJAX step at a time, and job JSON includes target/actual RPS and step timing diagnostics.

0.39.2 note: successful shipment status refreshes no longer create WooCommerce order notes. WDC creates an order note only when automatic order status mapping changes the WooCommerce order status, using the compact format `Посылка {barcode}`, `Статус: {universal status}.`, `Статус заказа изменён:`, `{from_status} -> {target_status}`.

0.39.1 note: autosync no longer drops terminal shipments before order status mapping. Terminal universal statuses still skip carrier tracking refresh, but `ShipmentStatusAutoSyncService` applies `ShipmentOrderStatusMappingService` to the already saved terminal shipment state, records `terminal_status_no_tracking_update`, and includes the mapping result in diagnostics.

0.39.0 note: universal shipment status to WooCommerce order status mapping is implemented. The `WDC -> Статусы -> Соответствие статусов` tab stores a disabled-by-default global enable flag and `shipment_status_order_status_mapping`, reads available WooCommerce statuses through `wc_get_order_statuses()` including custom statuses, and applies mapping through `ShipmentOrderStatusMappingService` after shipment status is saved by `ShipmentStatusUpdateService`.

0.38.0 note: universal shipment status autosync is implemented through `ShipmentStatusAutoSyncService`, `WDC -> Статусы`, WP Cron hook `wdc_shipment_status_autosync`, schedule `wdc_every_6_hours`, and lock `wdc_shipment_status_autosync_lock`. The first dispatcher target is `russian_post_domestic`, which reuses `ShipmentStatusUpdateService::update_russian_post()`.

## Общий статус

- Текущая версия: `0.41.12`.
- Текущая базовая ветка: `develop`.
- Рабочая ветка: `feature/order-delivery-recalculation`.
- Последнее обновление статуса: 2026-06-08.
- Общая готовность проекта: примерно 72%.
- Следующий рекомендуемый этап: стабилизировать ручной QA сценария `feature/order-delivery-recalculation` на реальном WooCommerce order admin и затем переходить к следующим carrier/checkout улучшениям.

## Краткое резюме

Проект уже имеет рабочую платформу расчета доставки для WooCommerce: core bootstrap, DI container, миграции, checkout runtime, календарь доставки, Rule Engine, локальную базу FIAS/GAR, DaData-подсказки и обогащение, слой delivery services, внутреннюю и международную Почту России, ПВЗ Почты России с импортом/REST/картой, а также ручной runtime отправлений Почты России.

После веток 0.38.x-0.39.x закрыты важные части carrier lifecycle для Почты России: создание отправления, отмена backlog-отправления, ручное внесение трекинга, ручное и автоматическое обновление статусов, универсальные статусы, автосинхронизация, автоматическое изменение WooCommerce-статусов заказов и сверка фактической стоимости отправления с расчетом checkout. Не закрыты документы/ярлыки/партии/Ф103 внутри WDC, полноценный admin recalculation, история статусов, production operations dashboard и будущие перевозчики.

## Готовность по блокам

| Блок | Статус | Готовность | Комментарий |
| ---- | ------ | ---------: | ----------- |
| Core Platform | done | 100% | Entrypoint, autoloader, container, settings, logger, encryption, migrations, HPOS declaration, admin menu. |
| Locations / FIAS | done | 88% | Locations/regions/aliases, GAR import, snapshots, incremental updates, checkout lookup, DaData coordinate/postcode helpers. |
| Delivery Services | done | 90% | Registry, repositories, settings/countries/admin UI, service rules integration, unified Russian Post domestic service migration. |
| Russian Post Domestic | done | 92% | Unified `russian_post_domestic`, pickup/courier split, Tariff API, tariff variants, courier calc postcode substitution, Otpravka credentials and shipment settings. |
| Pickup Points | done | 90% | Russian Post import, diagnostics, local repository, REST API, checkout map, order persistence, admin shipment-modal map selector. |
| Shipments | partial | 80% | Russian Post create/cancel/remove/manual attach, draft/preview, address normalization, actual-cost lookup; documents and other carriers are absent. |
| Shipment Tracking | partial | 78% | Russian Post Tracking API refresh, mapper, universal status persistence and manual metabox refresh; no full status history UI. |
| Shipment Autosync | partial | 75% | WP Cron/manual run, 6-hour interval, lock, diagnostics and Russian Post dispatch exist; only one carrier target and no advanced batching. |
| Order Status Mapping | done | 80% | Carrier-neutral universal shipment status -> WooCommerce status mapping, custom statuses, terminal-status autosync handling and compact private notes. |
| Checkout Integration | partial | 86% | WooCommerce method, city/location picker, sorting, tariff selector, courier validation, pickup map, order meta, calculation metabox and completed admin order delivery recalculation save flow. |
| Rule Engine | done | 88% | Conditions/groups, audit, price/days mutations, comments, service rules, simulation and packaging tab. |
| International Shipping | partial | 75% | Russian Post international rates/country mapping/fallback work; no shipment creation/tracking/documents for international flow. |
| Future Carriers | not-started | 0% | CDEK, DPD, Yandex Delivery, PEK, Energia, Aerogruz and Jet have no runtime adapters. |
| Operations / Monitoring | partial | 50% | Logger, diagnostics pages and autosync diagnostics exist; no production dashboard/rotation strategy. |
| Documentation | partial | 76% | Profile docs are broad and useful; some historical docs need version/status cleanup after this audit. |

## Реализовано

### Platform, Data And Checkout

- Plugin entrypoint and `WDC_VERSION` are updated to `0.40.0`.
- `src/Core` wires runtime environment, autoloader, DI container, feature flags, requirements checks, plugin hooks and activation.
- `src/Infrastructure` provides settings, logging/redaction, encryption, Action Scheduler/WP Cron wrapper and migration manager.
- `database/migrations` contains the active schema for calendar, locations, GAR import, rules, delivery services, Russian Post pickup points and unified Russian Post domestic service.
- `src/WooCommerce/HPOSCompatibility.php` declares WooCommerce HPOS compatibility.
- `src/Domain` contains address, calendar, carrier, common, package, pickup, quote, shipment and status domain classes.
- `src/Calendar` stores delivery calendars, generates years, calculates/format delivery dates and exposes admin UI.
- `src/Locations` covers FIAS/GAR clients/import/snapshots/incremental updates, local locations/regions, aliases, display names, search, country index and courier calculation postcode fill.
- `src/Checkout` covers WooCommerce shipping method registration, package/rate mapping, quote orchestration, caching, sorting, validation, city selector, DaData suggestions, courier address handling, pickup map and order meta persistence.
- `src/Rules` implements the Rule Engine, service-level rules, default fallback rules, condition groups/expressions, audit trail, price/days changes, comments, stop-processing, simulation and admin builder.
- `src/Packaging` adds global/service-aware packaging weight calculation used by delivery services and checkout.

### Delivery Services

- `src/DeliveryServices` stores and manages delivery services, countries, settings and service admin UI.
- Runtime availability supports carrier directory, selected countries, all countries and all-except-selected modes.
- Service rules, minimum price, rounding, packaging weight and customer comments are integrated into checkout rates.
- Migration `0026_unify_russian_post_domestic_service.php` copies old domestic pickup/courier settings into one `russian_post_domestic` service and removes obsolete domestic service rows/settings/country rows/service-rule bindings.

### Russian Post Domestic

- A single domestic carrier/service is the source of truth: `carrier_key=russian_post_domestic`, `service_key=russian_post_domestic`.
- Pickup and courier are split by `delivery_type` and checkout group/rate ids such as `russian_post_domestic:pickup` and `russian_post_domestic:courier`.
- Domestic Tariff API requests support `pack=99`, declared value `sumoc`, tariff variants, per-tariff ECOM flag, API token, cache/debug metadata and safe error diagnostics.
- Configurable method titles, tariff labels and delivery ranges are persisted in checkout/session/order calculation data while visible WooCommerce shipping item meta is kept clean.
- Courier Russian Post tariff calculation can use `russianpost_courier_calc_postal_code` found by the admin location tool `Подобрать индексы для курьерской Почты России`.
- Otpravka credentials, Tracking credentials, postoffice acceptance indices, default from postcode and shipment settings live in `WDC -> Службы доставки -> Почта России по РФ`.

### Pickup Points

- Russian Post pickup points are imported into `wp_wdc_pickup_points_russian_post`.
- Import state, diagnostics, local location matching/rebind, point type settings and work-time formatting are implemented.
- Checkout pickup REST/search/detail endpoints and selection state are implemented.
- Checkout map uses the frontend pickup map stack with Leaflet/Yandex providers and selected pickup persistence.
- WooCommerce order admin shipment modal can choose a Russian Post OPS/PVZ on the map; selection updates only the shipment draft/preview/create request and does not rewrite checkout/order meta.

### Russian Post Shipments

- WooCommerce order metabox `Отправления` prepares Russian Post shipment drafts from HPOS-safe order APIs and saved WDC order meta.
- Creation sends `PUT /2.0/user/backlog` through `RussianPostShipmentAdapter`.
- Pickup/OPS payloads use `address-type-to=DEMAND`, `index-to`, `region-to`, `place-to`; domestic payloads send `mail-direct=643`.
- ECOM payloads are controlled by tariff setting `is_ecom` and use `ecom-data.delivery-point-index`.
- Courier payloads require Russian Post address normalization before create.
- Successful create stores barcode/ШПИ and hidden technical `backlog_order_id`.
- After successful create, the modal closes, a toast is shown, the first status refresh starts automatically, and WDC attempts actual-cost lookup through `backlog/search` without failing the create flow.
- Cancellation calls `DELETE /1.0/backlog` with `backlog_order_id` and is available only for operation `28 / Присвоение идентификатора`.
- Local remove-from-order clears only WooCommerce `_wdc_shipments` state and does not call Russian Post.
- Manual tracking attachment normalizes barcode/ШПИ, searches `backlog/search`, falls back to `shipment/search`, stores lookup source/backlog id when available and starts the first status refresh.
- Actual Russian Post cost from `total-rate-wo-vat + total-vat` is stored and compared with checkout `Базовая стоимость API`; <=3% over base is ok, more is warning, missing base is neutral.

### Shipment Statuses, Autosync And Order Status Mapping

- `src/Domain/Status/DeliveryStatus.php` defines universal shipment statuses.
- `RussianPostTrackingApiClient` calls Russian Post Tracking API `getOperationHistory` over SOAP 1.2.
- `RussianPostTrackingStatusMapper` contains the fixed Russian Post operation/attribute mapping, including pickup/courier corrections and `type:-` fallback.
- Manual metabox status refresh stores universal status, raw carrier operation data, checked timestamp and terminal marker in `_wdc_shipments`.
- `ShipmentStatusAutoSyncCron` registers hook `wdc_shipment_status_autosync`, schedule `wdc_every_6_hours` and keeps the event scheduled even when disabled.
- `ShipmentStatusAutoSyncService` scans selected WooCommerce order statuses, skips missing tracking/unsupported carriers/terminal tracking refreshes, dispatches Russian Post domestic updates and stores diagnostics.
- Terminal statuses still run order status mapping against saved shipment state.
- `ShipmentOrderStatusMappingService` applies universal shipment status -> WooCommerce order status mapping when enabled, validates target statuses from `wc_get_order_statuses()`, updates orders and adds a compact private WDC note only when the order status actually changes.

### Russian Post International

- `RussianPostInternationalCarrier` supports non-RU Russian Post international quote calculation.
- Country mapping, Russian Post country directory refresh/manual mapping, fallback rates, VAT normalization, packaging weight and service rules are implemented.
- International flow is quote-only: shipment creation, tracking and documents are not implemented for international delivery.

## Частично реализовано

- Checkout UX is functional, but full browser-storage TTL behavior, final edge-case stabilization and carrier-neutral pickup orchestration for future carriers remain.
- Order admin recalculation has the full manager workflow for previewing rates, choosing pickup/courier, saving delivery, replacing shipping items and adding systematic notes; remaining work is real-store QA and carrier expansion.
- Shipment runtime is strong for Russian Post domestic manual operations, but carrier-neutral shipment lifecycle for other carriers is not implemented.
- Shipment tracking has current status refresh and autosync, but no full status event history/timeline UI.
- Shipment autosync supports Russian Post domestic only and scans all selected orders without advanced batching/pagination controls.
- Operations diagnostics exist, but production monitoring, log/document rotation and long-running import hardening remain limited.
- Documentation is mostly current in active Russian Post/status docs, but older foundation docs still contain stage-specific historical wording.

## Не реализовано

- CDEK runtime adapter, settings, rates, pickup/courier flow, shipments and statuses.
- DPD runtime adapter, settings, rates, pickup/courier flow, shipments and statuses.
- Yandex Delivery runtime adapter, pricing, pickup/courier flow and future offer confirmation.
- PEK, Energia, Aerogruz, Jet adapters.
- Plugin-generated Russian Post labels, forms, batches and F103.
- Carrier document storage, download UI and rotation.
- Full carrier-neutral status history model/UI.
- Automatic shipment creation outside the manager-triggered order metabox flow.
- Shipment integrations for international Russian Post and all future carriers.
- Full manual/fixed pseudo-carrier lifecycle from the target specification.
- Production operations dashboard.

## Известные ограничения

- The plugin is still fresh-install oriented; production data migration compatibility is limited to the active migration path documented in current files.
- Russian Post shipment documents are intentionally prepared manually in the Russian Post account; WDC does not call Forms API and does not generate labels/F103.
- Russian Post shipment creation is manual from the WooCommerce order admin metabox; there is no automatic creation on checkout/order placement.
- Autosync is carrier-neutral in shape but has only the Russian Post domestic dispatcher in production code.
- Autosync uses selected order statuses and `wc_get_orders(limit=-1)`; high-volume production batching is not yet designed.
- Terminal statuses skip carrier API refresh and only run order status mapping against the saved shipment state.
- Actual-cost comparison depends on `backlog/search` returning total fields and on checkout calculation data having `api_base_price_rub`.
- Courier shipment creation depends on Russian Post address normalization; failed/stale normalization blocks create.
- Admin PВЗ selection for shipments uses the local Russian Post pickup database and does not recalculate checkout tariffs.
- Pickup map production implementation is Russian Post-specific, even though generic pickup domain/storage exists.
- International Russian Post remains quote-only.
- Runtime monitoring/log rotation and import retry/backoff are not production-complete.

## Технический долг

### Russian Post Pickup Import

- Verify automatic ZIP download through Russian Post APIs on production Linux/VDS.
- Compare direct cURL backend and WordPress HTTP API backend stability.
- Check timeout, SSL, Action Scheduler, background download and PHP ZipArchive behavior on LocalWP and production.
- Keep manual TXT/JSON payload import as the final LocalWP/Windows fallback.
- Add retry/backoff, CLI import mode or chunked streamed download if production testing shows the need.

### Shipments And Statuses

- Add pagination/batching strategy for autosync on large WooCommerce order volumes.
- Add status history/timeline if business users need more than the latest universal/carrier status.
- Keep document generation explicitly out of current scope or start a separate Russian Post documents stage.
- Improve multi-place UI beyond the current basic first-place/default distribution.
- Generalize shipment adapters and autosync dispatch once the next carrier starts.

### Checkout And Orders

- Implement admin recalculation/replacement workflow for existing orders.
- Finish browser-storage TTL and remaining checkout UX edge cases.
- Keep validating hidden WDC order calculation data against WooCommerce order item state before shipment creation.

### Operations

- Define production monitoring/status dashboard.
- Define log retention/rotation policy.
- Harden long background imports and diagnostics for production datasets.

### Documentation

- `docs/wdc-shipments-foundation.md` is historically useful but still describes the original manual stage where background polling was not included; current autosync/order mapping source is `docs/wdc-shipment-statuses.md`.
- `docs/wdc-russian-post-international.md` still carries an old documented version even though the runtime remains present; update it when international shipping is touched.
- `docs/wdc-current-code-map.md` should continue to receive small top notes when major modules change.
- `docs/walls-delivery-calc-tech-spec.md` is a target-state document, not implementation status; do not use it as the source of readiness percentages.

## Завершенные ветки / этапы, отраженные в develop

- Core platform, domain model, migrations, HPOS compatibility and admin menu baseline.
- Calendar and delivery-date foundation.
- FIAS/GAR locations, snapshots, aliases, display names and checkout location picker.
- Rule Engine foundation, admin builder, service-level rules and packaging tab.
- Delivery Services foundation.
- Russian Post international quote/fallback/country mapping baseline.
- Russian Post domestic unified service (`russian_post_domestic`) with pickup/courier split.
- Russian Post pickup points import, diagnostics, REST API and checkout map.
- Russian Post admin shipment-modal OPS/PVЗ selector.
- Russian Post domestic shipment creation, cancellation, manual tracking attachment and manual status refresh.
- Russian Post universal shipment statuses and fixed status mapping.
- Shipment status autosync.
- Universal shipment status -> WooCommerce order status mapping.
- Russian Post actual-cost lookup and checkout base-cost comparison.
- Courier Russian Post calculation postcode fill optimization.

## Следующие этапы

### 1. Admin Recalculation

- Рекомендуемая ветка: `feature/order-delivery-recalculation`.
- Что входит: ручной QA завершенного пересчета доставки в заказе, проверка реальных WooCommerce shipping item/totals/order note сценариев и доработка UX только по найденным edge cases.
- Почему следующий: базовый order support workflow реализован; перед расширением carrier coverage стоит пройти его на реальных заказах и HPOS screens.
- Обновить документы: `docs/wdc-checkout-integration.md`, `docs/wdc-current-code-map.md`, `docs/project-status.md`, `README.md`.

### 2. Russian Post Production Hardening

- Рекомендуемая ветка: `feature/russian-post-production-hardening`.
- Что входит: production checks for pickup import, autosync batching strategy, retry/backoff decisions, diagnostics and log/retention policy.
- Зависимости: current Russian Post domestic shipment/status runtime.
- Обновить документы: `docs/wdc-russian-post-domestic.md`, `docs/wdc-russian-post-pickup-points.md`, `docs/wdc-shipment-statuses.md`, `docs/project-status.md`.

### 3. Shipment Documents Decision

- Рекомендуемая ветка: `feature/russian-post-documents` only if WDC should own labels/forms/F103.
- Что входит: decide whether documents stay manual or become plugin-generated; if implemented, add Forms API clients, storage, UI and rotation.
- Зависимости: stable Russian Post shipment state and production credentials.
- Обновить документы: Russian Post shipment/status docs and `docs/project-status.md`.

### 4. CDEK Carrier Foundation

- Рекомендуемая ветка: `feature/cdek-carrier-foundation`.
- Что входит: CDEK adapter, settings, rates, pickup/courier baseline, smoke tests and first multicarrier pickup abstractions.
- Зависимости: stable checkout/order recalculation patterns.
- Обновить документы: create `docs/wdc-cdek.md`, update code map and project status.

### 5. DPD Carrier Foundation

- Рекомендуемая ветка: `feature/dpd-carrier-foundation`.
- Что входит: DPD adapter, settings, rates, pickup/courier baseline and smoke tests.
- Зависимости: multicarrier checkout patterns after CDEK.

### 6. Yandex Delivery Foundation

- Рекомендуемая ветка: `feature/yandex-delivery-foundation`.
- Что входит: Yandex pricing, geo, pickup/courier rates, settings and future offer confirmation baseline.
- Зависимости: stabilized multicarrier checkout.

### 7. Operations Stabilization

- Рекомендуемая ветка: `feature/runtime-operations-stabilization`.
- Что входит: production monitoring, dashboard, cleanup/rotation, long import hardening and support diagnostics.
- Зависимости: main runtime integrations.

## Документы, которые нужно обновлять после задач

- `docs/project-status.md` - после каждой завершенной задачи.
- `README.md` - если меняются версия, публичное поведение, runtime-требования или команды проверки.
- `docs/wdc-current-code-map.md` - если меняется структура модулей или добавляется крупный блок.
- Профильный `docs/wdc-*.md` по области задачи.
- `docs/walls-delivery-calc-tech-spec.md` - только если меняется целевой продуктовый или архитектурный контракт.
- `docs/wdc-migration-plan.md` - если меняется порядок этапов, риски или стратегия перехода.
