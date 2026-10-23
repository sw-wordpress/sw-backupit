import { computed, ref, shallowRef } from 'vue';
import { defineStore } from 'pinia';
import { useApi } from '@/api';
import type { ApiError } from '@/api/client';
import { toApiError } from '@/composables/useAsyncData';
import type { Job } from '@/types/api';

/** Shared, visibility-aware poll of running jobs (nav badge, dashboard mini-cards). */
export const useJobsStore = defineStore('jobs', () => {
  const running = shallowRef<Job[]>([]);
  const loaded = ref(false);
  const error = shallowRef<ApiError | null>(null);
  const count = computed(() => running.value.length);

  let timer: ReturnType<typeof setTimeout> | null = null;
  let subscribers = 0;
  let failures = 0;
  let inFlight = false;

  async function refresh(): Promise<void> {
    if (inFlight) return;
    inFlight = true;
    try {
      const res = await useApi().jobs.list({ status: 'running', per_page: 20 });
      running.value = res.items;
      error.value = null;
      failures = 0;
    } catch (e) {
      error.value = toApiError(e);
      failures += 1;
    } finally {
      loaded.value = true;
      inFlight = false;
    }
  }

  function schedule(): void {
    if (subscribers === 0) return;
    const base = running.value.length > 0 ? 4000 : 15000;
    const delay = failures > 0 ? Math.min(60000, base * 2 ** failures) : base;
    timer = setTimeout(() => {
      timer = null;
      if (document.hidden) {
        schedule();
        return;
      }
      void refresh().finally(schedule);
    }, delay);
  }

  function onVisibility(): void {
    if (!document.hidden && subscribers > 0) {
      if (timer) clearTimeout(timer);
      timer = null;
      void refresh().finally(schedule);
    }
  }

  /** Call from a mounted component; pair with `unsubscribe()` on unmount. */
  function subscribe(): void {
    subscribers += 1;
    if (subscribers === 1) {
      document.addEventListener('visibilitychange', onVisibility);
      void refresh().finally(schedule);
    }
  }
  function unsubscribe(): void {
    subscribers = Math.max(0, subscribers - 1);
    if (subscribers === 0) {
      if (timer) clearTimeout(timer);
      timer = null;
      document.removeEventListener('visibilitychange', onVisibility);
    }
  }

  return { running, loaded, error, count, refresh, subscribe, unsubscribe };
});
