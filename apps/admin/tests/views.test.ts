import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { listBackups, requests, setup } from './support/component';
import { click, mountApp, settle } from './support/app';

let fetchMock: ReturnType<typeof setup>;
beforeEach(() => {
  fetchMock = setup();
});
afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
  document.body.innerHTML = '';
});

const sent = (re: RegExp): boolean => requests(fetchMock).some((r) => re.test(r));
async function waitFor(cond: () => boolean, tries = 40): Promise<void> {
  for (let i = 0; i < tries && !cond(); i++) await settle(1);
}
async function open(path: string, ready: RegExp) {
  const mounted = await mountApp(path);
  await waitFor(() => ready.test(document.body.textContent ?? ''));
  return mounted;
}
function input(label: string): HTMLInputElement {
  const el = Array.from(document.querySelectorAll<HTMLInputElement>('[role="dialog"] input')).find(
    (i) =>
      i.closest('label')?.textContent?.includes(label) || i.getAttribute('aria-label') === label,
  );
  if (!el) throw new Error(`input ${label} not found`);
  return el;
}
async function type(el: HTMLInputElement, value: string): Promise<void> {
  el.value = value;
  el.dispatchEvent(new Event('input', { bubbles: true }));
  await settle();
}
function confirmInput(): HTMLInputElement {
  return Array.from(document.querySelectorAll<HTMLInputElement>('[role="dialog"] input')).at(-1)!;
}
async function clickTab(name: RegExp): Promise<void> {
  Array.from(document.querySelectorAll<HTMLElement>('[role="tab"]'))
    .find((t) => name.test(t.textContent ?? ''))
    ?.click();
  await settle(6);
}

describe('schedules view', () => {
  it('runs, toggles, edits, creates and deletes schedules', async () => {
    const { wrapper } = await open('/schedules', /Nightly/);
    await click(/Run Nightly now/);
    expect(sent(/POST .*\/schedules\/\d+\/run/)).toBe(true);

    document.querySelector<HTMLElement>('[role="switch"]')?.click();
    await settle();
    expect(sent(/(PUT|PATCH) .*\/schedules\/\d+/)).toBe(true);

    await click(/Edit Nightly/);
    await waitFor(() => /Maximum attempts/.test(document.body.textContent ?? ''));
    await click('Save');
    await waitFor(() => sent(/(PUT|PATCH) .*\/schedules\/\d+/));

    await click(/Create schedule/);
    await waitFor(() => /Maximum attempts/.test(document.body.textContent ?? ''));
    await type(input('Name'), 'Created by test');
    await click('Save');
    await waitFor(() => sent(/POST .*\/schedules$/));
    expect(sent(/POST .*\/schedules$/)).toBe(true);

    await click(/Delete Hourly DB/);
    await click('Delete');
    await waitFor(() => sent(/DELETE .*\/schedules\/\d+/));
    expect(sent(/DELETE .*\/schedules\/\d+/)).toBe(true);
    wrapper.unmount();
  });

  it('is read-only without the manage capability', async () => {
    setup(['swbi_view']);
    const { wrapper } = await open('/schedules', /Nightly/);
    expect(document.body.textContent).not.toContain('Create schedule');
    wrapper.unmount();
  });
});

describe('profiles view', () => {
  it('runs, duplicates and deletes profiles', async () => {
    const { wrapper } = await open('/profiles', /Nightly Full/);
    await click(/Run now/);
    await waitFor(() => sent(/POST .*\/profiles\/\d+\/run/));
    expect(sent(/POST .*\/profiles\/\d+\/run/)).toBe(true);

    await click(/Duplicate Nightly Full/);
    await waitFor(() => sent(/POST .*\/profiles\/\d+\/duplicate/));
    expect(sent(/POST .*\/profiles\/\d+\/duplicate/)).toBe(true);

    await click(/Delete Database hourly/);
    await click('Delete');
    await waitFor(() => sent(/DELETE .*\/profiles\/\d+/));
    expect(sent(/DELETE .*\/profiles\/\d+/)).toBe(true);
    wrapper.unmount();
  });

  it('opens the editor from the list', async () => {
    const { wrapper, router } = await open('/profiles', /Nightly Full/);
    await click(/Edit Nightly Full/);
    await waitFor(() => /\/edit$/.test(router.currentRoute.value.path), 200);
    expect(router.currentRoute.value.path).toMatch(/^\/profiles\/\d+\/edit$/);
    wrapper.unmount();
  });
});

describe('storage view', () => {
  it('tests, discovers, edits, creates and deletes targets', async () => {
    const { wrapper } = await open('/storage', /Offsite SFTP/);
    await click(/Test connection/);
    await waitFor(() => sent(/POST .*\/storage-targets\/\d+\/test/));
    expect(sent(/POST .*\/storage-targets\/\d+\/test/)).toBe(true);

    const finders = Array.from(document.querySelectorAll<HTMLElement>('button')).filter((b) =>
      /Find backups/.test(b.textContent ?? ''),
    );
    // The sheet is mounted already open on the first click, so discovery runs from the second open on.
    finders.at(-1)!.click();
    await settle(6);
    await click('Close');
    finders.at(-1)!.click();
    await waitFor(() => sent(/\/storage-targets\/\d+\/discover/));
    expect(sent(/\/storage-targets\/\d+\/discover/)).toBe(true);
    await click('Close');

    await click(/Edit Local staging/);
    await waitFor(() => /Directory/.test(document.body.textContent ?? ''));
    await click('Save');
    await waitFor(() => sent(/(PUT|PATCH) .*\/storage-targets\/\d+/));
    expect(sent(/(PUT|PATCH) .*\/storage-targets\/\d+/)).toBe(true);

    await click(/Add storage target/);
    await waitFor(() => /Directory/.test(document.body.textContent ?? ''));
    await type(input('Name'), 'Another local');
    await type(input('Directory'), 'backups-extra');
    await click('Save');
    await waitFor(() => sent(/POST .*\/storage-targets$/));
    expect(sent(/POST .*\/storage-targets$/)).toBe(true);

    await click(/Delete Wasabi bucket/);
    await click('Delete');
    await waitFor(() => sent(/DELETE .*\/storage-targets\/\d+/));
    expect(sent(/DELETE .*\/storage-targets\/\d+/)).toBe(true);
    wrapper.unmount();
  });
});

describe('settings view', () => {
  it('saves changed settings, manages recipients and keys', async () => {
    const { wrapper } = await open('/settings', /Default key ID/);
    const fields = Array.from(document.querySelectorAll<HTMLInputElement>('input[type="number"]'));
    expect(fields.length).toBeGreaterThan(0);
    await type(fields[0]!, String(Number(fields[0]!.value || 0) + 1));
    await click(/Save/);
    await waitFor(() => sent(/(PUT|PATCH|POST) .*\/settings$/));
    expect(sent(/(PUT|PATCH|POST) .*\/settings$/)).toBe(true);

    await click(/Remove admin@example.com/);
    await click(/Delete key offsite/);
    await type(confirmInput(), 'offsite');
    await click('Delete');
    await waitFor(() => sent(/DELETE .*\/encryption-keys\//));
    expect(sent(/DELETE .*\/encryption-keys\//)).toBe(true);
    wrapper.unmount();
  });

  it('resets unsaved changes', async () => {
    const { wrapper } = await open('/settings', /Default key ID/);
    const field = document.querySelector<HTMLInputElement>('input[type="number"]')!;
    const original = field.value;
    await type(field, '999');
    await click('Reset');
    await settle(4);
    expect(document.querySelector<HTMLInputElement>('input[type="number"]')!.value).toBe(original);
    wrapper.unmount();
  });
});

describe('backup sheet', () => {
  it('verifies, protects, browses files and deletes a backup', async () => {
    const item = listBackups()[0]!;
    const { wrapper } = await open(`/backups/${item.id}`, /Test restore/);
    await click(/Verify/);
    expect(sent(new RegExp(`POST .*/backups/${item.id}/verify`))).toBe(true);

    document.querySelector<HTMLElement>('[role="switch"]')?.click();
    await waitFor(() => sent(new RegExp(`POST .*/backups/${item.id}/protect`)));
    expect(sent(new RegExp(`POST .*/backups/${item.id}/protect`))).toBe(true);

    await clickTab(/Files/);
    await waitFor(() => sent(new RegExp(`GET .*/backups/${item.id}/files`)));
    expect(sent(new RegExp(`GET .*/backups/${item.id}/files`))).toBe(true);
    await clickTab(/Overview/);

    wrapper.unmount();
  });

  it('deletes a backup only after the short id is typed', async () => {
    // Another backup than the one protected above: protected backups cannot be deleted.
    const item = listBackups()[3]!;
    const { wrapper } = await open(`/backups/${item.id}`, /Test restore/);
    await click(/^deleteDelete$/);
    await settle(4);
    expect(sent(/DELETE .*\/backups\//)).toBe(false);
    await type(confirmInput(), item.id.slice(0, 8));
    await click(/Delete permanently/);
    await waitFor(() => sent(new RegExp(`DELETE .*/backups/${item.id}`)));
    expect(sent(new RegExp(`DELETE .*/backups/${item.id}`))).toBe(true);
    wrapper.unmount();
  });

  it('opens compare, transfer and restore from the toolbar', async () => {
    const item = listBackups()[0]!;
    const { wrapper } = await open(`/backups/${item.id}`, /Test restore/);
    await click(/Compare/);
    expect(document.body.textContent).toContain('Start comparison');
    await click(/Copy \/ move/);
    await waitFor(() => /Start copy/.test(document.body.textContent ?? ''));
    expect(document.body.textContent).toContain('Start copy');
    await click(/Restore/);
    await waitFor(() => /Prepare restore/.test(document.body.textContent ?? ''));
    expect(document.body.textContent).toContain('Prepare restore');
    wrapper.unmount();
  });
});

describe('audit view', () => {
  it('expands event details and pages', async () => {
    const { wrapper } = await open('/audit', /Details/);
    await click(/Details/);
    await click('Next page');
    await click('Previous page');
    expect(sent(/GET .*\/audit/)).toBe(true);
    wrapper.unmount();
  });
});
