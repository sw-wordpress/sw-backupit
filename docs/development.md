# Development

## Setup
```bash
composer install
(cd apps/admin && npm install)
bin/test-db.sh start                 # private throw-away MariaDB on 127.0.0.1:33077 (never touches real databases)
(cd tests/wp && composer install)    # WordPress test environment (own PHPUnit 9.6, WordPress core downloaded into tests/wp/vendor)
# optional: S3 mock for the storage tests (moto)
python3 -m venv /tmp/swbi-moto && /tmp/swbi-moto/bin/pip install 'moto[server]' && bin/test-s3.sh start
```
Tests that need a database or S3 mock skip themselves when the service is not reachable. Connection settings:
`SWBI_TEST_DB_HOST/PORT/USER/PASSWORD`, `SWBI_TEST_S3_PORT`. The WordPress tests use the database `swbi_wp_tests` of the private instance.

## Checks
```bash
composer cs          # PSR-12 (core, restore, tests)
composer cs:wp       # WordPress Coding Standards (adapter)
composer stan        # PHPStan level 8
composer test        # unit + integration
composer test:e2e    # backup -> wipe -> restore (incl. encryption, storage targets, incremental chains)
composer test:load   # 20,000 files, 300 MB file, concurrent workers, full disk (slow)
composer test:wp     # WordPress integration tests (single site)
composer test:wp:ms  # same environment as multisite
(cd apps/admin && npm run typecheck && npm run lint && npm run test:coverage)
```
`composer check` runs style, analysis and tests. Style warnings for lines longer than 200 characters do not fail the build. Documented lint exceptions live in
`phpcs.xml.dist` / `phpcs-wordpress.xml.dist` (enum handling of PHPCompatibility 9, docblock requirement relaxed for typed adapter methods, exception messages
are never echoed as HTML).

## Build
`bin/build-zip.sh` creates `dist/sw-backupit-<version>.zip` (production Composer dependencies, compiled SPA, standalone restore in `restore-app/`).
It needs Composer, Docker and Node.js on the *build* machine only. `bin/build-restore.sh <dir>` builds only the standalone restore application.

### Admin UI build
`make build` (Make, Node.js >= 18.18, npm) builds only the admin UI into `assets/admin/` (including TypeScript checks), not the release ZIP
or the restore application. Dependencies are installed with `npm ci --include=dev` on the first build and whenever
`apps/admin/package.json` or its lockfile changes.

### Isolated release dependencies
Local ZIP builds and the GitHub release workflow share `bin/build-zip.sh`. It installs only production Composer dependencies into a
temporary staging tree, smoke-tests the checkout, then runs `coenjacobs/mozart:1.2.3` (rewriting the staging copy, not the checkout).
External classes use the namespace `SWBackupIt\Dependencies\`; classmapped symbols use `SWBackupIt_`. Only the resulting
`vendor-prefixed/` tree is shipped, never the original top-level `vendor/` directory.

The plugin prefers `vendor-prefixed/autoload.php` and registers the Core, Restore and WordPress PSR-4 mappings on its loader. Source
checkouts keep the normal `composer install` workflow and the `SWBACKUPIT_AUTOLOADER` test override.

The independent `restore-app/` contains only its own classes, a minimal loader and PSR Log, copied from the isolated staging tree. Its
small `vendor/` directory is intentional; it is not the plugin's original Composer tree. Building restore directly from a development
checkout still supports the unprefixed PSR Log library.

`php tests/bootstrap.php [plugin-directory]` checks the real plugin entry point, lifecycle/hooks, all three monorepo mappings and
representative production library operations (including offline AWS signing, dynamic phpseclib loading and local storage). The build
runs this test again after removing the original Composer tree; CI also checks the final extracted ZIP and the standalone restore CLI.

## Dependency security
Every push/PR fails on known advisories in **shipped** dependencies (`composer audit --locked --no-dev`, skipped while `composer.lock` has no
runtime packages; `npm audit --omit=dev --audit-level=high` in `apps/admin`). New advisories appear without code changes, so
`.github/workflows/security-audit.yml` audits all dependencies, including dev tooling, daily and keeps one open `security-audit` issue
(closed when clean). `.github/dependabot.yml` opens weekly grouped PRs (security fixes, peer-coupled toolchain packages, dev tools, runtime
minor/patch; runtime majors individually). Dependabot PRs get no secrets, so the SonarQube job skips them. Enable Dependabot alerts and
security updates in the repository settings. The admin app needs Node >= 22 (vue-i18n 11; `apps/admin/.nvmrc`, CI uses 22).
PHPStan level 8 covers all production PHP (core, restore, WordPress adapter, `sw-backupit.php`, `uninstall.php`) with no baseline; WordPress
(`szepeviktor/phpstan-wordpress`), WP-CLI and constants come from `tests/phpstan/` (bootstrap, stubs, corrected core PHPDoc). `bin/` holds
dev/build scripts and is not analysed. `tests/wp` is a separate Composer project and outside Dependabot and the audits.

## SonarQube Cloud
The test workflow uploads merged PHP Clover coverage and analyzes same-repository PRs and pushes to the default branch. Configure the
`SONAR_TOKEN` Actions secret and `SONAR_ORGANIZATION` Actions variable (organization-level bindings may be reused). Project mapping and
source paths are in `sonar-project.properties`. Fork PRs run tests without access to the scanner token. Coverage uses the existing
PHPUnit scopes (unit, integration, WordPress and e2e suites, merged); unmeasured application code is not excluded from SonarQube. Generated assets and third-party dependencies are
not analysis inputs. The admin UI's `npm run test:coverage` measures every file in `apps/admin/src` (except type-only `src/types/`)
with V8 and writes `apps/admin/coverage/lcov.info` for SonarQube. CI fails unless line coverage is strictly above 80 % (exactly 80 %
fails): `tests/coverage/check.php` for the merged PHP Clover report, `apps/admin/scripts/check-coverage.mjs` for the frontend
(Vitest thresholds alone would accept 80 %).

## Conventions
See `AGENTS.md` and `CODE-STYLE.md`. Gotchas learned the hard way: `wpdb::$field_types` maps columns named `object_id` to `%d` (pass explicit formats);
ZipStream rewrites `\ : * ? " < > |` in entry names (we percent-encode them); the WordPress test suite creates tables as TEMPORARY (other connections cannot see them).
