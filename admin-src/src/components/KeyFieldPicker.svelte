<script>
  import MultiSelect from 'svelte-multiselect';

  // `fields` is this table's field list (from ajax_get_form_fields — [{id, name}, …]);
  // `keyFieldIds` is the parent's source of truth (tables[ti].key_field_id, a number[]).
  // We keep our own `selected` state (svelte-multiselect needs a bindable array of its
  // own Option objects) and re-derive it from `keyFieldIds`/`fields` in an $effect —
  // required because `fields` and `keyFieldIds` can both change asynchronously after
  // this component mounts (a table's fields load via AJAX; an edited query's saved key
  // ids arrive after `loadQuery()` resolves), and a prop only read once into $state()
  // would miss those later updates (the exact bug already hit once in App.svelte).
  let { fields = [], keyFieldIds = [], onChange } = $props();

  // frm_items metadata appears in the same visual form group as ordinary
  // fields, but it cannot be a join key because join keys are frm_fields IDs.
  let options = $derived(
    fields
      .filter((f) => !f.is_system)
      .map((f) => ({ label: f.label || f.name, value: Number(f.id) }))
  );
  let selected = $state([]);

  $effect(() => {
    selected = options.filter((o) => keyFieldIds.includes(o.value));
  });

  function handleChange(data) {
    onChange((data.options || []).map((o) => o.value));
  }
</script>

<div class="ffapi-keypicker">
  <MultiSelect bind:selected {options} onchange={handleChange} placeholder="Select key field(s)…" />
</div>

<style>
  .ffapi-keypicker {
    flex: 1;
    --sms-bg: var(--ffapi-surface);
    --sms-text-color: var(--ffapi-text-primary);
    --sms-border: 1px solid var(--ffapi-border);
    --sms-border-radius: var(--ffapi-radius-base);
    --sms-focus-border: 1px solid var(--ffapi-brand);
    --sms-padding: 4px 8px;
    --sms-min-height: 32px;
    --sms-font-size: 12.5px;
    --sms-options-bg: var(--ffapi-surface);
    --sms-options-border: 1px solid var(--ffapi-border);
    --sms-options-shadow: var(--ffapi-shadow-pop);
    --sms-li-active-bg: var(--ffapi-surface-sunken);
    --sms-selected-bg: var(--ffapi-brand);
    --sms-selected-text-color: #fff;
    --sms-active-color: var(--ffapi-brand);
    --sms-placeholder-color: var(--ffapi-text-muted);
    --sms-max-width: 100%;
    --sms-width: 100%;
  }
</style>
