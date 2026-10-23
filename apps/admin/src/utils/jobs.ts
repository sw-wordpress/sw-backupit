import type { Job, JobStatus } from '@/types/api';

/** Pipeline order shown in the stepper. `preparing` is the API's name for "preflight". */
export const PHASES = [
  'preflight',
  'scanning',
  'exporting',
  'archiving',
  'encrypting',
  'verifying',
  'uploading',
  'finalizing',
] as const;
export type Phase = (typeof PHASES)[number];

const PHASE_ALIASES: Record<string, Phase> = {
  pending: 'preflight',
  preparing: 'preflight',
  preflight: 'preflight',
  completed: 'finalizing',
};

export function normalisePhase(phase: string): Phase | null {
  if ((PHASES as readonly string[]).includes(phase)) return phase as Phase;
  return PHASE_ALIASES[phase] ?? null;
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
