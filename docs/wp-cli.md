# WP-CLI

All commands exit with `0` on success and non-zero on failure. Use `--format=json` where available.

```bash
wp swbackupit profile list [--format=json]
wp swbackupit profile show <id|name>
wp swbackupit backup create --profile=<id|name> [--background] [--format=json]   # runs to completion in the CLI process
wp swbackupit backup list [--format=json]
wp swbackupit backup status <job-id|backup-id>
wp swbackupit backup verify <backup-id> [--deep]
wp swbackupit job resume <job-id>        # resumes paused/failed jobs
wp swbackupit job cancel <job-id>
wp swbackupit schedule list
wp swbackupit schedule run <schedule-id>
wp swbackupit schedule run-due           # for a real system cron
wp swbackupit catalog rebuild            # rebuild the catalog from manifests (disaster recovery)
```

The CLI is not subject to web time limits and is the recommended way to run backups on dedicated servers. For reliable
schedules add a system cron job and set `define( 'DISABLE_WP_CRON', true );`:

```cron
* * * * * cd /var/www/html && wp swbackupit schedule run-due --quiet
```

Restore is performed by the standalone application (`restore-app/bin/swbackupit-restore`), see the user guide.
