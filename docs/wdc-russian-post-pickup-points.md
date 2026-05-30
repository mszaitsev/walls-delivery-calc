# Russian Post Pickup Points

Version: 0.26.0.

Version 0.26.0 adds checkout pickup address search above the map. The modal search field now calls `GET /wp-json/wdc/v1/points/address-search` with `query`, optional `location_id`, and `country_code`. Free-form address, street, house, and postal-index queries are accepted. Address queries are resolved through DaData, then the map moves to the resolved coordinates and the pickup list is rebuilt from nearest points.

DaData address search is not a separate HTTP client. It uses the existing `AddressSuggestionClientInterface` implementation (`DaDataSuggestionClient`) and the shared `DaDataTokenPool`, so every non-cached address request participates in the same token rotation, daily usage counters, exhausted-token tracking, and daily limits as checkout DaData suggestions and location coordinate enrichment. If a checkout location is known, its id/country context is sent to the endpoint and the DaData request uses location filters/restricted value where possible.

Address-search results are cached for 24 hours by normalized query plus `location_id` and country code. Cache hits return coordinates and nearest points without calling DaData and without increasing token counters.

Six-digit postcode search is handled locally and never calls DaData. The backend first returns active pickup points with that exact postcode. If none exist, it can use the coordinates of a local location row with the same postal code and return nearest pickup points around that location. If neither exists, the endpoint returns a normal not-found payload. This postcode path remains available when all DaData tokens are exhausted.

When no DaData token is available for address search, the endpoint returns `address_search_available=false`. The frontend changes the search input placeholder to "Сейчас работает поиск только по почтовому индексу", limits input to digits, and shows "Поиск доступен только по индексу". Exactly six digits still run the local postcode search.

Successful address search renders a separate red `search` marker at the found address. Pickup markers remain blue unless previewed/active, and the search marker never participates in pickup selection. Distances in the list are recalculated from the found address, not from the selected city center, and the list shows a compact "Найден адрес" block with the nearest pickup distance.

Version 0.25.13 avoids duplicate popup close calls. Empty map clicks still mark the popup as manually closed and call provider `closePopup()`, but provider `popupclose`/`balloonclose` events only mark the state because the popup is already closed.

Version 0.25.12 adds an explicit manual-close state for the map balloon. The popup opens on first visible render for a committed point, on marker click, and on list-card click. If the customer closes it with the balloon control or clicks empty map space, `popupManuallyClosed` prevents bbox reloads from reopening it; the active marker and list row can remain highlighted until another marker/list action resets the flag.

Clustering is wider so overlapping pins aggregate sooner. Leaflet now clusters through zoom 17 with a 64px grid and shows individual pins only at zoom 18+. Yandex uses `ymaps.Clusterer` through zoom 17 with `gridSize: 80`, then bypasses the Clusterer at zoom 18+.

Version 0.25.11 makes marker color depend only on preview/active state. Normal single pins remain blue; the active preview pin is red in both Leaflet and Yandex. A previously saved checkout point is localized as `initialContext.selectedPoint`, then becomes `previewPoint` when it is present in the current visible point set, so reopening the map highlights the saved point without firing `wdc:point-selected`.

Version 0.25.10 removes the temporary Leaflet address-only popup from marker creation. Leaflet binds popups only in `openPointPopup(point, html)`, where the full pickup card HTML and auto-pan options are available. The compact status and old fallback confirm button remain technical event/status surfaces only and are visually hidden, so the customer sees a single shared list footer button plus the balloon action. Active-row scrolling now uses `getBoundingClientRect()` against the list scroll container and never calls `scrollIntoView()`, preventing checkout page scroll jumps.

Version 0.25.8 removes per-row select buttons from the visible pickup list. Rows are now navigation only: clicking a row opens the point balloon and marks it as preview without changing checkout. A single sticky/shared list footer button, `Выбрать этот пункт`, confirms the current preview point; it is disabled before preview and changes to a selected/choose prompt when appropriate. The balloon button continues to confirm immediately.

When a marker opens a preview, the side list scrolls its matching row into view inside the list container only. List-row preview no longer calls the provider focus/center method, so the map does not jump to the point. Leaflet opens popups with `autoPan`, `keepInView`, and padding; Yandex placemarks use balloon auto-pan, so the map only nudges when the balloon would be outside the viewport.

Cluster behavior now has a high-zoom escape hatch. Leaflet's grid clustering returns individual points at zoom 18 and above. Yandex uses `ymaps.Clusterer` below zoom 18 and adds placemarks directly at zoom 18+, keeping nearby points selectable on the map; when coordinates are identical, the list remains the reliable selection path.

Version 0.25.7 gives preview state priority over committed selection for map visuals. When `committedPoint = A` and `previewPoint = B`, and both are visible, B owns the active marker and open balloon while A remains the checkout selection and keeps the selected list state. If B disappears after bbox reload, preview is cleared; in 0.25.11 a visible committed point is promoted back into preview instead of receiving a separate committed marker color. Reloads still reopen the balloon automatically when the preview point remains visible.

Version 0.25.6 changes pickup type settings to one customer-facing label per type. The `Типы пунктов выдачи` block now shows only `Использовать` and `Название в карточке/баллоне/списке` for OPS, PVZ, and APS.

Map markers are textless. Single pickup points render as blue pins with a white center and a blue tail, matching the map-style marker reference. Clusters render as white circles with a thicker blue border and a dark count. The frontend still uses the configured type label in the list and balloon card, but never inside the marker itself.

The map state is now explicitly split into preview and committed selection. Marker clicks and list-row clicks set `previewPoint`, highlight the marker and list row, and open the balloon without dispatching `wdc:point-selected`. The balloon `Выбрать этот пункт` button and the shared list footer button set `committedPoint`, dispatch `wdc:point-selected`, update the compact status, and save the point to checkout immediately. The modal footer confirm button remains a fallback for a committed point, not a required second step.

Balloon state is resilient during map movement. Bbox reloads re-render markers and reopen the balloon when the preview or committed point is still part of the new visible point set. Dragging, zooming, and bounds changes do not close the balloon. Clicking empty map space closes only the preview popup and keeps the committed checkout point.

Version 0.25.4 moves the point details card from the side/bottom panel into the map popup/balloon. Marker clicks and list-row clicks both open the map popup for that point. The popup contains the customer-facing type label, address, work time, clean description when present, and the `Выбрать этот пункт` action. Pressing that popup button confirms the point, dispatches the existing `wdc:point-selected` event, updates the list selected state, and keeps the external confirm button available as a fallback.

The side/bottom panel is no longer the primary details surface; it only shows compact status such as `Выберите пункт на карте или в списке.` or `Выбран: ...`. The visible list remains as navigation beside the map on desktop and below it on mobile, sorted and capped as before, and opens the same popup instead of duplicating the full card.

Leaflet and Yandex now use matching custom HTML marker visuals. Single points render as textless blue pins with a white center and a blue tail. Clusters render as blue-outlined white circles with the count, without a tail. Leaflet uses `divIcon` for pins and grid-cluster markers; Yandex uses `ymaps.templateLayoutFactory.createClass` for placemarks and `clusterIconLayout` for cluster circles.

Version 0.25.2 adds pickup type controls for `russian_post_domestic_pickup` on the delivery service page, tab `ПВЗ / ОПС`, block `Типы пунктов выдачи`. OPS, PVZ, and APS currently have `Использовать` and `Название в карточке/баллоне/списке`.

`Название в карточке/баллоне/списке` is the customer-facing type name shown in the popup card and visible list rows. Defaults are OPS `Отделение Почты России`, PVZ `Пункт выдачи`, and APS `Почтомат`; markers do not render text.

At least one type must remain enabled. Saving an all-disabled configuration automatically enables OPS. The Russian Post `/points` and `/points/search` endpoints apply enabled types to map data; when REST also receives `type[]`, the effective filter is the intersection of requested and enabled types.

Version 0.25.1 separates preview state from confirmed pickup selection. The initial search fallback can render a point card and a soft list preview, but only an explicit marker or list-row click sets `selectedPoint`, enables the confirm button, highlights the row with `active selected`, and dispatches `wdc:point-selected`.

## Pickup Map UX

Version 0.25.0 adds a visible pickup-point list to the checkout modal. On desktop the map and list sit side by side; on mobile the map is above the list to avoid horizontal crowding. Every bbox response refreshes map markers and the list. The list is capped to the first 100 sorted points for DOM performance, while all returned points remain available to the map marker/cluster layer.

When the modal has `initialContext.lat/lng`, each point gets a haversine distance from the selected settlement center and the list is sorted by that distance. Distances render as meters below 1 km, for example `450 м`, and as one-decimal kilometers from 1 km, for example `1.2 км`. If the settlement has no usable coordinates, ordering falls back to postcode/address with the original response order as a stable tie-breaker.

Selection is synchronized both ways. Marker clicks preview the point and open the map popup; list-row clicks call the provider focus method, center/zoom the map on the point, and open the same popup. The selected row receives both `active` and `selected` classes only after the popup button confirms the point; previewed rows may receive the softer `preview` class.

The popup card renders a compact customer-facing Russian Post card: title/postcode, configured point type label, address, work time, and a readable description only when present. Empty fields and technical descriptions such as `0.000000` are suppressed.

Provider adapters now expose the richer selection surface used by `wdc-pickup-map.js`: `renderMarkers(points, options)`, `setActivePoint(pointId)`, `focusPoint(point)`, `openPointPopup(point, html)`, `closePopup()`, and popup select delegation while keeping the older methods intact. Leaflet renders local HTML `divIcon` markers with type CSS classes and a lightweight screen-grid clusterer; cluster markers are numbered circles and click to fit/zoom the grouped bounds. Yandex renders custom HTML placemark layouts, uses `ymaps.Clusterer` for numbered aggregate circles, and avoids standard `islands` marker presets for single pickup points.

## Pickup Map Providers

Version 0.24.2 disables Leaflet's built-in attribution control in the pickup provider with `attributionControl: false`, removing the standard lower-right attribution block from the pickup modal. Yandex Maps behavior is unchanged.

Version 0.24.1 hardens the Yandex provider's async startup. `setCenter()` before Yandex API readiness updates `pendingCenter`, queued markers render after `ymaps.Map` is created, `clearMarkers()` before readiness clears queued marker data, `fitToViewport()` runs after creation, and `boundsChanged()` is called manually once the map is ready so bbox loading starts from the actual final center.

Version 0.24.0 makes the checkout pickup modal map provider configurable in `Калькулятор доставок -> Настройки -> Карта ПВЗ`.

Available providers:

- `OpenStreetMap / Leaflet`, the default and backward-compatible fallback.
- `Яндекс.Карты`, enabled by selecting the provider and entering a Yandex Maps API key.

The Yandex Maps API key is used only when `Яндекс.Карты` is selected. The saved key is not rendered back into the admin HTML field; an empty input keeps the existing key, and the `Очистить ключ Яндекс.Карт` checkbox removes it.

Frontend map code now goes through provider adapters in `assets/frontend/pickup-map/providers/`:

- `wdc-map-provider-leaflet.js`
- `wdc-map-provider-yandex.js`

Both adapters expose `create(container, options)`, `setCenter(lat, lng, zoom)`, `renderMarkers(points, options)`, `setActivePoint(pointId)`, `focusPoint(point)`, `clearMarkers()`, `fitToMarkers()`, `destroy()`, `onPointClick(callback)`, and `invalidateSize()`. `wdc-pickup-map.js` owns pickup REST loading, visible-list sorting, selected-card rendering, search, and request cancellation, while provider files own map-specific markers, clusters, viewport changes, cleanup, and size invalidation.

Asset loading is provider-specific. Leaflet mode enqueues local `assets/vendor/leaflet/leaflet.css`, `assets/vendor/leaflet/leaflet.js`, and the Leaflet provider adapter. Yandex mode does not enqueue Leaflet; it enqueues the Yandex provider adapter, which loads `api-maps.yandex.ru` only when the localized config says a Yandex key is present. If Yandex is selected without a key, checkout does not break and the modal shows: `Для Яндекс.Карт не задан API key. Выберите OpenStreetMap или укажите ключ в настройках.`

## Location Coordinates

Version 0.23.12 keeps the coordinate-fill DaData query intentionally small: only `postal_code` and `display_name` are joined. If `postal_code` is empty, the query is just `display_name`; if `display_name` is empty, the row is skipped as `empty_query`. The batch no longer appends `region_name`, `district_name`, `city_name`, `settlement_name`, or `place_name` as separate fragments.

Coordinate-fill status now explains skipped rows with `skipped_empty_query`, `skipped_no_dadata_success`, `skipped_no_coordinates`, and `skipped_invalid_coordinates`. The aggregate `skipped` counter still increases, and the job records `last_skip_reason` plus `last_dadata_message` for the latest skipped DaData case.

Version 0.23.11 adds a mass coordinate fill tool on the locations admin page, `?page=wdc-platform-locations`. The former "Заполнение почтовых индексов через DaData" block is now "Заполнение информации через DaData" and shows both postal-code and coordinate counters, including "координаты есть" and "координат нет".

The new "Получить координаты через DaData" button uses the same AJAX batch/progress pattern as "Получить индексы через DaData": start creates a job state, step requests process small batches, and the JSON status includes `status`, `phase`, `started_at`, `finished_at`, `processed`, `updated`, `skipped`, `failed`, `errors`, `last_id`, `cursor`, and `current_batch`. Missing coordinates are rows where `latitude` or `longitude` is NULL or exactly `0.0000000`; existing valid coordinates are not overwritten.

The batch starts with city rows (`place_type` city markers such as `г` / `г.`), then continues through the remaining RU locations. For each row it builds a DaData city query from postcode, region/district, display name, city/settlement/place fields, reads `geo_lat` and `geo_lon`, and persists them with the HPOS-independent local location repository method `LocationRepository::update_coordinates()`. This prepares stable city coordinates for the checkout pickup map so the map can start near the selected city without adding broader frontend search logic.

## Checkout Map Fixes

Version 0.23.4 updates checkout DOM state after DaData coordinate enrichment without a page reload. The existing nonce-protected `GET /wp-json/wdc/v1/checkout/state` response includes `city_context`; after WooCommerce `updated_checkout`, frontend code refreshes that context, writes fresh `wdc_platform_location_lat/lng/postcode/display_name` hidden fields, and stores a runtime `currentContext`.

The frontend also prefetches the initial pickup points after `updated_checkout` when `russian_post_domestic_pickup` is active for an RU destination. If coordinates are known it loads a small bbox around them; otherwise it searches by the current query, then loads a bbox around the first result. The cache key is based on context coordinates/query/postcode/display name and is cleared on destination changes, so old-city points are not reused. The map accepts `initialContext.preloadedPoints` and renders those markers immediately on modal open before the usual bbox refresh completes.

Version 0.23.3 fixes stale startup context after WooCommerce `updated_checkout`. The frontend no longer trusts only the page-load localized `window.wdcPickupCheckout.initialContext`; every modal open recomputes context from current DOM hidden city picker fields first, visible checkout fields second, and localized config last. This keeps the map on the newly selected city after AJAX recalculation without a full page reload.

Version 0.23.2 prevents the modal from loading the Novosibirsk bbox before resolving the checkout destination. Startup order is now: saved RU `city_context` coordinates, then an initial `/points/search` by postcode/city or selected location display name, then the Novosibirsk fallback only when no coordinates and no query are available. Initial search centers the map and may show a preview card, but it does not confirm a pickup point; confirmation still requires an explicit marker click and button press.

When the checkout city picker selects or resolves a local location without usable coordinates, checkout address runtime asks the existing DaData suggestion client for city coordinates, stores `geo_lat/geo_lon` through `LocationRepository::update_coordinates()`, and saves `lat/lng` into the WooCommerce session `city_context`. This enrichment runs during city selection/resolve, not when the map opens. If DaData has no coordinates or the request fails, checkout does not fail; the map uses the initial search fallback.

Checkout state endpoints remain nonce-protected: `GET /wp-json/wdc/v1/checkout/state`, `POST /wp-json/wdc/v1/checkout/pickup-point`, and `DELETE /wp-json/wdc/v1/checkout/pickup-point` all require `X-WP-Nonce`. Changing city, country, or postcode resets pickup selection; switching shipping methods only hides or shows the UI and keeps the saved selection in session.

Version 0.23.1 keeps one Leaflet copy at `assets/vendor/leaflet/` and enqueues `assets/vendor/leaflet/leaflet.css` plus `assets/vendor/leaflet/leaflet.js`.

Checkout state is private checkout state now: `GET /wp-json/wdc/v1/checkout/state`, `POST /wp-json/wdc/v1/checkout/pickup-point`, and `DELETE /wp-json/wdc/v1/checkout/pickup-point` all require the WordPress REST nonce in `X-WP-Nonce`. The public point directory endpoints remain public: `/points`, `/points/search`, and `/points/{id}`.

When the modal opens, the map chooses its initial viewport in this order: saved checkout city coordinates from session context, current RU checkout postcode/city fields, then the Novosibirsk fallback. If only postcode/city is available, the frontend searches the local point endpoint and centers on the first found point without confirming it automatically; the customer must still click a marker and press "Выбрать этот пункт".

Pickup reset is destination-driven. Changing city, country, or postcode clears the selected pickup point and UI. Switching between shipping methods only hides or shows the pickup block; it does not clear session selection, so returning to `russian_post_domestic_pickup` restores the chosen point. Server-side validation remains responsible for blocking checkout when the active pickup rate has no valid selection.

## Checkout Map MVP

Version 0.23.0 adds the first production checkout map for `russian_post_domestic_pickup`.

Frontend assets live in `assets/frontend/pickup-map/`:

- `wdc-pickup-api.js` wraps REST calls;
- `wdc-pickup-modal.js` owns overlay, ESC close, focus trap, and destroy lifecycle;
- `wdc-pickup-map.js` owns Leaflet/OpenStreetMap map state, bbox loading, debounce, markers, selected-card rendering, and request aborts;
- `wdc-pickup-checkout.js` connects the modal to WooCommerce checkout fields, hides pickup UI for courier methods, resets selection on shipping/country/city changes, and updates checkout after save;
- `wdc-pickup-map.css` styles the fullscreen/mobile modal and selected-point block.

Leaflet is enqueued from local `assets/vendor/leaflet/` paths, not from a CDN. The map uses OpenStreetMap tiles. Points are never preloaded into hidden fields, DOM, session storage, or local storage; every map movement schedules a debounced 250 ms `GET /wp-json/wdc/v1/points?carrier=russian_post&bbox=minLng,minLat,maxLng,maxLat&limit=500`, and an `AbortController` cancels stale bbox/search requests.

Checkout state endpoints:

- `POST /wp-json/wdc/v1/checkout/pickup-point` with `{ point_id, shipping_method_id }`: checks the REST nonce, validates `russian_post_domestic_pickup`, verifies that the point exists and is active, and stores a structured `wdc_pickup_point` selection in WC session.
- `DELETE /wp-json/wdc/v1/checkout/pickup-point`: clears the checkout pickup selection.
- `GET /wp-json/wdc/v1/checkout/state`: returns the current selected point or `null`.

Session state stores `id`, `point_code`, `point_type`, `postcode`, `address`, `lat`, `lng`, and a compact `snapshot` of the point at selection time. The legacy internal pickup selection key is also populated so existing checkout validation and order persistence paths can use the selected Russian Post point without loading the full directory.

WooCommerce validation now requires a saved pickup point for `russian_post_domestic_pickup` and returns `Выберите пункт выдачи Почты России.` when the user tries to place an order without a point. This is a server-side checkout hook and cannot be bypassed by disabling JavaScript.

Order persistence is HPOS-safe and uses WooCommerce order/item APIs. Orders receive `_wdc_pickup_point_id`, `_wdc_pickup_point_code`, `_wdc_pickup_point_type`, `_wdc_pickup_point_address`, `_wdc_pickup_point_postcode`, and `_wdc_pickup_point_snapshot` JSON. Shipping item meta includes `Пункт выдачи`, `Индекс ПВЗ`, and `Тип ПВЗ`. The selected point is displayed on the admin order delivery metabox, thank-you/order details, and customer/admin emails.

The current scope covers the local Russian Post pickup directory, public point REST, and the checkout map/selection MVP. Shipment registration, labels, tracking statuses, multicarrier pickup maps, and advanced clustering are still out of scope.

## Storage

Russian Post points now live in a carrier-specific table created by `database/migrations/0021_create_russian_post_pickup_points_table.php`:

`wp_wdc_pickup_points_russian_post`

The generic legacy table `wp_wdc_pickup_points` is no longer used for Russian Post import or REST. It is intentionally not supported by this stage; an administrator can remove it manually:

```sql
DROP TABLE IF EXISTS wp_wdc_pickup_points;
```

The Russian Post table stores only the data needed for the map, checkout pickup selection, and future shipment registration: `id`, `point_code`, `point_type`, `postcode`, `country_code`, `region_name`, `city_name`, `street`, `house`, `address`, FIAS/GAR ids, `latitude`, `longitude`, `geohash`, `description`, compact readable `work_time`, `active`, `source_hash`, `last_seen_at`, `created_at`, and `updated_at`. It does not store brand, e-commerce option JSON, services, phones, images, weight or size limits, payment flags, inspection flags, `raw_reference`, or `work_time_json`. Fresh imports normalize raw `workTime` during parsing and keep only the compact text needed by REST and the future map. Indexes include `uniq_point_code`, `idx_type_active`, `idx_city_active`, `idx_postcode`, `idx_lat_lng`, `idx_geohash`, and `idx_source_hash`.

Existing test tables are not migrated to the compact schema. To recreate the table before a repeat import, remove it manually:

```sql
DROP TABLE IF EXISTS wp_wdc_pickup_points_russian_post;
```

## API Layer

Shared API "Отправка" classes live in `src/Carriers/RussianPost/Otpravka/`:

- `RussianPostOtpravkaApiSettings`
- `RussianPostOtpravkaApiClient`

These settings are intentionally not pickup-only. The same credentials/client layer will later be reused for shipment registration in the Russian Post personal account, labels, statuses, and other shipment operations.

Stored settings:

- `russian_post_otpravka_access_token`
- `russian_post_otpravka_login`
- `russian_post_otpravka_password_encrypted`
- `russian_post_otpravka_timeout`
- `russian_post_pickup_unload_type`
- `russian_post_pickup_schedule_enabled`
- `russian_post_pickup_last_import_result`
- `russian_post_pickup_last_success_at`

Secret fields are not rendered back into HTML. Empty secret inputs preserve existing values; clear checkboxes remove saved values. The `X-User-Authorization` Basic value is computed from Login + Password when the Otpravka client makes a request; it is not stored or edited as a separate credential.

## Import

Pickup import classes live in `src/Pickup/RussianPost/`:

- `RussianPostPassportPointNormalizer`
- `RussianPostPickupImporter`
- `RussianPostPickupImportStateService`
- `RussianPostPickupPointRepository`

The import fallback chain is:

1. automatic API direct cURL download;
2. automatic API WordPress HTTP download;
3. manual ZIP upload;
4. manual TXT/JSON payload upload.

The importer supports three source modes:

1. API download import: WordPress downloads `GET https://otpravka-api.pochta.ru/1.0/unloading-passport/zip?type=<ALL|OPS|PVZ|APS>` into a temp ZIP file.
2. Manual uploaded ZIP import: an administrator downloads the ZIP outside WordPress and uploads it on the admin tab. This is recommended for LocalWP or WordPress HTTP/Action Scheduler environments where long background downloads are unstable.
3. Manual uploaded TXT/JSON import: an administrator extracts the ZIP outside WordPress and uploads the `.txt` or `.json` payload containing `passportElements`. This is the most reliable LocalWP/Windows fallback because it skips both API download and PHP ZipArchive.

Both modes use the same resumable background jobs:

- init job `wdc_russian_post_pickup_import_init`: create a staging table `wp_wdc_pickup_points_russian_post_staging_<import_id>`, download ZIP for `source=api_download`, use the stored uploaded ZIP for `source=uploaded_zip`, or use the uploaded payload directly for `source=uploaded_payload`; ZIP sources extract the first `.json`/`.txt` payload to a temp file and delete the ZIP, while uploaded payload sources skip download/extract and save `payload_file` with `payload_offset=0`, then schedule the first batch;
- batch job `wdc_russian_post_pickup_import_batch`: open the payload, seek to `payload_offset`, parse up to 500 `passportElements` objects, normalize and insert only that small batch into staging, save the new byte offset and counters, then schedule the next batch or finalize;
- finalize job `wdc_russian_post_pickup_import_finalize`: atomically swap staging into `wp_wdc_pickup_points_russian_post` with `RENAME TABLE`, verify that main exists, delete the backup only after successful verification, delete the payload temp file, save success state, and unlock.

The parser resumes from the saved byte offset and does not re-read the whole payload from the beginning. The full ALL payload is never decoded at once, and no single PHP process performs the full import.

The current main table remains readable while staging is being built. REST and future checkout map reads always use the main table only. If import fails, staging is dropped and the old main table remains untouched. Full snapshots do not use `mark_missing_inactive`; the swapped main table contains the current snapshot.

If a swap fails after the previous main table has been renamed to backup, the repository attempts to rename backup back to main. A recovered failure still marks the import failed and records a clear message, but leaves the production table restored. If recovery also fails, the backup table is kept for manual repair and is not deleted by failed cleanup.

After a successful swap, the importer runs `ANALYZE TABLE wp_wdc_pickup_points_russian_post` to refresh InnoDB statistics for bbox/search queries and admin tools. Analyze failure is stored as a warning in import errors, but does not turn a successful import into failed.

The import result stores `downloaded`, `parsed`, `inserted`, `updated`, `deactivated`, `skipped`, `errors`, `started_at`, and `finished_at`.

Download diagnostics are stored in the same state/result: `download_url`, `download_started_at`, `download_duration_ms`, `download_http_code`, `download_response_message`, `temp_file_size`, `download_error`, `download_backend`, `fallback_used`, `first_backend_error`, `curl_errno`, and `curl_error`. The client tries direct cURL first when the extension is available, writing the ZIP straight to the temp file. If cURL fails, it falls back to WordPress HTTP streaming and records diagnostics from both backends. The Otpravka download timeout defaults to 120 seconds and is clamped to 30..300 seconds; both backends use a short connect timeout. A download stage with no activity for 5 minutes is marked failed and the lock is cleared on the next status/lock check. The admin AJAX status endpoint calls `RussianPostPickupImporter::refresh_state_for_status()` before returning state, so ordinary polling also performs stale cleanup and returns the failed state.

Locking uses `wdc_russian_post_pickup_import_lock` via transients, with an option fallback in non-WP smoke tests, so parallel imports return a readable status instead of running twice.

Manual imports from the admin UI now queue a background job instead of running in the HTTP request. The persistent live state is stored in the `wdc_russian_post_pickup_import_state` option with `status`, `stage`, timestamps, counters, first errors, type, memory peak, `source`, `temp_zip_file`, `original_upload_name`, `uploaded_file_size`, `import_id`, `payload_file`, `payload_offset`, `objects_processed`, `batches_processed`, `current_batch_size`, `last_batch_duration_ms`, `max_batch_duration_ms`, `parser_completed`, `staging_table`, `main_table`, `backup_table`, `rows_inserted_to_staging`, `swap_started_at`, and `swap_finished_at`. The importer updates state before download or uploaded ZIP extraction, after extraction, after every batch insert, before swap, and on success/failure. If a queued/running state has no activity for more than 2 hours, stale lock recovery marks it failed and allows a new run with a warning.

ZIP extraction requires the PHP `zip` extension / `ZipArchive`. The init job writes extract diagnostics before entering ZipArchive: `extract_started_at`, `extract_zip_file`, `extract_zip_size`, `ziparchive_available`, `extract_backend`, `extract_duration_ms`, and `extract_error`. After extract it also stores `extract_success`, `extracted_payload_file`, `extracted_payload_size`, `extracted_payload_entry_name`, and `extracted_payload_entry_index`. The extractor validates ZipArchive availability before `open()`, records ZipArchive open codes/messages on invalid archives, extracts the first `.json`/`.txt` entry with `ZipArchive::extractTo()` into a temporary directory, then copies the extracted payload stream-to-stream into the resumable payload temp file without loading it into memory. Uploaded TXT/JSON payloads skip this step and go straight to `parse`.

The Otpravka passport ZIP download timeout defaults to 120 seconds and is sanitized to 30..300 seconds. Download failures store the HTTP code, WP error message when present, response message/body excerpt up to 1000 characters, duration, and temp file size when available. A running `download` stage with no activity for more than 5 minutes is marked failed with `Download stage timed out/stale.` and the import lock is cleared. The stale download error also recommends manual ZIP upload for unstable environments. A running `extract` stage with no activity for more than 5 minutes is marked failed with `Extract stage timed out/stale. Check PHP ZipArchive extension or use extracted JSON/TXT import.` and cleanup removes ZIP/payload/staging. A stale `parse`/`upsert` batch older than 10 minutes is marked failed with `Batch stage timed out/stale.`.

`RussianPostPickupPointRepository` writes import batches only into staging. This keeps the production table stable and avoids sustained writes against the table read by REST/checkout.

## Admin

Manual import is available at:

`Службы доставки -> Почта России — по России / pickup service -> ПВЗ / ОПС`

The tab is shown only for `russian_post_domestic_pickup`. It contains:

- shared API "Отправка" credentials: AccessToken, Login, Password;
- Russian-labeled automatic download timeout;
- unload type `ALL|OPS|PVZ|APS`;
- weekly update flag with the next scheduled run time when enabled;
- "Запустить импорт сейчас";
- "Загрузить ZIP/TXT и начать импорт";
- collapsible live import status/progress;
- last import result;
- active counts for `OPS`, `PVZ`, `APS`;
- lock status.

The "run import now" button schedules the init hook `wdc_russian_post_pickup_import_init` through Action Scheduler when available, otherwise through `wp_schedule_single_event(time()+5, ...)`, then redirects back to the tab. The "Загрузить ZIP/TXT и начать импорт" button stores one uploaded `.zip`, `.txt`, or `.json` file under `uploads/wdc-imports/`, records `original_upload_name` and `uploaded_file_size`, then chooses the processing path by extension: `.zip` uses `source=uploaded_zip` and ZIP extract; `.txt`/`.json` use `source=uploaded_payload` and go directly to the batch parser. The status box is collapsed by default: its summary shows status, stage, parsed rows, and inserted rows, and the JSON-like status table is visible only after expanding. It polls `admin-ajax.php?action=wdc_russian_post_pickup_import_status` every 3 seconds while the state is `queued` or `running`; polling stops on `success` or `failed`. Source/status/stage labels and common errors are shown in Russian in the admin UI. The status output includes source, upload filename/size, payload path/size, parsed rows, rows inserted to staging, skipped rows, batch metrics, staging/main table names, swap timestamps, and errors. On the current test import, `ALL` produced 37302 active points.

If a ZIP upload is stored successfully but the background import cannot be queued, for example because another import is already locked/running, the admin handler deletes the uploaded ZIP immediately and saves failed state with `Unable to queue ZIP import. Another import may be running.`

State becomes `queued` only after the background job is actually scheduled. If scheduling fails, the state is saved as `failed` with `Unable to schedule background import job.`, so the admin screen does not get stuck in a forever-queued state.

The admin tab includes "Отменить / сбросить зависший импорт" while an import is queued/running. It clears `wdc_russian_post_pickup_import_lock`, drops the staging table, removes temp files including uploaded ZIPs and extracted payloads, and marks state failed with `Import was manually cancelled/reset by admin.` without touching the main table.

Manual PowerShell download template shown in admin:

```powershell
# === НАСТРОЙКИ ===
$AccessToken = "ВАШ_ACCESS_TOKEN"
$Login       = "ВАШ_LOGIN"
$Password    = "ВАШ_PASSWORD"

# === АВТОРИЗАЦИЯ ДЛЯ X-USER-AUTHORIZATION ===
$BasicAuth = [Convert]::ToBase64String(
    [Text.Encoding]::UTF8.GetBytes("$Login`:$Password")
)

# === КУДА СОХРАНИТЬ ===
$OutFile = "D:\russian-post-passport-all.zip"

# === СКАЧИВАНИЕ ===
Invoke-WebRequest `
  -Uri "https://otpravka-api.pochta.ru/1.0/unloading-passport/zip?type=ALL" `
  -Headers @{
      "Authorization"        = "AccessToken $AccessToken"
      "X-User-Authorization" = "Basic $BasicAuth"
      "Accept"               = "application/octet-stream"
  } `
  -OutFile $OutFile `
  -TimeoutSec 300
```

The existing `Калькулятор доставок -> ПВЗ` page remains in place and now shows a Russian Post summary with active total, type counts, and last successful import date.

## REST API

The local pickup directory is exposed through public read-only REST endpoints under `wdc/v1`. For `carrier=russian_post`, they read only from `wp_wdc_pickup_points_russian_post` and do not call Russian Post or any external API. Other carriers return an empty list for list/search in this stage.

`GET /wp-json/wdc/v1/points`

Parameters:

- `carrier=russian_post`
- `bbox=minLng,minLat,maxLng,maxLat`
- `type[]=OPS|PVZ|APS`
- `limit`, default `500`, max `1000`

Example:

```text
/wp-json/wdc/v1/points?carrier=russian_post&bbox=82.80,54.90,83.10,55.20&type[]=PVZ&limit=200
```

`GET /wp-json/wdc/v1/points/search`

Parameters:

- `q`
- `carrier=russian_post`
- `city`
- `type[]=OPS|PVZ|APS`
- `limit`, default `50`, max `100`

Example:

```text
/wp-json/wdc/v1/points/search?carrier=russian_post&q=630001&city=Новосибирск&type[]=OPS
```

`GET /wp-json/wdc/v1/points/{id}` returns a safe detail card with `point_code`, point type, address, postcode, city/region, coordinates, work time, and description.

The API validates bbox ranges, clamps limits, sanitizes all query parameters, uses prepared SQL through `RussianPostPickupPointRepository`, and does not expose raw snapshots, `work_time_json`, secrets, source hash, temp files, or import state fields.

## Scheduling

`RussianPostPickupImporter::SCHEDULE_HOOK` registers the scheduled import hook. When "Обновлять еженедельно" is enabled, WP Cron schedules a weekly import; disabling clears the hook when the WordPress cron functions are available.

## Tests

Smoke coverage:

```powershell
php tests/pickup/run-russian-post-pickup-import-smoke.php
php tests/delivery-services/run-delivery-services-smoke.php
php tests/runtime/run-no-legacy-smoke.php
```
