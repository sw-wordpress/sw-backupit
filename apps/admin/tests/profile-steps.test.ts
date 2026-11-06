import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { handle } from '../dev/mockApi';
import { defaultProfileInput } from '../src/features/profiles/defaults';
import StepCompression from '../src/features/profiles/steps/StepCompression.vue';
import StepContent from '../src/features/profiles/steps/StepContent.vue';
import StepRetention from '../src/features/profiles/steps/StepRetention.vue';
import StepSummary from '../src/features/profiles/steps/StepSummary.vue';
import { STEP_IDS, validateProfile } from '../src/features/profiles/wizard';
import type { BackupType, ProfileInput, StorageTarget } from '../src/types/api';
import { mountComponent, settle, setup } from './support/component';

beforeEach(() => {
  setup();
});
afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});

const targets = (): StorageTarget[] =>
  (
    handle({ method: 'GET', path: 'storage-targets', query: new URLSearchParams(), body: {} })
      .body as { items: StorageTarget[] }
  ).items;

function form(over: Partial<ProfileInput> = {}): ProfileInput {
  return { ...defaultProfileInput(), name: 'Nightly', ...over };
}
const text = (): string => document.body.textContent ?? '';

describe('StepSummary', () => {
  it.each<[BackupType, string[], string[]]>([
    ['full', ['Paths to include', 'Tables to include'], []],
    ['database', ['Tables to include'], ['Paths to include']],
    ['files', ['Paths to include'], ['Tables to include']],
  ])('shows the content rows that apply to a %s backup', async (type, present, absent) => {
    const f = form({ type });
    const w = mountComponent(StepSummary, {
      form: f,
      targets: targets(),
      issues: validateProfile(f),
    });
    await settle();
    for (const label of present) expect(w.text().toLowerCase()).toContain(label.toLowerCase());
    for (const label of absent) expect(w.text().toLowerCase()).not.toContain(label.toLowerCase());
    w.unmount();
  });

  it('lists problems with a link back to the step and emits go', async () => {
    const f = form({ name: '' });
    const issues = validateProfile(f);
    const w = mountComponent(StepSummary, { form: f, targets: targets(), issues });
    await settle();
    const alert = w.find('[role="alert"]');
    expect(alert.exists()).toBe(true);
    await alert.find('button').trigger('click');
    expect(w.emitted('go')![0]).toEqual([STEP_IDS.indexOf('general')]);
    // Section edit buttons jump to their step as well.
    const edits = w.findAll('button').filter((b) => b.element.closest('[role="alert"]') === null);
    await edits.at(-1)!.trigger('click');
    expect(w.emitted('go')!.length).toBe(2);
    w.unmount();
  });

  it('describes compression, encryption, retention, notifications and targets', async () => {
    const all = targets();
    const f = form({
      volume_size_mb: 128,
      compression: { method: 'store', level: 6 },
      encryption: { enabled: true, key_id: 'primary-2026' },
      storage_target_ids: [all[0]!.id],
      retention: {
        ...defaultProfileInput().retention,
        max_count: 0,
        max_age_days: 30,
        max_storage_mb: 2048,
        delete_local_after_upload: true,
        gfs: { daily: 7, weekly: 4, monthly: 12, yearly: 2 },
      },
      notifications: {
        ...defaultProfileInput().notifications,
        on_success: true,
        on_failure: true,
        on_warning: true,
        recipients: ['ops@example.com'],
      },
    });
    const w = mountComponent(StepSummary, { form: f, targets: all, issues: validateProfile(f) });
    await settle();
    const t = w.text();
    expect(t).toContain('128 MB');
    expect(t).toContain('primary-2026');
    expect(t).toContain('30 d');
    expect(t).toContain('2048 MB');
    expect(t).toContain('7 / 4 / 12 / 2');
    expect(t).toContain('ops@example.com');
    expect(t).toContain(all[0]!.name);
    w.unmount();
  });

  it('describes deflate compression without encryption', async () => {
    const f = form({
      compression: { method: 'deflate', level: 9 },
      encryption: { enabled: false, key_id: '' },
    });
    const w = mountComponent(StepSummary, { form: f, targets: [], issues: validateProfile(f) });
    await settle();
    expect(w.text()).toMatch(/9/);
    w.unmount();
  });
});

describe('StepRetention', () => {
  it('renders the retention fields and shows validation only when asked', async () => {
    const f = form();
    const issues = [{ field: 'retention.max_count', code: 'min' as const, n: 0 }];
    const w = mountComponent(StepRetention, {
      modelValue: f,
      issues,
      showIssues: false,
      'onUpdate:modelValue': (v: ProfileInput) => w.setProps({ modelValue: v }),
    });
    await settle();
    expect(w.findAll('input').length).toBeGreaterThanOrEqual(7);
    await w.setProps({ showIssues: true });
    await settle();
    expect(w.findAll('input').length).toBeGreaterThan(0);
    const vm = w.vm as unknown as { form: ProfileInput; msg: (f: string) => string | undefined };
    expect(vm.msg('retention.max_count')).toBeTruthy();
    expect(vm.msg('retention.unknown')).toBeUndefined();
    vm.form = { ...vm.form, retention: { ...vm.form.retention, max_count: 3 } };
    await settle();
    expect(w.emitted('update:modelValue')?.at(-1)?.[0]).toMatchObject({
      retention: { max_count: 3 },
    });
    await w.setProps({ showIssues: false });
    expect(vm.msg('retention.max_count')).toBeUndefined();
    w.unmount();
  });
});

describe('StepCompression', () => {
  it('offers the available keys plus the currently selected one', async () => {
    const f = form({ encryption: { enabled: true, key_id: 'legacy-key' } });
    const w = mountComponent(StepCompression, {
      modelValue: f,
      keyIds: ['primary-2026'],
      keysLoading: false,
      issues: [{ field: 'volume_size_mb', code: 'min', n: 1 }],
      showIssues: true,
    });
    await settle();
    const vm = w.vm as unknown as { keyOptions: Array<{ value: string }> };
    expect(vm.keyOptions.map((o) => o.value).sort()).toEqual(['legacy-key', 'primary-2026']);
    expect(w.findAll('input').length).toBeGreaterThan(0);
    w.unmount();
  });

  it('renders without encryption and while keys load', async () => {
    const w = mountComponent(StepCompression, {
      modelValue: form({ encryption: { enabled: false, key_id: '' } }),
      keyIds: [],
      keysLoading: true,
      issues: [],
      showIssues: false,
    });
    await settle();
    const vm = w.vm as unknown as { keyOptions: unknown[] };
    expect(vm.keyOptions).toEqual([]);
    expect(text()).toBeTruthy();
    w.unmount();
  });
});

describe('StepContent', () => {
  it.each<BackupType>(['full', 'files', 'database'])(
    'renders the content form for %s',
    async (type) => {
      const w = mountComponent(StepContent, { modelValue: form({ type }) });
      await settle();
      expect(w.findAll('input, textarea').length).toBeGreaterThan(0);
      w.unmount();
    },
  );
});
