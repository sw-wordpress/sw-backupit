# SWBackupIt

Robust, resumable WordPress backup, restore and disaster recovery with an independent standalone restore application.

* **Streams everything** (files → ZIP64 volumes, database → gzip SQL parts): low memory, short resumable ticks, works on shared hosting; no process has to stay alive.
* **Persistent jobs**: checkpoints, leases, pause/resume/cancel, retry with backoff, watchdog, per-step logs and progress.
* **Full / Files / Database / Incremental / Differential** backups with complete per-backup indexes and verified base chains.
* **Storage**: local, SFTP, S3-compatible via Flysystem; multi-target replication with independent status, remote verification, resumable S3 multipart, immutable targets / Object Lock.
* **Client-side encryption** (libsodium XChaCha20-Poly1305 streaming, documented format), retention incl. GFS, e-mail notifications.
* **Standalone restore** (CLI + secured web wizard) that needs neither WordPress nor Composer: verification, compare, selective restore, serialization-safe URL/path/prefix migration, restore from storage, test restores in an isolated environment.
* **Enterprise**: audit log, background verification, pre-update backups, multisite (network-wide), health monitoring.
* Modern Vue 3 / Quasar admin UI (German/English), REST API (`docs/rest-api.md`), WP-CLI (`docs/wp-cli.md`).

Backups are self-contained directories (`manifest.json`, `files.ndjson`, volumes, SQL parts – `docs/backup-format.md`) and can be restored without the SWBackupIt database tables.

## Documentation
`docs/user-guide.md` · `docs/architecture.md` · `docs/backup-format.md` · `docs/security.md` · `docs/wp-cli.md` · `docs/hooks.md` · `docs/rest-api.md` · `docs/development.md` · progress: `DONE.md`, `TODO.md`

## Requirements
PHP 8.2+, WordPress 6.4+, extensions `zip`, `zlib`, `pdo_mysql` (`sodium` for encryption). Build the installable ZIP with `bin/build-zip.sh` (needs Composer and, for the UI, Node on the *build* machine only).

License: GPL-2.0-or-later.
