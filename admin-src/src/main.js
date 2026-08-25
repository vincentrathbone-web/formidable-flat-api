import { mount } from 'svelte';
import App from './App.svelte';
import { configure } from './lib/api.js';
import { setCalcFunctions } from './lib/formula.js';
import './lib/tokens.css';

const target = document.getElementById('ffapi-admin-app');
if (target) {
  const boot = window.ffapiAdmin || {};
  configure({ ajaxUrl: boot.ajaxUrl, adminUrl: boot.adminUrl, builderNonce: boot.nonces?.builder || '' });
  setCalcFunctions(boot.calcFunctions);

  mount(App, {
    target,
    props: { boot },
  });
}
