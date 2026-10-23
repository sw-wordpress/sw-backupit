import { createApp } from 'vue';
import { createPinia } from 'pinia';
import { Notify, Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import 'quasar/dist/quasar.prod.css';
import '@quasar/extras/material-icons-round/material-icons-round.css';
import './styles/fonts.css';
import './styles/app.css';

import App from './App.vue';
import { getConfig } from './config';
import { createAppI18n } from './i18n';
import { createAppRouter } from './router';

const MOUNT_ID = 'swbackupit-app';

function showFatal(el: HTMLElement, message: string): void {
  el.classList.add('swbi-root');
  const p = document.createElement('p');
  p.setAttribute('role', 'alert');
  p.textContent = message; // textContent: never inject markup
  el.replaceChildren(p);
}

function bootstrap(): void {
  const el = document.getElementById(MOUNT_ID);
  if (!el) {
    console.error(`SWBackupIt: mount node #${MOUNT_ID} not found`);
    return;
  }
  let config;
  try {
    config = getConfig();
  } catch (error) {
    console.error(error);
    showFatal(el, 'SWBackupIt: configuration missing (window.swbackupitConfig).');
    return;
  }

  el.classList.add('swbi-root', 'swbi-fullbleed');
  document.body.classList.add('swbi-admin-page');

  const app = createApp(App);
  app.use(createPinia());
  app.use(createAppRouter());
  app.use(createAppI18n(config.locale));
  app.use(Quasar, {
    plugins: { Notify },
    iconSet,
    // Quasar portals (dialogs, menus, tooltips, notifications) are appended to <body>; give them
    // the root class so the scoped Quasar CSS and our tokens apply there as well.
    config: { globalNodes: { class: 'swbi-root' } },
  });
  app.mount(el);
}

bootstrap();
