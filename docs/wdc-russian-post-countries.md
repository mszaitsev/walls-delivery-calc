# WDC Russian Post Countries

Version: 0.19.3.

## Persistent Mapping Table

Russian Post international delivery now uses a persistent country mapping table:

`{$wpdb->prefix}wdc_russian_post_country_mappings`

The table is created by `database/migrations/0016_create_russian_post_country_mappings.php`.

Each row stores one WooCommerce country and its Russian Post dictionary match:

- WooCommerce code/name
- Russian Post country id/name/ISO2
- parcel availability and block flags
- API availability, match status, and `match_source`
- manual mode and manual comment
- calculated `effective_enabled`
- last check timestamp
- raw Russian Post country JSON

The mapping survives checkout requests and is refreshed only when an admin runs the refresh action, or when lazy refresh is explicitly enabled in settings and the table is empty.

## Refresh Flow

The admin action calls `RussianPostCountryMappingService::refresh_from_api()`:

1. Fetch Russian Post country dictionary through `RussianPostApiClient::fetch_countries()`.
2. Read WooCommerce countries from `WC()->countries->get_countries()`.
3. Build an index by normalized Russian Post country name.
4. Match WooCommerce countries by normalized country name, then by configured aliases for known naming differences.
5. Exclude `RU`.
6. Upsert every non-RU WooCommerce country into the mapping table.
7. Preserve existing `manual_mode` and `manual_comment`.
8. Store raw JSON, `match_source`, and `last_checked_at`.

The Russian Post dictionary may return rows like `{id, name, parcel}` without ISO2. In that case `rp_country_id` is filled from `id`, `rp_country_name` from `name`, and `rp_iso2` remains empty.

Refresh stats include raw API count, name index count, sample API keys, WooCommerce count, matched count, enabled count, skipped/unmatched count, manual enabled/disabled counts, and errors. If the API has rows but the name index is empty, refresh reports `country_name_index_empty`.

`match_source` values:

- `name`: direct normalized name match
- `alias`: match through the alias map
- `none`: no match
- `iso2`: reserved for a future API response that includes ISO2 again

## Effective Enabled

`effective_enabled` is calculated as:

- `manual_mode=enabled`: enabled
- `manual_mode=disabled`: disabled
- `manual_mode=auto`: `matched && api_available && has_parcel && !parcel_block`

This makes the default behavior API-driven while still allowing explicit manual overrides.

## Admin Page

The admin page is:

`Калькулятор доставок -> Почта России: страны`

Slug:

`wdc-russian-post-countries`

The page includes:

- refresh button
- mapping statistics
- filters for effective enabled/disabled, matched/unmatched, manual enabled/disabled, and auto
- search by WooCommerce name, Russian Post name, country code, or Russian Post ISO2
- pagination with 20/50/100 per page
- match source column
- per-row actions: auto, enable manually, disable manually

Manual row changes set the comment to:

`изменено вручную DD.MM.YYYY`

## Bulk Lists

The page has two textarea inputs:

- `Страны, куда доставка есть`
- `Страны, куда доставки нет`

Format is one country per line. Matching is case-insensitive, normalizes `ё/е`, removes punctuation, and checks WooCommerce country name, Russian Post country name, WooCommerce country code, and Russian Post ISO2 when present.

The first submit builds a preview:

- rows that will be changed
- rows already in the desired state
- unrecognized rows
- duplicate country conflicts across both lists

If the same resolved country appears in both lists, the preview returns an error and nothing is applied.

When confirmed:

- “есть доставка” rows get `manual_mode=enabled`
- “нет доставки” rows get `manual_mode=disabled`
- `manual_comment` is set to `изменено вручную DD.MM.YYYY`

Countries that are still in `auto` and already have the desired effective state are left unchanged.

## Runtime

`RussianPostCountryDirectory` now reads the persistent mapping table first. `get_country($countryCode)` returns a mapping only when:

- country is not `RU`
- row exists
- `effective_enabled=1`

If the row is missing or disabled, Russian Post quote flow returns the configured zero-cost fallback when fallback is enabled, with `fallback_reason=unsupported_country_{code}`.

The setting `auto_refresh_countries_if_empty` controls lazy refresh when the mapping table is empty. Its default is `false`.
