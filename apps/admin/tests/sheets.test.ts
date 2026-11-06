import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { handle } from '../dev/mockApi';
import KeysCard from '../src/features/settings/KeysCard.vue';
import ScheduleSheet from '../src/features/schedules/ScheduleSheet.vue';
import DiscoverSheet from '../src/features/storage/DiscoverSheet.vue';
import StorageTargetSheet from '../src/features/storage/StorageTargetSheet.vue';
import type { Schedule, StorageTarget } from '../src/types/api';
import { mountComponent, requests, settle, setup } from './support/component';

let fetchMock: ReturnType<typeof setup>;
beforeEach(() => {
  fetchMock = setup();
});
afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});

const get = <T>(path: string): T[] =>
  (handle({ method: 'GET', path, query: new URLSearchParams(), body: {} }).body as { items: T[] })
    .items;
const failing = () =>
  new Response(
    JSON.stringify({
      code: 'swbi_invalid_param',
      message: 'Bad',
      data: { status: 400, params: { name: 'Required' } },
    }),
    {
      status: 400,
      headers: { 'Content-Type': 'application/json' },
    },
  );
const bodies = (re: RegExp, method: string): Array<Record<string, unknown>> =>
  fetchMock.mock.calls
    .filter((c) => re.test(String(c[0])) && (c[1] as RequestInit | undefined)?.method === method)
    .map((c) => JSON.parse(String((c[1] as RequestInit).body)) as Record<string, unknown>);

type StorageVm = {
  name: string;
  type: string;
  cfg: Record<string, unknown>;
  secrets: Record<string, string>;
  lockMode: string;
  lockObjectDays: number;
  ownRetention: boolean;
  immutable: boolean;
  isEdit: boolean;
  credentialsSet: boolean;
  save: () => Promise<void>;
  setSecret: (k: string, v: string) => void;
  setStr: (k: string, v: string | number | null) => void;
  required: (v: unknown) => true | string;
  str: (k: string) => string;
  secret: (k: string) => string;
};

describe('StorageTargetSheet', () => {
  it('creates a new S3 target with write-only credentials and object lock', async () => {
    const w = mountComponent(StorageTargetSheet, { modelValue: false, target: null });
    await w.setProps({ modelValue: true });
    await settle();
    const vm = w.vm as unknown as StorageVm;
    expect(vm.isEdit).toBe(false);
    vm.name = ' Cold storage ';
    vm.type = 's3';
    await settle();
    expect(vm.cfg).toHaveProperty('bucket');
    vm.setStr('bucket', 'b1');
    vm.setStr('endpoint', 'https://s3.example.com');
    vm.setStr('region', null);
    vm.setSecret('access_key', 'AK');
    vm.setSecret('secret_key', '');
    vm.lockMode = 'COMPLIANCE';
    vm.lockObjectDays = 7;
    vm.ownRetention = true;
    await vm.save();
    const [sent] = bodies(/storage-targets$/, 'POST');
    expect(sent!.name).toBe('Cold storage');
    expect(sent!.credentials).toEqual({ access_key: 'AK' });
    expect((sent!.config as Record<string, unknown>).object_lock).toEqual({
      mode: 'COMPLIANCE',
      days: 7,
    });
    expect(sent!.retention).not.toBeNull();
    expect(vm.secrets).toEqual({});
    expect(w.emitted('saved')).toHaveLength(1);
    expect(w.emitted('update:modelValue')?.at(-1)).toEqual([false]);
    w.unmount();
  });

  it('builds defaults for sftp and local and validates required fields', async () => {
    const w = mountComponent(StorageTargetSheet, { modelValue: true, target: null });
    await settle();
    const vm = w.vm as unknown as StorageVm;
    vm.type = 'sftp';
    await settle();
    expect(vm.cfg.port).toBe(22);
    expect(vm.str('host')).toBe('');
    expect(vm.secret('password')).toBe('');
    vm.type = 'local';
    await settle();
    expect(vm.cfg).toHaveProperty('path');
    expect(vm.required('')).not.toBe(true);
    expect(vm.required(undefined)).not.toBe(true);
    expect(vm.required('x')).toBe(true);
    w.unmount();
  });

  it('edits an existing target and keeps credentials write-only', async () => {
    const target = get<StorageTarget>('storage-targets').find((t) => t.type !== 'local')!;
    const w = mountComponent(StorageTargetSheet, { modelValue: false, target });
    await w.setProps({ modelValue: true });
    await settle();
    const vm = w.vm as unknown as StorageVm;
    expect(vm.isEdit).toBe(true);
    expect(vm.name).toBe(target.name);
    expect(vm.secrets).toEqual({});
    vm.name = 'Renamed';
    await vm.save();
    expect(
      bodies(new RegExp(`storage-targets/${target.id}$`), 'PUT').length +
        bodies(new RegExp(`storage-targets/${target.id}$`), 'PATCH').length,
    ).toBeGreaterThan(0);
    await w.setProps({ modelValue: false });
    await settle();
    expect(vm.secrets).toEqual({});
    w.unmount();
  });

  it('keeps the sheet open and surfaces field errors when saving fails', async () => {
    const w = mountComponent(StorageTargetSheet, { modelValue: true, target: null });
    await settle();
    fetchMock.mockImplementation(async () => failing());
    const vm = w.vm as unknown as StorageVm;
    await vm.save();
    expect(w.emitted('saved')).toBeUndefined();
    expect(w.emitted('update:modelValue')).toBeUndefined();
    w.unmount();
  });
});

type ScheduleVm = {
  form: { name: string; frequency: string; cron_expression: string; profile_id: number };
  needsTime: boolean;
  profileOptions: Array<{ value: number }>;
  weekdayOptions: unknown[];
  tzOptions: string[];
  dayRule: (v: number) => true | string;
  cronRule: (v: string) => true | string;
  positive: (v: number) => true | string;
  filterTz: (v: string, u: (fn: () => void) => void) => void;
  save: () => Promise<void>;
};

describe('ScheduleSheet', () => {
  it('creates a cron schedule and trims the expression', async () => {
    const w = mountComponent(ScheduleSheet, {
      modelValue: false,
      schedule: null,
      defaultTimezone: 'UTC',
    });
    await w.setProps({ modelValue: true });
    await settle(6);
    const vm = w.vm as unknown as ScheduleVm;
    expect(vm.profileOptions.length).toBeGreaterThan(0);
    expect(vm.weekdayOptions).toHaveLength(7);
    vm.form.name = 'Cron job';
    vm.form.frequency = 'cron';
    vm.form.cron_expression = '  0 3 * * *  ';
    expect(vm.needsTime).toBe(false);
    await vm.save();
    const [sent] = bodies(/schedules$/, 'POST');
    expect(sent!.cron_expression).toBe('0 3 * * *');
    expect(w.emitted('saved')).toHaveLength(1);
    w.unmount();
  });

  it('clears the cron expression for non-cron frequencies when updating', async () => {
    const schedule = get<Schedule>('schedules')[0]!;
    const w = mountComponent(ScheduleSheet, {
      modelValue: false,
      schedule,
      defaultTimezone: 'UTC',
    });
    await w.setProps({ modelValue: true });
    await settle(6);
    const vm = w.vm as unknown as ScheduleVm;
    vm.form.frequency = 'daily';
    vm.form.cron_expression = 'junk';
    await vm.save();
    const sent = [
      ...bodies(new RegExp(`schedules/${schedule.id}$`), 'PUT'),
      ...bodies(new RegExp(`schedules/${schedule.id}$`), 'PATCH'),
    ];
    expect(sent[0]!.cron_expression).toBe('');
    w.unmount();
  });

  it('validates day, cron and attempts and filters time zones', async () => {
    const w = mountComponent(ScheduleSheet, {
      modelValue: true,
      schedule: null,
      defaultTimezone: 'UTC',
    });
    await settle(6);
    const vm = w.vm as unknown as ScheduleVm;
    expect(vm.dayRule(0)).not.toBe(true);
    expect(vm.dayRule(15)).toBe(true);
    expect(vm.dayRule(32)).not.toBe(true);
    expect(vm.cronRule('* * *')).not.toBe(true);
    expect(vm.cronRule('*/5 * * * *')).toBe(true);
    expect(vm.positive(0)).not.toBe(true);
    expect(vm.positive(2)).toBe(true);
    vm.filterTz('europe/ber', (fn) => fn());
    expect(vm.tzOptions.every((z) => z.toLowerCase().includes('europe/ber'))).toBe(true);
    w.unmount();
  });

  it('reports save errors without closing', async () => {
    const w = mountComponent(ScheduleSheet, {
      modelValue: true,
      schedule: null,
      defaultTimezone: 'UTC',
    });
    await settle(6);
    fetchMock.mockImplementation(async () => failing());
    await (w.vm as unknown as ScheduleVm).save();
    expect(w.emitted('saved')).toBeUndefined();
    w.unmount();
  });
});

type KeysVm = {
  newId: string;
  passphrase: string;
  items: Array<{ id: string }>;
  created: { secret: string } | null;
  revealOpen: boolean;
  copied: boolean;
  deleteOpen: boolean;
  deleteError: string | null;
  toDelete: { id: string } | null;
  idRule: (v: string) => true | string;
  create: () => Promise<void>;
  copySecret: () => Promise<void>;
  closeReveal: () => void;
  askDelete: (k: { id: string }) => void;
  doDelete: () => Promise<void>;
};

describe('KeysCard', () => {
  it('creates a key, reveals the secret once and drops it on close', async () => {
    const w = mountComponent(KeysCard);
    await settle(6);
    const vm = w.vm as unknown as KeysVm;
    const before = vm.items.length;
    expect(vm.idRule('bad id!')).not.toBe(true);
    expect(vm.idRule('key-2026.a')).toBe(true);
    vm.newId = ' fresh-key ';
    vm.passphrase = 'pw';
    await vm.create();
    await settle(4);
    expect(vm.revealOpen).toBe(true);
    expect(vm.created?.secret).toBeTruthy();
    expect(vm.newId).toBe('');
    expect(vm.items.length).toBe(before + 1);
    const write = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText: write } });
    await vm.copySecret();
    expect(write).toHaveBeenCalledWith(vm.created!.secret);
    expect(vm.copied).toBe(true);
    vm.closeReveal();
    expect(vm.created).toBeNull();
    expect(vm.revealOpen).toBe(false);
    await vm.copySecret();
    w.unmount();
  });

  it('falls back when the clipboard is blocked', async () => {
    const w = mountComponent(KeysCard);
    await settle(6);
    const vm = w.vm as unknown as KeysVm;
    vm.newId = 'k-clip';
    await vm.create();
    vi.stubGlobal('navigator', {
      ...navigator,
      clipboard: { writeText: vi.fn().mockRejectedValue(new Error('no')) },
    });
    await vm.copySecret();
    expect(vm.copied).toBe(false);
    w.unmount();
  });

  it('deletes a key and shows an error when deletion fails', async () => {
    const w = mountComponent(KeysCard);
    await settle(6);
    const vm = w.vm as unknown as KeysVm;
    const key = vm.items[0]!;
    await vm.doDelete();
    expect(requests(fetchMock).some((r) => r.startsWith('DELETE'))).toBe(false);
    vm.askDelete(key);
    expect(vm.deleteOpen).toBe(true);
    fetchMock.mockImplementationOnce(async () => failing());
    await vm.doDelete();
    expect(vm.deleteError).toBeTruthy();
    expect(vm.deleteOpen).toBe(true);
    await vm.doDelete();
    expect(vm.deleteOpen).toBe(false);
    expect(
      requests(fetchMock).some(
        (r) => r.includes(`DELETE`) && r.includes(`encryption-keys/${key.id}`),
      ),
    ).toBe(true);
    w.unmount();
  });

  it('reports a failing key creation as a form error', async () => {
    const w = mountComponent(KeysCard);
    await settle(6);
    const vm = w.vm as unknown as KeysVm;
    fetchMock.mockImplementation(async () => failing());
    vm.newId = 'x';
    await vm.create();
    expect(vm.revealOpen).toBe(false);
    w.unmount();
  });
});

describe('DiscoverSheet', () => {
  it('searches a target for backups and imports the selection', async () => {
    const target = get<StorageTarget>('storage-targets').find((t) => t.type !== 'local')!;
    const w = mountComponent(DiscoverSheet, { modelValue: false, target });
    await w.setProps({ modelValue: true });
    await settle(8);
    expect(
      requests(fetchMock).some((r) => new RegExp(`storage-targets/${target.id}/discover`).test(r)),
    ).toBe(true);
    w.unmount();
  });
});
