# User guide

## Installation
Upload the plugin ZIP (Plugins → Add New → Upload) and activate it. On multisite activate it network wide and use the **Network Admin**
menu (all data and backups are network wide). Open **sw-backupit**. The dashboard shows warnings (missing PHP extensions, staging directory
location, WP-Cron reliability, overdue schedules, free disk space).

Requirements: PHP 8.2+, WordPress 6.4+, extensions `zip`, `zlib`, `pdo_mysql` (and `sodium` for encryption). No Composer or Node.js on the server.

## Default backup scope
On activation two profiles are created: **Full backup (everything, without cache)** backs up the complete WordPress directory and the database, excluding only
cache directories (`wp-content/cache`, `wp-content/uploads/cache`, `wp-content/et-cache`, `wp-content/litespeed`, `wp-content/upgrade`) and the backup staging area, so a 100 %
copy of the site is always available. **Content update (uploads, themes, plugins)** is an incremental profile that only refreshes those three directories (plus the database).
New profiles start with the same "everything minus cache" scope. Add schedules for the profiles you want to run automatically.

## Languages
The interface, messages, notifications and the restore wizard are available in English, German, French, Polish and Italian (follows the WordPress user language; see `translations.md`).

## Backup profiles
A profile defines what is backed up: type (Full, Files only, Database only, Incremental, Differential), directories/patterns to include or
exclude, database tables, compression, volume size, resource limits, storage targets, retention and notifications. A running job always uses
a snapshot of the profile as it was when the job started. *Run now* starts a manual backup; progress, phase and logs are shown live. Jobs can be
paused, resumed, cancelled and (when failed) retried; they continue from their checkpoints. Only one backup runs at a time.

* **Incremental** stores only files changed since the previous backup, **Differential** only files changed since the last full backup. Without a base
  backup the first run is a full backup. The database is always exported completely. A backup chain needs all its base backups to restore.
* *Consistent database snapshot* exports the database in one transaction (InnoDB only). It cannot be resumed, so use it from the CLI.
* Database and files are never an atomic snapshot of each other (see `backup-format.md`).

## Schedules
Hourly, daily, weekly, monthly or a cron expression, in a chosen timezone (DST-safe). WP-Cron needs site visitors; for exact timing use a system
cron job (`wp sw-backupit schedule run-due`, see `wp-cli.md`) and set the cron mode to *system*. Failed scheduled runs are retried with
backoff (manual runs of a schedule are not retried; renaming a schedule keeps a pending retry). Cancelled runs show the status *cancelled*.
Overdue schedules are reported. On DST changes a fixed time runs once per day (the repeated hour in autumn does not run it twice); hourly
schedules run every real hour.

## Storage targets
Add targets under *Storage targets*: **local directory**, **SFTP** (set the host key fingerprint; accepting an unknown host is an explicit, warned
option) or **S3-compatible object storage** (endpoint, bucket, region, path style). Credentials are write-only and stored encrypted. Private or
loopback addresses are refused unless you tick the private-network option (SSRF protection). *Test connection* writes, reads and deletes a probe file.
A backup is uploaded to every target of its profile (manifest last), verified remotely and counts as successful according to the profile's
success policy (*all targets*, *at least one*, *local only*). A failing target never harms the verified copies on other targets.
*Immutable* targets and a *lock period* (days) keep backups from being deleted by retention; S3 targets can additionally apply Object Lock
(the bucket must have it enabled; verified for single-part uploads only in the test suite).
*Discover / import* lists backups found on a target and registers them in the catalog (disaster recovery after losing the WordPress database).

## Retention
Per profile: keep the newest N, keep for N days, maximum storage, and grandfather-father-son (daily/weekly/monthly/yearly). A backup is kept if
any rule keeps it. Protected backups, the newest full backup and every base backup needed by a kept backup are never deleted; backups with
unreliable metadata are never deleted automatically. `wp sw-backupit retention run --dry-run` shows what would be deleted.

## Encryption
Settings → *Encryption keys* creates a random 256-bit key (shown **once** – store it in a password manager) or a passphrase-based key. Select the
key in the profile. Volumes, database parts and the file index are encrypted with libsodium (XChaCha20-Poly1305, streaming). The manifest stays
readable and contains no secret. Best practice: keep the key outside the database (`define( 'SWBACKUPIT_KEY_<ID>', '…' );` in wp-config.php or the
environment). Without the key a backup can be verified (checksums) but not read or restored.

## Notifications
E-mail through WordPress for success, failure, warnings, upload failures, overdue backups, low disk space and successful/failed test restores
(following the profile's notification settings). Restores in the standalone application do not send mails (WordPress is not running there).
Per-schedule notification settings are not available; schedules use their profile's settings. Identical
notifications are de-duplicated within the configured window.

## Backups
The list shows all backups with size, files, status and replication state. *Verify* checks checksums, *Browse* the file index, *Compare* shows
differences to the current installation (only in backup / only local / modified / identical / not comparable, metadata or SHA-256), *Download*
single files (authorised, never public) or the *complete recovery package* – one ZIP with the backup, every base backup it needs and the
standalone restore application (also `wp sw-backupit backup package <id>`); keep it together with the encryption key for disaster recovery, *Protect* against deletion, *Test restore* runs an isolated restore (temp directory + scratch database)
and documents the result. Deleting needs confirmation. A regular background check verifies stored backups and warns about damaged ones.

## Restore (standalone)
Restore never runs inside WordPress. In the UI choose *Restore* → *Prepare*: a copy of the restore application is placed in a directory with a random
name and protected by a one-time token (shown once, valid 1 hour). Open the shown URL (HTTPS recommended), sign in, choose the backup, confirm the
target directory and database name by typing them again, review verification and comparison, and start. Progress is saved; reload to resume. After
success the wizard locks itself and removes its files. The command line works the same way and is independent of WordPress:

```bash
php restore-app/bin/sw-backupit-restore verify /path/to/backup --deep
php restore-app/bin/sw-backupit-restore compare /path/to/backup --target=/var/www/site --mode=sha256
SWBI_DB_PASSWORD=… php restore-app/bin/sw-backupit-restore restore /path/to/backup \
  --target=/var/www/site --confirm-target=/var/www/site \
  --db-host=localhost --db-name=site --db-user=site --confirm-database=site \
  [--key=…] [--path=wp-content/uploads] [--overwrite=if_different] [--dry-run] [--tables=wp_posts,wp_postmeta]
php restore-app/bin/sw-backupit-restore fetch <backup-id> --storage=target.json --to=/path/to/backups --with-chain   # from a storage target
```

`--tables` restores only the listed tables (selective database restore; they are dropped and recreated, other tables are left alone,
views/triggers/routines are skipped; combine with `--no-files`). Files are selected with `--path`/`--pattern`.

Restore verifies the backup first, never overwrites outside the confirmed target, never follows symlinks, refuses suspicious archives and never
reports success if a phase failed. An interrupted restore continues when started again.

## Migration to another domain, subdirectory or server
Restore with URL migration: `--replace='https://alte-domain.de=>https://neue-domain.de/wordpress'` (and a path pair, e.g. old and new document root),
optionally `--new-prefix=blog_` and `--migration-dry-run`. PHP serialized values are rewritten structurally (lengths are corrected, nothing is ever
unserialized), `guid` columns stay unchanged, wp-config.php gets the new database settings. The web wizard has the same options.

## Updates and audit
Settings → *Backup before updates* creates a backup before core/plugin/theme updates – including bulk and automatic updates, but not when a
new plugin or theme is installed (a recent intact backup is reused, it waits at most 20 s, never blocks the update unless you enable *block on
failure*). The **audit log** (Settings) records who created, downloaded, deleted or restored backups and which
settings changed.

## Troubleshooting
* *Backup stuck*: a crashed worker blocks the job until its lease expires (about a minute); a watchdog restarts lost jobs.
* *Failed*: open the job log, fix the cause, press retry (`wp sw-backupit job resume <id>`).
* *Schedules do not start*: check the cron notice on the dashboard; use a system cron.
* *Staging location error*: set a directory outside the web root in Settings (directories inside the WordPress directory or the
  document root are refused unless `allow_public_paths` is set). If the staging directory has to stay inside the web root, the
  plugin writes `.htaccess`, `web.config` and `index.php` files, which only Apache and IIS honour. On **nginx** add a deny rule and
  reload nginx:

  ```nginx
  # inside the server { } block of the site
  location ~ /sw-backupit-staging- { deny all; return 404; }
  location ~ /\.swbi-restore-state- { deny all; return 404; }
  ```
* *Encrypted backup cannot be read*: provide the key (`--key`, `SWBI_KEY`, or `SWBACKUPIT_KEY_<ID>`).
* *Restore says a base backup is missing*: download the whole chain (`fetch --with-chain`) into the same directory.
