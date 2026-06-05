# Карта текущего кода

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
- order meta storage for shipment state, safe request/response snapshots, barcode/result ids and last safe error;
- `WDC -> Перевозчики` page for shared carrier credentials.

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
