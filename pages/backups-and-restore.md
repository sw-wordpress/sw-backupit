# Backups and restore

## Inspect and preserve a backup

Open **Backups**, select an item and inspect **Overview**, **Locations** and **Files**. **Verify** checks stored checksums. **Compare → Metadata (fast)** compares size/time; **SHA-256 (thorough)** compares checksums and can take much longer. Comparison changes nothing; its sample path list is not necessarily a complete list of differences.

Use **Download → Complete recovery package (ZIP)** to obtain the backup, required base backups and standalone restore application together. Individual volumes/database/index/manifest downloads are not substitutes for a complete recovery set. Store recovery packages privately: they can contain personal data, database contents and configuration secrets. Keep encryption secrets separately and available if the original server is lost.

Enable **Protect from deletion and retention** for copies that must be preserved. Remove protection explicitly before deleting. Deletion requires confirmation and may affect several locations; manually deleting a base can make dependent incremental/differential backups unusable.

## Copy or move to another target

1. Open **Copy / move** for the backup. Select the verified source and destination **Storage target**.
2. Choose **Copy** to keep the source, or **Move** to remove the source copy only after destination verification. Moving requires deletion permission and is refused for immutable/locked sources.
3. Start the transfer and follow **Transfers** above the list. Transfers run in the background; the dialog can be closed.
4. Confirm the destination in **Locations** and check any error before removing other copies.

Missing base backups are copied along. A move never removes those bases from the source. Encrypted files remain encrypted during transfer and copying does not require the decryption key. A transfer is not a restore or a migration of the live website.

## Test recovery

Choose **Test restore → Start test** on a backup. The test restores into an isolated temporary directory and scratch database, not over the live site, but it consumes server resources. Inspect the recorded checks:

- **Passed**: the performed checks passed.
- **Partially passed**: database checking was skipped because scratch database creation was unavailable; archive/file checks alone do not prove database recovery.
- **Failed**: inspect the failed check and job/log information before relying on this backup.

In **Settings → Restore tests**, **Enable automatic restore tests** schedules checks of the newest backup. It is off by default; the default interval is seven days when enabled. A restore test is additional evidence, not a guarantee that all application behavior on a different server will work.

## Restore with a working admin interface

::: warning Destructive operation
Restore replaces selected files and database content with older data. Changes since that backup can be lost, and the site may be unavailable. Preserve the current state first and test on a separate destination where possible. Confirm the actual filesystem path and database, not just the site name.
:::

1. In **Backups**, choose **Restore**, review source/target and preflight warnings, then **Prepare restore**.
2. Copy the **Restore token** before closing the dialog; it is shown once. The prepared link is valid for one hour. Treat it as a secret and use HTTPS.
3. Choose **Open standalone restore** / **Open restore in new tab**. Restoration runs outside WordPress, not directly inside its admin UI.
4. Authenticate with the token. Select the backup and provide its encryption secret if needed.
5. Enter the intended target directory and database connection, and type the target directory and database name again to confirm them. Review verification/comparison and any migration/selection options before starting.
6. Follow progress; if interrupted, reload to resume from saved state. Do not treat a failed phase as success.
7. After completion, check the site's front end, admin login and application data. The successful web wizard locks itself and removes its files; verify that temporary restore access is no longer usable and clean up any leftover uploaded recovery data privately.

## Recover when WordPress is unavailable

Keep the complete recovery package and key off-server in advance. Extract the package in a controlled recovery location, follow its included standalone application layout and use its web wizard or CLI. The standalone tool does not need a working WordPress installation or Composer. Ensure the target PHP extensions, filesystem access and database credentials are available.

An example verification using the included application:

```sh
php restore-app/bin/sw-backupit-restore verify /path/to/backup --deep
php restore-app/bin/sw-backupit-restore compare /path/to/backup --target=/var/www/site --mode=sha256
```

These paths are examples: point them at the extracted application and actual backup directory. Remote-only backups must be fetched with all required bases into the same parent location (`fetch --with-chain`). See the full [standalone/WordPress CLI reference](https://github.com/sw-wordpress/sw-backupit/blob/main/docs/wp-cli.md) for restore, credentials, key input, dry-run and fetching options. Avoid exposing secrets in shell history or public directories.

## Selective restore and migration

Standalone restore can select files by path/pattern and database tables with `--tables` (combine with `--no-files` for tables only). Selected tables are dropped and recreated; other tables are left alone. Views/triggers/routines are skipped for selective table restores. Confirm dependencies between tables before choosing only part of an application's data.

For a new domain, subdirectory or server, configure URL/path replacements, target database settings and an optional new table prefix in the wizard or CLI. For example, a replacement pair is `https://old.example=>https://new.example/wordpress`. Use a migration dry-run before writing. Serialized PHP values are rewritten structurally and `guid` columns are left unchanged; wp-config.php receives the new database settings. Review the restored site and integrations afterward rather than assuming every external service was migrated. See [format and migration details](https://github.com/sw-wordpress/sw-backupit/blob/main/docs/backup-format.md).
