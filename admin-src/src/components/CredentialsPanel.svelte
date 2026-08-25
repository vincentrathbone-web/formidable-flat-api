<script>
  import { submitForm } from '../lib/api.js';
  import { buildPowerQuery } from '../lib/power-query.js';
  import { showToast } from '../lib/toast.svelte.js';

  let { boot } = $props();
  let revealed = $state(false);
  let fontSize = $state(boot.fontSize ?? 11);

  const masked = '•'.repeat(Math.max(8, (boot.apiKey || '').length));

  async function copy(text) {
    try {
      if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(text);
    } catch (e) { /* ignore */ }
    showToast('Copied to clipboard');
  }

  function regenerate() {
    if (!window.confirm('Generate a new key? Existing Power Query connections will break.')) return;
    submitForm('formidable_flat_regenerate_key', { _wpnonce: boot.nonces.regenerateKey });
  }

  function saveFontSize() {
    submitForm('formidable_flat_save_font_size', {
      print_font_size: String(fontSize),
      _wpnonce: boot.nonces.saveFontSize,
    });
  }

  const pqSnippet = buildPowerQuery(
    `${boot.restBase}/query/your-query-slug`,
    boot.apiKey,
  );
</script>

<section>
  <div class="ffapi-card">
    <div class="ffapi-card-head"><div><h2>API Key</h2><p>Used as the <span class="ffapi-kbd">X-Api-Key</span> header on every REST request.</p></div></div>
    <div class="ffapi-card-body">
      <div class="ffapi-code-row">
        <code>{revealed ? boot.apiKey : masked}</code>
        <button class="ffapi-btn ffapi-btn-sm ffapi-btn-ghost" onclick={() => (revealed = !revealed)}>{revealed ? 'Hide' : 'Reveal'}</button>
        <button class="ffapi-btn ffapi-btn-sm ffapi-btn-ghost" onclick={() => copy(boot.apiKey)}>Copy</button>
      </div>
      <div style="margin-top:12px; display:flex; gap:8px; align-items:center;">
        <button class="ffapi-btn ffapi-btn-sm ffapi-btn-danger-ghost" onclick={regenerate}>Regenerate key</button>
        <span class="ffapi-hint" style="margin:0;">Existing Power Query connections using the old key will stop working.</span>
      </div>
      <p class="ffapi-hint" style="margin-top:14px;">
        Send the key as an <span class="ffapi-kbd">X-Api-Key</span> request header. In Excel or Power BI,
        choose <strong>Anonymous</strong> when the web-source credential dialog appears: the M query supplies
        the API key itself. Do not select Web API, Windows, Organizational Account, or Basic Authentication.
        Basic API-key authentication remains available only for older integrations and is unreliable on HTTPS WordPress sites.
      </p>
    </div>
  </div>

  <div class="ffapi-card">
    <div class="ffapi-card-head"><div><h2>Power Query snippet</h2><p>Complete template for a Blank Query's Advanced Editor.</p></div></div>
    <div class="ffapi-card-body">
      <div class="ffapi-code-row">
        <code style="white-space:pre-wrap;">{pqSnippet}</code>
        <button class="ffapi-btn ffapi-btn-sm ffapi-btn-ghost" onclick={() => copy(pqSnippet)}>Copy</button>
      </div>
      <ol class="ffapi-hint" style="margin:14px 0 0 20px; line-height:1.7;">
        <li>Replace <span class="ffapi-kbd">your-query-slug</span>, or copy the ready-made snippet from a saved query's Export menu.</li>
        <li>In Excel: Data → Get Data → From Other Sources → Blank Query → Advanced Editor.</li>
        <li>Paste the snippet, choose Done, then choose <strong>Anonymous</strong> for the displayed base URL.</li>
        <li>If Excel keeps spinning, open Data Source Settings, clear permissions for that base URL, and reconnect as Anonymous.</li>
      </ol>
    </div>
  </div>

  <div class="ffapi-card">
    <div class="ffapi-card-head"><div><h2>Print output</h2><p>Font size used by the print/PDF export.</p></div></div>
    <div class="ffapi-card-body" style="display:flex; align-items:center; gap:12px;">
      <input type="number" bind:value={fontSize} min="8" max="24" style="width:80px;" />
      <span class="ffapi-hint" style="margin:0;">pt · applies to <span class="ffapi-kbd">[formidable_flat_button action="print"]</span></span>
      <button class="ffapi-btn ffapi-btn-sm ffapi-btn-primary" onclick={saveFontSize}>Save</button>
    </div>
  </div>
</section>
