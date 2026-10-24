# sw-backupit

Robust, resumable WordPress backup, restore and disaster recovery with an independent standalone restore application.

* **Streams everything** (files → ZIP64 volumes, database → gzip SQL parts): low memory, short resumable ticks, works on shared hosting; no process has to stay alive.
* **Persistent jobs**: checkpoints, leases, pause/resume/cancel, retry with backoff, watchdog, per-step logs and progress.
* **Full / Files / Database / Incremental / Differential** backups with complete per-backup indexes and verified base chains.
* **Storage**: local, SFTP, S3-compatible via Flysystem; multi-target replication with independent status, remote verification, resumable S3 multipart, immutable targets / Object Lock.
* **Client-side encryption** (libsodium XChaCha20-Poly1305 streaming, documented format), retention incl. GFS, e-mail notifications.
* **Standalone restore** (CLI + secured web wizard) that needs neither WordPress nor Composer: verification, compare, selective restore, serialization-safe URL/path/prefix migration, restore from storage, test restores in an isolated environment.
* **Enterprise**: audit log, background verification, pre-update backups, multisite (network-wide), health monitoring.
* Modern Vue 3 / Quasar admin UI (German/English), REST API (`docs/rest-api.md`), WP-CLI (`docs/wp-cli.md`).

Backups are self-contained directories (`manifest.json`, `files.ndjson`, volumes, SQL parts – `docs/backup-format.md`) and can be restored without the sw-backupit database tables.

## Plugin naming

The plugin directory and entry point are `sw-backupit/sw-backupit.php`. The text domain, REST namespace
(`sw-backupit/v1`), admin page, WP-CLI command (`wp sw-backupit`) and restore executable (`sw-backupit-restore`)
use the same name. Update external CLI/REST integrations and reactivate the renamed plugin in WordPress if necessary.
PHP namespaces (`SWBackupIt`), constants (`SWBACKUPIT_*`), the JavaScript configuration object and existing
`swbi_*` storage keys stay unchanged for compatibility. Backups with the legacy manifest label remain readable.

## Documentation
`docs/user-guide.md` · `docs/architecture.md` · `docs/backup-format.md` · `docs/security.md` · `docs/wp-cli.md` · `docs/hooks.md` · `docs/rest-api.md` · `docs/development.md` · progress: `DONE.md`, `TODO.md`

## Requirements
PHP 8.2+, WordPress 6.4+, extensions `zip`, `zlib`, `pdo_mysql` (`sodium` for encryption). Build the installable ZIP with `bin/build-zip.sh` (needs Composer, Docker and, for the UI, Node on the *build* machine only).

## Isolated release dependencies

Local ZIP builds and the GitHub release workflow share `bin/build-zip.sh`. It installs
only production Composer dependencies into a temporary staging tree, smoke-tests the
checkout, then runs `coenjacobs/mozart:1.2.3`. External classes use the namespace
`SWBackupIt\Dependencies\`; classmapped symbols use `SWBackupIt_`. Only the resulting
`vendor-prefixed/` tree is shipped, never the original top-level `vendor/` directory.
Mozart rewrites the staging copy, not the development checkout.

The plugin prefers `vendor-prefixed/autoload.php` and registers the Core, Restore and
WordPress PSR-4 mappings on its loader. Source checkouts retain the normal
`composer install` workflow and the `SWBACKUPIT_AUTOLOADER` test override.

The independent `restore-app/` contains only its own classes, a minimal loader and
PSR Log, copied from the isolated staging tree. Its small `vendor/` directory is
intentional; it is not the plugin's original Composer tree. Building restore directly
from a development checkout still supports the unprefixed PSR Log library.

`php tests/bootstrap.php [plugin-directory]` checks the real plugin entry point,
lifecycle/hooks, all three monorepo mappings and representative production library
operations (including offline AWS signing, dynamic phpseclib loading and local storage).
The build runs this test again after removing the original Composer tree; CI also
checks the final extracted ZIP and the standalone restore CLI.

License: GPL-2.0-or-later.
