# HANDOVER — Formidable Flat API

**v3.1.2** · Last updated 2026-08-26

---

## Architecture

```
formidable-flat-api.php         Bootstrap, constants, frontend shortcodes + AJAX
class-flat-api-engine.php       Core data layer — fetch, merge, join, filter, sort, calc
class-flat-api-rest.php         REST route registration
class-flat-api-admin.php        wp-admin page, AJAX handlers, bootstrap payload
class-flat-api-security.php     Export limits, CSV sanitization, key throttling
class-formula-builder.php       No-eval formula tokenizer → shunting-yard → RPN
class-xlsx-writer.php           Dependency-free XLSX (raw OOXML via ZipArchive)
admin-src/                      Svelte 5 + Vite source — excluded from release ZIPs
dist/                           Built admin.js + admin.css — committed, no server build
assets/                         Frontend button/table CSS + JS
```

**Data flow:** REST / shortcode / admin export → `run_saved_query()` → fetch rows (direct `$wpdb`, no Formidable PHP API) → `apply_query_joins()` → filter → sort → calc columns → prune/alias → limit → JSON / CSV / XLSX / print / Tabulator table.

**Saved queries** are stored in the WP option `formidable_flat_saved_queries`. `run_saved_query()` is the single execution path for all output formats — a change there affects everything.

**Query→query joins** are LEFT JOINs applied before filter/sort/calc, capped at depth 3, with cycle detection. Three match modes: `first` (1:1), `all` (1:many), `nearest_before` (as-of join on a key + latest-date-on-or-before match — see `apply_nearest_before_join()`).

**Filters** support 9 generic operators (`= != > >= < <= contains not_empty is_empty`) plus 5 date-aware ones (`date_equals date_before date_after date_on_or_before date_on_or_after`) that compare by calendar day via `date_op()`.

**Formula evaluator** — PHP is authoritative; `admin-src/src/lib/formula.js` mirrors it for the browser preview only. Never introduce `eval()` or `create_function()`.

---

## WordPress options

| Constant | Option key |
|----------|-----------|
| `FRM_FLAT_OPTION_KEY` | `formidable_flat_api_key` |
| `FRM_FLAT_QUERIES_KEY` | `formidable_flat_saved_queries` |
| `FRM_FLAT_FONT_SIZE_KEY` | `formidable_flat_print_font_size` |
| `FRM_FLAT_THEME_KEY` | `formidable_flat_table_theme` |

**DMR Reports** depends on this plugin via `GET /formidable-flat/v1/query/{slug}`. Do not reintroduce DMR/canonical/sample-pipeline logic here.

---

## Security invariants

- Admin AJAX/POST: nonce + `manage_options` on every handler.
- Frontend AJAX: `ffapi_frontend` nonce + `is_user_logged_in()`. No `nopriv` registrations.
- REST: API key required; session/capabilities are not a bypass. Constant-time key comparison.
- Formula evaluator: **no `eval()` or `create_function()` — ever.**
- CSV: formula prefixes neutralized; numeric values preserved.
- Legacy saved-query label normalization (`"Form Name: Field Name"` → bare label) must be preserved.

---

## Admin UI build

```powershell
cd admin-src
npm install       # first time only
npm run build     # run after any admin source change and before packaging
```

Built output goes to `dist/` (committed). `admin-src/` is excluded from release ZIPs. The PHP/Svelte contract is `window.ffapiAdmin`, assembled in `enqueue_assets()` — check every consuming component when changing it.

`enqueue_assets()`'s `$hook` check is a **suffix** match (`_page_formidable-flat-api`), not an exact string — different Formidable Forms Core versions have produced different hook prefixes (`formidable_page_...` vs `formidable-1_page_...`). Breaking this silently empties the admin mount point with no PHP error.

`KeyFieldPicker.svelte` runs on **Slim Select**, not `svelte-multiselect` — the latter has a click/prop-update race that was already hit and fixed once. Don't reintroduce it.

---

## Testing checklist

**Minimum before any commit:**
1. `php -l` on every changed PHP file.
2. `npm run build` if `admin-src/` changed.

**Before a release, verify end-to-end:**
- Single-form query · View query · merged query · composite key
- Query-to-query join (`first`, `all`, and `nearest_before` with a real date-anchored match)
- Filters (including the `date_*` operators) · natural sort · aliases · column ordering
- Chained calculated columns
- All output formats: REST JSON, CSV, XLSX, print, frontend table
- Admin: Edit existing query, Preview, Save, Export menu, nonce-failure behaviour

---

## Release workflow

**Before bumping a version number, diff the working tree against the last known-good
release** (git tag or ZIP), not just against whatever branch happens to be checked out.
v3.1.1 was built from a stale base and silently shipped without the `nearest_before`
join, the `date_*` filter operators, the key-picker's sample-preview/match-check
endpoints, and the Slim Select fix above — all real, already-in-production features that
just vanished with no error. A higher version number is not proof of a superset diff.

1. Build `admin-src/` if its source changed.
2. Bump the version in: `package.ps1`, `formidable-flat-api.php` (header + `FRM_FLAT_VERSION`), `PLUGIN.md`.
3. Run `.\package.ps1` — never overwrites an existing version ZIP; uses Python `zipfile` for forward-slash paths (do not use `Compress-Archive`).
4. Confirm the ZIP: one `formidable-flat-api/` root, all runtime PHP + `dist/` present, no `admin-src/` or dev files.
5. Install on a test WordPress copy and verify the changed workflow.
