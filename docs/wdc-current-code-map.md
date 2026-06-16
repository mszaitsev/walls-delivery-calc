# Карта текущего кода

## DPD Checkout Runtime 0.58.1

- `src/Carriers/Runtime/DpdQuoteCarrier.php` is the DPD checkout quote carrier registered in `CarrierRegistry` under `carrier_key=dpd`. It returns rates only when the built-in DPD delivery service is enabled by the common service settings, active-environment credentials are complete, the receiver `location_id` is known, receiver `dpd_city_id` is mapped, and DPD `getServiceCostByParcels3` returns numeric-cost options.
- The runtime reuses `src/Carriers/Dpd/Tariff/DpdTariffCalculationService.php`, `DpdCityResolver`, `DpdSettings` and the existing SOAP wrapper/auth path. It does not call DPD outside `DpdApiClient` and does not write checkout rates back to delivery tables.
- DPD checkout package input is minimal and aggregate-only: total WooCommerce package weight when present, DPD default weight fallback, package dimensions or DPD default dimensions, and declared value from package/order total or DPD default. Cart item composition, `unitLoad`, COD/НПП and fiscal receipts are not included.
- `src/Carriers/Dpd/DpdSettings.php` stores DPD runtime method titles, known service-code enabled flags/custom titles, sender pickup mode and receiver delivery mode. Default enabled codes are `ECN,CSM,MXO`; unchecked-all means no DPD checkout rates.
- `src/Carriers/Runtime/DpdQuoteCarrier.php` marks DPD options with `tariff_selector_group`, `checkout_group_id`, `selected_tariff_object`, `selected_tariff_title`, `pickup_method_title` and `courier_method_title`, so `src/Checkout/WooCommerce/NewShippingMethod.php` groups DPD services like CDEK/Russian Post into one method per delivery type with `tariff_variants`.
- DPD terminal delivery mode sets `selfDelivery=true`, returns a `DeliveryType::PICKUP` calculation rate, keeps `requires_pickup_point=false`, and stores `dpd_pickup_points_not_implemented=true` until DPD pickup point selection exists.
- `DpdQuoteCarrier::quote_id()` includes receiver location, sender/receiver city IDs, weight, dimensions, declared value, pickup/delivery modes, enabled service codes, calculation date and environment for diagnostics.
- `src/Checkout/Cache/QuoteCache.php` includes selected location, package dimensions and declared value in the generic quote cache key so DPD quotes vary on the receiver and parcel parameters that affect `getServiceCostByParcels3`.
- `src/Core/Plugin.php` registers `DpdQuoteCarrier` in the checkout runtime registry only. It still registers no `DpdShipmentAdapter` in `CarrierShipmentAdapterRegistry`, so DPD does not appear in shipment creation/metabox actions.
- `tests/dpd/run-dpd-checkout-runtime-smoke.php` covers disabled service, missing credentials, missing receiver cityId, grouped MAX/NDY mapping, enabled-code filtering, unchecked-all behavior, terminal modes, quote_id dimensions, missing-cost skipping, service-level minimum price post-processing through the orchestrator, DPD runtime registry presence, and shipment adapter absence.
- `docs/wdc-dpd-checkout-runtime.md` documents the 0.58.1 boundary. Pickup points, parcel shop selection/map, postamats, shipment creation, cancellation, statuses, labels, COD/НПП, `unitLoad`, fiscal receipts and new global carrier branching remain intentionally out of scope.

## DPD Tariff Calculation Foundation 0.57.0

- `src/Carriers/Dpd/DpdApiClient.php::getServiceCostByParcels3()` is the low-level wrapper for the DPD calculator SOAP method. It uses `DpdEndpoints::SERVICE_CALCULATOR` (`calculator2`) and the existing `DpdApiClient::call()` path, so `DpdSoapRequest` remains the only place that adds `auth`.
- `src/Carriers/Dpd/Tariff/DpdTariffRequest.php` and `DpdTariffParcel.php` describe the admin diagnostic tariff input: sender/receiver DPD city IDs, one parcel, declared value, pickup/delivery mode flags, optional service code and pickup date.
- `src/Carriers/Dpd/Tariff/DpdTariffRequestBuilder.php` builds the `getServiceCostByParcels3` payload with `pickup.cityId`, `delivery.cityId`, `selfPickup`, `selfDelivery`, `declaredValue`, optional `serviceCode`/`pickupDate`, and `parcel[]` weight/dimensions/quantity. It intentionally does not include credentials or WooCommerce objects.
- `src/Carriers/Dpd/Tariff/DpdTariffCalculationService.php` resolves sender city ID from DPD tariff settings/override or sender `location_id`, resolves receiver `location_id` through `DpdCityResolver`, calls the DPD API wrapper, catches `DpdException`, and returns `DpdTariffResult` without writing rates to delivery tables.
- `src/Carriers/Dpd/Tariff/DpdTariffOptionNormalizer.php` tolerates DPD SOAP bodies shaped as one object, arrays, or nested `return`/service fields and normalizes service code, name, cost, currency, delivery period/date, pickup/delivery flags and raw fields.
- `src/Carriers/Dpd/DpdSettings.php` stores DPD tariff calculator settings (`dpd_tariff_sender_location_id`, `dpd_tariff_sender_dpd_city_id`, display sender city name, default parcel dimensions/weight, declared value) and the one-shot visible tariff action result.
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` adds the `DPD Расчет` tab with diagnostic sender/default package settings and a nonce/capability-protected test calculator. The tab displays success/failure, raw returned option count, normalized service list and debug raw payload/response when DPD debug is enabled.
- `src/Core/Plugin.php` registers DPD tariff builder/normalizer/calculation service for admin diagnostics and the DPD checkout quote carrier. It still registers no DPD shipment adapter in `CarrierShipmentAdapterRegistry`.
- `tests/dpd/run-dpd-tariff-calculation-smoke.php` covers payload building/auth separation, controlled sender/receiver cityId errors, fake API invocation, single/array response normalization, visible admin result storage, and DPD shipment-adapter non-registration.
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
- `docs/wdc-dpd-geography.md` documents the geography scope and stage-2 constraints. DPD geography remains isolated from shipment adapters; checkout runtime only reads the resolved `dpd_city_id` through `DpdCityResolver`.

## DPD Foundation 0.54.0

- `src/Carriers/Dpd/DpdSettings.php` stores DPD environment, test/production client numbers, encrypted client keys, request timeout, debug flag and redacted dry diagnostic result in the existing settings/encryption layer.
- `src/Carriers/Dpd/DpdSoapClientInterface.php` is the required replaceable DPD SOAP transport boundary. `DpdApiClient` depends on the interface, while `DpdSoapClient` is only the current PHP `SoapClient` implementation.
- `src/Carriers/Dpd/DpdEndpoints.php` maps test/production WSDL URLs for `geography2`, `calculator2`, `order2`, `tracing`, `tracing1-1`, `event-tracking`, `label-print` and `delivery-management`.
- `src/DeliveryServices/DeliveryServiceRepository.php` and `DeliveryServiceManager.php` create the built-in `dpd` service disabled by default with RU country availability. The service is predefined and protected from deletion like other system services.
- `src/DeliveryServices/Admin/DeliveryServicesAdminPage.php` adds the DPD `Данные для входа` tab and a dry diagnostic action. The diagnostic checks credentials, endpoint selection and SOAP transport availability only; it does not call the DPD API.
- `src/Core/Plugin.php` registers DPD settings/API/transport/geography services and, as of 0.58.0, the checkout quote carrier. It does not add a DPD shipment adapter.
- `tests/dpd/run-dpd-foundation-smoke.php` covers disabled service creation, encrypted client key storage, redaction, endpoint selection, graceful missing transport diagnostic, quote carrier registration source, and shipment adapter absence.
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
- `src/Shipments/Application/ShipmentStatusAutoSyncService.php` scans WooCommerce orders by selected order statuses, reads `_wdc_shipments`, skips terminal universal statuses and missing tracking numbers, collects diagnostics including order status mapping counters, and dispatches by `carrier_key` through `CarrierShipmentAdapterRegistry`. It supports current adapters for `russian_post_domestic` and `cdek`; CDEK update still delegates to `CdekOrderStatusService`, applies universal order-status mapping after update, and throttles CDEK status calls with a 10 ms pause.
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
- `PickupMapCheckout` localizes raw `pickupSelections` / `pickupSelectionsRaw` dictionaries plus the renderable-card `selectedPickupPoints` subset and `activePickupFamily`, while `CheckoutPickupPointRestController` returns `pickup_selections` / `pickupSelections` and `active_pickup_family` from state, save and reset responses;
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
