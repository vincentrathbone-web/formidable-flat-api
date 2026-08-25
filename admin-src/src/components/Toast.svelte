<script>
  import { toastState } from '../lib/toast.svelte.js';
</script>

<div class="ffapi-toast-host">
  {#each toastState.items as t (t.id)}
    <div class="ffapi-toast show">{t.message}</div>
  {/each}
</div>
