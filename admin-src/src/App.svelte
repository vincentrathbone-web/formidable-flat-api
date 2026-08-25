<script>
  import Toast from './components/Toast.svelte';
  import SavedQueriesList from './components/SavedQueriesList.svelte';
  import QueryBuilder from './components/QueryBuilder.svelte';
  import EndpointBuilder from './components/EndpointBuilder.svelte';
  import CredentialsPanel from './components/CredentialsPanel.svelte';
  import ShortcodesReference from './components/ShortcodesReference.svelte';

  let { boot } = $props();

  const TABS = [
    { id: 'queries', label: 'Saved Queries', icon: '☰' },
    { id: 'builder', label: 'Query Builder', icon: '⊕' },
    { id: 'legacy', label: 'Endpoint Builder', icon: '⇄' },
    { id: 'credentials', label: 'Credentials', icon: '🔑' },
    { id: 'shortcodes', label: 'Shortcodes', icon: '⧉' },
  ];

  function initialTab() {
    const params = new URLSearchParams(window.location.search);
    const t = params.get('tab');
    return TABS.some((x) => x.id === t) ? t : (boot.tab || 'queries');
  }

  let activeTab = $state(initialTab());
  let editSlug = $state(new URLSearchParams(window.location.search).get('edit') || '');
  let theme = $state('light');

  function switchTab(id, opts = {}) {
    activeTab = id;
    editSlug = opts.editSlug || '';
    const url = new URL(window.location.href);
    url.searchParams.set('tab', id);
    if (opts.editSlug) url.searchParams.set('edit', opts.editSlug);
    else url.searchParams.delete('edit');
    window.history.pushState({}, '', url);
  }

  window.addEventListener('popstate', () => {
    activeTab = initialTab();
    editSlug = new URLSearchParams(window.location.search).get('edit') || '';
  });

  function openBuilder(slug) {
    switchTab('builder', { editSlug: slug || '' });
  }

  const noticeText = (() => {
    const p = new URLSearchParams(window.location.search);
    if (p.get('saved')) return '✅ Query saved successfully.';
    if (p.get('deleted')) return '🗑️ Query deleted.';
    if (p.get('font_saved')) return '✅ Print font size setting saved.';
    if (p.get('theme_saved')) return '✅ Table theme updated. Refresh the frontend page to see changes.';
    return '';
  })();
  let noticeVisible = $state(!!noticeText);
</script>

<div class="ffapi-svelte-root" data-theme={theme}>
  <div class="ffapi-header">
    <div class="ffapi-header-top">
      <div class="ffapi-brand">
        <div class="ffapi-brand-mark" aria-hidden="true"></div>
        <div>
          <div class="ffapi-brand-name">Formidable Flat API</div>
          <div class="ffapi-brand-sub">v{boot.version || ''}</div>
        </div>
      </div>
      <div class="ffapi-theme-toggle" role="group" aria-label="Theme">
        <button class:active={theme === 'light'} onclick={() => (theme = 'light')}>Light</button>
        <button class:active={theme === 'dark'} onclick={() => (theme = 'dark')}>Dark</button>
      </div>
    </div>
    <nav class="ffapi-tab-strip">
      {#each TABS as t}
        <button class="ffapi-tab-btn" class:active={activeTab === t.id} onclick={() => switchTab(t.id)}>
          <span class="ffapi-tab-icon">{t.icon}</span>
          {t.label}
          {#if t.id === 'queries'}<span class="ffapi-badge-count">{(boot.queries || []).length}</span>{/if}
        </button>
      {/each}
      <a class="ffapi-tab-btn ffapi-tab-link" href={boot.dmrReportsUrl || '#'}>
        <span class="ffapi-tab-icon">📄</span> DMR Reports
      </a>
    </nav>
  </div>

  {#if noticeVisible}
    <div class="ffapi-notice-bar">
      <div class="ffapi-notice">{noticeText}</div>
    </div>
  {/if}

  <div class="ffapi-content">
    {#if activeTab === 'queries'}
      <SavedQueriesList {boot} onEdit={openBuilder} />
    {:else if activeTab === 'builder'}
      {#key editSlug}
        <QueryBuilder {boot} slug={editSlug} onSaved={() => switchTab('queries')} />
      {/key}
    {:else if activeTab === 'legacy'}
      <EndpointBuilder {boot} />
    {:else if activeTab === 'credentials'}
      <CredentialsPanel {boot} />
    {:else if activeTab === 'shortcodes'}
      <ShortcodesReference {boot} />
    {/if}
  </div>
</div>

<Toast />

<style>
  .ffapi-header {
    background: var(--ffapi-header-bg); border-bottom: 1px solid var(--ffapi-border);
  }
  .ffapi-header-top {
    display: flex; align-items: center; justify-content: space-between;
    padding: 14px 24px 0;
  }
  .ffapi-brand { display: flex; align-items: center; gap: 10px; }
  .ffapi-brand-mark {
    width: 26px; height: 26px; flex: none; border-radius: var(--ffapi-radius-sm);
    background: linear-gradient(135deg, var(--ffapi-accent-blue), var(--ffapi-brand)); position: relative;
  }
  .ffapi-brand-mark::before, .ffapi-brand-mark::after { content: ''; position: absolute; background: #fff; border-radius: 1px; }
  .ffapi-brand-mark::before { width: 11px; height: 2px; top: 8px; left: 7.5px; }
  .ffapi-brand-mark::after { width: 7px; height: 2px; top: 15.5px; left: 7.5px; }
  .ffapi-brand-name { font-size: 14.5px; font-weight: 700; letter-spacing: -0.1px; }
  .ffapi-brand-sub { font-size: 11px; color: var(--ffapi-text-muted); margin-top: -1px; }

  .ffapi-theme-toggle { display: flex; gap: 3px; background: var(--ffapi-surface-sunken); border-radius: var(--ffapi-radius-pill); padding: 3px; }
  .ffapi-theme-toggle button {
    border: none; background: none; color: var(--ffapi-text-medium); font-size: 11.5px; font-weight: 500;
    padding: 5px 12px; border-radius: var(--ffapi-radius-pill); cursor: pointer;
  }
  .ffapi-theme-toggle button.active { background: var(--ffapi-surface); color: var(--ffapi-text-primary); box-shadow: var(--ffapi-shadow-card); }

  .ffapi-tab-strip { display: flex; gap: 4px; padding: 14px 20px 0; overflow-x: auto; }
  .ffapi-tab-btn {
    display: flex; align-items: center; gap: 7px; background: none; border: none; cursor: pointer;
    color: var(--ffapi-text-medium); font-size: 13.5px; font-weight: 500; padding: 9px 14px 11px;
    border-bottom: 2.5px solid transparent; white-space: nowrap; text-decoration: none;
  }
  .ffapi-tab-btn:hover { color: var(--ffapi-text-primary); }
  .ffapi-tab-btn.active { color: var(--ffapi-brand); border-bottom-color: var(--ffapi-brand); font-weight: 600; }
  .ffapi-tab-link { margin-left: auto; opacity: 0.85; }
  .ffapi-tab-icon { width: 15px; flex: none; text-align: center; }
  .ffapi-badge-count {
    font-size: 10.5px; font-weight: 700; background: var(--ffapi-surface-sunken); color: var(--ffapi-text-medium);
    padding: 1px 6px; border-radius: var(--ffapi-radius-pill);
  }
  .ffapi-tab-btn.active .ffapi-badge-count { background: var(--ffapi-brand-wash); color: var(--ffapi-brand); }

  .ffapi-notice-bar { padding: 12px 24px 0; max-width: 1180px; margin: 0 auto; }
  .ffapi-notice { padding: 10px 16px; border-radius: var(--ffapi-radius-base); font-size: 13px; background: var(--ffapi-success-bg); color: var(--ffapi-success); }

  .ffapi-content { padding: 16px 24px 60px; max-width: 1180px; margin: 0 auto; }
</style>
