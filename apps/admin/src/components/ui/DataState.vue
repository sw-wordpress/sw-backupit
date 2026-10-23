<script setup lang="ts">
import type { ApiError } from '@/api/client';
import ErrorState from './ErrorState.vue';

/**
 * Standard four-state wrapper: loading (skeleton) / error+retry / empty / content.
 * `loading` only shows the skeleton while there is nothing to display yet, so refreshes
 * do not flash.
 */
withDefaults(
  defineProps<{
    loading: boolean;
    error: ApiError | null;
    empty?: boolean;
    rows?: number;
    variant?: 'list' | 'cards' | 'block';
    compactError?: boolean;
  }>(),
  { empty: false, rows: 4, variant: 'list', compactError: false },
);
const emit = defineEmits<{ (e: 'retry'): void }>();
</script>

<template>
  <div>
    <ErrorState v-if="error && empty" :error="error" :compact="compactError" @retry="emit('retry')" />
    <template v-else-if="loading && empty">
      <div class="swbi-skeleton" :class="`swbi-skeleton--${variant}`" aria-busy="true">
        <span class="swbi-sr-only" role="status">…</span>
        <template v-if="variant === 'cards'">
          <q-skeleton v-for="n in rows" :key="n" height="150px" class="swbi-skeleton__card" />
        </template>
        <template v-else-if="variant === 'block'">
          <q-skeleton height="180px" />
        </template>
        <template v-else>
          <div v-for="n in rows" :key="n" class="swbi-skeleton__row">
            <q-skeleton type="circle" size="36px" />
            <div class="swbi-skeleton__lines">
              <q-skeleton type="text" width="45%" />
              <q-skeleton type="text" width="70%" />
            </div>
          </div>
        </template>
      </div>
    </template>
    <template v-else-if="empty"><slot name="empty" /></template>
    <template v-else>
      <div v-if="error" class="swbi-inline-error" role="alert">
        <q-icon name="r_error" size="18px" aria-hidden="true" />
        <span>{{ error.message }}</span>
        <q-btn flat dense no-caps color="primary" icon="r_refresh" :label="$t('common.retry')" @click="emit('retry')" />
      </div>
      <slot />
    </template>
  </div>
</template>

<style scoped>
.swbi-skeleton--cards {
  display: grid;
  gap: 16px;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr));
}
.swbi-skeleton__row {
  display: flex;
  gap: 14px;
  align-items: center;
  padding: 14px 4px;
}
.swbi-skeleton__lines {
  flex: 1;
}
.swbi-inline-error {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  margin-bottom: 12px;
  border-radius: var(--swbi-radius-sm);
  background: var(--swbi-danger-bg);
  color: var(--swbi-danger-text);
  font-size: 14px;
}
</style>
