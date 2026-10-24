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
(cd apps/admin && npm run typecheck && npm run lint && npm run test)
```
`composer check` runs style, analysis and tests. Style warnings for lines longer than 200 characters do not fail the build. Documented lint exceptions live in
`phpcs.xml.dist` / `phpcs-wordpress.xml.dist` (enum handling of PHPCompatibility 9, docblock requirement relaxed for typed adapter methods, exception messages
are never echoed as HTML).

## Build
`bin/build-zip.sh` creates `dist/swbackupit-<version>.zip` (production Composer dependencies, compiled SPA, standalone restore in `restore-app/`).
`bin/build-restore.sh <dir>` builds only the standalone restore application.

## Conventions
See `AGENTS.md` and `CODE-STYLE.md`. Gotchas learned the hard way: `wpdb::$field_types` maps columns named `object_id` to `%d` (pass explicit formats);
ZipStream rewrites `\ : * ? " < > |` in entry names (we percent-encode them); the WordPress test suite creates tables as TEMPORARY (other connections cannot see them).
