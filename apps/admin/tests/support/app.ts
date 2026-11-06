import { flushPromises, mount } from '@vue/test-utils';
import type { VueWrapper } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { Notify, Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import { vi } from 'vitest';
import { handle } from '../../dev/mockApi';
import App from '../../src/App.vue';
import { resetConfigForTests } from '../../src/config';
import { createAppI18n } from '../../src/i18n';
import { createAppRouter } from '../../src/router';
import type { Capability } from '../../src/types/config';

export const REST_ROOT = 'https://x.test/wp-json/sw-backupit/v1/';
export const ALL_CAPS: Capability[] = [
  'swbi_view',
  'swbi_run',
  'swbi_manage_profiles',
  'swbi_manage_storage',
  'swbi_download',
  'swbi_delete',
  'swbi_restore',
  'swbi_settings',
];

/** Route every REST call through the in-memory contract implementation used by the dev server. */
export function installMockFetch(override?: (path: string, init: RequestInit) => Response | null) {
  const fetchMock = vi.fn(async (input: RequestInfo | URL, init: RequestInit = {}) => {
    const url = new URL(String(input));
    const path = url.pathname.slice('/wp-json/sw-backupit/v1/'.length);
    const custom = override?.(path, init);
    if (custom) return custom;
    const method = init.method ?? 'GET';
    const body =
      typeof init.body === 'string' && init.body !== ''
        ? (JSON.parse(init.body) as Record<string, unknown>)
        : {};
    const res = handle({ method, path, query: url.searchParams, body });
    return new Response(res.status === 204 ? null : JSON.stringify(res.body), {
      status: res.status,
      headers: { 'Content-Type': 'application/json' },
    });
  });
  vi.stubGlobal('fetch', fetchMock);
  return fetchMock;
}

export function configure(caps: Capability[] = ALL_CAPS, locale = 'en_US'): void {
  window.swbackupitConfig = {
    restRoot: REST_ROOT,
    nonce: 'n',
    locale,
    version: '1.0.0',
    adminUrl: 'https://x.test/wp-admin/',
    capabilities: caps,
    siteUrl: 'https://x.test',
  };
  resetConfigForTests();
  window.scrollTo = (() => undefined) as typeof window.scrollTo;
  Element.prototype.scrollTo = (() => undefined) as typeof Element.prototype.scrollTo;
}

export async function settle(rounds = 4): Promise<void> {
  for (let i = 0; i < rounds; i++) {
    await flushPromises();
    await new Promise((r) => setTimeout(r, 0));
  }
}

/** Mount the full shell (layout, router, stores) like main.ts does. */
export async function mountApp(path = '/'): Promise<{
  wrapper: VueWrapper;
  router: ReturnType<typeof createAppRouter>;
}> {
  const router = createAppRouter();
  await router.push(path);
  const host = document.createElement('div');
  host.id = 'sw-backupit-app';
  document.body.appendChild(host);
  const wrapper = mount(App, {
    attachTo: host,
    global: {
      plugins: [
        createPinia(),
        router,
        createAppI18n(window.swbackupitConfig?.locale ?? 'en_US'),
        [Quasar, { plugins: { Notify }, iconSet }],
      ] as never[],
    },
  });
  await router.isReady();
  await settle();
  return { wrapper, router };
}

/** Find a button (incl. Quasar portals in body) by its visible label or aria-label. */
export function findButton(label: string | RegExp): HTMLElement | undefined {
  const match = (s: string | null | undefined) =>
    s != null && (typeof label === 'string' ? s.trim() === label : label.test(s.trim()));
  return Array.from(document.querySelectorAll<HTMLElement>('button, [role="button"], a')).find(
    (b) => match(b.getAttribute('aria-label')) || match(b.textContent),
  );
}

export async function click(label: string | RegExp): Promise<boolean> {
  const b = findButton(label);
  if (!b) return false;
  b.click();
  await settle();
  return true;
}
