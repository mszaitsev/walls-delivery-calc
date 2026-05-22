# WDC GAR Places Import

Version: 0.15.0.

## Source CSV

`gar_places.csv` is a prepared UTF-8 CSV without BOM. It uses `;` as delimiter and double quotes around values. Empty values are normalized to empty strings/`NULL` depending on the target column.

Expected columns:

`region_code`, `region_name`, `region_type`, `region_fias_id`, `region_kladr_id`, `city_name`, `city_type`, `city_fias_id`, `city_kladr_id`, `place_name`, `place_type`, `place_level`, `display_name`, `fias_id`, `gar_object_id`, `kladr_id`, `okato`, `oktmo`, `postal_code`.

Rows without `gar_object_id`, `fias_id`, `place_name`, or `region_code` are skipped.

## Import Flow

The admin page `Калькулятор доставок -> Населенные пункты` contains the `Импорт GAR/ФИАС CSV` section. The CSV can be uploaded through the form or read from a server path such as `/path/to/gar_places.csv`.

The importer:

1. Clears `wdc_gar_places_stage`.
2. Streams CSV rows with `SplFileObject` and `fgetcsv` semantics.
3. Loads valid rows into `wdc_gar_places_stage`.
4. Deduplicates regions by `region_code` into `wdc_regions`.
5. Imports places into `wdc_locations`.
6. Generates aliases into `wdc_location_aliases`.
7. Clears staging after a successful import.

Before import, the local base is replaced: locations, aliases, regions, and carrier mappings are cleared. Carrier mappings are included because a full location reload can orphan old mappings.

## Tables

`wdc_regions` stores normalized region data keyed by `region_code`. Keeping regions separate avoids duplicating region names and FIAS/KLADR IDs on every location row, and gives future integrations a stable region reference.

`wdc_gar_places_stage` mirrors the CSV one-to-one. It is disposable and is cleared before import and after success.

`wdc_locations` keeps the internal `id` for compatibility, but the canonical external key is `gar_object_id`. It also stores `fias_id`, `kladr_id`, city fields, place fields, OKATO/OKTMO, and `postal_code`.

`display_name` is stored in the database, not rebuilt on every frontend request. If an imported row has an empty `display_name`, the importer builds a fallback from place and region fields.

`postal_code` is imported but not used in delivery calculations yet. It may be empty in the current prepared CSV.

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
{"type":"meta","version":"0.15.0","tables":["wdc_regions","wdc_locations","wdc_location_aliases","wdc_location_carrier_codes"],"created_at":"2026-05-23 12:00:00"}
```

Following rows use:

```json
{"type":"row","table":"wdc_locations","data":{"gar_object_id":1001}}
```

Export reads tables page by page. Import replaces the four tables, ignores snapshot columns absent from the current schema, and lets missing current columns use DB defaults or `NULL`.

## Server Requirements

The production CSV is about 46 MB and 160716 rows. The importer is streaming, but a web request can still hit PHP limits. For production-size imports, increase `max_execution_time`, `upload_max_filesize`, and `post_max_size`, or import on a test/staging environment and transfer a JSONL snapshot to the live site.

## Future Work

Future branches can add incremental GAR updates, DaData enrichment for postal codes and KLADR-based matching, and real CDEK/DPD carrier code population.
