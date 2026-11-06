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
- PHPStan level 8 covers all production PHP (core, restore, adapter, plugin file, `uninstall.php`) with no baseline or ignores; `bin/` (build and dev scripts, never shipped) is deliberately outside the scope; WordPress/WP-CLI symbols and PHPDoc fixes live in `tests/phpstan/`.
- Security: path guard for every filesystem write on restore, no unserialize without `allowed_classes => false`, no shell execution from the web UI, no secrets in logs/manifests.

## Workflow
1. Write/adjust interface → implement → tests → `composer test`, `composer stan`, `composer cs` → docs → update `TODO.md`/`DONE.md` → small coherent commit.
2. Never run destructive operations on the real WordPress install; tests use temp dirs and a private MariaDB instance (`SWBI_TEST_DB_*` env vars, see `docs/development.md`).
3. Document unimplemented features explicitly (no fake implementations).

## Translations
Every change to user-facing text updates all languages (PHP gettext catalogs in `languages/`, SPA catalogs in `apps/admin/src/i18n/`, wizard catalogs are generated). Run `bin/i18n.sh update` and `bin/i18n.sh check`; the PHPUnit test `I18nTest` and the Vitest parity test enforce it. See `docs/translations.md`.

## Dokumentation

- Verfasse Dokumentation möglichst knapp und ohne Wiederholungen, um Tokens zu sparen. Beschränke sie auf nötige Entscheidungen, Sicherheitsdetails und Testhinweise; verlinke vorhandene Dokumentation statt Inhalte zu duplizieren.

## Tests und Coverage

- Jede funktionale Codeänderung (neue Funktionen, Fehlerbehebungen und Refactorings) muss durch passende automatisierte Tests abgedeckt sein; Tests im selben Arbeitsschritt ergänzen oder anpassen.
- Pflicht ist eine Zeilenabdeckung von **> 80 %** des produktiven Codes je Plugin; genau 80 % reichen nicht. PHP und JavaScript/TypeScript, soweit vorhanden, jeweils prüfen. Bestehende strengere Coverage-Grenzen bleiben verbindlich.
- Vor Abschluss relevante Tests und Coverage-Prüfungen ausführen und Ergebnisse nennen. Fehlende Coverage-Infrastruktur ergänzen; fehlende Messwerte gelten nicht als erfüllte Vorgabe. Keine produktiven Dateien ausschließen oder Grenzen senken, um die Vorgabe zu umgehen.

Vorhanden: PHPUnit-/WordPress-Coverage und SonarQube; siehe `docs/development.md` (SonarQube Cloud) und `sonar-project.properties`.

## Automated review follow-up for pull / merge requests

1. After creating a pull request (PR) or merge request (MR), keep the task open and wait five minutes before checking for automated reviews. Inspect review submissions, inline comments and unresolved review threads, bot comments, and review-related check runs for the latest pushed commit.
2. Assess every reported finding against the code. Fix valid findings, run the relevant checks, and push the corrections to the same PR/MR. Document the reasoning for findings that are incorrect or do not apply; do not silently ignore them.
3. After every push of corrections, wait another five minutes and repeat the review check. Continue this cycle until there are no outstanding valid automated-review findings for the latest commit.
4. If an automated review is still queued or running, keep monitoring and recheck after another five minutes. If review results cannot be accessed or the review fails to run, report that blocker instead of claiming review success.
5. Report success only after the follow-up check finds no outstanding valid findings and no automated reviews still pending. Include the PR/MR link and the review outcome. If no automated review appeared after the five-minute wait, explicitly report that no automated review was received rather than implying approval.
