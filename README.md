# SWBackupIt

Robust, resumable WordPress backup, restore and disaster recovery with an independent standalone restore application.

* Streams everything (files, ZIP64 volumes, database) – low memory, works on shared hosting (short resumable ticks)
* Persistent jobs with checkpoints, leases, pause/resume/cancel, retry with backoff
* Backups are self-contained directories (manifest + index + volumes + SQL parts) that restore without WordPress
* Modern Vue 3 / Quasar admin UI, REST API, WP-CLI, standalone restore CLI

Status: see `DONE.md` / `TODO.md`. Docs: `docs/user-guide.md`, `docs/architecture.md`, `docs/backup-format.md`,
`docs/security.md`, `docs/wp-cli.md`, `docs/development.md`.

Requirements: PHP 8.2+, WordPress 6.4+, extensions zip, zlib, pdo_mysql (sodium for encryption).
License: GPL-2.0-or-later.
