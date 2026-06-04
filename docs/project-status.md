# Project Status

## Общий статус

- Версия / baseline проекта: `0.33.8`, определено по `walls-delivery-calc.php`.
- Базовая ветка: `develop`.
- Последнее обновление статуса: 2026-06-04.
- Общий процент готовности: примерно 66%.
- Следующий рекомендуемый этап: Russian Post shipment statuses/documents/cancellation.

## Краткое резюме

Проект уже имеет рабочую платформу расчета доставки для WooCommerce: core bootstrap, DI container, миграции, checkout runtime, календарь доставки, Rule Engine, локальную базу ФИАС/ГАР, DaData-подсказки и обогащение, Почту России для внутренних/международных расчетов и полноценный слой ПВЗ Почты России с импортом, REST API и checkout map.

При этом проект еще не закрывает полный carrier lifecycle из ТЗ: ручное создание отправлений Почты России реализовано первым foundation-этапом, но документы ТК, статусы, трекинг, автоматическое изменение WooCommerce-статусов и остальные ТК пока не реализованы.

## Готовность по блокам

| Блок | Статус | Готовность | Комментарий |
| ---- | ------ | ---------: | ----------- |
| Core Platform | done | 100% | Bootstrap, autoloader, container, settings, logger, migrations, HPOS declaration. |
| Domain Model | done | 90% | Базовые value objects для адресов, календаря, carrier, package, pickup, quote, shipment и status. |
| Delivery Calendar | done | 90% | Таблица календаря, генерация года, расчет плановой даты, admin UI. |
| FIAS/GAR Places | done | 85% | Locations, regions, aliases, GAR import, snapshots, incremental update, checkout lookup. |
| DaData | done | 80% | Address suggestions, token pool, postcode/coordinate enrichment, pickup address search. |
| Rule Engine | done | 85% | Conditions, groups, audit trail, price/days mutations, admin builder, simulation. |
| Checkout Rates | done | 80% | WooCommerce shipping method, orchestrator, cache, sorting, rules, order meta persistence. |
| Checkout UX | partial | 70% | City picker, sorting, tariff selector, courier address summary, pickup map; browser storage TTL and full UX stabilization remain. |
| Russian Post Domestic | done | 80% | Domestic runtime, tariff variants, API client, pickup/courier services. |
| Russian Post International | done | 75% | International runtime, country mapping, API/fallback pricing. |
| Russian Post Pickup Points | done | 85% | Import pipeline, compact table, REST API, checkout map, order persistence. |
| Multicarrier Pickup Layer | partial | 35% | Generic domain/storage exists, but production checkout map is Russian Post-specific. |
| Order Admin Recalculation | partial | 30% | Order delivery metabox exists; full recalculation/replacement workflow is missing. |
| Shipment Domain | partial | 55% | Domain objects exist and are used by the manual shipment creation runtime. |
| Shipment Runtime | partial | 42% | Manual WooCommerce order admin flow creates Russian Post Otpravka backlog shipments with server-side payload preview, tariff select, postoffice-code select and visible AJAX result diagnostics; statuses/documents/cancellation/admin pickup map are pending. |
| Tracking / Documents / Status Sync | not-started | 0% | No tracking polling, labels, acts, documents, or status sync runtime. |
| WooCommerce Status Mapping | not-started | 0% | Domain baseline exists, but no automatic WooCommerce order status changes. |
| CDEK | planned | 0% | Planned carrier stage; no adapter found in code. |
| DPD | planned | 0% | Planned carrier stage; no adapter found in code. |
| Yandex Delivery | planned | 0% | Planned carrier stage; no adapter found in code. |
| PEK | planned | 0% | Planned carrier stage; no adapter found in code. |
| Energia | planned | 0% | Planned carrier stage; no adapter found in code. |
| Aerogruz | planned | 0% | Planned carrier stage; no adapter found in code. |
| Jet | planned | 0% | Planned carrier stage; no adapter found in code. |
| Manual / Fixed Pseudo-carriers | partial | 40% | Delivery services layer exists; full manual/fixed pseudo-carrier lifecycle is not complete. |
| Logs / Operations | partial | 45% | Logger and diagnostics exist; production monitoring/dashboard and full rotation remain. |
| Documentation | needs-review | 70% | Baseline is consolidated here; some historical docs remain intentionally archived. |

## Реализовано

### Core Platform

- `walls-delivery-calc.php` is a minimal plugin entrypoint with version `0.33.8`.
- `src/Core/bootstrap.php` wires the autoloader and plugin runtime.
- `src/Core/Plugin.php` registers services and hooks through a DI container.
- `src/Infrastructure/Settings`, `src/Infrastructure/Logging`, `src/Infrastructure/Security`, `src/Infrastructure/Queue`, and `src/Infrastructure/Database` provide settings, logging, encryption, background scheduling, and migrations.
- `src/WooCommerce/HPOSCompatibility.php` declares HPOS compatibility.

### Domain Model

- `src/Domain` contains framework-independent value objects and entities for address normalization, calendar dates, carriers, money, packages, pickup selection, quotes, shipments, and delivery statuses.
- Shipment/status domain classes are present. The first runtime uses shipment create requests/results for manual Russian Post shipment creation from the WooCommerce order admin.

### Delivery Calendar

- `src/Calendar` includes calendar day storage, year generation, shop/carrier calendar types, timezone handling, delivery date calculation, formatting, scheduler, and admin UI.
- Migration `0001_create_calendar_days_table.php` creates calendar storage.

### FIAS/GAR

- `src/Locations` includes FIAS credentials/endpoints/http/rate limiting, GAR changes client/sync, import services, snapshots, aliases, display-name formatting, search, and repositories.
- Migrations `0002`, `0003`, `0007`, `0008`, `0009`, `0010`, `0011`, `0023`, `0024`, and `0025` support locations, aliases, GAR changes/imports, and location indexes/fields.
- Checkout location AJAX/search integrates local places into checkout.

### DaData

- `src/Checkout/AddressSuggestions` implements address suggestions, token pool, DaData client, fallback client, normalization, and AJAX endpoints.
- `src/Locations/Postcodes` and `src/Locations/Coordinates` support postcode and coordinate enrichment.
- Pickup address search can use DaData while keeping postcode-only fallback.

### Rule Engine

- `src/Rules` includes domain objects, condition/action value objects, condition evaluator, rule evaluator, rule engine, simulator, repository, and admin UI.
- The engine supports price changes, promo/crossed prices, delivery-days changes, disabling rates, stop processing, condition groups, and audit trail.

### Checkout Rates

- `src/Checkout/Runtime` includes checkout orchestration, carrier execution guard, fallback rate factory, rule-applied rate builder, calculation result, and checkout logging.
- `src/Checkout/WooCommerce` registers the WooCommerce shipping method, maps packages/rates, persists order meta, renders rates/sorting/delivery type/address/pickup UI, and validates checkout.
- `src/Checkout/Cache` caches delivery quotes.
- `src/Checkout/Sorting` sorts rates.

### Russian Post

- `src/Carriers/Runtime/RussianPostDomesticCarrier.php` and `src/Carriers/Runtime/RussianPostInternationalCarrier.php` are registered in `CarrierRegistry`.
- `src/Carriers/RussianPost` includes tariff API clients/settings, country mapping/directory, domestic tariff variants, courier tariff probing, and Otpravka credentials/client foundation.
- `src/DeliveryServices` provides service definitions/settings/countries/admin management used by Russian Post services.

### Russian Post Pickup Points

- `src/Pickup/RussianPost` includes importer, import state, diagnostics, passport point normalizer, point repository, location resolver, type settings, and work-time formatter.
- `src/Pickup/Rest` exposes pickup point directory and checkout selection state endpoints.
- `assets/frontend/pickup-map` contains the checkout map, modal, API wrapper, checkout integration, and Leaflet/Yandex provider adapters.
- Migration `0021_create_russian_post_pickup_points_table.php` creates the carrier-specific Russian Post pickup table; `0022` links points to local locations.
- Selected pickup data is persisted in checkout session, order meta, shipping item meta, order details, emails, and admin metabox.

## Частично реализовано

### Checkout UX

- Уже есть: city picker, country-aware location lookup, checkout sorting, nested domestic tariff selector, pickup map, courier address summary and validation, DaData suggestions.
- Не хватает: полного browser storage TTL по ТЗ, финальной стабилизации всех edge cases, полного multicarrier pickup UX.
- Следующий документ/этап: `docs/wdc-checkout-integration.md`, этап Admin Recalculation и будущие carrier stages.

### Order Admin Recalculation

- Уже есть: `src/Orders/Admin/OrderDeliveryMetabox.php` и сохранение расчетных данных заказа.
- Не хватает: полноценного пересчета доставки менеджером, замены shipping item, системного order note по формату ТЗ.
- Следующий документ/этап: Admin Recalculation.

### Shipment Domain

- Уже есть: `src/Domain/Shipment/Shipment.php`, `ShipmentCreateRequest.php`, `ShipmentCreateResult.php`.
- Не хватает: shipment runtime services, carrier shipment API adapters, admin form/action, idempotency, order meta/state transitions.
- Следующий документ/этап: Russian Post Shipments Foundation.

### Delivery Statuses Domain

- Уже есть: `src/Domain/Status/DeliveryStatus.php`, `StatusEvent.php`, `StatusMapping.php`.
- Не хватает: polling, carrier status translation, persistence, documents/tracking integration, WooCommerce status updates.
- Следующий документ/этап: Tracking, Documents And Status Sync.

### Pickup Layer

- Уже есть: generic pickup domain/storage baseline plus production Russian Post pickup implementation.
- Не хватает: carrier-neutral map/source orchestration for CDEK, DPD, Yandex and other carriers.
- Следующий документ/этап: future carrier foundations.

### Documentation

- Уже есть: detailed documents for core platform, domain model, checkout, calendar, FIAS/GAR, DaData, rules, Russian Post, pickup points, and development workflow.
- Не хватает: live project status maintenance after every task and planned carrier-specific documents.
- Следующий документ/этап: keep this `docs/project-status.md` updated after each task.

## Не реализовано

### Carrier Adapters

- CDEK.
- DPD.
- Yandex.Доставка.
- PEK.
- Energia.
- Aerogruz.
- Jet.

### Shipment Runtime

- Статусы отправлений.
- Документы/ярлыки/партии/Ф103.
- Отмена отправлений.
- Автосинхронизация.
- Полноценный выбор ПВЗ на карте в админской модалке.

### Carrier Documents

- Накладные.
- Ярлыки.
- Акты.
- Хранение и ротация документов ТК.

### Tracking / Status Polling

- Получение tracking number.
- Фоновая синхронизация статусов.
- История статусов доставки.

### WooCommerce Status Mapping

- Mapping статусов доставки в WooCommerce statuses.
- Автоматическое изменение статуса заказа по статусу доставки.

### Operations

- Production monitoring/status dashboard.
- Полная ротация логов и документов.
- Production hardening для долгих фоновых импортов.

### Manual / Fixed Pseudo-carriers

- Delivery services foundation уже есть, но полный слой фиксированных/ручных pseudo-carriers по ТЗ не завершен.

## Текущие технические остатки

### Russian Post pickup import

- Проверить и доработать автоматическое скачивание ZIP через API "Отправка" на боевом Linux/VDS сервере.
- Сравнить стабильность direct cURL backend и WordPress HTTP API backend.
- Проверить timeout behavior, SSL behavior, Action Scheduler interaction, background download stability, PHP ZipArchive/zip extension behavior on LocalWP и production Linux/VDS.
- Сохранить manual TXT/JSON payload import на LocalWP/Windows как final fallback path.
- При необходимости добавить retry/backoff, CLI import mode, chunked streamed download.

### Russian Post shipments

- Админская карта выбора ПВЗ пока не подключена; в модалке показывается явное inline-сообщение, а код ПВЗ можно скорректировать вручную.
- Распределение товаров по грузоместам в UI пока базовое: товары заказа попадают в первое место, детальное распределение остается отдельным этапом.
- Domestic shipment payloads now use `mail-direct=643`.
- Обычный pickup/ОПС для Почты России создается через `address-type-to=DEMAND`, `index-to`, `region-to`, `place-to` без `ecom-data`.
- ECOM-сценарий включается настройкой тарифа `is_ecom` во вкладке `Тарифы`; object `54020` не включает `ecom-data` сам по себе.
- Индексы места приема настраиваются на `WDC -> Перевозчики -> Почта России`, default `630005`, и выбираются в модалке как `postoffice-code`.
- После AJAX create модалка показывает barcode/result-id или нормализованные ошибки API Почты; страница не перезагружается автоматически.

## Несоответствия документации и кода

### `docs/wdc-current-code-map.md`

- Этот документ является навигационной картой текущей кодовой базы.

### `docs/walls-delivery-calc-tech-spec.md`

- Документ является целевым ТЗ, а не статусом готовности.
- Фактический статус реализации ведется в `docs/project-status.md`.

## Roadmap

### 1. Tracking, Documents And Status Sync

- Рекомендуемая ветка: `feature/russian-post-status-documents`.
- Что входит: tracking number, status polling, labels/docs, acts, status mapping, background sync.
- Зависимости: Russian Post Shipments Foundation.
- Обновить документы: `docs/walls-delivery-calc-tech-spec.md`, Russian Post docs, `docs/wdc-migration-plan.md`, `docs/project-status.md`, `README.md`.

### 3. Admin Recalculation

- Рекомендуемая ветка: `feature/order-delivery-recalculation`.
- Что входит: пересчет доставки в заказе, замена shipping item, системные order notes, admin validation.
- Зависимости: стабильный checkout/order meta baseline.
- Обновить документы: `docs/wdc-checkout-integration.md`, `docs/wdc-current-code-map.md`, `docs/project-status.md`.

### 4. CDEK Carrier Foundation

- Рекомендуемая ветка: `feature/cdek-carrier-foundation`.
- Что входит: CDEK adapter, settings, rates, pickup/courier baseline, smoke tests.
- Зависимости: stabilized carrier/shipment patterns after Russian Post.
- Обновить документы: `docs/wdc-cdek.md` planned, `docs/walls-delivery-calc-tech-spec.md`, `docs/wdc-current-code-map.md`, `docs/project-status.md`.

### 5. DPD Carrier Foundation

- Рекомендуемая ветка: `feature/dpd-carrier-foundation`.
- Что входит: DPD adapter, settings, rates, pickup/courier baseline, smoke tests.
- Зависимости: multicarrier checkout patterns after CDEK.
- Обновить документы: `docs/wdc-dpd.md` planned, `docs/walls-delivery-calc-tech-spec.md`, `docs/wdc-current-code-map.md`, `docs/project-status.md`.

### 6. Yandex Delivery Foundation

- Рекомендуемая ветка: `feature/yandex-delivery-foundation`.
- Что входит: Yandex pricing, geo, pickup/courier rates, settings, future offer confirmation baseline.
- Зависимости: stabilized multicarrier checkout.
- Обновить документы: `docs/wdc-yandex-delivery.md` planned, `docs/walls-delivery-calc-tech-spec.md`, `docs/wdc-current-code-map.md`, `docs/project-status.md`.

### 7. Operations Stabilization

- Рекомендуемая ветка: `feature/runtime-operations-stabilization`.
- Что входит: production checks, log/document cleanup, import hardening, monitoring/status dashboard.
- Зависимости: main runtime integrations.
- Обновить документы: `docs/wdc-runtime-stabilization.md`, `docs/project-status.md`, `README.md`.

## Планируемые профильные carrier-документы

- `docs/wdc-cdek.md` - planned.
- `docs/wdc-dpd.md` - planned.
- `docs/wdc-yandex-delivery.md` - planned.
- Остальные carrier docs - planned по мере начала соответствующего этапа.

## Документы, которые нужно обновлять после задач

- `docs/project-status.md` - после каждой завершенной задачи.
- `docs/walls-delivery-calc-tech-spec.md` - если меняется целевой продуктовый или архитектурный контракт.
- Профильный `docs/wdc-*.md` по области задачи.
- `docs/wdc-current-code-map.md` - если меняется структура модулей.
- `docs/wdc-migration-plan.md` - если меняется порядок этапов, риски или стратегия перехода.
- `README.md` - если меняются runtime-требования, публичное поведение или команды проверки.
- `docs/todo.md` - только как указатель на этот документ, не как самостоятельный список задач.
