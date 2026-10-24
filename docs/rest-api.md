# REST API contract – `swbackupit/v1`

Base URL: `{wpApiRoot}swbackupit/v1` (e.g. `/wp-json/swbackupit/v1`).
Authentication: WordPress cookie session + `X-WP-Nonce: <wp_rest nonce>` on **every** request.
All bodies/responses are JSON. Timestamps are ISO-8601 UTC strings (`2026-10-24T12:00:00Z`).
Sizes are bytes. IDs of profiles/schedules/targets are integers; job and backup IDs are UUIDv4 strings.

## Error shape (standard WP_Error REST shape)

```json
{ "code": "swbi_invalid_param", "message": "Human readable (translated)", "data": { "status": 400, "params": { "name": "Required" } } }
```

Status codes: 400 validation, 401/403 auth/capability, 404 missing, 409 conflict (e.g. another job running), 429 rate limited.

## Capabilities

| Capability | Meaning |
|---|---|
| `swbi_view` | read everything except secrets |
| `swbi_run` | start/pause/resume/cancel jobs |
| `swbi_manage_profiles` | profiles + schedules CRUD |
| `swbi_manage_storage` | storage targets CRUD/test |
| `swbi_download` | download backups |
| `swbi_delete` | delete backups |
| `swbi_restore` | prepare/execute restore |
| `swbi_settings` | settings |

Administrators receive all of them by default (`manage_options` mapped on activation).

## Enumerations

* `BackupType`: `full | files | database | incremental | differential`
* `JobStatus`: `pending | preparing | scanning | exporting | archiving | encrypting | uploading | verifying | completed | paused | retry_waiting | failed | cancelled`
* `Severity`: `debug | info | notice | warning | error`
* `Frequency`: `hourly | daily | weekly | monthly | cron`
* `StorageType`: `local | sftp | s3`
* `Health`: `ok | warning | error`

## Resources

### Health – `GET /health`
```json
{
  "status": "ok|warning|error",
  "checks": [{ "id": "sodium", "label": "Sodium extension", "status": "ok|warning|error", "message": "..." }],
  "last_success": { "backup_id": "uuid", "finished_at": "..." } ,
  "last_failure": { "job_id": "uuid", "finished_at": "...", "error": "..." },
  "next_run": { "schedule_id": 1, "schedule_name": "Nightly", "at": "..." },
  "backup_count": 12,
  "storage_bytes": 123456789,
  "storage_targets": [{ "id": 1, "name": "Local", "type": "local", "status": "ok|warning|error", "message": "..." }],
  "warnings": ["string"],
  "cron": { "mode": "wp-cron|system", "wp_cron_disabled": false, "last_tick_at": "...", "notice": "WP-Cron depends on site traffic…" }
}
```

### Profiles – `GET|POST /profiles`, `GET|PUT|DELETE /profiles/{id}`, `POST /profiles/{id}/duplicate`, `POST /profiles/{id}/run`
```json
{
  "id": 1, "name": "Full", "description": "",
  "type": "full",
  "include_paths": ["wp-content/uploads"], "exclude_paths": ["wp-content/cache"],
  "include_patterns": ["**/*"], "exclude_patterns": ["**/*.log"],
  "external_paths": [],
  "db_tables_include": [], "db_tables_exclude": ["wp_actionscheduler_logs"], "db_include_foreign_tables": false,
  "compression": { "method": "deflate|store", "level": 6 },
  "encryption": { "enabled": false, "key_id": "" },
  "volume_size_mb": 256,
  "limits": { "time_budget_seconds": 20, "memory_mb": 128, "batch_rows": 1000 },
  "checksums": true,
  "storage_target_ids": [1],
  "success_policy": "all|any|local_only",
  "retention": { "max_count": 10, "max_age_days": 0, "max_storage_mb": 0, "gfs": { "daily": 7, "weekly": 4, "monthly": 6, "yearly": 0 } },
  "notifications": { "on_success": false, "on_failure": true, "on_warning": true, "recipients": ["a@b.c"] },
  "created_at": "...", "updated_at": "..."
}
```
`POST /profiles/{id}/run` → `201` + Job. `DELETE` requires query `?confirm=true`.

### Schedules – `GET|POST /schedules`, `GET|PUT|DELETE /schedules/{id}`, `POST /schedules/{id}/run`
```json
{
  "id": 1, "name": "Nightly", "profile_id": 1, "enabled": true,
  "frequency": "daily", "cron_expression": "", "timezone": "Europe/Berlin", "time_of_day": "02:30",
  "retry": { "max_attempts": 3, "backoff_seconds": 600 },
  "next_run_at": "...", "last_run_at": "...", "last_status": "completed|failed|null", "last_error": null
}
```

### Jobs – `GET /jobs`, `GET /jobs/{id}`, `POST /jobs/{id}/pause|resume|cancel`, `POST /jobs/{id}/tick`, `GET /jobs/{id}/logs`
`GET /jobs?status=running|finished|<JobStatus>&page=1&per_page=20` → `{ "items": [Job], "total": 5, "pages": 1 }` (list envelope used by all collections).

Job:
```json
{
  "id": "uuid", "profile_id": 1, "profile_name": "Full", "type": "full",
  "status": "archiving", "phase": "archiving",
  "started_at": "...", "updated_at": "...", "finished_at": null,
  "progress": {
    "percent": 42.5,
    "files_total": 1200, "files_done": 510,
    "bytes_total": 734003200, "bytes_done": 312000000, "bytes_compressed": 120000000,
    "compression_ratio": 0.38,
    "current_item": "wp-content/uploads/2026/10/a.jpg",
    "volumes_done": 2,
    "elapsed_seconds": 63, "eta_seconds": 80,
    "upload": { "bytes_done": 0, "bytes_total": 0, "rate_bps": 0 },
    "targets": [{ "target_id": 1, "name": "Local", "status": "pending|uploading|verified|failed", "error": null }]
  },
  "error": null,
  "warnings_count": 0, "retry_count": 0, "backup_id": null
}
```
`POST /jobs/{id}/tick` executes one short work unit server-side (browser-driven runner when WP-Cron is unreliable) and returns the updated Job.
`GET /jobs/{id}/logs?after=<event id>&severity=warning&phase=archiving&limit=200` → `{ "items": [{ "id": 17, "time": "...", "severity": "info", "phase": "archiving", "message": "...", "context": {} }], "last_id": 17 }`

### Backups – `GET /backups`, `GET /backups/{id}`, `DELETE /backups/{id}?confirm=<id>`
`GET /backups?profile_id=&type=&status=&search=&page=&per_page=` → list envelope.
```json
{
  "id": "uuid", "profile_id": 1, "profile_name": "Full", "type": "full",
  "status": "complete|incomplete|verified|corrupt",
  "created_at": "...", "size_bytes": 1234, "file_count": 1200, "volume_count": 3,
  "encrypted": false, "protected": false, "parent_id": null, "wp_version": "6.8", "site_url": "https://...",
  "verified_at": "...", "restore_tested_at": null,
  "locations": [{ "target_id": 1, "name": "Local", "status": "verified|failed|pending", "verified_at": "..." }]
}
```
* `POST /backups/{id}/verify` → `{ "ok": true, "checked_volumes": 3, "errors": [] }` (or a Job for long runs: `{ "job": Job }`)
* `POST /backups/{id}/protect` body `{ "protected": true }`
* `GET /backups/{id}/files?path=wp-content&search=&page=1&per_page=100` → list envelope of `{ "path": "...", "size": 1, "mtime": 0, "type": "file|dir", "volume": 1 }`
* `POST /backups/{id}/compare` body `{ "mode": "metadata|sha256" }` → `{ "only_in_backup": N, "only_local": N, "modified": N, "identical": N, "incomparable": N, "samples": { "modified": ["path", …] } }`
* `GET /backups/{id}/download?volume=1` → streamed file (`Content-Disposition: attachment`)
* `POST /backups/{id}/restore/prepare` → `{ "restore_url": "…", "token_expires_at": "…", "warnings": [] }`
* `POST /backups/{id}/restore` body `{ "confirm": "<id>", "target_path_confirmed": true }` → Job-like restore job

### Storage targets – `GET|POST /storage-targets`, `GET|PUT|DELETE /storage-targets/{id}`, `POST /storage-targets/{id}/test`, `GET /storage-targets/{id}/discover`, `POST /storage-targets/{id}/import`
```json
{
  "id": 1, "name": "Local", "type": "local|sftp|s3",
  "config": { "path": "…" } ,
  "credentials_set": true,
  "immutable": false, "retention": null,
  "status": "ok", "usage_bytes": 123, "last_checked_at": "…", "message": ""
}
```
`config` for `sftp`: `host, port, username, root`; `s3`: `endpoint, region, bucket, prefix, path_style`. Secret fields (`password`, `private_key`, `secret_key`, `access_key`) are **write-only** (sent in `credentials`, never returned; `credentials_set` indicates presence).
`POST …/test` → `{ "ok": true, "message": "…", "latency_ms": 42 }`.

### Settings – `GET|PUT /settings`
```json
{
  "staging_dir": "/path/outside/webroot/preferred",
  "limits": { "time_budget_seconds": 20, "memory_mb": 128, "batch_rows": 1000, "max_parallel_uploads": 1 },
  "encryption": { "default_key_id": "" },
  "notifications": { "recipients": ["admin@…"], "dedupe_minutes": 60 },
  "logging": { "retention_days": 30, "max_events_per_job": 5000, "level": "info" },
  "cron": { "mode": "wp-cron|system", "timezone": "Europe/Berlin" },
  "retention_default": { "max_count": 10, "max_age_days": 0, "gfs": { "daily": 7, "weekly": 4, "monthly": 6, "yearly": 0 } },
  "pre_update_backup": { "core": false, "plugins": false, "themes": false, "profile_id": null }
}
```

---

# Implementation notes (authoritative – the backend implements exactly this)

## Jobs
* `phase` is the current **step** id: `preflight | scan | export | archive | encrypt | verify_local | manifest | upload | verify_remote | finalize | retention | notify` (or `null` before the first step). `status` is the state machine value.
* Extra fields: `trigger` (`manual|schedule|cli`), `next_retry_at`.
* `POST /jobs/{id}/retry` retries a `failed` job from its checkpoints. `pause|resume|cancel|retry|tick` return the updated Job; invalid transitions return `400 swbi_error`, running parallel backups `409 swbi_conflict`.
* `GET /jobs?status=running` = all non-terminal states incl. `paused`/`retry_waiting`; `finished` = completed/failed/cancelled. `per_page` max 100.
* Job logs: `after` is the last seen `id`; the server caps a response at `limit` (max 1000) from that position (oldest first).

## Schedules
Weekly/monthly schedules additionally use `weekday` (0 = Sunday … 6) and `monthday` (1–31, clamped to the month length); all schedules use `time_of_day` (`HH:MM`) and `timezone`. `hourly` uses the minute of `time_of_day`. `POST /schedules/{id}/run` returns the started Job (`409` if another backup runs).

## Backups
* `GET /backups/{id}/download?part=volume|manifest|index|database&volume=N&_wpnonce=<rest nonce>` streams one file (`volume` is the 1-based number for `volume` and `database`).
* `POST /backups/{id}/compare` body `{ "mode": "metadata|sha256" }` → `{ only_in_backup, only_local, modified, identical, incomparable, samples: { <category>: [path…] } }` (synchronous; use the CLI for very large sites).
* `POST /backups/{id}/restore/prepare` → `{ restore_url, token, token_expires_at, target_path, warnings[] }`. **The token is shown once.** The restore itself runs in the standalone web wizard behind `restore_url` (or the restore CLI); `POST /backups/{id}/restore` always answers `501 swbi_use_wizard`.
* `POST /backups/{id}/protect` body `{ "protected": true|false }` returns the Backup. `DELETE /backups/{id}?confirm=<id>` needs the exact id; protected backups answer `409`.
* Backup `status`: `complete | verified | corrupt`.

## Storage targets
Config keys: `local`: `path`; `sftp`: `host, port, username, root, host_fingerprint, trust_unknown_host, allow_private_network, timeout`; `s3`: `endpoint, region, bucket, prefix, path_style, allow_private_network`. Credentials (write-only): `sftp`: `password, private_key, passphrase`; `s3`: `access_key, secret_key`. Updating without `credentials` keeps the stored secrets.
* `GET …/{id}/discover` → list envelope of `{ backup_id, created_at, size_bytes, type, profile_name, already_imported, error }`.
* `POST …/{id}/import` body `{ "backup_ids": [uuid…], "download": false }` → `{ "imported": n }` (registers backups in the catalog; with `download` the data is fetched into the local staging area).
* `DELETE` answers `409` while a profile uses the target. Private/loopback endpoints are refused unless `allow_private_network` is set (SSRF protection).

## Encryption keys
`GET /encryption-keys` → `{ items: [{ id, source }] }` (never secrets). `POST /encryption-keys` body `{ "id": "prod", "passphrase": "optional" }` → `201 { id, secret, warning }` – the **secret is returned once**; without a passphrase a random 256 bit key is generated. `DELETE /encryption-keys/{id}`. Profiles reference keys through `encryption: { enabled, key_id }`.

## Profile additions
`db_consistent_snapshot` (bool, single-invocation transactional snapshot, InnoDB only), `retention.delete_local_after_upload` (bool), `success_policy` `all|any|local_only`.

## Error codes
`swbi_invalid_param` (400, with `data.params`), `swbi_confirmation_required` (400), `swbi_not_found` (404), `swbi_conflict` (409), `swbi_not_implemented`/`swbi_use_wizard` (501), `swbi_storage_error` (502), `swbi_vetoed`/`swbi_integrity`/`swbi_transient`… appear in `job.error.code`.

## Corrections / clarifications
* `job.error` is `null` or an object `{ "code": "swbi_integrity", "message": "…", "retryable": false }`; `last_failure.error` in the health report is the message string.
* Backup `status` is `complete | verified | corrupt` (there is no `incomplete` state in the catalog: incomplete backups are never registered).
* There are no restore jobs: restore runs in the standalone wizard/CLI. `POST /backups/{id}/restore` always returns `501 swbi_use_wizard`.
* Encryption key ids match `^[A-Za-z0-9._-]{1,64}$`; `DELETE /encryption-keys/{id}` answers `409 swbi_conflict` while a profile uses the key.
* `part=database` downloads database part number `volume` (1-based); the number of parts equals `database_parts` in the manifest (the backup detail will expose `database_part_count` – until then use 1…n and treat 404 as the end).
* Rate-limit (429) responses are only produced by the standalone restore wizard (HTML), not by the REST API.

## Enterprise endpoints (Phase 4)
* `GET /audit?page=&per_page=&action=&object_type=&user_id=` (capability `swbi_settings`) → list envelope of `{ id, time, user_id, user, ip, action, object_type, object_id, context }`. Actions: `backup_started, backup_downloaded, backup_deleted, backup_protected, backup_verified, restore_prepared, restore_tested, profile_saved, profile_deleted, schedule_saved, schedule_deleted, storage_target_saved, storage_target_deleted, encryption_key_created, encryption_key_deleted, settings_updated, backups_imported`. Entries never contain secrets.
* `POST /backups/{id}/restore-test` (capability `swbi_restore`) → `{ status: passed|partial|failed, seconds, tested_at, checks: [{ name, status: passed|failed|skipped, detail }] }`. Runs synchronously in an isolated temp directory and scratch database; `partial` = database check skipped (no CREATE DATABASE privilege). The Backup resource gains `restore_test` (the last result or `null`) next to `restore_tested_at`.
* Settings additions: `pre_update_backup: { core, plugins, themes, profile_id, max_age_minutes, wait_seconds, block_on_failure }`, `restore_test: { enabled, interval_days }`, `logging.audit_retention_days`.
* Storage target config additions: `lock_days` (all types, retention lock in days) and `object_lock: { mode: GOVERNANCE|COMPLIANCE, days }` (s3).
