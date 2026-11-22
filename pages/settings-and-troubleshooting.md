# Settings and troubleshooting

## Staging and privacy

**Settings → Staging directory** controls local backup work files. The plugin tries a private directory beside WordPress; if unavailable it can propose a path under wp-content, but public locations are refused for writes by default. Choose a writable absolute path outside both WordPress and the web server document root, with no `..` segments. On shared hosting, ask the provider for a private path and sufficient disk space.

Backups can include wp-config.php, passwords, personal data and complete database records. Restrict hosting/storage access, choose sensible retention, and keep recovery copies and secrets out of public downloads. Encryption does not replace access control. The technical `allow_public_paths` override is not a recommended fix: `.htaccess` and IIS rules do not protect nginx. Server-specific access controls and risks are described in the [existing user guide](https://github.com/sw-wordpress/sw-backupit/blob/main/docs/user-guide.md#troubleshooting) and [security guide](https://github.com/sw-wordpress/sw-backupit/blob/main/docs/security.md).

## Cron and job progress

The default **Cron mode** is **WP-Cron (traffic driven)**. It checks schedules and advances jobs; little traffic can delay execution. The watchdog can restart a lost worker after its lease expires (about a minute). **Jobs → Drive job from this browser** can trigger work units while that tab is visible, but stops when the tab closes and is not a permanent scheduling solution.

For **System cron (recommended)**, have the hosting administrator install a real scheduled command every minute and coordinate `DISABLE_WP_CRON` with the site's other cron needs. For example:

```text
* * * * * wp sw-backupit schedule run-due --path=/path/to/wordpress
```

Run it as the appropriate hosting user and replace the path. Select system mode in the plugin only when the external runner exists. See [WP-CLI and background tasks](https://github.com/sw-wordpress/sw-backupit/blob/main/docs/wp-cli.md) for automation. Check the dashboard warning, next due time, timezone and latest job after setup.

## Notifications, logs and updates

- Set global notification recipients in **Settings**; profiles can define their own recipients and success/failure/warning choices. New profiles default to failure and warning emails on, success off. Delivery uses WordPress mail: configure and test your site's mail transport. Identical notifications are suppressed for 60 minutes by default.
- **Logging** defaults to level `info`, 30-day job log retention and at most 5,000 events per job. **Audit log retention** defaults to 365 days (`0` means forever). Audit records include actor/action information; review access and retention for your privacy needs. Do not publish unreviewed technical logs.
- **Backup before updates** is off by default for core, plugins and themes. Enable the desired update types and select a profile. It applies to manual, bulk and automatic updates, not fresh plugin/theme installs. An intact backup younger than the default maximum age of 60 minutes may be reused; the default wait is at most 20 seconds.
- **Block the update if the backup fails** is off by default. Enabling it can prevent security updates when backups fail. Monitor backups before adopting that policy. Standalone restores do not send WordPress email notifications because WordPress is not running there.

## Common problems

| Symptom | What to check |
| --- | --- |
| No menu or an action is unavailable | Use the correct administrator/backup permission; on multisite open Network Admin. Check PHP/WordPress requirements and that you installed the built release, not a source archive. |
| No scheduled backup | Schedule enabled, correct profile/timezone, **Next run**, dashboard cron warning and functioning WP/system cron. A saved schedule alone does not run the server's cron. |
| Job stops progressing | Wait about a minute for the lease/watchdog. Open **Jobs**, inspect phase/log and available **Pause**, **Resume** or **Retry** actions. Fix the cause before retrying a failed job. Browser driving helps only while the tab remains visible. |
| Public staging error or permission denied | Select a private writable absolute path. Check hosting ownership/permissions and disk space rather than making the backup directory public. |
| Memory/time limit errors | Review profile/global work-unit limits and database batch size; reduce them to fit hosting limits. Remote uploads still need local staging space. |
| Storage connection fails | Review host/endpoint, bucket/root, permissions and credentials; run **Test connection**. Check SFTP fingerprint and deliberate private-network policy. Do not disable verification blindly. |
| Backup completed but one remote copy failed | Inspect **Locations**, upload log and the profile's **Success policy**. A permissive policy can report success without every remote target succeeding. |
| Encrypted content will not open | Supply the original secret for that key ID. A checksum verification without a key does not prove decryption. Lost secrets cannot be recovered. |
| Missing base backup during restore | Keep all bases with the selected incremental/differential backup; use `fetch --with-chain` or a complete recovery package. |
| Restore test is only partially passed | Database check was skipped; inspect scratch database privileges. Test in isolation, never grant broad production privileges merely to turn the badge green. |
| No email | Check profile notification choices, recipients, deduplication and WordPress mail delivery. Restore-app execution itself does not send mail. |

For support, include plugin/PHP/WordPress versions, the operation and a sanitized error/phase. Remove tokens, keys, credentials, database records and personal paths before sharing an [issue](https://github.com/sw-wordpress/sw-backupit/issues).

## Deactivation and removal

Deactivation stops background tasks and removes prepared restore wizards but keeps profiles, settings and backups. Deletion also keeps stored plugin data unless the technical `delete_data_on_uninstall` setting was enabled (off by default, not exposed in the admin UI). When enabled, uninstall removes plugin tables/settings/keys/capabilities; backups in staging and on storage targets are never automatically deleted. On multisite this is network-wide. Preserve recovery packages and keys before removal and explicitly manage remaining sensitive backup files.
