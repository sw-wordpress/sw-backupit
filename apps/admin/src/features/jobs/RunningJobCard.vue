<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import StatusChip from '@/components/ui/StatusChip.vue';
import { useFormat } from '@/composables/useFormat';
import type { Job } from '@/types/api';

const props = defineProps<{ job: Job }>();
const { t, te } = useI18n();
const fmt = useFormat();
const percent = computed(() => Math.min(100, Math.max(0, props.job.progress.percent)));
const phaseLabel = computed(() =>
  te(`phases.${props.job.phase}`) ? t(`phases.${props.job.phase}`) : props.job.phase,
);
</script>

<template>
  <router-link :to="{ name: 'job', params: { id: job.id } }" class="swbi-jc">
    <div class="swbi-jc__top">
      <strong class="swbi-truncate">{{ job.profile_name }}</strong>
      <StatusChip domain="job" :status="job.status" dense />
    </div>
    <div class="swbi-jc__phase">{{ phaseLabel }}</div>
    <q-linear-progress
      :value="percent / 100"
      size="10px"
      rounded
      color="primary"
      track-color="grey-4"
      class="swbi-jc__bar"
      :aria-label="t('jobs.progressOf', { name: job.profile_name })"
      :aria-valuenow="Math.round(percent)"
      aria-valuemin="0"
      aria-valuemax="100"
      role="progressbar"
    />
    <div class="swbi-jc__meta swbi-num">
      <span>{{ fmt.percent(percent, true) }}</span>
      <span>{{
        t('jobs.filesOf', {
          done: fmt.number(job.progress.files_done),
          total: fmt.number(job.progress.files_total),
        })
      }}</span>
      <span v-if="job.progress.eta_seconds !== null">{{
        t('jobs.etaShort', { time: fmt.duration(job.progress.eta_seconds) })
      }}</span>
    </div>
  </router-link>
</template>

<style scoped>
.swbi-jc {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 16px 18px;
  border-radius: var(--swbi-radius);
  background: var(--swbi-surface);
  border: 1px solid var(--swbi-border);
  box-shadow: var(--swbi-shadow-1);
  color: var(--swbi-text) !important;
  text-decoration: none !important;
  transition:
    box-shadow 0.2s ease,
    transform 0.2s ease;
}
.swbi-jc:hover {
  box-shadow: var(--swbi-shadow-2);
  transform: translateY(-1px);
}
.swbi-jc__top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}
.swbi-jc__phase {
  font-size: 13px;
  color: var(--swbi-muted);
}
.swbi-jc__bar {
  background: var(--swbi-surface-3);
}
.swbi-jc__meta {
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
  font-size: 13px;
  color: var(--swbi-muted);
}
.swbi-jc__meta span:first-child {
  font-weight: 700;
  color: var(--swbi-text);
}
</style>
