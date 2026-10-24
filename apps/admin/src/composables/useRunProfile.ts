import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useRouter } from 'vue-router';
import { useApi } from '@/api';
import { useJobsStore } from '@/stores/jobs';
import type { Job } from '@/types/api';
import { useNotify } from './useNotify';

/** Start a profile or schedule run, then take the user to the live job view. */
export function useRunProfile() {
  const { t } = useI18n();
  const router = useRouter();
  const notify = useNotify();
  const jobs = useJobsStore();
  const busyId = ref<number | null>(null);

  async function start(id: number, starter: () => Promise<Job>): Promise<void> {
    busyId.value = id;
    try {
      const job = await starter();
      notify.success(t('jobs.started'));
      void jobs.refresh();
      await router.push({ name: 'job', params: { id: job.id } });
    } catch (error) {
      notify.error(error);
    } finally {
      busyId.value = null;
    }
  }

  return {
    busyId,
    /** `storageTargetId`: destination for this run only (0 = local staging only). */
    runProfile: (id: number, storageTargetId?: number) =>
      start(id, () => useApi().profiles.run(id, storageTargetId)),
    runSchedule: (id: number) => start(id, () => useApi().schedules.run(id)),
  };
}
