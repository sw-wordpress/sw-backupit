<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import ProgressRing from '@/components/ui/ProgressRing.vue';
import StatusChip from '@/components/ui/StatusChip.vue';
import type { HealthReport } from '@/types/api';

const props = defineProps<{ report: HealthReport }>();
const { t } = useI18n();

const okCount = computed(() => props.report.checks.filter((c) => c.status === 'ok').length);
const total = computed(() => props.report.checks.length);
const percent = computed(() => (total.value === 0 ? 100 : (okCount.value / total.value) * 100));
const tone = computed(() =>
  props.report.status === 'ok'
    ? 'success'
    : props.report.status === 'warning'
      ? 'warning'
      : 'danger',
);
const icon = computed(() =>
  props.report.status === 'ok'
    ? 'r_verified_user'
    : props.report.status === 'warning'
      ? 'r_warning'
      : 'r_gpp_bad',
);
const problems = computed(() => props.report.checks.filter((c) => c.status !== 'ok'));
</script>

<template>
  <section class="swbi-hero" :class="`swbi-hero--${tone}`" aria-labelledby="swbi-hero-title">
    <ProgressRing
      :value="percent"
      :size="132"
      :stroke="11"
      :tone="tone"
      :label="t('dashboard.healthScore')"
      :semantic="false"
    >
      <q-icon :name="icon" size="44px" :class="`swbi-hero__icon--${tone}`" aria-hidden="true" />
    </ProgressRing>
    <div class="swbi-hero__text">
      <StatusChip domain="health" :status="report.status" />
      <h2 id="swbi-hero-title" class="swbi-hero__title">
        {{ t(`dashboard.health.${report.status}.title`) }}
      </h2>
      <p class="swbi-hero__sub">{{ t(`dashboard.health.${report.status}.text`) }}</p>
      <p class="swbi-hero__count swbi-num">
        {{ t('dashboard.checksPassed', { ok: okCount, total }) }}
      </p>
    </div>
    <ul
      v-if="problems.length > 0"
      class="swbi-hero__problems"
      :aria-label="t('dashboard.failedChecks')"
    >
      <li v-for="check in problems" :key="check.id">
        <StatusChip domain="health" :status="check.status" :label="check.label" dense />
        <span class="swbi-hero__msg">{{ check.message }}</span>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.swbi-hero {
  position: relative;
  display: grid;
  grid-template-columns: auto minmax(0, 1fr) minmax(0, 1fr);
  gap: 28px;
  align-items: center;
  padding: 28px 32px;
  border-radius: 20px;
  background: var(--swbi-surface);
  border: 1px solid var(--swbi-border);
  box-shadow: var(--swbi-shadow-2);
  overflow: hidden;
}
.swbi-hero::before {
  content: '';
  position: absolute;
  inset: 0;
  background: var(--swbi-gradient-soft);
  pointer-events: none;
}
.swbi-hero > * {
  position: relative;
}
.swbi-hero__title {
  margin-top: 10px;
  font-size: 26px;
  font-weight: 750;
  letter-spacing: -0.02em;
  line-height: 1.2;
}
.swbi-hero__sub {
  margin-top: 4px;
  color: var(--swbi-muted);
  max-width: 52ch;
}
.swbi-hero__count {
  margin-top: 10px;
  font-size: 13px;
  font-weight: 600;
  color: var(--swbi-muted);
}
.swbi-hero__icon--success {
  color: var(--swbi-success);
}
.swbi-hero__icon--warning {
  color: var(--swbi-warning);
}
.swbi-hero__icon--danger {
  color: var(--swbi-danger);
}
.swbi-hero__problems {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: 14px 16px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-surface);
  border: 1px solid var(--swbi-border);
}
.swbi-hero__problems li {
  display: flex;
  flex-direction: column;
  gap: 4px;
  align-items: flex-start;
}
.swbi-hero__msg {
  font-size: 13px;
  color: var(--swbi-muted);
}
@media (max-width: 1100px) {
  .swbi-hero {
    grid-template-columns: auto minmax(0, 1fr);
  }
  .swbi-hero__problems {
    grid-column: 1 / -1;
  }
}
@media (max-width: 560px) {
  .swbi-hero {
    grid-template-columns: minmax(0, 1fr);
    justify-items: start;
    padding: 22px 18px;
  }
}
</style>
