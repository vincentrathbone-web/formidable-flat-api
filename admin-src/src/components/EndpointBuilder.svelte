<script>
  import { getFormFields } from '../lib/api.js';
  import { showToast } from '../lib/toast.svelte.js';

  let { boot } = $props();

  let type = $state('form');
  let formId = $state('');
  let viewId = $state('');
  let mergeRows = $state([{ formId: '', keyFieldId: '', fields: [] }]);

  function url() {
    const base = boot.restBase || '';
    if (type === 'form') return `${base}/form/${formId || '{ID}'}`;
    if (type === 'view') return `${base}/view/${viewId || '{ID}'}`;
    const ids = mergeRows.filter((r) => r.formId).map((r) => r.formId);
    const keys = mergeRows.filter((r) => r.formId).map((r) => r.keyFieldId || '0');
    return `${base}/merged/${ids.length ? ids.join(',') : '{IDS}'}/${keys.length ? keys.join(',') : '{KEYS}'}`;
  }

  function addMergeRow() {
    mergeRows = [...mergeRows, { formId: '', keyFieldId: '', fields: [] }];
  }
  function removeMergeRow(i) {
    mergeRows = mergeRows.filter((_, idx) => idx !== i);
  }
  async function onMergeFormChange(i, fid) {
    mergeRows = mergeRows.map((r, idx) => (idx === i ? { ...r, formId: fid, keyFieldId: '' } : r));
    if (!fid) return;
    try {
      const fields = await getFormFields(fid);
      mergeRows = mergeRows.map((r, idx) => (idx === i ? { ...r, fields } : r));
    } catch (e) { /* field list stays empty, key select just shows no options */ }
  }

  async function copy() {
    try {
      if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(url());
    } catch (e) { /* ignore */ }
    showToast('Copied to clipboard');
  }
</script>

<section>
  <div class="ffapi-card">
    <div class="ffapi-card-head"><div><h2>Ad-hoc endpoint</h2><p>Build a REST URL without saving a query.</p></div></div>
    <div class="ffapi-card-body">
      <span class="ffapi-field-label">Type</span>
      <select bind:value={type} style="width:auto; min-width:160px;">
        <option value="form">Form</option>
        <option value="view">View</option>
        <option value="merged">Merged</option>
      </select>

      {#if type === 'form'}
        <div style="margin-top:12px;">
          <span class="ffapi-field-label">Form</span>
          <select bind:value={formId} style="max-width:360px;">
            <option value="">— select a form —</option>
            {#each boot.forms || [] as f}<option value={f.id}>{f.name} (ID:{f.id})</option>{/each}
          </select>
        </div>
      {:else if type === 'view'}
        <div style="margin-top:12px;">
          <span class="ffapi-field-label">View ID</span>
          <input type="text" bind:value={viewId} placeholder="e.g. 42" style="max-width:200px;" />
        </div>
      {:else}
        <div style="margin-top:12px; display:flex; flex-direction:column; gap:8px;">
          {#each mergeRows as row, i}
            <div style="display:flex; gap:10px; align-items:center;">
              <select style="flex:1;" onchange={(e) => onMergeFormChange(i, e.target.value)}>
                <option value="">— Form —</option>
                {#each boot.forms || [] as f}<option value={f.id} selected={String(f.id) === String(row.formId)}>{f.name} (ID:{f.id})</option>{/each}
              </select>
              <select style="flex:1;" bind:value={row.keyFieldId}>
                <option value="">— Key field —</option>
                {#each row.fields as f}<option value={f.id}>{f.name}</option>{/each}
              </select>
              <button class="ffapi-row-remove" onclick={() => removeMergeRow(i)} title="Remove">×</button>
            </div>
          {/each}
          <button class="ffapi-btn ffapi-btn-sm ffapi-btn-ghost" style="align-self:flex-start;" onclick={addMergeRow}>+ Add table</button>
          <p class="ffapi-hint">Counts must match for a merged endpoint — one key field per form, in the same order.</p>
        </div>
      {/if}

      <div class="ffapi-code-row" style="margin-top:14px;">
        <code>{url()}</code>
        <button class="ffapi-btn ffapi-btn-sm ffapi-btn-ghost" onclick={copy}>Copy</button>
      </div>
    </div>
  </div>
</section>

<style>
  .ffapi-row-remove { background: none; border: none; color: var(--ffapi-text-muted); cursor: pointer; font-size: 16px; padding: 4px; flex: none; }
  .ffapi-row-remove:hover { color: var(--ffapi-danger); }
</style>
