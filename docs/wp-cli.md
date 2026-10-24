# WP-CLI

All commands exit with `0` on success and non-zero on failure. Use `--format=json` where available.

```bash
# Profiles
wp swbackupit profile list [--format=json]
wp swbackupit profile show <id|name>

# Backups
wp swbackupit backup create --profile=<id|name> [--background] [--format=json]   # runs to completion in the CLI process
wp swbackupit backup list [--format=json]
wp swbackupit backup status <job-id|backup-id>
wp swbackupit backup verify <backup-id> [--deep]
wp swbackupit backup compare <backup-id> [--mode=metadata|sha256] [--format=json]
wp swbackupit backup test-restore <backup-id>                                    # isolated test restore (temp dir + scratch database)
wp swbackupit backup restore <backup-id> --confirm-target=<ABSPATH> [--no-database] \
        [--confirm-database=<db name>] [--overwrite=always|never|if_different] [--key=…]   # destructive, needs confirmations

# Jobs and schedules
wp swbackupit job resume <job-id>        # resumes paused/failed jobs
wp swbackupit job cancel <job-id>
wp swbackupit schedule list
wp swbackupit schedule run <schedule-id>
wp swbackupit schedule run-due           # for a real system cron

# Storage, retention, catalog
wp swbackupit storage list
wp swbackupit storage test <id>
wp swbackupit retention run [--dry-run] [--format=json]
wp swbackupit catalog rebuild            # rebuild the catalog from manifests in the staging directory
```

`backup restore` refuses to run unless the target directory (and, when the database is restored, its name) is typed as confirmation.
The restore of a different site/domain, URL migration and the web wizard belong to the standalone restore application
(`restore-app/bin/swbackupit-restore`, see the user guide), which does not need WP-CLI.

For reliable schedules add a system cron job and set `define( 'DISABLE_WP_CRON', true );`:

```cron
* * * * * cd /var/www/html && wp swbackupit schedule run-due --quiet
```
