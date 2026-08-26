# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A proprietary WordPress plugin (**Formidable Flat API**, by Controll IT Systems) that flattens nested Formidable Forms repeater data into flat tabular rows and exposes them as JSON (REST), CSV, XLSX, print output, and an interactive frontend table. The primary consumer is Microsoft Power Query / Excel / BI tools, plus end-user export buttons on the site frontend.

There is no test suite. Runtime PHP has no build step (plain PHP loaded directly by
WordPress). The admin UI does have a build step as of v2.30.0 — see "Admin UI build
step" below. "Running" it means installing into a WordPress site with Formidable Forms
5.0+ active.

## Admin UI build step (v2.30.0)

The 5 core admin tabs (Saved Queries, Query Builder, Endpoint Builder, Credentials,
Shortcodes — previously ~1,500 lines of inline HTML/CSS/JS hand-rolled in
`class-flat-api-admin.php`'s `render_page()`) are now a Svelte 5 app, source in
`admin-src/`, built with Vite to `dist/admin.js` + `dist/admin.css`:

```powershell
cd admin-src
npm install   # first time only
npm run build
```

**Run this before every `package.ps1`** — the built `dist/` output is committed to the
repo and shipped in the release ZIP; there is no build-on-server step, same as any WP
plugin shipping pre-built JS. `package.ps1` excludes `admin-src/` (source, `node_modules/`,
`package.json`) from the ZIP but does *not* exclude `dist/`.

Architecture: `admin-src/src/main.js` mounts `App.svelte` into a single
`<div id="ffapi-admin-app">` that `render_page()` now just echoes — all the real page
setup (saved queries, forms list, API key, nonces, calc-function metadata) moved to
`enqueue_assets()`, which calls `wp_localize_script()` to hand it to the app as
`window.ffapiAdmin`. Client-side tab switching uses `history.pushState` on `?tab=`/`&edit=`
so deep links still work; no server round-trip on tab change. `admin-src/src/lib/formula.js`
is a straight port of the old inline formula tokenizer/evaluator (still the client-side
mirror of `class-formula-builder.php`, used only for the live calc-formula tester — never
to compute real report data).

The Svelte app calls `admin-ajax.php`/`admin-post.php` endpoints (`ffapi_get_form_fields`,
`ffapi_preview_query`, `ffapi_load_query`, `ffapi_key_value_samples`,
`ffapi_key_match_check`, and the save/delete/duplicate/regenerate-key/save-font-size/
save-theme admin-post actions), all behind a single shared `ffapi_builder` nonce
(`boot.nonces.builder`), sent as the `nonce` param by `admin-src/src/lib/api.js`'s
`getJson()`/`postJson()` helpers. `ffapi_key_value_samples` (up to 3 real sample values
for a join-key field) and `ffapi_key_match_check` (live "Matches found" indicator,
backed by `Formidable_Flat_API_Engine::distinct_join_keys()`) power the Query Builder's
join key-picker — see "Query→query joins" below.

DMR Reports/Sample Pipeline are unaffected — they're separate plugins with their own
admin pages, not part of this rebuild.

**`enqueue_assets()`'s hook check is suffix-matched, not exact-matched — keep it that
way.** It gates on `substr($hook, -strlen($suffix)) === '_page_formidable-flat-api'`
rather than a hardcoded full hook string. WordPress derives the hook *prefix* from
Formidable's own top-level menu registration, not from the literal `'formidable'` parent
slug this plugin passes to `add_submenu_page()` — different Formidable Forms Core
versions have been observed producing `formidable_page_...` on some installs and
`formidable-1_page_...` on others (a menu-slug collision on Formidable's side). An exact
string match silently breaks the admin UI (mount div stays empty, `window.ffapiAdmin`
never populates, no PHP error at all) on any install where the prefix differs.

## Packaging & release workflow

Distribution ZIPs are built with `package.ps1` (PowerShell + Python for correct forward-slash ZIP paths — `Compress-Archive` corrupts the WP installer with backslashes):

```powershell
./package.ps1
```

The script **aborts if a ZIP for the current version already exists** — it never overwrites prior release ZIPs (they are kept as release history). To cut a release you must bump the version in **all of**:
- `package.ps1` (`$version`)
- `formidable-flat-api.php` (the plugin header `Version:` **and** the `FRM_FLAT_VERSION` constant)
- `PLUGIN.md`

The ZIP excludes dev-only files (`.claude`, `CLAUDE.md`, `package.ps1`, `packaging.md`, `*.zip`, `.git`, etc. — see `$excludeItems`).

## Architecture

Entry point `formidable-flat-api.php` defines constants, `require_once`s the engine/REST/formula/xlsx classes, and instantiates `Formidable_Flat_API`. That class owns all **frontend** shortcodes and AJAX handlers; admin is loaded lazily only when `is_admin()`.

**DMR Report + Canonical Mapping moved to a separate plugin in v2.29.0** — see
"Split: DMR Reports plugin" below. What follows describes the classes still in *this*
plugin.

The PHP classes and their roles:

- **`class-flat-api-engine.php`** — `Formidable_Flat_API_Engine` (static methods). The core data layer. Queries Formidable's DB tables directly (`frm_fields`, `frm_items`, `frm_item_metas`, `frm_forms`) via `$wpdb` — it does **not** use Formidable's PHP API. Three fetch paths: `fetch_form_rows` (single form), `fetch_view_rows` (Formidable View), and `fetch_merged_rows` (join multiple forms on key fields). `run_saved_query` is the orchestrator: it loads raw rows, then applies filtering → natural sort → column pruning/ordering/aliasing → calculated columns → limit. `query_output_columns()` (moved here from the old canonical-mapping class in v2.29.0) derives a saved query's output columns *from its definition*, no DB round-trip — used by both the query builder's join picker and (via a thin delegate) the DMR Reports plugin's canonical mapping UI.
- **`class-flat-api-rest.php`** — `Formidable_Flat_API_REST`. Registers REST routes under `formidable-flat/v1`: `/form/{id}`, `/view/{id}`, `/merged/{form_ids}/{key_field_ids}` (comma-separated, counts must match), `/query/{slug}`. All callbacks are thin wrappers over the engine. (The `/report` route moved to the DMR Reports plugin in v2.29.0 — same namespace/path, registered from `class-dmr-rest.php` there instead.)
- **`class-formula-builder.php`** — `Formidable_Flat_Formula_Builder`. Calculated-columns UI + evaluator. **Security-critical:** formulas are evaluated with a hand-written tokenizer → shunting-yard → RPN evaluator. **Never** introduce `eval()`/`create_function()` here — the no-eval guarantee is what makes saved formulas safe. Supports `[Field Name]` refs, `+ - * /`, unary minus, parens, numeric literals; coerces currency strings like `"R 1,234.56"` to numbers.
- **`class-xlsx-writer.php`** — `Formidable_Flat_XLSX_Writer`. Dependency-free `.xlsx` generation (raw OOXML, no PhpSpreadsheet).
- **`class-flat-api-admin.php`** — `Formidable_Flat_API_Admin`. Owns the wp-admin page under **Formidable → Flat API**: menu registration, the admin AJAX handlers the Svelte UI calls (`ajax_get_form_fields`, `ajax_preview_query`, `ajax_load_query`, `ajax_key_value_samples`, `ajax_key_match_check`), save/delete/duplicate/regenerate-key/font-size/theme admin-post handlers, and `enqueue_assets()` (builds the `window.ffapiAdmin` bootstrap payload and localizes it onto the built admin script). `render_page()` itself is now just the Svelte mount point — see "Admin UI build step" above for where the actual UI lives. (The `dmr`/`canonical` tabs moved to the DMR Reports plugin's own admin page in v2.29.0.)

## Split: DMR Reports plugin (v2.29.0)

The DMR Quarterly Airborne Pollutants Exposure Report and Canonical Mapping features —
formerly `class-flat-api-report.php`/`class-flat-api-canonical.php`
(`Formidable_Flat_API_Report`/`Formidable_Flat_API_Canonical`) — moved into a separate,
dependent plugin: **DMR Reports** (`controll-dmr-reports`,
`Requires Plugins: formidable-flat-api`), renamed to `DMR_Reports_Report`/
`DMR_Reports_Canonical`. They were domain-specific regulatory-report logic for one
client vertical, not generic query-flattening — most Formidable Flat API installs never
used them. See that plugin's own CLAUDE.md for the full architecture (it's substantial —
custom DB tables, snapshot/lock/classification logic, the canonical-mapping model).

**What this means for this plugin:** nothing published breaks — DMR Reports registers
the same shortcode names and the same `/report` REST path, so existing pages and Power
Query connections keep working once DMR Reports is installed alongside this one.
Internally, DMR Reports talks to this plugin only through its public REST API
(`GET /formidable-flat/v1/query/{slug}`, dispatched in-process via `rest_do_request()`),
not by calling `Formidable_Flat_API_Engine` directly — a cleaner plugin boundary than
the in-process PHP calls the old report class made. The one exception is
`query_output_columns()` (see above), which stayed in core because the core query
builder's own join picker depends on it — it isn't DMR-specific logic, it just used to
live on the canonical-mapping class.

**Sample Pipeline follow-up:** `Controll Sample Pipeline` depended on the old
`Formidable_Flat_API_Report`/`_Canonical` classes directly (not through this plugin's
REST API — a different pattern than DMR Reports uses). Its dependency was updated to
`Requires Plugins: controll-dmr-reports` and all 16 call sites renamed to
`DMR_Reports_Report`/`DMR_Reports_Canonical` — see that plugin's own CLAUDE.md/HANDOVER.md.

### The flattening model (read this before touching the engine)

Formidable stores repeater rows as nested arrays of child entry IDs inside the parent's meta. The engine expands **one output row per child entry**, copying parent fields onto each row. Every row carries system columns: `Draft Status`, `Parent_ID`, `Child_ID`, `Created_At`, `Last Modified By`. When a queried form is itself a child/repeater sub-form, `get_parent_form_context` reverse-looks-up the parent form (via `parent_form_id` or by scanning `field_options` for `form_select`) and merges the grandparent's fields in as selectable columns too.

Field references throughout are by **field name (label)**, not field ID. As of v2.10.4 labels are bare field names; older saved queries used `"Form Name: Field Name"` — `run_saved_query` has a back-compat shim (`$strip_prefix`) that strips the `"...: "` prefix from `selected_fields`, `column_order`, `sort_field`, and `filters` so legacy queries keep working. Preserve this when editing.

### Saved queries (the central data structure)

Saved queries live in the WP option `formidable_flat_saved_queries` (constant `FRM_FLAT_QUERIES_KEY`) as an array of associative arrays keyed by `slug`. Shape (see `handle_save_query`):

```php
[ 'slug', 'label', 'tables' => [ ['form_id', 'key_field_id'] ],
  'joins' => [ ['query_slug','left_key','right_key','match',      // v2.27.0+
                'join_type',                                      // v3.3.0 — left|inner|right|full, match:first/all only
                'left_date','right_date','right_time','max_gap_days'] ], // match:nearest_before only
  'selected_fields' => [labels], 'column_order' => [ ['label','alias'] ],
  'filters' => [ ['field','operator','value'] ], 'sort_field', 'sort_dir',
  'calculated_columns' => [ ['name','formula'] ], 'saved_at' ]
```

`operator` is one of `= != > >= < <= contains not_empty is_empty` (generic string/numeric
compare) or `date_equals date_before date_after date_on_or_before date_on_or_after`
(day-granularity comparison via `date_op()` — both sides parsed with `strtotime()` and
truncated to midnight before comparing, so a stored value with a time component or a
different date format still compares correctly by calendar day; either side failing to
parse makes the filter not match rather than falling back to a misleading string compare).
Allowlisted in `class-flat-api-admin.php`'s `sanitize_filter_operator()` — extend that
list, not just the engine's `match()`, when adding a new operator.

`key_field_id` is a scalar for a single key or an array for a composite key (joined with `||` in `fetch_merged_rows`). The same query object flows unchanged through REST, shortcodes, and admin exports — there is one canonical execution path (`run_saved_query`), so a change there affects every output format.

### Query→query joins (`joins`, v2.27.0)

A saved query can pull in **another saved query's output columns**, matched on a shared column. This exists because a client's Formidable forms often can't be changed, so the fields a report needs are spread across several queries — joins are how you gather them into one query that can supply every canonical field (see the canonical layer above).

Applied in `run_saved_query` by `apply_query_joins()` **immediately after the fetch and before filter/sort/prune/calc**, which is what makes joined columns behave exactly like native ones (selectable, filterable, sortable, usable in formulas). The joined side is executed via `run_saved_query()` itself, so what comes back is that query's **output** columns (post-selection, post-alias, post-calc) — i.e. what the builder shows.

Rules — deliberately simple, and mirrored in the builder JS so the field picker shows the real column names the query will produce:
- **`join_type`** (v3.3.0, default `'left'`) controls which unmatched rows survive — `left`/`inner`/`right`/`full`, standard SQL-join semantics. Applies only to `match: 'first'/'all'`; `nearest_before` is always a left/as-of join by design and ignores it. A right-only row (join_type `right`/`full`, a joined-query row with no base match) is built via `merge_joined()` against a *blank template* of the base row's own column names, so its columns — and the label-prefix collision rule below — line up identically with a matched row's; the base's own key field on that row stays blank rather than being backfilled from the joined side (consistent with the collision rule always favoring the base column name over the incoming one).
- `match: 'first'` (1:1, e.g. post-weights per sample) leaves the row count unchanged (for `join_type: left/inner`); `match: 'all'` (1:many, e.g. several pollutant results per sample) emits one row per match.
- `match: 'nearest_before'` — an **"as-of" join**, not an equi-join, for data with no shared ID at all (e.g. a pump calibrated alone with no sample attached yet, issued days later to whatever sample needs it). Matches on `left_key`/`right_key` same as the other modes, but among same-key candidates picks the one whose `right_date` is the **latest value on or before** the base row's own `left_date` (never a later one — calibration always precedes use). Implemented in `apply_nearest_before_join()`, always 1:1 in row count like `match: 'first'`. Every base row gets a `"{label} Match"` column, always present (never blank-and-ambiguous) explaining *why* nothing matched when nothing did — no candidates for that key at all vs. every candidate's date falling after the anchor are different, both real situations worth telling apart. `right_time` (optional) breaks ties when more than one candidate shares the winning date — only reorders same-date candidates, can never turn an otherwise-matching same-day candidate into a non-match. `max_gap_days` (optional, 0/absent = no limit) **flags** (via the Match column) rather than silently uses a best candidate whose date is more than N days before the anchor — a real, rare case where the nearest available calibration may be stale enough to want surfaced for review.
- **Nothing is overwritten**: a joined column whose name collides with an existing one comes in prefixed with the joined query's label (`Post-weights: Sample ID`). Collisions are checked against the base row *and any earlier join*, because the engine merges into the accumulating row.
- Keys are matched **case-insensitively, whitespace-collapsed** (`join_key()`) — the same sample id is rarely typed identically across two forms.
- Guards: a query can't join itself (excluded from the picker), cycles are detected via `$join_stack`, and the chain is depth-capped (`MAX_JOIN_DEPTH = 3`). A bad config is skipped, never fatal.
- `apply_query_joins()`'s optional third by-reference parameter (`$join_stats`, `func_num_args()`-gated same as `run_saved_query()`'s `$stage_counts`) returns per-join `['matched', 'unmatched', 'right_only']` counts for `first`/`all` joins — `run_saved_query()` feeds this straight into `$stage_counts['joins']`, which the builder's flow banner renders live. `nearest_before` isn't included there; its matched/unmatched is still derived downstream from its own `"{label} Match"` column.

**Joins-only queries (no `tables` at all, v3.3.0):** `run_saved_query()` only hard-fails when *both* `tables` and `joins` are empty. When `tables` is empty but `joins` isn't, the **first** join entry supplies the starting rows instead of matching against anything — it's `self::run_saved_query()` on `joins[0]['query_slug']` directly, and its `left_key`/`right_key`/`match`/`join_type` are ignored (the sanitizers in `class-flat-api-admin.php` allow that one entry to have empty `left_key`/`right_key` specifically when it's index 0 and `tables` is empty — everywhere else those two are still required, so don't loosen that check further). Only `array_slice($joins_cfg, 1)` — the *rest* of the joins — are actually passed to `apply_query_joins()`. Mirrored in `QueryBuilder.svelte`: the join row at index 0 renders a plain "start from this saved query" picker instead of match controls whenever `tables.length === 0`, and `allAvailableLabels()` folds in that first query's own output fields (via `joinedQueryFields()`) so a *second* join's "match my" has something to offer — without that, the field picker for the first configurable match is empty by construction, since there's no real table to draw fields from.

### Frontend layer

Two shortcodes, registered in `formidable-flat-api.php`:
- `[formidable_flat_button query="slug" type="button|icon" action="print|csv|xlsx" label="..."]` — single export/print button.
- `[formidable_flat_table query="slug" edit_page_id="..."]` — interactive Tabulator.js table with client-side search, per-row edit links, and export-of-filtered-rows.

Assets in `assets/` (`frontend.css/js`, `table.css/js`) are enqueued **conditionally for logged-in users** — `enqueue_frontend_assets` checks the WordPress session and `has_shortcode` on the current post, then loads only what's present. Tabulator is loaded from unpkg CDN; table theme is chosen by the `formidable_flat_table_theme` option. Frontend AJAX actions are prefixed `ffapi_frontend_*`, are registered only through `wp_ajax_*` (never `wp_ajax_nopriv_*`), and all five callbacks call `authorize_frontend_request()` to verify login plus the shared `ffapi_frontend` nonce before query lookup/execution. The JS global passed via `wp_localize_script` is `ffapiFrontend` (`ajaxUrl`, `nonce`, `fontSize`).

### Constants & options

Defined in `formidable-flat-api.php`: `FRM_FLAT_PATH`, `FRM_FLAT_VERSION`, and the option-key constants — `formidable_flat_api_key` (REST auth key), `formidable_flat_saved_queries`, `formidable_flat_print_font_size` (clamped 8–24), `formidable_flat_table_theme`.

## Conventions

- **Security (v3.0.0+):** frontend shortcode execution requires `is_user_logged_in()` plus the `ffapi_frontend` nonce inside every callback; never add a `wp_ajax_nopriv_ffapi_frontend_*` registration. Admin AJAX/POST handlers require the relevant nonce plus `current_user_can('manage_options')`. REST auth is API-key-only: `X-Api-Key` is preferred, legacy Basic API-key authentication is retained temporarily, and WordPress login/capabilities must never bypass the key. Since v3.0.1, a late `rest_authentication_errors` filter allows a valid `X-Api-Key` through a private site's global REST gate only for `/formidable-flat/v1`; never broaden that namespace check or override the gate for an invalid/missing key. Export and REST resource ceilings, filtered-row validation, CSV formula protection, and failed-key throttling live in `class-flat-api-security.php`.
  - **Why `X-Api-Key` exists (v2.28.1, 2026-07-17):** on any HTTPS site, WordPress core's own Application Passwords feature (default since WP 5.6) intercepts every `Authorization: Basic` header during current-user determination and rejects it as an unknown WP username — *before* `check_permission()` is ever called. This is core behavior, not a config error on any one site, and it breaks Basic Auth for every install of this plugin over HTTPS. Confirmed by direct server-side investigation on a live production site: `wp_is_application_passwords_available()` returns `true` under real request conditions, and Wordfence's own "disable application passwords" hardening option was off, ruling out both a plugin conflict and a site misconfiguration. `X-Api-Key` isn't a header core inspects, so it isn't subject to this collision. Basic Auth is kept only for backwards compatibility with older integrations; new integrations (and PLUGIN.md's docs/examples, and the admin Credentials tab) should always lead with `X-Api-Key`.
- **No external PHP dependencies** — XLSX and formula evaluation are deliberately self-contained. Keep it that way.
- Each class file carries its own `File:` / `Version:` docblock header; these per-file versions drift from the plugin version and are not part of the release bump.
- User-facing reference docs live in `PLUGIN.md` (comprehensive) and `README.md` (changelog-style highlights). Update them when changing user-visible behavior.
- **`KeyFieldPicker.svelte` must stay on Slim Select (`slim-select` npm package), not `svelte-multiselect`.** It was originally built on `svelte-multiselect`'s `bind:selected`, which raced against an `$effect` re-deriving `selected` from props: a click fired `onChange`, the parent wrote back a new `key_field_id`, and the resulting prop update re-ran the effect — sometimes overwriting the in-progress click before it stuck. Rebuilt on Slim Select instead, driven imperatively (own the instance, push data/selection into it, only react to its `afterChange` event) specifically to remove that race. `keepOrder: true` matters too — for a composite key, pick order is meaningful (the engine concatenates each table's key field values in `key_field_id`'s array order), so don't let it default-sort the display.
- **A whole release cycle (v3.1.1) was once built from a stale base and silently shipped without this file's fix, the `nearest_before` join, the `date_*` filter operators, and the key-picker's sample-preview/match-check AJAX endpoints** — all real, already-validated-in-production features that just vanished with no error, discovered only when a user reported "date filtering stopped working." A plugin version number bump is not proof the new version is a superset of the old one — if you're about to treat a higher-numbered branch/tag as authoritative, diff it against the last known-good state first rather than assuming.
