import type { Job, JobStatus, JobStep } from '@/types/api';

/** Pipeline order shown in the stepper (ids as reported in `job.phase`). */
export const PHASES: readonly JobStep[] = [
  'preflight',
  'scan',
  'export',
  'archive',
  'encrypt',
  'verify_local',
  'manifest',
  'upload',
  'verify_remote',
  'finalize',
  'retention',
  'notify',
];

export function phaseIndex(phase: string | null): number {
  return phase === null ? -1 : (PHASES as readonly string[]).indexOf(phase);
}

export const TERMINAL_STATUSES: readonly JobStatus[] = ['completed', 'failed', 'cancelled'];

export function isTerminal(status: JobStatus): boolean {
  return TERMINAL_STATUSES.includes(status);
}

/** Active = the server is (or soon will be) doing work, so ticking/polling makes sense. */
export function isActive(job: Pick<Job, 'status'>): boolean {
  return !isTerminal(job.status) && job.status !== 'paused';
}

/** Delay before the next poll, with backoff while nothing changes and after errors. */
export function nextPollDelay(options: {
  unchangedCount: number;
  errorCount: number;
  paused: boolean;
}): number {
  if (options.errorCount > 0) return Math.min(30000, 2000 * 2 ** (options.errorCount - 1));
  if (options.paused) return 5000;
  return Math.min(5000, 1000 + options.unchangedCount * 500);
}

/** Human text of `job.error` whether the server sent a string or `{code, message}`. */
export function jobErrorText(error: Job['error']): string | null {
  if (error === null || error === undefined) return null;
  if (typeof error === 'string') return error;
  return error.message ?? error.code ?? null;
}
