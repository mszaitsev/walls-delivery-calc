- 0.88.0 Yandex Geo Manual Review Queue: YandexDeliveryGeoMappingRepository exposes grouped needs_review queue methods plus approve/reject/bulk reject transitions; the Маппинг geo_id tab blocks those manual actions while the runner is running.
## Yandex Geo Mapping Runner 0.87.0

- `src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingRunnerService.php` is the production runner for full WDC `location_id` -> Yandex `geo_id` mapping. It stores state in option `wdc_yandex_delivery_geo_mapping_runner_state`, supports `full` and `retry_errors` modes, uses fixed `BATCH_SIZE = 30`, reserves work ahead with `next_location_id` under an option lock, exposes `WORKER_COUNT = 3`, deletes old mappings before each full-mode remap, never skips existing primary mappings in full mode, and finishes with `done` when no rows remain.
- Full mode processes active RU locations with non-empty `display_name` in `id ASC` order through the existing `YandexDeliveryGeoMappingService::detect_for_runner()` wrapper. Existing working primary mappings are skipped; technical failures do not stop the batch.
- Technical failures are represented by marker `YandexDeliveryGeoMappingRepository::TECHNICAL_ERROR_GEO_ID = 999999999`. Marker rows have `status=error`, `confidence=0`, `is_primary=0` and compact raw JSON. `find_primary_geo_id()` and `set_primary()` reject this marker.
- Retry mode selects only locations with marker `999999999`. A successful retry replaces the marker with a normal mapped/needs_review/not_found result; a repeated technical failure updates the marker and `errors_last`.
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` renders the runner inside `Маппинг geo_id`: status fields, progress bar, latest technical errors and buttons `Запустить полный маппинг`, `Обработать тех.ошибки`, `Выполнить шаг`, `Пауза`, `Сбросить прогресс`. Manual detect and primary actions are blocked while state is `running`.
- `assets/admin/yandex-delivery-geo-mapping-runner.js` starts/retries the runner through admin-ajax, loops `step` calls while the page stays open, refreshes progress after every step and resumes a running state on page open. This stage does not add PVZ import, coverage batch, checkout or pricing.
## Yandex Admin UX Consolidation 0.85.0

- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` now renders the Yandex Delivery admin tabs as `Данные для входа`, `Маппинг geo_id`, `Покрытие Яндекса`, `Яндекс ПВЗ`.
- `Маппинг geo_id` visually consolidates the old technical geo tabs: manual `location/detect` search and primary selection, mass mapping controls (`start/run step/pause/reset` actions), read-only mapping analytics, and a working manual review queue for `needs_review` rows with approve/reject/bulk reject actions.
- `Покрытие Яндекса` remains a diagnostic/manual coverage tool. It can find WDC locations by name, run one `check_yandex_delivery_geo_coverage` check by `location_id`, show `covered`/`not_covered`/`no_geo_id`/`error`/`unknown` stats and list the latest checks.
- `Яндекс ПВЗ` is the future pickup-point workspace. The current Moscow-only `geo_id=213` import is kept as a clearly labeled test diagnostic, not as full Russia import.
- Redirects for `run_yandex_delivery_geo_detect`, `set_yandex_delivery_geo_primary`, `start_yandex_delivery_geo_batch`, `run_yandex_delivery_geo_batch_step`, `pause_yandex_delivery_geo_batch`, `reset_yandex_delivery_geo_batch` and `check_yandex_delivery_geo_coverage` return to the new consolidated tabs.
- Architecture decision: a separate coverage batch is not needed. Future PVZ import will iterate confirmed mapped geo_id values and update `covered`/`not_covered` together with point import results.
## Yandex Coverage Discovery Foundation 0.84.0

- `database/migrations/0034_create_yandex_delivery_geo_coverage_table.php` creates `wdc_yandex_delivery_geo_coverage` through `YandexDeliveryGeoCoverageRepository`. The table stores one latest selective coverage result per WDC `location_id`: primary `yandex_geo_id`, source mapping status, coverage status, pickup/dropoff counts, compact operators/sample JSON, last check time, message and compact raw stats.
- `src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoCoverageStatus.php` defines `covered`, `not_covered`, `no_geo_id`, `error` and `unknown`. `covered` means Yandex returned at least one pickup/dropoff point for the primary geo_id; `not_covered` means a successful request returned zero points; `no_geo_id` means the WDC location has no working primary mapping and Yandex is not called; `error` records API/exception failures.
- `src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoCoverageService.php` uses `YandexDeliveryGeoMappingRepository::find_primary_geo_id()` as the only working geo_id source, then calls `POST /api/b2b/platform/pickup-points/list` with payload `{"type":"pickup_point","geo_id":<int>}`. It does not use `limit`, pagination, full Russia import, coordinate fallback or checkout/pricing code.
- Response normalization accepts point arrays from `points`, `pickup_points`, `items`, `result` or a root list. `operators_json` stores counts by `operator_id`; `sample_points_json` stores at most the first 5 compact points (`id`, `operator_id`, `dropoff`, `address`); `raw_stats_json` stores only compact counters/source keys and never the full raw response.
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` introduced the diagnostic coverage UI; after 0.85.0 it appears as `Покрытие Яндекса` in the consolidated Yandex tab order and keeps the manual `location_id` check, checked row, status statistics and latest 50 checks.
- `src/Core/Plugin.php` registers `YandexDeliveryGeoCoverageRepository` and `YandexDeliveryGeoCoverageService` and passes both into `DeliveryServicesAdminPage`. `tests/yandex-delivery/run-yandex-delivery-geo-coverage-smoke.php` covers repository upsert/stats, no_geo_id/no-call, covered/not_covered/error paths, payload guards, sample cap and raw-response guard.
- Current required Yandex smoke list: foundation, pickup, geo mapping, geo batch, geo analysis, geo resolution and geo coverage.
## Yandex Mapping Resolution Engine 0.82.0

- `src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoResolutionPolicy.php` is the decision layer above scored Yandex `location/detect` candidates. It returns `mapped`, `needs_review` or `not_found`, a primary geo_id when safe, a reason and confidence.
- `src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingStatus.php` now includes `needs_review`. This status is diagnostic only: it is not a working geo_id and checkout/runtime code must still use only rows with `is_primary=1` through `find_primary_geo_id()`.
- Resolution rules: confidence `>=95` with at least a 15-point gap maps the best candidate; `locality_exact` without confident primary becomes `needs_review`; `locality_exact` plus wrong/foreign region is `needs_review`, not `not_found`; region+district/type context without locality also becomes `needs_review`; truly irrelevant results become one NULL-geo `not_found` diagnostic row.
- `YandexDeliveryGeoMappingService` receives `YandexDeliveryGeoResolutionPolicy` from `src/Core/Plugin.php`, saves mapped primary rows, stores needs_review candidates with `is_primary=0`, and keeps not_found as one NULL `yandex_geo_id` row. `YandexDeliveryGeoMappingBatchService` counts `needs_review` in the existing `ambiguous` counter.
- `tests/yandex-delivery/run-yandex-delivery-geo-resolution-smoke.php` covers mapped, needs_review, not_found, service integration, batch integration and source guards. No checkout, pricing, PVZ import/selection, shipment creation, full PVZ import or autosync code is added.
## Yandex Low Confidence Analysis 0.81.0

- `src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoAnalysisService.php` is a read-only analysis service for already saved rows in `wdc_yandex_delivery_geo_mappings`. It does not call Yandex APIs, does not run `location/detect`, and does not rebuild mappings.
- The service returns bucket statistics (`100`, `95_99`, `80_94`, `60_79`, `40_59`, `1_39`, `0`), status statistics (`mapped`, `multiple_matches`, `not_found`, `manual`, `error`), top low-confidence regions, top settlement types, top `matched_by` patterns parsed from `raw_json.scoring.matched_by`, and the lowest-confidence rows with WDC `display_name`.
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` adds the Yandex tab order `Данные для входа`, `ПВЗ / точки сдачи`, `Yandex geo_id`, `Yandex geo batch`, `Yandex geo analysis`. The analysis tab has a GET `max_confidence` filter defaulting to `59.99` and renders read-only dashboard tables.
- `src/Core/Plugin.php` registers `YandexDeliveryGeoAnalysisService` and passes it into `DeliveryServicesAdminPage`. `tests/yandex-delivery/run-yandex-delivery-geo-analysis-smoke.php` covers bucket/status stats, region/type aggregation, `matched_by` aggregation, low-confidence row fields and source guards against detect/API/checkout/pricing/pickup calls.
- This tool is only for analyzing first batch results and identifying which settlements landed in low confidence buckets. Scorer behavior, batch builder behavior, checkout, pricing, PVZ import, pickup selection, shipments, statuses and documents are unchanged.
## Yandex Geo Mapping Batch Builder 0.79.0

- `src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingBatchService.php` owns the experimental option-backed batch state in `wdc_yandex_delivery_geo_mapping_batch_state`. State includes status/session/timestamps, last processed `location_id`, limit, batch size, processed/mapped/ambiguous/not_found/errors/skipped_existing counters, confidence buckets, message and `errors_last` capped at 10 compact rows.
- `start(limit, batch_size)` creates a new running session with safe defaults `1000` and `25`, clamps limit to `1..10000` and batch size to `1..100`. `run_step()` reads only the next short RU/display_name batch, skips existing primary mappings, calls `YandexDeliveryGeoMappingService::detect_for_location_id()`, classifies saved mappings and updates state. `pause()` changes status to `paused`; `reset()` clears the option back to idle state.
- `src/Locations/Storage/LocationRepository.php::find_batch_after_id()` now accepts optional `country_code` and `require_display_name` filters while preserving the existing two-argument usage. The batch service uses the repository layer instead of raw SQL.
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` adds `Yandex geo batch` after `Yandex geo_id` and handles `start_yandex_delivery_geo_batch`, `run_yandex_delivery_geo_batch_step`, `pause_yandex_delivery_geo_batch` and `reset_yandex_delivery_geo_batch` through the existing nonce/action redirect flow. The tab renders state fields, confidence buckets and last errors, with ordinary POST buttons and no AJAX.
- `src/Core/Plugin.php` registers `YandexDeliveryGeoMappingBatchService` and passes it into `DeliveryServicesAdminPage`. `tests/yandex-delivery/run-yandex-delivery-geo-batch-smoke.php` covers start/clamps, step size, skipped existing primary, mapped/ambiguous/not_found/error classification, confidence buckets, bounded errors_last, pause/reset and source guards.
- This builder is only for quality analysis of WDC `location_id` -> Yandex `geo_id` mapping. First recommended live run: 1000 locations. Evaluate mapped / ambiguous / not_found / errors before widening. Do not run the full 160000 locations yet; checkout, pricing, pickup selection/map, full Russia PVZ import, shipments, statuses and documents are still absent.
## Yandex Delivery Geo Mapping 0.76.0

- `database/migrations/0033_create_yandex_delivery_geo_mappings_table.php` creates `wdc_yandex_delivery_geo_mappings` with `location_id`, `yandex_geo_id`, Yandex locality/region, source query, status, confidence, primary flag, raw JSON and created/updated timestamps. It deliberately does not use `wdc_location_delivery_codes`, because Yandex mappings need candidate rows and primary selection; multiple `geo_id` for a large city remains a working hypothesis to recheck later against pickup-points/list results and the completed mapping database.
- `src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingRepository.php` owns save, batch save, lookup by `location_id`, primary lookup/switching, location cleanup, search and statistics. Status values are `mapped`, `multiple_matches`, `not_found`, `manual` and `error` from `YandexDeliveryGeoMappingStatus.php`.
- `src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMappingService.php` builds search strings from `LocationRepository`/`Location` models, calls `POST /api/b2b/platform/location/detect`, normalizes variants into mappings, stores raw variant plus scoring diagnostics in `raw_json`, sorts candidates by smart confidence and auto-selects primary only when the best score is reliable enough.
- `src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMatchScorer.php` owns deterministic candidate scoring. It normalizes locality/region text, handles structured and string `address` values, downranks foreign/noisy candidates and returns `confidence`, `matched_by` and `reason`. `src/Carriers/YandexDelivery/Api/YandexDeliveryApiClient.php` exposes `locationDetect()` and `YandexDeliveryEndpoints.php` includes `/api/b2b/platform/location/detect`.
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` adds the Yandex tab `Yandex geo_id` after `ПВЗ / точки сдачи`. It shows mapping statistics, accepts `location_id` or WDC location-name search, runs `location/detect`, displays `geo_id/locality/region/confidence/status/scoring` and lets an admin mark a row primary/manual.
- `src/Core/Plugin.php` registers the Yandex geo mapping repository and service. No checkout carrier, pricing calculator, pickup selector, map, shipment flow, full Russia pickup import, cron or autosync was added in 0.76.0.
- `tests/yandex-delivery/run-yandex-delivery-geo-mapping-smoke.php` covers repository CRUD, multiple candidate rows per location, primary switching, smart scorer examples for Moscow/Saint Petersburg/Novosibirsk/Kazan/Ekaterinburg, auto-primary, raw scoring diagnostics, location/detect normalization, status constants, migration schema and admin wiring.
# Карта текущего кода


## Checkout Sorting 0.103.5

- `src/Checkout/Sorting/RateSorter.php` owns deterministic checkout ordering for all carriers, with separate strategies for selector variants and checkout methods. Only rates with `meta['tariff_selector_group']` are grouped, using `meta['checkout_group_id']`; ordinary rates are standalone checkout methods keyed by `rate_id`, so `service_key` never merges pickup/courier methods. `sort_group_rates()` uses original carrier values inside one selector; `sort_methods()` uses final active rate values between methods.
- `src/Domain/Quote/DeliveryRate.php` carries neutral `original_cost` and `original_delivery_days` fields. `sorting_cost()` and `sorting_delivery_days()` fall back to current `price`/`delivery_days` for older/direct rates.
- Inside a group, price sorting uses original carrier cost and fastest sorting uses original minimum days. Between methods, price sorting uses final `price` and fastest sorting uses final `delivery_days.min_days`; ties use the secondary value, title, `tariff_key`, `rate_id` and input index.
- `NewShippingMethod::rates_for_wc()` replaces the first rate of a tariff selector group with the selector method, preserves original-value variant order, applies any selected session tariff as active, then sorts methods by the active final value.
- `RuleAppliedRateBuilder`, `DeliveryServiceManager`, `CheckoutOrchestrator`, `WooCommerceRateMapper` and `NewShippingMethod` preserve source values while rules and service post-processing keep changing final checkout display values.
- `tests/checkout/run-checkout-smoke.php` covers standalone same-service rates, selector-only grouping by `checkout_group_id`, original-value sorting inside groups, final-value sorting between methods and stable tie-breakers. `tests/checkout/run-woocommerce-checkout-smoke.php` covers selector insertion, separate same-service methods, variant order and selected active tariff ordering.
## Shared Packaging Builder 0.103.2

- `src/Packaging/PackagingBuilder.php` is the shared package-place builder extracted from the former DPD tariff layer. It expands product quantities, splits long items over 49 cm into separate parcels, aggregates <=50 cm3 small items into one synthetic block, optimizes identical groups into grid blocks, runs the deterministic 3D shelf/bin packer with `box_50_50_30` and `box_40_40_40`, attempts one box and then two boxes, falls back to stacked rows, adds packaging weight through `PackagingWeightCalculator`, and returns diagnostics without DPD-specific namespaces.
- Public shared API: `PackagingBuilder::build(QuoteRequest): PackagingResult`; `PackagingResult::to_array()` exposes array `parcels`, counts, dimensions, weight totals, box formats, selected formats, small-item/identical-grid diagnostics and packing limit reason. `PackagingResult::parcels()` returns `PackagingParcel` objects for internal adapter use. `PackagingParcel` carries neutral `weight_g`, `length_cm`, `width_cm`, `height_cm` and `quantity` values.
- `src/Core/Plugin.php` registers `PackagingBuilderConfig::defaults()` and then the shared `PackagingBuilder`; no `DpdSettings` are needed to create Packaging. Defaults live in `src/Packaging/PackagingBuilderConfig.php`: `500 g`, `20x15x10 cm`, declared value `1 RUB`. DPD is passed a separate legacy `PackagingBuilderConfig(1000, 20, 20, 20, 1000)` through `src/Carriers/Dpd/Tariff/DpdPackagingBuilderFactory.php`, and `DpdQuoteCarrier` is wired to that factory from `src/Core/Plugin.php`, preserving existing fallback payloads without making Packaging depend on DPD settings. Yandex pricing now consumes the shared generic builder; DPD remains on `DpdPackagingBuilderFactory` with its legacy config.
- `src/Carriers/Runtime/DpdQuoteCarrier.php` now converts shared `PackagingParcel` objects into `DpdTariffParcel` before calling `DpdTariffCalculationService`. `DpdTariffParcel`, `DpdTariffRequest`, `DpdTerminalCodeTariffRequest` and request builders remain DPD-specific payload DTOs.
- `src/Domain/Package/ShipmentPlace.php` remains the shipment-domain model with place number, declared value and items. It was not reused as the shared packaging parcel to keep the refactor local and preserve shipment behavior.
- `tests/dpd/run-dpd-parcel-builder-smoke.php` now exercises the shared `PackagingBuilder`; DPD checkout runtime smoke verifies the DPD-configured builder, legacy fallback `20x20x20`/`1000 RUB`, Parcels3 payload parcel count, dimensions, weights, declaredValue, selfPickup/selfDelivery, sender/receiver cityId and diagnostics.

## Yandex Delivery Checkout Pricing and PVZ Selection 0.104.6

- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` renders a `Точка сдачи отправлений Яндекс.Доставки` block on the Yandex Delivery `Расчет` tab. The block lets an admin choose a city, searches the local WDC locations directory, saves `YandexDeliverySettings::SOURCE_LOCATION_ID_KEY` only to restore the admin selector, resolves all mapped/manual `yandex_geo_id` values through location mapping v2, then lists all active `available_for_dropoff` Yandex PVZ rows for that geo id set without an in-geo limit and provides a client-side `full_address` filter from 3 characters. It saves `YandexDeliverySettings::SOURCE_PLATFORM_STATION_ID_KEY` as the sender PVZ id and displays the saved `platform_station_id` plus read-only full address.
- `src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointV2Repository.php` exposes the read-only admin selector `source_dropoff_points_by_geo_id()` over the existing live PVZ table and returns all matching source dropoff rows for the selected geo id set; import/staging/swap logic is unchanged.
- `src/Carriers/YandexDelivery/YandexDeliverySettings.php` exposes `source_platform_station_id()` and `source_location_id()`. `YandexDeliveryCarrier` now reads the saved source station for pricing-calculator requests and keeps the existing `yandex_pickup` / `yandex_courier` rate ids.
- `src/Carriers/YandexDelivery/Pricing/` contains the local pricing request builder, response parser and parsed result value object for checkout pricing. Delivery day labels reuse `DeliveryDaysFormatter`. The request builder now receives the shared generic `PackagingBuilder`, converts `PackagingParcel` objects into Yandex `places[]`, expands parcel `quantity` into repeated places, and sets `total_weight` to the sum of `physical_dims.weight_gross`. Empty or invalid packaging results fall back to the previous single-place model with generic defaults.
- `src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointV2Repository.php` exposes `representative_destination_pickup_point_by_geo_ids()` as the fallback for preliminary pickup pricing and `destination_pickup_points_by_geo_ids()` / `destination_pickup_point_by_platform_station_id()` for buyer-selected checkout PVZ. Destination candidates use all mapped/manual geo ids, require active non-empty `platform_station_id` rows with `type=pickup_point` or `terminal`, do not require `available_for_dropoff`, and the checkout picker path intentionally applies no REST limit, array_slice or SQL LIMIT for Yandex points within the selected city.
- `src/Carriers/YandexDelivery/Pickup/YandexDeliveryCheckoutPickupPointFormatter.php` formats buyer-facing Yandex pickup presentation. It keeps `platform_station_id` as the internal id/point_code only, sets Yandex `display_code` to empty, derives title/display_title/card_title from operator_id/type/name, and stores warnings in `presentation_comment` separately from instructions in `description`.
- `assets/frontend/pickup-map/wdc-pickup-map.js` keeps all loaded Yandex city points for markers/clusters and filters the side list by the current bbox through `pointInsideBounds()`, rendering `Показано X из Y` and preserving committed selections outside the viewport. The Leaflet and Yandex provider adapters use 128px cluster grids. `wdc-map-provider-leaflet.js` additionally retains `lastPoints`/`lastSearchMarker` and rebuilds its own clustered marker layers on `zoomend`, preserving active-point and popup state without another Yandex pickup REST load.
- `src/Carriers/YandexDelivery/Api/YandexDeliveryApiClient.php` posts to `/api/b2b/platform/pricing-calculator`; tokens are still taken from existing Yandex settings/credentials.
- Buyer PVZ map/selection, shipment creation, offers/confirm, import and geo pipeline code remain out of scope for this stage.
- `tests/yandex-delivery/run-yandex-delivery-source-station-smoke.php` covers the local source-station selectors, multi-geo source dropoff listing, saved station id sanitization, admin UI filter contracts, missing-station warning, and checkout non-dependence on the setting.

## Yandex Delivery Pickup Points AJAX Import 0.74.0

- `src/Carriers/YandexDelivery/YandexDeliverySettings.php` stores active environment, encrypted test/production Bearer tokens, test/production source `platform_station_id`, request timeout, debug flag, explicit connection-check result and last manual pickup import/action result, pickup import page size and AJAX import state/report support. Empty token fields preserve the old encrypted token; explicit clear checkboxes remove it.
- `src/Carriers/YandexDelivery/YandexDeliveryEndpoints.php` selects `https://b2b.taxi.tst.yandex.net` for test and `https://b2b-authproxy.taxi.yandex.net` for production. `src/Carriers/YandexDelivery/Api/YandexDeliveryApiClient.php` sends JSON requests with `Authorization: Bearer ...`, extracts Yandex error code/message and sanitizes diagnostics through settings redaction.
- `src/Carriers/YandexDelivery/Api/YandexDeliveryConnectionDiagnosticService.php` remains the explicit live connection probe. It runs `POST /api/b2b/platform/pickup-points/list` only from the admin button and succeeds only when the configured source point is found with `type=pickup_point` and `available_for_dropoff=true`.
- `database/migrations/0032_create_yandex_delivery_pickup_points_table.php` creates the local `wdc_yandex_delivery_pickup_points` table through `YandexDeliveryPickupPointRepository`, with unique `platform_station_id`, type/geo/city/dropoff/active indexes and `raw_json` diagnostics storage.
- `src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointNormalizer.php` accepts raw API objects, survives missing optional fields, normalizes address/operator/geo/schedule/payment/dropoff flags and preserves full `raw_json`. `YandexDeliveryPickupPointRepository.php` owns save/update, mark inactive, activate imported rows, search, lookup and counts.
- `src/Carriers/YandexDelivery/Pickup/YandexDeliveryPickupPointImportService.php` is manual-only and stateful. `start_import()` creates an option-backed session/lock and marks old rows inactive; `run_import_step()` calls one `POST /api/b2b/platform/pickup-points/list` page with `type=pickup_point`, normalizes/saves that page, updates counters and finalizes on the last page; `reset_import()` clears state and lock. Reports include `fetched`, `normalized`, `saved`, `inactive`, `errors`, `duration`, `page_size`, `pages` and `memory_peak_mb`. `YandexDeliveryPickupPointService.php` exposes search, statistics, lookup and active-environment sender-point validation.
- `src/DeliveryServices/DeliveryServiceRepository.php` and `DeliveryServiceManager.php` create the built-in `yandex_delivery` service as `Яндекс Доставка`, RU-only, disabled by default, sorted after DPD and non-deletable as a custom service.
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` now has `Яндекс Доставка -> Данные для входа` and `ПВЗ / точки сдачи`. The pickup tab renders active/type/dropoff/last-import stats, sender point validation, page-size control, AJAX import/reset buttons, visible step progress and first search results by `platform_station_id`, city and type. `assets/admin/yandex-delivery-pickup-import.js` drives start/step/status/reset without reloading the page.
- `src/Core/Plugin.php` registers settings, replaceable HTTP client, API client, diagnostic service, Yandex pickup repository/normalizer/import/service and Yandex geo mapping repository/service. No Yandex checkout carrier, shipment adapter, cron, autosync, offers/create, offers/confirm, documents or status mapping are registered in 0.74.0.
- `tests/yandex-delivery/run-yandex-delivery-foundation-smoke.php` covers the 0.72 API/settings foundation. `tests/yandex-delivery/run-yandex-delivery-pickup-smoke.php` covers migration schema, normalization, save/update, unique `platform_station_id`, inactive/reactivation flow, search, counts, sender validation, AJAX import start/step/reset/session guards, import statistics and absence of checkout/shipment/cron wiring.
## DPD Pickup Autosync 0.71.0

- `src/Carriers/Dpd/Pickup/DpdPickupPointAutoSync.php` owns the dedicated WP-Cron hook `wdc_dpd_pickup_points_autosync`. It reads DPD pickup autosync settings, converts selected Moscow-time (GMT+3) slots into UTC timestamps, schedules one daily event per unique time and skips execution when disabled or no selected time matches.
- `src/Carriers/Dpd/DpdSettings.php` stores `dpd_pickup_autosync_enabled` plus three independent 15-minute time fields. Invalid values normalize to empty, duplicates are ignored when effective times are read, and the option set is separate from DPD shipment status autosync settings.
- `src/Carriers/Dpd/Pickup/DpdPickupPointImportService.php` is still the only importer. Manual buttons and cron both call it; public import methods accept a context (`manual` or `auto_cron`) and share a short option-based lock so concurrent manual/cron runs skip safely as `skipped_lock_busy`.
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` renders the DPD -> ПВЗ autosync block, saves settings, reschedules cron events and displays the last import context/source beside the existing counts/result.
- `tests/dpd/run-dpd-pickup-autosync-smoke.php` covers select options, time validation, GMT+3 conversion, scheduling/rescheduling, duplicate slots, disabled/no-time skips, cron success/failure, lock-busy skip and shared manual/cron importer path.

## DPD Shipment Lifecycle, Documents And Autosync 0.69.1

- `src/Shipments/Dpd/DpdOrderRegistrationService.php` owns manual DPD registration, local pending creation before SOAP, `getOrderStatus` refresh/polling decisions, manual attach, cancel and local remove.
- `src/Shipments/Dpd/DpdEventSyncService.php` treats `event-tracking/getEvents` as the global DPD client inbox: atomic option lock, `docId`, `resultComplete`, optional confirm, 20-package safety limit, all matched WooCommerce orders updated from one package and unmatched event summaries logged without PII. In autosync mode it also enriches only newly updated shipments missing actual cost or planned delivery date.
- `src/Shipments/Dpd/DpdShipmentRepository.php` wraps `_wdc_shipments[dpd]`, maintains `_wdc_dpd_order_number`, supports HPOS-compatible lookup by DPD number, and removes the DPD index on local delete.
- `src/Shipments/Dpd/DpdEventNormalizer.php` normalizes `clientOrderNr`, `dpdOrderNr`, `eventNumber`, `eventCode`, `eventName`, `eventDate` and selects the latest event per order without storing parameter history.
- `src/Shipments/Dpd/DpdShipmentEnrichmentService.php` calls `tracing1-1/getStatesByDPDOrder` only for `orderCost` and `planDeliveryDate`; status and button policy still come from registration state or `getEvents`.
- `src/Shipments/Dpd/DpdShipmentButtonPolicy.php` centralizes DPD actions: create/manual when absent, update/remove while pending, update with DPD number, cancel only for EventCode `1001`, `1101`, `1201`, `1401`, `1501`, and remove for cancelled/other states.
- `src/Shipments/Dpd/DpdShipmentAdapter.php` stays thin over those services, returns the `Скачать документы` action only for EventCode `1401` with `dpd_order_number`, exposes generic shipment status payload fields for the shared metabox UI, and opts into autosync support so the shared scheduler can run the DPD global pre-pass.
- `src/Carriers/Dpd/DpdApiClient.php`, `DpdSoapRequest.php` and `DpdSettings.php` provide WSDL-checked DPD methods/wrappers including `order2/getInvoiceFile` with wrapper `request` and `label-print/createLabelFile` with wrapper `getLabelFile`, fixed 10-second `createOrder2` timeout, nullable event lookback days, disabled-by-default event confirm setting, enabled-by-default DPD autosync setting and readonly last autosync run/result keys.
- `src/Shipments/Admin/OrderShipmentsMetabox.php` and `assets/admin/shipments-admin.js` handle the two-stage DPD create flow, non-overlapping 10-second pending polling, manual update, temporary local-remove visibility after cancel errors, carrier-neutral planned-date/status rendering and DPD ZIP document download. Initial PHP render now uses the DPD adapter status payload for cancel/remove visibility, matching AJAX refresh behavior.
- `src/Shipments/Application/ShipmentCreationService.php` strips CDEK-only fields from DPD shipments; no `cdek_*` keys are written for DPD.
- `src/Shipments/Dpd/DpdShipmentDocumentService.php` validates DPD shipment state, requests invoice PDF without `parcelCount`/`cargoValue`, requests the A6 PDF label, creates a temporary ZIP and cleans it after streaming; it never writes PDFs to order meta or mutates shipment status.
- `src/Shipments/Application/ShipmentStatusAutoSyncService.php` runs DPD once per autosync pass via `DpdEventSyncService::sync(null, true)`, records DPD diagnostics, saves `wdc_dpd_autosync_last_run/result`, and skips per-order DPD polling because the event inbox is already global.

## DPD Manual Create 0.66.0

- `src/Carriers/Dpd/DpdSoapRequest.php` supports the DPD `orders` SOAP wrapper in addition to existing `direct` and `request` modes.
- `src/Carriers/Dpd/DpdApiClient.php::createOrder2()` calls `order2/createOrder2`, normalizes SOAP/transport/business errors and returns an array result.
- `src/Carriers/Dpd/Shipments/DpdShipmentPayloadBuilder.php` builds the shared preview/live DPD body: `header.datePickup`, `senderAddress`, `pickupTimePeriod`, `order.serviceCode`, `serviceVariant`, cargo fields, `receiverAddress` and `parcel[]`.
- `src/Shipments/Dpd/DpdShipmentAdapter.php` validates, calls DPD createOrder2, normalizes DPD order/request/parcel numbers and keeps labels/documents empty; status autosync is handled globally through DPD `getEvents`, not per order.
- `src/Shipments/Application/ShipmentCreationService.php` saves successful DPD manual creates in `_wdc_shipments[dpd]` with `pending_creation_in_carrier`, DPD identifiers, sanitized request/response and admin marker.
- `src/Shipments/Admin/OrderShipmentsMetabox.php` exposes `Создать отправление DPD` in the existing modal. `assets/admin/shipments-admin.js` keeps the button disabled until tariff, date, pickup/courier and cargo-place data are valid.
- `tests/dpd/run-dpd-create-order-smoke.php` covers mocked createOrder2, payload shape, persistence, duplicates, errors and no auto-create hook.
## DPD Pickup Recalculation Diagnostics 0.69.3

- `src/Carriers/Runtime/DpdQuoteCarrier.php` keeps order recalculation on the checkout DPD carrier path and passes explicit `dpd_receiver_city_id` / `dpd_city_id` context into tariff calculation. Its quote raw reference and DPD rate meta expose receiver location/city, delivery terminal selection/code/source, raw count, skipped counters and `dpd_filter_removed_count`.
- `src/Carriers/Dpd/Tariff/DpdTariffCalculationService.php` still calls `getServiceCostByParcels3` for pickup and courier. Pickup uses `selfDelivery=true`; when no selected pickup point is supplied it auto-selects an active receiver `parcel_shop` terminalCode and never treats `terminal_self_delivery` as the receiver pickup point. Missing receiver parcel shops return `DPD pickup tariff unavailable: no active parcel_shop for receiver cityId {cityId}`.
- `tests/dpd/run-dpd-order-recalculation-smoke.php` covers DPD courier presence, DPD pickup grouped rendering, auto parcel_shop terminalCode, duplicate `terminal_self_delivery` avoidance, selected pickup payload, diagnostics counters and the no-parcel-shop case where courier remains available while pickup is absent.
## Order Delivery Recalculation 0.69.2

- `src/Orders/Application/OrderQuoteRequestMapper.php` maps WooCommerce order state plus the admin-selected settlement into the checkout `QuoteRequest`. For order recalculation it accepts `id`, `location_id` or `selected_location_id`, preserves DPD cityId aliases, and passes `dpd_receiver_city_id` into `customer_context` so DPD rates use the same `DpdQuoteCarrier` runtime as checkout.
- `src/Orders/Application/OrderDeliveryRecalculationService.php` groups preview rates without carrier filtering and now has DPD fallback titles: `DPD до пункта выдачи` for pickup and `DPD курьером` for courier. Russian Post and CDEK title fallbacks are unchanged.
- `tests/dpd/run-dpd-order-recalculation-smoke.php` covers DPD pickup/courier preview, grouped tariffs, DPD titles, `location_id` payloads, pickup save blockers/aliases, courier pickup-meta cleanup and platform/calculation data persistence.

## DPD Status Mapping 0.65.1

- `src/Domain/Status/DeliveryStatus.php` defines the universal shipment status `pending_creation_in_carrier` with label `Попытка создания в ТК`. It is the first value in `DeliveryStatus::all()`, directly before `created_in_carrier`, so admin select ordering stays stable.
- `src/Shipments/Dpd/DpdStatusMapping.php` is the DPD EventCode dictionary and mapping service. The dictionary is sourced from `docs/dpd/ws-integration-guide.docx`, section 5.5.4 "Справочник статусов заказа EventCode, EventName и его параметров(ParamName)", and contains 75 EventCode rows. Runtime rows keep EventCode, EventName, optional DPD marker/code name from the `Код` column and document comments where relevant; ParamName/event parameters are intentionally not stored.
- `DpdStatusMapping::default_mapping()` maps offer/pre-registration events `1001`, `1101`, `1201`, `1301` to `pending_creation_in_carrier`; unknown-safe payment/problem/cancel/archive events to `unknown`; active delivery/problem-repeat events to `in_transit`; refusal return events `2404`, `2405`, `2406`, `2416` to `returning_to_sender`; final delivery events `3304`, `3305`, `3308` to `delivered`; and `3306` to `returned_to_sender`.
- `SettingsRepository::defaults()` registers `dpd_status_mapping`, and the service reads/writes saved overrides through the same `wdc_core_settings` option as CDEK status mapping. Invalid saved values fall back to the updated default for that EventCode.
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` adds `WDC → Службы доставки → DPD → Статусы DPD`. The tab renders EventCode, EventName, DPD marker/code name, editable universal-status select and default value; it supports save and reset-to-default.
- `tests/dpd/run-dpd-status-mapping-smoke.php` covers dictionary completeness, EventName/default validity, absence of ParamName/parameters, new universal status ordering/select presence, saved override, invalid fallback, unknown EventCode fallback, admin render, save/reset and CDEK mapping regression. `tests/shipments/run-shipment-status-smoke.php` also verifies the new universal-status ordering for shipment status flows.
- DPD status API polling, cron/sync, shipment updates, live create, labels and cancellation remain intentionally out of scope.
## DPD Modal Preparation Cleanup 0.63.2

- `src/Shipments/Admin/OrderShipmentsMetabox.php` keeps the DPD receiver pickup block focused on `Код ПВЗ`, `Адрес ПВЗ` and `Выбрать другой ПВЗ`; the visible `Тип точки` row is now CDEK-only. The DPD comment textarea was removed.
- `src/Carriers/Dpd/Shipments/DpdShipmentDateResolver.php` resolves DPD `datePickup` defaults with the store timezone, a 17:00 cutoff and `CalendarService`/`CalendarTypes::SHOP`; when no calendar service is available it falls back to today/next calendar day.
- `src/Shipments/Application/OrderShipmentDraftFactory.php` adds the resolved `date_pickup` to DPD draft meta and reads temporary modal `date_pickup` from the admin request without saving it to order meta or settings.
- `src/Carriers/Dpd/Shipments/DpdShipmentPayloadBuilder.php` validates `date_pickup`, writes it to `request.header.datePickup`, and no longer emits a DPD `comment` field.
- `assets/admin/shipments-admin.js` hides the CDEK city-code row for DPD courier normalization even when the shared normalized snapshot contains `cdek_city_code`; CDEK still shows the row.
- `tests/dpd/run-dpd-shipment-preparation-smoke.php` covers hidden DPD point type/comment, CDEK preservation, date defaults/validation/payload header, hidden DPD CDEK city code and the no-live-call boundary.

## DPD Modal Preparation Refinement 0.63.1

- `src/Shipments/Admin/OrderShipmentsMetabox.php` now shows DPD receiver pickup point fields as `Код ПВЗ`, `Тип точки`, `Адрес ПВЗ` and exposes the shared `Выбрать другой ПВЗ` picker for DPD pickup delivery. The visible DPD technical block (`serviceCode`, cityId and terminalCode rows) was removed; the modal shows `В заказе тариф` instead.
- DPD sender pickup point is displayed as `ПВЗ отправителя: {код}, {адрес}` with `Выбрать другой ПВЗ отправителя`. Sender and receiver picker results are written only into modal hidden fields/admin request data and are not saved to order meta or DPD settings.
- `assets/admin/shipments-admin.js` treats `dpd:pickup` like CDEK code-based pickup points, opens receiver/sender picker titles for DPD, posts receiver/sender cityId to the shared admin pickup search endpoint, updates DPD pickup labels in the modal and keeps the single-place weight hint hidden once multiple places exist.
- `OrderShipmentsMetabox::ajax_search_pickup_points()` handles `carrier_key=dpd` through `DpdPickupPointService` and returns only active `parcel_shop` rows. `terminal_self_delivery` rows are not returned to the modal picker.
- `OrderShipmentDraftFactory` reads temporary DPD `delivery_type`, selected `tariff_object`, sender `pickup_terminal_code`, receiver `pickup_point_code`, normalized courier address snapshot and manual places from the admin request. These values are merged into the in-memory `ShipmentCreateRequest` only.
- `DpdShipmentPayloadBuilder` validates sender terminalCode, pickup receiver terminalCode, courier normalized address, serviceCode and manual parcels, then builds the shared `order2/createOrder2` body used by both dry-run preview and live manual create. Visible preview strips legacy `dry_run/live_api_call` debug meta.
- `tests/dpd/run-dpd-shipment-preparation-smoke.php` covers DPD receiver/sender picker markers, pickup/courier switch, tariff switch, hidden technical block removal, temporary payload overrides, normalized courier address, single/multi-place weight hint behavior and no live API call.

## DPD Order Recalculation 0.64.0

- `src/Orders/Admin/OrderDeliveryMetabox.php` renders the existing order `Калькулятор доставок` modal and now passes saved `location_id` plus current DPD pickup terminal aliases into the modal bootstrap JSON.
- `src/Orders/Admin/OrderDeliveryRecalculationAdminController.php` handles preview/save AJAX and now supports DPD pickup search through `DpdPickupPointService`; DPD rows are normalized into the same map/list payload shape as checkout.
- `assets/admin/order-delivery-recalculation.js` keeps the existing pickup picker and adds a DPD-only fresh preview after selecting a DPD point, sending `selected_pickup_point.terminal_code` so `DpdQuoteCarrier` recalculates with the selected receiver terminalCode before save.
- `src/Orders/Application/OrderQuoteRequestMapper.php` maps WooCommerce order items/address/meta into a checkout `QuoteRequest` and now includes saved/selected DPD terminalCode plus saved `location_id` in `customer_context`.
- `src/Orders/Application/OrderDeliveryRecalculationService.php` continues to call the shared `CheckoutOrchestrator`; DPD therefore uses `DpdQuoteCarrier`, the shared `PackagingBuilder`, enabled DPD tariffs and Parcels3 terminalCode-aware pricing exactly like checkout.
- `src/Orders/Application/OrderDeliveryReplacementService.php` writes DPD selected serviceCode/title, DPD rate meta, `_wdc_delivery_calculation_data`, shared `_wdc_pickup_*` meta and DPD alias meta. Courier saves clear shared pickup meta and DPD receiver aliases.
- `src/Shipments/Application/OrderShipmentDraftFactory.php` reads the recalculated DPD order meta for `Отправления`: serviceCode, delivery type, sender pickup terminalCode, receiver delivery terminalCode for pickup, selected pickup snapshot and courier address for courier.
- `tests/dpd/run-dpd-order-recalculation-smoke.php` covers DPD appearance in recalculation, Parcels3 pickup/courier payloads, auto vs selected receiver terminalCode, pickup/courier save meta, WooCommerce shipping item update, shipment draft visibility and the manual-create/no-auto-create boundary.

## DPD Manual Shipment Preparation/Create 0.63.0-0.66.0

- `src/Shipments/Dpd/DpdShipmentAdapter.php` registers DPD in `CarrierShipmentAdapterRegistry` for manual preview/create. `build_safe_payload_preview()` returns an `order2/createOrder2` preview without legacy visible debug meta; `create()` validates, calls `DpdApiClient::createOrder2()` with the dedicated order-create timeout and normalizes DPD order/request/parcel identifiers. Label/status/cancel/manual-attach actions remain disabled, and `supports_status_auto_sync()` is `false`.
- `src/Carriers/Dpd/Shipments/DpdShipmentPayloadBuilder.php` builds the DPD order payload from saved order/rate meta, DPD settings (cargo category, sender name/phone, payer clientNumber), modal contactFio/instructions, structured DaData courier address fields and manager-entered places. It does not read checkout tariff `parcel[]`, does not persist parcels in order meta, and uses order goods value as `cargoValue`/declared value source.
- `src/Shipments/Application/OrderShipmentDraftFactory.php` creates DPD shipment drafts from `_wdc_platform_rate_meta`, `_wdc_delivery_calculation_data`, DPD pickup aliases and `DpdSettings::tariff_default_sender_terminal_code()`. Initial DPD drafts intentionally have no places, so the manager must enter грузоместа in the modal.
- `src/Shipments/Admin/OrderShipmentsMetabox.php` exposes the shared `Отправления` modal for DPD orders, shows `Предпросмотр payload`, renders `Создать отправление DPD`, stores unique modal contactFio history through settings AJAX, keeps DPD order comment absent, and relies on server-side validation before SOAP.
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` keeps `ПВЗ отправителя по умолчанию` on `DPD Расчет`, stores a sender terminalCode, validates/summarizes active `parcel_shop` rows and warns when the configured terminal does not match the resolved sender cityId.
- `src/Core/Plugin.php` registers `DpdShipmentAdapter` in the adapter registry and in `ShipmentCreationService` live-create adapters. DPD auto creation is not implemented.
- `tests/dpd/run-dpd-shipment-preparation-smoke.php` covers DPD order/rate meta reading, pickup/courier payloads, missing terminal/parcel validation, missing default sender warning, declared-value derivation, no checkout `parcel[]` reuse and preview behavior.
- `tests/dpd/run-dpd-create-order-smoke.php` covers mocked createOrder2, persistence, duplicates, DPD errors and no auto-create hook.
- `docs/wdc-dpd-shipment-preparation.md` and `docs/wdc-dpd-create-order.md` document the manual-only boundary and `order2/createOrder2` mapping.
## DPD TerminalCode Runtime Pricing 0.62.1

- `src/Carriers/Dpd/Tariff/DpdTerminalCodeTariffRequest.php` and `DpdTerminalCodeTariffRequestBuilder.php` build the runtime `calculator2/getServiceCostByParcels3` payload with `pickup.cityId`, `pickup.terminalCode`, `delivery.cityId`, optional pickup-only `delivery.terminalCode`, `selfPickup`, `selfDelivery`, `declaredValue`, optional `serviceCode`/`pickupDate` and `parcel[]`. The builder stays auth-free; `DpdSoapRequest` adds `request.auth`.
- `src/Carriers/Dpd/Tariff/DpdTariffCalculationService.php` now calls `DpdApiClient::getServiceCostByParcels3()`. Pickup quotes include sender and receiver terminalCode; courier quotes include sender terminalCode and omit receiver terminalCode.
- `src/Carriers/Dpd/Pickup/DpdPickupPointService.php::find_runtime_parcel_shop_for_city_id()` and `find_runtime_parcel_shop_by_terminal_code()` select active `parcel_shop` rows for runtime pricing. They avoid duplicate `terminal_self_delivery` rows when possible, allow duplicated `parcel_shop` fallback when needed, and never use standalone `terminal_self_delivery` as a runtime terminal.
- `assets/frontend/pickup-map/wdc-pickup-checkout.js` triggers `update_checkout` after saving a DPD pickup point so selected terminalCode replaces the auto-selected receiver terminalCode and rates recalculate.
- `src/Checkout/WooCommerce/WooCommercePackageMapper.php` and `src/Checkout/Cache/QuoteCache.php` include the selected DPD terminalCode in checkout context/cache keys.
- The previous 0.61.0 admin terminalCode diagnostic service/form/comparison block was removed. In 0.62.1 the remaining generic `Тестовый расчет DPD` form/result storage was also removed from `DPD Расчет`; the tab now stores sender/default parcel settings plus the 0.63.0 default sender terminalCode, and shows read-only sender location + DPD cityId information. `tests/dpd/run-dpd-terminalcode-runtime-pricing-smoke.php` covers Parcels3 pickup/courier payloads, auto/selected terminalCode, duplicate fallback, quote_id changes and the disabled DPD dry-run adapter boundary.

## DPD Checkout Pickup Selection 0.60.0

- `src/Carriers/Runtime/DpdQuoteCarrier.php` calculates DPD checkout prices through `calculator2/getServiceCostByParcels3`. Pickup rates set `requires_pickup_point=true`, auto-select receiver terminalCode before buyer selection, and use buyer-selected terminalCode after checkout save. Courier rates use sender terminalCode and do not send receiver terminalCode.
- `src/Pickup/Rest/PickupPointsRestController.php` extends the shared pickup points endpoint for `carrier=dpd`. It reads active local DPD rows through `DpdPickupPointService` by checkout `location_id`/mapped DPD cityId and returns the same map/list shape used by CDEK and Russian Post: `point_code`, `pickup_family=dpd:pickup`, title/type/address/city/coordinates/schedule/source and snapshot. DPD `raw_json` is not exposed in checkout REST payloads.
- `src/Pickup/Rest/CheckoutPickupPointRestController.php` extends the shared checkout selection endpoint for `carrier=dpd`. It resolves the posted `point_code`/`terminal_code` against the local active DPD repository before saving the selection into the checkout session.
- `src/Checkout/WooCommerce/CheckoutDeliveryTypeSelector.php` and `src/Checkout/WooCommerce/PickupMapCheckout.php` are reused for the DPD checkout UI. No separate DPD-only frontend architecture is added.
- `assets/frontend/pickup-map/wdc-pickup-map.js` owns the shared checkout pickup map/list interaction. Address search and browser geolocation update the active distance origin, then refresh `visiblePoints` through `sortPoints(enrichPoints(...))` before rerendering markers/list so distances and `Ближайший ПВЗ` are current.
- `src/Checkout/WooCommerce/CheckoutValidation.php` validates DPD pickup selections through `DpdPickupPointService`; DPD pickup fails with `Выберите пункт выдачи DPD.` when no active terminal is selected. DPD courier and non-DPD rates keep their existing validation paths.
- `src/Checkout/WooCommerce/OrderShippingMetaPersister.php` stores the selected DPD point in canonical `_wdc_pickup_*` meta and DPD aliases: `_wdc_dpd_pickup_terminal_code`, `_wdc_dpd_pickup_type`, `_wdc_dpd_pickup_name`, `_wdc_dpd_pickup_address`, `_wdc_dpd_pickup_city_name`, `_wdc_dpd_pickup_latitude`, `_wdc_dpd_pickup_longitude`, `_wdc_dpd_pickup_source`.
- `tests/dpd/run-dpd-checkout-pickup-selection-smoke.php` covers DPD location_id lookup, endpoint shape/search, checkout save, validation, order meta persistence, Parcels3 terminalCode runtime boundary and the disabled DPD shipment preview/metabox boundary.

## DPD Pickup Points Foundation 0.59.2

- `src/Carriers/Dpd/DpdApiClient.php` exposes low-level geography wrappers for `getParcelShops()` and `getTerminalsSelfDelivery2()`. `getParcelShops` uses `DpdSoapRequest::WRAPPER_REQUEST`; `getTerminalsSelfDelivery2` uses direct auth.
- `database/migrations/0031_create_dpd_pickup_points_table.php` creates `wdc_dpd_pickup_points`, separate from `wdc_location_delivery_codes`. The table stores `terminal_code`, `type`, country/region/city fields, address, name, coordinates, schedule JSON, raw JSON, source, active flag and import timestamps.
- `src/Carriers/Dpd/Pickup/DpdPickupPointRepository.php` owns schema creation, safe source replacement, upserts, inactive marking, lookup by `terminal_code`, cityId/cityName search and source counts. Replacement upserts the new valid set first and only then marks inactive old rows for the same source whose `terminal_code + type` key is absent from the new set.
- `src/Carriers/Dpd/Pickup/DpdPickupPointNormalizer.php` tolerates object/array/single-object SOAP shapes and normalizes DPD parcel shops (`code`) and self-delivery terminals (`terminalCode`) into one local format.
- `src/Carriers/Dpd/Pickup/DpdPickupPointScheduleFormatter.php` converts DPD schedule arrays/objects/JSON strings/plain strings into readable checkout text.
- `src/Carriers/Dpd/Pickup/DpdPickupPointImportService.php` performs manual imports for parcel shops, terminals or both, stores `dpd_last_pickup_import_report` through `DpdSettings`, and catches SOAP/import errors into controlled reports. Empty DPD responses and responses with fetched rows but zero normalized valid points do not call replacement and leave existing points unchanged.
- `src/Carriers/Dpd/Pickup/DpdPickupPointService.php` is the read-only checkout-map and runtime terminal-selection boundary. It resolves WDC `location_id` through `LocationDeliveryCodeRepository::get_dpd_city_id()` and reads points by DPD cityId or terminalCode. Consumer reads deduplicate by `terminal_code` and prefer `parcel_shop` over duplicate `terminal_self_delivery`; runtime terminal selection uses only `parcel_shop`.
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` adds the DPD `DPD ПВЗ` tab with status, manual import buttons and diagnostics by terminalCode/cityId/cityName.
- `tests/dpd/run-dpd-pickup-points-smoke.php` covers repository behavior, normalizer shapes, import counts, wrapper names/auth wrappers, read-only service lookup, Parcels3 runtime pricing boundary and the disabled DPD shipment preview/metabox boundary.

## DPD Checkout Runtime 0.59.0

- `src/Carriers/Runtime/DpdQuoteCarrier.php` is the DPD checkout quote carrier registered in `CarrierRegistry` under `carrier_key=dpd`. It returns rates only when the built-in DPD delivery service is enabled by the common service settings, active-environment credentials are complete, sender/receiver DPD city IDs are known, required active DPD `parcel_shop` terminalCode values exist, and DPD `getServiceCostByParcels3` returns numeric-cost options.
- The runtime reuses `src/Carriers/Dpd/Tariff/DpdTariffCalculationService.php`, `DpdCityResolver`, `DpdSettings` and the existing SOAP wrapper/auth path. It does not call DPD outside `DpdApiClient` and does not write checkout rates back to delivery tables.
- DPD checkout package input is built by the shared `src/Packaging/PackagingBuilder.php`. DPD `parcel[]` means packaging places, not cart items. The builder expands product quantities, splits items with any side over 49 cm into separate long-item parcels, aggregates <=50 cm3 small items into one synthetic block, optimizes identical groups into grid blocks, then uses fast deterministic 3D shelf/bin packing with `box_50_50_30` and `box_40_40_40`. It sends actual occupied dimensions, attempts one box and then two boxes, and falls back to stacked rows. Packaging weight is added per parcel through `PackagingWeightCalculator`. `unitLoad`, COD/НПП and fiscal receipts are not included.
- `src/Carriers/Dpd/DpdSettings.php` stores DPD runtime method titles, known service-code enabled flags/custom titles, and `dpd_runtime_enable_courier_rates`. Default enabled codes are `ECN,CSM,MXO`; unchecked-all means no DPD checkout rates. Legacy runtime pickup mode, delivery mode and method-title prefix settings are not rendered or read.
- `src/Carriers/Runtime/DpdQuoteCarrier.php` marks DPD options with `tariff_selector_group`, `checkout_group_id`, `selected_tariff_object`, `selected_tariff_title`, `pickup_method_title` and `courier_method_title`, so `src/Checkout/WooCommerce/NewShippingMethod.php` groups DPD services like CDEK/Russian Post into one method per delivery type with `tariff_variants`.
- `src/Checkout/Runtime/CheckoutOrchestrator.php` adds a DPD pickup entry whenever the DPD delivery service is active and adds the DPD courier entry only when `dpd_runtime_enable_courier_rates` is enabled.
- DPD pickup delivery sends `getServiceCostByParcels3` with `selfPickup=true`, `selfDelivery=true`, sender `pickup.terminalCode` and receiver `delivery.terminalCode`, returns a `DeliveryType::PICKUP` calculation rate, and requires a selected local DPD pickup point for checkout/order meta.
- DPD courier delivery sends a separate `getServiceCostByParcels3` request with `selfPickup=true`, `selfDelivery=false`, sender `pickup.terminalCode` and no `delivery.terminalCode`, returns `DeliveryType::COURIER`, and requires a courier address. If courier rates are disabled, `DpdQuoteCarrier` returns empty quote reason `courier_rates_disabled` without a SOAP call.
- `getServiceCost3` is not used because it does not match the current package-place `parcel[]` model.
- `DpdQuoteCarrier::quote_id()` includes receiver location, sender/receiver city IDs, sender/receiver terminalCode values, normalized parcel signature, parcel count, long-item parcel count, regular item count, total weight, dimensions, declared value, parcel dimensions, box limits, package builder source, `delivery_type`, fixed `selfPickup=true`, derived `selfDelivery`, courier-rates enablement, enabled service codes, calculation date and environment for diagnostics.
- `src/Checkout/Cache/QuoteCache.php` includes selected location, selected DPD terminalCode, package dimensions and declared value in the generic quote cache key so DPD quotes vary on receiver, terminal and parcel parameters that affect `getServiceCostByParcels3`.
- `src/Core/Plugin.php` registers `DpdQuoteCarrier` in the checkout runtime registry and registers `DpdShipmentAdapter` in `CarrierShipmentAdapterRegistry` for manual dry-run preview only. DPD does not appear in live shipment creation, status sync, label or cancellation flows.
- `tests/dpd/run-dpd-parcel-builder-smoke.php` covers DPD 3D packing, identical grid blocks, small-item aggregation, long items, mixed baskets, packaging weight per parcel, two-box split and stacked-rows fallback. `tests/dpd/run-dpd-checkout-runtime-smoke.php` covers disabled service, missing credentials, missing receiver cityId, grouped MAX/NDY mapping, enabled-code filtering, unchecked-all behavior, fixed terminal-origin pickup payload, courier-disabled no-call behavior, courier-enabled payload, split orchestrator entries, DPD parcel payload propagation, quote_id parcel/delivery type/courier settings, missing-cost skipping, service-level minimum price post-processing through the orchestrator, DPD runtime registry presence, and dry-run-only shipment adapter boundary.
- `docs/wdc-dpd-checkout-runtime.md` documents the 0.62.0 DPD checkout boundary. TerminalCode-aware runtime pricing is connected, while DPD shipment creation, cancellation, statuses, labels, COD/НПП, `unitLoad`, fiscal receipts, complex bin packing and new global carrier branching remain intentionally out of scope.

## DPD Tariff Calculation Foundation 0.57.0

- `src/Carriers/Dpd/DpdApiClient.php::getServiceCostByParcels3()` is the primary runtime low-level wrapper for the DPD calculator SOAP method. It uses `DpdEndpoints::SERVICE_CALCULATOR` (`calculator2`) and the existing `DpdApiClient::call()` path, so `DpdSoapRequest` remains the only place that adds `auth`. `getServiceCostByParcels2()` remains available as a legacy wrapper.
- `src/Carriers/Dpd/Tariff/DpdTariffRequest.php` and `DpdTariffParcel.php` remain as the legacy Parcels2 request DTOs used by low-level/smoke coverage. Runtime Parcels3 requests use `DpdTerminalCodeTariffRequest`.
- `src/Carriers/Dpd/Tariff/DpdTerminalCodeTariffRequestBuilder.php` builds the runtime `getServiceCostByParcels3` payload with `pickup.cityId`, `pickup.terminalCode`, `delivery.cityId`, optional `delivery.terminalCode`, `selfPickup`, `selfDelivery`, `declaredValue`, optional `serviceCode`/`pickupDate`, and `parcel[]` packaging-place weight/dimensions/quantity. It intentionally does not include credentials, extra services or WooCommerce objects.
- `src/Carriers/Dpd/Tariff/DpdTariffCalculationService.php` resolves sender city ID from DPD tariff settings/override or sender `location_id`, resolves receiver `location_id` through `DpdCityResolver`, calls the DPD API wrapper, catches `DpdException`, and returns `DpdTariffResult` without writing rates to delivery tables.
- `src/Carriers/Dpd/Tariff/DpdTariffOptionNormalizer.php` tolerates DPD SOAP bodies shaped as one object, arrays, or nested `return`/service fields and normalizes service code, name, cost, currency, delivery period/date, pickup/delivery flags and raw fields.
- `src/Carriers/Dpd/DpdSettings.php` stores DPD calculation settings (`dpd_tariff_sender_location_id`, optional `dpd_tariff_sender_dpd_city_id`, default parcel dimensions/weight and declared value) plus runtime titles/service-code settings. The removed display-only sender city name and one-shot tariff action result options are no longer part of current settings.
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` renders the `DPD Расчет` tab as settings-only: sender `location_id`, optional sender DPD cityId override, read-only sender location + DPD cityId summary, and default parcel values. The old `Тестовый расчет DPD` form/action/result block is removed.
- `src/Core/Plugin.php` registers DPD tariff builder/normalizer/calculation service for the DPD checkout quote carrier, plus the 0.63.0 DPD shipment adapter for manual dry-run preview only.
- `tests/dpd/run-dpd-tariff-calculation-smoke.php` covers payload building/auth separation, controlled sender/receiver cityId errors, fake API invocation, single/array response normalization, current admin settings boundaries, and the disabled DPD dry-run adapter boundary.
- `docs/wdc-dpd-tariff-calculation.md` documents the tariff boundary. Pickup points, order creation, statuses, labels, COD, `unitLoad`, receipts, cron and tariff sync are intentionally not implemented.

## DPD Delivery Codes And Geography Import 0.56.3

- `database/migrations/0030_create_location_delivery_codes.php` creates `wdc_location_delivery_codes` with `location_id` primary key, nullable `dpd_city_id`, nullable `updated_at`, and `dpd_city_id` index.
- `src/Locations/Storage/LocationDeliveryCodeRepository.php` is the storage boundary for delivery carrier codes tied 1:1 to `wdc_locations.id`. It supports `find_by_location_id`, `get_dpd_city_id`, `save_dpd_city_id`, `delete_by_location_id`, and `cleanup_orphans`.
- `src/Carriers/Dpd/DpdCityResolver.php` resolves DPD `cityId` for an existing WDC/FIAS/GAR `Location` from `wdc_location_delivery_codes.dpd_city_id` only. It requires `Location->id`, does not call live DPD geography APIs automatically, and returns an import/DaData/manual-mapping-required diagnostic message when no mapping exists.
- `src/Carriers/Dpd/DpdDuplicateCityResolver.php` remains isolated for future imported/API candidate matching. It scores candidates by FIAS GUID, GAR ID, countryCode, cityCode/KLADR, regionCode, postal code and city name, but is not used by the mapping-only resolver path.
- `src/Carriers/Dpd/DpdApiClient.php` exposes geography wrappers for `getCitiesCashPay` and `getPossibleExtraService`; these are low-level wrappers only and do not implement automatic city lookup business logic.
- `src/Carriers/Dpd/DpdGeographyDiagnosticService.php` provides admin-only DPD geography diagnostics and manual mapping save for existing locations. Manual mapping writes `dpd_city_id` through `LocationDeliveryCodeRepository`. The current diagnostic checks resolver/mapping state only and does not run live SOAP calls, mass enrichment or cron jobs.
- `src/Carriers/Dpd/Geography/DpdGeographyFtpClient.php` downloads the newest `GeographyNewDPD_*.csv` through SFTP when `ssh2` and encrypted DPD SFTP password are available; otherwise it returns a safe manual-upload message.
- `src/Locations/Storage/LocationRepository.php::dpd_location_index_rows()` reads only the active RU columns needed for DPD indexed matching, in chunks, instead of loading full `Location` objects or querying per CSV row.
- `src/Carriers/Dpd/Geography/DpdLocationIndex.php` builds reusable in-memory lookup maps for normalized FIAS, city FIAS, KLADR variants, city KLADR variants and conservative region+district+name+type keys. Duplicate keys are marked ambiguous and are not auto-matchable.
- `src/Carriers/Dpd/Geography/DpdGeographyCsvParser.php` supports full stream parsing plus step reads by byte offset/header columns for large `GeographyNewDPD_*.csv` files.
- `src/Carriers/Dpd/Geography/DpdGeographyMatcher.php` matches each DPD row against `DpdLocationIndex`; it no longer calls `LocationRepository` SQL lookup per CSV row.
- `src/Carriers/Dpd/Geography/DpdGeographyImportStateService.php` stores the current import job in `wdc_dpd_geography_import_state`, hides temp paths/staging table name from public state, and keeps only light job metadata, offsets, counters, timestamps, errors and last message.
- `src/Carriers/Dpd/Geography/DpdGeographyStageRepository.php` owns per-job `wdc_dpd_geography_stage_<job_hash>` tables. It creates/drops staging, upserts candidates, marks conflicts, and finalizes candidates into `wdc_location_delivery_codes` without touching future non-DPD carrier-code columns.
- `src/Carriers/Dpd/Geography/DpdGeographyImportService.php` starts SFTP/manual import jobs, builds the location index once, processes rows in limited AJAX steps, writes only to staging during import, finalizes the DPD-only refresh on EOF, stores the report in DPD settings, and removes CSV/index/staging temp resources on finish/reset.
- `src/Carriers/Dpd/Geography/WpDpdDaDataDeliveryClient.php` and `DpdDaDataDeliveryFallbackService.php` implement the admin-only single-location DaData delivery fallback using the shared DaData token pool and usage counters.
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` renders the separate DPD География tab, starts SFTP/manual import jobs, registers `wp_ajax_wdc_dpd_geography_import_status`, and displays the import progress/reset UI alongside manual mapping, diagnostics and DaData fallback.
- `assets/admin/dpd-geography-import.js` polls the DPD geography AJAX endpoint only on `page=wdc-delivery-services&service=dpd&tab=dpd_geography`, updates the progress bar and counters, and stops polling after finished/failed/cancelled.
- `tests/locations/run-location-delivery-codes-smoke.php` covers insert/update/read/delete/orphan cleanup for the new table.
- `tests/dpd/run-dpd-city-resolver-smoke.php` covers missing mapping, manual save, mapping reuse, API wrapper availability outside resolver, and shipment-adapter non-registration.
- `tests/dpd/run-dpd-location-index-smoke.php` covers unique FIAS matching, KLADR normalization, ambiguous name keys and index export/load.
- `tests/dpd/run-dpd-geography-import-smoke.php` covers Windows-1251 CSV parsing, indexed matching, idempotent duplicates, conflict rollback, final report storage and temp-file cleanup.
- `docs/wdc-dpd-geography.md` documents the geography scope and stage-2 constraints. DPD geography remains isolated from live shipment creation; checkout runtime and manual shipment preparation only read the resolved `dpd_city_id` through `DpdCityResolver`/saved order meta.

## DPD Foundation 0.54.0

- `src/Carriers/Dpd/DpdSettings.php` stores DPD environment, test/production client numbers, encrypted client keys, request timeout, debug flag and redacted dry diagnostic result in the existing settings/encryption layer.
- `src/Carriers/Dpd/DpdSoapClientInterface.php` is the required replaceable DPD SOAP transport boundary. `DpdApiClient` depends on the interface, while `DpdSoapClient` is only the current PHP `SoapClient` implementation.
- `src/Carriers/Dpd/DpdEndpoints.php` maps test/production WSDL URLs for `geography2`, `calculator2`, `order2`, `tracing`, `tracing1-1`, `event-tracking`, `label-print` and `delivery-management`.
- `src/DeliveryServices/DeliveryServiceRepository.php` and `DeliveryServiceManager.php` create the built-in `dpd` service disabled by default with RU country availability. The service is predefined and protected from deletion like other system services.
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` adds the DPD `Данные для входа` tab and a dry diagnostic action. The diagnostic checks credentials, endpoint selection and SOAP transport availability only; it does not call the DPD API.
- `src/Core/Plugin.php` registers DPD settings/API/transport/geography services, the checkout quote carrier and the 0.63.0 dry-run shipment adapter. Live DPD create/status/label flows are still disabled.
- `tests/dpd/run-dpd-foundation-smoke.php` covers disabled service creation, encrypted client key storage, redaction, endpoint selection, graceful missing transport diagnostic, quote carrier registration source, and disabled dry-run adapter registration.
- `docs/wdc-dpd-integration.md` documents the stage-1 boundary and future strategies for cityId, statuses, `unitLoad`, COD and receipts.

## CDEK Express Single Package Rates 0.47.0

- 0.53.1 shipment actions note: `src/Shipments/Cdek/CdekBarcodePrintService.php` now stores BARCODE print creation/check metadata and recreates pending print forms that remain `ACCEPTED`/`PROCESSING` beyond the recovery threshold, while preserving the READY cache. `src/Shipments/Admin/OrderShipmentsMetabox.php` returns `carrier_ui_payload()` after every shipment AJAX action, and `assets/admin/shipments-admin.js` normalizes that adapter payload before rendering buttons, fixing Russian Post create/update button state without carrier-specific JS branches.
- 0.53.0 carrier shipment actions note: `src/Shipments/Contracts/CarrierShipmentAdapterInterface.php` formalizes the order-shipment lifecycle action surface for carriers, and `src/Shipments/Application/CarrierShipmentAdapterRegistry.php` registers current adapters by `carrier_key`. `src/Shipments/Cdek/CdekShipmentAdapter.php` and `src/Shipments/RussianPost/RussianPostShipmentAdapter.php` now expose presentation, status payload, update/cancel/remove/manual attach and label-action hooks while delegating business logic to the existing services. `src/Shipments/Admin/OrderShipmentsMetabox.php` dispatches shipment action AJAX through the registry, and `src/Shipments/Application/ShipmentStatusAutoSyncService.php` uses adapter support/tracking/throttle/update methods for CDEK and Russian Post.
- 0.52.0 CDEK autosync/calculator note: `src/Shipments/Application/ShipmentStatusAutoSyncService.php` dispatches `carrier_key=cdek` through `CdekOrderStatusService`, applies universal order-status mapping after the shipment update, throttles CDEK status calls by 10 ms and writes `updates_by_carrier[cdek]` diagnostics. `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` renders the dedicated CDEK rules test calculator and labels sender city as the door-origin `from_location.city` setting.
- 0.51.3 CDEK label/status admin note: `src/Shipments/Admin/OrderShipmentsMetabox.php` renders one managed `Скачать этикетку` action for registered CDEK shipments. `assets/admin/shipments-admin.js` prepares BARCODE labels through AJAX polling, downloads READY PDFs with `fetch()` + Blob/Object URL, and resets the busy state after download starts. `src/Shipments/Cdek/CdekBarcodePrintService.php` splits preparation from final PDF streaming and caches READY print UUIDs for 50 minutes; the `admin-post` endpoint only downloads cached READY labels. `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` owns the `Статусы СДЭК` service tab, saves `CdekStatusMappingService::MAPPING_KEY`, and redirects `save_cdek_statuses` back to `cdek_statuses`; the general `ShipmentStatusesAdminPage` no longer renders CDEK mapping. `src/Shipments/Cdek/CdekStatusMappingService.php` contains labels/default mappings for all CDEK order status codes from Appendix 1.
- 0.50.5 CDEK tariff admin note: `src/Carriers/Cdek/Tariffs/CdekTariffRepository.php` exposes bulk management methods for managed CDEK tariffs, and `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` renders POST-only bulk buttons for deleting all CDEK tariffs or enabling/disabling all tariffs / one delivery-mode group. Bulk changes clear the delivery quote cache and redirect back to the CDEK tariffs tab with a count notice.
- 0.50.4 CDEK postamat note: `src/Carriers/Cdek/Tariffs/CdekTariffSyncService.php` now classifies postamat/locker tariff names as the same warehouse side used by PVZ/warehouse modes, so order creation continues to use `delivery_point` for postamats. `src/Pickup/Cdek/CdekDeliveryPointService.php` normalizes `POSTAMAT`, `LOCKER` and `POSTOMAT` deliverypoint types into `Постамат СДЭК` presentation fields, and `src/Shipments/Admin/OrderShipmentsMetabox.php` shows `Тип точки` for known CDEK pickup rows.
- 0.50.3 CDEK shipment direction note: `database/migrations/0029_add_cdek_tariff_delivery_mode.php` adds editable managed-tariff `delivery_mode`. `src/Carriers/Cdek/Tariffs/CdekTariffSyncService.php` fills it from API mode fields or tariff names, `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` exposes `Режим тарифа`, and `src/Shipments/Cdek/CdekCreateRequestBuilder.php` uses mode 1-4 to send the correct `from_location`/`shipment_point` and `to_location`/`delivery_point` combinations. `src/Orders/Application/OrderDeliveryAddressNormalizationService.php` shares the apartment/office/premise splitter with CDEK courier preparation, and `src/Shipments/Admin/OrderShipmentsMetabox.php` renders the CDEK recipient-door courier comment field.
- 0.50.1 CDEK courier shipment note: `src/Shipments/Cdek/CdekRecipientAddressPreparationService.php` prepares recipient courier addresses through the DaData suggestion client directly, reuses known CDEK city codes from normalized draft fields, `_wdc_delivery_calculation_data.api.cdek_to_city_code`, `_wdc_platform_rate_meta.location.cdek_to_city_code` or saved `request_payload_sanitized.to_location.code`, and only then falls back to the shared `CdekLocationResolver`. `src/Orders/Application/OrderDeliveryReplacementService.php` now writes CDEK city code into admin-saved calculation API data, `src/Shipments/Application/OrderShipmentDraftFactory.php` reads both calculation data and platform rate meta, and `src/Shipments/Cdek/CdekCreateRequestBuilder.php` continues to reject courier `to_location.code = 0`.
- 0.50.0 CDEK courier shipment note: `src/Shipments/Admin/OrderShipmentsMetabox.php` routes shipment address processing by carrier: Russian Post still uses `RussianPostAddressNormalizer`, while CDEK courier stores the prepared CDEK draft snapshot and can auto-prepare it before create. `src/Shipments/Application/OrderShipmentDraftFactory.php` carries `cdek_city_code`, city, postal code and delivery-address fields into the create request, and `src/Shipments/Cdek/CdekCreateRequestBuilder.php` uses them for `to_location` and blocks courier creation when the CDEK city code is missing.
- 0.49.1 shipment modal note: `src/Shipments/Admin/OrderShipmentsMetabox.php` renders the `Грузоместа` item table for all carriers with carrier-neutral data hooks and original base-item data for forced split merges. `assets/admin/shipments-admin.js` keeps backward-compatible CDEK hooks but drives the UI from generic shipment item/place selectors, parses comma/dot decimals for item price/dimensions, restores base rows from original data when a package is removed, and preserves manager edits when only an individual split row is deleted. `src/Shipments/Application/OrderShipmentDraftFactory.php` parses item cost and item dimensions through a comma-aware decimal helper while package place weight/dimensions remain whole numbers for carrier payloads.
- 0.49.0 shipment modal note: `src/Shipments/Admin/OrderShipmentsMetabox.php` renders CDEK sender pickup point code/address, exposes temporary sender pickup selection and product-search AJAX for manual package items; `assets/admin/shipments-admin.js` owns package summary recalculation, split row rebalancing/removal, manual item rows and sender pickup draft updates. `src/Shipments/Application/OrderShipmentDraftFactory.php` carries modal `shipment_point`/`shipment_point_address` into the CDEK create request, and `src/Shipments/Cdek/CdekCreateRequestBuilder.php` prefers that modal `shipment_point` over the saved default for warehouse-origin tariffs.
- `src/Carriers/Cdek/Tariffs/CdekTariffRepository.php` stores the managed CDEK tariff table (`tariff_code`, CDEK name, nullable weight/dimension limits, custom site title, delivery type, delivery mode, admin comment, active flag and last sync timestamp). Admin listing sorts active tariffs first, then by CDEK name and code. Nullable limit fields use dynamic insert/update formats so SQL `NULL` is preserved instead of being formatted as `0.000`.
- `src/Carriers/Cdek/Tariffs/CdekTariffSyncService.php` calls `GET /v2/calculator/alltariffs`, normalizes delivery modes into `pickup`/`courier`, stores the numeric CDEK direction mode (`1` door-door, `2` door-warehouse, `3` warehouse-door, `4` warehouse-warehouse), stores weight/dimension limits, fixes obvious mojibake in CDEK strings, builds sync diffs and applies updates without overwriting custom title/comment/active state.
- `database/migrations/0027_create_cdek_tariffs_table.php` creates `wp_wdc_cdek_tariffs`; `0028_add_cdek_tariff_limits.php` adds nullable limit columns on updates from the first tariff-management schema; `0029_add_cdek_tariff_delivery_mode.php` adds the numeric tariff direction mode.
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` renders the CDEK `Тарифы` tab, sync preview/confirmation, inline tariff editing, active flags, compact restriction display, Russian delivery-type labels `до ПВЗ` / `до двери`, editable `Режим тарифа`, the CDEK `Статусы СДЭК` mapping tab, and the CDEK `Правила` test calculator.
- `src/Carriers/Cdek/CdekSettings.php` stores the CDEK `shipment_point` setting with default `NSK69`, optional `cdek_shipment_point_address`, `cdek_sender_city_name` for door-origin `from_location.city`, and `cdek_sender_address` for door-origin `from_location`; the admin CDEK calculation tab renders sender city name before sender city code.
- `src/Carriers/Runtime/CdekCarrier.php` still prices through `POST /v2/calculator/tarifflist`, but uses the managed tariff row for title, delivery type and active/inactive filtering when a row exists. CDEK package payloads are built per `PackageItem` unit, with item dimensions and per-dimension CDEK defaults; aggregate `packaging_weight_g` is added once to the first item package unless packaging is already represented by a `WDC_PACKAGING` item. Eligible carts also receive a second single-package tarifflist pass after a conservative 50x50x30 cm fit check; new tariff codes are merged without duplicates, and CDEK-specific rate filtering removes duplicate-period and slower/more-expensive options before checkout output. Since 0.48.4, CDEK adds the configured insurance percent of discounted goods total to API `delivery_sum` before rules and saved “Базовая стоимость API”.
- `src/Shipments/Cdek/CdekOrderStatusService.php` reads CDEK order status from `entity.statuses[]` by newest non-deleted `date_time`, saves `planned_delivery_date`, saves actual delivery cost from `entity.delivery_detail.total_sum`, and exposes CDEK status/action/actual-cost payload data to `src/Shipments/Admin/OrderShipmentsMetabox.php`.
- `src/Checkout/Cache/DeliveryQuoteCacheManager.php` clears WDC quote transients, the runtime quote namespace, WooCommerce `shipping_for_package_*` session rates and WDC runtime rate/tariff session caches. It also maintains `wdc_delivery_rates_cache_version` and adds it to WooCommerce shipping packages, so the package hash changes globally after manual reset or CDEK tariff save/sync and existing customer checkout sessions recalculate rates without a cart change.
- `docs/wdc-cdek-insurance-audit.md` documents current insurance findings and the order-creation follow-up.

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
- `src/Carriers/Cdek/Api/CdekApiClient.php` now supports authorized JSON runtime requests in addition to OAuth connection checks, including `GET /v2/deliverypoints`, `POST /v2/orders`, `GET /v2/orders` and `GET /v2/orders/{uuid}`.
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
- `src/Carriers/Cdek` contains the CDEK foundation, tariff calculation, tariff-management and order-registration support: settings, separate encrypted test/production credentials, active-environment API base URL selection, OAuth token service/cache, API response/exception objects, WP HTTP adapter, destination city resolver, API client methods for `tarifflist`, `alltariffs`, locations, delivery points, `POST /v2/orders`, `GET /v2/orders`, `GET /v2/orders/{uuid}`, and CDEK BARCODE print methods (`POST /v2/print/barcodes`, status lookup and PDF download), plus the managed tariff repository/sync service.
- `src/Shipments/Cdek` contains the CDEK shipment adapter, request builder, BARCODE print service, CDEK status mapping service and order status service. It builds `POST /v2/orders` from the carrier-aware `Отправления` modal, stores `registration_pending`, checks async registration status by UUID/CDEK number/IM number, streams BARCODE PDFs without storing files, and saves a universal status code through configurable CDEK raw-status mapping edited in the CDEK service tab.
- `src/Shipments/Application/ShipmentBacklogService.php` owns cancel/manual-attach rules. Cancel uses `backlog_order_id` and is allowed only for operation `28 / Присвоение идентификатора`; manual attach searches by barcode in backlog first, falls back to shipment search, saves `backlog_order_id` when returned, then attempts the first Tracking API refresh.
- `src/Shipments/RussianPost/RussianPostTrackingStatusMapper.php` contains the code-fixed mapping generated from `status pocha.xlsx`. Unknown operation/attribute pairs map to `unknown` / `не определён`.
- The 0.36.1 mapping correction maps selected pickup operations including `8:2`, `12:1..12:31`, and `42:1..42:30` to `ready_for_pickup`, and maps `8:15` plus `8:18` to `handed_to_courier`.
- The 0.36.2 mapper fallback treats empty, absent, `0`, and `-` attributes as compatible with `type:-` mappings when no exact `type:attr` key exists. This covers Russian Post operations `28:-` (`создан в ТК`) and `46:-` (`отменён`).
- `src/Shipments/Application/ShipmentStatusUpdateService.php` updates `_wdc_shipments` for the Russian Post domestic shipment, saves universal status fields plus raw carrier operation fields, and then invokes order status mapping through `ShipmentOrderStatusMappingService`.
- `src/Shipments/Application/ShipmentOrderStatusMappingService.php` reads `shipment_status_order_status_mapping_enabled` and `shipment_status_order_status_mapping`, validates target statuses against `wc_get_order_statuses()`, updates WooCommerce orders with `update_status()`, and adds a private WDC order note on successful automatic changes.
- `src/Shipments/Application/ShipmentStatusAutoSyncService.php` scans WooCommerce orders by selected order statuses, reads `_wdc_shipments`, skips terminal universal statuses and missing tracking numbers, collects diagnostics including order status mapping counters, and dispatches by `carrier_key` through `CarrierShipmentAdapterRegistry`. It supports current per-order adapters for `russian_post_domestic` and `cdek`; CDEK update still delegates to `CdekOrderStatusService`, applies universal order-status mapping after update, and throttles CDEK status calls with a 10 ms pause. DPD is handled before the per-order loop with one global `getEvents` sync, then DPD shipments in the selected order scan are counted as `carrier_global_sync` skips.
- `src/Shipments/Application/ShipmentStatusAutoSyncCron.php` registers WP Cron hook `wdc_shipment_status_autosync`, schedule `wdc_every_6_hours`, and keeps the event scheduled even when autosync is disabled.
- `src/Shipments/Admin/ShipmentStatusesAdminPage.php` renders `WDC -> Статусы` with main autosync settings, universal shipment status to WooCommerce order status mapping, diagnostics, and the manual run action. CDEK raw-status mapping is edited inside `WDC -> Службы доставки -> СДЭК -> Статусы СДЭК`.
- `src/Shipments/Admin/OrderShipmentsMetabox.php` exposes shared AJAX actions for update/cancel/remove/manual attach and delegates them through the carrier adapter registry. `assets/admin/shipments-admin.js` updates the status block without reloading the order page, closes the create modal after success, shows a local 10-second toast, and starts the first status refresh automatically.

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
- `WooCommerceRateMapper` exposes top-level `pickup_family` for pickup rates, and `CheckoutRateRenderer` normalizes real WooCommerce meta-data key/value entries before rendering the shared checkout pickup UI for `requires_pickup_point=true` / `delivery_type=pickup` rates, including `yandex_pickup` with `data-shipping-method-id=yandex_pickup`, `data-wdc-pickup-checkout`, `data-wdc-pickup-open`, standard `wdc_pickup_*` hidden fields and `wdc_pickup_family=yandex_delivery:pickup`; `CheckoutDeliveryTypeSelector` keeps session capture compatibility but no longer registers a duplicate shipping-rate HTML renderer;
- `PickupMapCheckout` localizes raw `pickupSelections` / `pickupSelectionsRaw` dictionaries plus the renderable-card `selectedPickupPoints` subset and `activePickupFamily`, while `CheckoutPickupPointRestController` returns `pickup_selections` / `pickupSelections` and `active_pickup_family` from state, save and reset responses;
- `assets/frontend/pickup-map/wdc-pickup-checkout.js` keeps `pickupSelections` as the restore source of truth, restores the active family bucket on boot/reload from localized `activePickupFamily`/`selectedPickupPoint`, reruns `boot()` on WooCommerce `updated_checkout`, opens `[data-wdc-pickup-open]` through delegated document click handling so ajax-recreated buttons remain clickable, maps `yandex_pickup` to `yandex_delivery:pickup` for family matching/pickup-rate detection, triggers `update_checkout` after saving `yandex_delivery:pickup`, merges REST/localized dictionaries without replacing complete payloads by code-only points, starts background prefetch for the active pickup family (including CDEK city-code requests) and hides inactive family cards without clearing their saved selection;
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
- CDEK tariffs are edited on `WDC -> Службы доставки -> СДЭК -> Тарифы`; sync uses `GET /v2/calculator/alltariffs`, stores weight/dimension limits, and runtime CDEK rates prefer `custom_title` over the CDEK tariff name.
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
- `tests/cdek/run-cdek-order-creation-smoke.php` covers CDEK order payload rules, package/item assignment, validation, async accepted status, status polling outcomes, idempotency and CDEK toast/status payloads;
- demo и fixture data в `tests/fixtures`;
- прямые PHP entrypoints, например `tests/domain/run-domain-smoke.php`, `tests/checkout/run-checkout-smoke.php` и `tests/runtime/run-no-legacy-smoke.php`.
### Yandex Delivery geo matching 0.77.0

- `src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoMatchScorer.php` performs deterministic region/district/type-aware scoring for `location/detect` candidates. As of 0.80.0 it canonicalizes administrative aliases (`респ`, `р-н`, `МО`, `ГО`, `АО`) and settlement type synonyms (`пгт`, `пос`, `ст`, `х`, `с`, `д`) before scoring, and emits `type_equivalent` diagnostics when equivalent types use different wording.
- `src/Carriers/YandexDelivery/Geo/YandexDeliveryGeoDistance.php` contains a pure haversine utility reserved for future distance-based validation.
- `YandexDeliveryGeoMappingService::build_search_query()` prefers `Location::display_name` and only falls back to manual query assembly when it is empty.

- src/Carriers/YandexDelivery/Pickup/YandexDeliveryCheckoutPickupPointFormatter.php converts local Yandex PVZ rows into the shared checkout pickup point shape (carrier_key=yandex_delivery, point_code/platform_station_id, display address/name/city/region/coordinates/schedule/operator fields and safe snapshot without raw JSON).
- src/Pickup/Rest/PickupPointsRestController.php and CheckoutPickupPointRestController.php support carrier=yandex_delivery for listing/searching and saving the selected station in the common pickup session bucket. CheckoutSessionManager maps the legacy yandex_pickup rate id to yandex_delivery:pickup so the common picker bucket works with the existing Yandex rate id.
- src/Checkout/WooCommerce/NewShippingMethod.php passes both pickup_selection and pickup_selections into QuoteRequest customer_context. src/Carriers/Runtime/YandexDeliveryCarrier.php reads pickup_selections['yandex_delivery:pickup'] before the global pickup_selection, so a saved Yandex platform_station_id is restored even after another carrier becomes the current global selection; selected pricing records pickup_source=selected, while representative PVZ remains the fallback with pickup_source=representative.
- src/Checkout/WooCommerce/CheckoutValidation.php requires a Yandex PVZ for yandex_pickup, rejects pickup selections from other carriers, and skips PVZ validation for yandex_courier. OrderShippingMetaPersister saves canonical _wdc_pickup_* meta plus Yandex aliases including _wdc_yandex_delivery_pickup_platform_station_id.
