<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { StepId } from './wizard';

export interface StepView {
  id: StepId;
  state: 'done' | 'current' | 'todo';
  reachable: boolean;
  hasIssues: boolean;
}

defineProps<{ steps: StepView[]; current: number }>();
const emit = defineEmits<{ (e: 'go', index: number): void }>();
const { t } = useI18n();
</script>

<template>
  <div class="swbi-stepper">
    <div class="swbi-stepper__bar">
      <span class="swbi-eyebrow">{{ t('profiles.wizard.setup') }}</span>
      <div class="swbi-stepper__track" aria-hidden="true">
        <div
          class="swbi-stepper__fill"
          :style="{ width: `${((current + 1) / steps.length) * 100}%` }"
        />
      </div>
      <span class="swbi-mono swbi-muted">{{
        t('profiles.wizard.stepOf', { n: current + 1, total: steps.length })
      }}</span>
    </div>
    <nav :aria-label="t('profiles.wizard.stepperLabel')" class="swbi-stepper__nav">
      <ol>
        <li v-for="(s, i) in steps" :key="s.id">
          <button
            type="button"
            class="swbi-step"
            :class="[
              `swbi-step--${s.state}`,
              { 'is-last': i === steps.length - 1, 'swbi-step--issue': s.hasIssues },
            ]"
            :aria-current="s.state === 'current' ? 'step' : undefined"
            :disabled="!s.reachable"
            @click="emit('go', i)"
          >
            <span class="swbi-step__dot">
              <q-icon v-if="s.hasIssues" name="r_priority_high" size="16px" aria-hidden="true" />
              <q-icon
                v-else-if="s.state === 'done'"
                name="r_check"
                size="16px"
                aria-hidden="true"
              />
              <q-icon
                v-else-if="i === steps.length - 1"
                name="r_flag"
                size="14px"
                aria-hidden="true"
              />
              <template v-else>{{ i + 1 }}</template>
            </span>
            <span class="swbi-step__text">
              <span class="swbi-step__title">{{ t(`profiles.wizard.steps.${s.id}.title`) }}</span>
              <span class="swbi-step__hint">{{ t(`profiles.wizard.steps.${s.id}.hint`) }}</span>
              <span v-if="s.hasIssues" class="swbi-sr-only">{{
                t('profiles.wizard.stepHasIssues')
              }}</span>
              <span v-else-if="s.state === 'done'" class="swbi-sr-only">{{
                t('profiles.wizard.stepDone')
              }}</span>
            </span>
          </button>
        </li>
      </ol>
    </nav>
  </div>
</template>

<style scoped>
.swbi-stepper__bar {
  display: flex;
  align-items: center;
  gap: 16px;
  margin-bottom: 12px;
}
.swbi-stepper__track {
  flex: 1;
  height: 4px;
  border-radius: 999px;
  background: var(--swbi-surface-3);
  overflow: hidden;
}
.swbi-stepper__fill {
  height: 100%;
  border-radius: 999px;
  background: var(--swbi-primary);
  transition: width 0.3s ease;
}
.swbi-stepper__nav {
  overflow-x: auto;
  padding-bottom: 4px;
}
.swbi-stepper__nav ol {
  display: grid;
  grid-auto-flow: column;
  grid-auto-columns: minmax(150px, 1fr);
  gap: 8px;
}
.swbi-step {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  width: 100%;
  padding: 10px;
  border: 1px solid transparent;
  border-radius: 14px;
  background: transparent;
  color: var(--swbi-muted);
  font: inherit;
  text-align: left;
  cursor: pointer;
  transition:
    background-color 0.15s ease,
    border-color 0.15s ease;
}
.swbi-step:disabled {
  cursor: default;
  opacity: 0.75;
}
.swbi-step:not(:disabled):hover {
  background: var(--swbi-surface);
}
.swbi-step__dot {
  display: grid;
  place-items: center;
  flex: none;
  width: 28px;
  height: 28px;
  border: 1px solid var(--swbi-border);
  border-radius: 50%;
  background: var(--swbi-surface);
  color: var(--swbi-muted);
  font-size: 12px;
  font-weight: 600;
  font-variant-numeric: tabular-nums;
}
.swbi-step.is-last .swbi-step__dot {
  border-style: dashed;
}
.swbi-step__text {
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.swbi-step__title {
  font-size: 14px;
  font-weight: 600;
  color: var(--swbi-text);
}
.swbi-step__hint {
  font-size: 11.5px;
  line-height: 1.35;
  color: var(--swbi-muted);
}
.swbi-step--current {
  background: var(--swbi-surface);
  border-color: var(--swbi-primary-bg);
  box-shadow: var(--swbi-shadow-1);
}
.swbi-step--current .swbi-step__dot {
  border-color: var(--swbi-primary);
  background: var(--swbi-primary);
  color: #fff;
  box-shadow: 0 0 0 4px var(--swbi-primary-bg);
}
.swbi-step--done .swbi-step__dot {
  border-color: var(--swbi-success);
  background: var(--swbi-success-bg);
  color: var(--swbi-success-text);
}
.swbi-step--issue .swbi-step__dot {
  border-color: var(--swbi-danger);
  background: var(--swbi-danger-bg);
  color: var(--swbi-danger-text);
}
@container (max-width: 1100px) {
  .swbi-step {
    flex-direction: column;
    align-items: center;
    gap: 6px;
    text-align: center;
  }
  .swbi-step__text {
    align-items: center;
  }
}
</style>
