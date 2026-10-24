<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { Job, JobStep } from '@/types/api';
import { phaseIndex, PHASES } from '@/utils/jobs';

const props = defineProps<{ job: Job }>();
const { t } = useI18n();

const ICONS: Record<JobStep, string> = {
  preflight: 'r_checklist',
  scan: 'r_manage_search',
  export: 'r_database',
  archive: 'r_folder_zip',
  encrypt: 'r_lock',
  verify_local: 'r_fact_check',
  manifest: 'r_list_alt',
  upload: 'r_cloud_upload',
  verify_remote: 'r_cloud_done',
  finalize: 'r_flag',
  retention: 'r_auto_delete',
  notify: 'r_mail',
};

const currentIndex = computed(() => {
  if (props.job.status === 'completed') return PHASES.length;
  return phaseIndex(props.job.phase);
});

type State = 'done' | 'current' | 'failed' | 'pending';
const steps = computed(() =>
  PHASES.map((phase, index) => {
    let state: State = 'pending';
    if (index < currentIndex.value) state = 'done';
    else if (index === currentIndex.value) {
      state =
        props.job.status === 'failed' || props.job.status === 'cancelled' ? 'failed' : 'current';
    }
    return {
      phase,
      state,
      icon: state === 'done' ? 'r_check' : state === 'failed' ? 'r_close' : ICONS[phase],
    };
  }),
);
</script>

<template>
  <ol class="swbi-steps" :aria-label="t('jobs.phases')">
    <li
      v-for="step in steps"
      :key="step.phase"
      class="swbi-step"
      :class="`swbi-step--${step.state}`"
      :aria-current="step.state === 'current' ? 'step' : undefined"
    >
      <span class="swbi-step__dot"><q-icon :name="step.icon" size="18px" aria-hidden="true" /></span>
      <span class="swbi-step__label">{{ t(`phases.${step.phase}`) }}</span>
      <span class="swbi-sr-only">{{ t(`jobs.stepState.${step.state}`) }}</span>
    </li>
  </ol>
</template>

<style scoped>
.swbi-steps {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 18px 4px;
}
.swbi-step {
  position: relative;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  text-align: center;
  font-size: 12px;
  font-weight: 600;
  color: var(--swbi-muted);
}
.swbi-step::before {
  content: '';
  position: absolute;
  top: 17px;
  left: calc(-50% + 20px);
  right: calc(50% + 20px);
  height: 2px;
  background: var(--swbi-border);
}
.swbi-step:nth-child(6n + 1)::before {
  display: none;
}
.swbi-step--done::before,
.swbi-step--current::before {
  background: var(--swbi-gradient);
}
.swbi-step__dot {
  display: grid;
  place-items: center;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: 2px solid var(--swbi-border);
  background: var(--swbi-surface);
  transition:
    background-color 0.3s ease,
    border-color 0.3s ease;
}
.swbi-step--done .swbi-step__dot {
  background: var(--swbi-success-bg);
  border-color: var(--swbi-success);
  color: var(--swbi-success-text);
}
.swbi-step--current {
  color: var(--swbi-text);
}
.swbi-step--current .swbi-step__dot {
  border-color: var(--swbi-primary);
  background: var(--swbi-primary-bg);
  color: var(--swbi-primary-text);
  box-shadow: 0 0 0 4px var(--swbi-primary-soft);
}
.swbi-step--failed {
  color: var(--swbi-danger-text);
}
.swbi-step--failed .swbi-step__dot {
  border-color: var(--swbi-danger);
  background: var(--swbi-danger-bg);
  color: var(--swbi-danger-text);
}
@media (max-width: 760px) {
  .swbi-steps {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
  .swbi-step::before {
    display: none;
  }
}
</style>
