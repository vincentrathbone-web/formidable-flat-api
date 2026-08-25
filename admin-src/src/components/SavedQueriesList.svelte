<script>
  import ExportMenu from './ExportMenu.svelte';
  import { submitForm } from '../lib/api.js';

  let { boot, onEdit } = $props();
  const queries = boot.queries || [];

  function sourceSummary(q) {
    const n = (q.tables || []).length;
    const joins = (q.joins || []).length;
    let s = `${n} table${n === 1 ? '' : 's'}`;
    if (joins) s += ` · ${joins} join${joins === 1 ? '' : 's'}`;
    return s;
  }

  function columnCount(q) {
    if (q.column_order && q.column_order.length) return q.column_order.length;
    return (q.selected_fields || []).length;
  }

  function duplicate(slug) {
    submitForm('formidable_flat_duplicate_query', {
      slug,
      _wpnonce: boot.nonces.duplicateQuery,
    });
  }

  function del(slug, label) {
    if (!window.confirm(`Delete "${label}"? This cannot be undone.`)) return;
    submitForm('formidable_flat_delete_query', {
      slug,
      _wpnonce: boot.nonces.deleteQuery,
    });
  }
</script>

<section>
  <div class="ffapi-card">
    <table class="ffapi-data-table">
      <thead>
        <tr><th>Query</th><th>Sources</th><th>Columns</th><th></th><th></th></tr>
      </thead>
      <tbody>
        {#if queries.length === 0}
          <tr><td colspan="5" class="ffapi-hint" style="padding:20px;">No saved queries yet — use "New Query" to build one.</td></tr>
        {/if}
        {#each queries as q}
          <tr>
            <td>
              <div class="ffapi-row-title">{q.label || q.slug}</div>
              <div class="ffapi-row-sub">{q.slug}</div>
            </td>
            <td>{sourceSummary(q)}</td>
            <td>{columnCount(q)} columns</td>
            <td><ExportMenu {boot} slug={q.slug} label={q.label || q.slug} restBase={boot.restBase} /></td>
            <td style="white-space:nowrap;">
              <button class="ffapi-btn ffapi-btn-icon ffapi-btn-ghost" title="Edit" onclick={() => onEdit(q.slug)}>✎</button>
              <button class="ffapi-btn ffapi-btn-icon ffapi-btn-ghost" title="Duplicate" onclick={() => duplicate(q.slug)}>⧉</button>
              <button class="ffapi-btn ffapi-btn-icon ffapi-btn-ghost ffapi-btn-danger-ghost" title="Delete" onclick={() => del(q.slug, q.label || q.slug)}>🗑</button>
            </td>
          </tr>
        {/each}
      </tbody>
    </table>
  </div>
  <p class="ffapi-hint">"Export" downloads CSV/Excel or opens a print view directly, plus copyable snippets for the REST endpoint and frontend shortcodes.</p>
</section>

<style>
  .ffapi-row-title { font-weight: 600; }
  .ffapi-row-sub { font-size: 11.5px; color: var(--ffapi-text-muted); margin-top: 1px; }
</style>
