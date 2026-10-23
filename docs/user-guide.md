# User guide

## Installation
Upload the plugin ZIP (Plugins → Add New → Upload) and activate it. Open **SWBackupIt** in the admin menu. The dashboard
shows warnings (missing PHP extensions, staging directory location, WP-Cron reliability).

## Backup profiles
A profile defines what is backed up: type (Full, Files only, Database only), directories/patterns to include or exclude,
database tables, compression, volume size, resource limits, retention and notifications. A running job always uses a snapshot of
the profile as it was when the job started. Use *Run now* for a manual backup; progress, phase and logs are shown live. Jobs can be
paused, resumed and cancelled. Only one backup runs at a time.

## Schedules
Hourly, daily, weekly, monthly or a cron expression, in a chosen timezone (DST-safe). WP-Cron needs site visitors; for exact
timing use a system cron job (`wp swbackupit schedule run-due`, see `wp-cli.md`). Failed scheduled runs are retried with backoff.

## Backups
The list shows all backups with size, files and status. *Verify* checks checksums of all volumes. *Browse* the file index,
*Download* single volumes (authorised, never public), *Protect* a backup against deletion. Deleting needs confirmation.

## Restore (standalone)
Restore does not need WordPress. Copy the backup directory and the `restore-app` folder (inside the plugin) to the target server:
```bash
php restore-app/bin/swbackupit-restore verify /path/to/backup --deep
SWBI_DB_PASSWORD=… php restore-app/bin/swbackupit-restore restore /path/to/backup \
  --target=/var/www/site --confirm-target=/var/www/site \
  --db-host=localhost --db-name=site --db-user=site --confirm-database=site
```
Options: `--overwrite=always|never|if_different`, `--path=` / `--pattern=` for selective restore, `--dry-run`,
`--no-database`, `--no-files`. An interrupted restore continues when the same command is run again.
Restore verifies the backup first and never reports success if a phase failed.

## Migration to another domain/server
Planned for the next release (URL/path migration with serialized-data safety).

## Encryption, notifications
Planned (next release). Until then protect the staging directory and downloaded backups yourself.

## Troubleshooting
* *Backup stuck*: a crashed worker blocks the job until its lease expires (about a minute); then it continues.
* *Failed*: open the job log, fix the cause, press retry (`wp swbackupit job resume <id>`); it continues from its checkpoints.
* *Schedules do not start*: check the cron notice on the dashboard; use a system cron.
* *Staging warnings*: set a directory outside the web root in Settings.
