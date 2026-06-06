# Карта текущего кода

## Shipment Statuses 0.37.0

- `src/Domain/Status/DeliveryStatus.php` defines the carrier-neutral shipment status model: `created_in_carrier`, `in_transit`, `ready_for_pickup`, `handed_to_courier`, `delivered`, `returning_to_sender`, `returned_to_sender`, `cancelled`, `rejected`, `unknown`, with Russian UI labels.
- `src/Carriers/RussianPost/Tracking/RussianPostTrackingApiClient.php` calls Russian Post Tracking API `getOperationHistory` over SOAP 1.2 with `wp_remote_post`. It uses only `russian_post_tracking_login` and `russian_post_tracking_password_encrypted` from the unified domestic service settings.
- `src/Carriers/RussianPost/Otpravka/RussianPostOtpravkaApiClient.php` also supports Russian Post backlog deletion through `DELETE /1.0/backlog` and manual shipment lookup through `GET /1.0/backlog/search?query={barcode}` plus fallback `GET /1.0/shipment/search?query={barcode}`.
- `src/Shipments/Application/ShipmentBacklogService.php` owns cancel/manual-attach rules. Cancel uses `backlog_order_id` and is allowed only for operation `28 / Присвоение идентификатора`; manual attach searches by barcode in backlog first, falls back to shipment search, saves `backlog_order_id` when returned, then attempts the first Tracking API refresh.
- `src/Shipments/RussianPost/RussianPostTrackingStatusMapper.php` contains the code-fixed mapping generated from `status pocha.xlsx`. Unknown operation/attribute pairs map to `unknown` / `не определён`.
- The 0.36.1 mapping correction maps selected pickup operations including `8:2`, `12:1..12:31`, and `42:1..42:30` to `ready_for_pickup`, and maps `8:15` plus `8:18` to `handed_to_courier`.
- The 0.36.2 mapper fallback treats empty, absent, `0`, and `-` attributes as compatible with `type:-` mappings when no exact `type:attr` key exists. This covers Russian Post operations `28:-` (`создан в ТК`) and `46:-` (`отменён`).
- `src/Shipments/Application/ShipmentStatusUpdateService.php` updates `_wdc_shipments` for the Russian Post domestic shipment and saves universal status fields plus raw carrier operation fields.
- `src/Shipments/Admin/OrderShipmentsMetabox.php` exposes AJAX action `wdc_update_shipment_status` on the existing `Обновить статус` button. `assets/admin/shipments-admin.js` updates the status block without reloading the order page, closes the create modal after success, shows a local 10-second toast, and starts the first status refresh automatically.

## Назначение

Этот документ является навигационной картой текущей кодовой базы.

Статус реализации, roadmap, технический долг и отслеживание готовности ведутся здесь:

- docs/project-status.md

Продуктовые требования описаны здесь:

- docs/walls-delivery-calc-tech-spec.md

Используйте эту карту, чтобы быстро найти основные участки кода для нужной функциональной области.

## Корневая структура

- `walls-delivery-calc.php` - entrypoint WordPress-плагина, который загружает `src/Core/bootstrap.php`.
- `src/` содержит текущий runtime плагина: платформу, домен, checkout, перевозчиков, локации, правила, ПВЗ, заказы и вспомогательные модули.
- `database/migrations/` содержит версионированные изменения схемы БД, управляемые инфраструктурным слоем.
- `assets/` содержит CSS/JS для админки и checkout, включая frontend карты ПВЗ.
- `tests/` содержит standalone smoke-тесты и fixtures.

## Core Platform

Расположение:
`src/Core`, `src/Admin`, `src/WooCommerce`

Ответственность:

- bootstrap плагина, autoloading, runtime environment и DI container;
- регистрация сервисов и подключение WordPress/WooCommerce hooks в `Plugin.php`;
- feature flags и проверки runtime-требований;
- меню WDC в админке, страница настроек и admin notices;
- декларация совместимости WooCommerce HPOS.

## Domain Layer

Расположение:
`src/Domain`

Ответственность:

- framework-independent value objects и entities для расчетов доставки;
- модели адреса, календаря, перевозчика, упаковки, ПВЗ, quote, shipment и статуса;
- общие primitives: money, date ranges и форматирование сроков доставки;
- контракты данных для checkout, перевозчиков, правил, ПВЗ и order metadata.

## Checkout

Расположение:
`src/Checkout`

Ответственность:

- checkout orchestration и runtime расчета quote;
- регистрация WooCommerce shipping method, mapping packages/rates, rendering, validation и сохранение order meta;
- выбор города/локации, нормализация адреса и подсказки адресов;
- кеширование quote, сортировка rates, fallback rates и сборка rates с примененными правилами;
- инструменты симуляции checkout для admin diagnostics.
- domestic Russian Post checkout method labels use service settings `pickup_method_title`/`courier_method_title`; visible domestic shipping item meta contains only delivery days, while hidden WDC order meta/calculation data stores service, tariff, delivery type and pickup point code/type/postcode/address.

## Calendar

Расположение:
`src/Calendar`

Ответственность:

- хранение календарных дней и доступ через repository;
- типы календарей магазина и перевозчиков;
- расчет даты доставки, форматирование, работа с timezone и генерация года;
- admin page календаря и scheduler hooks.

## Locations (FIAS/GAR)

Расположение:
`src/Locations`

Ответственность:

- хранение локальных локаций и регионов;
- import clients, managers, snapshots и incremental updates для FIAS/GAR;
- поиск локаций, aliases, форматирование display name и обработка раскладки клавиатуры;
- helpers для postcode и coordinate enrichment;
- admin tooling для импорта, cleanup, поиска и snapshots локаций.

## Rules

Расположение:
`src/Rules`

Ответственность:

- domain objects правил, conditions, actions, operators и evaluation context;
- condition evaluation, rule evaluation, запуск Rule Engine и simulation;
- persistence правил через repository;
- admin rule builder, UI schema, форматирование формул и audit output.

## Carriers

Расположение:
`src/Carriers`

Ответственность:

- carrier adapter contract и registry;
- runtime context, передаваемый carrier adapters;
- adapters Почты России для внутренней и международной доставки;
- API clients, settings, country mapping, tariff variants, courier probing и Otpravka client foundation Почты России;
- admin page стран Почты России.

## Shipments

Расположение:
`src/Shipments`, `assets/admin/shipments-admin.*`

Ответственность:

- carrier-neutral contract for shipment creation adapters;
- manual WooCommerce order admin metabox `Отправления`;
- safe draft creation from HPOS-compatible WooCommerce order APIs and saved WDC order meta;
- Russian Post Otpravka `PUT /2.0/user/backlog` payload building and response normalization;
- admin-only Russian Post OPS/PVZ selector inside the shipment modal; it updates the shipment draft and preview without saving WooCommerce order meta;
- order meta storage for shipment state, safe request/response snapshots, barcode, hidden technical `backlog_order_id`, and last safe error;
- Russian Post domestic Tariff API endpoint/token, Otpravka credentials, Tracking placeholders and postoffice acceptance indices are edited in `WDC -> Службы доставки -> Почта России по РФ -> API / Credentials`.
- `default_from_postcode` is edited beside postoffice acceptance indices but remains the tariff fallback origin setting; pickup codes are not written to `shipping_address_2`.

## Pickup Points

Расположение:
`src/Pickup`, `src/Domain/Pickup`

Ответственность:

- domain model ПВЗ, storage, location resolution и rendering карточки;
- import ПВЗ Почты России, import state, diagnostics, normalization, type settings и work-time formatting;
- поиск адресов для ПВЗ;
- REST controllers для directory/search/detail ПВЗ и checkout pickup selection state;
- `RussianPostPickupPointRepository::search_admin_pickup_rows()` searches local Russian Post pickup rows by postcode, city and address for the shipment modal;
- admin summary page для ПВЗ.

## Orders

Расположение:
`src/Orders`

Ответственность:

- metabox доставки в WooCommerce order admin;
- отображение сохраненных данных расчета доставки в админке заказа;
- order-facing точка доступа к delivery и pickup metadata.

## Delivery Services

Расположение:
`src/DeliveryServices`

Ответственность:

- delivery service definitions, registry, manager и repositories;
- настройки сервисов, стран, комментариев и packaging-related configuration;
- admin page сервисов доставки;
- данные сервисов, используемые checkout и расчетом carrier rates.
- Historical migration note: unified Russian Post domestic service `russian_post_domestic`; old `russian_post_domestic_pickup`/`russian_post_domestic_courier` rows are physically removed by migration `0026`, and no backward compatibility layer for those keys remains.
- domestic Russian Post availability is edited on `Основные`; the separate availability tab is no longer part of the service edit UI.

## Packaging

Расположение:
`src/Packaging`

Ответственность:

- расчет упаковочного веса;
- objects результата применения упаковки;
- общие packaging data для delivery service и checkout calculations.

## Infrastructure

Расположение:
`src/Infrastructure`

Ответственность:

- settings repository для plugin options и module configuration;
- logging и redaction чувствительных данных;
- encryption для secret settings;
- wrapper Action Scheduler / WP Cron queue;
- database migration manager.

## Database Migrations

Расположение:
`database/migrations`

Ответственность:

- версионированные изменения схемы для calendar, locations, aliases, GAR imports, rules, pickup points, delivery services и carrier support tables;
- migration files, загружаемые через `src/Infrastructure/Database/MigrationManager.php`;
- история схемы plugin-managed database tables.
- migration `0026_unify_russian_post_domestic_service.php` copies the previous Russian Post domestic settings/tariffs/countries/credentials into `service_key=russian_post_domestic`, then physically deletes old pickup/courier service rows, related service settings/countries, and service-rule bindings.

## Assets

Расположение:
`assets`

Ответственность:

- admin CSS/JS для calendar, locations, rules, checkout simulation и Russian Post pickup import;
- frontend checkout CSS/JS для city selection, address suggestions, rate sorting, courier summaries, tariff selection и pickup UI;
- скрипты pickup map, modal, API wrapper, checkout integration, map providers и стили карты;
- vendored Leaflet assets в `assets/vendor/leaflet`.

## Tests

Расположение:
`tests`

Ответственность:

- standalone smoke-тесты для domain, calendar, FIAS/GAR, locations, address suggestions, checkout, rules, carriers, delivery services, pickup, orders, packaging и runtime checks;
- demo и fixture data в `tests/fixtures`;
- прямые PHP entrypoints, например `tests/domain/run-domain-smoke.php`, `tests/checkout/run-checkout-smoke.php` и `tests/runtime/run-no-legacy-smoke.php`.
