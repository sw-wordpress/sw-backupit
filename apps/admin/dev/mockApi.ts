/**
 * In-memory implementation of docs/rest-api.md for development and screenshots.
 * Only imported by vite.config.ts (dev server middleware), never by `src/`, so it cannot
 * end up in the production bundle.
 */
import type {
  ArchiveEntry,
  Backup,
  BackupType,
  HealthReport,
  Job,
  JobLogEntry,
  JobStatus,
  Profile,
  Schedule,
  Settings,
  Severity,
  StorageTarget,
} from '../src/types/api';

export interface MockResponse {
  status: number;
  body: unknown;
}
export interface MockRequest {
  method: string;
  path: string;
  query: URLSearchParams;
  body: Record<string, unknown>;
}

const MIN = 60_000;
const HOUR = 3_600_000;
const DAY = 86_400_000;
const iso = (ms: number): string => new Date(ms).toISOString().replace(/\.\d{3}Z$/, 'Z');
const START = Date.now();

function rng(seed: number): () => number {
  let a = seed;
  return () => {
    a |= 0;
    a = (a + 0x6d2b79f5) | 0;
    let t = Math.imul(a ^ (a >>> 15), 1 | a);
    t = (t + Math.imul(t ^ (t >>> 7), 61 | t)) ^ t;
    return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
  };
}
const rand = rng(42);
const uuid = (): string =>
  'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = Math.floor(rand() * 16);
    return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
  });

function wpError(
  status: number,
  code: string,
  message: string,
  params?: Record<string, string>,
): MockResponse {
  return { status, body: { code, message, data: { status, params: params ?? {} } } };
}

// ---------------------------------------------------------------- seed data
const baseProfile = {
  include_patterns: [] as string[],
  exclude_patterns: ['**/*.log'],
  external_paths: [] as string[],
  db_tables_include: [] as string[],
  db_tables_exclude: ['wp_actionscheduler_logs'],
  db_include_foreign_tables: false,
  db_consistent_snapshot: false,
  compression: { method: 'deflate' as const, level: 6 },
  volume_size_mb: 256,
  limits: { time_budget_seconds: 20, memory_mb: 128, batch_rows: 1000 },
  checksums: true,
  success_policy: 'all' as const,
  notifications: {
    on_success: false,
    on_failure: true,
    on_warning: true,
    recipients: ['admin@example.com'],
  },
};

let profiles: Profile[] = [
  {
    ...baseProfile,
    id: 1,
    name: 'Nightly Full',
    description: 'Complete site backup including database, every night.',
    type: 'full',
    include_paths: ['wp-content/uploads', 'wp-content/themes', 'wp-content/plugins'],
    exclude_paths: ['wp-content/cache'],
    encryption: { enabled: true, key_id: 'primary-2026' },
    storage_target_ids: [1, 2],
    retention: {
      max_count: 10,
      max_age_days: 0,
      max_storage_mb: 0,
      delete_local_after_upload: false,
      gfs: { daily: 7, weekly: 4, monthly: 6, yearly: 1 },
    },
    created_at: iso(START - 90 * DAY),
    updated_at: iso(START - 3 * DAY),
  },
  {
    ...baseProfile,
    id: 2,
    name: 'Database hourly',
    description: 'Quick database snapshot for busy shops.',
    type: 'database',
    include_paths: [],
    exclude_paths: [],
    encryption: { enabled: false, key_id: '' },
    storage_target_ids: [1],
    retention: {
      max_count: 48,
      max_age_days: 3,
      max_storage_mb: 0,
      delete_local_after_upload: false,
      gfs: { daily: 0, weekly: 0, monthly: 0, yearly: 0 },
    },
    created_at: iso(START - 60 * DAY),
    updated_at: iso(START - 20 * DAY),
  },
  {
    ...baseProfile,
    id: 3,
    name: 'Uploads only',
    description: '',
    type: 'files',
    include_paths: ['wp-content/uploads'],
    exclude_paths: [],
    compression: { method: 'store', level: 1 },
    encryption: { enabled: false, key_id: '' },
    storage_target_ids: [3],
    retention: {
      max_count: 0,
      max_age_days: 30,
      max_storage_mb: 0,
      delete_local_after_upload: false,
      gfs: { daily: 0, weekly: 0, monthly: 0, yearly: 0 },
    },
    created_at: iso(START - 30 * DAY),
    updated_at: iso(START - 30 * DAY),
  },
  {
    ...baseProfile,
    id: 4,
    name: 'Weekly differential',
    description: 'Changes since the last full backup.',
    type: 'differential',
    include_paths: ['wp-content'],
    exclude_paths: ['wp-content/cache'],
    encryption: { enabled: false, key_id: '' },
    storage_target_ids: [1, 3],
    retention: {
      max_count: 6,
      max_age_days: 0,
      max_storage_mb: 0,
      delete_local_after_upload: false,
      gfs: { daily: 0, weekly: 0, monthly: 0, yearly: 0 },
    },
    created_at: iso(START - 12 * DAY),
    updated_at: iso(START - 12 * DAY),
  },
];
let nextProfileId = 5;

let schedules: Schedule[] = [
  {
    id: 1,
    name: 'Nightly',
    profile_id: 1,
    enabled: true,
    frequency: 'daily',
    cron_expression: '',
    timezone: 'Europe/Berlin',
    time_of_day: '02:30',
    weekday: 1,
    monthday: 1,
    retry: { max_attempts: 3, backoff_seconds: 600 },
    next_run_at: iso(START + 9 * HOUR),
    last_run_at: iso(START - 15 * HOUR),
    last_status: 'completed',
    last_error: null,
  },
  {
    id: 2,
    name: 'Hourly DB',
    profile_id: 2,
    enabled: true,
    frequency: 'cron',
    cron_expression: '0 * * * *',
    timezone: 'UTC',
    time_of_day: '',
    weekday: 0,
    monthday: 1,
    retry: { max_attempts: 2, backoff_seconds: 300 },
    next_run_at: iso(START + 25 * MIN),
    last_run_at: iso(START - 35 * MIN),
    last_status: 'failed',
    last_error:
      'Database connection lost during export (mysqli_sql_exception: MySQL server has gone away)',
  },
  {
    id: 3,
    name: 'Weekly differential',
    profile_id: 4,
    enabled: false,
    frequency: 'weekly',
    cron_expression: '',
    timezone: 'Europe/Berlin',
    time_of_day: '04:00',
    weekday: 0,
    monthday: 1,
    retry: { max_attempts: 1, backoff_seconds: 900 },
    next_run_at: null,
    last_run_at: null,
    last_status: null,
    last_error: null,
  },
];
let nextScheduleId = 4;

let targets: StorageTarget[] = [
  {
    id: 1,
    name: 'Local staging',
    type: 'local',
    config: { path: '/var/backups/swbackupit' },
    credentials_set: false,
    immutable: false,
    retention: null,
    status: 'ok',
    usage_bytes: 8_420_000_000,
    last_checked_at: iso(START - 12 * MIN),
    message: '',
  },
  {
    id: 2,
    name: 'Offsite SFTP',
    type: 'sftp',
    config: {
      host: 'backup.example.com',
      port: 22,
      username: 'wpbackup',
      root: '/srv/backups/site',
      host_fingerprint: 'SHA256:3b1fK0mNq8vXq1Zb7yQ2cQ0aVwJ1y9lY2tqf8GxN4uE',
      trust_unknown_host: false,
      allow_private_network: false,
      timeout: 30,
    },
    credentials_set: true,
    immutable: false,
    retention: null,
    status: 'warning',
    usage_bytes: null,
    last_checked_at: iso(START - 3 * HOUR),
    message: 'Host key changed since last connection.',
  },
  {
    id: 3,
    name: 'Wasabi bucket',
    type: 's3',
    config: {
      endpoint: 'https://s3.eu-central-1.wasabisys.com',
      region: 'eu-central-1',
      bucket: 'site-backups',
      prefix: 'wp/',
      path_style: false,
      allow_private_network: false,
    },
    credentials_set: true,
    immutable: true,
    retention: null,
    status: 'error',
    usage_bytes: 21_900_000_000,
    last_checked_at: iso(START - 25 * MIN),
    message: 'Access denied (HTTP 403). Check the access key permissions.',
  },
];
let nextTargetId = 4;
let keys: Array<{ id: string; source: string }> = [
  { id: 'primary-2026', source: 'stored' },
  { id: 'offsite', source: 'generated' },
];

let settings: Settings = {
  staging_dir: '/var/backups/swbackupit/staging',
  limits: { time_budget_seconds: 20, memory_mb: 128, batch_rows: 1000, max_parallel_uploads: 1 },
  encryption: { default_key_id: 'primary-2026' },
  notifications: { recipients: ['admin@example.com'], dedupe_minutes: 60 },
  logging: { retention_days: 30, max_events_per_job: 5000, level: 'info' },
  cron: { mode: 'wp-cron', timezone: 'Europe/Berlin' },
  retention_default: {
    max_count: 10,
    max_age_days: 0,
    gfs: { daily: 7, weekly: 4, monthly: 6, yearly: 0 },
  },
  pre_update_backup: { core: true, plugins: false, themes: false, profile_id: 1 },
};

function makeBackups(): Backup[] {
  const out: Backup[] = [];
  for (let i = 0; i < 47; i += 1) {
    const profile =
      profiles[i % 7 === 3 ? 1 : i % 5 === 4 ? 2 : i % 9 === 8 ? 3 : 0] ?? profiles[0]!;
    const type = profile.type as BackupType;
    const size =
      type === 'database'
        ? 18_000_000 + Math.floor(rand() * 9_000_000)
        : 400_000_000 + Math.floor(rand() * 600_000_000);
    const created = START - (i * 7 + 5) * HOUR * (1 + Math.floor(i / 12));
    const status =
      i === 6 ? 'corrupt' : i === 11 ? 'complete' : i % 4 === 0 ? 'verified' : 'complete';
    out.push({
      id: uuid(),
      profile_id: profile.id,
      profile_name: profile.name,
      type,
      status,
      created_at: iso(created),
      size_bytes: size,
      file_count: type === 'database' ? 1 : 8000 + Math.floor(rand() * 9000),
      volume_count: Math.max(1, Math.ceil(size / (256 * 1024 * 1024))),
      encrypted: profile.encryption.enabled,
      protected: i === 2 || i === 20,
      parent_id: type === 'differential' ? null : null,
      wp_version: '6.8.2',
      site_url: 'https://example.com',
      verified_at: status === 'verified' ? iso(created + 20 * MIN) : null,
      restore_tested_at: i === 4 ? iso(created + 2 * DAY) : null,
      locations: profile.storage_target_ids.map((tid) => ({
        target_id: tid,
        name: targets.find((t) => t.id === tid)?.name ?? `#${tid}`,
        status: tid === 3 && i % 6 === 0 ? 'failed' : i < 2 && tid === 2 ? 'pending' : 'verified',
        verified_at: iso(created + 30 * MIN),
      })),
    });
  }
  return out;
}
let backups: Backup[] = makeBackups();

function makeFinishedJobs(): Job[] {
  const jobs: Job[] = [];
  for (let i = 0; i < 26; i += 1) {
    const profile = profiles[i % 3] ?? profiles[0]!;
    const failed = i % 7 === 2;
    const started = START - (i * 5 + 2) * HOUR;
    const dur = 120 + Math.floor(rand() * 900);
    const backup = failed ? null : backups[i % backups.length];
    jobs.push({
      id: uuid(),
      profile_id: profile.id,
      profile_name: profile.name,
      type: profile.type,
      status: failed ? 'failed' : i === 9 ? 'cancelled' : 'completed',
      phase: failed ? 'upload' : 'notify',
      trigger: i % 3 === 0 ? 'schedule' : i % 7 === 0 ? 'cli' : 'manual',
      next_retry_at: null,
      started_at: iso(started),
      updated_at: iso(started + dur * 1000),
      finished_at: iso(started + dur * 1000),
      progress: {
        percent: failed ? 83 : 100,
        files_total: 12000,
        files_done: failed ? 9960 : 12000,
        bytes_total: 734_003_200,
        bytes_done: failed ? 609_000_000 : 734_003_200,
        bytes_compressed: 280_000_000,
        compression_ratio: 0.38,
        current_item: null,
        volumes_done: 3,
        elapsed_seconds: dur,
        eta_seconds: null,
        upload: {
          bytes_done: failed ? 120_000_000 : 280_000_000,
          bytes_total: 280_000_000,
          rate_bps: 0,
        },
        targets: profile.storage_target_ids.map((tid) => ({
          target_id: tid,
          name: targets.find((t) => t.id === tid)?.name ?? '',
          status: failed && tid === 3 ? 'failed' : 'verified',
          error: failed && tid === 3 ? 'Access denied (HTTP 403)' : null,
        })),
      },
      error: failed
        ? {
            code: 'swbi_storage_error',
            message: 'Upload to "Wasabi bucket" failed: Access denied (HTTP 403)',
          }
        : null,
      warnings_count: i % 5 === 0 ? 2 : 0,
      retry_count: failed ? 3 : 0,
      backup_id: backup?.id ?? null,
    });
  }
  return jobs;
}

interface Sim {
  lastAdvance: number;
  paused: boolean;
  logs: JobLogEntry[];
  loggedPhases: Set<string>;
  nextLogId: number;
}
const jobs: Job[] = makeFinishedJobs();
const sims = new Map<string, Sim>();

const PHASE_PLAN: Array<{ until: number; phase: string; status: JobStatus }> = [
  { until: 4, phase: 'preflight', status: 'preparing' },
  { until: 12, phase: 'scan', status: 'scanning' },
  { until: 24, phase: 'export', status: 'exporting' },
  { until: 62, phase: 'archive', status: 'archiving' },
  { until: 66, phase: 'encrypt', status: 'encrypting' },
  { until: 72, phase: 'verify_local', status: 'verifying' },
  { until: 75, phase: 'manifest', status: 'archiving' },
  { until: 90, phase: 'upload', status: 'uploading' },
  { until: 95, phase: 'verify_remote', status: 'verifying' },
  { until: 97, phase: 'finalize', status: 'uploading' },
  { until: 99, phase: 'retention', status: 'uploading' },
  { until: 100.1, phase: 'notify', status: 'uploading' },
];

function log(
  sim: Sim,
  severity: Severity,
  phase: string,
  message: string,
  context: Record<string, unknown> = {},
): void {
  sim.logs.push({ id: sim.nextLogId++, time: iso(Date.now()), severity, phase, message, context });
}

function startJob(profile: Profile, percent = 0): Job {
  const now = Date.now();
  const job: Job = {
    id: uuid(),
    profile_id: profile.id,
    profile_name: profile.name,
    type: profile.type,
    status: 'preparing',
    phase: 'preflight',
    trigger: 'manual',
    next_retry_at: null,
    started_at: iso(now - (percent / 100) * 100_000),
    updated_at: iso(now),
    finished_at: null,
    progress: {
      percent,
      files_total: 12000,
      files_done: 0,
      bytes_total: 734_003_200,
      bytes_done: 0,
      bytes_compressed: 0,
      compression_ratio: 0,
      current_item: null,
      volumes_done: 0,
      elapsed_seconds: 0,
      eta_seconds: null,
      upload: { bytes_done: 0, bytes_total: 0, rate_bps: 0 },
      targets: profile.storage_target_ids.map((tid) => ({
        target_id: tid,
        name: targets.find((t) => t.id === tid)?.name ?? '',
        status: 'pending',
        error: null,
      })),
    },
    error: null,
    warnings_count: 0,
    retry_count: 0,
    backup_id: null,
  };
  const sim: Sim = {
    lastAdvance: now,
    paused: false,
    logs: [],
    loggedPhases: new Set(),
    nextLogId: 1,
  };
  log(sim, 'info', 'preflight', `Job started for profile "${profile.name}"`);
  sims.set(job.id, sim);
  jobs.unshift(job);
  return job;
}

const SAMPLE_FILES = [
  'wp-content/uploads/2026/10/IMG_4821.jpg',
  'wp-content/uploads/2026/09/hero-banner.webp',
  'wp-content/plugins/woocommerce/includes/class-wc-order.php',
  'wp-content/themes/astra/assets/css/main.min.css',
  'wp-content/uploads/2025/12/catalog-winter.pdf',
  'wp-content/plugins/elementor/assets/js/frontend.min.js',
];

function advance(job: Job, boost = 0): void {
  const sim = sims.get(job.id);
  if (!sim || job.finished_at !== null) return;
  const now = Date.now();
  const dt = (now - sim.lastAdvance) / 1000;
  sim.lastAdvance = now;
  if (sim.paused || job.status === 'paused') return;
  // 100 s end-to-end by wall clock, ticks from the browser add a small boost
  let percent = Math.min(100, job.progress.percent + dt * 1.0 + boost);
  const profile = profiles.find((p) => p.id === job.profile_id);
  const encrypted = profile?.encryption.enabled ?? false;
  if (!encrypted && percent > 62 && percent < 66) percent = 66;
  const step = PHASE_PLAN.find((s) => percent < s.until) ?? PHASE_PLAN[PHASE_PLAN.length - 1]!;
  const p = job.progress;
  p.percent = Math.round(percent * 10) / 10;
  p.files_done = Math.min(p.files_total, Math.round((Math.min(percent, 66) / 66) * p.files_total));
  p.bytes_done = Math.round((Math.min(percent, 66) / 66) * p.bytes_total);
  p.bytes_compressed = Math.round(p.bytes_done * 0.38);
  p.compression_ratio = p.bytes_done > 0 ? 0.38 : 0;
  p.volumes_done = Math.floor(p.bytes_compressed / (256 * 1024 * 1024)) + (percent >= 66 ? 1 : 0);
  p.current_item =
    percent >= 12 && percent < 66
      ? (SAMPLE_FILES[Math.floor(percent) % SAMPLE_FILES.length] ?? null)
      : null;
  p.elapsed_seconds = Math.round((now - new Date(job.started_at).getTime()) / 1000);
  p.eta_seconds =
    percent > 1 ? Math.round(((100 - percent) / Math.max(percent, 1)) * p.elapsed_seconds) : null;
  if (percent >= 80) {
    const total = Math.round(p.bytes_total * 0.38);
    const frac = Math.min(1, (percent - 80) / 17);
    p.upload = {
      bytes_done: Math.round(total * frac),
      bytes_total: total,
      rate_bps: frac > 0 && frac < 1 ? 6_400_000 : 0,
    };
    p.targets.forEach((t, i) => {
      t.status = frac >= 1 ? 'verified' : i === 0 || frac > 0.5 ? 'uploading' : 'pending';
    });
  }
  job.status = step.status;
  job.phase = step.phase;
  job.updated_at = iso(now);
  if (!sim.loggedPhases.has(step.phase)) {
    sim.loggedPhases.add(step.phase);
    log(sim, 'notice', step.phase, `Entering phase ${step.phase}`);
    if (step.phase === 'scan')
      log(sim, 'warning', 'scan', 'Skipped unreadable file wp-content/uploads/.htaccess-backup', {
        path: 'wp-content/uploads/.htaccess-backup',
        errno: 13,
      });
  }
  if (percent < 62 && percent >= 12) {
    log(sim, 'debug', 'archive', `Added ${p.current_item ?? ''}`, { bytes: 120000 });
    if (Math.floor(percent) % 5 === 0)
      log(sim, 'info', step.phase, `${p.files_done}/${p.files_total} files processed`);
  }
  if (percent >= 100) finishJob(job, profile);
}

function finishJob(job: Job, profile: Profile | undefined): void {
  const sim = sims.get(job.id);
  job.status = 'completed';
  job.phase = 'notify';
  job.finished_at = iso(Date.now());
  job.progress.eta_seconds = null;
  job.progress.percent = 100;
  job.progress.upload.rate_bps = 0;
  job.progress.targets.forEach((t) => {
    t.status = 'verified';
  });
  if (sim) log(sim, 'info', 'finalizing', 'Backup completed');
  if (profile) {
    const id = uuid();
    job.backup_id = id;
    backups.unshift({
      id,
      profile_id: profile.id,
      profile_name: profile.name,
      type: profile.type,
      status: 'complete',
      created_at: iso(Date.now()),
      size_bytes: job.progress.bytes_compressed,
      file_count: job.progress.files_total,
      volume_count: Math.max(1, job.progress.volumes_done),
      encrypted: profile.encryption.enabled,
      protected: false,
      parent_id: null,
      wp_version: '6.8.2',
      site_url: 'https://example.com',
      verified_at: null,
      restore_tested_at: null,
      locations: profile.storage_target_ids.map((tid) => ({
        target_id: tid,
        name: targets.find((t) => t.id === tid)?.name ?? '',
        status: 'verified',
        verified_at: iso(Date.now()),
      })),
    });
  }
}

// one job already running when the dev server starts
const seededProfile = profiles[0]!;
const seeded = startJob(seededProfile, 38);
seeded.status = 'archiving';
seeded.phase = 'archive';
['scan', 'export'].forEach((ph) => sims.get(seeded.id)?.loggedPhases.add(ph));
{
  const sim = sims.get(seeded.id)!;
  log(sim, 'notice', 'scan', 'Entering phase scan');
  log(sim, 'warning', 'scan', 'Skipped unreadable file wp-content/uploads/.htaccess-backup', {
    path: 'wp-content/uploads/.htaccess-backup',
    errno: 13,
  });
  log(sim, 'notice', 'export', 'Entering phase export');
  log(sim, 'info', 'export', 'Exported 14 tables (22.4 MB)');
  seeded.warnings_count = 1;
}

// ---------------------------------------------------------------- helpers
function paginate<T>(
  items: T[],
  q: URLSearchParams,
  defaultPer = 20,
): { items: T[]; total: number; pages: number } {
  const per = Math.min(200, Math.max(1, Number(q.get('per_page') ?? defaultPer)));
  const page = Math.max(1, Number(q.get('page') ?? 1));
  return {
    items: items.slice((page - 1) * per, page * per),
    total: items.length,
    pages: Math.max(1, Math.ceil(items.length / per)),
  };
}
const num = (v: unknown, fallback = 0): number =>
  typeof v === 'number' && Number.isFinite(v) ? v : fallback;
const isRunning = (j: Job): boolean => !['completed', 'failed', 'cancelled'].includes(j.status);

function health(): HealthReport {
  const lastOk = backups.find((b) => b.status !== 'corrupt');
  const lastFail = jobs.find((j) => j.status === 'failed');
  return {
    status: 'warning',
    checks: [
      {
        id: 'sodium',
        label: 'Sodium extension',
        status: 'ok',
        message: 'libsodium 1.0.19 available',
      },
      { id: 'zip', label: 'ZipArchive', status: 'ok', message: 'ext-zip available' },
      { id: 'staging', label: 'Staging directory', status: 'ok', message: '38.2 GB free' },
      {
        id: 'cron',
        label: 'Cron reliability',
        status: 'warning',
        message: 'No cron run in the last 12 hours.',
      },
      {
        id: 'storage-3',
        label: 'Wasabi bucket',
        status: 'error',
        message: 'Access denied (HTTP 403).',
      },
      { id: 'memory', label: 'PHP memory limit', status: 'ok', message: '256M' },
    ],
    last_success: lastOk ? { backup_id: lastOk.id, finished_at: lastOk.created_at } : null,
    last_failure: lastFail
      ? {
          job_id: lastFail.id,
          finished_at: lastFail.finished_at ?? lastFail.updated_at,
          error:
            typeof lastFail.error === 'object' && lastFail.error
              ? (lastFail.error.message ?? '')
              : (lastFail.error ?? ''),
        }
      : null,
    next_run: { schedule_id: 2, schedule_name: 'Hourly DB', at: iso(START + 25 * MIN) },
    backup_count: backups.length,
    storage_bytes: backups.reduce((s, b) => s + b.size_bytes, 0),
    storage_targets: targets.map((t) => ({
      id: t.id,
      name: t.name,
      type: t.type,
      status: t.status,
      message: t.message,
    })),
    warnings: [
      'The last backup to "Wasabi bucket" could not be verified.',
      'WordPress cron has not run for 12 hours; scheduled backups may be delayed.',
      '2 backups are older than the configured retention but protected.',
    ],
    cron: {
      mode: settings.cron.mode,
      wp_cron_disabled: false,
      last_tick_at: iso(START - 12 * HOUR),
      notice:
        'WP-Cron only runs when your site receives visitors. On low-traffic sites scheduled backups can be delayed or skipped.',
    },
  };
}

// archive file tree -----------------------------------------------------------
const ARCHIVE: Omit<ArchiveEntry, 'type'>[] = (() => {
  const r = rng(7);
  const out: Omit<ArchiveEntry, 'type'>[] = [];
  const add = (path: string, vol: number): void => {
    out.push({
      path,
      size: Math.floor(r() * r() * 4_000_000) + 200,
      mtime: Math.floor(START / 1000) - Math.floor(r() * 86400 * 200),
      volume: vol,
    });
  };
  for (let y = 2024; y <= 2026; y += 1)
    for (let m = 1; m <= 12; m += 1)
      for (let f = 0; f < 18; f += 1)
        add(`wp-content/uploads/${y}/${String(m).padStart(2, '0')}/image-${f}.jpg`, 1 + (m % 3));
  for (const plugin of ['woocommerce', 'elementor', 'yoast-seo', 'akismet'])
    for (let f = 0; f < 60; f += 1) add(`wp-content/plugins/${plugin}/includes/file-${f}.php`, 2);
  for (const theme of ['astra', 'twentytwentyfive'])
    for (let f = 0; f < 40; f += 1)
      add(`wp-content/themes/${theme}/template-parts/part-${f}.php`, 3);
  add('wp-config.php', 1);
  add('.htaccess', 1);
  add('database.sql', 1);
  return out;
})();

function listFiles(path: string, search: string): ArchiveEntry[] {
  const prefix = path === '' ? '' : `${path.replace(/\/+$/, '')}/`;
  if (search !== '') {
    const s = search.toLowerCase();
    return ARCHIVE.filter((f) => f.path.startsWith(prefix) && f.path.toLowerCase().includes(s)).map(
      (f) => ({ ...f, type: 'file' as const }),
    );
  }
  const dirs = new Map<string, ArchiveEntry>();
  const files: ArchiveEntry[] = [];
  for (const f of ARCHIVE) {
    if (!f.path.startsWith(prefix)) continue;
    const rest = f.path.slice(prefix.length);
    const slash = rest.indexOf('/');
    if (slash === -1) files.push({ ...f, type: 'file' });
    else {
      const name = rest.slice(0, slash);
      if (!dirs.has(name))
        dirs.set(name, {
          path: `${prefix}${name}`,
          size: 0,
          mtime: f.mtime,
          type: 'dir',
          volume: f.volume,
        });
    }
  }
  return [
    ...[...dirs.values()].sort((a, b) => a.path.localeCompare(b.path)),
    ...files.sort((a, b) => a.path.localeCompare(b.path)),
  ];
}

// ---------------------------------------------------------------- router
export function handle(req: MockRequest): MockResponse {
  const { method, path, query, body } = req;
  let m: RegExpMatchArray | null;

  if (method === 'GET' && path === 'health') return { status: 200, body: health() };

  // profiles
  if (path === 'profiles') {
    if (method === 'GET') return { status: 200, body: paginate(profiles, query, 20) };
    if (method === 'POST') {
      if (typeof body.name !== 'string' || body.name.trim() === '')
        return wpError(400, 'swbi_invalid_param', 'Invalid parameter(s): name', {
          name: 'Required',
        });
      const p = {
        ...(body as unknown as Profile),
        id: nextProfileId++,
        created_at: iso(Date.now()),
        updated_at: iso(Date.now()),
      };
      profiles.push(p);
      return { status: 201, body: p };
    }
  }
  if ((m = path.match(/^profiles\/(\d+)(?:\/(duplicate|run))?$/))) {
    const id = Number(m[1]);
    const p = profiles.find((x) => x.id === id);
    if (!p) return wpError(404, 'swbi_not_found', 'Profile not found.');
    if (m[2] === 'duplicate' && method === 'POST') {
      const copy: Profile = {
        ...JSON.parse(JSON.stringify(p)),
        id: nextProfileId++,
        name: `${p.name} (copy)`,
        created_at: iso(Date.now()),
        updated_at: iso(Date.now()),
      };
      profiles.push(copy);
      return { status: 201, body: copy };
    }
    if (m[2] === 'run' && method === 'POST') {
      if (jobs.some(isRunning))
        return wpError(
          409,
          'swbi_conflict',
          'Another backup job is already running. Wait for it to finish or cancel it.',
        );
      return { status: 201, body: startJob(p) };
    }
    if (!m[2]) {
      if (method === 'GET') return { status: 200, body: p };
      if (method === 'PUT') {
        if (typeof body.name !== 'string' || body.name.trim() === '')
          return wpError(400, 'swbi_invalid_param', 'Invalid parameter(s): name', {
            name: 'Required',
          });
        Object.assign(p, body, { id, updated_at: iso(Date.now()) });
        return { status: 200, body: p };
      }
      if (method === 'DELETE') {
        if (query.get('confirm') !== 'true')
          return wpError(400, 'swbi_confirmation_required', 'Confirmation required.');
        profiles = profiles.filter((x) => x.id !== id);
        return { status: 200, body: { deleted: true } };
      }
    }
  }

  // schedules
  if (path === 'schedules') {
    if (method === 'GET') return { status: 200, body: paginate(schedules, query, 20) };
    if (method === 'POST') {
      if (typeof body.name !== 'string' || body.name.trim() === '')
        return wpError(400, 'swbi_invalid_param', 'Invalid parameter(s): name', {
          name: 'Required',
        });
      const s: Schedule = {
        ...(body as unknown as Schedule),
        id: nextScheduleId++,
        next_run_at: body.enabled ? iso(Date.now() + 6 * HOUR) : null,
        last_run_at: null,
        last_status: null,
        last_error: null,
      };
      schedules.push(s);
      return { status: 201, body: s };
    }
  }
  if ((m = path.match(/^schedules\/(\d+)(?:\/(run))?$/))) {
    const id = Number(m[1]);
    const s = schedules.find((x) => x.id === id);
    if (!s) return wpError(404, 'swbi_not_found', 'Schedule not found.');
    if (m[2] === 'run' && method === 'POST') {
      const p = profiles.find((x) => x.id === s.profile_id);
      if (!p) return wpError(404, 'swbi_not_found', 'Profile not found.');
      if (jobs.some(isRunning))
        return wpError(409, 'swbi_conflict', 'Another backup job is already running.');
      return { status: 201, body: startJob(p) };
    }
    if (method === 'GET') return { status: 200, body: s };
    if (method === 'PUT') {
      Object.assign(s, body, { id });
      s.next_run_at = s.enabled ? iso(Date.now() + 6 * HOUR) : null;
      return { status: 200, body: s };
    }
    if (method === 'DELETE') {
      schedules = schedules.filter((x) => x.id !== id);
      return { status: 200, body: { deleted: true } };
    }
  }

  // jobs
  if (method === 'GET' && path === 'jobs') {
    jobs.filter(isRunning).forEach((j) => advance(j));
    const f = query.get('status');
    let list = jobs;
    if (f === 'running') list = jobs.filter(isRunning);
    else if (f === 'finished') list = jobs.filter((j) => !isRunning(j));
    else if (f) list = jobs.filter((j) => j.status === f);
    return { status: 200, body: paginate(list, query, 20) };
  }
  if ((m = path.match(/^jobs\/([0-9a-f-]+)(?:\/(pause|resume|cancel|tick|logs))?$/))) {
    const job = jobs.find((j) => j.id === m![1]);
    if (!job) return wpError(404, 'swbi_not_found', 'Job not found.');
    let sim = sims.get(job.id);
    const action = m[2];
    if (!action && method === 'GET') {
      advance(job);
      return { status: 200, body: job };
    }
    if (action === 'tick' && method === 'POST') {
      if (!isRunning(job)) return { status: 200, body: job };
      advance(job, 1.5);
      return { status: 200, body: job };
    }
    if (action === 'logs' && method === 'GET') {
      advance(job);
      const after = Number(query.get('after') ?? 0);
      const sev = query.get('severity');
      const ph = query.get('phase');
      const limit = Math.min(500, Number(query.get('limit') ?? 200));
      const all = (sim?.logs ?? []).filter(
        (l) => l.id > after && (!sev || l.severity === sev) && (!ph || l.phase === ph),
      );
      const items = all.slice(0, limit);
      return {
        status: 200,
        body: { items, last_id: items.length > 0 ? items[items.length - 1]!.id : after },
      };
    }
    if (method === 'POST' && action) {
      if (!sim) {
        sim = {
          lastAdvance: Date.now(),
          paused: false,
          logs: [],
          loggedPhases: new Set(),
          nextLogId: 1,
        };
        sims.set(job.id, sim);
      }
      if (action === 'retry') {
        if (job.status !== 'failed')
          return wpError(400, 'swbi_error', 'Only failed jobs can be retried.');
        if (jobs.some(isRunning))
          return wpError(409, 'swbi_conflict', 'Another backup job is already running.');
        job.status = 'archiving';
        job.phase = 'archive';
        job.error = null;
        job.finished_at = null;
        job.retry_count += 1;
        job.progress.percent = Math.min(job.progress.percent, 40);
        sim.lastAdvance = Date.now();
        log(sim, 'notice', 'archive', 'Retrying from checkpoint');
        job.updated_at = iso(Date.now());
        return { status: 200, body: job };
      }
      if (action === 'pause' && isRunning(job)) {
        sim.paused = true;
        job.status = 'paused';
        log(sim, 'notice', job.phase ?? '', 'Job paused');
      } else if (action === 'resume' && job.status === 'paused') {
        sim.paused = false;
        sim.lastAdvance = Date.now();
        job.status = PHASE_PLAN.find((s) => s.phase === job.phase)?.status ?? 'archiving';
        log(sim, 'notice', job.phase ?? '', 'Job resumed');
      } else if (action === 'cancel' && isRunning(job)) {
        job.status = 'cancelled';
        job.finished_at = iso(Date.now());
        log(sim, 'warning', job.phase ?? '', 'Job cancelled by user');
      } else
        return wpError(400, 'swbi_error', 'The job is not in a state that allows this action.');
      job.updated_at = iso(Date.now());
      return { status: 200, body: job };
    }
  }

  // backups
  if (method === 'GET' && path === 'backups') {
    let list = backups;
    const pid = query.get('profile_id');
    if (pid) list = list.filter((b) => b.profile_id === Number(pid));
    const type = query.get('type');
    if (type) list = list.filter((b) => b.type === type);
    const st = query.get('status');
    if (st) list = list.filter((b) => b.status === st);
    const s = query.get('search')?.toLowerCase();
    if (s) list = list.filter((b) => b.id.includes(s) || b.profile_name.toLowerCase().includes(s));
    return { status: 200, body: paginate(list, query, 20) };
  }
  if (
    (m = path.match(
      /^backups\/([0-9a-f-]+)(?:\/(verify|protect|files|compare|download|restore\/prepare|restore))?$/,
    ))
  ) {
    const b = backups.find((x) => x.id === m![1]);
    if (!b) return wpError(404, 'swbi_not_found', 'Backup not found.');
    const action = m[2];
    if (!action) {
      if (method === 'GET') return { status: 200, body: b };
      if (method === 'DELETE') {
        if (query.get('confirm') !== b.id)
          return wpError(
            400,
            'swbi_confirmation_required',
            'Confirmation does not match the backup id.',
          );
        if (b.protected)
          return wpError(409, 'swbi_conflict', 'This backup is protected and cannot be deleted.');
        backups = backups.filter((x) => x.id !== b.id);
        return { status: 200, body: { deleted: true } };
      }
    }
    if (action === 'verify' && method === 'POST') {
      b.verified_at = iso(Date.now());
      if (b.status === 'corrupt')
        return {
          status: 200,
          body: {
            ok: false,
            checked_volumes: b.volume_count,
            errors: [
              'Volume 2: SHA-256 mismatch (expected 9f2c…e1, got 41ab…07)',
              'Volume 2: 3 entries unreadable',
            ],
          },
        };
      if (b.status === 'complete') b.status = 'verified';
      return { status: 200, body: { ok: true, checked_volumes: b.volume_count, errors: [] } };
    }
    if (action === 'protect' && method === 'POST') {
      b.protected = body.protected === true;
      return { status: 200, body: b };
    }
    if (action === 'files' && method === 'GET') {
      const all = listFiles(query.get('path') ?? '', query.get('search') ?? '');
      return { status: 200, body: paginate(all, query, 100) };
    }
    if (action === 'compare' && method === 'POST') {
      return {
        status: 200,
        body: {
          only_in_backup: 12,
          only_local: 87,
          modified: 143,
          identical: b.file_count - 155,
          incomparable: 3,
          samples: {
            modified: [
              'wp-content/plugins/woocommerce/includes/class-wc-order.php',
              'wp-content/themes/astra/functions.php',
              'wp-config.php',
            ],
            only_in_backup: ['wp-content/uploads/2024/01/old-banner.jpg'],
            only_local: [
              'wp-content/uploads/2026/10/new-photo.jpg',
              'wp-content/cache/page-1.html',
            ],
          },
        },
      };
    }
    if (action === 'restore/prepare' && method === 'POST') {
      return {
        status: 200,
        body: {
          restore_url: 'http://127.0.0.1:5173/wp-content/plugins/swbackupit/restore/index.php',
          token: 'swbi-rt-' + uuid().replace(/-/g, '').slice(0, 24),
          token_expires_at: iso(Date.now() + 30 * MIN),
          target_path: '/var/www/html/wordpress_dev',
          warnings: [
            'The backup was created with WordPress 6.8.2; this site runs 6.8.3.',
            'Disk space on the target is lower than twice the backup size.',
          ],
        },
      };
    }
    if (action === 'restore' && method === 'POST') {
      return wpError(501, 'swbi_use_wizard', 'Restore runs only in the standalone restore wizard.');
    }
    if (action === 'download' && method === 'GET')
      return { status: 200, body: { note: 'mock download' } };
  }

  // storage
  if (path === 'storage-targets') {
    if (method === 'GET') return { status: 200, body: paginate(targets, query, 20) };
    if (method === 'POST') {
      if (typeof body.name !== 'string' || body.name.trim() === '')
        return wpError(400, 'swbi_invalid_param', 'Invalid parameter(s): name', {
          name: 'Required',
        });
      const creds = body.credentials as Record<string, string> | undefined;
      const t: StorageTarget = {
        id: nextTargetId++,
        name: body.name,
        type: body.type as StorageTarget['type'],
        config: (body.config ?? {}) as StorageTarget['config'],
        credentials_set: !!creds && Object.keys(creds).length > 0,
        immutable: body.immutable === true,
        retention: (body.retention ?? null) as StorageTarget['retention'],
        status: 'ok',
        usage_bytes: 0,
        last_checked_at: null,
        message: '',
      };
      targets.push(t);
      return { status: 201, body: t };
    }
  }
  if ((m = path.match(/^storage-targets\/(\d+)(?:\/(test|discover|import))?$/))) {
    const id = Number(m[1]);
    const t = targets.find((x) => x.id === id);
    if (!t) return wpError(404, 'swbi_not_found', 'Storage target not found.');
    const action = m[2];
    if (!action) {
      if (method === 'GET') return { status: 200, body: t };
      if (method === 'PUT') {
        const creds = body.credentials as Record<string, string> | undefined;
        Object.assign(t, {
          name: body.name,
          config: body.config,
          immutable: body.immutable,
          retention: body.retention ?? null,
        });
        if (creds && Object.keys(creds).length > 0) t.credentials_set = true;
        return { status: 200, body: t };
      }
      if (method === 'DELETE') {
        const user = profiles.find((p) => p.storage_target_ids.includes(id));
        if (user) {
          return wpError(
            409,
            'swbi_conflict',
            `The storage target is still used by profile "${user.name}".`,
          );
        }
        targets = targets.filter((x) => x.id !== id);
        return { status: 200, body: { deleted: true } };
      }
    }
    if (action === 'test' && method === 'POST') {
      t.last_checked_at = iso(Date.now());
      return {
        status: 200,
        body:
          id === 3
            ? { ok: false, message: 'Access denied (HTTP 403)', latency_ms: 212 }
            : {
                ok: true,
                message: 'Connected and wrote a test object.',
                latency_ms: id === 1 ? 3 : 142,
              },
      };
    }
    if (action === 'discover' && method === 'GET') {
      const mk = (
        ageDays: number,
        size: number,
        error: string | null,
        imported = false,
        id = uuid(),
      ) => ({
        backup_id: id,
        created_at: iso(START - ageDays * DAY),
        size_bytes: size,
        type: 'full',
        profile_name: 'Nightly Full',
        already_imported: imported,
        error,
      });
      return {
        status: 200,
        body: {
          items: [
            mk(40, 512_000_000, null),
            mk(41, 498_000_000, null),
            mk(55, 0, 'Manifest missing or unreadable'),
            mk(3, backups[3]!.size_bytes, null, true, backups[3]!.id),
          ],
          total: 4,
          pages: 1,
        },
      };
    }
    if (action === 'import' && method === 'POST') {
      const ids = Array.isArray(body.backup_ids) ? body.backup_ids : [];
      return { status: 200, body: { imported: ids.length } };
    }
  }

  // encryption keys
  let km: RegExpMatchArray | null;
  if (path === 'encryption-keys') {
    if (method === 'GET') return { status: 200, body: { items: keys } };
    if (method === 'POST') {
      const id = typeof body.id === 'string' ? body.id.trim() : '';
      if (!/^[A-Za-z0-9._-]{1,64}$/.test(id)) {
        return wpError(400, 'swbi_invalid_param', 'Invalid parameter(s): id', {
          id: 'Invalid key id',
        });
      }
      if (keys.some((k) => k.id === id))
        return wpError(409, 'swbi_conflict', 'A key with this id already exists.');
      const pass = typeof body.passphrase === 'string' && body.passphrase !== '';
      keys.push({ id, source: pass ? 'passphrase' : 'generated' });
      const secret = pass
        ? String(body.passphrase)
        : Array.from({ length: 32 }, () =>
            Math.floor(Math.random() * 256)
              .toString(16)
              .padStart(2, '0'),
          ).join('');
      return {
        status: 201,
        body: { id, secret, warning: 'Store this secret now. It cannot be shown again.' },
      };
    }
  }
  if ((km = path.match(/^encryption-keys\/([^/]+)$/)) && method === 'DELETE') {
    const id = decodeURIComponent(km[1]!);
    if (!keys.some((k) => k.id === id)) return wpError(404, 'swbi_not_found', 'Key not found.');
    keys = keys.filter((k) => k.id !== id);
    return { status: 200, body: { deleted: true } };
  }

  // settings
  if (path === 'settings') {
    if (method === 'GET') return { status: 200, body: settings };
    if (method === 'PUT') {
      if (typeof body.staging_dir !== 'string' || body.staging_dir.trim() === '')
        return wpError(400, 'swbi_invalid_param', 'Invalid parameter(s): staging_dir', {
          staging_dir: 'Required',
        });
      if (num((body.limits as Settings['limits'] | undefined)?.memory_mb) < 32)
        return wpError(400, 'swbi_invalid_param', 'Invalid parameter(s): limits.memory_mb', {
          'limits.memory_mb': 'Must be at least 32',
        });
      settings = body as unknown as Settings;
      return { status: 200, body: settings };
    }
  }

  return wpError(404, 'rest_no_route', 'No route was found matching the URL and request method.');
}
