# Profiles and schedules

A profile defines backup contents and handling. A schedule decides when to run that profile. Running jobs use a snapshot of the profile: later edits do not change a job already in progress. Only one backup runs at a time.

![Profiles list with Content update (uploads, themes, plugins) and Full backup (everything, without cache), each showing zero storage targets and a Run now button.](/screenshots/sw-backupit-profiles.png)

The real demo shows two sample profiles before any backup or external target has been configured. Its displayed retention values are example configuration, not universal defaults or evidence of a successful backup.

## Create or adjust a profile

Open **Profiles → Create profile** (or edit an existing profile). Work through the wizard, review the summary and save. Choose a descriptive name and the appropriate type:

| Type | Contents and dependencies |
| --- | --- |
| Full | Files and database within the selected scope. |
| Files only | Selected files, without a database export. |
| Database only | Selected database tables, without site files. |
| Incremental | Files changed since the preceding backup; depends on the backup chain. |
| Differential | Files changed since the last full backup; depends on that base. |

Incremental/differential runs need a usable base; without a base the normal first run creates a full backup. Check failures rather than assuming a new base was produced. Their database export is complete, not a row-level delta. Preserve every required base when downloading, moving or restoring a chain.

### Scope

Paths are relative to the WordPress directory, for example `wp-content/uploads`. Empty **Paths to include** means the whole WordPress directory. Default cache exclusions are `wp-content/cache`, `wp-content/uploads/cache`, `wp-content/et-cache`, `wp-content/litespeed` and `wp-content/upgrade`; staging is also excluded. Use include/exclude patterns to refine scope. **External paths** is an explicit advanced choice for directories outside WordPress: review private data before including them.

An empty **Tables to include** means tables with the WordPress prefix, not every table in a shared database. Review exclusions and **Also back up tables without the WordPress prefix** carefully. The default content-update profile includes uploads, themes, plugins and the database; it is not a substitute for an independent full backup.

Files and the database do not form an atomic snapshot of each other. The advanced consistent-database-snapshot option applies to InnoDB, cannot resume and is intended for CLI execution. See [backup format and consistency](https://github.com/sw-wordpress/sw-backupit/blob/main/docs/backup-format.md).

### Packaging and limits

New profiles start with Deflate level 6, 256 MB volumes, SHA-256 file checksums enabled and encryption disabled. Each work unit defaults to 20 seconds, a 128 MB memory limit and 1,000 rows per database batch. Keep the time budget below PHP's execution limit; reduce resource values when your host terminates jobs. Lower values can make jobs take longer.

Select [storage targets, a success policy and encryption key](./storage-and-encryption.md) before saving. With no extra targets the backup remains local; remote storage still requires local staging space.

## Retention

Set **Maximum count**, **Maximum age**, **Maximum storage** and optional **Grandfather-father-son (GFS)** daily/weekly/monthly/yearly slots. `0` disables that rule; a backup is retained if any rule keeps it. The new-profile editor starts with a count of 10 and other retention rules disabled; inspect each saved profile rather than assuming global presets were copied unchanged.

Retention preserves protected backups, the newest full backup and base backups required by retained backups. Unreliable metadata is not a reason for automatic deletion. Immutable targets and lock periods also prevent deletion, so limits do not guarantee a hard storage ceiling. Review proposed deletion without changing anything:

```sh
wp sw-backupit retention run --dry-run
```

## Schedule a profile

1. Open **Schedules → Create schedule**, name it and select the saved profile.
2. Choose **Hourly**, **Daily**, **Weekly**, **Monthly** or **Cron expression**. Set the time and **Time zone** explicitly.
3. Configure **Maximum attempts** and **Wait between attempts** for failed scheduled runs. Manual schedule runs are not retried automatically.
4. Save, enable the schedule and check **Next run**. Check **Last run** and the corresponding job after it becomes due.

Cron expressions have five fields: minute, hour, day of month, month, day of week; for example `30 2 * * *` means 02:30 daily in the selected timezone. For monthly days beyond the end of a shorter month, the UI uses that month's last day. Fixed daily times run once through the repeated autumn hour; hourly schedules follow real elapsed hours.

WP-Cron is traffic-driven. On quiet sites or when `DISABLE_WP_CRON` is set, arrange a real system cron; switching **Cron mode** alone does not install one. See [cron setup and diagnostics](./settings-and-troubleshooting.md#cron-and-job-progress). Schedule notifications come from the profile, not independent per-schedule settings.
