<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useApi } from '@/api';
import ConfirmDialog from '@/components/ui/ConfirmDialog.vue';
import ErrorState from '@/components/ui/ErrorState.vue';
import PageHeader from '@/components/ui/PageHeader.vue';
import ProgressRing from '@/components/ui/ProgressRing.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import SwbiCard from '@/components/ui/SwbiCard.vue';
import { useCapabilities } from '@/composables/useCapabilities';
import { useFormat } from '@/composables/useFormat';
import { useJobMonitor } from '@/composables/useJobMonitor';
import { useNotify } from '@/composables/useNotify';
import { useAppStore } from '@/stores/app';
import { useJobsStore } from '@/stores/jobs';
import type { Job } from '@/types/api';
import { isActive, isTerminal, normalisePhase } from '@/utils/jobs';
import LogViewer from './LogViewer.vue';
import PhaseStepper from './PhaseStepper.vue';

const props = defineProps<{ id: string }>();
const { t, te } = useI18n();
const fmt = useFormat();
const api = useApi();
const notify = useNotify();
const app = useAppStore();
const jobsStore = useJobsStore();
const { can } = useCapabilities();
const canRun = can('swbi_run');

const monitor = useJobMonitor(() => props.id, {
  drive: () => app.driveJobs,
  canRun,
  onUpdate: announceProgress,
});
const job = monitor.job;

const acting = ref<'pause' | 'resume' | 'cancel' | null>(null);
const cancelOpen = ref(false);

onMounted(() => monitor.start());
watch(
  () => props.id,
  () => {
    monitor.reset();
    monitor.start();
  },
);

const active = computed(() => job.value !== null && isActive(job.value));
const paused = computed(() => job.value?.status === 'paused');
const percent = computed(() => (job.value ? Math.min(100, Math.max(0, job.value.progress.percent)) : 0));
const tone = computed(() => (job.value?.status === 'failed' ? 'danger' : job.value?.status === 'completed' ? 'success' : job.value?.status === 'paused' ? 'warning' : 'primary'));
const phaseLabel = computed(() => (job.value && te(`phases.${normalisePhase(job.value.phase) ?? job.value.phase}`) ? t(`phases.${normalisePhase(job.value.phase) ?? job.value.phase}`) : (job.value?.phase ?? '')));

// ---- throttled screen-reader announcements (never every poll) ----
let lastAnnounce = 0;
let lastPhase = '';
let lastBucket = -1;
let lastStatus = '';
function announceProgress(j: Job): void {
  const now = Date.now();
  const terminalChange = isTerminal(j.status) && j.status !== lastStatus;
  const phaseChange = j.phase !== lastPhase && lastPhase !== '';
  const bucket = Math.floor(j.progress.percent / 25);
  const bucketChange = bucket !== lastBucket && lastBucket !== -1;
  if (terminalChange) {
    app.announce(t('jobs.announce.terminal', { name: j.profile_name, status: t(`status.job.${j.status}`) }));
    lastAnnounce = now;
  } else if ((phaseChange || bucketChange) && now - lastAnnounce > 15000) {
    app.announce(t('jobs.announce.progress', { name: j.profile_name, phase: te(`phases.${j.phase}`) ? t(`phases.${j.phase}`) : j.phase, percent: Math.round(j.progress.percent) }));
    lastAnnounce = now;
  }
  lastPhase = j.phase;
  lastBucket = bucket;
  lastStatus = j.status;
  if (isTerminal(j.status)) void jobsStore.refresh();
}

async function act(kind: 'pause' | 'resume'): Promise<void> {
  acting.value = kind;
  try {
    const next = await api.jobs[kind](props.id);
    monitor.poke(next);
    void jobsStore.refresh();
  } catch (e) {
    notify.error(e);
  } finally {
    acting.value = null;
  }
}
async function cancel(): Promise<void> {
  acting.value = 'cancel';
  try {
    const next = await api.jobs.cancel(props.id);
    cancelOpen.value = false;
    monitor.poke(next);
    void jobsStore.refresh();
  } catch (e) {
    notify.error(e);
  } finally {
    acting.value = null;
  }
}

const stats = computed(() => {
  const j = job.value;
  if (!j) return [];
  const p = j.progress;
  return [
    { label: t('jobs.stats.files'), value: t('jobs.filesOf', { done: fmt.number(p.files_done), total: fmt.number(p.files_total) }) },
    { label: t('jobs.stats.bytes'), value: `${fmt.bytes(p.bytes_done)} / ${fmt.bytes(p.bytes_total)}` },
    { label: t('jobs.stats.compressed'), value: fmt.bytes(p.bytes_compressed) },
    { label: t('jobs.stats.ratio'), value: p.compression_ratio > 0 ? fmt.percent(p.compression_ratio) : '–' },
    { label: t('jobs.stats.volumes'), value: String(p.volumes_done) },
    { label: t('jobs.stats.elapsed'), value: fmt.duration(p.elapsed_seconds) },
    { label: t('jobs.stats.eta'), value: active.value ? fmt.duration(p.eta_seconds) : '–' },
  ];
});
const uploadPercent = computed(() => {
  const u = job.value?.progress.upload;
  return u && u.bytes_total > 0 ? (u.bytes_done / u.bytes_total) * 100 : 0;
});
const showUpload = computed(() => {
  const p = job.value?.progress;
  return !!p && (p.upload.bytes_total > 0 || p.targets.length > 0);
});
</script>

<template>
  <div>
    <PageHeader :title="job ? job.profile_name : t('jobs.detail')" :subtitle="job ? `${t(`types.${job.type}`) === `types.${job.type}` ? job.type : t(`types.${job.type}`)} · ${job.id}` : undefined" back-to="/jobs" :back-label="t('nav.jobs')">
      <template v-if="job">
        <q-btn v-if="canRun && active" flat no-caps icon="r_pause" :label="t('jobs.pause')" :loading="acting === 'pause'" @click="act('pause')" />
        <q-btn v-if="canRun && paused" unelevated no-caps color="primary" icon="r_play_arrow" :label="t('jobs.resume')" :loading="acting === 'resume'" @click="act('resume')" />
        <q-btn v-if="canRun && (active || paused)" outline no-caps color="negative" icon="r_stop" :label="t('jobs.cancel')" @click="cancelOpen = true" />
        <q-btn v-if="job.backup_id" unelevated no-caps color="primary" icon="r_inventory_2" :label="t('jobs.openBackup')" :to="{ name: 'backups', params: { id: job.backup_id } }" />
      </template>
    </PageHeader>

    <ErrorState v-if="monitor.error.value && !job" :error="monitor.error.value" @retry="monitor.start()" />
    <div v-else-if="!job" aria-busy="true" class="swbi-stack">
      <q-skeleton height="200px" /><q-skeleton height="90px" /><q-skeleton height="260px" />
    </div>

    <div v-else class="swbi-stack">
      <div v-if="monitor.error.value" class="swbi-jd__warn" role="alert">
        <q-icon name="r_wifi_off" size="20px" aria-hidden="true" />
        <span>{{ t('jobs.connectionLost') }} ({{ monitor.error.value.message }})</span>
      </div>

      <SwbiCard accent>
        <div class="swbi-jd__top">
          <ProgressRing :value="percent" :size="140" :stroke="12" :tone="tone" :label="t('jobs.progressOf', { name: job.profile_name })" />
          <div class="swbi-jd__main">
            <div class="swbi-row">
              <StatusChip domain="job" :status="job.status" />
              <span class="swbi-jd__phase">{{ phaseLabel }}</span>
              <span v-if="job.retry_count > 0" class="swbi-muted">{{ t('jobs.retries', { n: job.retry_count }, job.retry_count) }}</span>
              <span v-if="job.warnings_count > 0" class="swbi-jd__warncount"><q-icon name="r_warning" size="16px" aria-hidden="true" /> {{ t('jobs.warnings', { n: job.warnings_count }, job.warnings_count) }}</span>
            </div>
            <p v-if="job.progress.current_item && active" class="swbi-jd__current" :title="job.progress.current_item">
              <span class="swbi-eyebrow">{{ t('jobs.currentItem') }}</span>
              <span class="swbi-mono swbi-truncate">{{ job.progress.current_item }}</span>
            </p>
            <dl class="swbi-jd__stats">
              <div v-for="s in stats" :key="s.label"><dt>{{ s.label }}</dt><dd class="swbi-num">{{ s.value }}</dd></div>
            </dl>
          </div>
        </div>

        <div v-if="job.error" class="swbi-jd__error" role="alert">
          <q-icon name="r_error" size="22px" aria-hidden="true" />
          <div><strong>{{ t('jobs.failedWith') }}</strong><p>{{ job.error }}</p></div>
        </div>
        <p v-if="job.status === 'retry_waiting'" class="swbi-muted swbi-jd__note"><q-icon name="r_schedule" size="16px" aria-hidden="true" /> {{ t('jobs.retryWaiting') }}</p>
      </SwbiCard>

      <SwbiCard :title="t('jobs.phases')" icon="r_timeline"><PhaseStepper :job="job" /></SwbiCard>

      <SwbiCard v-if="canRun && (active || paused)" :title="t('jobs.driver.title')" icon="r_memory">
        <q-toggle v-model="app.driveJobs" :label="t('jobs.driver.label')" />
        <p class="swbi-muted swbi-jd__note">{{ t('jobs.driver.hint') }}</p>
        <p v-if="app.driveJobs && monitor.driving.value" class="swbi-jd__driving"><q-icon name="r_autorenew" size="16px" aria-hidden="true" /> {{ t('jobs.driver.active') }}</p>
      </SwbiCard>

      <SwbiCard v-if="showUpload" :title="t('jobs.upload.title')" icon="r_cloud_upload">
        <template v-if="job.progress.upload.bytes_total > 0">
          <q-linear-progress :value="uploadPercent / 100" size="10px" rounded color="primary" class="swbi-jd__bar" role="progressbar" :aria-label="t('jobs.upload.title')" :aria-valuenow="Math.round(uploadPercent)" aria-valuemin="0" aria-valuemax="100" />
          <p class="swbi-num swbi-jd__note">{{ fmt.bytes(job.progress.upload.bytes_done) }} / {{ fmt.bytes(job.progress.upload.bytes_total) }} · {{ fmt.rate(job.progress.upload.rate_bps) }}</p>
        </template>
        <ul class="swbi-jd__targets">
          <li v-for="tg in job.progress.targets" :key="tg.target_id">
            <span class="swbi-jd__tname"><q-icon name="r_dns" size="18px" aria-hidden="true" /> {{ tg.name }}</span>
            <StatusChip domain="upload" :status="tg.status" dense />
            <span v-if="tg.error" class="swbi-jd__terr">{{ tg.error }}</span>
          </li>
        </ul>
      </SwbiCard>

      <SwbiCard :title="t('jobs.log.title')" icon="r_terminal">
        <LogViewer :logs="monitor.logs.value" :dropped="monitor.droppedLogs.value" />
      </SwbiCard>
    </div>

    <ConfirmDialog
      v-model="cancelOpen"
      :title="t('jobs.cancelTitle')"
      :target="job ? `${job.profile_name} (${job.id.slice(0, 8)})` : undefined"
      :consequences="[t('jobs.cancelConsequence1'), t('jobs.cancelConsequence2')]"
      :confirm-label="t('jobs.cancelConfirm')"
      :loading="acting === 'cancel'"
      @confirm="cancel"
    />
  </div>
</template>

<style scoped>
.swbi-jd__top {
  display: flex;
  gap: 28px;
  align-items: center;
}
.swbi-jd__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.swbi-jd__phase {
  font-weight: 650;
  font-size: 17px;
}
.swbi-jd__warncount {
  display: inline-flex;
  gap: 4px;
  align-items: center;
  color: var(--swbi-warning-text);
  font-weight: 600;
  font-size: 13px;
}
.swbi-jd__current {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}
.swbi-jd__stats {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
  gap: 12px 20px;
}
.swbi-jd__stats dt {
  font-size: 12px;
  color: var(--swbi-muted);
}
.swbi-jd__stats dd {
  font-weight: 650;
  font-size: 15px;
}
.swbi-jd__error {
  display: flex;
  gap: 12px;
  margin-top: 18px;
  padding: 14px 16px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-danger-bg);
  color: var(--swbi-danger-text);
}
.swbi-jd__warn {
  display: flex;
  gap: 10px;
  align-items: center;
  padding: 10px 14px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-warning-bg);
  color: var(--swbi-warning-text);
  font-size: 14px;
}
.swbi-jd__note {
  margin-top: 8px;
  font-size: 13px;
  display: flex;
  gap: 6px;
  align-items: center;
}
.swbi-jd__driving {
  margin-top: 6px;
  display: flex;
  gap: 6px;
  align-items: center;
  font-size: 13px;
  color: var(--swbi-primary-text);
}
.swbi-jd__bar {
  background: var(--swbi-surface-3);
}
.swbi-jd__targets {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-top: 12px;
}
.swbi-jd__targets li {
  display: flex;
  gap: 10px;
  align-items: center;
  flex-wrap: wrap;
}
.swbi-jd__tname {
  display: inline-flex;
  gap: 8px;
  align-items: center;
  font-weight: 600;
}
.swbi-jd__terr {
  font-size: 13px;
  color: var(--swbi-danger-text);
}
@media (max-width: 640px) {
  .swbi-jd__top {
    flex-direction: column;
    align-items: flex-start;
    gap: 16px;
  }
}
</style>
