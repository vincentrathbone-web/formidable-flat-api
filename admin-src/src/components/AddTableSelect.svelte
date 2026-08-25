<script>
  import SlimSelect from 'slim-select';
  import 'slim-select/styles';

  // Single-select "+ Add table…" trigger, searchable via Slim Select. Unlike
  // KeyFieldPicker, this control's own selection is never a source of truth —
  // picking a form adds it as a table chip elsewhere (via onPick) and the
  // control immediately resets itself to the placeholder so the next table can
  // be added the same way. `options` already excludes forms already added.
  let { options = [], onPick } = $props();

  let selectEl = $state();
  let slim;

  // A native single-select always has an option implicitly selected (the
  // first one, absent any explicit selection) — without a real placeholder
  // entry in the data, SlimSelect treats that implicit selection as a genuine
  // pick, firing afterChange for it immediately (and again after every
  // setData(), cascading through the whole list). The placeholder entry gives
  // it a legitimate "nothing picked" state to fall back to.
  function toData(list) {
    return [
      { text: '+ Add table…', value: '', placeholder: true },
      ...list.map((f) => ({ text: f.name, value: String(f.id) })),
    ];
  }

  $effect(() => {
    if (!selectEl) return;
    const data = toData(options);

    if (!slim) {
      slim = new SlimSelect({
        select: selectEl,
        settings: { searchPlaceholder: 'Search forms…' },
        events: {
          afterChange: (newVal) => {
            const picked = (newVal || [])[0];
            if (!picked || !picked.value) return;
            onPick(Number(picked.value));
            // Reset back to the placeholder for the next pick. runAfterChange
            // is false so this doesn't re-trigger afterChange with an empty
            // selection (see KeyFieldPicker.svelte for the same guard and why
            // it matters: setSelected/setData can otherwise fire a genuine but
            // unwanted change event that fights the next render).
            slim.setSelected([], false);
          },
        },
      });
    }

    slim.setData(data);
  });

  $effect(() => {
    return () => slim?.destroy();
  });
</script>

<div class="ffapi-table-select">
  <select bind:this={selectEl}></select>
</div>

<style>
  .ffapi-table-select {
    display: inline-block;
    width: auto;
    min-width: 180px;
    --ss-primary-color: var(--ffapi-brand);
    --ss-bg-color: var(--ffapi-surface);
    --ss-font-color: var(--ffapi-text-primary);
    --ss-border-color: var(--ffapi-border);
    --ss-border-radius: var(--ffapi-radius-base);
    --ss-font-size: 12.5px;
    --ss-main-height: 32px;
    --ss-content-bg-color: var(--ffapi-surface);
    --ss-content-border-color: var(--ffapi-border);
  }
</style>
