# SW-BackupIt user documentation

SW-BackupIt backs up WordPress files and MySQL/MariaDB databases. Backups are managed in WordPress; restoration uses a separate application that can run without a working WordPress installation. On multisite, administration and backup data are network-wide.

- [Getting started](./getting-started.md): requirements, installation and the first verified backup.
- [Profiles and schedules](./profiles-and-schedules.md): full, files-only, database-only, incremental and differential backups, scope, retention and automatic execution.
- [Storage and encryption](./storage-and-encryption.md): local directories, SFTP, S3-compatible targets and optional backup encryption.
- [Backups and restore](./backups-and-restore.md): browse and compare files, copy/move backups, download recovery packages, test restores and migrate a site.
- [Settings and troubleshooting](./settings-and-troubleshooting.md): staging, cron, notifications, update backups, logs and common failures.
- [Changelog](./Changelog.md): user-facing documentation changes and existing release history.

For automation and integrations, see the repository's [WP-CLI reference](https://github.com/sw-wordpress/sw-backupit/blob/main/docs/wp-cli.md), [REST API](https://github.com/sw-wordpress/sw-backupit/blob/main/docs/rest-api.md) and [hooks](https://github.com/sw-wordpress/sw-backupit/blob/main/docs/hooks.md).
