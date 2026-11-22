# Getting started

## Requirements and access

- WordPress 6.4 or newer; PHP 8.2 or newer; MySQL or MariaDB.
- PHP extensions `zip`, `zlib` and `pdo_mysql`; `sodium` for encrypted backups.
- A writable staging directory with enough space to create the backup before uploading it elsewhere.
- An administrator account with the relevant backup permissions. On multisite, use a network administrator: backups cover the network, not an isolated subsite.

The installable release includes PHP dependencies and the compiled admin interface; Composer and Node.js are not required on the hosting server. The interface follows the WordPress user language (English, German, French, Italian or Polish); this guide uses English UI labels.

## Install the plugin

1. Download the installable `sw-backupit-<version>.zip` from [GitHub Releases](https://github.com/sw-wordpress/sw-backupit/releases). Do not use the automatically generated source-code ZIP as an installable release.
2. In WordPress, choose **Plugins → Add New → Upload Plugin**, upload the release ZIP and activate it. For multisite, network-activate it and use **Network Admin**.
3. Open **sw-backupit → Dashboard**. Resolve missing-extension, staging, free-space and cron warnings before relying on backups.
4. In **Settings → Staging directory**, check the resolved directory. Choose an absolute directory outside both the WordPress directory and the public web root. Ask your host for a writable private path if needed. Saving a public path is refused by default.

## Make and check the first backup

![SW-BackupIt dashboard showing no successful backup, zero backups, no scheduled run and no storage targets in the initial demo state.](/screenshots/sw-backupit-dashboard.png)

This real demo is not configured yet. Its green health indicator reports environment checks, not a tested recovery: **Last successful backup** is **Never**. Create, verify and test a backup using the steps below.

1. Open **Profiles**. Activation creates **Full backup (everything, without cache)** and **Content update (uploads, themes, plugins)**.
2. Open the full profile and review its scope and retention. The full profile includes the WordPress directory and database, excluding cache directories and backup staging. Tables outside the WordPress prefix need explicit inclusion; files outside WordPress are not automatically included.
3. Click **Run now** for **Full backup (everything, without cache)**. Use the profile's configured targets, choose another target for this run, or select local-only storage when offered; the one-run choice does not edit the profile.
4. Follow progress in **Jobs**. Wait for successful completion and inspect any warning or target failure; a started job is not yet a usable backup.
5. Open the resulting item in **Backups**, click **Verify**, then inspect **Files** to check that expected content is present.
6. Run **Test restore** and inspect its result. **Partially passed** is not a full database restore test: see [restore testing](./backups-and-restore.md#test-recovery).
7. Download **Complete recovery package (ZIP)** and keep it in a private location away from this server. If encrypted, preserve the secret separately. The package contains the backup, required bases and the standalone restore application, not a replacement copy of your secret key.

Next, [add storage and encryption](./storage-and-encryption.md), then [create a schedule](./profiles-and-schedules.md#schedule-a-profile). A local backup alone does not protect against loss of the hosting server.
