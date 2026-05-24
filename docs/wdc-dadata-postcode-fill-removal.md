# WDC DaData Postcode Fill Removal

This is a one-time admin mechanism for filling `wdc_locations.postal_code` through DaData by `fias_id`.

When postcode enrichment is finished and verified, remove the temporary tool while keeping the filled `postal_code` data in `wdc_locations`.

Files and code to remove:

- `src/Locations/Postcodes/DaDataPostcodeClient.php`.
- DaData postcode DI registration and import in `src/Core/Plugin.php`.
- The extra `DaDataPostcodeClient` constructor argument in `src/Locations/Admin/LocationsAdminPage.php`.
- The admin UI block titled `Заполнение почтовых индексов через DaData`.
- The frontend handlers for `wdc-dadata-postcode-fill-start` and `wdc-dadata-postcode-clear-markers`.
- AJAX actions:
  - `wdc_dadata_postcode_fill_start`
  - `wdc_dadata_postcode_fill_step`
  - `wdc_dadata_postcode_fill_status`
  - `wdc_dadata_postcode_fill_cancel`
  - `wdc_dadata_postcode_clear_markers`
- Job option handling for `wdc_dadata_postcode_fill_job`.
- Repository methods that were added only for this tool:
  - `count_with_postal_code()`
  - `count_without_postal_code()`
  - `count_technical_no_index_marker()`
  - `next_postcode_batch()`
  - `random_postcode_batch_for_non_cities()`
  - `update_postal_code()`
  - `clear_postal_code_marker()`
- Smoke test `tests/locations/run-dadata-postcode-fill-smoke.php`.

Do not remove:

- Existing `postal_code` values in `wdc_locations`.
- `DaDataTokenPool`, because it is still used by checkout address suggestions.
- Existing visual address suggestion settings and docs.
