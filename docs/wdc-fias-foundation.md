# WDC FIAS/GAR Locations Foundation

## Locations Storage Architecture

The locations foundation stores settlement-level delivery destinations in `wdc_locations`.
The table is intentionally independent from removed legacy shipping code. As of `0.20.0`, the plugin is `src/` only and fresh-install oriented. Production data is populated from prepared GAR/ФИАС CSV or snapshots; demo JSON is test fixture data only.

Key fields:

- `fias_id` and `gar_id` keep external address registry identifiers.
- `country_code`, `region_name`, `region_code`, `city_name`, `settlement_name`, and `settlement_type` describe the destination.
- `display_name` is the user-facing label, for example `Бердск — Новосибирская область`.
- `searchable_text` is a lowercased combined search string used by the repository fallback search.
- `active` allows keeping historical rows without showing them in lookup results.

`LocationRepository` is registered in the core container and provides save, bulk insert, lookup, search, grouped search, and count operations.

`LocationCountryIndexService` maintains the persistent local-country index in the `wdc_location_country_codes` option. The value contains normalized ISO-2 country codes present in active `wdc_locations` rows. The service exposes `rebuild()`, `countries()`, `has_country()`, and `mark_stale()`; missing, empty, or stale options are lazily rebuilt once from `SELECT DISTINCT country_code` instead of being recalculated on every checkout request.

Repository writes that can change the country set mark the index stale: adding locations, bulk upserts/imports, deleting/clearing locations, and changing an existing row's `country_code`. Postal-code updates, display-name rebuilds, type display rule changes, alias regeneration, DaData postcode enrichment, and other normalization tasks do not mark the country index stale because they cannot add or remove represented countries.

## FIAS/GAR Abstraction

This stage does not download FIAS archives, call GAR APIs, or run a full sync pipeline. The foundation only introduces stable storage and service boundaries.

`GarChangesService` is a placeholder abstraction for future GAR change detection. It tracks whether changes are pending, when the last check happened, and can mark the state as checked without making network calls.

## Aliases

`wdc_location_aliases` stores alternate names for a location:

- `location_id` links the alias to a canonical location.
- `alias` keeps the original alias.
- `alias_normalized` stores a normalized lookup key.
- `source` can later identify whether an alias came from admin data, GAR, FIAS, carrier mapping, or another source.

Alias lookup is not wired into runtime checkout yet.

## Search Ranking

`LocationSearchService` normalizes queries by replacing `ё` with `е`, lowercasing, trimming, and collapsing multiple spaces.

Ranking rules are deliberately simple:

- exact settlement or city match ranks highest;
- exact region match is boosted;
- prefix matches outrank partial matches;
- broad `searchable_text` matches are included as a low-weight fallback.

No Elasticsearch, Meilisearch, external API, REST endpoint, AJAX endpoint, or frontend autocomplete is introduced in this stage.

## Normalization Abstraction

`AddressNormalizerInterface` defines a future normalizer contract returning the existing domain `AddressNormalizationResult`.

`FallbackAddressNormalizer` does not call external services. It returns:

- `success=false`;
- `source=fallback`;
- an `Address` with the raw address preserved;
- optional context values copied into the existing domain `Address`.

The existing domain `Address` model is reused and not duplicated.

## Future Checkout Integration

Checkout can later depend on `LocationSearchService` and `AddressNormalizerInterface`, but this stage intentionally avoids WooCommerce field overrides, REST API, AJAX autocomplete, and runtime shipping orchestration.

## Future Carrier City Mapping

Carrier integrations can later map carrier city identifiers or aliases to `wdc_locations` through `wdc_location_aliases` or a dedicated carrier mapping table. The canonical location record should remain the shared destination identity.

## Future GAR Changes Sync

A later GAR sync can build on `GarChangesService` by adding a real adapter that checks GAR change feeds, records pending changes, and triggers a controlled import/update process.

## Country-aware Checkout Lookup

As of 0.21.15, checkout passes the selected WooCommerce country into local city search and resolve. The local picker is active only when `LocationCountryIndexService::has_country($countryCode)` is true; otherwise WooCommerce city/state inputs stay manual and no local warning is rendered. Supported-country searches are filtered by `country_code`, so local rows from one country cannot appear while another country is selected. For `RU`, `BY`, and `KZ`, latin query text is treated as transliteration or wrong keyboard layout input and is normalized before database lookup; raw latin lookup for those countries is intentionally skipped.

# GAR CSV Import Note

As of `0.15.10`, the local places foundation is populated from prepared `gar_places.csv` through `wdc_gar_places_stage`, `wdc_regions`, and the expanded `wdc_locations` schema. The CSV importer maps fields by header, supports optional `district_*` and `city_*` levels, imports `region_type` into locations, imports `display_name` as-is, ignores unknown columns, uses bulk inserts/upserts, exposes chunked admin progress, checks staging schema before loading rows, reports SQL bulk failures in job errors, and uses `postal_code` instead of legacy `postcode`. Snapshot export/import transfers the four location tables plus the `wdc_location_type_display_rules` option. See [wdc-gar-places-import.md](wdc-gar-places-import.md) for the current import and snapshot workflow.

The admin locations page includes paginated search with ranked ordering and compact page numbers. As of `0.17.5`, admin search uses the same hierarchy-aware exact/prefix matcher as checkout instead of broad `searchable_text` contains matching. It also checks exact `fias_id`, `gar_id`/`gar_object_id`, `kladr_id`, and `postal_code` before hierarchy search; exact identifier matches return only those rows. Search group headers use `region_name` plus mapped `region_type` after the name and are sorted by `region_name`.

`LocationDisplayNameFormatter` owns the admin-side display formula:

```text
region_part, district_part, city_part, place_part
```

Type display rules are stored in `wdc_location_type_display_rules`. They apply to `region_type`, `city_type`, and `place_type` with `before`, `after`, or `hidden` positions. `district_type` remains fixed as `district_name + " " + district_type`. The admin settings are grouped into collapsible Region, City, and Place sections with per-section type counts.

The admin `Пересобрать display_name` action runs as a chunked AJAX job with a progress bar and JSON status block. It updates `display_name`, refreshes `searchable_text`, and regenerates GAR aliases for each processed batch.

## Checkout City Picker V2

As of `0.17.5`, checkout lookup uses the local GAR/FIAS locations table for a hierarchy-aware city picker.

`CheckoutLocationSearchParser` normalizes mixed queries such as `Алтайский край, Курьинский р-н, село Ивановка`: punctuation is treated as separators, `ё` is normalized to `е`, and type words are removed from the real search token set. Type words from raw GAR types and admin display rules, for example `область`, `обл.`, `район`, `р-н`, `город`, `г.`, `село`, and `деревня`, are level markers only. The special alias `МО` expands to the region-level token `московская`, so it searches as `Московская область` regardless of region type display rules.

Checkout search is not a full-text search over `searchable_text`. It checks `region_name`, `district_name`, `city_name`, and the resolved lowest-level place name separately, using only exact and prefix matches. It does not match inside a word: `брод` can match `Брод`, `Бродки`, and `Бродовка`, but not `Верхобродово`.

Ranking promotes candidates that match more hierarchy levels, then exact/prefix city/place, district, and region matches. Strong place matches filter away weak unrelated candidates, but region-name-only matches remain visible at lower priority. Region groups use rank buckets: exact city/place, prefix city/place, district+place, region-only, then other. Inside exact/prefix buckets, hierarchy seniority is applied first, so city-level matches rank above place-level matches; groups with the same seniority sort alphabetically by region name. If a query matches an upper level, for example `Домодедово` as `city_name`, checkout can show the city itself plus nested places inside that city.

The checkout picker loading state uses the text `Идёт поиск, подождите несколько секунд` with a lightweight CSS spinner. Permanent modal actions let the customer use the current input as a manual city value or clear the input/results without waiting for an empty-result fallback button.

`LocationDisplayNameFormatter` now also formats checkout region headers, option labels, state field values, and city field values. Region headers always use `region_name + mapped region_type` unless the type is hidden. Option rows use the settlement label plus useful parent hierarchy, for example `с. Гусиный Брод - Новосибирский р-н, Новосибирская обл.`.
