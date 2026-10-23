# SWBackupIt – Implementation plan

Status legend: [x] done, [~] partial, [ ] open. The authoritative task list is `TODO.md`; finished work is logged in `DONE.md`.

## Key architecture decisions (ADR summary)

| # | Decision | Rationale |
|---|---|---|
| 1 | Monorepo with one root `composer.json`, three PSR-4 packages: `packages/core` (`SWBackupIt\Core`), `packages/restore` (`SWBackupIt\Restore`), `packages/wordpress` (`SWBackupIt\WordPress`) | Core/Restore share code, WordPress adapter is the only place that knows WP. Standalone restore is built from core+restore only. |
| 2 | Core persists jobs through `JobRepositoryInterface`; `FileJobRepository` (atomic JSON) in core, `WpdbJobRepository` in the WP adapter | Core must run without WordPress; WP uses dedicated `swbi_*` tables (spec §21). |
| 3 | File index = NDJSON stream (`files.ndjson`) in canonical path order, not SQLite | `pdo_sqlite` is not available on many shared hosts. NDJSON is streamable, mergeable (incremental) and readable by the standalone restore without any DB. |
| 4 | Archives = independent ZIP volumes written with `maennchen/zipstream-php` (ZIP64); files larger than the volume limit are split into `*.swbpartNNNN` chunk entries recorded in the manifest | A volume is a complete, self-contained ZIP; volume-granular resume. |
| 5 | Resume granularity: finished + verified volumes are never rebuilt; the volume in progress is discarded and rebuilt | Streaming ZIP cannot be appended safely; volume size is the user knob for shared hosts. |
| 6 | DB export = own streaming exporter over PDO (keyset pagination, binary-safe hex literals, gzip parts split at statement boundaries, resumable per part) | No dependency on `mysqldump`/shell; low memory. |
| 7 | Encryption = libsodium `crypto_secretstream_xchacha20poly1305`, Argon2id key derivation (or raw key file), per-volume encrypted container `SWBE1` | Authenticated, streaming, documented in `docs/backup-format.md`; no secrets stored only in WP DB. |
| 8 | Execution model = short "ticks" with a time budget; drivers: WP-Cron single events + loopback, browser tick (`POST /jobs/{id}/tick`), WP-CLI/system cron loop | No permanently running process; works on shared hosting. |
| 9 | Core events via `EventDispatcherInterface` with explicit failure policies per event (blocking vs. non-blocking) | Hooks may never leave state inconsistent (spec §17). |
| 10 | Restore web wizard: single-use token (hashed at rest), CSRF, rate limit, path confirmation, self-lock/self-delete after success | Spec §13.6. |

## Phases

### Phase 1 – Core / MVP
1. Repository, composer, tooling (PHPUnit, PHPStan, PHPCS) ✔
2. Core domain: enums, value objects, profile snapshot, job + state machine, repositories
3. Support: path guard, filesystem helper, atomic writes, resource budget
4. File scanner + rules + NDJSON index
5. DB exporter
6. Archive writer (volumes, chunking), archive reader/verifier
7. Manifest manager (format v1)
8. Backup engine (steps, checkpoints, resume, lock/lease, cancel/pause)
9. Standalone restore (CLI): verify, extract (secure), DB import, config rewrite
10. WordPress adapter: bootstrap, schema/migrations, repositories, REST, scheduler, WP-CLI, capabilities
11. Vue/Quasar admin SPA
12. Tests incl. end-to-end backup → wipe → restore
13. Docs

### Phase 2 – Storage & Recovery
Flysystem storage manager (local/SFTP/S3) with capability interfaces, multi-target upload with retry/backoff and per-target status, remote verification, retention (GFS), encryption, extended restore (serialized-safe URL/path migration, selective restore, compare), notifications.

### Phase 3 – Advanced backup
Incremental/differential via merge-join of canonical file indices, backup chains, chain-aware retention, chunked/multipart upload, full resume, extended hook system.

### Phase 4 – Enterprise
Audit log, restore test automation, health monitoring, multisite, immutable storage (object lock), pre-update backups, error handling hardening, load/security tests.

## Quality gates per feature
Unit tests → integration tests → PHPStan → PHPCS (PSR-12 core/restore, WPCS wordpress) → docs + TODO/DONE update → commit.
