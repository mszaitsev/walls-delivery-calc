# Карта текущего кода

## CDEK Pickup QA Fix 0.45.1

- `src/Checkout/WooCommerce/CheckoutValidation.php` restores CDEK pickup selections as CDEK data and no longer queries the Russian Post pickup repository for CDEK point codes such as `KEM7`.
- `src/Pickup/Cdek/CdekDeliveryPointService.php` normalizes CDEK `code` into `point_code`/`cdek_code`, keeps postcode separately, supports `PVZ` and `POSTAMAT`, saves description and sets `Срок хранения 3 дня` for CDEK postamats.
- `src/Pickup/Presentation/PickupPointCardRenderer.php` is carrier-aware for pickup cards: CDEK `PVZ` renders `Пункт выдачи СДЭК`, CDEK `POSTAMAT` renders `Постамат СДЭК`, and CDEK postamats show red bold storage notice.
- `assets/frontend/pickup-map/wdc-pickup-map.js`, map providers and CSS separate CDEK `POSTAMAT` from Russian Post `APS`; Russian Post keeps `Почтомат`, CDEK uses `Постамат` with a separate marker color.
- `assets/admin/order-delivery-recalculation.js` keeps CDEK pickup picker state with code/type/description/storage notice and shows CDEK code instead of postcode in the admin picker.
- `tests/cdek/run-cdek-pickup-points-smoke.php` and checkout/pickup smoke tests cover CDEK validation restore, CDEK code vs postcode, POSTAMAT title/storage notice, description persistence and Russian Post regression boundaries.
- Technical debt: permanent FIAS/GAR -> CDEK `city_code` mapping remains deferred to a later CDEK integration stage.

## CDEK Tariff Calculation 0.44.0

- `src/Carriers/Runtime/CdekCarrier.php` is the runtime adapter for service/carrier key `cdek`. It builds `POST /v2/calculator/tarifflist` payloads, maps tariff candidates to `DeliveryRate`, classifies CDEK `delivery_mode`, marks pickup rates as requiring a pickup point, and stores safe API/location/package meta.
- `src/Checkout/Runtime/CheckoutOrchestrator.php` caches successful quotes only when they contain rates, so CDEK `api_error`/403 and zero-rate tarifflist results do not become stable cached empty delivery options; `DeliveryQuoteCacheManager` clears runtime quote cache plus CDEK city/deliverypoints transients without clearing OAuth tokens.
- `src/Carriers/Cdek/CdekLocationResolver.php` resolves destination CDEK city code through `/v2/location/cities` and caches confident matches in transients.
- `src/Carriers/Cdek/Api/CdekApiClient.php` now supports authorized JSON runtime requests in addition to OAuth connection checks, including `GET /v2/deliverypoints`.
- `src/Pickup/Cdek/CdekDeliveryPointService.php` loads CDEK pickup points for a CDEK city code, normalizes them for the shared picker and caches the result by environment/city/type.
- `src/Checkout/Runtime/CheckoutOrchestrator.php` runs `cdek` services separately for pickup and courier when the common delivery service is active.
- `src/Checkout/WooCommerce/NewShippingMethod.php` and `CheckoutRateRenderer.php` reuse the existing grouped tariff selector for generic tariff candidates, including CDEK.
- `src/Orders/Application/OrderDeliveryRecalculationService.php` groups CDEK tariff candidates for admin recalculation preview without requiring a CDEK pickup point yet.
- `tests/cdek/run-cdek-tariff-calculation-smoke.php` covers fake OAuth, location resolution, tarifflist payload, response mapping, delivery type classification, runtime visibility, rule engine, calculation data, admin preview and secret redaction.

## Order Delivery Recalculation 0.42.0

The order-admin delivery recalculation stage is complete and HPOS-audited. The flow uses WooCommerce order/shipping-item CRUD (`wc_get_order()`, `$order->get_meta()`, `$order->update_meta_data()`, `$order->calculate_totals(false)`, `$order->add_order_note()`, `WC_Order_Item_Shipping`) and does not use direct order `postmeta`/`wp_posts` access or `WP_Query` over `shop_order`.

- `src/Orders/Application/OrderQuoteRequestMapper.php` builds an admin recalculation `QuoteRequest` from a WooCommerce order: order items, product weights/dimensions, shipping country/city/postcode/address, payment method and existing WDC location/calculation meta fallbacks. It also accepts a selected location payload as destination override.
- `src/Orders/Application/OrderDeliveryRecalculationService.php` owns preview recalculation. Preview is not blocked by shipping item count or shipment state; it calls `CheckoutOrchestrator`, normalizes available rates for admin rendering, returns full rate/tariff meta needed for later save, and returns the destination label used for the preview without mutating the order.
- `src/Orders/Application/OrderDeliveryAddressNormalizationService.php` is the admin thin wrapper for delivery-address normalization/geocoding and courier address suggestions. Courier address suggestions reuse the shared checkout `AddressSuggestionService`, `AddressSuggestionNormalizer` and `AddressLineParser`, so street, house, flat/room/premise lookup and house-level finalization match checkout.
- `src/Orders/Application/OrderDeliveryReplacementService.php` creates/replaces the single WooCommerce shipping item, keeps visible shipping item meta compact as only `Срок доставки` (`не указан` when absent), rewrites hidden WDC delivery meta, recalculates totals, and writes shipping city/state through checkout-compatible selected-location payload/formatter values instead of storing the full `display_name` as city.
- `src/Orders/Admin/OrderDeliveryMetabox.php` renders the modal, current location/pickup/shipping-address JSON payloads, and a save warning area. Current location labels prefer `display_name` and avoid `region + display_name` duplication.
- `assets/admin/order-delivery-recalculation.js` owns modal state. Courier address suggestions run automatically without the old `Проверить адрес` button; `Использовать этот адрес` stays as explicit manual fallback. The save button remains enabled for valid courier payloads while a non-blocking warning is shown if the courier address settlement cannot be confidently matched to the calculated settlement.
- `src/Orders/Application/OrderDeliveryReplacementService.php` owns save/replacement. It blocks saves with multiple shipping items or registered shipment markers, creates a missing shipping item or replaces the single existing one, rewrites WDC platform/calculation/pickup meta with checkout-like package/API/rules/result structure, updates shipping address, recalculates totals, adds a private order note, and saves the order. Pickup save requires a pickup point and writes the pickup point address to WooCommerce shipping address; courier save requires normalized address.
- `src/Orders/Admin/OrderDeliveryRecalculationAdminController.php` registers AJAX actions for preview, settlement search, pickup point search, courier address suggestions, address normalization, address geocoding and save. It keeps the controller thin: nonce/capability checks, `wc_get_order()` loading and request parsing live here, while recalculation/replacement/address work is delegated to application services. Preview/pickup search remain available for calculation, while save blockers are enforced by the replacement service.
- `src/Orders/Admin/OrderDeliveryRateRenderer.php` renders admin pickup/courier rate groups, prices, crossed prices, comments and Russian Post domestic tariff rows. It intentionally leaves all radio buttons unchecked, embeds rate/tariff payload data for the modal state, and renders pickup controls only for rates that require pickup points.
- `src/Orders/Admin/OrderDeliveryMetabox.php` shows `Пересчитать доставку` in `Калькулятор доставок`, renders the hidden modal markup plus current-settlement selector shell, current pickup/current shipping address JSON payloads, and the save button that starts disabled until JS state is valid.
- `assets/admin/order-delivery-recalculation.js` and `.css` provide the order-admin modal interaction. The JS opens/closes the modal, avoids duplicate preview requests, searches settlements, stores selected location/rate/pickup point/normalized courier address in modal memory, preselects the current order pickup point when location is unchanged, loads preview HTML, opens the map-backed pickup picker through existing pickup map/provider assets, syncs active marker/list state, geocodes manual pickup-map address search through the admin DaData endpoint, runs courier street/house/flat suggestions through the shared checkout suggestion stack, enables pickup save for rate+pickup and courier save only after a normalized suggestion/house-finalized address or explicit manual fallback, posts save payload, and reloads the page after success.
- `tests/orders/run-order-delivery-recalculation-smoke.php` covers modal metabox/current pickup/current shipping address markup, order-to-quote mapping, location search, location override preview, all-rates preview, Russian Post pickup/courier groups, pickup/geocode endpoint payload/security, save blockers, shipping item create/replace, pickup save without normalized address, courier save requiring normalized address, WDC meta rewrite with package/API/rules/result, totals, private notes, JS save/prefill/map-sync/geocode hooks, viewport scroll CSS, and no mutation during preview/pickup search.

## Project Status Refresh 0.40.0

- `docs/project-status.md` is the current source for readiness percentages, completed stages, known limitations, technical debt and roadmap after the 0.39.x Russian Post shipment/status work.
- The codebase currently includes unified `russian_post_domestic`, Russian Post shipment creation/cancellation/manual tracking attach, manual and automatic status refresh, carrier-neutral order status mapping, actual-cost comparison with checkout calculation data, courier calculation postcode fill, and admin pickup-point selection on the shipment map.
- Future carrier adapters, Russian Post plugin-generated documents and production operations hardening remain outside the completed scope.

## Shipment Statuses 0.38.0

- `src/Domain/Status/DeliveryStatus.php` defines the carrier-neutral shipment status model: `created_in_carrier`, `in_transit`, `ready_for_pickup`, `handed_to_courier`, `delivered`, `returning_to_sender`, `returned_to_sender`, `cancelled`, `rejected`, `unknown`, with Russian UI labels.
- `src/Carriers/RussianPost/Tracking/RussianPostTrackingApiClient.php` calls Russian Post Tracking API `getOperationHistory` over SOAP 1.2 with `wp_remote_post`. It uses only `russian_post_tracking_login` and `russian_post_tracking_password_encrypted` from the unified domestic service settings.
- `src/Carriers/RussianPost/Otpravka/RussianPostOtpravkaApiClient.php` also supports Russian Post backlog deletion through `DELETE /1.0/backlog` and manual shipment lookup through `GET /1.0/backlog/search?query={barcode}` plus fallback `GET /1.0/shipment/search?query={barcode}`.
- `src/Carriers/Cdek` contains the CDEK foundation and tariff calculation support: settings, separate encrypted test/production credentials, active-environment API base URL selection, OAuth token service/cache, API response/exception objects, WP HTTP adapter, destination city resolver and API client methods for `tarifflist`, locations and delivery points. Orders, statuses, print forms and webhooks are still not implemented.
- `src/Shipments/Application/ShipmentBacklogService.php` owns cancel/manual-attach rules. Cancel uses `backlog_order_id` and is allowed only for operation `28 / Присвоение идентификатора`; manual attach searches by barcode in backlog first, falls back to shipment search, saves `backlog_order_id` when returned, then attempts the first Tracking API refresh.
- `src/Shipments/RussianPost/RussianPostTrackingStatusMapper.php` contains the code-fixed mapping generated from `status pocha.xlsx`. Unknown operation/attribute pairs map to `unknown` / `не определён`.
- The 0.36.1 mapping correction maps selected pickup operations including `8:2`, `12:1..12:31`, and `42:1..42:30` to `ready_for_pickup`, and maps `8:15` plus `8:18` to `handed_to_courier`.
- The 0.36.2 mapper fallback treats empty, absent, `0`, and `-` attributes as compatible with `type:-` mappings when no exact `type:attr` key exists. This covers Russian Post operations `28:-` (`создан в ТК`) and `46:-` (`отменён`).
- `src/Shipments/Application/ShipmentStatusUpdateService.php` updates `_wdc_shipments` for the Russian Post domestic shipment, saves universal status fields plus raw carrier operation fields, and then invokes order status mapping through `ShipmentOrderStatusMappingService`.
- `src/Shipments/Application/ShipmentOrderStatusMappingService.php` reads `shipment_status_order_status_mapping_enabled` and `shipment_status_order_status_mapping`, validates target statuses against `wc_get_order_statuses()`, updates WooCommerce orders with `update_status()`, and adds a private WDC order note on successful automatic changes.
- `src/Shipments/Application/ShipmentStatusAutoSyncService.php` scans WooCommerce orders by selected order statuses, reads `_wdc_shipments`, skips terminal universal statuses and missing tracking numbers, collects diagnostics including order status mapping counters, and dispatches by `carrier_key`.
- `src/Shipments/Application/ShipmentStatusAutoSyncCron.php` registers WP Cron hook `wdc_shipment_status_autosync`, schedule `wdc_every_6_hours`, and keeps the event scheduled even when autosync is disabled.
- `src/Shipments/Admin/ShipmentStatusesAdminPage.php` renders `WDC -> Статусы` with main autosync settings, universal shipment status to WooCommerce order status mapping, diagnostics, and the manual run action.
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
- checkout method labels can use service settings such as `pickup_method_title`/`courier_method_title`; visible WooCommerce shipping item meta is carrier-neutral and contains only `Срок доставки`, while hidden WDC order meta/calculation data stores service, tariff, delivery type, pickup point data, API, rules and package details.

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

- Russian Post courier postcode fill lives in `src/Locations/Postcodes/RussianPostCourierCalcPostcodeFillStateService.php`, uses courier technical marker `999999999` for retry-later technical failures, retries technical probe errors up to 5 attempts, and queues marker rows before cities and other settlements through `LocationRepository::next_russianpost_courier_calc_postcode_location()`.

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
- universal shipment status autosync service, cron scheduler, status settings admin page, order status mapping service, manual run, diagnostics, shared lock, and `carrier_key -> updater` dispatch;
- manual WooCommerce order admin metabox `Отправления`;
- safe draft creation from HPOS-compatible WooCommerce order APIs and saved WDC order meta;
- Russian Post Otpravka `PUT /2.0/user/backlog` payload building and response normalization;
- admin-only Russian Post OPS/PVZ selector inside the shipment modal; it updates the shipment draft and preview without saving WooCommerce order meta;
- order meta storage for shipment state, safe request/response snapshots, barcode, hidden technical `backlog_order_id`, and last safe error;
- Russian Post domestic Tariff API endpoint/token, Otpravka credentials, Tracking placeholders and postoffice acceptance indices are edited in `WDC -> Службы доставки -> Почта России по РФ -> Данные для входа`.
- `default_from_postcode` is edited beside postoffice acceptance indices but remains the tariff fallback origin setting; pickup codes are not written to `shipping_address_2`.

## Pickup Points

Расположение:
`src/Pickup`, `src/Domain/Pickup`

Ответственность:

- domain model ПВЗ, storage, location resolution и carrier-neutral rendering карточки;
- import ПВЗ Почты России, import state, diagnostics, normalization, type settings и work-time formatting;
- поиск адресов для ПВЗ;
- REST controllers для directory/search/detail ПВЗ и checkout pickup selection state;
- `RussianPostPickupPointRepository::search_admin_pickup_rows()` searches local Russian Post pickup rows by postcode, city and address for the shipment modal;
- `PickupPointPresentationResolver` centralizes pickup card presentation metadata for built-in Russian Post/CDEK and generic/custom pickup fallback (`card_title`, `point_type_label`, marker type, code/postcode display flags and storage notice);
- normalized pickup payloads carry `carrier_key`, `service_key`, `pickup_family={carrier_key}:pickup`, `point_code`, `point_type`, `point_type_label`, `point_title`, address/postcode/city/region, work time, description, storage notice, coordinates and snapshot data;
- checkout selected pickup state is bucketed by `pickup_family` in `CheckoutSessionManager` under canonical `wdc_platform_pickup_selections`; legacy singleton keys are derived mirrors/migration fallback only when the dictionary is empty, while validation, order meta persistence and localized checkout restore read the active family bucket and compare stable destination identity before restoring a saved point;
- `PickupMapCheckout` localizes both `selectedPickupPoints` and `pickupSelections` dictionaries plus `activePickupFamily`, while `CheckoutPickupPointRestController` returns `pickup_selections` / `pickupSelections` and `active_pickup_family` from state, save and reset responses;
- `assets/frontend/pickup-map/wdc-pickup-checkout.js` keeps `pickupSelections` as the restore source of truth, restores the active family bucket on boot/reload from localized `activePickupFamily`/`selectedPickupPoint`, merges REST/localized dictionaries without replacing complete payloads by code-only points, starts background prefetch for the active pickup family (including CDEK city-code requests) and hides inactive family cards without clearing their saved selection;
- `assets/frontend/pickup-map/wdc-pickup-map.js` uses shared `display_title` / `display_code` for popup and side-list titles, with Russian Post postcode display and CDEK `cdek_code` display;
- `assets/frontend/domestic-tariff-selector.js` and `.css` disable and grey out nested tariff rates when their parent grouped shipping method is inactive;
- `CdekDeliveryPointService` provides live CDEK pickup point data from `GET /v2/deliverypoints` to the shared checkout/admin picker infrastructure and fills the normalized presentation fields;
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
- `tests/cdek/run-cdek-foundation-smoke.php` covers the CDEK foundation with fake HTTP and no real CDEK requests;
- demo и fixture data в `tests/fixtures`;
- прямые PHP entrypoints, например `tests/domain/run-domain-smoke.php`, `tests/checkout/run-checkout-smoke.php` и `tests/runtime/run-no-legacy-smoke.php`.
