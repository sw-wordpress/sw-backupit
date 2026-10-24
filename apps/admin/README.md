# SWBackupIt admin frontend

Vue 3 (Composition API, `<script setup lang="ts">`) + Quasar (via `@quasar/vite-plugin`, **no Quasar CLI**) + Vite + TypeScript `strict` + Pinia + vue-router (hash history) + vue-i18n. It runs inside the wp-admin page `admin.php?page=swbackupit`.

REST contract: [`docs/rest-api.md`](../../docs/rest-api.md) including the authoritative section "Implementation notes". The TypeScript types in `src/types/api.ts` mirror it 1:1.

## Dev workflow

Node 18.18+ is enough (versions are pinned accordingly, see below).

```bash
cd apps/admin
npm install
npm run dev          # http://127.0.0.1:5173 with the in-memory mock API (no WordPress needed)
npm run typecheck    # vue-tsc --noEmit
npm run lint         # eslint (flat config, eslint-plugin-vue, typescript-eslint)
npm run format       # prettier --write
npm test             # vitest (api client, formatters, job utils, CSS scoping, components)
npm run build        # type-check + production build -> ../../assets/admin (gitignored)
npm run screenshots  # needs `npm run dev` running and a Chromium in ~/.cache/ms-playwright (or CHROME_PATH)
node scripts/check-css-scope.mjs   # asserts every selector in the built CSS is namespaced
```

Dev URL switches (only the dev host page `index.html`): `?lang=en` (English), `?caps=swbi_view,swbi_run` (capability set).

### Mock API (dev only)

`dev/mockApi.ts` implements the contract in memory (profiles, schedules, jobs incl. a simulated advancing job with phases/logs/ticks, backups with a lazily generated archive tree, compare, restore prepare, storage targets with test/discover/import, settings, 400/403/404/409 errors). `dev/mockApiPlugin.ts` is a Vite dev-server middleware registered with `apply: 'serve'` from `vite.config.ts` only. `src/` never imports anything from `dev/`, so the production bundle cannot contain mock data (verified by grep, see below). The mock requires the nonce `dev-nonce` like the real server requires a valid nonce.

## Production build and PHP integration

`npm run build` emits into `assets/admin/` (repo root):

| File                  | Purpose                                                   |
| --------------------- | --------------------------------------------------------- |
| `app.js`              | ES module entry, load with `<script type="module">`       |
| `app.css`             | the only stylesheet (`cssCodeSplit: false`)               |
| `chunks/*.js`         | lazy route chunks (relative imports, `base: './'`)        |
| `assets/*`            | fonts (Inter latin/latin-ext, Material Icons Round woff2) |
| `.vite/manifest.json` | Vite manifest (entry key `src/main.ts`)                   |

`emptyOutDir` is on. No external resources are requested at runtime.

### Config injection contract

PHP prints, before the module script:

```html
<div id="swbackupit-app"></div>
<script>
    window.swbackupitConfig = {
        restRoot: 'https://example.com/wp-json/swbackupit/v1/', // ends with "/"
        nonce: '<wp_rest nonce>',
        locale: 'de_DE', // starts with "de" -> German, anything else -> English
        version: '1.0.0',
        adminUrl: 'https://example.com/wp-admin/',
        capabilities: ['swbi_view', 'swbi_run'],
        siteUrl: 'https://example.com',
    };
</script>
<link rel="stylesheet" href=".../assets/admin/app.css" />
<script type="module" src=".../assets/admin/app.js"></script>
```

Plain-permalink roots such as `https://x/?rest_route=/swbackupit/v1/` work too. If the nonce is refreshed by WordPress (`X-WP-Nonce` response header) the client adopts it. Capabilities are UX only (hide/disable); the server stays authoritative. Without `swbi_view` the app shows a "no access" state.

## Structure

```
src/
  main.ts                 bootstrap (Pinia, router, i18n, Quasar with scoped portals)
  config.ts               reads/validates window.swbackupitConfig
  api/client.ts           the ONLY fetch wrapper (nonce, JSON, ApiError{code,message,status,params})
  api/index.ts            typed per-resource modules (health, profiles, schedules, jobs, backups, storage, settings)
  types/                  api.ts (contract mirror), config.ts
  composables/            useAsyncData, useJobMonitor (poll+tick+logs), useCapabilities, useFormat, useNotify, useTheme, useRunProfile
  stores/                 app (theme, rail, drive toggle, live-region), jobs (shared running-jobs poll), lookups
  utils/                  format.ts (Intl helpers), jobs.ts (phases, backoff)
  i18n/                   de.ts (schema), en.ts (typed against de.ts), index.ts
  components/ui/          design-system layer: SwbiCard, StatusChip, StatTile, PageHeader, EmptyState,
                          ErrorState, DataState, ConfirmDialog, ProgressRing, FormSection, SideSheet,
                          FormSheet, PaginationBar, TagInput, SecretInput
  features/<domain>/      dashboard, profiles, schedules, backups, jobs, storage, settings (lazy views)
  layouts/                ShellLayout (left rail / top tabs on mobile), NoAccess
  styles/                 fonts.css, app.css (tokens, isolation reset, utilities)
dev/                      mock API (dev server only)
tools/postcssScope.ts     build-time scoping of Quasar CSS
tests/                    vitest specs
scripts/                  screenshots.mjs, check-css-scope.mjs, i18n-keys.mjs
```

## i18n

All user-visible strings live in `src/i18n/{de,en}.ts`. `de.ts` defines the schema; `en.ts` is typed `typeof de`, so a missing or extra key is a compile error. The language is chosen once from `config.locale` (German for `de*`, English otherwise; English is the fallback). Dates, numbers, bytes, durations and relative times use `Intl` with the active locale (`utils/format.ts`, `useFormat()`). Plurals use vue-i18n pipe syntax. Server-provided messages (errors, health messages) are shown as delivered.

`npm`-side note: the plugin PHP side uses WordPress' own translation system; this app deliberately ships its own small catalogue because the strings are bundled with the build (see "Deviations" in the report).

## Style isolation inside wp-admin

1. **Namespace.** Everything mounts into `#swbackupit-app`, which gets the classes `swbi-root` (and `swbi-fullbleed`). Our own CSS only uses `swbi-` classes, `.swbi-root` descendants or Vue scoped styles. Design tokens are CSS custom properties on `.swbi-root` and `.swbi-root.swbi-dark`.
2. **Quasar CSS is rewritten at build time** by `tools/postcssScope.ts` (a PostCSS plugin, active only for files from `node_modules/quasar` and `@quasar/extras`): `html/body/:root` become `.swbi-root`, `body.body--dark` becomes `.swbi-root.swbi-dark`, `body.body--light` becomes `.swbi-root:not(.swbi-dark)`, scroll-lock classes on `body` are neutralised, every other selector gets the `.swbi-root ` prefix. `@keyframes` are left untouched. `node scripts/check-css-scope.mjs` verifies the built `app.css` contains no unscoped selector.
3. **Portals.** Quasar teleports dialogs, menus, tooltips and notifications to `<body>`. `app.use(Quasar, { config: { globalNodes: { class: 'swbi-root' } } })` gives each of these global nodes the `swbi-root` class, so the scoped rules and tokens apply. `useThemeSync()` additionally copies `swbi-dark` to those nodes (MutationObserver on `body > .swbi-root`). Z-indexes of portals are raised above the admin bar (99999).
4. **Reset against wp-admin bleed.** `.swbi-root` resets headings, lists, links, tables, `code`, `label` etc. (wp-admin styles bare elements). Quasar selectors become `.swbi-root .q-…`, which out-specifies wp-admin's `input[type=text]` rules.
5. **Layout in wp-admin.** `.swbi-fullbleed` cancels the content area padding (`margin-left: -20px`, `-10px` on mobile); sticky offsets use `--wp-admin--admin-bar--height`. The only global side effects are the body class `swbi-admin-page` (hides `#wpfooter`, removes `#wpbody-content` bottom padding), Quasar's own body classes (`desktop`/`mobile`, `body--dark`) and `body.q-body--prevent-scroll {overflow:hidden}` while a dialog is open.

The dev host page (`index.html`) simulates hostile wp-admin globals (`h1`, `p`, `input[type=text]`, `label`, `table`, `code`, `a`) to make isolation regressions visible in screenshots.

## Security notes

- No `v-html` anywhere (ESLint rule `vue/no-v-html` is an error). Server strings are rendered with text interpolation only; restore links are only rendered when they are `http(s)`.
- Secret fields of storage targets are write-only inputs (`autocomplete="new-password"`), sent in `credentials`, wiped after saving/closing, never prefilled; a "credentials set" badge shows presence.
- Nothing sensitive is stored in localStorage/URL/console. The only localStorage key is `swbi-theme` (manual dark/light choice), wrapped in try/catch.
- Download links use `_wpnonce` in the query string (anchors cannot send headers) – this is the REST nonce (CSRF token), not a credential.

## Contract-specific behaviour (per "Implementation notes")

- **Job steps.** `job.phase` is a step id (`preflight … notify`, see `PHASES` in `utils/jobs.ts`); the stepper shows all 12 steps, labels in `i18n` under `phases.*`. Unknown ids are shown as delivered.
- **Job actions.** Pause/resume/cancel/retry (`POST /jobs/{id}/retry` for `failed` jobs), `trigger` and `next_retry_at` (for `retry_waiting`) are displayed. `job.error` may be a string or `{code, message}`.
- **Schedules.** `weekday` (weekly) and `monthday` (monthly) fields; hourly uses the minute of `time_of_day`.
- **Backups.** Downloads use `?part=volume|manifest|index|database&volume=N&_wpnonce=`. Restore: only `POST /backups/{id}/restore/prepare`. The one-time `token` is kept in memory only while the dialog is open (copy button, discarded on close), together with the wizard link, expiry and `target_path`. `POST /backups/{id}/restore` is never called (501 by design).
- **Storage targets.** Exact config/credential keys per type (sftp: `host_fingerprint`, `trust_unknown_host`, `allow_private_network`, `timeout`; s3: `allow_private_network`), write-only credentials, discover items with `error`, import `{backup_ids, download}`, 409 on delete shown in the dialog.
- **Encryption keys.** Settings > "Encryption keys" (list, create with optional passphrase, secret shown once with a copy button and a required "stored" confirmation, delete with typed confirmation). The profile's encryption tab selects from this list.
- **Errors.** `message` is always shown; 400/409 on forms render inline with per-field `data.params`; 501 (`swbi_use_wizard`/`swbi_not_implemented`) is an informative toast; 429 a "slow down" warning; 409 on delete (target in use, protected backup) is shown inside the confirmation dialog or toast.

## Phase 4 (enterprise) UI

- **Audit** (`/audit`, nav entry and view require `swbi_settings`): `GET /audit` with filters (action, object type, user id), pagination, translated action labels (`audit.actions.*`), relative and absolute time, expandable `context` (rendered as text only), empty/error states.
- **Restore test** (backup detail, `swbi_restore`): confirmation dialog (isolated temp directory + scratch database, production untouched, may take minutes), then `POST /backups/{id}/restore-test`. The result (also the stored `backup.restore_test`) is shown as a status badge (passed/partial/failed), duration and a per-check list with chips; `partial` is explained (database check skipped, no CREATE DATABASE privilege). Untested backups show a "never tested" hint.
- **Settings:** "Backup before updates" (toggles, profile, `max_age_minutes`, `wait_seconds`, `block_on_failure` with a warning), "Restore tests" (`enabled`, `interval_days`), `logging.audit_retention_days`. `PUT /settings` sends only the top-level groups that changed.
- **Storage targets:** `lock_days` for all types, s3 `object_lock {mode, days}` (note: bucket needs Object Lock), `immutable` with the explanation that retention never deletes immutable/locked targets.
- **Chains:** incremental/differential backups show their parent id as a chip in the table; the detail shows a "Required base backups" hint with a link to the parent.

## Behaviour notes

- **Job runner.** `useJobMonitor` polls `GET /jobs/{id}` with backoff (1 s → 5 s while unchanged, 5 s while paused, exponential up to 30 s on errors), pulls logs with `after=<last_id>`, keeps at most 2000 log lines, pauses while the tab is hidden and stops when its scope is disposed. While the job is active and the tab is visible, and "Drive job from this browser" is on (default), it calls `POST /jobs/{id}/tick` in a loop instead of `GET`. Progress is never simulated.
- Starting a profile/schedule/restore navigates to `/jobs/:id`, where the driver runs.
- Screen-reader announcements for progress are throttled (phase change or 25 % step, at most every 15 s; terminal status always) via one polite live region.
- Destructive actions use `ConfirmDialog` naming the target and consequences; deleting a backup requires typing the first 8 characters of its id; restore requires the path-confirmation checkbox plus typing the id prefix.
- Tables collapse into stacked cards below 760 px; the rail collapses to icons and becomes a scrollable top bar below 860 px.

## Versions (Node 18 compatible)

vue 3.5, quasar 2.18, @quasar/vite-plugin 1.9.0 (1.10+ requires Node 20), vite 6.4, vitest 3.2 (vitest 4+ requires Node 20), vue-i18n 10.0 (11 requires Node 22), pinia 3, vue-router 4, eslint 9.39, typescript 5.9, vue-tsc 3, prettier 3, playwright-core 1.55 (dev only; uses a Chromium from the Playwright cache).
`npm audit` reports advisories in vitest's transitive dependencies (dev tooling only, never shipped); the fixed versions need Node 20+.
