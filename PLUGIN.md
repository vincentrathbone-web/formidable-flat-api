# Formidable Flat API — Reference

**v3.2.0** · Requires WordPress 5.8+, PHP 7.4+, Formidable Forms 5.0+

---

## REST API

### Base URL
```
https://yoursite.com/wp-json/formidable-flat/v1
```

### Authentication

Use the `X-Api-Key` header for all integrations:
```http
X-Api-Key: YOUR_API_KEY
```

Basic Auth (`Authorization: Basic`) is unreliable on HTTPS — WordPress core's Application Passwords feature intercepts it before this plugin runs. `X-Api-Key` is unaffected.

### Endpoints

| Endpoint | Description |
|----------|-------------|
| `GET /form/{form_id}` | All entries from one form |
| `GET /view/{view_id}` | Entries through a Formidable View |
| `GET /merged/{form_ids}/{key_field_ids}` | Multiple forms merged on shared key fields (comma-separated, counts must match) |
| `GET /query/{slug}` | Run a saved query — recommended for all integrations |

Pagination: `?page=1&per_page=1000` (default 1 000, max 100 000).

### Power Query M expression

```m
let
    BaseUrl      = "https://yoursite.com",
    RelativePath = "wp-json/formidable-flat/v1/query/my-query",
    Response = Json.Document(
        Web.Contents(BaseUrl, [
            RelativePath = RelativePath,
            Headers = [#"X-Api-Key" = "YOUR_API_KEY", Accept = "application/json"],
            Timeout = #duration(0, 0, 2, 0)
        ])
    ),
    Result = if Value.Is(Response, type list)
             then if List.IsEmpty(Response) then #table({}, {})
                  else Table.FromRecords(Response)
             else error "Unexpected response."
in Result
```

Select **Anonymous** when Excel prompts for credentials — the key travels in `X-Api-Key`, not the credential dialog. If Connect spins or keeps prompting: **Data → Data Source Settings → clear permissions → reconnect → Anonymous**.

---

## Query Builder

Go to **Formidable → Flat API → New Query**.

1. **Source** — add one or more forms. For a multi-form merge, pick the shared key field on each form.
2. **Fields** — tick what to include. Use **Select all / Deselect all** per group.
3. **Column order** — drag to reorder; set output aliases.
4. **Filters** — AND-logic row filters. Operators: `=` `!=` `>` `>=` `<` `<=` `contains` `not_empty` `is_empty`, plus five date-aware operators — `date is` `date is before` `date is after` `date is on or before` `date is on or after` — that compare by calendar day regardless of any time component or string-format difference between the stored value and the filter value.
5. **Calculated columns** — see below.
6. **Sort** — any output column, natural sort order.

The slug is auto-generated from the name as you type. Duplicate slugs are auto-suffixed on save.

**Query→query joins:** in step 1 you can also pull in another saved query's output columns matched on a shared column, or merge multiple forms directly. Once a table's key field(s) are set, a live sample-value preview (up to 3 real values) and a server-checked "Matches found ✓ / No matches ✗" indicator appear next to its picker — so a key mismatch is visible before you ever click Preview.

Three match modes:

| Mode | Behaviour |
|------|-----------|
| `first` | 1:1 — row count unchanged. |
| `all` | 1:many — one output row per match; row count grows. |
| `nearest_before` | An "as-of" join for data with no shared ID — matches on a key plus finds, among candidates sharing that key, the one whose date is the latest value on or before the base row's own date (e.g. matching a sample to the most recent calibration that precedes it). Optional time-of-day tie-break when more than one candidate shares the winning date, and an optional `max_gap_days` staleness cutoff that flags — never silently drops — a match older than the configured window. |

Unmatched base rows are always kept (LEFT JOIN — a join never deletes rows). Keys match case-insensitively, whitespace collapsed.

---

## Calculated Columns

Server-side formula columns. No `eval()` — ever.

| Construct | Example |
|-----------|---------|
| Field reference | `[Field Name]` |
| Arithmetic | `[Qty] * [Price]`, `([A] + [B]) / [C]` |
| Text concat | `[First] & " " & [Last]` |
| Functions | `ROUND(x[,n])` `ABS` `MIN` `MAX` `SUM` `LEN` `CONCAT` `UPPER` `LOWER` `TRIM` |

Blank cells → `0` for arithmetic, `""` for `&`. Currency strings (`"R 1,234.56"`) → `1234.56`. A calc column may reference any source field, not only selected output columns. Columns evaluate in order — a later column can reference an earlier one.

---

## Shortcodes

### `[formidable_flat_button]`

| Parameter | Default | Description |
|-----------|---------|-------------|
| `query` | — | Saved query slug (required) |
| `action` | `print` | `print`, `csv`, or `xlsx` |
| `type` | `button` | `button` or `icon` |
| `label` | auto | Button label text |

### `[formidable_flat_table]`

| Parameter | Default | Description |
|-----------|---------|-------------|
| `query` | — | Saved query slug (required) |
| `theme` | `light` | `light` or `dark` |
| `edit_page_id` | — | Adds per-row edit link |

Both require a logged-in WordPress session. Theme tokens are CSS variables on `.ffapi-table-container` — override any in custom CSS.

---

## Troubleshooting

**`401 invalid_username` with the correct key** — Switch to `X-Api-Key` header. Basic Auth is intercepted by WordPress core on HTTPS.

**Power Query spins or keeps prompting** — Clear credentials: Data → Data Source Settings → select the base URL → Clear Permissions → reconnect → Anonymous.

**Preview shows headers but no data** — Remove filters one by one to find which is over-restrictive.

**XLSX download fails** — PHP `ZipArchive` extension is required (`php -m | grep zip`).

**Table breaks Elementor layout** — Requires v2.25.6+. Check for a theme or plugin overriding `min-width` on `.ffapi-table-container`.

---

## Changelog

### 3.2.0
- Added: Query Builder redesigned as a Power BI/Power Query-style split view — a resizable top pane for building the query and a dominant bottom pane with a live-updating results grid, defaulting to a 2:1 top/bottom split.
- Added: the preview now runs automatically as you edit the query (debounced ~600ms) instead of requiring a manual "Preview" click, and shows a per-step row-count flow (source → after join → after filters → final output) so a faulty join or filter is visible immediately.
- Added: a "Save Query" button next to "Refresh now" in the preview toolbar, so you can save without scrolling back to the header.
- Changed: live preview now shows the first 100 rows by default (was 10).
- Changed: admin UI typography standardized to two font sizes (13px body/labels, 11px captions/hints) across the Query Builder and shared design tokens.

### 3.1.2
- Fixed: 3.1.1 was built from an outdated base and silently dropped several features already validated in production use — restored: the five `date_*` filter operators, the `nearest_before` "as-of" join match mode (with optional time-of-day tie-break and `max_gap_days` staleness cutoff), the join key-picker's live sample-value preview and "Matches found" indicator, a composite-key `(int)` cast bug fix (was silently zeroing out multi-field merges), and a self-referential parent-form guard.
- Fixed: the join key picker is back on Slim Select. 3.1.1 had reverted it to an earlier `svelte-multiselect` implementation with a known click-race condition (a click could be silently overwritten by a competing reactive update).
- Fixed: the admin page's script-enqueue check matched an exact hardcoded hook string; on some Formidable Forms Core versions WordPress generates a different hook prefix, silently leaving the admin UI unmounted with no error. Now matches by suffix instead, regardless of Formidable's own menu registration.
- Kept from 3.1.1: the six-field system-field-picker pruning and the Select all/Deselect all per-group buttons.

### 3.1.1
- Fixed: selecting only parent-form fields no longer fans out one row per child/repeater entry — the engine now skips repeater expansion when no child-form fields are in the selection.
- Added: "Select all / Deselect all" button per field group in the Query Builder field picker.
- Added: live slug preview shown next to the query name as you type.
- Fixed: saving a new query whose slug collides with an existing one now auto-suffixes (`-2`, `-3`, …) rather than creating a duplicate.
- Removed: six redundant system fields from the field picker — *Created by User ID*, *Modified by User ID*, *Entry Key*, *Entry Name*, *Entry Description*, *Post ID*. Existing saved queries that reference them continue to work.

### 3.0.1
- Fixed: valid `X-Api-Key` was rejected by a private site's global REST gate before this plugin's permission callback ran. A `rest_authentication_errors` filter now passes a verified key through for `/formidable-flat/v1` only.
- Fixed: Credentials tab Power Query snippet referenced an undefined variable. Replaced with a complete, working M expression.
- Added: each saved query's Export menu copies a complete Power Query expression for that query's endpoint.

### 3.0.0
- **Breaking:** all REST endpoints now require the plugin API key. WordPress session and capabilities are no longer accepted as REST auth.
- Added: `class-flat-api-security.php` — export size limits, CSV formula-prefix neutralization, failed-key throttling.
- Removed: Markdown docs from release ZIPs.

### 2.30.x
- Rebuilt admin UI as a Svelte 5 + Vite app (v2.30.0). Per-query Export menu. Field picker grouped by origin form.
- Fixed post-launch regressions: edit slug, Export menu actions, legacy label normalization, composite key restoration, nonce checks on three AJAX endpoints (v2.30.1).
- Source-qualified system metadata — e.g. `Form 22: Created Date` instead of an ambiguous aggregate (v2.30.2). Full parent metadata exposed when a child/repeater form is the primary source (v2.30.3).

### 2.29.0
- Split: DMR Quarterly Report and Canonical Mapping moved to the separate **DMR Reports** plugin. Existing shortcode names and the `/report` REST path are preserved by that plugin.

### 2.28.x
- Added `X-Api-Key` header as the recommended auth method (v2.28.1).
- Fixed: merged queries never populated system columns (`Created_At`, etc.) — always blank (v2.28.2).
- Added friendlier system column aliases: `Created by`, `Modified by`, `Created Date`, `Updated date` (v2.28.3).

### 2.27.x
- Added query-to-query joins (v2.27.0).
- Fixed: joined columns missing from Filter dropdowns (v2.27.1).
- Fixed: calculated columns silently truncated to 3 decimal places (v2.27.4).

### 2.25–2.26
- Text concatenation (`&`) and functions added to calculated columns (v2.25.0).
- Calculated columns can reference any source field (v2.25.1) and be positioned anywhere in the output (v2.25.4).
- Table theming tokenised; `theme="light|dark"` added (v2.25.2).
- Fixed table header colour and Elementor flex layout compatibility (v2.25.5–7).
- Column ordering now applies to the interactive table and its CSV download (v2.25.8).
