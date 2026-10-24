import type { BackupType, ProfileInput } from '@/types/api';

export const STEP_IDS = [
  'general',
  'content',
  'compression',
  'storage',
  'retention',
  'summary',
] as const;
export type StepId = (typeof STEP_IDS)[number];

export const NAME_MAX = 60;

/** Resource presets map onto the real `profile.limits` fields (no invented settings). */
export type PresetId = 'low' | 'normal' | 'high';
export const LIMIT_PRESETS: Record<PresetId, { time_budget_seconds: number; batch_rows: number }> =
  {
    low: { time_budget_seconds: 10, batch_rows: 250 },
    normal: { time_budget_seconds: 20, batch_rows: 1000 },
    high: { time_budget_seconds: 40, batch_rows: 2000 },
  };

/** Which preset the current limits correspond to, or `custom` when they were edited by hand. */
export function detectPreset(limits: ProfileInput['limits']): PresetId | 'custom' {
  for (const id of Object.keys(LIMIT_PRESETS) as PresetId[]) {
    const p = LIMIT_PRESETS[id];
    if (
      p.time_budget_seconds === limits.time_budget_seconds &&
      p.batch_rows === limits.batch_rows
    ) {
      return id;
    }
  }
  return 'custom';
}

export function applyPreset(limits: ProfileInput['limits'], id: PresetId): ProfileInput['limits'] {
  return { ...limits, ...LIMIT_PRESETS[id] };
}

/** Quote one argument for a POSIX shell (single quotes; embedded quotes escaped). */
export function shellQuote(value: string): string {
  return /^[A-Za-z0-9_./:=@%+-]+$/.test(value) ? value : `'${value.replace(/'/g, `'\\''`)}'`;
}

/** WP-CLI command equivalent to running this profile. The server assigns the real id on save. */
export function cliCommand(name: string, id: number | null): string {
  const ref = id !== null ? String(id) : name.trim() !== '' ? name.trim() : '<name>';
  return `wp swbackupit backup create --profile=${shellQuote(ref)}`;
}

export const includesFiles = (type: BackupType): boolean => type !== 'database';
export const includesDatabase = (type: BackupType): boolean => type !== 'files';

export type IssueCode = 'required' | 'tooLong' | 'min' | 'email';
export interface Issue {
  field: string;
  code: IssueCode;
  /** Parameter for the message (e.g. minimum value). */
  n?: number;
}
export type StepIssues = Record<StepId, Issue[]>;

const EMAIL = /^\S+@\S+\.\S+$/;

/** Pure validation, grouped by wizard step. Client-side validation is UX only. */
export function validateProfile(form: ProfileInput): StepIssues {
  const out: StepIssues = {
    general: [],
    content: [],
    compression: [],
    storage: [],
    retention: [],
    summary: [],
  };
  const name = form.name.trim();
  if (name === '') out.general.push({ field: 'name', code: 'required' });
  else if (name.length > NAME_MAX)
    out.general.push({ field: 'name', code: 'tooLong', n: NAME_MAX });
  const pos = (v: number): boolean => Number.isFinite(v) && v >= 1;
  const nonNeg = (v: number): boolean => Number.isFinite(v) && v >= 0;
  if (!pos(form.limits.memory_mb))
    out.general.push({ field: 'limits.memory_mb', code: 'min', n: 1 });
  if (!pos(form.limits.time_budget_seconds)) {
    out.general.push({ field: 'limits.time_budget_seconds', code: 'min', n: 1 });
  }
  if (!pos(form.limits.batch_rows))
    out.general.push({ field: 'limits.batch_rows', code: 'min', n: 1 });
  if (!pos(form.volume_size_mb))
    out.compression.push({ field: 'volume_size_mb', code: 'min', n: 1 });
  if (form.encryption.enabled && form.encryption.key_id.trim() === '') {
    out.compression.push({ field: 'encryption.key_id', code: 'required' });
  }
  const r = form.retention;
  const numbers: Array<[string, number]> = [
    ['retention.max_count', r.max_count],
    ['retention.max_age_days', r.max_age_days],
    ['retention.max_storage_mb', r.max_storage_mb],
    ['retention.gfs.daily', r.gfs.daily],
    ['retention.gfs.weekly', r.gfs.weekly],
    ['retention.gfs.monthly', r.gfs.monthly],
    ['retention.gfs.yearly', r.gfs.yearly],
  ];
  for (const [field, value] of numbers)
    if (!nonNeg(value)) out.retention.push({ field, code: 'min', n: 0 });
  if (!form.notifications.recipients.every((a) => EMAIL.test(a))) {
    out.retention.push({ field: 'notifications.recipients', code: 'email' });
  }
  return out;
}

export const issueCount = (issues: Issue[]): number => issues.length;
export const hasIssues = (all: StepIssues): boolean => STEP_IDS.some((s) => all[s].length > 0);
export function firstStepWithIssues(all: StepIssues): StepId | null {
  return STEP_IDS.find((s) => all[s].length > 0) ?? null;
}

/** The request body subset shown in the live preview (real fields only). */
export function previewPayload(form: ProfileInput): Record<string, unknown> {
  const out: Record<string, unknown> = { name: form.name, type: form.type };
  if (includesFiles(form.type)) {
    out.include_paths = form.include_paths;
    out.exclude_paths = form.exclude_paths;
  }
  if (
    includesDatabase(form.type) &&
    (form.db_tables_include.length > 0 || form.db_tables_exclude.length > 0)
  ) {
    out.db_tables_include = form.db_tables_include;
    out.db_tables_exclude = form.db_tables_exclude;
  }
  out.compression = form.compression;
  out.encryption = form.encryption.enabled ? form.encryption : { enabled: false };
  out.volume_size_mb = form.volume_size_mb;
  out.limits = form.limits;
  out.checksums = form.checksums;
  out.storage_target_ids = form.storage_target_ids;
  out.success_policy = form.success_policy;
  out.retention = form.retention;
  return out;
}

export interface JsonToken {
  text: string;
  kind: 'key' | 'string' | 'literal' | 'number' | 'punct';
}

/** Splits pretty-printed JSON into coloured tokens (rendered as text nodes, never as HTML). */
export function jsonLines(value: unknown): JsonToken[][] {
  const re = /("(?:\\.|[^"\\])*")(\s*:)?|\b(true|false|null)\b|-?\d+(?:\.\d+)?|[{}[\],:]/g;
  return JSON.stringify(value, null, 2)
    .split('\n')
    .map((line) => {
      const tokens: JsonToken[] = [];
      let last = 0;
      for (const m of line.matchAll(re)) {
        const idx = m.index ?? 0;
        if (idx > last) tokens.push({ text: line.slice(last, idx), kind: 'punct' });
        if (m[1] !== undefined) {
          tokens.push({ text: m[1], kind: m[2] !== undefined ? 'key' : 'string' });
          if (m[2] !== undefined) tokens.push({ text: m[2], kind: 'punct' });
        } else if (m[3] !== undefined) tokens.push({ text: m[0], kind: 'literal' });
        else if (/^-?\d/.test(m[0])) tokens.push({ text: m[0], kind: 'number' });
        else tokens.push({ text: m[0], kind: 'punct' });
        last = idx + m[0].length;
      }
      if (last < line.length) tokens.push({ text: line.slice(last), kind: 'punct' });
      return tokens;
    });
}
