import { computed, onBeforeUnmount, shallowRef } from 'vue';
import { useApi } from '@/api';
import type { Transfer } from '@/types/api';

const POLL_MS = 2500;

/** Copy/move transfers, polled while one is queued or running. */
export function useTransfers(onFinished?: (transfer: Transfer) => void) {
  const transfers = shallowRef<Transfer[]>([]);
  const active = computed(() =>
    transfers.value.filter((tr) => tr.status === 'queued' || tr.status === 'running'),
  );
  let timer: ReturnType<typeof setTimeout> | null = null;
  let controller: AbortController | null = null;
  let stopped = false;

  async function load(): Promise<void> {
    if (timer) clearTimeout(timer);
    timer = null;
    controller?.abort();
    controller = new AbortController();
    const before = new Map(transfers.value.map((tr) => [tr.id, tr.status]));
    try {
      transfers.value = await useApi().transfers.list(controller.signal);
    } catch {
      /* keep the last known state; the next poll tries again */
    }
    for (const tr of transfers.value) {
      const was = before.get(tr.id);
      if ((was === 'queued' || was === 'running') && was !== tr.status) onFinished?.(tr);
    }
    if (!stopped && active.value.length > 0) timer = setTimeout(() => void load(), POLL_MS);
  }

  onBeforeUnmount(() => {
    stopped = true;
    if (timer) clearTimeout(timer);
    controller?.abort();
  });

  return { transfers, active, load };
}
