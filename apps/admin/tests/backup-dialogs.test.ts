import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import BackupSheet from '../src/features/backups/BackupSheet.vue';
import BackupFileBrowser from '../src/features/backups/BackupFileBrowser.vue';
import CompareDialog from '../src/features/backups/CompareDialog.vue';
import RestoreDialog from '../src/features/backups/RestoreDialog.vue';
import TransferDialog from '../src/features/backups/TransferDialog.vue';
import {
  buttonByText,
  listBackups,
  mountComponent,
  requests,
  settle,
  setup,
} from './support/component';

let fetchMock: ReturnType<typeof setup>;
beforeEach(() => {
  fetchMock = setup();
});
afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});

describe('BackupSheet comparison permission', () => {
  it('hides comparison from viewers and offers it to download-capable users', async () => {
    for (const allowed of [false, true]) {
      setup(allowed ? ['swbi_view', 'swbi_download'] : ['swbi_view']);
      const w = mountComponent(BackupSheet, { modelValue: true, backupId: listBackups()[0]!.id });
      await settle(8);
      expect(Boolean(buttonByText(/Compare/))).toBe(allowed);
      w.unmount();
    }
  });
});

describe('CompareDialog', () => {
  it('runs a metadata comparison and renders the summary', async () => {
    const backup = listBackups()[0]!;
    const w = mountComponent(CompareDialog, { modelValue: true, backupId: backup.id });
    await settle();
    buttonByText(/Start comparison/)!.click();
    await settle(6);
    expect(requests(fetchMock)).toContain(
      `POST /wp-json/sw-backupit/v1/backups/${backup.id}/compare`,
    );
    const body = JSON.parse(String(fetchMock.mock.calls.at(-1)![1]?.body ?? '{}')) as {
      mode?: string;
    };
    expect(body.mode).toBe('metadata');
    expect((w.vm as unknown as { result: unknown }).result).not.toBeNull();
    w.unmount();
  });

  it('sends the selected thorough mode and reports API errors', async () => {
    const backup = listBackups()[0]!;
    const w = mountComponent(CompareDialog, { modelValue: true, backupId: backup.id });
    await settle();
    (w.vm as unknown as { mode: string }).mode = 'sha256';
    await settle();
    buttonByText(/Start comparison/)!.click();
    await settle(6);
    const sent = fetchMock.mock.calls
      .filter((c) => (c[1] as RequestInit | undefined)?.method === 'POST')
      .map((c) => JSON.parse(String((c[1] as RequestInit).body)) as { mode: string });
    expect(sent.at(-1)!.mode).toBe('sha256');

    fetchMock.mockImplementation(
      async () =>
        new Response(
          JSON.stringify({ code: 'boom', message: 'No archive', data: { status: 500 } }),
          {
            status: 500,
            headers: { 'Content-Type': 'application/json' },
          },
        ),
    );
    buttonByText(/Start comparison/)!.click();
    await settle(6);
    expect((w.vm as unknown as { result: unknown }).result).toBeNull();
    expect((w.vm as unknown as { running: boolean }).running).toBe(false);
    w.unmount();
  });
});

describe('RestoreDialog', () => {
  it('walks intro, prepare, confirmation and exposes only http(s) links', async () => {
    const backup = listBackups()[0]!;
    const w = mountComponent(RestoreDialog, { modelValue: true, backup });
    await settle();
    const vm = w.vm as unknown as {
      step: string;
      prepared: { token: string; restore_url: string } | null;
      confirmed: boolean;
      pathConfirmed: boolean;
      typedId: string;
      restoreHref: string | null;
      differentSite: boolean;
    };
    expect(vm.step).toBe('intro');
    buttonByText(/Prepare restore/)!.click();
    await settle(6);
    expect(vm.step).toBe('confirm');
    expect(vm.prepared?.token).toBeTruthy();
    expect(vm.confirmed).toBe(false);
    vm.pathConfirmed = true;
    vm.typedId = backup.id.slice(0, 8);
    await settle();
    expect(vm.confirmed).toBe(true);
    vm.prepared = { ...vm.prepared!, restore_url: 'javascript:alert(1)' };
    expect(vm.restoreHref).toBeNull();
    vm.prepared = { ...vm.prepared!, restore_url: 'https://x.test/restore/' };
    expect(vm.restoreHref).toBe('https://x.test/restore/');
    w.unmount();
  });

  it('copies the token and falls back to a notice when the clipboard is blocked', async () => {
    const backup = listBackups()[0]!;
    const w = mountComponent(RestoreDialog, { modelValue: true, backup });
    await settle();
    buttonByText(/Prepare restore/)!.click();
    await settle(6);
    const vm = w.vm as unknown as { copied: boolean; copyToken: () => Promise<void> };
    const write = vi.fn().mockResolvedValue(undefined);
    vi.stubGlobal('navigator', { ...navigator, clipboard: { writeText: write } });
    await vm.copyToken();
    expect(write).toHaveBeenCalled();
    expect(vm.copied).toBe(true);
    vi.stubGlobal('navigator', {
      ...navigator,
      clipboard: { writeText: vi.fn().mockRejectedValue(new Error('denied')) },
    });
    vm.copied = false;
    await vm.copyToken();
    expect(vm.copied).toBe(false);
    w.unmount();
  });

  it('forgets the token when the dialog closes', async () => {
    const backup = listBackups()[0]!;
    const w = mountComponent(RestoreDialog, { modelValue: true, backup });
    await settle();
    buttonByText(/Prepare restore/)!.click();
    await settle(6);
    await w.setProps({ modelValue: false });
    await settle();
    expect((w.vm as unknown as { prepared: unknown }).prepared).toBeNull();
    w.unmount();
  });

  it('warns when the backup comes from a different site', async () => {
    const backup = { ...listBackups()[0]!, site_url: 'https://other.example' };
    const w = mountComponent(RestoreDialog, { modelValue: true, backup });
    await settle();
    expect((w.vm as unknown as { differentSite: boolean }).differentSite).toBe(true);
    w.unmount();
  });
});

describe('TransferDialog', () => {
  it('queues a copy to the chosen target and a move on request', async () => {
    const backup = listBackups()[0]!;
    const w = mountComponent(TransferDialog, { modelValue: true, backup });
    await settle(8);
    const vm = w.vm as unknown as {
      targetId: number | null;
      move: boolean;
      start: () => Promise<void>;
    };
    await vm.start();
    expect(requests(fetchMock).some((r) => /POST .*\/transfer/.test(r))).toBe(false);
    vm.targetId = 2;
    await vm.start();
    vm.move = true;
    await vm.start();
    const posts = fetchMock.mock.calls
      .filter((c) => /\/transfer$/.test(String(c[0])) && (c[1] as RequestInit).method === 'POST')
      .map(
        (c) =>
          JSON.parse(String((c[1] as RequestInit).body)) as { target_id: number; move: boolean },
      );
    expect(posts.map((p) => p.move)).toEqual([false, true]);
    expect(posts[0]!.target_id).toBe(2);
    expect(w.emitted('changed')).toBeTruthy();
    w.unmount();
  });

  it('cancels a running transfer and reports API errors', async () => {
    const backup = listBackups()[0]!;
    const w = mountComponent(TransferDialog, { modelValue: true, backup });
    await settle(8);
    const vm = w.vm as unknown as {
      current: { id: string } | null;
      cancel: () => Promise<void>;
      cancelling: boolean;
    };
    await vm.cancel();
    expect(vm.cancelling).toBe(false);
    w.unmount();
  });
});

describe('BackupFileBrowser', () => {
  it('lists the root, descends into a folder and searches', async () => {
    const backup = listBackups()[0]!;
    const w = mountComponent(BackupFileBrowser, { backupId: backup.id });
    await settle(8);
    const vm = w.vm as unknown as {
      entries: Array<{ path: string; type: string }>;
      path: string;
      search: string;
      crumbs: Array<{ name: string }>;
      searching: boolean;
      open: (e: { path: string; type: string }) => void;
      go: (p: string) => void;
      nameOf: (e: { path: string }) => string;
      loadPage: (reset: boolean) => Promise<void>;
      onVirtualScroll: (d: { index: number; to: number }) => void;
      hasMore: boolean;
    };
    expect(vm.entries.length).toBeGreaterThan(0);
    const dir = vm.entries.find((e) => e.type === 'dir');
    expect(dir).toBeDefined();
    vm.open(dir!);
    await settle(8);
    expect(vm.path).toBe(dir!.path);
    expect(vm.crumbs.length).toBeGreaterThan(0);
    vm.open({ path: 'a/file.txt', type: 'file' });
    expect(vm.path).toBe(dir!.path);
    vm.go('');
    await settle(8);
    vm.search = 'wp';
    expect(vm.searching).toBe(true);
    await new Promise((r) => setTimeout(r, 400));
    await settle(8);
    expect(vm.nameOf({ path: 'x/y/z.php' })).toBe('x/y/z.php');
    vm.search = '';
    await new Promise((r) => setTimeout(r, 400));
    await settle(8);
    expect(vm.nameOf({ path: 'x/y/z.php' })).toBe('z.php');
    vm.onVirtualScroll({ index: 0, to: 0 });
    await settle(4);
    await w.setProps({ backupId: listBackups()[1]!.id });
    await settle(8);
    expect(vm.path).toBe('');
    w.unmount();
  });

  it('shows an error state when the listing fails', async () => {
    const backup = listBackups()[0]!;
    fetchMock.mockImplementation(
      async () =>
        new Response(
          JSON.stringify({ code: 'boom', message: 'Index unreadable', data: { status: 500 } }),
          {
            status: 500,
            headers: { 'Content-Type': 'application/json' },
          },
        ),
    );
    const w = mountComponent(BackupFileBrowser, { backupId: backup.id });
    await settle(8);
    expect(document.body.textContent).toContain('Index unreadable');
    w.unmount();
  });
});
