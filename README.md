# Walls Delivery Calc

Current plugin version: 0.37.1.

Version 0.37.1 updates manual Russian Post tracking attachment. Managers now enter a tracking number rather than a SHPI-specific label; WDC normalizes it, searches `GET /1.0/backlog/search?query={barcode}` first, and falls back to `GET /1.0/shipment/search?query={barcode}` when the shipment has already left backlog. If shipment search returns no internal id, WDC still saves barcode/tracking number and runs Tracking API by barcode; cancellation stays disabled until `backlog_order_id` exists. The tracking copy control is now a compact accessible `fa-light fa-copy` icon button.

Current implementation status and roadmap are maintained in `docs/project-status.md`. Historical release notes below may not cover every intermediate 0.33.x change.

Version 0.37.0 finishes the Russian Post shipment cleanup for the current manual workflow. The order metabox no longer shows a documents/download placeholder because labels, batches, F103 and Russian Post documents are prepared manually in the Russian Post account. Managers can cancel a just-created backlog shipment while Russian Post still reports `Присвоение идентификатора`; cancellation uses `DELETE /1.0/backlog` with `backlog_order_id`. Managers can also attach a manually created shipment by entering ШПИ; WDC searches `GET /1.0/backlog/search`, saves barcode plus `backlog_order_id`, then tries the first Tracking API status refresh. Tracking still uses barcode/ШПИ, not `backlog_order_id`.

Version 0.36.4 stores Russian Post Otpravka create-response `result-id` as the explicit technical shipment-state field `backlog_order_id`. Barcode/ШПИ remains the manager-facing tracking identifier and is used for Tracking API status refreshes; in 0.37.0 `backlog_order_id` is kept hidden and used for internal backlog operations such as cancellation, not in customer-facing output, emails, account pages, public tracking blocks, or shipment toasts.

Version 0.36.2 polishes the manual shipment status flow. After a successful Russian Post shipment create, the preparation modal closes, a 10-second admin toast confirms creation, and WDC automatically runs the first `wdc_update_shipment_status` request; if that status refresh fails, creation remains successful and the metabox shows a Russian warning. Russian Post tracking operations without attributes now fall back from `type:0`/empty attr to `type:-`, so `28:-` maps to `created_in_carrier` / `создан в ТК` and `46:-` maps to `cancelled` / `отменён`. The metabox shows Russian status labels and uses barcode/ШПИ as the primary shipment identifier.

Version 0.36.1 corrects the Russian Post tracking status mapping: selected pickup operations including `8:2`, `12:1..12:31`, and `42:1..42:30` now map to `ready_for_pickup` / `ожидает самовывоза из ПВЗ/постамата`, while `8:15` and `8:18` map to `handed_to_courier` / `передан курьеру`. Unknown operation/attribute pairs still map to `unknown` / `не определён`.

Version 0.36.0 adds manual Russian Post tracking status refresh from the WooCommerce order metabox `Отправления`. The existing `Обновить статус` button calls `wdc_update_shipment_status`, reads Tracking API login/password from the unified domestic service `API / Credentials` tab, requests `getOperationHistory` through SOAP 1.2, maps the latest operation with the bundled table from `status pocha.xlsx`, and stores both the universal plugin status and raw Russian Post operation details in `_wdc_shipments`. Unknown Russian Post operation/attribute pairs become `unknown` / `не определён`. Automatic polling/synchronization is not included in this step.

Version 0.35.2 further cleans up unified Russian Post domestic checkout/order data. The `Расчет` tab now labels tariff origin/return indices as calculation-only fields, while `default_from_postcode` moved to `API / Credentials` next to postoffice acceptance indices. The Tariff API token field remains because the domestic tariff client sends it as `Authorization: Bearer ...` when configured. Visible domestic shipping item meta now contains only `Срок доставки`; pickup code/type/postcode/address live in `_wdc_delivery_calculation_data.pickup`, and pickup code is no longer written to `shipping_address_2`.

Version 0.35.1 cleans up the unified Russian Post domestic service UI and checkout metadata. The domestic service keeps availability and configurable pickup/courier method titles on `Основные`, keeps tariff calculation indices on `Расчет`, and keeps Tariff API endpoint/token plus Otpravka/Tracking credentials on `API / Credentials`. WooCommerce shipping item meta no longer shows technical `wdc_delivery_kind`, `delivery_kind`, or `checkout_group_id`; shipment creation uses hidden WDC order meta and `_wdc_delivery_calculation_data`.

Version 0.35.0 unifies Russian Post domestic delivery into one service settings context: `service_key=russian_post_domestic`, `carrier_key=russian_post_domestic`. Pickup/OPS and courier checkout groups remain visually separate, but they are now split by `delivery_type` and use group/rate ids such as `russian_post_domestic:pickup` and `russian_post_domestic:courier`. Otpravka credentials, postoffice codes, shipment settings, tariffs, pickup point type settings, future tracking credentials and status mapping storage now live in the domestic service tabs on `WDC -> Службы доставки`; the old `WDC -> Перевозчики` UI is removed. Migration `0026` copies existing domestic service settings, credentials, shipment settings and tariff variants into the unified service.

Version 0.34.0 adds an admin-only Russian Post pickup point selector inside the shipment preparation modal. Managers can search local `wdc_pickup_points_russian_post` rows, pick an OPS/PVZ on the existing configured map stack, and refresh the shipment draft/preview without changing checkout, tariff calculation, saved delivery method, or WooCommerce order meta.

Version 0.32.4 resolves local `wdc_locations` matches during Russian Post pickup import before staging inserts, so fresh `wdc_pickup_points_russian_post` rows can receive `location_id` immediately. A shared cached `RussianPostPickupLocationResolver` now handles FIAS, unique postal-code, and unique region+city matching for both importer and diagnostics rebind, and import state records location match counters.

Version 0.32.3 makes the locations admin page fast to open on large `wdc_locations` tables. The default `wdc-platform-locations` render now uses a lightweight total counter and defers country, alias, postal-code, coordinate, and technical-marker counters until the admin explicitly requests detailed counters. Fresh installs and updates also include an idempotent `idx_active_country_code (active, country_code)` index for common active/country filters while preserving `postal_code`.

Version 0.32.2 makes the Russian Post pickup diagnostics page fast to open on large pickup tables. The initial summary now uses only cheap counters, `all_problematic` excludes the expensive suspicious-coordinate distance check, and suspicious coordinates are evaluated only when the dedicated filter is selected.

Version 0.32.1 makes the Russian Post pickup diagnostics schema migrations production-safe. Migration `0022` now checks table, column, and index presence before altering the pickup table, and migration `0023` removes only the unused legacy `wdc_locations.postcode` column when the canonical `wdc_locations.postal_code` column is present. `wdc_locations.postal_code` is preserved for checkout fallback lookup, pickup diagnostics/rebind, DaData enrichment, and admin/search tooling; it may be empty when GAR/FIAS source data has no postal index. `wp_wdc_location_aliases` is not a runtime checkout dependency, but it is used by FIAS/GAR import, display-name rebuild, snapshot export/import, and full locations cleanup as a generated alternate-name store.

Version 0.32.0 adds an admin diagnostics screen for the Russian Post pickup-point database. The page shows quality counters, problem filters, paginated problematic rows, CSV export, suspicious coordinate checks against matched locations, and a guarded location rebind dry-run/apply action.

Version 0.31.2 fixes courier checkout address summaries when only postcode and city are filled. The courier summary now treats `address_1` as the required address signal, keeps the warning visible until address line 1 is filled, and only then shows the formatted `{postcode}, {city}, {address_1}` line.

Version 0.31.1 keeps courier checkout address required markers scoped to WDC-added markers and aligns billing/shipping address selection between PHP and frontend JS. Shipping address is now used only when `ship_to_different_address` is selected/present or when billing address is absent and shipping address exists, not merely because a hidden shipping address field has a value.

Version 0.31.0 adds courier checkout address handling for WDC rates. Courier rates now expose normalized courier meta, the selected courier shipping method shows the customer's `{postcode}, {city}, {address}` summary or a linked warning to fill the address, the address field becomes required only while courier delivery is selected, and checkout validation rejects courier orders without address line 1 while pickup/no-shipping flows remain unaffected.

Version 0.30.7 brings the same force-reopen behavior to the Leaflet/OpenStreetMap pickup map: active marker popup opens now always unbind/rebind before opening, popup close refreshes the active marker state, and side-list clicks use the same direct popup reopen path as marker clicks without committing a point.

Version 0.30.6 fixes the Yandex-only pickup map edge case where closing a balloon with its X could leave the Yandex events pane intercepting clicks on the still-active marker. The Yandex provider now recreates the active placemark after a real balloon close, preserving active state and click handlers so the next marker click opens the balloon again.

Version 0.30.5 simplifies the two stubborn checkout fixes: WDC domestic tariff labels now build one text line in PHP (`title - days: `) before the styled current price, avoiding flex-separated separator spans entirely, and pickup marker clicks use a direct `openPointPreviewFromMarker` path that clears manual-close state and imperatively reopens the popup every time.

Version 0.30.4 makes WDC nested tariff separators part of the PHP markup instead of CSS pseudo-content, so empty days/prices do not leave stray punctuation. Pickup marker clicks now explicitly clear the manual popup-close flag and providers suppress close events during the reopen tick, so closing a popup with its X no longer blocks reopening it by clicking the active marker.

Version 0.30.3 fixes the last checkout polish regressions: WDC nested rate separators now render as an explicit stable `title - days: price` text flow, and pickup marker clicks are protected from the map-click close path so clicking an already-active marker reopens its popup/balloon after a manual close in both Leaflet and Yandex providers.

Version 0.30.2 tightens the final checkout polish: WDC nested rate text now renders as `{title} - {days}: {price} {old price}` without extra spacing, pickup popup/list select buttons use rounded scoped styling, and clicking an active pickup marker after closing its popup reopens the preview balloon without selecting the point.

Version 0.30.1 polishes checkout UI details after the 0.30.0 split: the city picker modal now uses rounded checkout-style controls, WDC nested rate rows keep title, days, price, and crossed price in natural inline order, the pickup map search bar is a single rounded control with the submit action inside it, and active pickup markers stay visible/red while their popup opens above the marker.

Version 0.30.0 moves WDC checkout shipping UI ownership into the main plugin. WDC now styles its own shipping methods, nested domestic rate choices, crossed prices, pickup card/button states, and checkout pickup modals, while `walls-invoice-payment.php` keeps the shared checkout layout, payment flow, ordinary third-party shipping method cards, and eshoplogistic `wc_esl_*` pickup duplication. The visible checkout address-check block is no longer rendered by default, and delivery sorting is shown inside the shipping area only when at least two WDC rates are available.

Version 0.29.2 updates the pickup map geolocation control icon to a dark navigation arrow and raises the overlay button above map attribution.

Version 0.29.1 moves the pickup map geolocation action from the search row into a round map overlay control and renders the geolocation origin with the same red push-pin marker used by address search.

Version 0.29.0 adds a browser geolocation helper to the pickup map. The geolocation action centers the map on the customer's current coordinates, loads nearby pickup points, and sorts the list by distance from that temporary origin without changing checkout destination fields, locality, cross-location flow, or tariffs.

Version 0.28.5 clears the selected pickup point when the customer manually changes the checkout locality through the city selector. Cross-location pickup selection remains protected by a one-shot controlled location-change flag, so a confirmed pickup map locality change can recalculate checkout and save the pending pickup point without being cleared by the location event.

Version 0.28.4 hardens selected pickup UI toggling against theme button styles. Hidden checkout pickup controls now get the hidden attribute, `wdc-is-hidden`, `aria-hidden`, inline `display:none`, and a scoped CSS override so the primary choose button cannot remain visible while the selected pickup card is shown.

Version 0.28.3 removes the legacy selected pickup summary that was printed under checkout rate comments, leaving only the shared selected pickup card. The initial "Выбрать пункт выдачи" button stays hidden while a point is selected and returns when the selection is cleared.

Version 0.28.2 refines the selected pickup point card: the accent is now green, the card shows one full pickup address instead of a separate postcode/city line plus address, and checkout/email card sizing uses full container width with safer wrapping on mobile.

Version 0.28.1 makes the shared pickup point card renderer accept both checkout/order arrays and PickupPoint objects, and avoids duplicate selected pickup cards on the thank-you page by using a single order-details hook for customer order rendering.

Version 0.28.0 adds a shared pickup point card renderer for checkout, the order thank-you page, and customer emails. The checkout selected-point UI now updates the same card after map selection, order details reuse that renderer, and email output is controlled by a dynamic WooCommerce email-class setting that includes custom emails from extensions such as WooCommerce Order Status Manager.

Version 0.27.15 trims temporary checkout pickup diagnostics for production. Backend validation and pickup clear logs now keep only compact method, point-presence, pass/fail, and restore-status fields, while detailed frontend context logs move behind `window.wdcPickupCheckout.deepDebug`.

Version 0.27.14 registers an early WooCommerce checkout-process preloader for Russian Post pickup selections. Checkout submit POST hidden fields now restore the pickup session before later validation hooks run, and debug logs include checkout validation registration plus preload start/success/skipped messages.

Version 0.27.13 makes checkout POST hidden pickup fields authoritative during Russian Post pickup validation. When WooCommerce posts the bare `russian_post_domestic_pickup` method without saved rates, validation builds a synthetic pickup rate, restores the point from posted id/code, and accepts a minimal saved selection even when the pickup repository has no matching row.

Version 0.27.12 stops same-city pickup saves from triggering WooCommerce `update_checkout`. Selecting a Russian Post pickup point for the current checkout destination still saves through REST, applies the selected point UI, and closes the map, while cross-location pickup selection continues to recalculate checkout before saving the pending point.

Version 0.27.11 makes checkout validation resilient when WooCommerce posts the bare `russian_post_domestic_pickup` method id. Validation now resolves bare pickup family selections to saved family rates, falls back to a minimal Russian Post pickup rate when needed, restores posted pickup points by id or point code, saves minimal checkout pickup state even without repository details, and logs each validation decision when debug is enabled.

Version 0.27.10 prevents late checkout city-selector events from clearing Russian Post pickup selections. The frontend now recognizes same-location `wdc:location-selected` events by location id, FIAS id, or normalized city/postcode, extends the place-order reset guard for late checkout events, and checkout validation reads posted `shipping_method[0]` before falling back to WooCommerce session state.

Version 0.27.9 adds reason-coded pickup reset diagnostics and hardens checkout submit against WooCommerce recalculation payloads that omit `shipping_method`. Address runtime now falls back to `chosen_shipping_methods`, refuses automatic pickup clears while the active method family is `russian_post_domestic_pickup`, and frontend `updated_checkout` skips context refresh during place order. The pickup CSS remains scoped away from WooCommerce button and loader pseudo-elements.

Version 0.27.8 hardens Russian Post pickup selection persistence around WooCommerce checkout recalculation. Pickup selections are now matched and refreshed by the `russian_post_domestic_pickup` method family, address recalculation preserves the point during same-family tariff switches, and frontend reset functions are guarded during place order while `updated_checkout` restores hidden fields from the selected point.

Version 0.27.7 preserves the selected Russian Post pickup point during checkout submit and when switching tariff suffixes inside `russian_post_domestic_pickup`. Checkout validation now accepts the saved pickup point by method family, can restore the selection from submitted hidden pickup fields, and the frontend only clears pickup state after a real destination or carrier change.

Version 0.27.6 synchronizes the pickup map context after a confirmed cross-location save. Once WooCommerce recalculates and the pending pickup point is saved, the frontend rebuilds the map context from the resolved location plus actual checkout fields, updates `currentContext`, `window.wdcPickupCheckout.currentContext`, `initialContext`, and the selected pickup point, then clears and restarts pickup prefetch for the new locality. `contextFromFields()` now treats city-selector formatted city values such as `г Новосибирск` as matching the hidden locality instead of discarding hidden coordinates, and prefetch cache keys include FIAS so old-city points cannot be reused.

Version 0.27.5 keeps checkout locality formatting identical between the city selector and Russian Post pickup map. The city selector now includes the full location context in `wdc:location-selected`, including FIAS/GAR/KLADR ids and region/city/place type fields. Cross-location pickup confirmation applies the resolved location through the same city selector rules, so visible city/state and hidden fields match an ordinary city-picker selection. The pickup quick check now preserves FIAS from location events and logs the FIAS-first match reason in debug mode, preventing same-FIAS pickup points from falling through to less reliable string matching.

Version 0.27.4 simplifies cross-location Russian Post pickup selection. After the customer confirms a pickup point in another locality, the map closes immediately, checkout destination fields are updated, WooCommerce recalculates once, and the pickup point is saved only after `updated_checkout` using the current selected `russian_post_domestic_pickup` method and the freshly rendered checkout block. The cross-location wait timeout is now 60 seconds; if recalculation times out or the current method is no longer a pickup method, the point is not saved and checkout shows a notice. Frontend rate switching and cheapest-rate selection were removed from this flow.

Version 0.27.3 waits for a second WooCommerce `updated_checkout` when the cross-location flow has to switch to another available Russian Post pickup rate. The frontend now records whether `selectCheapestPickupRate()` changed the selected radio; unchanged rates save the pending pickup point immediately after the locality recalculation, while changed rates wait for the shipping-rate recalculation before saving. If that second recalculation times out, the pickup point is not saved and the modal asks the customer to try again.

Version 0.27.2 improves cross-location pickup selection UX and persistence. The pickup modal now shows a loading overlay and disables select buttons while the point is being checked, saved, or delivery is recalculating. For pickup points in another locality, checkout now runs a controlled flow: update destination fields, wait for WooCommerce `updated_checkout`, select the cheapest available `russian_post_domestic_pickup` rate if the previous rate disappeared, and only then save the pending pickup point. If no pickup rate remains available after recalculation, the modal shows a warning and does not save the point.

Version 0.27.1 makes pickup point FIAS/GUID resolution the primary local-location lookup path. `PickupPointLocationResolver` now resolves `fias_location_guid` against `locations.fias_id` before postal-code matching, and `LocationRepository::find_by_fias_id()` compares normalized GUIDs so dashed and non-dashed values match. This keeps a pickup point tied to its FIAS locality even when its postal code could also match another local row.

Version 0.27.0 adds cross-location protection for Russian Post pickup selection in checkout. If a customer commits a pickup point from another locality, the checkout compares the current destination and pickup point by FIAS/GUID first, then by normalized region plus city/settlement, and only then by postal code. For a different local pickup destination the map modal shows an in-modal confirmation, warns that the shipping cost will be recalculated, updates the checkout location fields only after confirmation, saves the pickup point, and triggers WooCommerce `update_checkout`. Cancelling leaves the map open and does not change the checkout destination or saved pickup point.

The new nonce-protected `POST /wp-json/wdc/v1/checkout/pickup-point/resolve-location` endpoint resolves a pickup point to a local checkout-compatible location without calling DaData. It uses the local locations table by `location_id` when available, then postal code plus city/region matching, then normalized city/region search, and finally postal code as a safe fallback. If no local location is found, checkout keeps the previous behavior and saves the pickup point without running the cross-location flow.

Version 0.26.4 changes the pickup address search marker into a distinct red pin, separate from pickup point flag markers. Address/postcode search no longer auto-previews or opens the nearest pickup point after the forced bbox load; it only sorts the list by distance from the search marker. Provider adapters detect close visual overlap between the search pin and pickup markers, shift the search pin sideways without changing its coordinates, and keep it below pickup markers/non-interactive so nearby pickup markers remain clickable.

Version 0.26.3 makes the post-search pickup map refresh deterministic. Successful address/postcode search now applies the red search marker immediately, updates the distance origin, recenters the map, and then forces a bbox load around the found point as the source of truth for visible pickup points. The narrow `address-search` response points are no longer rendered as the final list state, so the side list and map are replaced by nearby bbox results instead of getting stuck on exact postcode rows or stale previous-city data.

Version 0.26.2 refines the pickup address search row: opening the modal now focuses the close button instead of the search input, the search field has a decorative magnifier and an explicit "Искать адрес" button, and Enter triggers the same search action. After address/postcode search the frontend now loads a bbox around the found point, while postcode exact matches return nearest pickup points around the postcode anchor instead of leaving the list stuck on only the exact row.

Version 0.26.1 protects `GET /wdc/v1/points/address-search` with the WordPress REST nonce because the endpoint can spend DaData token quota. The checkout frontend already sends `X-WP-Nonce`; missing or invalid nonce now returns `wdc_forbidden` with HTTP 403. Public read-only pickup endpoints `/points`, `/points/search`, and `/points/{id}` remain public.

Version 0.26.0 adds address search above the Russian Post pickup map through `GET /wdc/v1/points/address-search`. Address queries use the existing `AddressSuggestionClientInterface` / `DaDataSuggestionClient` and shared `DaDataTokenPool`, so token rotation, daily counters, exhausted-token flags, and daily limits remain centralized. Results are cached for 24 hours by `query + location_id + country_code`, so repeated address searches do not spend DaData limits again.

Six-digit postcode searches bypass DaData completely and continue to work even when all DaData tokens are exhausted. The backend first returns pickup points with the exact postcode, then can fall back to coordinates of a local location with that postal code, and only then reports a not-found state. When address tokens are unavailable, the endpoint returns `address_search_available=false`; the frontend switches the search field to numeric-only mode with placeholder "Сейчас работает поиск только по почтовому индексу".

Successful address search recenters the map on the found address, adds a separate red search marker that is not selectable as a pickup point, reloads/sorts nearby pickup points by distance from the found address, and shows a compact "Найден адрес" block above the list with the nearest pickup distance.

Version 0.25.14 stops the admin DaData coordinate batch as soon as the shared token pool reports `dadata_daily_limit_exhausted`. The job keeps progress already written before the stop, records `stopped_reason=daily_limit_exhausted`, `tokens_exhausted=true`, and the message "Суточные лимиты DaData исчерпаны. Повторите запуск позже.", and it does not continue automatically on later step polls.

The locations settings DaData block now groups actions into two rows: index fill plus technical-marker cleanup, then coordinate fill plus "Обнулить задачу координат". The reset action deletes only the coordinate batch progress option, leaves saved latitude/longitude values untouched, does not affect the postcode/index batch state, and the next coordinate start again scans the full missing-coordinate set while still skipping rows with valid coordinates.

Version 0.25.13 separates popup close sources: empty map clicks call `markPopupManuallyClosed('map_click')` and close the popup, while provider `popupclose`/`balloonclose` callbacks call `markPopupManuallyClosed('popup_close')` without calling `closePopup()` again.

Version 0.25.12 makes balloon restore respectful of explicit user close. A committed point still opens automatically on the first visible render, and marker/list clicks always reopen the balloon, but closing the balloon or clicking empty map space sets `popupManuallyClosed`; bbox reloads, drags, moves, and zooms keep the active marker/list row without reopening the popup. Leaflet clustering now uses a 64px grid and disables clusters only at zoom 18+, while Yandex uses `gridSize: 80` and also keeps Clusterer enabled through zoom 17.

Version 0.25.11 changes pickup marker color semantics: normal single markers stay blue, while the current preview/active marker is red. `committedPoint` no longer has its own marker color and does not directly drive active marker rendering; when a previously saved checkout point appears in visible/preloaded points, the map promotes it to `previewPoint`, highlights the marker/list row, and may reopen the balloon without dispatching `wdc:point-selected`.

Version 0.25.10 removes the temporary Leaflet `bindPopup(address)` used during marker creation; Leaflet popups are now bound only when `openPointPopup(point, html)` receives the full card HTML. The compact status and old modal footer confirm controls are visually hidden, leaving only the shared list footer button in the visible list area. Active-row scrolling now computes row and container rectangles with `getBoundingClientRect()` and scrolls only the list container.

Version 0.25.8 refines pickup map ergonomics. Single pins keep the blue marker/white center shape but use a slimmer visual ring, closer to cluster weight. Per-row `Выбрать` buttons were removed from the side list; the list now has one shared footer button, `Выбрать этот пункт`, enabled only for an uncommitted preview point. The balloon button remains the primary selection action.

Marker clicks scroll the side/mobile list to the active row. List-row clicks open the same balloon without hard-centering the map; providers rely on popup/balloon auto-pan to nudge only when the balloon would be clipped. Since 0.25.12, Leaflet grid clustering stops at zoom 18+, and the Yandex provider bypasses `Clusterer` at zoom 18+, so only the final zoom levels render close points as individual pins.

Version 0.25.7 makes preview state the visual source of truth. If the customer has already committed point A but opens point B, point B owns the active marker, open balloon, and preview row while checkout remains committed to A. If B leaves the current bbox, preview is cleared; in 0.25.11 a visible committed point is promoted back into preview instead of getting a separate committed marker color.

Version 0.25.6 simplifies pickup type labels and tightens the map selection flow. Admin settings now keep one type name per OPS/PVZ/APS entry: `Название в карточке/баллоне/списке`, exposed to the frontend as `pickupPointTypes.*.label`.

Map pins are now textless blue pins with a white center, while clusters are white circles with a thick blue border and dark count. Opening a marker or list row creates a preview: the marker and row are highlighted and the balloon stays open, but checkout is not updated. The customer commits the point through `Выбрать этот пункт` in the balloon or through the shared list footer button; both paths dispatch `wdc:point-selected` and save the point immediately. The modal footer button remains only as a fallback for an already committed point.

Balloon state now survives bbox reloads and normal map movement when the preview/committed point is still present in the visible point set. Dragging or zooming does not close the balloon; clicking empty map space closes only the preview popup and keeps any committed checkout point intact.

Version 0.25.4 moves the pickup point details into the map popup/balloon. Clicking a marker or list row now opens the point card directly on the map, with address, work time, clean description when available, and the `Выбрать этот пункт` button inside the popup. The side/bottom panel remains as a compact status area, while the external confirm button stays as a compatibility fallback and is enabled only after a confirmed selection.

Single markers and clusters now share a consistent HTML style in Leaflet and Yandex: a white center, thick blue outline, short marker label from settings, and a blue tail for single point pins. Clusters render as numbered circles and still expand/focus on click. The visible list remains a navigation aid beside/below the map; it opens the same popup instead of duplicating the full card.

Version 0.25.2 adds configurable Russian Post pickup point types for `russian_post_domestic_pickup`. In the delivery service admin tab `ПВЗ / ОПС`, the `Типы пунктов выдачи` block controls OPS, PVZ, and APS independently: whether the type is used and the customer-facing type label. Current defaults are OPS `Отделение Почты России`, PVZ `Пункт выдачи`, and APS `Почтомат`. At least one type is always enabled; if an admin disables all three, OPS is restored automatically.

The public Russian Post `/points` and `/points/search` endpoints now filter results by enabled pickup types. Explicit REST `type[]` filters are intersected with enabled types, so requesting a disabled type returns an empty list. Checkout localizes `pickupPointTypes` to the map frontend; list/card/balloon text uses `label`, and markers stay textless.

Version 0.25.1 keeps initial search results as preview-only. `initialSearch()` may show the first found point in the card and softly mark it in the list, but it no longer enables confirmation or dispatches `wdc:point-selected` until the customer explicitly clicks a marker or list row.

Version 0.25.0 improves the Russian Post pickup modal UX. The map now has a neighboring visible-point list on desktop and a stacked map/list layout on mobile. Bbox loads refresh the list, which shows up to the first 100 points with index, point type, address, work time, and distance from the selected city center when `initialContext` has coordinates. Distance sorting uses haversine meters; without coordinates the list falls back to stable postcode/address ordering.

Selecting a marker or list row keeps the map, list, selected card, and confirm button in sync. The selected card shows only customer-readable fields, suppresses empty values and technical zero descriptions, and the selected list row receives `active selected`. Leaflet now uses local `divIcon` markers and a small frontend grid clusterer with numbered circles and click-to-fit behavior. Yandex uses `ymaps.Clusterer`, neutral circle-dot placemarks with type captions, active-point highlighting, and no delivery-truck preset.

Version 0.24.2 disables Leaflet's built-in attribution control in the pickup map provider so the standard OpenStreetMap/Leaflet attribution block is not shown in the lower-right corner of the pickup modal. Yandex Maps and the provider abstraction are unchanged.

Version 0.24.1 hardens the Yandex Maps pickup provider while the Yandex JS API is loading asynchronously. Calls to `setCenter()` before map readiness now update a pending center that is replayed after `ymaps.Map` creation, pending markers render after readiness, `clearMarkers()` before readiness clears queued points, viewport sizing runs after map creation, and bbox loading is triggered manually from the final ready center. Yandex API load failures are kept non-fatal and emit a debug `console.warn`.

Version 0.24.0 adds a pickup map provider architecture for the checkout pickup-point modal. Admin settings now choose between the default OpenStreetMap / Leaflet map and Yandex Maps, store a Yandex Maps API key without rendering it back into the settings field, preserve the saved key when the input is empty, and expose a clear-key checkbox. Checkout enqueues Leaflet assets only for the Leaflet provider; for Yandex it enqueues the Yandex provider adapter and loads the Yandex JS API only when the provider is selected and a key is present. If Yandex is selected without a key, checkout continues and the modal shows a Russian setup error instead of trying to load the API.

Version 0.23.12 simplifies the DaData coordinate batch query to `postal_code + display_name` only, so rows like `186752, респ Карелия, г Сортавала, поселок Уусикюля` are sent without duplicated region/city/place fragments. Coordinate batch JSON now includes explicit skip counters: `skipped_empty_query`, `skipped_no_dadata_success`, `skipped_no_coordinates`, `skipped_invalid_coordinates`, plus `last_skip_reason` and `last_dadata_message`.

Version 0.23.11 adds an admin DaData coordinate fill action for locations. On `?page=wdc-platform-locations`, the DaData block is now "Заполнение информации через DaData" and includes "Получить координаты через DaData"; it batch-processes RU locations whose `latitude/longitude` are NULL or `0.0000000`, starts with city rows, and stores valid `geo_lat/geo_lon` through `LocationRepository::update_coordinates()`. This keeps the checkout pickup map dependent on prepared local city coordinates instead of extra frontend search complexity.

Version 0.23.4 keeps enriched city coordinates live on checkout after WooCommerce AJAX recalculation. After `updated_checkout`, the pickup frontend reads fresh checkout `city_context` from the nonce-protected checkout state endpoint, updates city picker hidden `lat/lng` fields, and prefetches the starting Russian Post pickup bbox for the active RU pickup method. When the modal opens, cached `preloadedPoints` render markers immediately while normal bbox refresh remains active.

Version 0.23.3 makes the pickup map initial context DOM-first after WooCommerce AJAX checkout updates. Each modal open now rereads current city picker hidden `lat/lng/postcode/display_name` fields, then visible checkout postcode/city/country fields, and uses the localized `window.wdcPickupCheckout.initialContext` only as a stale-safe fallback.

Version 0.23.2 fixes the pickup map startup path for checkout cities without saved coordinates. The modal now uses saved RU city coordinates immediately when they exist; otherwise it runs the initial local pickup search by postcode/city before any bbox load, centers on the first found point as a preview only, and falls back to Novosibirsk only when there is no usable city query. City picker selection/resolve can enrich missing local city coordinates through DaData once, save them to the locations table, and carry `lat/lng` in checkout `city_context`; if DaData returns no coordinates, checkout continues with the search fallback.

Version 0.23.1 fixes checkout-map blockers before browser testing: `CheckoutValidation.php` now uses readable Russian validation strings, Leaflet is loaded from the single local `assets/vendor/leaflet/` copy, all checkout state endpoints including `GET /checkout/state` require a REST nonce, the map starts from the current checkout city/postcode or saved city coordinates when possible, and switching shipping methods no longer clears the selected pickup point. Pickup selection is reset only when city/country/postcode changes.

Version 0.23.0 starts the checkout MVP for Russian Post domestic pickup points. The checkout now renders a "Выбрать пункт выдачи" control for `russian_post_domestic_pickup`, opens a mobile-friendly Leaflet/OpenStreetMap modal, loads points only by map bbox through `/wp-json/wdc/v1/points`, stores the selected point in WooCommerce session through `/wp-json/wdc/v1/checkout/pickup-point`, validates the required pickup selection server-side, and saves HPOS-safe pickup meta/snapshot on the order and shipping item.

Version 0.22.33 polishes the Russian Post pickup import admin tab: the live status block is collapsible and starts collapsed, its summary shows status/stage/parsed/inserted, weekly scheduling shows the next planned run or a Russian warning, the ready Basic key field is removed, and the tab/status labels are localized in Russian. The Otpravka Basic authorization header is computed from Login + Password.

Version 0.22.32 unifies manual Russian Post pickup imports into one ZIP/TXT/JSON upload. The fallback chain is now automatic cURL download, automatic WP HTTP download, manual ZIP upload, then manual TXT/JSON payload upload. TXT/JSON uploads skip download and ZIP extract entirely and enter the resumable batch pipeline at `stage=parse`.

Version 0.22.31 fixes the extracted ZIP payload path safety check on Windows: paths are normalized before comparison, Windows drive/path case is handled case-insensitively, and boundary checks prevent sibling paths like `/tmp/base2` from passing as inside `/tmp/base`.

Version 0.22.30 makes manual ZIP extract fail-fast and diagnosable. Import state/status now records `extract_*` fields, ZipArchive availability, payload entry name/index/size, and extract errors. ZIP payloads are extracted with `ZipArchive::extractTo()` into a temp directory and copied stream-to-stream into the resumable payload file. A stale `extract` stage older than 5 minutes fails, unlocks, and cleans temp files/staging.

Version 0.22.29 fixes cleanup for failed manual ZIP import queueing: if the uploaded file is already stored but the background import cannot be queued because another import is running, the uploaded ZIP is deleted and state records `Unable to queue ZIP import. Another import may be running.`

Version 0.22.28 adds a production-safe manual ZIP import path for Russian Post pickup points. Admins can download `unloading-passport` outside WordPress, upload the ZIP on the "ПВЗ / ОПС" tab, and process it through the same resumable background staging pipeline without relying on WordPress HTTP/Action Scheduler for the large download step. Import state now records `source=api_download|uploaded_zip`, uploaded filename, uploaded size, and temp ZIP path; cleanup removes uploaded ZIPs on extract, finalize, fail, cancel, or stale reset.

Manual ZIP downloads can use this PowerShell template with placeholder credentials:

```powershell
$AccessToken = "ВАШ_ACCESS_TOKEN"
$Login = "ВАШ_LOGIN"
$Password = "ВАШ_PASSWORD"
$BasicAuth = [Convert]::ToBase64String([Text.Encoding]::UTF8.GetBytes("$Login`:$Password"))
$OutFile = "D:\russian-post-passport-all.zip"
Invoke-WebRequest `
  -Uri "https://otpravka-api.pochta.ru/1.0/unloading-passport/zip?type=ALL" `
  -Headers @{ "Authorization" = "AccessToken $AccessToken"; "X-User-Authorization" = "Basic $BasicAuth"; "Accept" = "application/octet-stream" } `
  -OutFile $OutFile `
  -TimeoutSec 300
```

Version 0.22.27 further lightens the Russian Post pickup table for the map stage: fresh schema removes brand, e-commerce JSON, services/phones/images, weight/size limits, and payment/inspection flags. The table now keeps only identity, type, postal/address/FIAS/GAR fields, coordinates, geohash, description, work time, active/source hash, and timestamps. Recreate local test data with `DROP TABLE IF EXISTS wp_wdc_pickup_points_russian_post;` before reimport.

Version 0.22.26 adds a direct cURL passport ZIP download backend before WordPress HTTP streaming. If cURL fails, import falls back to WP HTTP and stores backend, fallback, cURL errno/error, and first backend diagnostics in state.

Version 0.22.25 makes Russian Post pickup status polling perform stale checks: the AJAX status endpoint now refreshes import state through the importer, so a stuck `running/download` state older than 5 minutes is failed, unlocked, and cleaned up by ordinary status polling.

Version 0.22.24 improves Russian Post passport download diagnostics: default timeout is now 120 seconds, settings clamp to 30..300 seconds, streamed requests include connect timeout, import state records download URL/start/duration/HTTP/message/temp size/error, and stale download is failed after 5 minutes.

Version 0.22.23 compacts the Russian Post pickup table before the map stage: fresh tables no longer store `raw_reference` or `work_time_json`, `workTime` is normalized during import into readable `work_time`, and successful finalize runs `ANALYZE TABLE wp_wdc_pickup_points_russian_post`. Existing test tables are not migrated; remove them before reimport with `DROP TABLE IF EXISTS wp_wdc_pickup_points_russian_post;`.

Version 0.22.22 makes the Russian Post pickup table swap recovery-safe: after `RENAME TABLE` the importer verifies that `wdc_pickup_points_russian_post` exists, restores it from backup when possible, keeps backup on unrecovered failure, and records a clear swap error in import state.

Version 0.22.21 moves Russian Post pickup points out of the generic `wdc_pickup_points` table into `wdc_pickup_points_russian_post`. Imports now build a full snapshot in a staging table and atomically swap it into place, REST reads only the carrier-specific main table, and the old `wp_wdc_pickup_points` table can be dropped manually with `DROP TABLE IF EXISTS wp_wdc_pickup_points;`.

Version 0.22.20 adds public read-only REST endpoints for the local pickup database: `GET /wp-json/wdc/v1/points`, `GET /wp-json/wdc/v1/points/search`, and `GET /wp-json/wdc/v1/points/{id}`. The endpoints support carrier/type/limit filters, bbox validation, search, and safe point detail responses without raw import snapshots or secrets.

Version 0.22.13 changes Russian Post pickup import into a resumable background batch pipeline. The init job only downloads/extracts the payload, each batch job parses and upserts 75 objects from the saved payload offset, and finalize deactivates missing points and cleans temp files, so one PHP process no longer writes to MySQL for the whole import.

Version 0.22.12 adds timeout-safe diagnostics for Russian Post pickup background import: Otpravka ZIP download timeout defaults to 300 seconds, failed downloads store HTTP/WP error details and a short body excerpt, stale download stages are failed after 15 minutes, and admins can manually cancel/reset a stuck import without deleting imported points.

Version 0.22.11 keeps Russian Post pickup import state honest when a background job cannot be scheduled: `queued` is saved only after the job is actually created, otherwise state becomes `failed` with `Unable to schedule background import job.`.

Version 0.22.10 runs Russian Post pickup import in the background from the admin UI. The page returns immediately, stores live state in `wdc_russian_post_pickup_import_state`, and polls progress every 3 seconds while the job is queued or running. Stale queued/running locks older than 2 hours are marked failed so a new import can be started; the current ALL test import produced 37302 active points.

Version 0.22.01 fixes Russian Post pickup import identity and temp-file cleanup: `point_code` is now unique per concrete point even when several objects share one postcode, and imported ZIP files are deleted after reading.

Version 0.22.00 adds the production foundation for a local Russian Post pickup-point directory. It extends `wdc_pickup_points`, adds shared API "Отправка" credentials/client classes, imports `unloading-passport` ZIP data through `RussianPostPickupImporter`, exposes manual import/status on the domestic pickup service tab, and keeps checkout map/REST/selected-point persistence for the next stage.

Version 0.21.29 removes deprecated domestic defaults `27030`, `27020`, `28030`, and `28020` while preserving old saved tariff JSON, and simplifies the order calculation metabox by hiding VAT status and technical service keys.

Version 0.21.28 hides domestic selector/runtime technical keys from visible WooCommerce shipping item meta and stores both original API delivery range and final rule-adjusted delivery range in `_wdc_delivery_calculation_data`.

Version 0.21.27 adds skipped-tariff diagnostics for Russian Post domestic API errors, extends courier variants with EMS object codes, formats delivery days with Russian plural forms, and makes domestic checkout/order method titles include service, selected tariff, and delivery range.

Version 0.21.26 keeps Russian Post domestic single-tariff grouped delivery days in the WooCommerce method label, suppresses the separate planned-delivery line for that single-tariff case, and leaves multi-tariff timing only inside selector rows.

Version 0.21.25 gives Russian Post domestic pickup/courier services distinct predefined admin titles, keeps courier availability bootstrapped for RU, uses final rule-adjusted delivery days in checkout and orders, supports internal tariff admin comments, shows per-variant crossed prices, and hides the radio selector when only one tariff is available.

Version 0.21.24 preserves the current enabled state when predefined Russian Post domestic services are upserted, so bootstrap reactivates soft-deleted system rows without undoing a normal admin toggle-off.

Version 0.21.23 protects predefined delivery services from deletion, keeps Russian Post domestic service bootstrap idempotent by service_key, renders domestic service simulation across active tariffs, and labels checkout/order shipping as `Почта России — {тариф}` without duplicating the delivery-days comment under the method.

Version 0.21.22 preserves a valid user-selected Russian Post domestic tariff during repeated checkout recalculations; the first available tariff is saved only as an initial/default fallback.

Version 0.21.21 fixes Russian Post domestic foundation blockers: tariff variant object-code mappings now match the domestic API catalog, postcode fallback can use the existing DaData enrichment path, API item summaries keep nested service/tariff/delivery fields, and API `errorcode`/`errormsg` responses are treated as errors.

Walls Delivery Calc is a WooCommerce delivery calculator plugin. The runtime is now `src/` only: the old `includes/*` legacy bootstrap, shipping method, carriers, API clients, settings, helpers, and cache wrappers have been removed.

This branch targets fresh installs only. Compatibility migrations for old legacy state are not part of the active install path. Current migrations create the active platform schema: calendar, locations/GAR, pickup points, rules, DaData-related settings/options, Russian Post country mappings, and delivery service tables.

## Runtime

- Main plugin file loads `src/Core/bootstrap.php`.
- `WallsShop\WDC\Core\Plugin` registers the service container, hooks, activation install, migrations, WooCommerce shipping method, checkout runtime, admin pages, and scheduled jobs.
- `CarrierRegistry` registers the current real carrier: Russian Post international.
- `DeliveryServiceRegistry` and `DeliveryServiceManager` wrap carriers as persistent delivery services.
- Demo JSON fixtures live under `tests/fixtures/demo` and are not used from runtime paths.

## Russian Post International

Russian Post international delivery runs through the `src` architecture:

- `WallsShop\WDC\Carriers\Runtime\RussianPostInternationalCarrier`
- `WallsShop\WDC\Carriers\RussianPost\RussianPostApiClient`
- `WallsShop\WDC\Carriers\RussianPost\RussianPostCountryDirectory`
- `WallsShop\WDC\Carriers\RussianPost\RussianPostCountryMappingRepository`
- `WallsShop\WDC\Carriers\RussianPost\RussianPostSettings`

The carrier is international-only, excludes `RU`, uses shared package and packaging-weight logic, caches quotes until the end of the current WordPress day, and returns configured manager fallback rates for API/availability failures when enabled. It returns API/VAT base price only; the old `/0.89 + 200` built-in markup has been removed.

## Delivery Services

Version 0.21.0 adds persistent delivery services:

- `wdc_delivery_services`
- `wdc_delivery_service_settings`
- `wdc_delivery_service_countries`

Russian Post international is auto-created as `russian_post_worldwide_parcel`. Service-specific rules can override default rules, and default fallback is controlled per service. Service post-processing applies minimum price and ruble rounding after rules while preserving zero fallback rates.

Version 0.21.1 makes the rules admin reusable: the default rules page and each delivery service's `Правила` tab use the same controller with different target context. Service tabs can copy current default rules into service-specific rules, simulation stays separated by target, quote cache keys include `service_key`, and `minimum_price_rub` is normalized to a non-negative decimal.

Version 0.21.3 adds real service edit tabs. Main, availability, calculation, rules, and Russian Post countries now render separate content. Russian Post service settings moved out of platform settings and into the service calculation tab, stored in `wdc_delivery_service_settings`; the Russian Post countries UI is embedded as a service tab. New rules default to `condition_1`, no-condition summaries show `Нет условий`, and Russian Post service simulation calls the carrier before applying service rules only.

Version 0.21.4 removes the remaining standalone Russian Post countries admin page surface. The countries admin class is embedded-only and is reachable through the Russian Post delivery service tab.

Version 0.21.5 removes the last dead standalone render branch from the embedded-only Russian Post countries admin.

Version 0.21.12 adds structured order calculation data. The selected delivery calculation is saved to `_wdc_delivery_calculation_data`, and the WooCommerce order metabox `Калькулятор доставок` renders the readable admin view. For Russian Post international, visible shipping item meta is reduced to `Способ доставки: международная доставка Почтой России`; API, package, rules, fallback, and technical service metadata are stored in the hidden calculation payload instead.

Version 0.21.13 extends rule audit entries with operation value/base, so order formula visualization can render actual applied rule names and operations for multiply, divide, and fixed price changes.

Version 0.21.14 cleans WooCommerce shipping item meta copied from rate `meta_data` for Russian Post international and stores the actual final rate price in runtime meta after rules and service post-processing.

Version 0.21.15 makes the checkout city picker country-aware. The local location country index is stored in `wdc_location_country_codes`, checkout search/resolve accept `country_code`, supported countries search only their own local rows, and unsupported countries keep normal manual WooCommerce city/state input without modal, auto-resolve, local warning, or stale local location order meta. For RU/BY/KZ, latin city picker text is treated as transliteration or wrong keyboard layout input before database lookup.

Version 0.21.16 treats `wdc_location_country_codes` with `countries=[]` and `stale=false` as a valid initialized empty index, so empty local location tables do not trigger repeated lazy rebuilds on every checkout request.

Version 0.21.17 extends `wdc_location_country_codes` with cached per-country location counts and shows the country summary on the `Населенные пункты` admin page. Country names come from WooCommerce countries, with country-code fallback when a name is not available.

Version 0.21.18 starts Russian Post domestic carrier preparation. Old demo pickup rows for carrier `demo` are cleaned from the pickup admin page, and `docs/wdc-russian-post-domestic.md` plus `tests/carriers/run-russian-post-domestic-api-probe.php` document and probe domestic tariff candidates.

Version 0.21.19 makes the old demo pickup row cleanup one-time through the `wdc_demo_pickup_cleanup_done` option instead of deleting on every pickup admin page load.

Version 0.21.20 adds `--insecure` to the Russian Post domestic probe for local Windows environments where PHP's trust store is not configured. SSL verification remains enabled by default and the flag must not be used in production runtime.

PowerShell Russian Post domestic probe:

```powershell
php tests/carriers/run-russian-post-domestic-api-probe.php --from=630005 --to=101000 --weight=1000 --objects=4030,4020,47030,47020,54020,41030,52030,23030,23020,24030,24020,7030,7020
```

Local Windows probe when PHP reports a self-signed certificate chain and the trust store is not configured:

```powershell
php tests/carriers/run-russian-post-domestic-api-probe.php --from=630005 --to=101000 --weight=1000 --objects=4030,4020,47030,47020,54020,41030,52030,23030,23020,24030,24020,7030,7020 --insecure
```

Version 0.21.6 moves packaging weight into the new `src/` foundation. Global tiers live on `Правила расчета -> Упаковка` as `packaging_weight_tiers`; services choose whether to include packaging and whether to apply it as `total_weight` or a `WDC_PACKAGING` virtual package item. Russian Post international uses final total weight.
# Russian Post domestic foundation

This branch adds the foundation for `russian_post_domestic` with pickup/courier services, domestic tariff variants, `pack=99` tariff requests, declared-value `sumoc`, checkout tariff selector, delivery range rules, selected-tariff session persistence and public order meta for service/tariff/delivery range.
