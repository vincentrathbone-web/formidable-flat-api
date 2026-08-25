// Thin client for the EXISTING admin-ajax.php / admin-post.php endpoints in
// class-flat-api-admin.php — this rebuild intentionally does not introduce a new REST
// layer.
//
// Two transports, matching what the PHP side actually expects:
//   - fetch() for the true read-only AJAX endpoints (get_form_fields, preview_query,
//     load_query) — these return JSON, and (as of the ffapi_builder nonce) are verified
//     server-side via check_ajax_referer('ffapi_builder', 'nonce').
//   - a real <form> POST submission (full page navigation) for anything that saves/
//     deletes/mutates — those are admin-post.php handlers that verify a nonce and end
//     in wp_redirect(), exactly like the pre-Svelte inline-HTML forms did. Keeping this
//     as a real navigation (not fetch+manual redirect) preserves the existing
//     nonce/redirect/notice behavior with zero backend changes.

let cfg = { ajaxUrl: '', adminUrl: '', builderNonce: '' };

export function configure(c) {
  cfg = { ...cfg, ...c };
}

async function getJson(action, params) {
  const url = new URL(cfg.ajaxUrl);
  url.searchParams.set('action', action);
  url.searchParams.set('nonce', cfg.builderNonce);
  for (const [k, v] of Object.entries(params || {})) url.searchParams.set(k, v);
  const res = await fetch(url.toString(), { credentials: 'same-origin' });
  const json = await res.json();
  if (!json || !json.success) throw new Error((json && json.data) || 'Request failed');
  return json.data;
}

async function postJson(action, body) {
  const fd = new FormData();
  fd.append('action', action);
  fd.append('nonce', cfg.builderNonce);
  for (const [k, v] of Object.entries(body || {})) fd.append(k, v);
  const res = await fetch(cfg.ajaxUrl, { method: 'POST', body: fd, credentials: 'same-origin' });
  const json = await res.json();
  if (!json || !json.success) throw new Error((json && json.data) || 'Request failed');
  return json.data;
}

export function getFormFields(formId) {
  return getJson('ffapi_get_form_fields', { form_id: formId });
}

export function previewQuery(queryDef, limit) {
  return postJson('ffapi_preview_query', {
    query_json: JSON.stringify(queryDef),
    limit: limit ?? 10,
  });
}

export function loadQuery(slug) {
  return getJson('ffapi_load_query', { slug });
}

/**
 * Fetches every row for a saved query (limit=0) via the existing preview AJAX action —
 * used for the admin-side "Print" action, mirroring the pre-Svelte ffapiPrint() flow.
 */
export async function runQueryAll(queryDef) {
  return postJson('ffapi_preview_query', {
    query_json: JSON.stringify(queryDef),
    limit: 0,
  });
}

/**
 * Submit a real form POST to admin-post.php — full page navigation, matching every
 * save/delete/duplicate/settings action's existing nonce + wp_redirect() behavior.
 * `fields` may include a `_wpnonce_field` name pointing at a hidden nonce value already
 * rendered into the page by PHP (see main.js bootstrap) — callers pass the actual
 * nonce string under `_wpnonce`.
 */
export function submitForm(action, fields) {
  const form = document.createElement('form');
  form.method = 'POST';
  form.action = cfg.adminUrl + 'admin-post.php';
  form.style.display = 'none';

  const addField = (name, value) => {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value ?? '';
    form.appendChild(input);
  };

  addField('action', action);
  for (const [k, v] of Object.entries(fields || {})) addField(k, v);

  document.body.appendChild(form);
  form.submit();
}
