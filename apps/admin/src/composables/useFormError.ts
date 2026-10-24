import { shallowRef } from 'vue';
import type { ApiError } from '@/api/client';
import { toApiError } from './useAsyncData';
import { useNotify } from './useNotify';

/** Validation/conflict errors are shown inline in the form; everything else as a toast. */
export function useFormError() {
  const error = shallowRef<ApiError | null>(null);
  const notify = useNotify();
  return {
    error,
    clear(): void {
      error.value = null;
    },
    handle(e: unknown): void {
      const err = toApiError(e);
      if (err.status === 400 || err.status === 409 || err.status === 422) error.value = err;
      else notify.error(e);
    },
  };
}
