# Russian Post pickup import performance profiling

Version: 1.0.8  
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

## Extraction

The admin Russian Post import page exposes a collapsed **Профилирование batch** section. From WP-CLI, export the complete bounded state without revealing credentials:

```bash
wp option get wdc_russian_post_pickup_import_state --format=json > /tmp/wdc-rp-profile.json
wp eval '$s=get_option("wdc_russian_post_pickup_import_state",array()); echo wp_json_encode(array("last_batch_profile"=>$s["last_batch_profile"]??array(),"batch_profile_aggregate"=>$s["batch_profile_aggregate"]??array(),"slow_batch_profiles"=>$s["slow_batch_profiles"]??array()), JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);'
```

The second command is preferred when sharing diagnostics because it selects only profiling fields.
