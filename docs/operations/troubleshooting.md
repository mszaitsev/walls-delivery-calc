# Troubleshooting

Version: 0.132.3

Start with:

```bash
php tests/shipments/run-shipment-regression-profile.php --list
php tests/shipments/run-shipment-regression-profile.php --group=framework
php tests/shipments/run-shipment-regression-profile.php
```

If a carrier shipment UI issue appears, check in this order:

1. adapter registration in `CarrierShipmentAdapterRegistry`;
2. persistence mapper registration in `ShipmentCreationService`;
3. document/modal registries if UI buttons or modal fields are missing;
4. AJAX controller nonce/capability/order/carrier validation;
5. generic JS payload key `document_actions` and carrier extension hooks.

Never expose carrier credentials or full raw API errors in admin messages.

## PEK Schema Recovery

If PEK diagnostics report `PEK location mapping lookup failed`, first verify the physical tables with the active WordPress prefix:

```sql
SHOW TABLES LIKE 'wp_wdc_pek_location_mappings';
SHOW TABLES LIKE 'wp_wdc_pek_terminals';
SHOW COLUMNS FROM wp_wdc_pek_location_mappings LIKE 'mapping_precision';
```

Version 0.132.2 expects the physical mapping precision column to be `mapping_precision`; the domain/API mapping key remains `precision`. Migration `0050` repairs missing PEK foundation tables, and migration `0051` backfills `mapping_precision` from any legacy physical `` `precision` `` column. Failed migrations are not marked applied and `wdc_db_version` is not advanced; after fixing the DB issue, run the normal plugin update/migration lifecycle again. Do not create PEK tables from diagnostics or repository read/write methods.

For PEK destination terminal search failures after a successful location mapping, rerun the explicit admin diagnostic and read the structured report before changing contracts. The report separates `location_resolution` from destination terminal stages, shows stable `error_code`, `failure_stage`, endpoint, HTTP status, query fingerprint, preserved mapping fields, safe response shape for `/branches/nearestdepartments/`, aggregate rejection reason counters, and the redacted `api_error_message` when PEK returns a logical/API error object. When PEK returns field-level validation details under `error.fields`, the report shows them separately as `Ошибки полей ПЭК` with only normalized field names and redacted text messages. The `address` validation failure confirmed in 0.131.10 is fixed by sending a non-empty address on every destination terminal request; coordinate requests now contain both address and coordinates, while address-only requests omit coordinates. The stable `message` remains project-owned; the PEK detail is shown separately as "Ошибка ПЭК". Reports and logs intentionally do not store or display request bodies, headers, raw error objects, rejected/attempted field values, raw terminal rows, API keys, login, tokens, Basic Authorization blobs, or full raw responses. Failed explicit admin diagnostics also emit one project logger event with the same safe context so WooCommerce/debug logs have enough evidence for the next targeted fix.

For PEK calculator issues, use the closed admin quote diagnostic. It reports the stable quote stage, endpoint/status, mode, mapping, safe request flags, insurance value, cargo conversion, light-cargo policy, cost, delivery days, safe service breakdown, safe response shape, and PEK field errors without storing raw request/response, INN/KPP/client card values, credentials, headers, addresses in logs, or stack traces. Calculator root `hasError=true` is reported by the typed quote parser as `pek_quote_root_error`, while non-calculator endpoints retain generic `pek_has_error`. Successful and failed parser-owned reports should show `POST /calculator/calculateprice/` and the HTTP status; for positive product weight below 3000 g the safe request should show `isHP=true`, `sealingPositionsCount=1`, and light-cargo services required. The threshold uses product weight before store packaging, while calculator weight includes packaging. Service text fields must remain strings, and `insuranceTerm` should render as Boolean yes/no, not `1` or an empty string. PEK carrier-provided API messages plus field-error names/messages are redacted with actual login/API key/client card/INN/KPP values before report storage; canonical field paths remain visible, raw/original field names are not stored, and logs intentionally omit API messages and field-message text. Do not add bag/sealing prices manually; returned `costTotal` is authoritative and services are breakdown evidence only. This diagnostic confirms the production `/calculator/calculateprice/` contract only; do not enable checkout PEK rates until the separate checkout runtime stage exists.
