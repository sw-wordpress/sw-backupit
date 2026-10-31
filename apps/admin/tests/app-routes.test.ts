import { mount } from '@vue/test-utils';
import { Notify, Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { handle } from '../dev/mockApi';
import { ApiError } from '../src/api/client';
import { useNotify } from '../src/composables/useNotify';
import { createAppI18n } from '../src/i18n';
import quasarLang from '../src/i18n/quasarLang';
import { configure, installMockFetch, mountApp, settle } from './support/app';

beforeEach(() => {
  configure();
  installMockFetch();
});
afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});

const ROUTES: Array<[string, string]> = [
  ['/', 'Dashboard'],
  ['/backups', 'Backups'],
  ['/jobs', 'Jobs'],
  ['/profiles', 'Profiles'],
  ['/schedules', 'Schedules'],
  ['/storage', 'Storage'],
  ['/audit', 'Audit'],
  ['/settings', 'Settings'],
];

describe('app shell', () => {
  it.each(ROUTES)('renders %s against the REST contract', async (path, title) => {
    const { wrapper } = await mountApp(path);
    expect(wrapper.find('nav').exists()).toBe(true);
    expect(wrapper.find('h1').text()).toContain(title);
    wrapper.unmount();
  });

  it.each(['/profiles/new', '/profiles/1/edit'])('renders the profile editor at %s', async (p) => {
    const { wrapper } = await mountApp(p);
    expect(wrapper.find('nav[aria-label]').exists()).toBe(true);
    wrapper.unmount();
  });

  it('renders a job detail with phases and logs', async () => {
    const list = handle({ method: 'GET', path: 'jobs', query: new URLSearchParams(), body: {} })
      .body as { items: Array<{ id: string; status: string }> };
    const job = list.items.find((j) => j.status === 'completed') ?? list.items[0]!;
    const { wrapper } = await mountApp(`/jobs/${job.id}`);
    await settle(8);
    expect(document.body.textContent).toContain(job.id.slice(0, 8));
    wrapper.unmount();
  });

  it('renders a running job detail and polls it', async () => {
    const list = handle({ method: 'GET', path: 'jobs', query: new URLSearchParams(), body: {} })
      .body as { items: Array<{ id: string; status: string }> };
    const running = list.items.find((j) => !['completed', 'failed', 'cancelled'].includes(j.status));
    expect(running).toBeDefined();
    const { wrapper } = await mountApp(`/jobs/${running!.id}`);
    await settle(8);
    expect(wrapper.find('h1').exists()).toBe(true);
    wrapper.unmount();
  });

  it('hides settings-only navigation without the capability', async () => {
    configure(['swbi_view']);
    const { wrapper } = await mountApp('/');
    expect(wrapper.find('nav').text()).not.toContain('Audit');
    wrapper.unmount();
  });

  it('shows the error state when the API fails', async () => {
    installMockFetch(
      () =>
        new Response(JSON.stringify({ code: 'boom', message: 'Server exploded', data: { status: 500 } }), {
          status: 500,
          headers: { 'Content-Type': 'application/json' },
        }),
    );
    const { wrapper } = await mountApp('/backups');
    expect(document.body.textContent).toContain('Server exploded');
    wrapper.unmount();
  });
});

describe('bootstrap (main.ts)', () => {
  it('reports a missing mount node', async () => {
    vi.resetModules();
    const err = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    await import('../src/main');
    expect(err).toHaveBeenCalledWith(expect.stringContaining('mount node'));
  });

  it('shows a fatal message without configuration', async () => {
    vi.resetModules();
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
    window.swbackupitConfig = undefined;
    const el = document.createElement('div');
    el.id = 'sw-backupit-app';
    document.body.appendChild(el);
    await import('../src/main');
    expect(el.querySelector('[role="alert"]')?.textContent).toContain('configuration missing');
  });

  it('mounts the app into the admin page', async () => {
    vi.resetModules();
    const el = document.createElement('div');
    el.id = 'sw-backupit-app';
    document.body.appendChild(el);
    await import('../src/main');
    await settle();
    expect(el.classList.contains('swbi-root')).toBe(true);
    expect(document.body.classList.contains('swbi-admin-page')).toBe(true);
    expect(el.querySelector('nav')).not.toBeNull();
  });
});

describe('quasarLang', () => {
  it('maps every UI language to a Quasar pack', () => {
    expect(quasarLang('de').isoName).toBe('de');
    expect(quasarLang('en').isoName).toBe('en-US');
    expect(quasarLang('pl').isoName).toBe('pl');
  });
});

describe('useNotify', () => {
  function notifier() {
    let api!: ReturnType<typeof useNotify>;
    mount(
      defineComponent({
        setup() {
          api = useNotify();
          return () => h('div');
        },
      }),
      { global: { plugins: [[Quasar, { plugins: { Notify }, iconSet }], createAppI18n('en_US')] as never[] } },
    );
    return api;
  }

  it('maps API errors to the matching notification type', () => {
    const n = notifier();
    // Quasar assigns Notify.create on install, so spy only after mounting.
    const spy = vi.spyOn(Notify, 'create').mockImplementation(() => () => undefined);
    n.success('Saved');
    n.info('FYI');
    n.error(new ApiError('swbi_aborted', '', 0));
    n.error(new ApiError('swbi_not_here', 'Use the wizard', 501));
    n.error(new ApiError('swbi_rate', 'Slow down', 429));
    n.error(new ApiError('swbi_invalid_param', 'Invalid', 400, { name: 'Required' }));
    n.error(new Error('plain'), 'Fallback');
    const types = spy.mock.calls.map((c) => (c[0] as { type: string }).type);
    expect(types).toEqual(['positive', 'info', 'info', 'warning', 'negative', 'negative']);
    expect((spy.mock.calls[4]![0] as { caption: string }).caption).toBe('name: Required');
  });
});
