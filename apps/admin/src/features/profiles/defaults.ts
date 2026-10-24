import type { Profile, ProfileInput } from '@/types/api';

export function defaultProfileInput(): ProfileInput {
  return {
    name: '',
    description: '',
    type: 'full',
    include_paths: ['wp-content/uploads', 'wp-content/themes', 'wp-content/plugins'],
    exclude_paths: ['wp-content/cache'],
    include_patterns: [],
    exclude_patterns: ['**/*.log'],
    external_paths: [],
    db_tables_include: [],
    db_tables_exclude: [],
    db_include_foreign_tables: false,
    db_consistent_snapshot: false,
    compression: { method: 'deflate', level: 6 },
    encryption: { enabled: false, key_id: '' },
    volume_size_mb: 256,
    limits: { time_budget_seconds: 20, memory_mb: 128, batch_rows: 1000 },
    checksums: true,
    storage_target_ids: [],
    success_policy: 'all',
    retention: {
      max_count: 10,
      max_age_days: 0,
      max_storage_mb: 0,
      delete_local_after_upload: false,
      gfs: { daily: 0, weekly: 0, monthly: 0, yearly: 0 },
    },
    notifications: { on_success: false, on_failure: true, on_warning: true, recipients: [] },
  };
}

/** Deep copy of the writable fields of a profile (drops id and timestamps). */
export function profileToInput(profile: Profile): ProfileInput {
  const { id: _id, created_at: _c, updated_at: _u, ...rest } = profile;
  return JSON.parse(JSON.stringify(rest)) as ProfileInput;
}

export const ADVANCED_TYPES = ['incremental', 'differential'] as const;
