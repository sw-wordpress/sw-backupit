# Events and hooks

All hooks are **observation points with a defined failure policy** (CODE-STYLE §3.3, PLAN §17). A hook can never leave a backup
in an inconsistent state: events fire only after the persistent state reached a consistent point, and listener errors are
caught, logged and – except for explicit vetoes – ignored.

| Core event | WordPress action | When | May veto? |
|---|---|---|---|
| `before_backup` | `swbi_before_backup` (+ filter `swbi_allow_before_backup`) | preflight passed, before any data is touched | yes (clean failure `swbi_vetoed`) |
| `after_backup` | `swbi_after_backup` | job saved as completed | no |
| `backup_failed` | `swbi_backup_failed` | job saved as failed (also when the watchdog stops a job stalled for 48 h) | no |
| `before_database_export` / `after_database_export` | `swbi_before_database_export` (+ filter `swbi_allow_…`) / `swbi_after_database_export` | around the database step | before: yes |
| `before_upload` / `after_upload` | `swbi_before_upload` (+ filter) / `swbi_after_upload` | around uploads to storage targets | before: yes |
| `before_restore` / `after_restore` / `restore_failed` | `swbi_before_restore` (+ filter) / … | standalone restore engine | before: yes |
| `before_step` / `after_step` / `step_failed` | `swbi_before_step` … | around every job step (`step` in the payload) | never |

Payloads are read-only arrays (`job_id`, `backup_id`, `step`, …). Additional WordPress actions for audit purposes:
`swbi_backup_downloaded`, `swbi_backup_deleted`, `swbi_settings_updated`, `swbi_storage_target_saved`, `swbi_encryption_key_created`,
`swbi_encryption_key_deleted`, `swbi_restore_prepared`, `swbi_backups_imported`, `swbi_backup_transfer_started` (backup id, mode, target id),
`swbi_backup_transferred` (backup id, mode, target id), `swbi_backup_transfer_failed` (backup id, message).

## Failure policy

* `FailurePolicy::Blocking` listeners are only accepted for `before_*` events (registering one for another event throws).
  A blocking listener that throws (or a filter returning `false`/`WP_Error`) fails the job cleanly *before* state changes.
* `FailurePolicy::Isolated` (default): exceptions are logged as warnings and swallowed. In WordPress they go to the plugin
  logger: the PHP error log (prefix `[sw-backupit]`) and the action `swbi_log( string $level, string $message, array $context )`
  (scalar context only, no secrets), so monitoring plugins can pick them up.
* No hook can run shell commands from the web UI; there is no such configuration.

```php
// Veto backups during a maintenance window.
add_filter( 'swbi_allow_before_backup', fn( $allowed ) => is_maintenance_window() ? new WP_Error( 'swbi', 'Maintenance window' ) : $allowed );
// React after a backup (isolated).
add_action( 'swbi_after_backup', function ( array $payload ) { /* notify, ping monitoring … */ } );
```

Framework independent core usage: `EventDispatcher::listen( Events::BEFORE_BACKUP, $callback, FailurePolicy::Blocking )`.
