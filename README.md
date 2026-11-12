# SW-BackupIt

[![Release](https://img.shields.io/github/v/release/sw-wordpress/sw-backupit?sort=semver)](https://github.com/sw-wordpress/sw-backupit/releases)
[![CI](https://github.com/sw-wordpress/sw-backupit/actions/workflows/tests.yml/badge.svg)](https://github.com/sw-wordpress/sw-backupit/actions/workflows/tests.yml)
[![Coverage](https://sonarcloud.io/api/project_badges/measure?project=sw-wordpress_sw-backupit&metric=coverage)](https://sonarcloud.io/summary/overall?id=sw-wordpress_sw-backupit)
[![SonarQube Issues](https://sonarcloud.io/api/project_badges/measure?project=sw-wordpress_sw-backupit&metric=vulnerabilities)](https://sonarcloud.io/project/issues?id=sw-wordpress_sw-backupit&impactSoftwareQualities=SECURITY&resolved=false)
![PHPStan level 8](https://img.shields.io/badge/phpstan%20level-8%20of%209-brightgreen?style=flat-square&logo=php)
![PHP 8.2+](https://img.shields.io/badge/PHP-8.2%2B-777bb4?style=flat-square&logo=php&logoColor=white)
![WordPress 6.4+](https://img.shields.io/badge/WordPress-6.4%2B-21759b?style=flat-square&logo=wordpress&logoColor=white)
[![License GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-blue?style=flat-square)](https://www.gnu.org/licenses/gpl-2.0.html)

Backs up the complete WordPress site (files and database) and restores it, also on another domain or server.
Restores run in a standalone application that works even when WordPress itself is broken.

## Features

- **Resumable backups** — Large sites are backed up in short steps that continue after interruptions, also on shared hosting.
- **Full, incremental and differential** — Profiles decide what is backed up, how often and how long it is kept.
- **Several storage targets** — Local directory, [SFTP](https://en.wikipedia.org/wiki/SSH_File_Transfer_Protocol) and [S3](https://en.wikipedia.org/wiki/Amazon_S3)-compatible storage, each copy verified.
- **Encryption** — Optional client-side encryption with [libsodium](https://en.wikipedia.org/wiki/NaCl_(software)) before a backup leaves the server.
- **Standalone restore** — Web wizard or command line, with selective restore and domain migration.
- **Restore tests and audit log** — Backups are checked regularly; downloads, deletions and restores are logged.

## Languages

English, German, French, Italian, Polish.

## Usage

1. Open **sw-backupit → Dashboard** (on multisite in the Network Admin) and fix any warnings shown there.
2. Optional: under **Storage targets** click **Add storage target**, then **Test connection**, and add the target to a profile under **Profiles**.
3. Under **Profiles** click **Run now** on *Full backup (everything, without cache)*. From the command line:
   `wp sw-backupit backup create --profile="Full backup (everything, without cache)"`
4. Under **Schedules** click **Create schedule** to run backups automatically.
5. To restore, open a backup under **Backups**, click **Restore**, then **Prepare restore** and **Open standalone restore**.

Full guide: [docs/user-guide.md](docs/user-guide.md), commands: [docs/wp-cli.md](docs/wp-cli.md).

## Configuration

### Requirements

PHP 8.2+ with `zip`, `zlib`, `pdo_mysql` (and `sodium` for encryption), WordPress 6.4+, MySQL or MariaDB, single site or multisite (network wide).

### Settings

Options are under **sw-backupit → Settings** (staging directory, **Cron mode**, **Encryption keys**,
**Backup before updates**, notifications, logging). Keep the staging directory outside the web root.

The plugin reads these `wp-config.php` constants:

```php
// Encryption key for key ID <ID> (upper-cased, other characters become "_"). Default: not set,
// the key stored under Settings → Encryption keys is used. Recommended: keep keys outside the database.
// Value: the secret shown once on creation ("base64:…") or a passphrase. Also read from the environment.
define( 'SWBACKUPIT_KEY_<ID>', 'base64:…' );

// Tests only: path to an alternative autoloader. Default: not set.
define( 'SWBACKUPIT_AUTOLOADER', '/path/to/autoload.php' );
```

### Background tasks

WP-Cron checks schedules every minute, runs backup jobs, restarts stuck jobs and once a day cleans up old logs
and restore wizards, verifies stored backups and runs due restore tests. On sites with little traffic or with
`DISABLE_WP_CRON`, set **Cron mode** to *System cron (recommended)* and run every minute, e.g.
`* * * * * wp sw-backupit schedule run-due --path=/path/to/wordpress`.

### Integrations

- **WP-CLI** — All backup, restore, schedule and storage functions: [docs/wp-cli.md](docs/wp-cli.md).
- **REST API and hooks** — For own integrations: [docs/rest-api.md](docs/rest-api.md), [docs/hooks.md](docs/hooks.md).

### Deactivation and removal

Deactivating stops all background tasks and removes prepared restore wizards; profiles, settings and backups are kept.
Deleting the plugin does the same and removes tables, settings, keys and capabilities only if the setting
`delete_data_on_uninstall` is enabled (default: off, not shown in the admin UI). Backups in the staging directory
and on storage targets are never deleted. On multisite all data is network wide.

## Troubleshooting

| Problem | Solution |
|---|---|
| Scheduled backups do not start. | Check the cron notice on the dashboard and use a system cron (see above). |
| A backup seems stuck. | Wait about a minute: the watchdog restarts lost jobs. Failed jobs can be retried from the job log. |
| The staging directory is refused or reported as public. | Choose a directory outside the web root; on nginx add a deny rule, see [docs/user-guide.md](docs/user-guide.md#troubleshooting). |
| An encrypted backup cannot be read. | Provide the key (`--key`, `SWBI_KEY` or `SWBACKUPIT_KEY_<ID>`). Without the key no restore is possible. |
| Restore reports a missing base backup. | Download the whole chain (`fetch --with-chain`) into the same directory. |

## Built with AI

This plugin was implemented with AI assistance. Why it exists and how AI is used:
[sw-wordpress manifest](https://github.com/sw-wordpress/.github/blob/main/profile/README.md).

AI agents must follow the project rules before changing code:

- Rules and mandatory coverage: [AGENTS.md](AGENTS.md), [CODE-STYLE.md](CODE-STYLE.md), [CONTRIBUTING.md](CONTRIBUTING.md)
- Product spec: [PLAN.md](PLAN.md), current status: [TODO.md](TODO.md), [DONE.md](DONE.md)
- Architecture, format and security: [docs/](docs/) ([architecture.md](docs/architecture.md), [backup-format.md](docs/backup-format.md), [security.md](docs/security.md))
- README structure: [README-STANDARD.md](https://github.com/sw-wordpress/sw-securelogin/blob/master/docs/README-STANDARD.md)

## Development

Requirements: PHP 8.2+, Composer, Node.js 22.13+ (22/24 LTS or 26+) with npm, Make, and Docker for the release build.

```sh
composer install
make build                           # admin UI (Vue/Quasar) into assets/admin
bin/test-db.sh start                 # private MariaDB for integration tests
(cd tests/wp && composer install)    # WordPress test environment

composer check                       # coding standards, PHPStan, unit + integration tests
composer test:e2e && composer test:wp && composer test:wp:ms
(cd apps/admin && npm run typecheck && npm run lint && npm run test)

bin/build-zip.sh                     # release ZIP in dist/
```

Every change needs tests; line coverage must stay above 80 % for PHP and JavaScript.
Test setup, release build and SonarQube: [docs/development.md](docs/development.md), translations: [docs/translations.md](docs/translations.md).
GitHub Actions run the checks on every push.

## License

[GPL-2.0-or-later](https://www.gnu.org/licenses/gpl-2.0.html) — see [LICENSE](LICENSE). Provided by Stefan Warnat.

Bundled third-party components. PHP libraries (license texts in each package directory under `vendor/`
after `composer install`; the release build ships them prefixed in `vendor-prefixed/`):

- [aws/aws-sdk-php](https://github.com/aws/aws-sdk-php), [aws/aws-crt-php](https://github.com/awslabs/aws-crt-php) — Apache-2.0
- [league/flysystem](https://github.com/thephpleague/flysystem) with [flysystem-local](https://github.com/thephpleague/flysystem-local), [flysystem-sftp-v3](https://github.com/thephpleague/flysystem-sftp-v3), [flysystem-aws-s3-v3](https://github.com/thephpleague/flysystem-aws-s3-v3), [mime-type-detection](https://github.com/thephpleague/mime-type-detection) — MIT
- [phpseclib/phpseclib](https://github.com/phpseclib/phpseclib), [paragonie/constant_time_encoding](https://github.com/paragonie/constant_time_encoding), [paragonie/random_compat](https://github.com/paragonie/random_compat) — MIT
- [maennchen/zipstream-php](https://github.com/maennchen/ZipStream-PHP), [dragonmantank/cron-expression](https://github.com/dragonmantank/cron-expression), [mtdowling/jmespath.php](https://github.com/jmespath/jmespath.php) — MIT
- [guzzlehttp/guzzle](https://github.com/guzzle/guzzle), [guzzlehttp/promises](https://github.com/guzzle/promises), [guzzlehttp/psr7](https://github.com/guzzle/psr7) — MIT
- [psr/log](https://github.com/php-fig/log) (also in the standalone restore app), [psr/http-client](https://github.com/php-fig/http-client), [psr/http-factory](https://github.com/php-fig/http-factory), [psr/http-message](https://github.com/php-fig/http-message) — MIT
- [symfony/filesystem](https://github.com/symfony/filesystem), [deprecation-contracts](https://github.com/symfony/deprecation-contracts), [polyfill-ctype](https://github.com/symfony/polyfill-ctype), [polyfill-mbstring](https://github.com/symfony/polyfill-mbstring), [polyfill-php80](https://github.com/symfony/polyfill-php80), [polyfill-php82](https://github.com/symfony/polyfill-php82) — MIT

Admin UI bundle in `assets/admin/` (license headers in `app.js`, full texts in `apps/admin/node_modules/` of the source):

- [Vue](https://github.com/vuejs/core), [Vue Router](https://github.com/vuejs/router), [Pinia](https://github.com/vuejs/pinia), [Vue I18n](https://github.com/intlify/vue-i18n) — MIT
- [Quasar](https://github.com/quasarframework/quasar), [@quasar/extras](https://github.com/quasarframework/quasar/tree/dev/extras) — MIT
- [Material Icons Round](https://github.com/google/material-design-icons) font — Apache-2.0
- [Inter](https://github.com/rsms/inter), [JetBrains Mono](https://github.com/JetBrains/JetBrainsMono) fonts (via Fontsource) — OFL-1.1
