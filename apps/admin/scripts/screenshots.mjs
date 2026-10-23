// Takes screenshots of the main views against the dev server (mock API).
// Usage: node scripts/screenshots.mjs [baseUrl] [only-name-filter]
import { existsSync, mkdirSync, readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { chromium } from 'playwright-core';

const base = process.argv[2] ?? 'http://127.0.0.1:5173';
const filter = process.argv[3] ?? '';
mkdirSync('screenshots', { recursive: true });

const VIEWS = [
    { name: 'dashboard', hash: '/' },
    { name: 'profiles', hash: '/profiles' },
    {
        name: 'profile-edit',
        hash: '/profiles',
        action: async (p) => {
            await p
                .getByRole('button', { name: /Nightly Full bearbeiten|Edit Nightly Full/ })
                .click();
            await p.waitForTimeout(500);
        },
    },
    { name: 'schedules', hash: '/schedules' },
    {
        name: 'schedule-edit',
        hash: '/schedules',
        action: async (p) => {
            await p.getByRole('button', { name: /Hourly DB bearbeiten|Edit Hourly DB/ }).click();
            await p.waitForTimeout(500);
        },
    },
    { name: 'backups', hash: '/backups' },
    {
        name: 'backup-detail',
        hash: '/backups',
        action: async (p) => {
            await p.locator('tbody tr').first().click();
            await p.waitForTimeout(700);
        },
    },
    {
        name: 'backup-files',
        hash: '/backups',
        action: async (p) => {
            await p.locator('tbody tr').first().click();
            await p.waitForTimeout(500);
            await p.getByRole('tab', { name: /Dateien|Files/ }).click();
            await p.waitForTimeout(700);
        },
    },
    {
        name: 'backup-restore',
        hash: '/backups',
        action: async (p) => {
            await p.locator('tbody tr').first().click();
            await p.waitForTimeout(500);
            await p
                .getByRole('button', { name: /Wiederherstellen|Restore/ })
                .first()
                .click();
            await p.waitForTimeout(500);
            await p
                .getByRole('button', { name: /Wiederherstellung vorbereiten|Prepare restore/ })
                .click();
            await p.waitForTimeout(700);
        },
    },
    {
        name: 'backup-delete',
        hash: '/backups',
        action: async (p) => {
            await p.locator('tbody tr').nth(1).click();
            await p.waitForTimeout(500);
            await p
                .getByRole('button', { name: /^Löschen|^Delete/ })
                .first()
                .click();
            await p.waitForTimeout(500);
        },
    },
    { name: 'jobs', hash: '/jobs' },
    {
        name: 'job-detail',
        hash: '/jobs',
        action: async (p) => {
            await p.locator('a[href*="/jobs/"]').first().click();
            await p.waitForTimeout(2500);
        },
    },
    { name: 'storage', hash: '/storage' },
    {
        name: 'storage-edit',
        hash: '/storage',
        action: async (p) => {
            await p
                .getByRole('button', { name: /Offsite SFTP bearbeiten|Edit Offsite SFTP/ })
                .click();
            await p.waitForTimeout(500);
        },
    },
    { name: 'settings', hash: '/settings' },
];

const MODES = [
    { suffix: 'light', viewport: { width: 1440, height: 900 }, scheme: 'light' },
    { suffix: 'dark', viewport: { width: 1440, height: 900 }, scheme: 'dark' },
    { suffix: 'mobile', viewport: { width: 375, height: 800 }, scheme: 'light' },
    { suffix: 'mobile-dark', viewport: { width: 375, height: 800 }, scheme: 'dark' },
];

// playwright-core does not download browsers; reuse any Chromium from the Playwright cache
// (override with CHROME_PATH).
function findChrome() {
    if (process.env.CHROME_PATH) return process.env.CHROME_PATH;
    const cache = join(homedir(), '.cache', 'ms-playwright');
    if (!existsSync(cache)) return undefined;
    const dirs = readdirSync(cache)
        .filter((d) => /^chromium-\d+$/.test(d))
        .sort()
        .reverse();
    for (const d of dirs) {
        const p = join(cache, d, 'chrome-linux', 'chrome');
        if (existsSync(p)) return p;
    }
    return undefined;
}
const browser = await chromium.launch({ executablePath: findChrome(), args: ['--no-sandbox'] });
for (const mode of MODES) {
    const context = await browser.newContext({
        viewport: mode.viewport,
        colorScheme: mode.scheme,
        reducedMotion: 'reduce',
    });
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
    page.on('console', (m) => {
        if (['error', 'warning'].includes(m.type())) errors.push(`${m.type()}: ${m.text()}`);
    });
    for (const view of VIEWS) {
        if (filter && !view.name.includes(filter)) continue;
        await page.goto(`${base}/#${view.hash}`, { waitUntil: 'networkidle' });
        await page.reload({ waitUntil: 'networkidle' });
        await page.waitForTimeout(900);
        if (view.action) {
            try {
                await view.action(page);
            } catch (e) {
                errors.push(`action ${view.name}: ${e.message.split('\n')[0]}`);
            }
        }
        const fullPage = !view.action;
        await page.screenshot({ path: `screenshots/${view.name}-${mode.suffix}.png`, fullPage });
    }
    if (errors.length) console.log(`[${mode.suffix}]\n  ` + [...new Set(errors)].join('\n  '));
    await context.close();
}
await browser.close();
console.log('done');
