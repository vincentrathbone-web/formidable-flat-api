// Shared toast notification store (Svelte 5 runes work in .svelte.js modules too).
let nextId = 1;
export const toastState = $state({ items: [] });

export function showToast(message) {
  const id = nextId++;
  toastState.items.push({ id, message });
  setTimeout(() => {
    toastState.items = toastState.items.filter((t) => t.id !== id);
  }, 2200);
}
