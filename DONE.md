# DONE

Chronological log of completed work (newest last).

## 2026-10-24
- Analysed `PLAN.md` and `CODE-STYLE.md`; wrote `docs/implementation-plan.md` (ADRs, phases), `AGENTS.md`, `docs/rest-api.md` (REST contract).
- Initialised git repo, root `composer.json` (PSR-4 monorepo mapping), dev tooling installed.

## Phase 1 (Core / MVP) – completed 2026-10-24
Implemented: monorepo (core / restore / wordpress), job engine with persistent state machine, leases, checkpoints,
pause/cancel/retry; resumable file scanner; ZIP64 volumes with chunking of huge files; streaming resumable DB exporter
(+ opt-in consistent snapshot); manifest v1 and NDJSON file index; local verification; standalone restore engine and CLI with
zip-slip/symlink/zip-bomb protection, resumable file and DB restore, wp-config rewrite; WordPress adapter (schema + migrations,
wpdb repositories, REST API, WP-Cron watcher, WP-CLI, capabilities, settings, health); Vue/Quasar SPA (built by a sub-agent);
build scripts (plugin ZIP, standalone restore distribution).

Tests (all green): 62 PHPUnit tests in the main suite (unit, integration incl. private MariaDB, E2E backup→wipe→restore, restore
security, CLI, standalone distribution in a separate process) + 12 WordPress integration tests; PHPStan level 8 clean; PHPCS
(PSR-12) and WPCS clean (documented exceptions in the ruleset).

Architecture decisions: see `docs/implementation-plan.md` (NDJSON index instead of SQLite because pdo_sqlite is missing on many hosts;
volume-granular resume; flags/lease handled outside the job entity; enum-only Domain dir excluded from two PHPCompatibility sniffs).

Known limitations: no external storage/encryption/retention/URL migration/notifications yet (Phase 2); restore web wizard open;
`compare` and web restore endpoints answer 501; external (absolute) paths are rejected by preflight.
