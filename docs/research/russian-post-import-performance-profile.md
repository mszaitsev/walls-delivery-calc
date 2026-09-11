# Russian Post pickup import performance profiling

Version: 1.0.9
Schema version: 1.0.0

## Purpose and scope

Release 1.0.8 is a production diagnostic build for intermittent 12–25 second Russian Post pickup batches. It adds observability only. It does not change SQL, indexes, batch size (500), the 18-second/15-unit worker slice, normalization, matching priority, staging writes, locks, scheduling, retries, swap, or database schema.

## Current pipeline

`RussianPostPickupImporter::run_import_batch()` owns the worker slice. Each atomic unit calls `process_one_batch()`, which reads the next payload objects, decodes each JSON object, normalizes it through `RussianPostPassportPointNormalizer`, resolves its canonical location through `RussianPostPickupLocationResolver`, prepares and inserts rows through `RussianPostPickupPointRepository`, and checkpoints `RussianPostPickupImportStateService`. The worker then rechecks state ownership, renews the structured import lock, and either runs another atomic unit or schedules one continuation/finalize action.

## Instrumentation points

`RussianPostImportBatchProfiler` is request-local and scoped to one atomic batch. It uses `hrtime(true)` where available (with a monotonic-compatible fallback) and accumulates:

- `payload_read_ms`, `parse_ms`, `normalize_ms`, `location_match_ms`;
- `staging_prepare_ms`, `staging_write_ms`, `checkpoint_ms`, `lock_renew_ms`;
- optional strategy lookup timings: `fias_match_ms`, `postal_match_ms`, `region_city_match_ms`;
- `total_batch_ms`, object count, offsets, timestamp, worker-slice start;
- memory before/after and process peak.

The last completed profile is stored in the import state. The state also retains at most ten profiles whose total duration meets the existing 10,000 ms slow-batch threshold, plus aggregate phase/query totals and at most 200 integer batch durations. This bounded data is sufficient for exact p50/p95 calculation for the current 74-batch production-sized import without storing every row.

## Query counter design

No `SAVEQUERIES` switch or SQL capture is used. Counters are incremented at existing call boundaries:

- location SELECT attempts and FIAS/postcode/region-city breakdown are counted in the resolver on cache misses;
- total lookup attempts and unique-key cardinalities are counted separately, with request-local SHA-1 keys used only for set membership;
- staging write attempts are counted immediately before the existing per-row `wpdb::insert()`;
- staging SELECT remains zero for the current insert-only path;
- state option writes count the durable checkpoint and the owner-scoped profiler CAS write;
- other identifiable WDC queries use the `wpdb::$num_queries` delta around lock renewal when available.

Only counters are persisted. SQL text, query arguments, raw addresses, FIAS identifiers, postcodes, payload content, credentials, tokens, headers, and cookies are not stored.

## Local fixture results

Deterministic fixtures cover 500 FIAS matches, 500 postcode matches with 37 unique keys, 500 region/city matches with 75 unique keys, and a mixed batch with matches, ambiguous results, no matches, and skips. They verify non-negative phase timings, query/match/unique-key counters, bounded histories, aggregate consistency, and absence of raw lookup values.

The existing importer fixture confirms that three input rows still produce the same three staging rows and three FIAS matches while the profile reports three staging writes. The worker-slice fixture still processes 1,500 objects in three atomic batches and schedules exactly one continuation.

## Profiling overhead

The local synthetic bookkeeping micro-check (100 repetitions of 500 JSON decode/normalization pairs) measured approximately 0.8 ms additional profiler work per 500-object batch on the development host. The uninstrumented synthetic body is deliberately tiny, so its percentage is not representative. Relative to the observed 364 ms fast production batch the measured absolute delta is about 0.2%, and relative to 12–25 second spikes it is negligible. Production phase totals remain the authority; there are no absolute timing assertions in tests.

## Suspected bottlenecks to validate, not conclusions

The current code can perform one canonical-location query per unique strategy key and one staging insert per accepted point. Resolver caches can collapse repeated keys within the PHP request, but they do not prefetch. This is a structural observation, not proof that either path causes the spikes. No query, cache, batching, or index optimization is included in 1.0.8.

Production evidence is required to compare a slow batch with a normal batch:

- phase totals, especially location matching versus staging writes;
- total and per-strategy lookup queries;
- lookup totals versus unique-key counts;
- state/lock query counts;
- memory before/after/peak;
- input objects, offsets, and the surrounding worker slice.

Only after a complete ALL import should a separate optimization task choose between lookup prefetch/caching, staging-write batching, an index change, or no change.

## Optimization 1.0.9

The complete 1.0.8 production profile processed 36,714 objects in 74 batches and wrote 36,700 staging rows. Of 233,942 ms of profiled batch work, location matching accounted for 137,821 ms and staging writes for 81,398 ms. The import issued 27,358 logical FIAS lookup queries and 36,700 staging write queries. These two paths therefore accounted for 93.7% of measured batch time and are the only paths changed in 1.0.9.

### Exact FIAS prefetch

The former resolver performed `LocationRepository::find_by_fias_id()` once per request-unique FIAS key. Version 1.0.9 collects FIAS values from the current 500-object atomic batch and loads exact `fias_id` matches in chunks of 200 through prepared `IN` lists. Its in-memory cache belongs to the resolver instance and can be reused by later atomic batches in the same worker slice; it is never persisted or shared between imports. Exact raw values are loaded first. Only raw misses are checked against the existing compact normalized value, and unresolved exceptional values retain the old per-key normalized-expression fallback. The matching priority and returned `Location` objects are unchanged.

The deterministic 500-key fixture changes the structural query model from approximately 500 exact SELECTs to at most three exact SELECTs. Repeated keys are served by the same request-local cache.

### Bounded staging INSERT

The former repository called `$wpdb->insert()` for every accepted row. Version 1.0.9 prepares the identical normalized columns and writes chunks of 100 rows with placeholders, reducing a normal 500-row batch from 500 statements to five. `ON DUPLICATE KEY` performs a no-op on the unique `point_code`, preserving the former behavior in which the first staged row remains and a later duplicate is counted as skipped. A failed bulk statement falls back to the former per-row path, retaining its exceptional failure accounting.

No transaction spans a worker slice or import. Each multi-row statement is the write boundary; the table DDL continues to inherit the WordPress database default engine. Production acceptance must confirm that the staging table uses InnoDB before relying on statement rollback during the exceptional per-row fallback. No schema or index changed.

### Region/city query investigation

The unchanged fallback calls `LocationRepository::search_by_tokens([$region, $city], 20, true, '', 'RU')`, producing this query shape:

```sql
SELECT l.*, r.region_name AS joined_region_name, r.region_type AS joined_region_type
FROM wp_wdc_locations l
LEFT JOIN wp_wdc_regions r ON r.region_code = l.region_code
WHERE l.active = 1
  AND l.country_code = 'RU'
  AND l.searchable_text LIKE '%<normalized-region>%'
  AND l.searchable_text LIKE '%<normalized-city>%'
ORDER BY l.display_name ASC
LIMIT 20;
```

The leading-wildcard predicates are non-sargable against the current indexes; the existing `(active, country_code)` key can narrow rows, while the text predicates and display-name ordering still require residual filtering and may require sorting. The development host has no project MySQL/MariaDB dataset, so no fabricated `EXPLAIN` is recorded here. A production `EXPLAIN` remains required before proposing an index or changing this parity-sensitive fallback. Version 1.0.9 deliberately leaves the query and its ambiguity semantics unchanged.

### Local structural and parity results

The optimization smoke covers unique and repeated FIAS keys, unique/ambiguous postcode fallback, ambiguous region/city results, missing FIAS fallback, and an inactive exact-FIAS fixture. Reference per-row resolution and prefetched resolution return identical status, strategy, and location ID. A 500-row staging fixture persists every supplied column identically in five writes; a duplicate keeps the first row and increments `skipped`. Profiler meanings and fields are unchanged and now report the reduced physical query counts.

Production validation still needs the same 1.0.8 profile export: output totals, phase totals, query totals, duration samples, slow-batch count, maximum batch duration, total import wall time, and peak memory. In particular, production data must show whether the exact-FIAS and staging reductions remove most slow batches while the unchanged region/city fallback remains the dominant residual spike.

## Extraction

The admin Russian Post import page exposes a collapsed **Профилирование batch** section. From WP-CLI, export the complete bounded state without revealing credentials:

```bash
wp option get wdc_russian_post_pickup_import_state --format=json > /tmp/wdc-rp-profile.json
wp eval '$s=get_option("wdc_russian_post_pickup_import_state",array()); echo wp_json_encode(array("last_batch_profile"=>$s["last_batch_profile"]??array(),"batch_profile_aggregate"=>$s["batch_profile_aggregate"]??array(),"slow_batch_profiles"=>$s["slow_batch_profiles"]??array()), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);'
```

The second command is preferred when sharing diagnostics because it selects only profiling fields.
