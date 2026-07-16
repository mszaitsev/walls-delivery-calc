# WDC Shipments Foundation

Version 0.111.0 extends the existing Shipment Framework presentation contract for Yandex without adding a parallel UI. Yandex actual shipment cost is populated from the selected offer `pricing_total` during normal create, stored as integer kopecks in the common actual-cost field, compared with the saved Base API cost through the existing 3% threshold, and preserved across later `request/info` updates because no later Yandex price source exists. Manual attach remains price-less and therefore hides the price row. Yandex labels are exposed through `CarrierShipmentAdapterInterface::label_actions()` and the shared protected admin download pattern; the PDF body is streamed as binary and is not persisted. `self_pickup_node_code.code` is stored as a string and rendered as a Yandex-specific row inside the common metabox after tracking.

Version 0.110.2 separates the Yandex source drop-off initial map context from manual address-search context. Initial load continues to use `source_location_id` and Yandex location mapping, but when a manager types an address in the source-dropoff picker the JS sends an all-Russia address-search request (`carrier=yandex_delivery`, `purpose=source_dropoff`, `country_code=RU`) without `location_id`, `source_location_id`, source city or FIAS/KLADR/GAR fields. The REST controller also ignores forged `location_id` for that purpose and calls shared `PickupAddressSearchService` unscoped with `include_points=false`, so DaData receives the literal user query and the existing nearby reload then fetches Yandex drop-off points around the found coordinate.

Version 0.110.1 scopes the Yandex source drop-off picker geographically. Initial load no longer calls a global source-dropoff search: `OrderShipmentsMetabox` resolves `source_location_id` through Yandex location mapping and loads map-ready drop-off points only for those mapped/manual `yandex_geo_id` values. Address search now geocodes first, preserves the search marker, then replaces selectable markers with nearby Yandex drop-off points around the found coordinate using 10, 25 and 50 km attempts. The repository performs bbox + Haversine filtering and returns `distance_km`; after 50 km the picker shows an empty message instead of falling back to all-country points.

Version 0.110.0 extends the shared shipment modal with a temporary Yandex source drop-off selector. The existing Yandex source field remains `yandex_source_platform_station_id`, but managers can open the same admin pickup-map picker and choose another source drop-off point for this modal instance only. The map endpoint is shared and carrier-scoped (`carrier_key=yandex_delivery`, `purpose=source_dropoff`); it returns only local Yandex points that are active, have coordinates and explicitly support store drop-off through `available_for_dropoff`. The override is submitted through current FormData, drives preview/create payload `source.platform_station.platform_id`, and is not saved as settings/order/shipment preference. Backend preview/create validation repeats the repository capability check, so forged pickup-only/inactive/unknown ids do not reach carrier HTTP. Yandex destination pickup/courier selectors and all other carrier modal flows are unchanged.

Version 0.109.3 extends the shared shipment metabox tracking presentation without changing carrier lifecycle data. Carrier status payloads may provide a structured `tracking_presentation` with `label`, `display_text`, `url` and `copy_value`; old string/barcode carriers continue through the previous path. Yandex uses this to show `Отслеживание посылки: ссылка` from persisted `sharing_url`, opens the route in a new tab and copies the full URL through the existing copy button. If `sharing_url` is absent or invalid, the block falls back to `Request ID Яндекс` and copies the request id.

Version 0.109.2 preserves raw `CANCELLED` as the Yandex API confirmation of successful cancellation, independent of admin universal-status overrides. The cancellation lifecycle combines `$is_cancelled` with the universal terminal decision only inside `YandexShipmentRegistrationService::cancel()` and `update_status()`; `YandexStatusMapping` still controls business status and WooCommerce mapping. A `CANCELLED -> in_transit` override therefore updates WooCommerce through `in_transit` and then local auto-delete still removes the Yandex shipment/lookup while keeping sequence meta. Immediate terminal responses after cancel return `auto_poll=false`, so bounded cancellation polling only starts for non-terminal responses.

Version 0.109.1 tightens Yandex cancel lifecycle on top of the shared status foundation. Server-side cancel now delegates to `YandexShipmentButtonPolicy` before `request/cancel`, so forged calls cannot bypass the same universal-status policy used by the metabox. The old raw terminal list was removed from the policy; cancel polling resolves raw Yandex status through the current `YandexStatusMapping` and treats universal `delivered`, `returned_to_sender`, `cancelled` and `rejected` as terminal. Local auto-delete still happens only for raw `CANCELLED`, after universal→WooCommerce mapping has run. `PARTICULARLY_DELIVERED` now defaults to universal `in_transit`.

Version 0.109.0 adds Yandex Delivery to the existing shipment status foundation. `YandexStatusMapping` provides the carrier catalog/default mapping and the delivery-service admin tab stores overrides in the same `SettingsRepository` option family as CDEK/DPD. Canonical `request/info` persistence now saves raw Yandex status diagnostics plus resolved `universal_status_code`/label, then the shared `ShipmentOrderStatusMappingService` applies universal→WooCommerce status mapping. The metabox primary status is universal; raw Yandex status is diagnostic. Yandex button policy for canonical shipments is now universal-status based: `pending_creation_in_carrier`/`created_in_carrier` allow carrier cancel, all other universal statuses expose local remove instead. Technical states (`reconciliation_required`, `cancellation_started`, poll exhaustion) stay separate and are not carrier API statuses.

Version 0.108.15 tightens the Yandex pre-production fixes. Courier DaData verification now reads locality from the normalized suggestion shape returned by `AddressSuggestionNormalizer`: canonical fields are preferred, settlement/city-with-type fields are supported, and region-as-locality is allowed only for federal cities. Duplicate `operator_request_id` auto-skip is limited to the exact Yandex production duplicate-code phrase (`already was request with such code within this employer`); generic `duplicate`, duplicate barcodes, HTTP 500 and transport failures stay single-attempt failures. During Yandex cancel polling, `cancellation_started` hides local remove until `yandex_cancel_poll_exhausted=true`; after exhaustion update + local remove are available, and canonical `CANCELLED` still auto-removes the local shipment while preserving sequence.

Version 0.108.14 closes the last Yandex pre-production UX gaps without changing the core HTTP lifecycle. Duplicate `operator_request_id` errors from `offers/create` are now auto-skipped inside one create click: the service reserves the next sequence id, rebuilds the payload and retries only `offers/create`, with a hard limit of 10 occupied ids. `offers/confirm` is still executed once and only after a successful offer response; unknown errors are not retried. Yandex cancel now behaves like bounded async status reconciliation: `request/cancel` persists `cancellation_started`, returns cancellation polling metadata (5000 ms × 14), and polling calls only `request/info`. `CANCELLED` triggers automatic local cleanup while preserving the sequence meta; exhaustion persists cancel-specific attempts/timestamp and leaves update + local remove. Manual attach accepts any valid Yandex `request_id`; sequence is synchronized only for operator ids in the current order family. Yandex courier preparation now requires explicit address verification through the existing DaData suggestions flow before structured destination fields are submitted.

Version 0.108.13 keeps the Yandex `operator_request_id` sequence persistent but compact. The sequence meta `_wdc_yandex_delivery_registration_sequence` now stores only `last_index`, `last_operator_request_id`, optional `current_attempt` and `updated_at`; it no longer stores a growing list of all allocated ids. The next id is still derived as `last_index + 1`, so an empty order starts at `1010`, then `1010/1`, `1010/2`, and so on. Reservation before real `offers/create`, no rollback after HTTP starts, the order-level lock, own-family manual attach sequence sync and slash-free temporary barcodes remain unchanged. Old 0.108.12 state with an `allocated_ids` key is ignored and normalized to the compact shape on the next repository read/save.

Version 0.108.12 adds persistent Yandex registration attempt numbering without changing the shared Shipment Framework or carrier HTTP contract. `YandexShipmentRepository` stores `_wdc_yandex_delivery_registration_sequence` separately from `_wdc_shipments`, so local remove, cancel, terminal status and reload do not reset the last Yandex `operator_request_id` index. The first Yandex create attempt uses the WooCommerce order number, and later independent attempts use `/1`, `/2` suffixes. Preview only peeks the next value; the actual reservation happens in `YandexShipmentRegistrationService::create_for_order()` immediately before real `offers/create`. After that point the id is considered used and is not rolled back even if the transport/API result is unknown. A short order-level lock prevents double-click/two-tab requests from allocating the same id at the same time. Since 0.108.14 manual attach accepts any valid request_id; the 0.108.12 strict family parser remains only for upward sequence synchronization when the attached operator id belongs to the current order family. Temporary place barcodes stay slash-free (`1010/1` becomes `1010-1-1`) while the actual `operator_request_id` remains `1010/1`.

Version 0.108.11 closes the gap between shipment metabox buttons and server actions for Yandex. Local remove now calls the same Yandex button policy on the server before deleting repository data: active `CREATED` and `cancellation_started` shipments are protected even if AJAX is sent manually, while `reconciliation_required` and terminal statuses remain removable. The shared bounded polling helper also distinguishes pending responses from transport failures. For generic/Yandex registration polling, HTTP/network/JSON errors count toward the bounded attempts and schedule the next tick instead of stopping after the first failure; `mode=dpd` preserves its previous stop-on-error behavior, and CDEK polling is unchanged.

Version 0.108.10 persists Yandex registration polling exhaustion on the server and aligns metabox buttons with the adapter after reload. A saved `reconciliation_required` Yandex shipment always remains an existing shipment with update + local remove available and create/manual attach/cancel hidden, even if the page was reloaded before 14 polling attempts finished. Since 0.108.15 `cancellation_started` exposes update during active cancel polling and adds local remove only after `yandex_cancel_poll_exhausted=true`, with a warning that the WooCommerce record is removed locally and Yandex status is unchanged. The shared `wdc_mark_shipment_poll_exhausted` AJAX action lets carriers persist exhausted polling state without changing the status endpoint; Yandex stores attempts/timestamp and keeps request id, lookup meta and selected offer audit. Polling attempts are not restored after reload, repeated pending toasts are suppressed, and local remove stops/invalidates the active polling run so stale responses cannot visually restore a removed shipment.

Version 0.108.9 adds bounded accepted-reconciliation polling for Yandex shipment registration. When `offers/confirm` succeeds but immediate `request/info` is still incomplete, the shared create flow persists the pending shipment and returns success/accepted so the modal closes and the metabox refreshes. The existing status AJAX path is then polled by the shared registration polling helper every 5 seconds up to 14 attempts; confirm is never repeated. If canonical Yandex `state.status` arrives, reconciliation is cleared and the status value renders once after the carrier label. If polling is exhausted, the UI keeps update available, shows local remove, and warns that local removal does not cancel the Yandex shipment.

Version 0.108.8 makes manual factual parcel input the shared shipment modal contract for every carrier. Initial editable place `weight_g`, `length_cm`, `width_cm` and `height_cm` are rendered empty without mutating the draft; all draft places, place numbers and item allocation rows remain visible. Submitted `places[]` remain the only source for preview/create, so empty fields fail existing strict validation instead of falling back to calculated package values. A single initial place shows only the compact calculated-weight hint `⚖️{weight}`; calculated dimensions are not shown as hints. Yandex manual attach still uses the existing generic manual attach UI, now labelled `Ввести номер Яндекс вручную` with `Request ID Яндекс` and placeholder `***-udp`.

Version 0.108.7 added Yandex manual attach through the shared shipment metabox. The entered value is treated as `request_id`, verified through `request/info`, checked against `operator_request_id`, and persisted as canonical request/info state.

Version 0.108.6 adds a carrier-neutral successful-preview gate for shipment creation. Carriers that set `requires_successful_preview` in modal capabilities keep the create button disabled until preview succeeds without errors; currently this applies to Yandex and DPD. Preview/create AJAX boundaries return controlled JSON errors and public shipment validation messages are Russianized at the metabox boundary.

Version 0.108.5 keeps the existing shared shipment modal and adds Yandex-specific modal presentation through the current draft/metabox/preview pipeline: one concrete Yandex service variant, no tariff/postoffice requirement, read-only source station, destination snapshot/address fields, ready interval hidden values, populated initial places and JSON-safe preview errors. Yandex preview remains local-only and does not call carrier HTTP.

Version 0.37.2 separates Russian Post cancellation from local WooCommerce shipment removal and uses an inline SVG tracking-copy icon. Version 0.37.0 adds Russian Post backlog cancellation and manual ШПИ attachment while keeping documents/labels outside the plugin workflow. Version 0.36.4 stores Russian Post Otpravka `result-id` as the explicit technical shipment-state field `backlog_order_id` while keeping barcode/ШПИ as the primary tracking identifier. Version 0.36.2 closes the shipment preparation modal after successful create, shows a 10-second toast, and automatically runs the first Russian Post status refresh. Version 0.36.0 added manual Russian Post tracking status refresh from the existing `Обновить статус` button in the order metabox. Version 0.35.2 keeps the manual shipment runtime foundation on the unified Russian Post domestic service and removes the need for visible technical WooCommerce shipping item meta or pickup-code data in `shipping_address_2`. Version 0.34.0 added the admin-only Russian Post OPS/PVZ selector for shipment drafts. The scope is intentionally manual and carrier-neutral, with Russian Post as the first adapter.

## Scope

- Shipments are never created automatically.
- A manager opens the WooCommerce order admin metabox `Отправления`, checks the draft, edits recipient, delivery scenario (`pickup` or `courier`), tariff, pickup/address, postoffice-code and parcel places, then clicks `Создать отправление`.
- The first adapter calls Russian Post Otpravka `PUT /2.0/user/backlog`.
- For `Почта России -> ПВЗ/ОПС`, a manager can choose another local Russian Post pickup point inside the shipment modal. The selection is used only for the shipment draft/preview/create request.
- Manual Russian Post status refresh is included for created shipments with barcode. The first refresh runs automatically after successful create; background polling is not included in this stage.
- Russian Post cancellation is included for backlog shipments that are still at operation `28 / Присвоение идентификатора`.
- Manual ШПИ attachment is included for shipments created manually in the Russian Post account.
- Russian Post labels, batches, F103 and documents are not created or downloaded in WDC; managers prepare them manually in the Russian Post account.
- Checkout, tariff calculation, the saved order delivery method and WooCommerce order meta are not changed by the admin pickup selector.

## Code

- `src/Shipments/Contracts/CarrierShipmentAdapterInterface.php` defines the carrier-neutral adapter contract.
- `src/Shipments/Application/ShipmentModalRequestMapper.php` parses the shared shipment modal `places[]` and canonical `shipment_items[]` allocation rows for carrier admin submit paths.
- `OrderShipmentDraftFactory::draft_array()` may expose small carrier-neutral modal capabilities such as `requires_tariff=false`; the metabox uses them without adding a second modal architecture.
- `src/Shipments/Application/ShipmentMetaboxButtonPolicy.php` resolves common metabox button capabilities from carrier status payload first and falls back to legacy status/barcode rules only when a capability is absent.
- `src/Shipments/Application/OrderShipmentDraftFactory.php` builds shipment drafts from HPOS-safe WooCommerce order APIs and saved WDC order meta.
- `src/Shipments/Application/ShipmentCreationService.php` performs order/request mismatch validation, adapter dispatch, mandatory persistence-mapper preflight, duplicate checks, common shipment envelope creation and repository save. Carrier-specific create fields, snapshots and order notes live in `CarrierShipmentPersistenceMapperInterface` implementations; a missing mapper blocks create before preview/API/repository side effects.
- `src/Shipments/Application/ShipmentStatusUpdateService.php` manually refreshes shipment status through Russian Post Tracking API and saves carrier-neutral status state.
- `src/Shipments/Presentation/ShipmentActualCostComparisonService.php` formats and compares actual carrier cost for shipment status payloads. Inputs are integer kopecks; absent actual cost hides the price row, exactly +3% over Base API cost is ok, and one kopeck above is warning. The low-level service can render explicit zero, but production carrier boundaries treat actual cost `0` as unknown.
- `src/Shipments/Presentation/ShipmentBaseApiCostResolver.php` reads the existing saved checkout Base API cost from order calculation meta without changing shipment persistence. It returns only positive base values; zero base values are treated as absent.
- `src/Shipments/Application/ShipmentServiceSettings.php` owns per-service shipment settings.
- `src/Shipments/Storage/OrderShipmentRepository.php` stores shipment state in order meta through WooCommerce CRUD.
- `src/Shipments/RussianPost/*` maps domestic tariff object codes, builds safe backlog payloads, normalizes create responses and contains Russian Post tracking status mapping.
- `src/Carriers/RussianPost/Tracking/RussianPostTrackingApiClient.php` calls `getOperationHistory` over SOAP 1.2.
- `src/Shipments/Admin/OrderShipmentsMetabox.php` renders the order metabox plus AJAX preview/create/search/status actions.
- `assets/admin/shipments-admin.js` and `assets/admin/shipments-admin.css` provide the admin modal behavior.
- `assets/frontend/pickup-map/providers/*` and the configured Leaflet/Yandex provider are reused for the admin pickup selector; no second map stack is introduced.

## Russian Post Payload

The adapter builds an array of backlog order objects. For one place it sends one order object. For multiple places it sends one object per place and sets:

- `add-to-mmo=true`;
- `group-name=<WooCommerce order number>`;
- per-place `mass` and `dimension`.

The builder fills `order-num`, `mail-type`, `mail-category`, `postoffice-code`, recipient fields, `payment=0`, `compulsory-payment=0`, `delivery-with-cod=false`, `payment-method=CASHLESS`, and `notice-payment-method=CASHLESS`. Ordinary parcel/courier/EMS variants use `mail-category=ORDINARY`; declared-value variants use `WITH_DECLARED_VALUE`.

Domestic Russian Post shipment payloads always send `mail-direct=643`.

Normal pickup/OPS shipments are not treated as ECOM by default. They send `address-type-to=DEMAND`, `index-to`, `region-to`, and `place-to`; `ecom-data` is omitted. In the admin modal the human-readable address is shown as `{index}, {region}, {place}, до востребования`.

ECOM pickup shipments use `ecom-data.delivery-point-index` and omit the normal recipient address schema unless a later Russian Post product explicitly requires it. The ECOM decision comes from the per-tariff `is_ecom` setting in Delivery Services, not from a hard-coded object code. Object `54020` still maps to `ECOM_MARKETPLACE`, but it only uses `ecom-data` when the tariff setting is enabled.

Courier shipments use normalized Russian Post address fields from `RussianPostAddressNormalizer`: `address-type-to`, `index-to`, `region-to`, `area-to`, `place-to`, `location-to`, `street-to`, `house-to`, `slash-to`, `letter-to`, `building-to`, `corpus-to`, `room-to`, and `num-address-type-to`. The modal shows the original shipping address and requires the manager to run `Обработать адрес`; successful creation is blocked until a valid normalization result matches the original-address hash. If the address changes, the cached normalized payload is cleared. Failed normalization may be shown in preview fallback, but it is not accepted for create.

`tel-address` is normalized to digits only before payload creation. If the normalized phone is empty, validation returns `Телефон получателя обязателен.`

The admin parcel-place UI accepts only integer values. Insurance is entered in rubles and converted before payload creation to Otpravka kopecks: `1000` rub -> `insr-value=100000`.

Postoffice acceptance indices are configured on `WDC -> Службы доставки -> Почта России по РФ -> API / Credentials` as `Индексы места приема для регистрации отправлений`. The default list contains `630005`; each configured value must be a 6-digit index and is used in the modal select for `postoffice-code`. These indices are separate from tariff calculation `from_postcodes` on `Расчет`. `default_from_postcode` is edited on the same tab after the postoffice-code list while retaining its existing storage key.

`dimension-type` and `prepaid-amount` are not sent by default. `goods` is omitted unless service setting `send_goods_items=true`.

## Storage

Successful creation stores `_wdc_shipments` on the order with:

- `carrier_key`, `service_key`, `order_id`, service title and delivery type;
- places snapshot;
- safe request preview without headers/secrets;
- normalized response snapshot without raw headers;
- barcode/tracking number, order number, group name;
- `backlog_order_id`, parsed from Otpravka `result-id` and reserved for internal Otpravka backlog operations such as cancellation;
- status `created`, `created_at`, `updated_at`.

Manual status refresh extends the same shipment state with:

- `universal_status_code`, `universal_status_label`;
- `carrier_status_title`, `carrier_status_description`;
- `carrier_operation_type_id`, `carrier_operation_type_name`;
- `carrier_operation_attr_id`, `carrier_operation_attr_name`;
- `carrier_operation_date`, `carrier_operation_address`, `carrier_operation_index`;
- `carrier_status_is_terminal` when the mapping marks the pair terminal;
- `tracking_checked_at`, `tracking_raw_snapshot`.

Credentials are never stored in order meta.

Otpravka `result-id` is parsed as part of the create API response and saved separately as `backlog_order_id`. It is not used as the primary shipment identifier: barcode/ШПИ remains the tracking number shown to managers and used by the Tracking API. In 0.37.0 `backlog_order_id` is used by cancellation (`DELETE /1.0/backlog`) and kept hidden in the metabox. It is not shown to customers, emails, account pages, public tracking blocks, or toasts.

Manual attachment saves shipment state with `source=manual_tracking_attach`, entered barcode/ШПИ, lookup source (`backlog_search` or `shipment_search`), returned `backlog_order_id` when present, status `created`, timestamps and a minimal safe response snapshot. Lookup uses `GET /1.0/backlog/search?query={barcode}` first and falls back to `GET /1.0/shipment/search?query={barcode}`.

In 0.37.2 the metabox has two cleanup actions:

- `Отменить отправление` cancels in Russian Post through `DELETE /1.0/backlog` and then clears shipment state. It is available only when the latest Russian Post operation is `28 / Присвоение идентификатора` and `backlog_order_id` exists.
- `Удалить из заказа` clears only WooCommerce shipment state through `OrderShipmentRepository::delete_for_carrier()` and does not call Russian Post. It is used when a shipment already cannot be cancelled in Russian Post or when the status has not been refreshed yet.

Failed creation stores `_wdc_shipment_last_error` with safe error code/message and adds a short order note. If a Russian Post shipment is already `created` or `registered`, repeat creation is blocked.

## Settings

`WDC -> Службы доставки -> Почта России по РФ -> API / Credentials` is the editing location for Tariff API and Otpravka credentials:

- Tariff API endpoint;
- Tariff API token;
- AccessToken;
- login;
- password;
- timeout;
- postoffice codes;
- default postoffice/from index for shipment registration.

Tracking login/password fields are used by manual status refresh:

- `russian_post_tracking_login`;
- `russian_post_tracking_password_encrypted`.

They are separate from Otpravka credentials and Tariff API token.

Shipment drafts read delivery type, selected tariff, service key and pickup point data from hidden WDC order meta and `_wdc_delivery_calculation_data`; they do not depend on visible shipping item meta such as `wdc_delivery_kind`, `delivery_kind`, `checkout_group_id`, `Пункт выдачи`, `Индекс ПВЗ`, or `Тип ПВЗ`. Pickup point type is shown in the WooCommerce order metabox `Калькулятор доставок` under `Код ПВЗ`.

Domestic Russian Post exposes shipment settings on the service `Отправления` tab:

- `shelf_life_days_default`, clamped to 15..60, default 30;
- `send_goods_items`, default false;
- `combine_goods_items_default`, default true;
- `combined_goods_name_template`, default `Товары по заказу {order_number}`.

## Tests

Smoke coverage:

```powershell
php tests/shipments/run-russian-post-shipments-smoke.php
php tests/shipments/run-shipment-status-smoke.php
```

The smoke tests cover Russian Post backlog payload building, status mapping, SOAP parsing/fault/empty-history handling, shipment status persistence, AJAX response shape and metabox/JS status UI wiring without real API credentials.

The status smoke also covers no-attribute fallback through `type:-`, including operation `28` -> `создан в ТК` and operation `46` -> `отменён`.

## Admin Preview And Pickup Selector

The preparation modal renders a safe server-side API payload preview. Field changes, service/tariff changes and place add/remove actions refresh the preview through debounced AJAX; if preview refresh temporarily fails, the old preview stays visible and the UI shows a warning.

The shipment modal is intentionally compact for manual preparation: parcel/place inputs accept integer-only values, the calculated order weight is shown in the weight label instead of being written into the editable value, and the declared-value field is shown only for tariffs whose shipment product has declared value. Place rows are compact, support add/remove for MMO-capable products, and feed both the preview and final Otpravka backlog payload. After a successful create the modal closes, the metabox shows the tracking number/toast, and the first status update starts automatically.

The pickup section shows the selected OPS/PVZ index and address plus `Выбрать другой ПВЗ`. The picker opens as a second modal above the shipment modal, searches local `wp_wdc_pickup_points_russian_post` rows by `postcode`, `city_name` and `address` through `wdc_search_russian_post_pickup_points`, renders found points on the configured map provider, and shows a table with index, city, address and choose action.

Selecting a point updates only shipment draft fields: `pickup_point_code`, `pickup_point_postcode`, `pickup_point_found`, `pickup_point_row`, `recipient_address` and the visible pickup index/address. It immediately calls `requestPreview(form)` so the preview/create payload uses the selected point. The selector does not write WooCommerce order meta and does not change checkout or tariff state.
## Shipment Modal Extensions 0.116.1
Carrier-specific shipment modal field markup and presentation context are now owned by small extensions registered through `ShipmentModalExtensionRegistry`. The common metabox keeps the modal shell and common places/items/preview/create lifecycle while CDEK, DPD, Russian Post and Yandex extensions render carrier-owned delivery, pickup and courier fragments without a generic form builder. In 0.116.1 the shared render path also dropped carrier defaults and duplicated tariff preparation: tariff requirements, successful-preview gates, tariff options and declared-value presentation come from draft capabilities plus modal extension context. Existing input names, data selectors and JavaScript behavior are preserved. Carrier-specific AJAX/pickup backend responsibilities remain as explicit follow-up debt.

## Shipment Admin JS Modules 0.117.0
The shipment admin runtime is split by ownership. `shipments-admin.js` remains the enqueue/localization target and final bootstrap, `shipment-events.js` owns only carrier-neutral DOM event wiring, and generic modules own core helpers, preview, status rendering, polling, allocation and pickup/map behavior. Carrier-specific JavaScript lives under `assets/admin/shipments/extensions/` for CDEK, DPD, Russian Post and Yandex. In 0.117.1 carrier DOM selectors, sender/source picker handling, document-click handling and address-normalization post-processing moved behind small carrier hooks registered before `shipment-events.js` loads. In 0.117.2 polling and status rendering were tightened as carrier-neutral modules: DPD lifecycle wrappers and places summary, Yandex cancellation/self-pickup presentation and CDEK default registration polling live in carrier extensions, while document visibility uses normalized `label_actions`. In 0.117.3 Yandex polling hooks became carrier-scoped by canonical key `yandex_delivery`, and structure smoke validates duplicate functions, top-level `const`/`let` declarations and function/lexical collisions in production script order. The split is behavior-neutral: existing selectors, field names, AJAX actions, payload shape, polling timing and carrier flows are unchanged.

## Shipment Document Actions 0.115.0

- Common shipment document actions are represented by `ShipmentDocumentAction` and streamed as `ShipmentBinaryDocument`.
- `ShipmentDocumentDownloadService` owns the protected `admin_post_wdc_download_shipment_document` shell: capability, nonce, order lookup, persisted shipment lookup, provider/action resolution, server-side policy and binary response headers.
- Carrier providers remain responsible for API endpoints, identifiers, payloads, PDF/ZIP validation and filenames. The common layer does not know CDEK UUIDs, DPD event codes, Yandex request IDs or Russian Post backlog rules.
- Russian Post uses persisted `backlog_order_id` as the canonical backlog form identifier and supports only single-shipment pre-batch printable PDF download.
