import { onScopeDispose, ref, shallowRef } from 'vue';
import { ApiError, isApiError } from '@/api/client';

export function toApiError(error: unknown): ApiError {
  if (isApiError(error)) return error;
  return new ApiError('swbi_unknown', error instanceof Error ? error.message : 'Unknown error', 0);
}

/**
 * Loading/error/data state for one request. Stale responses (superseded by a newer `load`)
 * are dropped. `load()` doubles as the "retry" action.
 */
export function useAsyncData<T>(fetcher: () => Promise<T>, options: { immediate?: boolean } = {}) {
  const data = shallowRef<T | null>(null);
  const loading = ref(false);
  const error = shallowRef<ApiError | null>(null);
  let ticket = 0;
  let disposed = false;

  async function load(): Promise<T | null> {
    const mine = ++ticket;
    loading.value = true;
    error.value = null;
    try {
      const result = await fetcher();
      if (mine === ticket && !disposed) data.value = result;
      return result;
    } catch (e) {
      const err = toApiError(e);
      if (mine === ticket && !disposed && !err.isAborted) error.value = err;
      return null;
    } finally {
      if (mine === ticket) loading.value = false;
    }
  }

  onScopeDispose(() => {
    disposed = true;
  });
  if (options.immediate !== false) void load();

  return { data, loading, error, load };
}
