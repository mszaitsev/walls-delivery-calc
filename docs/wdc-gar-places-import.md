# WDC GAR Places Import

Version: 0.15.7.

## Source CSV

`gar_places.csv` is a prepared UTF-8 CSV without BOM. It uses `;` as delimiter and double quotes around values. Empty values are normalized to empty strings/`NULL` depending on the target column.

The importer is header-based: the first row must contain column names. Header names are trimmed, UTF-8 BOM is removed, and names are lowercased before matching. Column order is not important. Unknown columns are ignored.

Expected columns:

`region_code`, `region_name`, `region_type`, `region_fias_id`, `region_kladr_id`, `district_name`, `district_type`, `district_fias_id`, `district_kladr_id`, `district_gar_object_id`, `district_level`, `city_name`, `city_type`, `city_fias_id`, `city_kladr_id`, `place_name`, `place_type`, `place_level`, `display_name`, `fias_id`, `gar_object_id`, `kladr_id`, `okato`, `oktmo`, `postal_code`.

Required columns are `region_code`, `region_name`, `place_name`, `fias_id`, and `gar_object_id`. If any required column is absent from the header, import returns a clear error. Rows with an empty required value are skipped.

The current hierarchy is `region_* -> district_* -> city_* -> place_*`. `district_*` and `city_*` can be empty.

## Import Flow

The admin page `Калькулятор доставок -> Населенные пункты` contains the `Импорт GAR/ФИАС CSV` section. The CSV can be uploaded through the form or read from a server path such as `/path/to/gar_places.csv`.

The importer:

1. Clears `wdc_gar_places_stage`.
2. Streams CSV rows with `SplFileObject` and `fgetcsv` semantics.
3. Loads valid rows into `wdc_gar_places_stage` with multi-row batch inserts.
4. Deduplicates regions by `region_code` into `wdc_regions`.
5. Imports places into `wdc_locations` with bulk `INSERT ... ON DUPLICATE KEY UPDATE` by `gar_object_id`/`fias_id`.
6. Replaces GAR aliases in batches in `wdc_location_aliases`.
7. Clears staging after a successful import.

The admin importer runs as a chunked AJAX job with progress instead of one long POST. The progress panel shows phase, rows read, stage rows, processed rows, imported locations, aliases, skipped rows, and errors. The real file is expected to be about 64 MB and about 160000 rows.

The follow-up migration `0010_add_gar_district_columns.php` covers installations where `0008` had already run before `district_*` columns were introduced. The importer also checks staging schema before loading CSV rows and reports SQL failures from bulk inserts instead of advancing progress counters after a failed query.

## Admin Search

The admin locations search is paginated. It defaults to 20 rows per page and supports 10, 20, 50, and 100 rows per page. The page shows total matches, the current visible range, first/previous/next/last links, and compact page numbers with ellipses for long result sets.

Search results are ranked so direct settlement matches are shown before parent-field matches:

1. exact `place_name`
2. prefix `place_name`
3. partial `place_name`
4. exact `city_name`
5. prefix `city_name`
6. partial `city_name`
7. `district_name`
8. `region_name`
9. `display_name` / `searchable_text`

The result details panel returns GAR/location fields including `display_name`, type fields, `searchable_text`, and `postal_code`. It does not expose legacy `postcode`.

Search result group headers use `region_name` plus the mapped visual `region_type` after the name. The group sort key remains plain `region_name`, so visual type labels do not change ordering. If the region type rule is hidden, the suffix is omitted.

Before import, the local base is replaced: locations, aliases, regions, and carrier mappings are cleared. Carrier mappings are included because a full location reload can orphan old mappings.

## Tables

`wdc_regions` stores normalized region data keyed by `region_code`. Keeping regions separate avoids duplicating region names and FIAS/KLADR IDs on every location row, and gives future integrations a stable region reference.

`wdc_gar_places_stage` mirrors the CSV one-to-one. It is disposable and is cleared before import and after success.

`wdc_locations` keeps the internal `id` for compatibility, but the canonical external key is `gar_object_id`. It also stores `fias_id`, `kladr_id`, district fields, city fields, place fields, OKATO/OKTMO, and `postal_code`.

`region_type` is stored in `wdc_locations` as well as `wdc_regions`. Migration `0011_add_location_region_type.php` backfills the column for installations that had already applied an older `0008`.

`display_name` is imported and stored as-is when present in the CSV. If an imported row has an empty `display_name`, the importer builds a fallback from region, district, city, and place fields.

`display_name` can be rebuilt later from admin type display rules. Rules are stored in `wdc_location_type_display_rules` and can control how `region_type`, `city_type`, and `place_type` are displayed:

- `before`
- `after`
- `hidden`

`district_type` is not configurable yet and is always displayed as `district_name + " " + district_type`.

The formatter builds:

```text
region_part, district_part, city_part, place_part
```

Example:

```text
Новосибирская обл, Новосибирский р-н, село Гусиный Брод
```

The admin action `Пересобрать display_name` runs as a chunked AJAX job. Each batch recalculates `display_name`, refreshes `searchable_text`, regenerates GAR aliases, and updates a progress bar plus a JSON status block for diagnostics.

The type-rule editor is split into collapsible Region, City, and Place sections. Each summary shows how many source types are currently available in that section.

`postal_code` is imported but not used in delivery calculations yet. It may be empty in the current prepared CSV. `postal_code` is the only location postal-index field used by the new domain/runtime; legacy `postcode` is not exported in snapshots and should not be used for GAR locations.

`wdc_location_carrier_codes` is a foundation table for future carrier mappings. This branch does not populate CDEK/DPD codes. Future examples:

```text
carrier_key=cdek external_code=344
carrier_key=dpd external_code=196006461
```

DaData enrichment by `kladr_id` for postal codes and carrier mappings is planned separately.

## Search

`searchable_text` is normalized with lower case, `ё -> е`, and collapsed spaces. It includes `display_name`, `place_name`, `place_type`, `city_name`, `city_type`, `region_name`, `region_code`, `fias_id`, `gar_object_id`, and `kladr_id`.

After a real import, searching `Новос` should find `Новосибирск`.

## Snapshot Export/Import

The admin section `Экспорт / импорт подготовленной базы` exports and imports JSON Lines snapshots for:

- `wdc_regions`
- `wdc_locations`
- `wdc_location_aliases`
- `wdc_location_carrier_codes`

The first JSONL row is metadata:

```json
{"type":"meta","version":"0.15.7","tables":["wdc_regions","wdc_locations","wdc_location_aliases","wdc_location_carrier_codes"],"created_at":"2026-05-23 12:00:00"}
```

Following rows use:

```json
{"type":"row","table":"wdc_locations","data":{"gar_object_id":1001}}
```

Export reads tables page by page. Import replaces the four tables, ignores snapshot columns absent from the current schema, and lets missing current columns use DB defaults or `NULL`. Admin snapshot export/import also runs as chunked AJAX jobs with progress.

Snapshot import softly accepts old `postcode` values as `postal_code` fallback before rows reach the location domain model, but snapshot export does not emit `postcode`. `Location::from_array()` itself only reads `postal_code`.

## Server Requirements

The production CSV is about 46 MB and 160716 rows. The importer is streaming, but a web request can still hit PHP limits. For production-size imports, increase `max_execution_time`, `upload_max_filesize`, and `post_max_size`, or import on a test/staging environment and transfer a JSONL snapshot to the live site.

The admin search result rows include a `Детали` button. It opens an inline panel loaded through `wdc_location_details` and shows the full `wdc_locations` row for inspection and DaData/carrier-mapping diagnostics. Details values are rendered with DOM nodes and `textContent`; database values from GAR/CSV are not injected through `innerHTML`.

## Future Work

Future branches can add incremental GAR updates, DaData enrichment for postal codes and KLADR-based matching, and real CDEK/DPD carrier code population.
