<script>
  import { showToast } from '../lib/toast.svelte.js';
  import { submitForm, loadQuery, runQueryAll } from '../lib/api.js';
  import { buildPowerQuery } from '../lib/power-query.js';

  let { boot, slug, label, restBase } = $props();
  let open = $state(false);
  let printing = $state(false);

  function options() {
    const url = `${restBase}/query/${slug}`;
    return [
      { icon: '📥', label: 'Download CSV', sub: 'Exports this query now', action: () => exportCsv() },
      { icon: '📊', label: 'Download Excel', sub: 'Exports this query now', action: () => exportXlsx() },
      { icon: '🖶', label: printing ? 'Preparing print view…' : 'Print', sub: 'Opens a printable view in a new tab', action: () => printQuery(), disabled: printing },
      { divider: true },
      { icon: '⇄', label: 'REST endpoint', sub: `/query/${slug}`, value: url },
      { icon: 'M', label: 'Power Query', sub: 'Complete Excel / Power BI query', value: buildPowerQuery(url, boot.apiKey) },
      { icon: '▦', label: 'Interactive table', sub: 'Searchable frontend widget', value: `[formidable_flat_table query="${slug}"]` },
      { icon: '🖶', label: 'Print button', sub: 'Shortcode for a frontend page', value: `[formidable_flat_button query="${slug}" action="print"]` },
      { icon: '▤', label: 'CSV button', sub: 'Shortcode for a frontend page', value: `[formidable_flat_button query="${slug}" action="csv"]` },
      { icon: '▥', label: 'Excel button', sub: 'Shortcode for a frontend page', value: `[formidable_flat_button query="${slug}" action="xlsx"]` },
    ];
  }

  function exportCsv() {
    submitForm('formidable_flat_export_csv', { query_slug: slug, _wpnonce: boot.nonces.exportCsv });
    open = false;
  }

  function exportXlsx() {
    submitForm('formidable_flat_export_xlsx', { query_slug: slug, _wpnonce: boot.nonces.exportXlsx });
    open = false;
  }

  async function printQuery() {
    if (printing) return;
    printing = true;
    open = false;
    const win = window.open('', '_blank');
    if (win) win.document.write('<html><head><title>Loading…</title></head><body><p style="font-family:sans-serif;padding:20px;">⏳ Loading data for printing…</p></body></html>');
    try {
      const queryDef = await loadQuery(slug);
      const result = await runQueryAll(queryDef);
      const rows = result?.rows || [];
      const keys = rows.length ? [...new Set(rows.flatMap((r) => Object.keys(r)))] : [];
      const fontSize = Number(boot.fontSize) || 11;
      const title = label || slug;

      const esc = (v) => String(v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
      const tableHtml = keys.length === 0 ? '<p>No data to print.</p>' :
        '<table>' +
        '<thead><tr>' + keys.map((k) => `<th>${esc(k)}</th>`).join('') + '</tr></thead>' +
        '<tbody>' + rows.map((row) =>
          '<tr>' + keys.map((k) => {
            const v = row[k] !== undefined ? row[k] : '';
            return `<td>${esc(typeof v === 'object' ? JSON.stringify(v) : v)}</td>`;
          }).join('') + '</tr>'
        ).join('') + '</tbody></table>';

      if (win) {
        win.document.write(`<!DOCTYPE html><html><head><title>${esc(title)}</title>
        <style>
          * { box-sizing: border-box; margin: 0; padding: 0; }
          body { font-family: "Segoe UI", Arial, sans-serif; font-size: ${fontSize}px; color: #111; padding: 16px; }
          h1 { font-size: ${fontSize + 5}px; margin-bottom: 4px; }
          .meta { font-size: ${fontSize}px; color: #666; margin-bottom: 14px; }
          table { width: 100%; border-collapse: collapse; }
          th { background: #1d2327; color: #fff; padding: 7px 10px; text-align: left; font-size: ${fontSize}px; border: 1px solid #444; }
          td { padding: 6px 10px; border: 1px solid #ddd; vertical-align: top; font-size: ${fontSize}px; }
          tr:nth-child(even) td { background: #f7f7f7; }
          @media print {
            body { padding: 8px; }
            th { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            tr:nth-child(even) td { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
          }
        </style>
        </head><body>
        <h1>${esc(title)}</h1>
        <p class="meta">Generated: ${new Date().toLocaleString()} &nbsp;|&nbsp; ${rows.length} row(s)</p>
        ${tableHtml}
        <script>window.onload = () => { window.print(); }<\/script>
        </body></html>`);
        win.document.close();
      }
    } catch (e) {
      if (win) win.document.write('<p style="font-family:sans-serif;padding:20px;">Error loading data for printing.</p>');
      showToast('Print failed: ' + (e?.message || 'unknown error'));
    } finally {
      printing = false;
    }
  }

  async function copy(value) {
    try {
      if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(value);
    } catch (e) { /* clipboard denied — value is still visible via the code row it came from */ }
    showToast('Copied to clipboard');
    open = false;
  }

  function onWindowClick(e) {
    if (!e.target.closest?.('.ffapi-export-wrap')) open = false;
  }
</script>

<svelte:window onclick={onWindowClick} />

<div class="ffapi-export-wrap">
  <button class="ffapi-btn ffapi-btn-sm" onclick={(e) => { e.stopPropagation(); open = !open; }}>
    Export
    <svg width="10" height="10" viewBox="0 0 10 10" fill="none"><path d="M2.5 3.75L5 6.25L7.5 3.75" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"/></svg>
  </button>
  {#if open}
    <div class="ffapi-export-menu">
      {#each options() as opt}
        {#if opt.divider}
          <div class="ffapi-export-divider"></div>
        {:else}
          <button class="ffapi-export-item" disabled={opt.disabled} onclick={() => (opt.action ? opt.action() : copy(opt.value))}>
            <span class="ffapi-export-icon">{opt.icon}</span>
            <span>
              <span class="ffapi-export-label">{opt.label}</span><br />
              <span class="ffapi-export-sub">{opt.sub}</span>
            </span>
          </button>
        {/if}
      {/each}
    </div>
  {/if}
</div>

<style>
  .ffapi-export-wrap { position: relative; display: inline-block; }
  .ffapi-export-menu {
    position: absolute; right: 0; top: calc(100% + 6px); z-index: 40;
    width: 300px; background: var(--ffapi-surface); border: 1px solid var(--ffapi-border); border-radius: var(--ffapi-radius-lg);
    box-shadow: var(--ffapi-shadow-pop); padding: 8px;
  }
  .ffapi-export-item {
    display: flex; align-items: center; gap: 10px; width: 100%; text-align: left; background: none; border: none;
    padding: 8px 9px; border-radius: var(--ffapi-radius-base); cursor: pointer; color: var(--ffapi-text-primary); font-size: 12.5px;
  }
  .ffapi-export-item:hover { background: var(--ffapi-surface-sunken); }
  .ffapi-export-item:disabled { opacity: 0.55; cursor: default; }
  .ffapi-export-icon {
    width: 26px; height: 26px; border-radius: var(--ffapi-radius-sm); background: var(--ffapi-surface-sunken);
    display: flex; align-items: center; justify-content: center; flex: none; font-size: 13px;
  }
  .ffapi-export-label { font-weight: 600; }
  .ffapi-export-sub { font-size: 11px; color: var(--ffapi-text-muted); font-weight: 400; }
  .ffapi-export-divider { height: 1px; background: var(--ffapi-border); margin: 6px 2px; }
</style>
