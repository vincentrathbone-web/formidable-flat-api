<script>
  import { untrack } from 'svelte';
  import SlimSelect from 'slim-select';
  import 'slim-select/styles';

  // `fields` is this table's field list (from ajax_get_form_fields — [{id, name}, …]);
  // `keyFieldIds` is the parent's source of truth (tables[ti].key_field_id, a number[]).
  //
  // Previously built on svelte-multiselect's `bind:selected`, which raced against an
  // $effect that re-derived `selected` from props: clicking an option fired onChange,
  // the parent wrote back a new `key_field_id` array, and the resulting prop update ran
  // the effect again — sometimes overwriting the in-progress click before it stuck. Slim
  // Select is driven imperatively instead: we own the instance, push data/selection into
  // it, and only react to its `afterChange` event, so there's no competing reactive
  // binding to lose the race.
  let { fields = [], keyFieldIds = [], onChange } = $props();

  let selectEl = $state();
  let slim;

  // frm_items metadata appears in the same visual form group as ordinary
  // fields, but it cannot be a join key because join keys are frm_fields IDs.
  //
  // Two entries with the same field id are deliberately collapsed to one:
  // Slim Select tracks options by its own internal id, so if `fields` ever lists
  // the same field id twice (e.g. a form misconfigured as its own parent — see
  // ajax_get_form_fields()/get_parent_form_context() in the PHP), one value
  // would resolve to two distinct options both marked selected. Deduping here
  // removes the possibility outright, regardless of what produced the duplicate.
  function toData(list) {
    const seen = new Set();
    const out = [];
    for (const f of list) {
      if (f.is_system) continue;
      const value = String(f.id);
      if (seen.has(value)) continue;
      seen.add(value);
      out.push({ text: f.label || f.name, value });
    }
    return out;
  }

  function currentValues() {
    return [...new Set((keyFieldIds || []).map(String))];
  }

  // Slim Select's setData() *replaces* every option with a brand-new object —
  // each one gets a fresh internal id (Math.random()-based). Its own selection-
  // changed check compares those internal ids (Store.getSelected() returns
  // option.id, not option.value), via a plain JSON.stringify equality — so
  // calling setData() again after a selection is already applied always looks
  // like "the selection changed" even when the actual field ids are identical,
  // and setData() fires its own afterChange unconditionally when that happens.
  // Doing that on every reactive run — as an earlier version of this component
  // did, to keep `selected` flags in the freshly-built data in sync — created a
  // genuine infinite loop: click → afterChange → onChange → new keyFieldIds
  // prop → effect reruns → setData() → phantom afterChange (same value, new
  // internal ids) → onChange again → forever, pegging the tab and leaving the
  // picker unresponsive.
  //
  // The fix is to only ever call setData() when the option *list* actually
  // changes (fields), and use setSelected() — which mutates the existing
  // options' `.selected` flag in place rather than recreating them, so their
  // ids stay stable across calls — for every selection-only update. `keyFieldIds`
  // is read with untrack() in the fields-effect so a selection-only change
  // doesn't also re-trigger a setData() call here.
  $effect(() => {
    if (!selectEl) return;
    const data = toData(fields);

    if (!slim) {
      slim = new SlimSelect({
        select: selectEl,
        // keepOrder: for a composite key, field pick order is meaningful — the
        // engine concatenates each table's key field values in key_field_id's
        // array order to build the join match string (implode('||', ...) in
        // fetch_merged_rows()), so two tables keying on the same fields but
        // clicked in a different order produce different match strings and
        // silently never join. Without keepOrder, Slim Select already stores
        // and reports selection in click order (confirmed: the underlying
        // afterChange payload/keyFieldIds was correct) but *displays* the chips
        // in field-list order regardless, which looks wrong and invites
        // "fixing" it by re-clicking into a now-actually-wrong order. keepOrder
        // makes the display match what's actually stored.
        settings: { placeholderText: 'Select key field(s)…', closeOnSelect: false, keepOrder: true },
        events: {
          // runAfterChange defaults to true only for genuine user interaction;
          // our own setSelected calls always pass `false` to avoid re-triggering
          // this and looping.
          afterChange: (newVal) => onChange([...new Set((newVal || []).map((o) => Number(o.value)))]),
        },
      });
    }

    slim.setData(data);
    slim.setSelected(untrack(currentValues), false);
  });

  $effect(() => {
    if (!slim) return;
    slim.setSelected(currentValues(), false);
  });

  $effect(() => {
    return () => slim?.destroy();
  });
</script>

<div class="ffapi-keypicker">
  <select bind:this={selectEl} multiple></select>
</div>

<style>
  .ffapi-keypicker {
    flex: 1;
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
