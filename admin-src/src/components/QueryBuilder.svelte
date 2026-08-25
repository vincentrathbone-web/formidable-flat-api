<script>
  import { getFormFields, previewQuery, loadQuery, submitForm } from '../lib/api.js';
  import { evaluate } from '../lib/formula.js';
  import { showToast } from '../lib/toast.svelte.js';
  import KeyFieldPicker from './KeyFieldPicker.svelte';

  let { boot, slug, onSaved } = $props();

  // These unqualified columns are retained by the engine for existing saved
  // queries only. New metadata selections arrive with their source form's field
  // list and use stable keys such as "Form 22: Created Date".
  const LEGACY_SYSTEM_FIELDS = [
    'Created by', 'Modified by', 'Created Date', 'Updated date', 'Timestamp',
    'Entry ID', 'Draft Status', 'Common Key', 'Parent_ID', 'Child_ID',
    'Created_At', 'Last Modified By',
  ];

  let label = $state('');
  let querySlug = $state('');
  let oldSlug = $state('');
  let tables = $state([]); // [{form_id, key_field_id: number[]}]
  let fieldsByForm = $state({}); // formId -> [{id,name,form_name,from_parent}]
  let selected = $state(new Set());
  let columnOrder = $state([]); // [{label, alias}]
  let filters = $state([]); // [{field, operator, value}]
  let calcCols = $state([]); // [{name, formula}]
  let sortField = $state('');
  let sortDir = $state('ASC');
  let previewRows = $state([]);
  let previewSample = $state(null);
  let previewing = $state(false);
  let saving = $state(false);
  let loaded = $state(!slug);

  // Every field known to the query, for insert-field pickers and
  // the formula tester's "known fields" universe.
  function allAvailableLabels() {
    const out = new Set(selected);
    for (const list of Object.values(fieldsByForm)) {
      for (const f of list) out.add(f.name);
    }
    return out;
  }

  function selectedLegacySystemFields() {
    return LEGACY_SYSTEM_FIELDS.filter((name) => selected.has(name));
  }

  // Fields, grouped by the form each field actually BELONGS to (its own `form_name`),
  // not by which source table the user added — a repeater/child table's fields come
  // back from ajax_get_form_fields already tagged with the parent form's fields mixed
  // in (from_parent: 1), so grouping by origin form is what surfaces the parent's
  // fields as their own visible section instead of burying them, unlabeled, inside the
  // child table's group. Deduplicates by label across tables, same as the pre-Svelte
  // admin UI did. Groups are keyed by form ID so identical form names remain
  // distinguishable and source-qualified frm_items metadata stays with its form.
  function groupedFields() {
    const seen = new Set();
    const groups = new Map();
    for (const t of tables) {
      for (const f of fieldsByForm[t.form_id] || []) {
        if (seen.has(f.name)) continue;
        seen.add(f.name);
        const gId = Number(f.form_id || t.form_id);
        const gName = f.form_name || formName(t.form_id);
        if (!groups.has(gId)) groups.set(gId, { id: gId, name: gName, fields: [] });
        groups.get(gId).fields.push(f);
      }
    }
    return groups;
  }

  async function ensureFieldsLoaded(formId) {
    if (fieldsByForm[formId]) return;
    try {
      const fields = await getFormFields(formId);
      fieldsByForm = { ...fieldsByForm, [formId]: fields };
    } catch (e) {
      showToast('Could not load fields for that form');
    }
  }

  async function addTable(formId) {
    if (!formId) return;
    if (tables.some((t) => t.form_id === Number(formId))) return;
    tables = [...tables, { form_id: Number(formId), key_field_id: [] }];
    await ensureFieldsLoaded(Number(formId));
  }

  function removeTable(formId) {
    tables = tables.filter((t) => t.form_id !== formId);
  }

  function formName(formId) {
    const f = (boot.forms || []).find((x) => x.id === formId);
    return f ? f.name : `Form ${formId}`;
  }

  function toggleField(name, checked) {
    const next = new Set(selected);
    if (checked) {
      next.add(name);
      if (!columnOrder.some((c) => c.label === name)) columnOrder = [...columnOrder, { label: name, alias: '' }];
    } else {
      next.delete(name);
      columnOrder = columnOrder.filter((c) => c.label !== name);
    }
    selected = next;
  }

  let collapsedGroups = $state(new Set());
  function toggleGroup(key) {
    const next = new Set(collapsedGroups);
    if (next.has(key)) next.delete(key); else next.add(key);
    collapsedGroups = next;
  }

  function selectAllInGroup(groupFields, selectAll) {
    const next = new Set(selected);
    let order = [...columnOrder];
    for (const f of groupFields) {
      if (selectAll) {
        next.add(f.name);
        if (!order.some((c) => c.label === f.name)) order = [...order, { label: f.name, alias: '' }];
      } else {
        next.delete(f.name);
        order = order.filter((c) => c.label !== f.name);
      }
    }
    selected = next;
    columnOrder = order;
  }

  // Drag-to-reorder column list
  let dragIndex = $state(null);
  function onDragStart(i) { dragIndex = i; }
  function onDrop(i) {
    if (dragIndex === null || dragIndex === i) return;
    const list = [...columnOrder];
    const [moved] = list.splice(dragIndex, 1);
    list.splice(i, 0, moved);
    columnOrder = list;
    dragIndex = null;
  }

  // Keyboard-accessible alternative to drag-and-drop reordering.
  function moveColumn(i, dir) {
    const j = i + dir;
    if (j < 0 || j >= columnOrder.length) return;
    const list = [...columnOrder];
    [list[i], list[j]] = [list[j], list[i]];
    columnOrder = list;
  }

  function addFilter() {
    filters = [...filters, { field: '', operator: '=', value: '' }];
  }
  function removeFilter(i) {
    filters = filters.filter((_, idx) => idx !== i);
  }

  function addCalc() {
    calcCols = [...calcCols, { name: '', formula: '' }];
  }
  function removeCalc(i) {
    calcCols = calcCols.filter((_, idx) => idx !== i);
  }
  function insertFieldToken(i, fieldName, inputEl) {
    if (!fieldName) return;
    const token = `[${fieldName}]`;
    const start = inputEl?.selectionStart ?? calcCols[i].formula.length;
    const end = inputEl?.selectionEnd ?? calcCols[i].formula.length;
    const cur = calcCols[i].formula || '';
    const next = cur.slice(0, start) + token + cur.slice(end);
    calcCols = calcCols.map((c, idx) => (idx === i ? { ...c, formula: next } : c));
    queueMicrotask(() => {
      inputEl?.focus();
      inputEl?.setSelectionRange(start + token.length, start + token.length);
    });
  }

  function calcResult(formula, name) {
    if (!formula) return { text: '—', err: false };
    if (!previewSample) return { text: 'Run Preview to test', err: false };
    const known = allAvailableLabels();
    const r = evaluate(formula, previewSample, known);
    if (r.error) return { text: r.error, err: true };
    return { text: `= ${r.result}`, err: false };
  }

  function buildQueryDef() {
    return {
      tables: tables.map((t) => ({
        form_id: t.form_id,
        key_field_id: t.key_field_id.length > 1 ? t.key_field_id : (t.key_field_id[0] ?? 0),
      })),
      selected_fields: Array.from(selected),
      column_order: columnOrder,
      filters,
      calculated_columns: calcCols.filter((c) => c.name && c.formula),
      sort_field: sortField,
      sort_dir: sortDir,
      joins: [],
    };
  }

  async function runPreview() {
    previewing = true;
    try {
      const def = buildQueryDef();
      const { rows, sample } = await previewQuery(def, 10);
      previewRows = rows || [];
      previewSample = sample || null;
    } catch (e) {
      showToast('Preview failed: ' + e.message);
    } finally {
      previewing = false;
    }
  }

  function slugify(s) {
    return (s || '').toLowerCase().trim().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '');
  }

  function save() {
    if (!label.trim()) { showToast('Give the query a name first'); return; }
    if (tables.length === 0) { showToast('Add at least one source table'); return; }
    saving = true;
    submitForm('formidable_flat_save_query', {
      query_label: label,
      query_slug: querySlug || slugify(label),
      old_slug: oldSlug,
      _wpnonce: boot.nonces.saveQuery,
      ffapi_payload: JSON.stringify(buildQueryDef()),
      ffapi_form_end: '1',
    });
  }

  $effect(() => {
    if (slug && !loaded) {
      (async () => {
        try {
          const q = await loadQuery(slug);
          label = q.label || '';
          querySlug = q.slug || '';
          oldSlug = q.slug || '';
          tables = (q.tables || []).map((t) => ({
            form_id: Number(t.form_id),
            key_field_id: Array.isArray(t.key_field_id) ? t.key_field_id.map(Number) : (t.key_field_id ? [Number(t.key_field_id)] : []),
          }));
          selected = new Set(q.selected_fields || []);
          columnOrder = q.column_order && q.column_order.length
            ? q.column_order
            : (q.selected_fields || []).map((f) => ({ label: f, alias: '' }));
          filters = q.filters || [];
          calcCols = q.calculated_columns || [];
          sortField = q.sort_field || '';
          sortDir = q.sort_dir || 'ASC';
          for (const t of tables) await ensureFieldsLoaded(t.form_id);
        } catch (e) {
          showToast('Could not load query "' + slug + '"');
        } finally {
          loaded = true;
        }
      })();
    }
  });
</script>

<section>
  <div class="ffapi-card">
    <div class="ffapi-card-head">
      <div>
        <input
          bind:value={label}
          placeholder="Query name"
          style="font-size:14.5px; font-weight:600; border:none; padding:2px 0; width:auto; min-width:220px;"
        />
        <div class="ffapi-slug-row">
          <span class="ffapi-muted" style="font-size:11.5px;">slug:</span>
          <code class="ffapi-slug-display ffapi-mono">{querySlug || slugify(label) || '—'}</code>
          {#if !slug}<span class="ffapi-muted" style="font-size:10.5px;">(auto-generated from name)</span>{/if}
        </div>
        <p>{tables.length} source table{tables.length === 1 ? '' : 's'} · {selected.size} fields selected</p>
      </div>
    </div>

    <div class="ffapi-builder-section">
      <p class="ffapi-section-title"><span class="ffapi-step-num">1</span> Source tables</p>
      <div class="ffapi-table-chips">
        {#each tables as t}
          <span class="ffapi-chip">
            {formName(t.form_id)} (Form {t.form_id})
            <button type="button" class="ffapi-chip-x" onclick={() => removeTable(t.form_id)} title="Remove">×</button>
          </span>
        {/each}
        <select onchange={(e) => { addTable(e.target.value); e.target.value = ''; }} style="width:auto; min-width:180px;">
          <option value="">+ Add table…</option>
          {#each (boot.forms || []).filter((f) => !tables.some((t) => t.form_id === f.id)) as f}
            <option value={f.id}>{f.name}</option>
          {/each}
        </select>
      </div>
      {#if tables.length > 1}
        <p class="ffapi-hint">Pick the join key field(s) per table below (tick more than one for a composite key).</p>
        {#each tables as t, ti}
          <div class="ffapi-key-picker">
            <span class="ffapi-mono ffapi-key-picker-label">{formName(t.form_id)} key:</span>
            <KeyFieldPicker
              fields={fieldsByForm[t.form_id] || []}
              keyFieldIds={t.key_field_id}
              onChange={(ids) => { tables = tables.map((x, i) => (i === ti ? { ...x, key_field_id: ids } : x)); }}
            />
          </div>
        {/each}
      {/if}
    </div>

    <div class="ffapi-builder-section">
      <p class="ffapi-section-title"><span class="ffapi-step-num">2</span> Fields <span class="ffapi-muted" style="font-weight:400; font-size:12px;">— {selected.size} selected</span></p>
      <div class="ffapi-field-groups">
        {#each [...groupedFields()] as [gId, group]}
          {@const groupKey = 'form-' + gId}
          {@const checkedCount = group.fields.filter((f) => selected.has(f.name)).length}
          <div class="ffapi-field-group" class:collapsed={collapsedGroups.has(groupKey)}>
            <div class="ffapi-field-group-head" role="button" tabindex="0"
              onclick={() => toggleGroup(groupKey)}
              onkeydown={(e) => (e.key === 'Enter' || e.key === ' ') && toggleGroup(groupKey)}>
              <span class="ffapi-fg-name">{group.name} <span class="ffapi-form-id">Form {gId}</span></span>
              <span class="ffapi-fg-meta">
                <button type="button" class="ffapi-select-all-btn"
                  onclick={(e) => { e.stopPropagation(); selectAllInGroup(group.fields, checkedCount < group.fields.length); }}
                  title={checkedCount < group.fields.length ? 'Select all fields in this group' : 'Deselect all fields in this group'}>
                  {checkedCount < group.fields.length ? 'Select all' : 'Deselect all'}
                </button>
                {checkedCount} of {group.fields.length}
                <svg class="ffapi-chev" width="12" height="12" viewBox="0 0 12 12" fill="none"><path d="M3 4.5L6 7.5L9 4.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
              </span>
            </div>
            <div class="ffapi-field-list">
              {#each group.fields as f}
                <label class="ffapi-field-check" class:ffapi-system-field={f.is_system}>
                  <input type="checkbox" checked={selected.has(f.name)} onchange={(e) => toggleField(f.name, e.target.checked)} />
                  <span class="ffapi-field-text">
                    <span>{f.label || f.name}</span>
                    {#if f.is_system}
                      <span class="ffapi-field-source">
                        {f.value_kind === 'direct' ? '' : 'resolved from '}{f.source_table}.{f.source_column}
                      </span>
                    {/if}
                  </span>
                </label>
              {/each}
              {#if group.fields.length === 0}<p class="ffapi-hint" style="padding:6px 8px;">Loading fields…</p>{/if}
            </div>
          </div>
        {/each}
        {#if selectedLegacySystemFields().length}
          <div class="ffapi-field-group ffapi-legacy-group">
            <div class="ffapi-field-group-head">
              <span class="ffapi-fg-name">Legacy aggregate metadata</span>
              <span class="ffapi-fg-meta">Existing query only</span>
            </div>
            <div class="ffapi-field-list">
              <p class="ffapi-legacy-note">These older columns can combine metadata from more than one form. Replace them with the form-qualified fields above.</p>
              {#each selectedLegacySystemFields() as name}
                <label class="ffapi-field-check">
                  <input type="checkbox" checked onchange={(e) => toggleField(name, e.target.checked)} />
                  {name}
                </label>
              {/each}
            </div>
          </div>
        {/if}
      </div>
      <p class="ffapi-hint">Entry metadata is grouped under its source form. Dates, IDs and IP values come directly from that form's <span class="ffapi-kbd">frm_items</span> row; resolved labels identify their source ID column.</p>
    </div>

    <div class="ffapi-builder-section">
      <p class="ffapi-section-title"><span class="ffapi-step-num">3</span> Column order &amp; aliases <span class="ffapi-muted" style="font-weight:400; font-size:12px;">— drag to reorder</span></p>
      <div class="ffapi-order-list" role="list">
        {#each columnOrder as c, i}
          <div class="ffapi-order-chip" role="listitem" draggable="true"
            ondragstart={() => onDragStart(i)}
            ondragover={(e) => e.preventDefault()}
            ondrop={() => onDrop(i)}>
            <span class="ffapi-drag-dots" aria-hidden="true">⠿⠿</span>
            <button type="button" class="ffapi-order-move" onclick={() => moveColumn(i, -1)} disabled={i === 0} title="Move up">▲</button>
            <button type="button" class="ffapi-order-move" onclick={() => moveColumn(i, 1)} disabled={i === columnOrder.length - 1} title="Move down">▼</button>
            <span style="flex:1;">{c.label}</span>
            <input class="ffapi-alias-input" placeholder="alias…" bind:value={c.alias} />
          </div>
        {/each}
        {#if columnOrder.length === 0}<p class="ffapi-hint">Select fields above to order them here.</p>{/if}
      </div>
    </div>

    <div class="ffapi-builder-section">
      <p class="ffapi-section-title"><span class="ffapi-step-num">4</span> Filters</p>
      {#each filters as f, i}
        <div class="ffapi-filter-row">
          <select bind:value={f.field}>
            <option value="">— field —</option>
            {#each columnOrder as c}<option value={c.label}>{c.label}</option>{/each}
          </select>
          <select bind:value={f.operator}>
            <option value="=">equals</option>
            <option value="!=">not equals</option>
            <option value="contains">contains</option>
            <option value=">">&gt;</option>
            <option value=">=">&gt;=</option>
            <option value="<">&lt;</option>
            <option value="<=">&lt;=</option>
            <option value="not_empty">is not empty</option>
            <option value="is_empty">is empty</option>
          </select>
          <input type="text" bind:value={f.value} disabled={f.operator === 'not_empty' || f.operator === 'is_empty'} placeholder="value" />
          <button class="ffapi-row-remove" onclick={() => removeFilter(i)} title="Remove filter">×</button>
        </div>
      {/each}
      <button class="ffapi-btn ffapi-btn-sm ffapi-btn-ghost" onclick={addFilter}>+ Add filter</button>
    </div>

    <div class="ffapi-builder-section">
      <p class="ffapi-section-title"><span class="ffapi-step-num">5</span> Calculated columns</p>
      {#each calcCols as c, i}
        {@const res = calcResult(c.formula, c.name)}
        <div class="ffapi-calc-item">
          <div class="ffapi-calc-item-top">
            <input type="text" class="ffapi-mono" placeholder="Column name" bind:value={c.name} />
            <input type="text" class="ffapi-mono" placeholder="[Field] + [Other Field]" bind:value={c.formula}
              id={'calc-formula-' + i} />
            <button class="ffapi-row-remove" onclick={() => removeCalc(i)} title="Remove column">×</button>
          </div>
          <div class="ffapi-calc-insert-row">
            <select onchange={(e) => { insertFieldToken(i, e.target.value, document.getElementById('calc-formula-' + i)); e.target.value = ''; }}>
              <option value="">+ Insert field…</option>
              {#each Array.from(allAvailableLabels()) as fname}<option value={fname}>{fname}</option>{/each}
            </select>
            <p class="ffapi-hint">Pick a field to insert it at the cursor — no need to type <span class="ffapi-kbd">[Field Name]</span> by hand.</p>
          </div>
          <div class="ffapi-formula-tester">
            <span>Live test {previewSample ? 'against the first preview row' : '(run Preview first)'}:</span>
            <span class="ffapi-result" class:err={res.err}>{res.text}</span>
          </div>
        </div>
      {/each}
      <button class="ffapi-btn ffapi-btn-sm ffapi-btn-ghost" onclick={addCalc}>+ Add calculated column</button>
    </div>

    <div class="ffapi-builder-section">
      <p class="ffapi-section-title"><span class="ffapi-step-num">6</span> Live preview <span class="ffapi-muted" style="font-weight:400; font-size:12px;">— {previewRows.length} rows shown</span></p>
      {#if previewRows.length === 0}
        <p class="ffapi-hint">Click "Preview" above to run this query against live data.</p>
      {:else}
        <div style="overflow-x:auto;">
          <table class="ffapi-data-table ffapi-mono" style="min-width:640px;">
            <thead><tr>{#each Object.keys(previewRows[0]) as k}<th>{k}</th>{/each}</tr></thead>
            <tbody>
              {#each previewRows as row}
                <tr>{#each Object.keys(previewRows[0]) as k}<td>{row[k]}</td>{/each}</tr>
              {/each}
            </tbody>
          </table>
        </div>
      {/if}
    </div>

    <div class="ffapi-builder-section ffapi-builder-actions">
      <button class="ffapi-btn ffapi-btn-sm" onclick={runPreview} disabled={previewing}>{previewing ? 'Loading…' : 'Preview'}</button>
      <button class="ffapi-btn ffapi-btn-sm ffapi-btn-primary" onclick={save} disabled={saving}>Save Query</button>
    </div>
  </div>
</section>

<style>
  .ffapi-builder-section { padding: 18px; border-bottom: 1px solid var(--ffapi-border); }
  .ffapi-builder-section:last-child { border-bottom: none; }
  .ffapi-section-title { font-size: 13px; font-weight: 700; margin: 0 0 10px; display: flex; align-items: center; gap: 8px; }
  .ffapi-step-num {
    width: 20px; height: 20px; border-radius: 50%; background: var(--ffapi-brand); color: #fff;
    font-size: 11px; display: inline-flex; align-items: center; justify-content: center; flex: none;
  }
  .ffapi-table-chips { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  .ffapi-chip {
    display: inline-flex; align-items: center; gap: 8px; background: var(--ffapi-surface-sunken);
    border: 1px solid var(--ffapi-border); border-radius: var(--ffapi-radius-base); padding: 6px 10px; font-size: 12.5px;
  }
  .ffapi-chip-x {
    cursor: pointer; color: var(--ffapi-text-muted); font-size: 14px; line-height: 1;
    background: none; border: none; padding: 0; font-family: inherit;
  }
  .ffapi-chip-x:hover { color: var(--ffapi-danger); }

  .ffapi-key-picker { display: flex; align-items: center; gap: 10px; margin-top: 10px; }
  .ffapi-key-picker-label { font-size: 12px; width: 160px; flex: none; }

  .ffapi-builder-actions { display: flex; justify-content: flex-end; gap: 8px; }

  .ffapi-field-groups { display: flex; flex-direction: column; gap: 8px; }
  .ffapi-field-group { border: 1px solid var(--ffapi-border); border-radius: var(--ffapi-radius-base); overflow: hidden; }
  .ffapi-field-group-head {
    display: flex; align-items: center; justify-content: space-between; width: 100%; text-align: left;
    background: var(--ffapi-surface-sunken); border: none; padding: 10px 12px; cursor: pointer; font-size: 13px;
    user-select: none;
  }
  .ffapi-fg-name { font-weight: 600; }
  .ffapi-form-id {
    margin-left: 6px; color: var(--ffapi-text-muted); font-size: 10.5px; font-weight: 500;
  }
  .ffapi-fg-meta { display: flex; align-items: center; gap: 10px; color: var(--ffapi-text-muted); font-size: 11.5px; }
  .ffapi-chev { transition: transform 0.14s ease; color: var(--ffapi-text-muted); }
  .ffapi-field-group.collapsed .ffapi-chev { transform: rotate(-90deg); }
  .ffapi-field-group.collapsed .ffapi-field-list { display: none; }
  .ffapi-field-list { display: flex; flex-direction: column; padding: 4px 6px; }
  .ffapi-field-check { display: flex; align-items: center; gap: 9px; padding: 6px 8px; font-size: 13px; cursor: pointer; border-radius: var(--ffapi-radius-sm); }
  .ffapi-field-check:hover { background: var(--ffapi-surface-sunken); }
  .ffapi-field-check input { width: auto; accent-color: var(--ffapi-brand); }
  .ffapi-field-text { display: flex; align-items: baseline; justify-content: space-between; gap: 12px; width: 100%; }
  .ffapi-field-source { color: var(--ffapi-text-muted); font-family: var(--ffapi-font-mono); font-size: 10.5px; }
  .ffapi-system-field { border-left: 2px solid color-mix(in srgb, var(--ffapi-brand) 45%, transparent); }
  .ffapi-legacy-group { border-color: color-mix(in srgb, var(--ffapi-danger) 35%, var(--ffapi-border)); }
  .ffapi-legacy-note {
    margin: 4px 8px 6px; color: var(--ffapi-text-muted); font-size: 11.5px; line-height: 1.4;
  }

  .ffapi-order-list { display: flex; flex-direction: column; gap: 6px; }
  .ffapi-order-chip {
    display: flex; align-items: center; gap: 8px; background: var(--ffapi-surface); border: 1px solid var(--ffapi-border);
    border-radius: var(--ffapi-radius-base); padding: 7px 10px; cursor: grab; font-size: 12.5px;
  }
  .ffapi-drag-dots { color: var(--ffapi-text-muted); letter-spacing: 1px; font-size: 12px; }
  .ffapi-order-move {
    background: none; border: none; color: var(--ffapi-text-muted); cursor: pointer; font-size: 9px;
    padding: 2px 3px; line-height: 1; font-family: inherit;
  }
  .ffapi-order-move:hover:not(:disabled) { color: var(--ffapi-brand); }
  .ffapi-order-move:disabled { opacity: 0.3; cursor: default; }
  .ffapi-alias-input { width: 130px; font-size: 12px; padding: 3px 6px; }

  .ffapi-filter-row { display: grid; grid-template-columns: 1fr 130px 1fr auto; gap: 8px; align-items: center; margin-bottom: 8px; }
  .ffapi-row-remove { background: none; border: none; color: var(--ffapi-text-muted); cursor: pointer; font-size: 16px; padding: 4px; }
  .ffapi-row-remove:hover { color: var(--ffapi-danger); }

  .ffapi-calc-item { border: 1px solid var(--ffapi-border); border-radius: var(--ffapi-radius-base); padding: 12px; margin-bottom: 10px; }
  .ffapi-calc-item-top { display: grid; grid-template-columns: 180px 1fr auto; gap: 8px; align-items: center; }
  .ffapi-calc-insert-row { display: flex; gap: 8px; margin-top: 8px; align-items: center; }
  .ffapi-calc-insert-row select { width: auto; min-width: 220px; font-size: 12.5px; padding: 5px 8px; }
  .ffapi-calc-insert-row .ffapi-hint { margin: 0; }
  .ffapi-slug-row { display: flex; align-items: center; gap: 6px; margin: 2px 0 4px; }
  .ffapi-slug-display { font-size: 12px; color: var(--ffapi-text-muted); }
  .ffapi-select-all-btn {
    background: none; border: 1px solid var(--ffapi-border); border-radius: var(--ffapi-radius-sm);
    color: var(--ffapi-brand); font-size: 10.5px; padding: 1px 6px; cursor: pointer; font-family: inherit;
    line-height: 1.5;
  }
  .ffapi-select-all-btn:hover { background: color-mix(in srgb, var(--ffapi-brand) 8%, transparent); }
  .ffapi-formula-tester {
    margin-top: 10px; padding: 10px 12px; border-radius: var(--ffapi-radius-base); background: var(--ffapi-surface-sunken);
    border: 1px solid var(--ffapi-border); font-size: 12.5px; display: flex; align-items: center; justify-content: space-between; gap: 10px;
  }
  .ffapi-result { font-family: var(--ffapi-font-mono); font-weight: 700; color: var(--ffapi-success); }
  .ffapi-result.err { color: var(--ffapi-danger); }
</style>
