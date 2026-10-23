import { computed, onScopeDispose, ref, shallowRef, toValue } from 'vue';
import type { MaybeRefOrGetter } from 'vue';
import { useApi } from '@/api';
import type { ApiError } from '@/api/client';
import { toApiError } from '@/composables/useAsyncData';
import type { Job, JobLogEntry } from '@/types/api';
import { isActive, isTerminal, nextPollDelay } from '@/utils/jobs';

export const LOG_BUFFER_LIMIT = 2000;
const LOG_PAGE = 200;
const LOG_MAX_PAGES_PER_CYCLE = 30;
const TICK_PAUSE_MS = 300;

export interface JobMonitorOptions {
  /** Drive the job from this browser via POST /jobs/{id}/tick while active and visible. */
  drive: MaybeRefOrGetter<boolean>;
  /** Whether the user may run jobs at all (capability gate). */
  canRun: boolean;
  onUpdate?: (job: Job) => void;
}

/** Append `incoming` to `buffer`, keep only the newest `limit` entries. Pure, exported for tests. */
export function appendBounded(
  buffer: JobLogEntry[],
  incoming: JobLogEntry[],
  limit = LOG_BUFFER_LIMIT,
): { entries: JobLogEntry[]; dropped: number } {
  if (incoming.length === 0) return { entries: buffer, dropped: 0 };
  const merged = buffer.concat(incoming);
  const dropped = Math.max(0, merged.length - limit);
  return { entries: dropped > 0 ? merged.slice(dropped) : merged, dropped };
}

/**
 * Live view of one job: polls `GET /jobs/{id}` with backoff, pulls new log events with
 * `after=`, optionally drives the job with `POST /jobs/{id}/tick`, pauses while the tab is
 * hidden and stops on scope disposal. Server state is the only truth.
 */
export function useJobMonitor(jobId: MaybeRefOrGetter<string>, options: JobMonitorOptions) {
  const api = useApi();
  const job = shallowRef<Job | null>(null);
  const loading = ref(true);
  const error = shallowRef<ApiError | null>(null);
  const logs = shallowRef<JobLogEntry[]>([]);
  const droppedLogs = ref(0);
  const driving = ref(false);
  const finished = computed(() => job.value !== null && isTerminal(job.value.status));

  let lastLogId = 0;
  let generation = 0;
  let controller: AbortController | null = null;
  let wake: (() => void) | null = null;
  let alive = false;

  function sleep(ms: number): Promise<void> {
    return new Promise((resolve) => {
      const t = setTimeout(done, ms);
      function done(): void {
        clearTimeout(t);
        wake = null;
        document.removeEventListener('visibilitychange', onVis);
        resolve();
      }
      function onVis(): void {
        if (!document.hidden) done();
      }
      document.addEventListener('visibilitychange', onVis);
      wake = done;
    });
  }

  function waitVisible(): Promise<void> {
    return new Promise((resolve) => {
      const onVis = (): void => {
        if (!document.hidden) {
          document.removeEventListener('visibilitychange', onVis);
          wake = null;
          resolve();
        }
      };
      document.addEventListener('visibilitychange', onVis);
      wake = onVis;
    });
  }

  async function fetchLogs(signal: AbortSignal): Promise<void> {
    const id = toValue(jobId);
    for (let page = 0; page < LOG_MAX_PAGES_PER_CYCLE; page += 1) {
      const res = await api.jobs.logs(id, { after: lastLogId, limit: LOG_PAGE }, signal);
      if (res.items.length > 0) {
        const { entries, dropped } = appendBounded(logs.value, res.items);
        logs.value = entries;
        droppedLogs.value += dropped;
      }
      lastLogId = Math.max(lastLogId, res.last_id);
      if (res.items.length < LOG_PAGE) return;
    }
  }

  function signature(j: Job): string {
    return `${j.status}|${j.phase}|${j.updated_at}|${j.progress.percent}|${j.progress.files_done}`;
  }

  async function run(myGeneration: number): Promise<void> {
    alive = true;
    try {
      await loop(myGeneration);
    } finally {
      if (myGeneration === generation) alive = false;
    }
  }

  async function loop(myGeneration: number): Promise<void> {
    let unchanged = 0;
    let errors = 0;
    controller = new AbortController();
    const signal = controller.signal;
    while (myGeneration === generation) {
      if (document.hidden) {
        await waitVisible();
        continue;
      }
      let waitMs: number;
      try {
        const current = job.value;
        const id = toValue(jobId);
        const shouldDrive = options.canRun && toValue(options.drive) && current !== null && isActive(current);
        driving.value = shouldDrive;
        const next = shouldDrive ? await api.jobs.tick(id, signal) : await api.jobs.get(id, signal);
        if (myGeneration !== generation) return;
        unchanged = current !== null && signature(current) === signature(next) ? unchanged + 1 : 0;
        job.value = next;
        options.onUpdate?.(next);
        await fetchLogs(signal);
        error.value = null;
        errors = 0;
        loading.value = false;
        if (isTerminal(next.status)) {
          driving.value = false;
          return;
        }
        waitMs = shouldDrive && errors === 0
          ? TICK_PAUSE_MS
          : nextPollDelay({ unchangedCount: unchanged, errorCount: 0, paused: next.status === 'paused' });
      } catch (e) {
        const err = toApiError(e);
        if (err.isAborted || myGeneration !== generation) return;
        errors += 1;
        error.value = err;
        loading.value = false;
        if (err.status === 404) return; // job vanished: nothing left to poll
        waitMs = nextPollDelay({ unchangedCount: unchanged, errorCount: errors, paused: false });
      }
      await sleep(waitMs);
    }
  }

  function start(): void {
    stop();
    generation += 1;
    void run(generation);
  }

  function stop(): void {
    generation += 1;
    controller?.abort();
    controller = null;
    driving.value = false;
    wake?.();
  }

  /** Interrupt the current wait and poll again immediately (after pause/resume/cancel). */
  function poke(next?: Job): void {
    if (next) job.value = next;
    wake?.();
    if (!alive && !finished.value) start();
  }

  /** Reset buffers and restart for a different job id. */
  function reset(): void {
    stop();
    job.value = null;
    logs.value = [];
    droppedLogs.value = 0;
    lastLogId = 0;
    loading.value = true;
    error.value = null;
  }

  onScopeDispose(stop);

  return { job, loading, error, logs, droppedLogs, driving, finished, start, stop, poke, reset };
}
