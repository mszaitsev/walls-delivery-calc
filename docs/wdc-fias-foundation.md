# WDC FIAS/GAR Locations Foundation

## Locations Storage Architecture

The locations foundation stores settlement-level delivery destinations in `wdc_locations`.
The table is intentionally independent from checkout and legacy shipping code. As of `0.15.8`, production data is populated from a prepared GAR/ФИАС CSV instead of the old demo admin import.

Key fields:

- `fias_id` and `gar_id` keep external address registry identifiers.
- `country_code`, `region_name`, `region_code`, `city_name`, `settlement_name`, and `settlement_type` describe the destination.
- `display_name` is the user-facing label, for example `Бердск — Новосибирская область`.
- `searchable_text` is a lowercased combined search string used by the repository fallback search.
- `active` allows keeping historical rows without showing them in lookup results.

`LocationRepository` is registered in the core container and provides save, bulk insert, lookup, search, grouped search, and count operations.

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

# GAR CSV Import Note

As of `0.15.8`, the local places foundation is populated from prepared `gar_places.csv` through `wdc_gar_places_stage`, `wdc_regions`, and the expanded `wdc_locations` schema. The CSV importer maps fields by header, supports optional `district_*` and `city_*` levels, imports `region_type` into locations, imports `display_name` as-is, ignores unknown columns, uses bulk inserts/upserts, exposes chunked admin progress, checks staging schema before loading rows, reports SQL bulk failures in job errors, and uses `postal_code` instead of legacy `postcode`. See [wdc-gar-places-import.md](wdc-gar-places-import.md) for the current import and snapshot workflow.

The admin locations page includes paginated search with ranked ordering and compact page numbers. Direct `place_name` matches are preferred over city, district, region, and broad `searchable_text` matches, so a settlement named by the query appears above rows where the same text only occurs in a parent field. Search group headers use `region_name` plus mapped `region_type` after the name and are sorted by `region_name`.

`LocationDisplayNameFormatter` owns the admin-side display formula:

```text
region_part, district_part, city_part, place_part
```

Type display rules are stored in `wdc_location_type_display_rules`. They apply to `region_type`, `city_type`, and `place_type` with `before`, `after`, or `hidden` positions. `district_type` remains fixed as `district_name + " " + district_type`. The admin settings are grouped into collapsible Region, City, and Place sections with per-section type counts.

The admin `Пересобрать display_name` action runs as a chunked AJAX job with a progress bar and JSON status block. It updates `display_name`, refreshes `searchable_text`, and regenerates GAR aliases for each processed batch.
