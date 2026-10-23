<script setup lang="ts">
withDefaults(
  defineProps<{
    label: string;
    value?: string;
    hint?: string;
    icon?: string;
    loading?: boolean;
    tone?: 'default' | 'success' | 'warning' | 'danger';
  }>(),
  { value: undefined, hint: undefined, icon: undefined, loading: false, tone: 'default' },
);
</script>

<template>
  <div class="swbi-stat" :class="`swbi-stat--${tone}`">
    <div class="swbi-stat__top">
      <span class="swbi-stat__label">{{ label }}</span>
      <q-icon v-if="icon" :name="icon" size="20px" class="swbi-stat__icon" aria-hidden="true" />
    </div>
    <template v-if="loading">
      <q-skeleton type="text" width="60%" height="32px" />
      <q-skeleton type="text" width="40%" />
    </template>
    <template v-else>
      <div class="swbi-stat__value swbi-num">
        <slot>{{ value ?? '–' }}</slot>
      </div>
      <div v-if="hint || $slots.hint" class="swbi-stat__hint">
        <slot name="hint">{{ hint }}</slot>
      </div>
    </template>
  </div>
</template>

<style scoped>
.swbi-stat {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 18px 20px;
  background: var(--swbi-surface);
  border: 1px solid var(--swbi-border);
  border-radius: var(--swbi-radius);
  box-shadow: var(--swbi-shadow-1);
  min-width: 0;
}
.swbi-stat__top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}
.swbi-stat__label {
  font-size: 13px;
  font-weight: 600;
  color: var(--swbi-muted);
}
.swbi-stat__icon {
  color: var(--swbi-primary);
}
.swbi-stat__value {
  font-size: 26px;
  font-weight: 700;
  line-height: 1.2;
  letter-spacing: -0.01em;
  overflow-wrap: anywhere;
}
.swbi-stat__hint {
  font-size: 13px;
  color: var(--swbi-muted);
}
.swbi-stat--success .swbi-stat__icon {
  color: var(--swbi-success);
}
.swbi-stat--warning .swbi-stat__icon {
  color: var(--swbi-warning);
}
.swbi-stat--danger .swbi-stat__icon {
  color: var(--swbi-danger);
}
</style>
