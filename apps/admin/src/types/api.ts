/**
 * Types mirroring docs/rest-api.md (`sw-backupit/v1`). Keep in sync with the contract.
 * Timestamps are ISO-8601 UTC strings, sizes are bytes.
 */

export type BackupType = 'full' | 'files' | 'database' | 'incremental' | 'differential';
export type JobStatus =
  | 'pending'
  | 'preparing'
  | 'scanning'
  | 'exporting'
  | 'archiving'
  | 'encrypting'
  | 'uploading'
  | 'verifying'
  | 'completed'
  | 'paused'
  | 'retry_waiting'
  | 'failed'
  | 'cancelled';
export type Severity = 'debug' | 'info' | 'notice' | 'warning' | 'error';
export type Frequency = 'hourly' | 'daily' | 'weekly' | 'monthly' | 'cron';
export type StorageType = 'local' | 'sftp' | 's3';
export type Health = 'ok' | 'warning' | 'error';
export type BackupStatus = 'complete' | 'verified' | 'corrupt';
export type SuccessPolicy = 'all' | 'any' | 'local_only';
export type CompressionMethod = 'deflate' | 'store';
export type TargetUploadStatus = 'pending' | 'uploading' | 'verified' | 'failed';
export type CompareMode = 'metadata' | 'sha256';
export type JobTrigger = 'manual' | 'schedule' | 'cli';
export type DownloadPart = 'volume' | 'manifest' | 'index' | 'database';
/** Step ids reported in `job.phase`. */
export type JobStep =
  | 'preflight'
  | 'scan'
  | 'export'
  | 'archive'
  | 'encrypt'
  | 'verify_local'
  | 'manifest'
  | 'upload'
  | 'verify_remote'
  | 'finalize'
  | 'retention'
  | 'notify';

/** Standard list envelope used by all collections. */
export interface Paginated<T> {
  items: T[];
  total: number;
  pages: number;
}

export interface HealthCheck {
  id: string;
  label: string;
  status: Health;
  message: string;
}

export interface HealthReport {
  status: Health;
  checks: HealthCheck[];
  last_success: { backup_id: string; finished_at: string } | null;
  last_failure: { job_id: string; finished_at: string; error: string } | null;
  next_run: { schedule_id: number; schedule_name: string; at: string } | null;
  backup_count: number;
  storage_bytes: number;
  storage_targets: Array<{
    id: number;
    name: string;
    type: StorageType;
    status: Health;
    message: string;
  }>;
  warnings: string[];
  cron: {
    mode: 'wp-cron' | 'system';
    wp_cron_disabled: boolean;
    last_tick_at: string | null;
    notice: string;
  };
}

export interface RetentionGfs {
  daily: number;
  weekly: number;
  monthly: number;
  yearly: number;
}

export interface Retention {
  max_count: number;
  max_age_days: number;
  max_storage_mb: number;
  delete_local_after_upload: boolean;
  gfs: RetentionGfs;
}

export interface Profile {
  id: number;
  name: string;
  description: string;
  type: BackupType;
  include_paths: string[];
  exclude_paths: string[];
  include_patterns: string[];
  exclude_patterns: string[];
  external_paths: string[];
  db_tables_include: string[];
  db_tables_exclude: string[];
  db_include_foreign_tables: boolean;
  db_consistent_snapshot: boolean;
  compression: { method: CompressionMethod; level: number };
  encryption: { enabled: boolean; key_id: string };
  volume_size_mb: number;
  limits: { time_budget_seconds: number; memory_mb: number; batch_rows: number };
  checksums: boolean;
  storage_target_ids: number[];
  success_policy: SuccessPolicy;
  retention: Retention;
  notifications: {
    on_success: boolean;
    on_failure: boolean;
    on_warning: boolean;
    recipients: string[];
  };
  created_at: string;
  updated_at: string;
}

/** Writable subset sent on create/update. */
export type ProfileInput = Omit<Profile, 'id' | 'created_at' | 'updated_at'>;

export interface Schedule {
  id: number;
  name: string;
  profile_id: number;
  enabled: boolean;
  frequency: Frequency;
  cron_expression: string;
  timezone: string;
  time_of_day: string;
  /** 0 = Sunday … 6; used by weekly schedules. */
  weekday: number;
  /** 1–31 (clamped to month length); used by monthly schedules. */
  monthday: number;
  retry: { max_attempts: number; backoff_seconds: number };
  next_run_at: string | null;
  last_run_at: string | null;
  last_status: 'completed' | 'failed' | null;
  last_error: string | null;
}

export type ScheduleInput = Omit<
  Schedule,
  'id' | 'next_run_at' | 'last_run_at' | 'last_status' | 'last_error'
>;

export interface JobTargetProgress {
  target_id: number;
  name: string;
  status: TargetUploadStatus;
  error: string | null;
}

export interface JobProgress {
  percent: number;
  files_total: number;
  files_done: number;
  bytes_total: number;
  bytes_done: number;
  bytes_compressed: number;
  compression_ratio: number;
  current_item: string | null;
  volumes_done: number;
  elapsed_seconds: number;
  eta_seconds: number | null;
  upload: { bytes_done: number; bytes_total: number; rate_bps: number };
  targets: JobTargetProgress[];
}

export interface Job {
  id: string;
  profile_id: number;
  profile_name: string;
  /** Backup type; restore jobs may report another value (see README, contract gaps). */
  type: string;
  status: JobStatus;
  /** Current step id (see JobStep) or null before the first step. */
  phase: string | null;
  trigger: JobTrigger;
  next_retry_at: string | null;
  started_at: string;
  updated_at: string;
  finished_at: string | null;
  progress: JobProgress;
  /** Plain message, or `{code, message}` (codes such as swbi_vetoed, swbi_integrity). */
  error: string | { code?: string; message?: string; retryable?: boolean } | null;
  warnings_count: number;
  retry_count: number;
  backup_id: string | null;
}

export interface JobLogEntry {
  id: number;
  time: string;
  severity: Severity;
  phase: string;
  message: string;
  context: Record<string, unknown>;
}

export interface JobLogPage {
  items: JobLogEntry[];
  last_id: number;
}

export type JobFilter = 'running' | 'finished' | JobStatus;

export interface BackupLocation {
  target_id: number;
  name: string;
  status: 'verified' | 'failed' | 'pending';
  verified_at: string | null;
}

export interface Backup {
  id: string;
  profile_id: number;
  profile_name: string;
  type: BackupType;
  status: BackupStatus;
  created_at: string;
  size_bytes: number;
  file_count: number;
  volume_count: number;
  encrypted: boolean;
  protected: boolean;
  parent_id: string | null;
  wp_version: string;
  site_url: string;
  verified_at: string | null;
  restore_tested_at: string | null;
  /** Last stored restore-test result or null. */
  restore_test: RestoreTestResult | null;
  /** Number of database dump parts (may be absent on older servers). */
  database_part_count?: number;
  locations: BackupLocation[];
}

export type TransferStatus = 'queued' | 'running' | 'completed' | 'failed' | 'cancelled';

/** Copy/move of a backup (and missing base backups) to another storage target. */
export interface Transfer {
  id: string;
  backup_id: string;
  mode: 'copy' | 'move';
  status: TransferStatus;
  error: string | null;
  /** 0 = local staging. */
  source_id: number;
  source_name: string;
  target_id: number;
  target_name: string;
  /** Backups transferred, base backups first. */
  backups: string[];
  bytes_total: number;
  bytes_done: number;
  created_at: string;
  updated_at: string;
}

export interface TransferInput {
  target_id: number;
  /** null = local copy when available, else a verified storage target. */
  source_id: number | null;
  move: boolean;
}

export interface BackupFilters {
  profile_id?: number;
  type?: BackupType;
  status?: BackupStatus;
  search?: string;
  page?: number;
  per_page?: number;
}

export type VerifyResult =
  { ok: boolean; checked_volumes: number; errors: string[] } | { job: Job };

export interface ArchiveEntry {
  path: string;
  size: number;
  mtime: number;
  type: 'file' | 'dir';
  volume: number;
}

export interface CompareResult {
  only_in_backup: number;
  only_local: number;
  modified: number;
  identical: number;
  incomparable: number;
  /** Category (e.g. modified, only_in_backup, only_local) to a sample of paths. */
  samples: Record<string, string[]>;
}

export type RestoreTestStatus = 'passed' | 'partial' | 'failed';
export interface RestoreTestCheck {
  name: string;
  status: 'passed' | 'failed' | 'skipped';
  detail: string;
}
export interface RestoreTestResult {
  status: RestoreTestStatus;
  seconds: number;
  tested_at: string;
  checks: RestoreTestCheck[];
}

export interface AuditEntry {
  id: number;
  time: string;
  user_id: number;
  user: string;
  ip: string;
  action: string;
  object_type: string;
  object_id: string;
  context: Record<string, unknown>;
}

export const AUDIT_ACTIONS = [
  'backup_started',
  'backup_downloaded',
  'backup_deleted',
  'backup_protected',
  'backup_verified',
  'restore_prepared',
  'restore_tested',
  'profile_saved',
  'profile_deleted',
  'schedule_saved',
  'schedule_deleted',
  'storage_target_saved',
  'storage_target_deleted',
  'encryption_key_created',
  'encryption_key_deleted',
  'settings_updated',
  'backups_imported',
] as const;

export interface ObjectLock {
  mode: 'GOVERNANCE' | 'COMPLIANCE';
  days: number;
}
export type StorageConfigValue = string | number | boolean | ObjectLock;
export type StorageConfig = Record<string, StorageConfigValue>;

export interface RestorePrepareResult {
  restore_url: string;
  /** Shown to the user exactly once; never stored by the UI. */
  token: string;
  token_expires_at: string;
  target_path: string;
  warnings: string[];
}

export interface StorageTarget {
  id: number;
  name: string;
  type: StorageType;
  /** local: `path`; sftp: `host, port, username, root`; s3: `endpoint, region, bucket, prefix, path_style`. */
  config: StorageConfig;
  credentials_set: boolean;
  immutable: boolean;
  retention: Retention | null;
  status: Health;
  usage_bytes: number | null;
  last_checked_at: string | null;
  message: string;
}

export interface StorageTargetInput {
  name: string;
  type: StorageType;
  config: StorageConfig;
  /** Write-only secrets (`password`, `private_key`, `secret_key`, `access_key`); omitted when unchanged. */
  credentials?: Record<string, string>;
  immutable: boolean;
  retention: Retention | null;
}

export interface StorageTestResult {
  ok: boolean;
  message: string;
  latency_ms: number;
}

export interface DiscoveredBackup {
  backup_id: string;
  created_at: string;
  size_bytes: number;
  type: BackupType | null;
  profile_name: string | null;
  already_imported: boolean;
  /** Set when the remote entry could not be read. */
  error: string | null;
}

export interface EncryptionKey {
  id: string;
  source: string;
}

export interface CreatedEncryptionKey {
  id: string;
  /** Returned once. */
  secret: string;
  warning: string;
}

export interface Settings {
  staging_dir: string;
  limits: {
    time_budget_seconds: number;
    memory_mb: number;
    batch_rows: number;
    max_parallel_uploads: number;
  };
  encryption: { default_key_id: string };
  notifications: { recipients: string[]; dedupe_minutes: number };
  logging: {
    retention_days: number;
    max_events_per_job: number;
    level: Severity;
    audit_retention_days: number;
  };
  restore_test: { enabled: boolean; interval_days: number };
  cron: { mode: 'wp-cron' | 'system'; timezone: string };
  retention_default: { max_count: number; max_age_days: number; gfs: RetentionGfs };
  pre_update_backup: {
    core: boolean;
    plugins: boolean;
    themes: boolean;
    profile_id: number | null;
    max_age_minutes: number;
    wait_seconds: number;
    block_on_failure: boolean;
  };
}
