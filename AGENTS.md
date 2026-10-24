# AGENTS.md – long-term rules for sw-backupit

Read `PLAN.md` (product spec) and `CODE-STYLE.md` (binding style/security rules) before changing code.

## Architecture rules
- `packages/core` is framework independent: **no WordPress functions, classes or hooks**. PSR-12, `strict_types`, camelCase.
- `packages/restore` must run without WordPress and without Composer on the target (built into a self-contained dir); shares core classes.
- `packages/wordpress` is the only place using WP APIs. WPCS style (tabs, snake_case functions), PSR-4 class names are an accepted exception (CODE-STYLE §3.1).
- Dependencies point inwards: wordpress → core ← restore. Core never imports the other two.
- Everything large is streamed; never load a whole file/table/manifest into memory.
- A checkpoint is written only after the data it describes is durably on disk (fsync + atomic rename).
- A finished volume/part is immutable. Never modify published artifacts in place.
- Backups must stay readable without the WordPress database (manifest + index live next to the volumes).
- Job status/backup types are enums, never strings.
- Hooks/events: define failure policy explicitly (see `docs/architecture.md`).
- Security: path guard for every filesystem write on restore, no unserialize without `allowed_classes => false`, no shell execution from the web UI, no secrets in logs/manifests.

## Workflow
1. Write/adjust interface → implement → tests → `composer test`, `composer stan`, `composer cs` → docs → update `TODO.md`/`DONE.md` → small coherent commit.
2. Never push or publish. Never run destructive operations on the real WordPress install; tests use temp dirs and a private MariaDB instance (`SWBI_TEST_DB_*` env vars, see `docs/development.md`).
3. Document unimplemented features explicitly (no fake implementations).

## Translations
Every change to user-facing text updates all languages (PHP gettext catalogs in `languages/`, SPA catalogs in `apps/admin/src/i18n/`, wizard catalogs are generated). Run `bin/i18n.sh update` and `bin/i18n.sh check`; the PHPUnit test `I18nTest` and the Vitest parity test enforce it. See `docs/translations.md`.
