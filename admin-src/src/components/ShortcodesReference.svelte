<script>
  import { submitForm } from '../lib/api.js';
  import { showToast } from '../lib/toast.svelte.js';

  let { boot } = $props();
  const exampleSlug = (boot.queries && boot.queries[0] && boot.queries[0].slug) || 'your-query-slug';
  let theme = $state(boot.theme || 'simple');

  const THEMES = [
    { id: 'simple', label: 'Simple' },
    { id: 'midnight', label: 'Midnight' },
    { id: 'modern', label: 'Modern' },
    { id: 'site', label: 'Site' },
    { id: 'site_dark', label: 'Site (dark)' },
  ];

  async function copy(text) {
    try {
      if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(text);
    } catch (e) { /* ignore */ }
    showToast('Copied to clipboard');
  }

  function saveTheme() {
    submitForm('formidable_flat_save_theme', { table_theme: theme, _wpnonce: boot.nonces.saveTheme });
  }
</script>

<section>
  <div class="ffapi-shortcode-grid">
    <div class="ffapi-card">
      <div class="ffapi-card-head"><div><h2>Interactive table</h2><p>Searchable, exportable, on the frontend.</p></div></div>
      <div class="ffapi-card-body">
        <div class="ffapi-code-row">
          <code>[formidable_flat_table query="{exampleSlug}"]</code>
          <button class="ffapi-btn ffapi-btn-sm ffapi-btn-ghost" onclick={() => copy(`[formidable_flat_table query="${exampleSlug}"]`)}>Copy</button>
        </div>
        <span class="ffapi-field-label" style="margin-top:14px;">Table theme (Tabulator base style, site-wide)</span>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
          <select bind:value={theme} style="width:auto;">
            {#each THEMES as t}<option value={t.id}>{t.label}</option>{/each}
          </select>
          <button class="ffapi-btn ffapi-btn-sm ffapi-btn-primary" onclick={saveTheme}>Save</button>
        </div>
        <p class="ffapi-hint">Per-table light/dark chrome is set separately via <span class="ffapi-kbd">theme="dark"</span> on the shortcode itself.</p>
      </div>
    </div>
    <div class="ffapi-card">
      <div class="ffapi-card-head"><div><h2>Export button</h2><p>Print, CSV or XLSX, one click.</p></div></div>
      <div class="ffapi-card-body">
        <div class="ffapi-code-row">
          <code>[formidable_flat_button query="{exampleSlug}" action="xlsx"]</code>
          <button class="ffapi-btn ffapi-btn-sm ffapi-btn-ghost" onclick={() => copy(`[formidable_flat_button query="${exampleSlug}" action="xlsx"]`)}>Copy</button>
        </div>
        <p class="ffapi-hint">Also accepts <span class="ffapi-kbd">action="print"</span> and <span class="ffapi-kbd">action="csv"</span>, and <span class="ffapi-kbd">type="icon"</span> for an icon-only variant.</p>
      </div>
    </div>
  </div>
</section>

<style>
  .ffapi-shortcode-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  @media (max-width: 900px) { .ffapi-shortcode-grid { grid-template-columns: 1fr; } }
</style>
