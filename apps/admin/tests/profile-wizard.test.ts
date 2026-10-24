import { flushPromises, mount } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { Notify, Quasar } from 'quasar';
import iconSet from 'quasar/icon-set/material-icons-round';
import { afterEach, beforeAll, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import { createAppI18n } from '../src/i18n';
import ProfileEditorView from '../src/features/profiles/ProfileEditorView.vue';
import ProfileStepper from '../src/features/profiles/ProfileStepper.vue';
import type { StepView } from '../src/features/profiles/ProfileStepper.vue';
import { defaultProfileInput } from '../src/features/profiles/defaults';
import {
  applyPreset,
  cliCommand,
  detectPreset,
  firstStepWithIssues,
  jsonLines,
  LIMIT_PRESETS,
  previewPayload,
  shellQuote,
  validateProfile,
} from '../src/features/profiles/wizard';

describe('limit presets', () => {
  it('map onto the real profile.limits fields', () => {
    expect(LIMIT_PRESETS.low).toEqual({ time_budget_seconds: 10, batch_rows: 250 });
    expect(LIMIT_PRESETS.normal).toEqual({ time_budget_seconds: 20, batch_rows: 1000 });
    expect(LIMIT_PRESETS.high).toEqual({ time_budget_seconds: 40, batch_rows: 2000 });
  });
  it('applies a preset without touching the memory limit and detects it again', () => {
    const base = { time_budget_seconds: 20, memory_mb: 192, batch_rows: 1000 };
    const low = applyPreset(base, 'low');
    expect(low).toEqual({ time_budget_seconds: 10, memory_mb: 192, batch_rows: 250 });
    expect(detectPreset(low)).toBe('low');
    expect(detectPreset(base)).toBe('normal');
    expect(detectPreset({ ...base, batch_rows: 999 })).toBe('custom');
  });
});

describe('CLI command', () => {
  it('quotes names for the shell and never invents a slug', () => {
    expect(shellQuote('Full')).toBe('Full');
    expect(shellQuote('Komplette Website')).toBe("'Komplette Website'");
    expect(shellQuote("it's")).toBe("'it'\\''s'");
    expect(cliCommand('Komplette Website', null)).toBe(
      "wp sw-backupit backup create --profile='Komplette Website'",
    );
    expect(cliCommand('', null)).toBe("wp sw-backupit backup create --profile='<name>'");
    expect(cliCommand('x', 7)).toBe('wp sw-backupit backup create --profile=7');
  });
});

describe('validateProfile', () => {
  it('groups issues by step', () => {
    const form = defaultProfileInput();
    expect(firstStepWithIssues(validateProfile(form))).toBe('general');
    form.name = 'Nightly';
    expect(firstStepWithIssues(validateProfile(form))).toBeNull();
    form.encryption = { enabled: true, key_id: '' };
    form.notifications.recipients = ['nope'];
    form.retention.max_count = -1;
    const v = validateProfile(form);
    expect(v.compression.map((i) => i.field)).toEqual(['encryption.key_id']);
    expect(v.retention.map((i) => i.code).sort()).toEqual(['email', 'min']);
    form.name = 'x'.repeat(61);
    expect(validateProfile(form).general[0]?.code).toBe('tooLong');
  });
});

describe('live preview', () => {
  it('only contains real fields and hides irrelevant scopes', () => {
    const form = defaultProfileInput();
    form.type = 'database';
    const payload = previewPayload(form);
    expect(payload).not.toHaveProperty('include_paths');
    expect(payload).toHaveProperty('limits');
    form.type = 'full';
    expect(previewPayload(form)).toHaveProperty('include_paths');
  });
  it('tokenises JSON into plain text tokens', () => {
    const lines = jsonLines({ name: 'a', ok: true, n: 3 });
    const flat = lines.flat();
    expect(flat.find((t) => t.text === '"name"')?.kind).toBe('key');
    expect(flat.find((t) => t.text === '"a"')?.kind).toBe('string');
    expect(flat.find((t) => t.text === 'true')?.kind).toBe('literal');
    expect(flat.find((t) => t.text === '3')?.kind).toBe('number');
    expect(lines.map((l) => l.map((t) => t.text).join('')).join('\n')).toBe(
      JSON.stringify({ name: 'a', ok: true, n: 3 }, null, 2),
    );
  });
});

const plugins = (extra: unknown[] = []): never[] =>
  [[Quasar, { iconSet, plugins: { Notify } }], createAppI18n('en_US'), ...extra] as never[];

describe('ProfileStepper', () => {
  const steps: StepView[] = [
    { id: 'general', state: 'done', reachable: true, hasIssues: false },
    { id: 'content', state: 'current', reachable: true, hasIssues: false },
    { id: 'compression', state: 'todo', reachable: false, hasIssues: false },
    { id: 'storage', state: 'todo', reachable: false, hasIssues: false },
    { id: 'retention', state: 'todo', reachable: false, hasIssues: true },
    { id: 'summary', state: 'todo', reachable: false, hasIssues: false },
  ];
  it('is a real nav list with aria-current and disabled unreachable steps', async () => {
    const w = mount(ProfileStepper, {
      props: { steps, current: 1 },
      global: { plugins: plugins() },
    });
    expect(w.find('nav ol').exists()).toBe(true);
    const buttons = w.findAll('li button');
    expect(buttons).toHaveLength(6);
    expect(buttons[1]?.attributes('aria-current')).toBe('step');
    expect(buttons[0]?.attributes('aria-current')).toBeUndefined();
    expect(buttons[2]?.attributes('disabled')).toBeDefined();
    expect(w.text()).toContain('Step 2 / 6');
    expect(w.text()).toContain('needs attention');
    await buttons[0]?.trigger('click');
    expect(w.emitted('go')?.[0]).toEqual([0]);
  });
});

describe('ProfileEditorView', () => {
  beforeAll(() => {
    window.swbackupitConfig = {
      restRoot: 'https://x.test/wp-json/sw-backupit/v1/',
      nonce: 'n',
      locale: 'en_US',
      version: '1',
      adminUrl: '',
      capabilities: ['swbi_view', 'swbi_manage_profiles'],
      siteUrl: 'https://x',
    };
  });
  afterEach(() => vi.unstubAllGlobals());

  it('blocks Next on an empty name, then advances and updates the stepper', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async (url: string) => {
        const body = url.includes('encryption-keys')
          ? { items: [] }
          : { items: [], total: 0, pages: 1 };
        return new Response(JSON.stringify(body), { status: 200 });
      }),
    );
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/profiles/new', name: 'profile-new', component: ProfileEditorView },
        { path: '/profiles', name: 'profiles', component: { template: '<div />' } },
      ],
    });
    await router.push('/profiles/new');
    const w = mount(ProfileEditorView, { global: { plugins: plugins([createPinia(), router]) } });
    await flushPromises();
    const current = (): string => w.find('[aria-current="step"]').text();
    expect(current()).toContain('General');
    const next = w.findAll('button').find((b) => b.text().startsWith('Next:'));
    expect(next).toBeDefined();
    await next?.trigger('click');
    await flushPromises();
    expect(current()).toContain('General');
    expect(w.text()).toContain('Required');

    await w.find('input[type="text"]').setValue('Nightly');
    await w
      .findAll('button')
      .find((b) => b.text().startsWith('Next:'))
      ?.trigger('click');
    await flushPromises();
    expect(current()).toContain('Content');
    // forward jumps beyond the furthest validated step stay disabled
    const stepButtons = w.findAll('nav li button');
    expect(stepButtons[3]?.attributes('disabled')).toBeDefined();
    expect(stepButtons[0]?.attributes('disabled')).toBeUndefined();
  });
});
