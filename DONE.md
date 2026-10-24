# DONE

Chronological log of completed work (newest last). Test counts refer to the state at the end of each phase.

## 2026-10-24 – Phase 1: Core / MVP
- Analysed `PLAN.md` and `CODE-STYLE.md`; wrote `docs/implementation-plan.md` (ADRs, phases), `AGENTS.md`, `docs/rest-api.md` (REST contract), git repo, monorepo (core / restore / wordpress).
- Job engine: persistent state machine, lease/lock, checkpoints, pause/cancel/retry with backoff; resumable file scanner (canonical order, NDJSON index instead of SQLite); ZIP64 volumes with chunking of huge files; streaming resumable DB exporter (opt-in consistent snapshot); manifest v1; local verification.
- Standalone restore engine + CLI (verify, list, restore, selective, dry-run) with zip-slip / symlink / zip-bomb protection, resumable file and DB restore, wp-config rewrite.
- WordPress adapter: schema + migrations, wpdb repositories, REST API, WP-Cron watcher, WP-CLI, capabilities, settings, health. Vue 3 / Quasar / TypeScript SPA (built by a delegated frontend sub-agent), build scripts for plugin ZIP and standalone restore distribution.

## Phase 2: Storage & Recovery
- Flysystem 3 storage layer (local, SFTP, S3) with capability handling, SSRF endpoint policy, retry with backoff + jitter, upload with temp names/atomic finalisation, per-target status, remote verification (size + SHA-256, S3 via object metadata), success policies, remote catalog discovery/import, backup + chain download.
- SWBE1 streaming authenticated encryption (libsodium secretstream, Argon2id or raw key), encrypt step with decrypt round-trip proof, key providers (constant/env/stored), restore-side decryption, verification without key.
- Retention: count / age / storage / GFS, protected + newest full + chain dependencies, unreliable metadata never deleted, dry-run, executor with failure isolation, catalogs (directory, WordPress).
- Restore: serialization-safe URL/path migration (own parser, never `unserialize`), transactional resumable DB migration, table prefix migration, file comparator (merge-join in canonical order, metadata/SHA-256), selective restore, secured web wizard (token hash, rate limit, CSRF, CSP, typed confirmations, self-lock/removal).
- WordPress: storage targets (encrypted credentials), encryption keys API, e-mail notifications with de-duplication, compare/restore-prepare endpoints, WP-CLI storage/retention/compare/restore.

## Phase 3: Advanced backup
- Incremental and differential backups with a complete per-backup index (`b` references), base selection with fallback to full, chain-aware retention, restore/verification across chains, `fetch --with-chain`.
- Resumable S3 multipart upload (JSON state, part granularity), step-level hook events (`before_step`/`after_step`/`step_failed`), `docs/hooks.md`.

## Phase 4: Enterprise
- Audit log (append-only table, REST + hooks), isolated restore tests (temp dir + scratch database, documented result), background verification and scheduled restore tests, health monitor (overdue schedules, low disk), job watchdog, pre-update backups (core/plugins/themes; recency window, wait limit, optional blocking), multisite semantics (network-wide data, network admin UI, one cron watcher), immutable targets / lock periods / S3 Object Lock, load and failure tests (20,000 files, 300 MB file, concurrent workers, full disk), REST security regression tests, SPA features for all of the above (second delegated round).

## Test and quality status (end of Phase 4)
- Main PHPUnit suites (unit + integration + e2e): 133 tests green (private MariaDB and S3 mock); load suite 4 tests green; WordPress integration suite 35 tests green in single-site and multisite mode.
- PHPStan level 8: clean. PHPCS: PSR-12 (core/restore/tests) and WordPress Coding Standards (adapter) clean (documented exceptions in the rulesets).
- SPA: `vue-tsc`, ESLint, Vitest (41 tests) and production build green.

## Key architecture decisions
See `docs/implementation-plan.md` and `docs/architecture.md`: NDJSON index (no pdo_sqlite dependency), volume-granular resume, flags/lease outside the job entity, manifest written after verified artifacts and uploaded last, complete index per delta backup, network-wide data on multisite, restore never inside WordPress, secrets only as hashes/encrypted values.

## Languages and default scope (follow-up)
- gettext pipeline: `languages/sw-backupit.pot` (code + generated core message list), `.po/.mo` for de_DE, fr_FR, pl_PL, it_IT, `bin/i18n.sh pot|update|compile|check`, regional locale fallback, core/restore messages translated at presentation time, standalone wizard with its own translator and generated catalogs, SPA catalogs in en/de/fr/pl/it with Quasar language packs; guards: PHPUnit `I18nTest`, Vitest `i18n-parity.test.ts`; documented in `docs/translations.md`, enforced by `AGENTS.md`/`CONTRIBUTING.md`.
- Default scope: "everything except cache" (profile seeded on activation, API/SPA creation defaults); second seeded profile "Content update (uploads, themes, plugins)" (incremental).

## Opus re-review (correctness, security, missing features)
- Realistic read-only test against the local dev site (1.1 GB, 62,870 files, 68 tables): backup 52 s / 32 MB peak memory, deep verify OK,
  restore into a temp dir + private MariaDB byte-identical (files) and checksum-identical (tables); only files edited during the run differed.
- Core/restore fixes: retention never deletes the newest backup of any type; torn chunked files flagged; failed restore entries retried;
  restore state bound to the request; exactly-once SQL resume (transactional progress table), persisted deferred views, session settings on
  resume; ENUM/SET/FLOAT/BIT/binary keys, BIT values, DEFAULT_GENERATED columns, utf8mb4 export connection, view db qualifier; migration
  progress reset, idempotent prefix rename, wp-config warning; lease loss aborts a tick; single-use wizard token; restore notes (symlinks,
  inconsistent files).
- WordPress fixes: multisite capabilities, WP-CLI subcommand names (`list`, `run-due`), watchdog/cancel schedule status and mails, paused
  jobs count as running, serialised job start and atomic schedule claim, system-cron mode, encrypted downloads, no false "corrupt" for
  remote-only backups, pre-update backups for core updates, DST-safe schedules, web-root staging refused, settings booleans, uninstall
  cleanup, verified migrations, logged listener errors, restore-test mails.
- New: complete recovery package as one ZIP (REST `GET /backups/{id}/package`, `wp sw-backupit backup package`, SPA download menu) and
  selective database restore (`--tables`).

