# WDC DaData Suggestions 0.14.9

DaData is used only for visual address suggestions in checkout. Post-factum DaData address normalization has been removed from the runtime normalization pipeline.

The remaining checkout chain is:

`local city context -> FIAS placeholder -> manual fallback`

## Credentials

There is one DaData credential in settings:

`API-ключ DaData для подсказок адреса`

It is stored encrypted on the server under `dadata_api_token_encrypted` and is used only by:

- `AddressSuggestionSettings`
- `DaDataSuggestionClient`
- `AddressSuggestionAjax`
- `AddressSuggestionService`

The API key is never localized to frontend config.

## Local City Picker

The local city picker remains enabled and is still responsible for city selection, local city context, and initial postcode fill. DaData address suggestions do not replace city selection.

If DaData suggestions are disabled, the local city picker continues to work as before.

## Address Picker UX

The buyer focuses or clicks the active WooCommerce `address_1` field. WDC opens its own address picker modal. Search happens only inside the modal search input.

No inline autocomplete is attached directly to the WooCommerce field.

When the modal opens, the search input is seeded from checkout context:

`region, city, address_1`

Examples:

- `Новосибирская область, Новосибирск, Некрасова`
- `Новосибирская область, Новосибирск, `

The region/city sources are, in order:

1. selected local location or DaData hidden fields
2. WooCommerce region/city fields
3. empty value

If the seeded query has enough characters, the address search starts immediately.

## DaData Request

Server-side requests use DaData Suggest API:

`https://suggestions.dadata.ru/suggestions/api/4_1/rs/suggest/address`

`stage=address` sends the full query string and uses only RU country scope:

- `query`
- `count`
- `locations: [{ country_iso_code: "RU" }]`
- `from_bound: street`
- `to_bound: house`

`locations_boost` is not sent. City priority is intentionally not used because the full query already includes region and city.

`stage=house_after_street` may restrict by selected `street_fias_id`, because the buyer explicitly selected that street.

`stage=resolve` resolves the final selected unrestricted address with `count: 1`.

## Street To House

When a street is selected:

- `address_1` becomes `street_with_type + " "`
- status becomes `street_selected`
- street FIAS/KLADR hidden fields are saved
- the modal stays open
- the next search uses `stage=house_after_street`

If the buyer clicks `Изменить улицу`, or clears the modal search input, selected street state is reset and the next search returns to `stage=address`.

## Applying Final Address

Final address levels are house, flat, and FIAS levels `8`, `9`, or `75`.

After selection, WDC calls `resolve` and applies the resolved item.

Case A: same selected region/city by FIAS id.

- city and region fields are not changed
- `address_1` gets street/house only, for example `ул Некрасова, д 10`
- DaData `postal_code` overwrites checkout postcode when present

Case B: different region/city that can be matched locally.

- city and region can be updated to the selected address
- `address_1` still gets street/house only
- DaData `postal_code` overwrites checkout postcode when present

Case C: selected region/city cannot be matched to the local city context.

- city and region are updated from DaData values
- `address_1` gets the full address without country, for example `Новосибирская обл, г Новосибирск, ул Некрасова, д 10`
- DaData `postal_code` overwrites checkout postcode when present

The frontend helper `localLocationMatchesDadata()` compares FIAS ids from the current local selection with the resolved DaData item.

## Hidden Fields And Order Meta

Resolved address selection fills `{billing|shipping}_dadata_*` hidden fields:

- status
- unrestricted value
- region/city/settlement/street/house/flat data
- FIAS/KLADR ids
- FIAS level

WDC-compatible location hidden fields are updated when present:

- `wdc_platform_location_fias_id`
- `wdc_platform_location_gar_id`
- `wdc_platform_location_display_name`
- `wdc_platform_location_region_name`
- `wdc_platform_location_postcode`

Order persistence stores the DaData hidden fields and compatible WDC meta.

## Manual Fallback

If DaData returns no result, the buyer can use `Использовать введенный адрес`.

Manual fallback:

- writes the modal search value to `address_1`
- sets status to `manual`
- does not change selected city or region
- clears address-specific DaData ids
- does not block checkout
