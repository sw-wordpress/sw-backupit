import { mount } from '@vue/test-utils';
import { Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import { beforeAll, describe, expect, it, vi } from 'vitest';
import { createApi } from '../src/api';
import { ApiClient } from '../src/api/client';
import RestoreTestPanel from '../src/features/backups/RestoreTestPanel.vue';
import { createAppI18n } from '../src/i18n';
import type { Backup } from '../src/types/api';

function backup(over: Partial<Backup> = {}): Backup {
  return {
    id: '4618c5e4-8eb4-4c9e-ac33-d550f46364aa',
    profile_id: 1,
    profile_name: 'Nightly',
    type: 'full',
    status: 'complete',
    created_at: '2026-10-24T01:00:00Z',
    size_bytes: 1,
    file_count: 1,
    volume_count: 1,
    encrypted: false,
    protected: false,
    parent_id: null,
    wp_version: '6.8',
    site_url: 'https://x',
    verified_at: null,
    restore_tested_at: null,
    restore_test: null,
    locations: [],
    ...over,
  };
}

beforeAll(() => {
  window.swbackupitConfig = {
    restRoot: 'https://x.test/wp-json/swbackupit/v1/',
    nonce: 'n',
    locale: 'en_US',
    version: '1',
    adminUrl: '',
    capabilities: ['swbi_view', 'swbi_restore'],
    siteUrl: 'https://x',
  };
});

const plugins = (): never[] => [[Quasar, { iconSet }], createAppI18n('en_US')] as never[];

describe('RestoreTestPanel', () => {
  it('hints that an untested backup is not verified', () => {
    const w = mount(RestoreTestPanel, {
      props: { backup: backup() },
      global: { plugins: plugins() },
    });
    expect(w.text()).toContain('Never tested');
    expect(w.text()).toContain('Test restore');
  });

  it('renders the stored result as a checklist and explains partial', () => {
    const w = mount(RestoreTestPanel, {
      props: {
        backup: backup({
          restore_tested_at: '2026-10-24T02:00:00Z',
          restore_test: {
            status: 'partial',
            seconds: 94,
            tested_at: '2026-10-24T02:00:00Z',
            checks: [
              { name: 'Archive integrity', status: 'passed', detail: 'ok' },
              { name: 'Database import', status: 'skipped', detail: 'no privilege' },
            ],
          },
        }),
      },
      global: { plugins: plugins() },
    });
    expect(w.text()).toContain('Partially passed');
    expect(w.text()).toContain('CREATE DATABASE');
    expect(w.text()).toContain('Archive integrity');
    expect(w.text()).toContain('Skipped');
    expect(w.text()).toContain('1:34');
  });
});

describe('Phase-4 API calls', () => {
  function setup() {
    const fetchMock = vi.fn(async () => new Response('{}', { status: 200 }));
    const api = createApi(
      new ApiClient({
        restRoot: 'https://x.test/wp-json/swbackupit/v1/',
        nonce: 'n',
        fetchImpl: fetchMock as unknown as typeof fetch,
      }),
    );
    return { api, fetchMock };
  }
  const call = (m: ReturnType<typeof vi.fn>): [string, RequestInit] =>
    m.mock.calls[0] as unknown as [string, RequestInit];

  it('queries the audit log with filters', async () => {
    const { api, fetchMock } = setup();
    await api.audit.list({ page: 2, action: 'backup_deleted', object_type: 'backup', user_id: 3 });
    expect(call(fetchMock)[0]).toBe(
      'https://x.test/wp-json/swbackupit/v1/audit?page=2&action=backup_deleted&object_type=backup&user_id=3',
    );
  });

  it('posts a restore test', async () => {
    const { api, fetchMock } = setup();
    await api.backups.restoreTest('abc');
    const [url, init] = call(fetchMock);
    expect(url).toBe('https://x.test/wp-json/swbackupit/v1/backups/abc/restore-test');
    expect(init.method).toBe('POST');
  });

  it('sends only the given settings groups', async () => {
    const { api, fetchMock } = setup();
    await api.settings.update({ restore_test: { enabled: true, interval_days: 14 } });
    expect(call(fetchMock)[1].body).toBe('{"restore_test":{"enabled":true,"interval_days":14}}');
  });

  it('builds part downloads', () => {
    const { api } = setup();
    expect(api.backups.downloadUrl('abc', 'manifest')).toBe(
      'https://x.test/wp-json/swbackupit/v1/backups/abc/download?part=manifest&_wpnonce=n',
    );
    expect(api.backups.downloadUrl('abc', 'database', 2)).toContain('part=database&volume=2');
  });
});
