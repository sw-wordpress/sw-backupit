<script setup lang="ts">
import { computed } from 'vue';
import { uid } from '@/utils/ids';

const props = withDefaults(
  defineProps<{
    /** 0..100 */
    value: number;
    size?: number;
    stroke?: number;
    tone?: 'primary' | 'success' | 'warning' | 'danger';
    label?: string;
    /** Whether to expose role=progressbar (false for purely decorative rings). */
    semantic?: boolean;
  }>(),
  { size: 96, stroke: 9, tone: 'primary', label: undefined, semantic: true },
);

const gradId = uid('swbi-ring');
const radius = computed(() => (props.size - props.stroke) / 2);
const circumference = computed(() => 2 * Math.PI * radius.value);
const clamped = computed(() => Math.min(100, Math.max(0, Number.isFinite(props.value) ? props.value : 0)));
const offset = computed(() => circumference.value * (1 - clamped.value / 100));
const stops = computed<[string, string]>(() => {
  switch (props.tone) {
    case 'success':
      return ['var(--swbi-success)', 'var(--swbi-accent-bright)'];
    case 'warning':
      return ['var(--swbi-warning)', 'var(--swbi-warning)'];
    case 'danger':
      return ['var(--swbi-danger)', 'var(--swbi-danger)'];
    default:
      return ['var(--swbi-primary)', 'var(--swbi-accent-bright)'];
  }
});
</script>

<template>
  <div
    class="swbi-ring"
    :style="{ width: `${size}px`, height: `${size}px` }"
    :role="semantic ? 'progressbar' : undefined"
    :aria-valuenow="semantic ? Math.round(clamped) : undefined"
    :aria-valuemin="semantic ? 0 : undefined"
    :aria-valuemax="semantic ? 100 : undefined"
    :aria-label="semantic ? label : undefined"
    :aria-hidden="semantic ? undefined : 'true'"
  >
    <svg :width="size" :height="size" :viewBox="`0 0 ${size} ${size}`" focusable="false" aria-hidden="true">
      <defs>
        <linearGradient :id="gradId" x1="0" y1="0" x2="1" y2="1">
          <stop offset="0" :stop-color="stops[0]" />
          <stop offset="1" :stop-color="stops[1]" />
        </linearGradient>
      </defs>
      <circle :cx="size / 2" :cy="size / 2" :r="radius" fill="none" class="swbi-ring__track" :stroke-width="stroke" />
      <circle
        :cx="size / 2"
        :cy="size / 2"
        :r="radius"
        fill="none"
        class="swbi-ring__value"
        :stroke="`url(#${gradId})`"
        :stroke-width="stroke"
        stroke-linecap="round"
        :stroke-dasharray="circumference"
        :stroke-dashoffset="offset"
        :transform="`rotate(-90 ${size / 2} ${size / 2})`"
      />
    </svg>
    <div class="swbi-ring__center"><slot>{{ Math.round(clamped) }}%</slot></div>
  </div>
</template>

<style scoped>
.swbi-ring {
  position: relative;
  display: inline-grid;
  place-items: center;
  flex: none;
}
.swbi-ring svg {
  position: absolute;
  inset: 0;
}
.swbi-ring__track {
  stroke: var(--swbi-surface-3);
}
.swbi-ring__value {
  transition: stroke-dashoffset 0.6s ease;
}
.swbi-ring__center {
  position: relative;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
  font-size: 18px;
  display: grid;
  place-items: center;
}
</style>
