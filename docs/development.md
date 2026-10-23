# Development

## Setup
```bash
composer install
(cd apps/admin && npm install)
bin/test-db.sh start          # private throw-away MariaDB on 127.0.0.1:33077 (never touches real databases)
(cd tests/wp && composer install)   # WordPress test environment (own PHPUnit 9.6)
```

## Checks
```bash
composer cs          # PSR-12 (core, restore, tests)
composer cs:wp       # WordPress Coding Standards (adapter)
composer stan        # PHPStan level 8
composer test        # unit + integration (DB tests skip without the test DB)
composer test:e2e    # backup -> wipe -> restore
composer test:wp     # WordPress integration tests
(cd apps/admin && npm run typecheck && npm run lint && npm run test)
```
Test database connection: `SWBI_TEST_DB_HOST/PORT/USER/PASSWORD` (defaults to the instance of `bin/test-db.sh`).

## Build
`bin/build-zip.sh` creates `dist/swbackupit-<version>.zip` (production Composer dependencies, compiled SPA, standalone restore).
`bin/build-restore.sh <dir>` builds only the standalone restore application.

## Conventions
See `AGENTS.md` and `CODE-STYLE.md`. Documented lint exceptions live in `phpcs*.xml.dist`.
