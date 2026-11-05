import { flushPromises, mount } from '@vue/test-utils';
import { Notify, Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import RestoreDialog from '../src/features/backups/RestoreDialog.vue';
import { defaultProfileInput } from '../src/features/profiles/defaults';
import StepStorage from '../src/features/profiles/steps/StepStorage.vue';
import DiscoverSheet from '../src/features/storage/DiscoverSheet.vue';
import { createAppI18n } from '../src/i18n';
import type { Backup, StorageTarget } from '../src/types/api';

const target: StorageTarget = {
  id: 7,
  name: 'Replica',
  type: 'local',
  config: {},
  credentials_set: false,
  immutable: false,
  retention: null,
  status: 'ok',
  usage_bytes: null,
  last_checked_at: null,
  message: '',
};
const backup: Backup = {
  id: '4618c5e4-8eb4-4c9e-ac33-d550f46364aa',
  profile_id: 1,
  profile_name: 'Nightly',
  type: 'files',
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
};
const global = () => ({
  plugins: [[Quasar, { iconSet, plugins: { Notify } }], createAppI18n('en_US')] as never[],
  stubs: { SideSheet: { template: '<div><slot /></div>' }, RouterLink: true },
});

beforeEach(() => {
  window.swbackupitConfig = {
    restRoot: 'https://x.test/wp-json/sw-backupit/v1/',
    nonce: 'n',
    locale: 'en_US',
    version: '1',
    adminUrl: '',
    capabilities: ['swbi_view', 'swbi_restore'],
    siteUrl: 'https://x',
  };
});
afterEach(() => vi.unstubAllGlobals());

describe('storage selection accessibility', () => {
  it('associates the whole target card with a keyboard-operable checkbox', async () => {
    const wrapper = mount(StepStorage, {
      props: { modelValue: defaultProfileInput(), targets: [target], loading: false },
      global: global(),
    });
    const checkbox = wrapper.find('[role="checkbox"]');
    expect(checkbox.attributes('aria-label')).toBe('Replica');
    expect(checkbox.attributes('tabindex')).toBe('0');
    expect(checkbox.text()).toContain('Replica');
    await checkbox.find('strong').trigger('click');
    expect(wrapper.props('modelValue').storage_target_ids).toEqual([7]);
    await wrapper.setProps({ modelValue: { ...wrapper.props('modelValue') } });
    expect(checkbox.attributes('aria-checked')).toBe('true');
    wrapper.unmount();
  });

  it('allows selecting the discovery card text and refuses disabled backups', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(
        async () =>
          new Response(
            JSON.stringify({
              items: [
                {
                  backup_id: backup.id,
                  created_at: backup.created_at,
                  size_bytes: 1,
                  type: 'files',
                  profile_name: 'Nightly',
                  already_imported: false,
                  error: null,
                },
                {
                  backup_id: 'disabled',
                  created_at: backup.created_at,
                  size_bytes: 1,
                  type: 'files',
                  profile_name: 'Old',
                  already_imported: true,
                  error: null,
                },
              ],
            }),
            { status: 200 },
          ),
      ),
    );
    const wrapper = mount(DiscoverSheet, {
      props: { modelValue: false, target },
      global: global(),
    });
    await wrapper.setProps({ modelValue: true });
    await flushPromises();
    const checkboxes = wrapper.findAll('.swbi-ds__row[role="checkbox"]');
    expect(checkboxes[0]?.attributes('aria-label')).toBe(backup.id);
    expect(checkboxes[0]?.text()).toContain('Nightly');
    await checkboxes[0]?.find('strong').trigger('click');
    expect(checkboxes[0]?.attributes('aria-checked')).toBe('true');
    await checkboxes[1]?.find('strong').trigger('click');
    expect(checkboxes[1]?.attributes('aria-checked')).toBe('false');
    expect(checkboxes[1]?.attributes('aria-disabled')).toBe('true');
    wrapper.unmount();
  });
});

describe('restore site comparison', () => {
  it('ignores long trailing slash runs and still detects different sites', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(
        async () =>
          new Response(
            JSON.stringify({
              restore_url: 'https://x/restore',
              token: 'test',
              token_expires_at: backup.created_at,
              target_path: '/tmp/target',
              warnings: [],
            }),
            { status: 200 },
          ),
      ),
    );
    const wrapper = mount(RestoreDialog, {
      props: {
        modelValue: true,
        backup: { ...backup, site_url: 'https://x' + '/'.repeat(100000) },
      },
      global: global(),
    });
    await wrapper.find('button').trigger('click');
    await flushPromises();
    expect(wrapper.find('.swbi-rs__box--warn').exists()).toBe(false);
    await wrapper.setProps({
      backup: { ...backup, site_url: 'https://other' + '/'.repeat(100000) },
    });
    expect(wrapper.find('.swbi-rs__box--warn').exists()).toBe(true);
    wrapper.unmount();
  });
});
