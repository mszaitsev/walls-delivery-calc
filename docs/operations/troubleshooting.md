# Troubleshooting

Version: 1.0.15

Start with the safe status/diagnostic panel owned by the affected subsystem. Never enable raw payload logging or expose carrier credentials, tokens, cookies, full addresses, phone numbers, email, or payment data to diagnose a failure.

## Installation and migrations

On activation, verify the plugin reports version 1.0.15 and that the unchanged schema baseline `wdc_db_version` remains `1.0.0`. A fresh install runs only `database/migrations/0001_initial_schema.php`. If activation reports a migration failure, inspect the WordPress database error and table privileges; do not edit migration options manually or replay deleted 0.x migration files.

The initial migration is idempotent for an already-correct development database and never drops/truncates business tables. Retired pre-1.0 tables that already exist are inert and may be removed separately only after an operator backup and explicit decision.

## Checkout and pickup

If no WDC rate appears:

1. verify the global WooCommerce runtime setting and Delivery Service/carrier enablement;
2. verify the current destination resolves to supported canonical location data;
3. verify package weight/dimensions and carrier-specific limits;
4. run the carrier admin diagnostic;
5. inspect only the final technical warning/error, not expected empty/no-match results.

If pickup points do not appear, verify that the selected rate has canonical pickup metadata, its destination fingerprint matches the current checkout, the active local catalog exists, and the provider can resolve the selection server-side. Do not accept browser-supplied cargo, location, address, family, or price as authority.

## Background jobs

Check WooCommerce → Status → Scheduled Actions for the documented current hooks. Expected pre-initialization, disabled, lock-busy, and no-work states are silent. Investigate technical warnings for transport/API/parse/contract failures, lost leases, retry exhaustion, database errors, or Action Scheduler unavailability after initialization.

Ozon pickup import uses an active published generation plus a separate building generation. Failed/cancelled builds must not replace the active snapshot. DPD, Russian Post, Yandex, and calendar jobs must remain bounded and non-overlapping.

Russian Post API pickup import requires the PHP Zip extension (`ZipArchive`). The background worker loads the WordPress File API itself; if `ZipArchive` is unavailable, the import must finish with `PHP ZipArchive extension is not available.` and release its lock rather than attempting a shell unzip fallback.

For a Russian Post pickup import, a queued/running state must have the same `import_id` as its unexpired option lease. The lease stays active across init, parse batches, and finalize and is removed only on terminal success/failure/cancellation. Guard diagnostics are retained in the import state when a callback receives the wrong job ID, loses its lease, or cannot access the payload; do not manually invoke importer hooks to recover a job.

For the Yandex full geography pipeline, a running outer state should have exactly one `wdc_yandex_delivery_geo_pipeline_v2_run_step` event whose sole argument is the current `session_id`. A transient no-argument event left by an upgrade is safe: it performs no work and repairs the owner-scoped continuation if needed. The short-lived outer execution lease is released between slices; while a callback is active it must contain the same session plus its private token. Do not delete the lease to accelerate an active worker.

While the Yandex full pipeline is `running` or `paused`, do not drive pickup import, geo builder, region enrichment, region mapping/manual overrides, or location mapping through standalone controls or direct AJAX. The server returns HTTP 409 for these mutations and the browser disables their loops because the outer pipeline is their sole executor. Before this ownership guard, a browser loop could race the background worker, make counters appear to roll backward, and attempt another pickup batch after successful promotion had correctly deleted the downloaded JSON. A not-readable JSON error with a lower offset behind already-published live counts is characteristic of that old race, not evidence that successful cleanup itself should be disabled.

## Shipments

For a failed shipment action, verify adapter/mapper/provider registration, current order/carrier identity, creation-attempt state, capability/nonce checks, and the carrier's safe diagnostic fields. An uncertain create result must remain reconcilable; do not submit again until the carrier cabinet has been checked.

Document downloads require an administrator capability, a valid nonce, a registered provider action, and a server-resolved fixed document path/response. Never construct a filesystem path from an unchecked request value.

## Logs

Follow [production logging policy](logging-policy.md). Expected no-tariff, no-match, precondition, fallback-success, cache-hit, selection, HTTP-success, and cron-no-op events are not incidents. Technical failures should retain only safe IDs, status/error codes, endpoints, stages, and aggregate counters.
