/**
 * Types mirroring docs/rest-api.md (`swbackupit/v1`). Keep in sync with the contract.
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
export type BackupStatus = 'complete' | 'incomplete' | 'verified' | 'corrupt';
export type SuccessPolicy = 'all' | 'any' | 'local_only';
export type CompressionMethod = 'deflate' | 'store';
export type TargetUploadStatus = 'pending' | 'uploading' | 'verified' | 'failed';
export type CompareMode = 'metadata' | 'sha256';

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
  /** Phase id; usually equals a JobStatus value, see PHASES in utils/jobs.ts. */
  phase: string;
  started_at: string;
  updated_at: string;
  finished_at: string | null;
  progress: JobProgress;
  error: string | null;
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
  locations: BackupLocation[];
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
  samples: Partial<Record<'modified' | 'only_in_backup' | 'only_local', string[]>>;
}

export interface RestorePrepareResult {
  restore_url: string;
  token_expires_at: string;
  warnings: string[];
}

export interface StorageTarget {
  id: number;
  name: string;
  type: StorageType;
  /** local: `path`; sftp: `host, port, username, root`; s3: `endpoint, region, bucket, prefix, path_style`. */
  config: Record<string, string | number | boolean>;
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
  config: Record<string, string | number | boolean>;
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

/** ASSUMED shape (not specified in docs/rest-api.md). */
export interface DiscoveredBackup {
  backup_id: string;
  created_at: string;
  size_bytes: number;
  type?: BackupType;
  profile_name?: string;
  already_imported?: boolean;
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
  logging: { retention_days: number; max_events_per_job: number; level: Severity };
  cron: { mode: 'wp-cron' | 'system'; timezone: string };
  retention_default: Omit<Retention, 'max_storage_mb'> & { max_storage_mb?: number };
  pre_update_backup: {
    core: boolean;
    plugins: boolean;
    themes: boolean;
    profile_id: number | null;
  };
}
